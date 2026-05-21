<?php
/**
 * ANIME INFINITE — Auth API
 * api/auth.php
 * Handles: login, register, logout, SSO, forgot_password, admin_login, bind
 */

require_once __DIR__ . '/../config/config.php';

$body   = getJsonBody();
$action = sanitize($body['action'] ?? $_GET['action'] ?? '');

// ── Maintenance check (auth endpoints bypass it) ───────────────
// We allow login/register even during maintenance.

switch ($action) {

    // ══════════════════════════════════════════════════════════════
    case 'login':
        handleLogin($body);
        break;

    case 'register':
        handleRegister($body);
        break;

    case 'logout':
        handleLogout();
        break;

    case 'forgot_password':
        handleForgotPassword($body);
        break;

    case 'reset_password':
        handleResetPassword($body);
        break;

    case 'admin_login':
        handleAdminLogin($body);
        break;

    case 'sso':
        handleSSORedirect($_GET['provider'] ?? '');
        break;

    case 'sso_callback':
        handleSSOCallback($_GET['provider'] ?? '');
        break;

    case 'bind':
        handleBind($_GET['provider'] ?? '');
        break;

    case 'verify_email':
        handleVerifyEmail($_GET['token'] ?? '');
        break;

    default:
        jsonError('未知操作', 400);
}

// ══════════════════════════════════════════════════════════════════
function handleLogin(array $body): void {
    $ip = $_SERVER['REMOTE_ADDR'];

    // Rate limit: 5 attempts per 60s per IP
    if (!checkRateLimit("login_{$ip}", RATE_LIMIT_LOGIN, RATE_LIMIT_WINDOW)) {
        jsonError('登入嘗試次數過多，請 1 分鐘後再試', 429);
    }

    $identifier = sanitize($body['identifier'] ?? '');
    $password   = $body['password'] ?? '';

    if (!$identifier || !$password) {
        jsonError('請填寫所有欄位');
    }

    // Find user by email or nickname
    $user = Database::queryOne(
        "SELECT * FROM users WHERE (email = ? OR nickname = ?) AND deleted_at IS NULL LIMIT 1",
        [$identifier, $identifier]
    );

    if (!$user || !password_verify($password, $user['password_hash'])) {
        writeAuditLog('login_failed', ['identifier' => $identifier, 'ip' => $ip]);
        jsonError('帳號或密碼錯誤');
    }

    if ($user['status'] === 'banned') {
        jsonError('此帳號已被停用，請聯絡客服');
    }

    // Update last login
    Database::execute(
        "UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?",
        [$ip, $user['id']]
    );

    $remember = (bool)($body['remember'] ?? false);
    $expire   = time() + ($remember ? JWT_EXPIRE_LONG : JWT_EXPIRE);

    $token = jwtEncode([
        'uid'        => $user['id'],
        'role_level' => (int)$user['role_level'],
        'email'      => $user['email'],
        'nickname'   => $user['nickname'],
        'exp'        => $expire,
    ]);

    writeAuditLog('login_success', ['uid' => $user['id'], 'ip' => $ip], $user['id']);

    jsonSuccess([
        'token' => $token,
        'user'  => buildUserPayload($user),
    ], '登入成功');
}

