#!/usr/bin/env php
<?php
/**
 * ANIME INFINITE — Cron Job Runner
 * cron/cron_jobs.php
 *
 * Recommended crontab setup:
 * ┌─────────────────────────── minute (0-59)
 * │  ┌──────────────────────── hour   (0-23)
 * │  │  ┌───────────────────── dom    (1-31)
 * │  │  │  ┌────────────────── month  (1-12)
 * │  │  │  │  ┌─────────────── dow    (0-6, Sun=0)
 * │  │  │  │  │
 * 0  0  *  *  *  php /var/www/anime-infinite/cron/cron_jobs.php birthday      >> /var/log/ai_cron.log 2>&1
 * 0  3  *  *  *  php /var/www/anime-infinite/cron/cron_jobs.php backup        >> /var/log/ai_cron.log 2>&1
 * 0  0  *  *  *  php /var/www/anime-infinite/cron/cron_jobs.php daily_reset   >> /var/log/ai_cron.log 2>&1
 * 0  0  *  *  1  php /var/www/anime-infinite/cron/cron_jobs.php weekly_reset  >> /var/log/ai_cron.log 2>&1
 * */5 * *  *  *  php /var/www/anime-infinite/cron/cron_jobs.php roblox_sync   >> /var/log/ai_cron.log 2>&1
 * 0  1  *  *  *  php /var/www/anime-infinite/cron/cron_jobs.php cleanup       >> /var/log/ai_cron.log 2>&1
 */

define('CRON_MODE', true);
require_once __DIR__ . '/../config/config.php';

// ── CLI only ──────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$job = $argv[1] ?? 'help';

echo '[' . date('Y-m-d H:i:s') . "] Starting job: {$job}\n";

switch ($job) {
    case 'birthday':     jobBirthdayGifts();     break;
    case 'backup':       jobDatabaseBackup();    break;
    case 'daily_reset':  jobDailyReset();        break;
    case 'weekly_reset': jobWeeklyReset();       break;
    case 'roblox_sync':  jobRobloxSync();        break;
    case 'cleanup':      jobCleanup();           break;
    case 'all':
        jobBirthdayGifts();
        jobDailyReset();
        jobRobloxSync();
        jobCleanup();
        break;
    default:
        echo "Available jobs: birthday | backup | daily_reset | weekly_reset | roblox_sync | cleanup | all\n";
        exit(0);
}

echo '[' . date('Y-m-d H:i:s') . "] Job {$job} completed.\n";

// ══════════════════════════════════════════════════════════════════
/**
 * JOB: Birthday Gift Dispatch
 * Runs: daily at midnight
 * Finds users whose birthday is today and haven't claimed this year's gift.
 * Auto-sends points + notification.
 */
