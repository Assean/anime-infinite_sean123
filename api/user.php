<?php
/**
 * ANIME INFINITE — User API
 * api/user.php
 */

require_once __DIR__ . '/../config/config.php';

$auth   = getAuthUser(true);
$uid    = (int)$auth['uid'];
$body   = getJsonBody();
$action = sanitize($body['action'] ?? $_GET['action'] ?? '');

switch ($action) {

    case 'get_profile':        getProfile($uid); break;
    case 'update_profile':     updateProfile($uid, $body); break;
    case 'change_password':    changePassword($uid, $body); break;
    case 'get_assets':         getAssets($uid); break;
    case 'get_history':        getHistory($uid); break;
    case 'get_tasks':          getTasks($uid); break;
    case 'complete_task':      completeTask($uid, $body); break;
    case 'claim_birthday':     claimBirthday($uid); break;
    case 'bind_roblox':        bindRoblox($uid, $body); break;
    case 'unbind_roblox':      unbindRoblox($uid); break;
    case 'send_otp':           sendPhoneOTP($uid, $body); break;
    case 'verify_otp':         verifyPhoneOTP($uid, $body); break;
    case 'get_notifications':  getNotifications($uid); break;
    case 'mark_notif_read':    markNotifRead($uid, $body); break;
    case 'reply_notification': replyNotification($uid, $body); break;
    case 'get_login_logs':     getLoginLogs($uid); break;
    case 'upload_avatar':      uploadAvatar($uid); break;
    default: jsonError('未知操作', 400);
}

// ══════════════════════════════════════════════════════════════════
function getProfile(int $uid): void {
    $user = Database::queryOne(
        "SELECT id, email, nickname, avatar, role_level, points, verifications,
                discord_tag, roblox_name, birthday, phone, phone_exempt,
                sec_question, created_at, last_login_at
         FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1",
        [$uid]
    );
    if (!$user) jsonError('找不到使用者', 404);
    jsonSuccess(['user' => sanitizeUserOutput($user)]);
}

// ══════════════════════════════════════════════════════════════════
function updateProfile(int $uid, array $body): void {
    $nickname = sanitize($body['nickname'] ?? '', 20);
    $avatar   = sanitize($body['avatar'] ?? '', 500);

    if ($nickname && (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 20)) {
        jsonError('暱稱需介於 2 至 20 個字元');
    }

    // Check nickname uniqueness (exclude self)
    if ($nickname) {
        $taken = Database::queryOne(
            "SELECT id FROM users WHERE nickname = ? AND id != ? LIMIT 1",
            [$nickname, $uid]
        );
        if ($taken) jsonError('此暱稱已被使用');
    }

    $fields = [];
    $params = [];
    if ($nickname) { $fields[] = 'nickname = ?'; $params[] = $nickname; }
    if ($avatar)   { $fields[] = 'avatar = ?';   $params[] = $avatar; }
    if (!$fields)  jsonError('沒有可更新的資料');

    $params[] = $uid;
    Database::execute("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?", $params);
    writeAuditLog('update_profile', compact('nickname', 'avatar'), $uid);
    jsonSuccess([], '個人資料已更新');
}