// ══════════════════════════════════════════════════════════════════
function handleRegister(array $body): void {
    $email    = sanitize($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $nickname = sanitize($body['nickname'] ?? '', 20);
    $birthday = sanitize($body['birthday'] ?? '');
    $phone    = sanitize($body['phone'] ?? '', 20);
    $noPhone  = (bool)($body['no_phone'] ?? false);
    $secQ     = sanitize($body['sec_q'] ?? '', 200);
    $secA     = sanitize($body['sec_a'] ?? '', 200);

    // Validations
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('請輸入有效的電子郵件格式');
    }
    if (strlen($password) < 8) {
        jsonError('密碼至少需要 8 個字元');
    }
    if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 20) {
        jsonError('暱稱需介於 2 至 20 個字元');
    }
    if (!$birthday || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        jsonError('請填寫正確的生日格式');
    }
    // Age check (must be 13+)
    $age = (new DateTime())->diff(new DateTime($birthday))->y;
    if ($age < 13) {
        jsonError('您必須年滿 13 歲才能註冊');
    }

    // Check uniqueness
    $exists = Database::queryOne(
        "SELECT id FROM users WHERE email = ? OR nickname = ? LIMIT 1",
        [$email, $nickname]
    );
    if ($exists) {
        jsonError('此電子郵件或暱稱已被使用');
    }

    // Hash password
    $hash = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 1,
    ]);

    // Hash security answer
    $secAHash = $secA ? password_hash(strtolower(trim($secA)), PASSWORD_ARGON2ID) : null;

    Database::beginTransaction();
    try {
        $uid = Database::insert(
            "INSERT INTO users (email, password_hash, nickname, birthday, role_level, status, created_at)
             VALUES (?, ?, ?, ?, 1, 'active', NOW())",
            [$email, $hash, $nickname, $birthday]
        );

        // Phone or security Q&A
        if (!$noPhone && $phone) {
            Database::execute(
                "UPDATE users SET phone = ? WHERE id = ?",
                [aiEncrypt($phone), $uid]
            );
        } elseif ($noPhone && $secQ && $secAHash) {
            Database::execute(
                "UPDATE users SET sec_question = ?, sec_answer_hash = ?, phone_exempt = 1 WHERE id = ?",
                [$secQ, $secAHash, $uid]
            );
        }

        // Send verification email
        $vToken = bin2hex(random_bytes(32));
        Database::execute(
            "INSERT INTO email_verifications (user_id, token, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR))",
            [$uid, $vToken]
        );

        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        error_log('[Register] ' . $e->getMessage());
        jsonError('註冊失敗，請稍後再試', 500);
    }

    // Queue verification email (non-blocking)
    // sendVerificationEmail($email, $nickname, $vToken);  // implement with SMTP

    $user = Database::queryOne("SELECT * FROM users WHERE id = ?", [$uid]);
    $token = jwtEncode([
        'uid'        => $uid,
        'role_level' => 1,
        'email'      => $email,
        'nickname'   => $nickname,
        'exp'        => time() + JWT_EXPIRE,
    ]);

    writeAuditLog('register', ['uid' => $uid, 'email' => $email], $uid);

    jsonSuccess([
        'token' => $token,
        'user'  => buildUserPayload($user),
    ], '帳號建立成功，請前往會員中心完成帳號綁定');
}

// ══════════════════════════════════════════════════════════════════
function handleLogout(): void {
    $auth = getAuthUser(false);
    if ($auth) {
        writeAuditLog('logout', [], $auth['uid']);
        // For stateless JWT we can't truly invalidate, but record logout.
        // In production, maintain a token blacklist in Redis/DB.
    }
    jsonSuccess([], '已成功登出');
}

