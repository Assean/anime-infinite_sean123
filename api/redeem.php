<?php
/**
 * ANIME INFINITE — Redeem Code API
 * api/redeem.php
 */

require_once __DIR__ . '/../config/config.php';

$auth   = getAuthUser(true);
$uid    = (int)$auth['uid'];
$body   = getJsonBody();
$action = sanitize($body['action'] ?? $_GET['action'] ?? 'redeem');

switch ($action) {
    case 'redeem':      doRedeem($uid, $body); break;
    case 'check':       checkCode($body);      break;
    default: jsonError('未知操作', 400);
}

// ══════════════════════════════════════════════════════════════════
function doRedeem(int $uid, array $body): void {
    $ip   = $_SERVER['REMOTE_ADDR'];
    $code = strtoupper(sanitize($body['code'] ?? '', 10));

    // Basic format check
    if (!preg_match('/^[A-Z0-9]{10}$/', $code)) {
        jsonError('兌換碼格式錯誤（需為 10 碼英數字元）');
    }

    // Rate limit: 10 attempts per hour per user
    if (!checkRateLimit("redeem_uid_{$uid}", RATE_LIMIT_REDEEM, 3600)) {
        jsonError('兌換嘗試次數過多，請 1 小時後再試', 429);
    }

    // Also rate-limit by IP
    if (!checkRateLimit("redeem_ip_{$ip}", RATE_LIMIT_REDEEM * 2, 3600)) {
        jsonError('此 IP 兌換嘗試次數過多', 429);
    }

    // Check Roblox binding (required for redeem)
    $user = Database::queryOne(
        "SELECT roblox_name, verifications FROM users WHERE id = ? LIMIT 1",
        [$uid]
    );
    $verifications = is_string($user['verifications'] ?? null)
        ? json_decode($user['verifications'], true)
        : [];
    if (empty($verifications['roblox'])) {
        jsonError('請先完成 Roblox 帳號綁定才能使用兌換功能');
    }

    // Fetch the code record
    $codeRow = Database::queryOne(
        "SELECT * FROM redeem_codes WHERE code = ? LIMIT 1",
        [$code]
    );

    if (!$codeRow) {
        logRedeemAttempt($uid, $code, 'invalid', $ip);
        jsonError('無效的兌換碼');
    }

    // Check expiry
    if ($codeRow['expires_at'] && strtotime($codeRow['expires_at']) < time()) {
        logRedeemAttempt($uid, $code, 'expired', $ip);
        jsonError('此兌換碼已過期');
    }

    // Check max uses
    if ($codeRow['max_uses'] > 0 && $codeRow['used_count'] >= $codeRow['max_uses']) {
        logRedeemAttempt($uid, $code, 'exhausted', $ip);
        jsonError('此兌換碼已達使用上限');
    }

    // Check if this user already used it
    $alreadyUsed = Database::queryOne(
        "SELECT id FROM redeem_usages WHERE code_id = ? AND user_id = ? LIMIT 1",
        [$codeRow['id'], $uid]
    );
    if ($alreadyUsed) {
        logRedeemAttempt($uid, $code, 'already_used', $ip);
        jsonError('您已使用過此兌換碼');
    }

    // Parse reward
    $reward    = json_decode($codeRow['reward_json'], true) ?? [];
    $rewardMsg = applyReward($uid, $reward, $codeRow['id']);

    Database::beginTransaction();
    try {
        // Mark usage
        Database::execute(
            "INSERT INTO redeem_usages (code_id, user_id, ip, used_at)
             VALUES (?, ?, ?, NOW())",
            [$codeRow['id'], $uid, $ip]
        );
        // Increment used_count
        Database::execute(
            "UPDATE redeem_codes SET used_count = used_count + 1 WHERE id = ?",
            [$codeRow['id']]
        );
        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        error_log('[Redeem] ' . $e->getMessage());
        jsonError('兌換失敗，請稍後再試', 500);
    }

    logRedeemAttempt($uid, $code, 'success', $ip);
    writeAuditLog('redeem_code', ['code' => $code, 'reward' => $reward], $uid);

    jsonSuccess(['reward' => $reward, 'message' => $rewardMsg], $rewardMsg);
}