// ══════════════════════════════════════════════════════════════════
function changePassword(int $uid, array $body): void {
    $current = $body['current'] ?? '';
    $newPwd  = $body['new_password'] ?? '';

    if (!$current || !$newPwd) jsonError('請填寫所有欄位');
    if (strlen($newPwd) < 8)   jsonError('新密碼至少需要 8 個字元');

    $user = Database::queryOne("SELECT password_hash FROM users WHERE id = ?", [$uid]);
    if (!$user || !password_verify($current, $user['password_hash'])) {
        jsonError('目前密碼錯誤');
    }

    $hash = password_hash($newPwd, PASSWORD_ARGON2ID);
    Database::execute("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $uid]);
    writeAuditLog('change_password', [], $uid);
    jsonSuccess([], '密碼已成功更新');
}

// ══════════════════════════════════════════════════════════════════
function getAssets(int $uid): void {
    $user = Database::queryOne(
        "SELECT points, roblox_gems, roblox_level, roblox_wins, roblox_kills,
                battle_pass_expires_at
         FROM users WHERE id = ? LIMIT 1",
        [$uid]
    );
    if (!$user) jsonError('找不到使用者', 404);

    $bpDays = null;
    if ($user['battle_pass_expires_at']) {
        $bpDays = max(0, (int)ceil(
            (strtotime($user['battle_pass_expires_at']) - time()) / 86400
        ));
    }

    jsonSuccess([
        'points'             => (int)$user['points'],
        'roblox_gems'        => (int)$user['roblox_gems'],
        'roblox_level'       => (int)$user['roblox_level'],
        'roblox_wins'        => (int)$user['roblox_wins'],
        'roblox_kills'       => (int)$user['roblox_kills'],
        'battle_pass_days'   => $bpDays,
    ]);
}

// ══════════════════════════════════════════════════════════════════
function getHistory(int $uid): void {
    $type   = sanitize($_GET['type'] ?? 'all');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $whereSql = $type !== 'all' ? "AND tx_type = ?" : "";
    $params   = $type !== 'all' ? [$uid, $type, $limit, $offset] : [$uid, $limit, $offset];

    $rows = Database::query(
        "SELECT tx_type, description, points_delta, status, created_at
         FROM transactions
         WHERE user_id = ? $whereSql
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?",
        $params
    );

    $total = Database::queryOne(
        "SELECT COUNT(*) as cnt FROM transactions WHERE user_id = ?",
        [$uid]
    )['cnt'] ?? 0;

    jsonSuccess(['records' => $rows, 'total' => (int)$total, 'page' => $page]);
}

// ══════════════════════════════════════════════════════════════════
function getTasks(int $uid): void {
    $now     = date('Y-m-d');
    $week    = date('Y-W');

    $completedToday   = Database::query(
        "SELECT task_key FROM task_completions WHERE user_id = ? AND period_key = ? AND task_group = 'daily'",
        [$uid, $now]
    );
    $completedWeekly  = Database::query(
        "SELECT task_key FROM task_completions WHERE user_id = ? AND period_key = ? AND task_group = 'weekly'",
        [$uid, $week]
    );

    $doneDaily  = array_column($completedToday,  'task_key');
    $doneWeekly = array_column($completedWeekly, 'task_key');

    $tasks = [
        'daily' => [
            ['key'=>'daily_login',   'label'=>'每日登入',          'reward'=>10,  'done'=> in_array('daily_login',   $doneDaily)],
            ['key'=>'daily_battle',  'label'=>'完成 1 場戰鬥',     'reward'=>20,  'done'=> in_array('daily_battle',  $doneDaily)],
            ['key'=>'daily_browse',  'label'=>'瀏覽任意攻略文章',  'reward'=>5,   'done'=> in_array('daily_browse',  $doneDaily)],
            ['key'=>'daily_like',    'label'=>'在社群按讚 3 次',   'reward'=>5,   'done'=> in_array('daily_like',    $doneDaily)],
        ],
        'weekly' => [
            ['key'=>'weekly_wins',   'label'=>'累計勝利 10 場',    'reward'=>100, 'done'=> in_array('weekly_wins',   $doneWeekly)],
            ['key'=>'weekly_topup',  'label'=>'儲值任意金額',      'reward'=>50,  'done'=> in_array('weekly_topup',  $doneWeekly)],
            ['key'=>'weekly_share',  'label'=>'分享任意文章',      'reward'=>30,  'done'=> in_array('weekly_share',  $doneWeekly)],
        ],
    ];

    // Check birthday gift
    $user = Database::queryOne("SELECT birthday, birthday_claimed_year FROM users WHERE id = ?", [$uid]);
    $birthdayAvailable = false;
    if ($user && $user['birthday']) {
        $bday       = new DateTime($user['birthday']);
        $today      = new DateTime();
        $sameDay    = $bday->format('m-d') === $today->format('m-d');
        $notClaimed = (int)$user['birthday_claimed_year'] !== (int)$today->format('Y');
        $birthdayAvailable = $sameDay && $notClaimed;
    }

    jsonSuccess(['tasks' => $tasks, 'birthday_available' => $birthdayAvailable]);
}

// ══════════════════════════════════════════════════════════════════
function completeTask(int $uid, array $body): void {
    $key   = sanitize($body['task_key'] ?? '');
    $group = sanitize($body['task_group'] ?? 'daily');

    if (!$key) jsonError('無效的任務');

    $rewardMap = [
        'daily_login'  => 10, 'daily_battle' => 20,
        'daily_browse' => 5,  'daily_like'   => 5,
        'weekly_wins'  => 100,'weekly_topup' => 50, 'weekly_share' => 30,
    ];

    if (!isset($rewardMap[$key])) jsonError('未知任務');

    $periodKey = $group === 'daily' ? date('Y-m-d') : date('Y-W');

    // Check not already done
    $done = Database::queryOne(
        "SELECT id FROM task_completions
         WHERE user_id = ? AND task_key = ? AND period_key = ? AND task_group = ?",
        [$uid, $key, $periodKey, $group]
    );
    if ($done) jsonError('此任務今日已完成');

    $reward = $rewardMap[$key];

    Database::beginTransaction();
    try {
        Database::execute(
            "INSERT INTO task_completions (user_id, task_key, task_group, period_key, reward, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$uid, $key, $group, $periodKey, $reward]
        );
        Database::execute(
            "UPDATE users SET points = points + ? WHERE id = ?",
            [$reward, $uid]
        );
        Database::execute(
            "INSERT INTO transactions (user_id, tx_type, description, points_delta, status, created_at)
             VALUES (?, 'reward', ?, ?, 'success', NOW())",
            [$uid, "任務獎勵：{$key}", $reward]
        );
        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        jsonError('任務完成失敗', 500);
    }

    jsonSuccess(['reward' => $reward], "任務完成！獲得 {$reward} P");
}

// ══════════════════════════════════════════════════════════════════
function claimBirthday(int $uid): void {
    $user = Database::queryOne(
        "SELECT birthday, birthday_claimed_year FROM users WHERE id = ?",
        [$uid]
    );
    if (!$user || !$user['birthday']) jsonError('未設定生日');

    $bday    = new DateTime($user['birthday']);
    $today   = new DateTime();
    $year    = (int)$today->format('Y');

    if ($bday->format('m-d') !== $today->format('m-d')) jsonError('今天不是您的生日');
    if ((int)$user['birthday_claimed_year'] === $year)   jsonError('今年生日禮包已領取');

    $reward = 500; // configurable

    Database::beginTransaction();
    try {
        Database::execute(
            "UPDATE users SET points = points + ?, birthday_claimed_year = ? WHERE id = ?",
            [$reward, $year, $uid]
        );
        Database::execute(
            "INSERT INTO transactions (user_id, tx_type, description, points_delta, status, created_at)
             VALUES (?, 'reward', '生日禮包', ?, 'success', NOW())",
            [$uid, $reward]
        );
        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        jsonError('領取失敗', 500);
    }

    jsonSuccess(['reward' => $reward], '生日快樂！已獲得 500 P 生日禮包 🎂');
}

// ══════════════════════════════════════════════════════════════════
function bindRoblox(int $uid, array $body): void {
    $username = sanitize($body['username'] ?? '', 50);
    $code     = sanitize($body['code'] ?? '', 10);

    if (!$username || !$code) jsonError('請填寫 Roblox 使用者名稱與驗證碼');

    // Verify the code via Roblox Open Cloud API (or in-game DataStore)
    $valid = verifyRobloxCode($username, $code);
    if (!$valid) jsonError('驗證碼無效或已過期，請在遊戲內重新執行 /bind');

    // Check the Roblox account isn't already bound to another user
    $alreadyBound = Database::queryOne(
        "SELECT id FROM users WHERE roblox_name = ? AND id != ? LIMIT 1",
        [$username, $uid]
    );
    if ($alreadyBound) jsonError('此 Roblox 帳號已綁定至其他使用者');

    Database::execute(
        "UPDATE users SET
            roblox_name = ?,
            verifications = JSON_SET(COALESCE(verifications, '{}'), '$.roblox', CAST(TRUE AS JSON))
         WHERE id = ?",
        [$username, $uid]
    );

    writeAuditLog('bind_roblox', ['roblox_name' => $username], $uid);
    jsonSuccess(['roblox_name' => $username], 'Roblox 帳號綁定成功！');
}

function verifyRobloxCode(string $username, string $code): bool {
    // In production: call Roblox Open Cloud DataStore API
    // GET https://apis.roblox.com/datastores/v1/universes/{universeId}/standard-datastores/datastore/entries/entry
    // key = "bind_{$username}"
    if (!ROBLOX_API_KEY || !ROBLOX_UNIVERSE_ID) return true; // Dev bypass

    $url = "https://apis.roblox.com/datastores/v1/universes/" . ROBLOX_UNIVERSE_ID
         . "/standard-datastores/datastore/entries/entry?datastoreName=BindCodes&entryKey=" . urlencode("bind_$username");
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["x-api-key: " . ROBLOX_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) return false;
    $stored = json_decode($res, true);
    return isset($stored['code']) && $stored['code'] === $code;
}

function unbindRoblox(int $uid): void {
    Database::execute(
        "UPDATE users SET roblox_name = NULL,
            verifications = JSON_SET(verifications, '$.roblox', CAST(FALSE AS JSON))
         WHERE id = ?",
        [$uid]
    );
    jsonSuccess([], 'Roblox 帳號已解除綁定');
}

// ══════════════════════════════════════════════════════════════════
function sendPhoneOTP(int $uid, array $body): void {
    $phone = sanitize($body['phone'] ?? '', 20);
    if (!$phone || !preg_match('/^\+?\d{8,15}$/', preg_replace('/\s+/', '', $phone))) {
        jsonError('手機號碼格式錯誤');
    }

    if (!checkRateLimit("otp_{$uid}", 3, 300)) jsonError('發送過於頻繁，請稍後再試', 429);

    $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes

    Database::execute(
        "INSERT INTO phone_otps (user_id, phone, otp_hash, expires_at, created_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE otp_hash = VALUES(otp_hash), expires_at = VALUES(expires_at), created_at = NOW()",
        [$uid, aiEncrypt($phone), password_hash($otp, PASSWORD_BCRYPT), $expires]
    );

    // Send SMS (stub — integrate with Twilio / local SMS gateway)
    sendSMS($phone, "【Anime Infinite】您的驗證碼為：{$otp}，5 分鐘內有效。請勿告訴他人。");

    jsonSuccess([], '驗證碼已發送，請查看您的簡訊');
}

function verifyPhoneOTP(int $uid, array $body): void {
    $phone = sanitize($body['phone'] ?? '', 20);
    $otp   = sanitize($body['otp'] ?? '', 6);

    $row = Database::queryOne(
        "SELECT * FROM phone_otps WHERE user_id = ? AND expires_at > NOW() AND used = 0 ORDER BY created_at DESC LIMIT 1",
        [$uid]
    );
    if (!$row || !password_verify($otp, $row['otp_hash'])) {
        jsonError('驗證碼錯誤或已過期');
    }

    Database::beginTransaction();
    try {
        Database::execute("UPDATE phone_otps SET used = 1 WHERE id = ?", [$row['id']]);
        Database::execute(
            "UPDATE users SET phone = ?,
                verifications = JSON_SET(COALESCE(verifications,'{}'), '$.phone', CAST(TRUE AS JSON))
             WHERE id = ?",
            [aiEncrypt($phone), $uid]
        );
        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        jsonError('綁定失敗', 500);
    }

    jsonSuccess([], '手機號碼驗證成功！');
}

function sendSMS(string $phone, string $message): bool {
    if (!SMS_API_KEY) return true; // Dev bypass
    // Integrate with Twilio or local SMS gateway here
    return true;
}

// ══════════════════════════════════════════════════════════════════
function getNotifications(int $uid): void {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $notifs = Database::query(
        "SELECT n.id, n.title, n.content, n.notif_type, n.created_at,
                nr.read_at, nr.replied_at, nr.reply_content
         FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
         WHERE n.target_type = 'all'
            OR (n.target_type = 'single' AND n.target_uid = ?)
            OR (n.target_type = 'role_min' AND ? >= n.target_role_min)
         ORDER BY n.created_at DESC
         LIMIT ? OFFSET ?",
        [$uid, $uid, $auth['role_level'] ?? 1, $limit, $offset]
    );

    $unread = Database::queryOne(
        "SELECT COUNT(*) as cnt FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
         WHERE nr.id IS NULL
           AND (n.target_type = 'all'
             OR (n.target_type = 'single' AND n.target_uid = ?)
             OR (n.target_type = 'role_min' AND ? >= n.target_role_min))",
        [$uid, $uid, $auth['role_level'] ?? 1]
    )['cnt'] ?? 0;

    jsonSuccess(['notifications' => $notifs, 'unread_count' => (int)$unread]);
}

// ══════════════════════════════════════════════════════════════════
function markNotifRead(int $uid, array $body): void {
    $notifId = (int)($body['notification_id'] ?? 0);
    if (!$notifId) jsonError('缺少 notification_id');

    Database::execute(
        "INSERT INTO notification_reads (notification_id, user_id, read_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE read_at = COALESCE(read_at, NOW())",
        [$notifId, $uid]
    );

    jsonSuccess([], '已讀回執已送出');
}

// ══════════════════════════════════════════════════════════════════
function replyNotification(int $uid, array $body): void {
    $notifId = (int)($body['notification_id'] ?? 0);
    $reply   = sanitize($body['reply'] ?? '', 1000);

    if (!$notifId || !$reply) jsonError('缺少必要資料');

    Database::execute(
        "INSERT INTO notification_reads (notification_id, user_id, read_at, reply_content, replied_at)
         VALUES (?, ?, NOW(), ?, NOW())
         ON DUPLICATE KEY UPDATE reply_content = ?, replied_at = NOW()",
        [$notifId, $uid, $reply, $reply]
    );

    jsonSuccess([], '回覆已送出');
}

// ══════════════════════════════════════════════════════════════════
function getLoginLogs(int $uid): void {
    $logs = Database::query(
        "SELECT login_at, ip, user_agent, status FROM login_logs
         WHERE user_id = ? ORDER BY login_at DESC LIMIT 10",
        [$uid]
    );
    jsonSuccess(['logs' => $logs]);
}

// ══════════════════════════════════════════════════════════════════
function uploadAvatar(int $uid): void {
    if (!isset($_FILES['avatar'])) jsonError('未收到圖片檔案');
    $file = $_FILES['avatar'];
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($file['type'], $allowed)) jsonError('僅支援 JPG、PNG、WEBP 格式');
    if ($file['size'] > 2 * 1024 * 1024) jsonError('圖片大小不可超過 2MB');

    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = "avatar_{$uid}_" . time() . ".{$ext}";
    $dir  = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) jsonError('上傳失敗');

    $url = APP_URL . '/uploads/avatars/' . $name;
    Database::execute("UPDATE users SET avatar = ? WHERE id = ?", [$url, $uid]);
    jsonSuccess(['avatar_url' => $url], '頭像已更新');
}

// ══════════════════════════════════════════════════════════════════
function sanitizeUserOutput(array $user): array {
    $v = is_string($user['verifications']) ? json_decode($user['verifications'], true) : ($user['verifications'] ?? []);
    return [
        'id'            => $user['id'],
        'email'         => $user['email'],
        'nickname'      => $user['nickname'],
        'avatar'        => $user['avatar'],
        'role_level'    => (int)$user['role_level'],
        'points'        => (int)($user['points'] ?? 0),
        'verifications' => $v,
        'discord_tag'   => $user['discord_tag'] ?? null,
        'roblox_name'   => $user['roblox_name'] ?? null,
        'phone_masked'  => $user['phone'] ? '●●●●' . substr(aiDecrypt($user['phone']) ?: '', -4) : null,
        'phone_exempt'  => (bool)$user['phone_exempt'],
        'birthday'      => $user['birthday'],
        'created_at'    => $user['created_at'],
        'last_login_at' => $user['last_login_at'],
    ];
}

global $auth;