function jobBirthdayGifts(): void {
    echo "  [Birthday] Scanning for birthdays...\n";

    $today = date('m-d');
    $year  = (int)date('Y');

    $users = Database::query(
        "SELECT id, nickname, email, points
         FROM users
         WHERE DATE_FORMAT(birthday, '%m-%d') = ?
           AND (birthday_claimed_year IS NULL OR birthday_claimed_year != ?)
           AND status = 'active'
           AND deleted_at IS NULL",
        [$today, $year]
    );

    if (empty($users)) {
        echo "  [Birthday] No birthdays today.\n";
        return;
    }

    $reward     = (int)(getConfigValue('birthday_bonus') ?? 500);
    $successCnt = 0;
    $failCnt    = 0;

    foreach ($users as $user) {
        try {
            Database::beginTransaction();

            // Grant points
            Database::execute(
                "UPDATE users SET points = points + ?, birthday_claimed_year = ? WHERE id = ?",
                [$reward, $year, $user['id']]
            );

            // Log transaction
            Database::execute(
                "INSERT INTO transactions (user_id, tx_type, description, points_delta, status, created_at)
                 VALUES (?, 'reward', '生日禮包自動發放', ?, 'success', NOW())",
                [$user['id'], $reward]
            );

            // Send notification
            Database::execute(
                "INSERT INTO notifications (target_type, target_uid, title, content, notif_type, created_at)
                 VALUES ('single', ?, '🎂 生日快樂！', ?, 'reward', NOW())",
                [
                    $user['id'],
                    "親愛的 {$user['nickname']}，生日快樂！🎉\n您的生日禮包「星辰禮盒」(+{$reward}P) 已自動發送至您的帳戶。祝您遊戲愉快！"
                ]
            );

            Database::commit();

            // Push Discord notification (optional)
            pushDiscordWebhook("🎂 玩家 **{$user['nickname']}** 今日生日，已自動發送生日禮包 (+{$reward}P)！");

            // Send birthday email
            sendBirthdayEmail($user['email'], $user['nickname'], $reward);

            $successCnt++;
            echo "  [Birthday] ✓ Sent to {$user['nickname']} (uid={$user['id']})\n";

        } catch (Throwable $e) {
            Database::rollback();
            $failCnt++;
            echo "  [Birthday] ✗ Failed for {$user['nickname']}: {$e->getMessage()}\n";
            error_log("[CRON:birthday] uid={$user['id']} error: {$e->getMessage()}");
        }
    }

    writeAuditLog('cron_birthday', [
        'date'    => date('Y-m-d'),
        'success' => $successCnt,
        'failed'  => $failCnt,
        'reward'  => $reward,
    ]);

    echo "  [Birthday] Done. Success={$successCnt}, Failed={$failCnt}\n";
}

// ══════════════════════════════════════════════════════════════════
/**
 * JOB: Database Backup
 * Runs: daily at 03:00
 * mysqldump → gzip → upload to Google Drive / S3
 */
function jobDatabaseBackup(): void {
    echo "  [Backup] Starting mysqldump...\n";

    $timestamp = date('Ymd_His');
    $filename  = "ai_auto_{$timestamp}.sql.gz";
    $backupDir = sys_get_temp_dir() . '/ai_backups/';

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0700, true);
    }

    $filepath = $backupDir . $filename;

    // Use --single-transaction for InnoDB (non-locking)
    $cmd = sprintf(
        'mysqldump --host=%s --port=%s --user=%s --password=%s %s '
        . '--single-transaction --routines --triggers --events '
        . '2>/dev/null | gzip -9 > %s',
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_PORT),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_NAME),
        escapeshellarg($filepath)
    );

    exec($cmd, $output, $retCode);

    if ($retCode !== 0 || !file_exists($filepath) || filesize($filepath) < 100) {
        echo "  [Backup] ✗ mysqldump failed (exit={$retCode})\n";
        error_log("[CRON:backup] mysqldump failed");
        writeAuditLog('cron_backup_failed', ['exit_code' => $retCode]);
        return;
    }

    $size = filesize($filepath);
    echo "  [Backup] ✓ Created {$filename} (" . round($size / 1024) . " KB)\n";

    // Upload to Google Drive (rclone must be configured)
    uploadToCloud($filepath, $filename);

    // Keep only last 7 local backups
    pruneLocalBackups($backupDir, 7);

    writeAuditLog('cron_backup_success', ['filename' => $filename, 'size' => $size]);
    echo "  [Backup] Done.\n";
}

function uploadToCloud(string $localPath, string $filename): void {
    // Option A: rclone (recommended)
    $rcloneDest = "gdrive:AnimeInfinite/backups/{$filename}";
    exec("rclone copy {$localPath} " . escapeshellarg(dirname($rcloneDest)) . " 2>&1", $out, $code);
    if ($code === 0) {
        echo "  [Backup] ✓ Uploaded to Google Drive\n";
        return;
    }

    // Option B: AWS S3 CLI
    $s3Bucket = getenv('S3_BUCKET');
    if ($s3Bucket) {
        exec("aws s3 cp {$localPath} s3://{$s3Bucket}/backups/{$filename} 2>&1", $out, $code);
        if ($code === 0) {
            echo "  [Backup] ✓ Uploaded to S3\n";
            return;
        }
    }

    echo "  [Backup] ⚠ Cloud upload skipped (no rclone/S3 configured)\n";
}