// ══════════════════════════════════════════════════════════════════
function handleForgotPassword(array $body): void {
    $email = sanitize($body['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('請輸入有效的電子郵件');
    }

    // Always respond success to prevent email enumeration
    $user = Database::queryOne("SELECT id, nickname FROM users WHERE email = ? LIMIT 1", [$email]);
    if ($user) {
        $token = bin2hex(random_bytes(32));
        Database::execute(
            "INSERT INTO password_resets (user_id, token, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 HOUR))
             ON DUPLICATE KEY UPDATE token = ?, expires_at = DATE_ADD(NOW(), INTERVAL 2 HOUR)",
            [$user['id'], $token, $token]
        );
        // sendPasswordResetEmail($email, $user['nickname'], $token);
    }

    jsonSuccess([], '若此 Email 已註冊，重置連結已發送至您的信箱');
}

// ══════════════════════════════════════════════════════════════════
function handleResetPassword(array $body): void {
    $token    = sanitize($body['token'] ?? '');
    $password = $body['password'] ?? '';

    if (!$token || strlen($password) < 8) {
        jsonError('無效的重置連結或密碼太短');
    }

    $reset = Database::queryOne(
        "SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW() AND used = 0 LIMIT 1",
        [$token]
    );
    if (!$reset) {
        jsonError('重置連結無效或已過期');
    }

    $hash = password_hash($password, PASSWORD_ARGON2ID);
    Database::beginTransaction();
    try {
        Database::execute("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $reset['user_id']]);
        Database::execute("UPDATE password_resets SET used = 1 WHERE token = ?", [$token]);
        Database::commit();
        writeAuditLog('password_reset', ['uid' => $reset['user_id']], $reset['user_id']);
    } catch (Throwable $e) {
        Database::rollback();
        jsonError('重置失敗，請稍後再試', 500);
    }

    jsonSuccess([], '密碼已成功重置，請重新登入');
}

// ══════════════════════════════════════════════════════════════════
function handleAdminLogin(array $body): void {
    $ip       = $_SERVER['REMOTE_ADDR'];
    $email    = sanitize($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $vpwd     = $body['verify_pwd'] ?? '';
    $safeCode = sanitize($body['safety_code'] ?? '');

    if (!checkRateLimit("admin_login_{$ip}", 3, 300)) { // 3 per 5 min
        writeAuditLog('admin_login_ratelimit', ['ip' => $ip]);
        jsonError('登入嘗試次數過多，請 5 分鐘後再試', 429);
    }

    // Layer 1: credentials
    $user = Database::queryOne(
        "SELECT * FROM users WHERE email = ? AND role_level >= 8 AND deleted_at IS NULL LIMIT 1",
        [$email]
    );

    if (!$user || !password_verify($password, $user['password_hash'])) {
        writeAuditLog('admin_login_failed_l1', ['email' => $email, 'ip' => $ip]);
        jsonError('帳號或密碼錯誤');
    }

    // Layer 2: admin verify password (global or per-user)
    $adminVerifyHash = $user['admin_verify_hash'] ?? ADMIN_VERIFY_PASS_HASH;
    if (!password_verify($vpwd, $adminVerifyHash)) {
        writeAuditLog('admin_login_failed_l2', ['uid' => $user['id'], 'ip' => $ip]);
        jsonError('管理員驗證密碼錯誤');
    }

    // Layer 3: safety code (for role >= 10, or if issued)
    if ((int)$user['role_level'] >= 10 || $user['safety_code_hash']) {
        if (!$safeCode) jsonError('請輸入帳戶安全碼');
        if (!$user['safety_code_hash'] || !password_verify($safeCode, $user['safety_code_hash'])) {
            writeAuditLog('admin_login_failed_l3', ['uid' => $user['id'], 'ip' => $ip]);
            jsonError('帳戶安全碼錯誤');
        }
    }

    Database::execute("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?", [$ip, $user['id']]);

    $token = jwtEncode([
        'uid'        => $user['id'],
        'role_level' => (int)$user['role_level'],
        'email'      => $user['email'],
        'nickname'   => $user['nickname'],
        'is_admin'   => true,
        'exp'        => time() + 3600 * 8, // 8-hour admin sessions
    ]);

    writeAuditLog('admin_login_success', ['uid' => $user['id'], 'ip' => $ip], $user['id']);
    jsonSuccess(['token' => $token, 'user' => buildUserPayload($user)], '歡迎進入管理後台');
}

// ══════════════════════════════════════════════════════════════════
function handleSSORedirect(string $provider): void {
    switch ($provider) {
        case 'discord':
            $url = 'https://discord.com/api/oauth2/authorize?' . http_build_query([
                'client_id'     => DISCORD_CLIENT_ID,
                'redirect_uri'  => DISCORD_REDIRECT_URI,
                'response_type' => 'code',
                'scope'         => 'identify email guilds.join',
                'state'         => bin2hex(random_bytes(16)),
            ]);
            header("Location: $url");
            exit;

        case 'google':
            $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id'     => GOOGLE_CLIENT_ID,
                'redirect_uri'  => GOOGLE_REDIRECT_URI,
                'response_type' => 'code',
                'scope'         => 'openid email profile',
                'state'         => bin2hex(random_bytes(16)),
            ]);
            header("Location: $url");
            exit;

        default:
            jsonError('不支援的 SSO 提供者', 400);
    }
}

// ══════════════════════════════════════════════════════════════════
function handleSSOCallback(string $provider): void {
    $code = $_GET['code'] ?? '';
    if (!$code) jsonError('OAuth 授權失敗');

    // Exchange code for token & user info
    $ssoUser = fetchSSOUserInfo($provider, $code);
    if (!$ssoUser) jsonError('無法取得 SSO 使用者資訊');

    // Find or create user
    $user = Database::queryOne(
        "SELECT * FROM users WHERE sso_provider = ? AND sso_id = ? LIMIT 1",
        [$provider, $ssoUser['id']]
    );

    if (!$user) {
        // Check if email already registered
        $user = Database::queryOne("SELECT * FROM users WHERE email = ? LIMIT 1", [$ssoUser['email']]);
        if ($user) {
            // Link SSO to existing account
            Database::execute(
                "UPDATE users SET sso_provider = ?, sso_id = ?, avatar = ?, verifications = JSON_SET(verifications, '$.{$provider}', true) WHERE id = ?",
                [$provider, $ssoUser['id'], $ssoUser['avatar'], $user['id']]
            );
        } else {
            // Create new user
            $uid = Database::insert(
                "INSERT INTO users (email, nickname, avatar, sso_provider, sso_id, role_level, status, verifications, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, 'active', ?, NOW())",
                [
                    $ssoUser['email'],
                    $ssoUser['username'] ?? explode('@', $ssoUser['email'])[0],
                    $ssoUser['avatar'],
                    $provider,
                    $ssoUser['id'],
                    json_encode(['email' => true, $provider => true]),
                ]
            );
            $user = Database::queryOne("SELECT * FROM users WHERE id = ?", [$uid]);
        }
    }

    $token = jwtEncode([
        'uid'        => $user['id'],
        'role_level' => (int)$user['role_level'],
        'email'      => $user['email'],
        'nickname'   => $user['nickname'],
        'exp'        => time() + JWT_EXPIRE,
    ]);

    // Return token to opener window
    echo "<!DOCTYPE html><html><body><script>
        window.opener?.postMessage({type:'sso_success',token:'" . addslashes($token) . "',user:" . json_encode(buildUserPayload($user)) . "},'*');
        window.close();
    </script></body></html>";
    exit;
}

// ══════════════════════════════════════════════════════════════════
function handleBind(string $provider): void {
    $auth = getAuthUser(true);
    // Similar to SSO but for an already-logged-in user
    // Redirect to provider, then link in callback
    handleSSORedirect($provider);
}

// ══════════════════════════════════════════════════════════════════
function handleVerifyEmail(string $token): void {
    if (!$token) jsonError('無效的驗證連結');
    $row = Database::queryOne(
        "SELECT * FROM email_verifications WHERE token = ? AND expires_at > NOW() AND used = 0 LIMIT 1",
        [$token]
    );
    if (!$row) jsonError('驗證連結無效或已過期');

    Database::beginTransaction();
    try {
        Database::execute(
            "UPDATE users SET verifications = JSON_SET(verifications, '$.email', true) WHERE id = ?",
            [$row['user_id']]
        );
        Database::execute("UPDATE email_verifications SET used = 1 WHERE token = ?", [$token]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        jsonError('驗證失敗', 500);
    }
    // Redirect to dashboard
    header('Location: ' . APP_URL . '/dashboard/index.html?verified=email');
    exit;
}

// ══════════════════════════════════════════════════════════════════
function fetchSSOUserInfo(string $provider, string $code): ?array {
    // This would make real OAuth calls. Stub for structure.
    if ($provider === 'discord') {
        // Exchange code for token
        $resp = httpPost('https://discord.com/api/oauth2/token', [
            'client_id'     => DISCORD_CLIENT_ID,
            'client_secret' => DISCORD_CLIENT_SECRET,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => DISCORD_REDIRECT_URI,
        ], ['Content-Type: application/x-www-form-urlencoded']);

        $tokenData = json_decode($resp, true);
        if (!isset($tokenData['access_token'])) return null;

        $user = json_decode(httpGet('https://discord.com/api/users/@me', [
            'Authorization: Bearer ' . $tokenData['access_token']
        ]), true);

        return [
            'id'       => $user['id'],
            'email'    => $user['email'],
            'username' => $user['username'],
            'avatar'   => $user['avatar']
                ? "https://cdn.discordapp.com/avatars/{$user['id']}/{$user['avatar']}.png"
                : null,
        ];
    }

    if ($provider === 'google') {
        $resp = httpPost('https://oauth2.googleapis.com/token', [
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
        ], ['Content-Type: application/x-www-form-urlencoded']);

        $tokenData = json_decode($resp, true);
        if (!isset($tokenData['access_token'])) return null;

        $user = json_decode(httpGet('https://www.googleapis.com/oauth2/v2/userinfo', [
            'Authorization: Bearer ' . $tokenData['access_token']
        ]), true);

        return [
            'id'      => $user['id'],
            'email'   => $user['email'],
            'username'=> $user['name'],
            'avatar'  => $user['picture'] ?? null,
        ];
    }

    return null;
}

// ══════════════════════════════════════════════════════════════════
function buildUserPayload(array $user): array {
    $verifications = is_string($user['verifications'])
        ? json_decode($user['verifications'], true)
        : ($user['verifications'] ?? []);

    return [
        'id'            => $user['id'],
        'email'         => $user['email'],
        'nickname'      => $user['nickname'],
        'avatar'        => $user['avatar'] ?? null,
        'role_level'    => (int)($user['role_level'] ?? 1),
        'points'        => (int)($user['points'] ?? 0),
        'verifications' => $verifications,
        'discord_tag'   => $user['discord_tag'] ?? null,
        'roblox_name'   => $user['roblox_name'] ?? null,
        'phone_masked'  => $user['phone'] ? '●●●●' . substr(aiDecrypt($user['phone']) ?: '', -4) : null,
        'birthday'      => $user['birthday'] ?? null,
    ];
}

// ── HTTP helpers ───────────────────────────────────────────────
function httpPost(string $url, array $data, array $headers = []): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ?: '';
}

function httpGet(string $url, array $headers = []): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ?: '';
}