// ══════════════════════════════════════════════════════════════════
function applyReward(int $uid, array $reward, int $codeId): string {
    $msgs = [];

    Database::beginTransaction();
    try {
        // Points reward
        if (!empty($reward['points'])) {
            $pts = (int)$reward['points'];
            Database::execute(
                "UPDATE users SET points = points + ? WHERE id = ?",
                [$pts, $uid]
            );
            Database::execute(
                "INSERT INTO transactions
                    (user_id, tx_type, description, points_delta, ref_id, status, created_at)
                 VALUES (?, 'redeem', ?, ?, ?, 'success', NOW())",
                [$uid, "兌換碼獎勵：{$pts} P", $pts, $codeId]
            );
            $msgs[] = "+{$pts} P 點數";
        }

        // Title reward
        if (!empty($reward['title'])) {
            Database::execute(
                "INSERT IGNORE INTO user_titles (user_id, title_key, obtained_at)
                 VALUES (?, ?, NOW())",
                [$uid, $reward['title']]
            );
            $msgs[] = "稱號「{$reward['title']}」";
        }

        // Battle Pass (days)
        if (!empty($reward['battle_pass_days'])) {
            $days = (int)$reward['battle_pass_days'];
            Database::execute(
                "UPDATE users SET
                    battle_pass_expires_at = DATE_ADD(
                        GREATEST(COALESCE(battle_pass_expires_at, NOW()), NOW()),
                        INTERVAL ? DAY
                    )
                 WHERE id = ?",
                [$days, $uid]
            );
            $msgs[] = "Battle Pass {$days} 天";
        }

        // Roblox gems
        if (!empty($reward['gems'])) {
            $gems = (int)$reward['gems'];
            Database::execute(
                "UPDATE users SET roblox_gems = roblox_gems + ? WHERE id = ?",
                [$gems, $uid]
            );
            $msgs[] = "+{$gems} 遊戲寶石";
        }

        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        throw $e;
    }

    return '兌換成功！獲得：' . implode('、', $msgs ?: ['獎勵已發送']);
}

// ══════════════════════════════════════════════════════════════════
function checkCode(array $body): void {
    $code = strtoupper(sanitize($body['code'] ?? '', 10));
    if (!preg_match('/^[A-Z0-9]{10}$/', $code)) {
        jsonError('兌換碼格式錯誤');
    }

    $codeRow = Database::queryOne(
        "SELECT code, reward_json, expires_at, max_uses, used_count FROM redeem_codes WHERE code = ? LIMIT 1",
        [$code]
    );

    if (!$codeRow) jsonError('無效的兌換碼', 404);

    $valid = true;
    $reason = '';

    if ($codeRow['expires_at'] && strtotime($codeRow['expires_at']) < time()) {
        $valid  = false;
        $reason = '已過期';
    } elseif ($codeRow['max_uses'] > 0 && $codeRow['used_count'] >= $codeRow['max_uses']) {
        $valid  = false;
        $reason = '已達使用上限';
    }

    jsonSuccess([
        'valid'      => $valid,
        'reason'     => $reason,
        'reward'     => json_decode($codeRow['reward_json'], true),
        'expires_at' => $codeRow['expires_at'],
    ]);
}

// ══════════════════════════════════════════════════════════════════
function logRedeemAttempt(int $uid, string $code, string $result, string $ip): void {
    try {
        Database::execute(
            "INSERT INTO redeem_attempt_logs (user_id, code, result, ip, attempted_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$uid, $code, $result, $ip]
        );
    } catch (Throwable $e) {
        error_log('[RedeemLog] ' . $e->getMessage());
    }
}