function pruneLocalBackups(string $dir, int $keep): void {
    $files = glob($dir . 'ai_auto_*.sql.gz');
    if (!$files) return;
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $toDelete = array_slice($files, $keep);
    foreach ($toDelete as $f) {
        unlink($f);
        echo "  [Backup] Pruned old backup: " . basename($f) . "\n";
    }
}

// ══════════════════════════════════════════════════════════════════
/**
 * JOB: Daily Reset
 * Runs: daily at midnight
 * Grants login bonus to users who logged in today.
 */
function jobDailyReset(): void {
    echo "  [DailyReset] Processing daily login bonuses...\n";

    $bonus = (int)(getConfigValue('daily_login_bonus') ?? 10);
    $today = date('Y-m-d');

    // Find users who logged in today but haven't received daily bonus
    $users = Database::query(
        "SELECT DISTINCT u.id, u.nickname FROM users u
         JOIN login_logs ll ON ll.user_id = u.id AND DATE(ll.login_at) = ? AND ll.status = 'success'
         LEFT JOIN task_completions tc ON tc.user_id = u.id AND tc.task_key = 'daily_login' AND tc.period_key = ?
         WHERE tc.id IS NULL AND u.status = 'active' AND u.deleted_at IS NULL
         LIMIT 5000",
        [$today, $today]
    );

    $cnt = 0;
    foreach ($users as $u) {
        try {
            Database::beginTransaction();
            Database::execute("UPDATE users SET points = points + ? WHERE id = ?", [$bonus, $u['id']]);
            Database::execute(
                "INSERT INTO task_completions (user_id, task_key, task_group, period_key, reward, created_at)
                 VALUES (?, 'daily_login', 'daily', ?, ?, NOW())",
                [$u['id'], $today, $bonus]
            );
            Database::execute(
                "INSERT INTO transactions (user_id, tx_type, description, points_delta, status, created_at)
                 VALUES (?, 'reward', '每日登入獎勵', ?, 'success', NOW())",
                [$u['id'], $bonus]
            );
            Database::commit();
            $cnt++;
        } catch (Throwable $e) {
            Database::rollback();
            error_log("[CRON:daily_reset] uid={$u['id']} error: {$e->getMessage()}");
        }
    }

    writeAuditLog('cron_daily_reset', ['date' => $today, 'bonuses_granted' => $cnt, 'bonus_pts' => $bonus]);
    echo "  [DailyReset] Done. Granted {$cnt} login bonuses (+{$bonus}P each)\n";
}

// ══════════════════════════════════════════════════════════════════
/**
 * JOB: Weekly Reset
 * Runs: every Monday at midnight
 */
function jobWeeklyReset(): void {
    echo "  [WeeklyReset] Resetting weekly task tracking...\n";

    // Weekly tasks reset automatically via period_key (YYYY-WW),
    // so no DB action needed — just log and notify
    $week = date('Y-W');
    writeAuditLog('cron_weekly_reset', ['week' => $week]);

    // Announce top players of last week
    $topPlayers = Database::query(
        "SELECT u.nickname, SUM(tc.reward) as total_reward
         FROM task_completions tc
         JOIN users u ON u.id = tc.user_id
         WHERE tc.period_key = ? AND tc.task_group = 'weekly'
         GROUP BY tc.user_id ORDER BY total_reward DESC LIMIT 3",
        [date('Y-W', strtotime('-7 days'))]
    );

    if ($topPlayers) {
        $msg = "🏆 **上週任務排行榜**\n";
        foreach ($topPlayers as $i => $p) {
            $medals = ['🥇','🥈','🥉'];
            $msg .= "{$medals[$i]} {$p['nickname']} — {$p['total_reward']}P\n";
        }
        pushDiscordWebhook($msg);
    }

    echo "  [WeeklyReset] Done.\n";
}

// ══════════════════════════════════════════════════════════════════
/**
 * JOB: Roblox Data Sync
 * Runs: every 5 minutes
 * Pulls in-game stats from Roblox DataStore and syncs to MySQL.
 */
function jobRobloxSync(): void {
    if (!ROBLOX_API_KEY || !ROBLOX_UNIVERSE_ID) {
        echo "  [RobloxSync] Skipped (no API key configured)\n";
        return;
    }

    echo "  [RobloxSync] Fetching pending sync queue...\n";

    // Read sync queue from Roblox DataStore
    $url = "https://apis.roblox.com/datastores/v1/universes/" . ROBLOX_UNIVERSE_ID
         . "/standard-datastores/datastore/entries?datastoreName=SyncQueue&limit=100";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["x-api-key: " . ROBLOX_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        echo "  [RobloxSync] ✗ API error (HTTP {$code})\n";
        return;
    }

    $data = json_decode($res, true);
    $keys = $data['keys'] ?? [];
    $cnt  = 0;

    foreach ($keys as $entry) {
        $key     = $entry['key'] ?? '';
        $parts   = explode('_', $key);
        if (count($parts) < 2 || $parts[0] !== 'player') continue;

        $robloxName = $parts[1];
        $user       = Database::queryOne(
            "SELECT id FROM users WHERE roblox_name = ? LIMIT 1",
            [$robloxName]
        );
        if (!$user) continue;

        // Fetch individual entry
        $entryUrl = "https://apis.roblox.com/datastores/v1/universes/" . ROBLOX_UNIVERSE_ID
                  . "/standard-datastores/datastore/entries/entry?datastoreName=SyncQueue&entryKey=" . urlencode($key);
        $ch2 = curl_init($entryUrl);
        curl_setopt_array($ch2, [
            CURLOPT_HTTPHEADER     => ["x-api-key: " . ROBLOX_API_KEY],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $entryRes  = curl_exec($ch2);
        $entryCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($entryCode !== 200) continue;

        $stats = json_decode($entryRes, true);

        Database::execute(
            "UPDATE users SET
                roblox_level  = COALESCE(?, roblox_level),
                roblox_wins   = COALESCE(?, roblox_wins),
                roblox_kills  = COALESCE(?, roblox_kills),
                roblox_gems   = COALESCE(?, roblox_gems)
             WHERE id = ?",
            [
                $stats['level']  ?? null,
                $stats['wins']   ?? null,
                $stats['kills']  ?? null,
                $stats['gems']   ?? null,
                $user['id'],
            ]
        );
        $cnt++;
    }

    echo "  [RobloxSync] Synced {$cnt} player records.\n";
}

// ══════════════════════════════════════════════════════════════════
/**
 * JOB: Cleanup
 * Runs: daily at 01:00
 * - Purge expired tokens / OTPs
 * - Purge old audit logs (> 90 days)
 * - Purge old login logs (> 30 days)
 * - Cancel timed-out pending orders (> 24h)
 * - Remove temp upload files (> 7 days)
 */
function jobCleanup(): void {
    echo "  [Cleanup] Starting...\n";

    // Expired email verifications
    $r1 = Database::execute("DELETE FROM email_verifications WHERE expires_at < NOW()");
    echo "  [Cleanup] Purged {$r1} expired email verifications\n";

    // Expired password resets
    $r2 = Database::execute("DELETE FROM password_resets WHERE expires_at < NOW()");
    echo "  [Cleanup] Purged {$r2} expired password resets\n";

    // Used / expired phone OTPs
    $r3 = Database::execute("DELETE FROM phone_otps WHERE expires_at < NOW() OR used = 1");
    echo "  [Cleanup] Purged {$r3} used/expired OTPs\n";

    // Stale pending orders (> 24 hours old)
    $r4 = Database::execute(
        "UPDATE orders SET status = 'cancelled'
         WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    echo "  [Cleanup] Cancelled {$r4} stale pending orders\n";

    // Old login logs (keep 30 days)
    $r5 = Database::execute(
        "DELETE FROM login_logs WHERE login_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    echo "  [Cleanup] Purged {$r5} old login logs\n";

    // Old audit logs (keep 90 days)
    $r6 = Database::execute(
        "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
    echo "  [Cleanup] Purged {$r6} old audit logs\n";

    // Old redeem attempt logs (keep 14 days)
    $r7 = Database::execute(
        "DELETE FROM redeem_attempt_logs WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"
    );
    echo "  [Cleanup] Purged {$r7} old redeem logs\n";

    // Temp avatar uploads older than 7 days that are unreferenced
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    $cleaned   = 0;
    if (is_dir($uploadDir)) {
        foreach (glob($uploadDir . '*') as $file) {
            if (filemtime($file) < strtotime('-7 days')) {
                $basename = basename($file);
                $inUse    = Database::queryOne(
                    "SELECT id FROM users WHERE avatar LIKE ? LIMIT 1",
                    ["%{$basename}"]
                );
                if (!$inUse) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }
    }
    echo "  [Cleanup] Removed {$cleaned} orphaned temp uploads\n";

    writeAuditLog('cron_cleanup', [
        'ev'      => $r1, 'pr' => $r2, 'otp' => $r3,
        'orders'  => $r4, 'll' => $r5, 'al'  => $r6,
        'redeem'  => $r7, 'uploads' => $cleaned,
    ]);

    echo "  [Cleanup] Done.\n";
}

// ══════════════════════════════════════════════════════════════════
// ── Helpers ────────────────────────────────────────────────────
function getConfigValue(string $key): ?string {
    $row = Database::queryOne(
        "SELECT config_value FROM game_configs WHERE config_key = ? LIMIT 1",
        [$key]
    );
    return $row['config_value'] ?? null;
}

function sendBirthdayEmail(string $email, string $nickname, int $reward): void {
    if (!SMTP_HOST || !SMTP_USER) return;

    $subject = "🎂 Anime Infinite — 生日快樂！";
    $body    = <<<HTML
<!DOCTYPE html>
<html>
<body style="background:#03040a;color:#e8edf8;font-family:sans-serif;padding:32px;text-align:center">
  <div style="max-width:480px;margin:0 auto;background:#0c1220;border-radius:16px;padding:32px;border:1px solid rgba(0,210,255,0.3)">
    <div style="font-size:3rem;margin-bottom:16px">🎂</div>
    <h1 style="color:#00d2ff;font-size:1.5rem;margin-bottom:8px">生日快樂，{$nickname}！</h1>
    <p style="color:#8fa3c8;line-height:1.7">
      感謝您一直以來對 Anime Infinite 的支持！<br>
      今天是您的特別日子，我們送上 <strong style="color:#ffd166">+{$reward}P</strong> 生日禮包！
    </p>
    <div style="background:rgba(255,209,102,0.1);border:1px solid rgba(255,209,102,0.3);border-radius:8px;padding:16px;margin:20px 0">
      <div style="font-size:1.8rem;font-weight:900;color:#ffd166">+{$reward} P</div>
      <div style="font-size:0.85rem;color:#8fa3c8">已發送至您的帳戶</div>
    </div>
    <a href="https://anime-infinite.com/dashboard"
       style="display:inline-block;background:linear-gradient(135deg,#00d2ff,#3a7bff);color:#03040a;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:8px">
      前往查看
    </a>
    <p style="color:#4a5a78;font-size:0.75rem;margin-top:24px">
      © Anime Infinite · <a href="https://anime-infinite.com" style="color:#00d2ff">anime-infinite.com</a>
    </p>
  </div>
</body>
</html>
HTML;

    // Use PHP mail() or PHPMailer in production
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
    mail($email, $subject, $body, $headers);
}
