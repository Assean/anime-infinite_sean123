<?php
/**
 * ANIME INFINITE — Application Configuration
 * config/config.php
 */

// ── Environment ────────────────────────────────────────────────
define('APP_ENV',     getenv('APP_ENV')  ?: 'production');   // development | production
define('APP_DEBUG',   APP_ENV === 'development');
define('APP_URL',     getenv('APP_URL')  ?: 'https://anime-infinite.com');
define('APP_VERSION', '1.0.0');

// ── Security ───────────────────────────────────────────────────
define('JWT_SECRET',      getenv('JWT_SECRET')      ?: 'CHANGE_THIS_SUPER_SECRET_KEY_32_CHARS');
define('JWT_EXPIRE',      60 * 60 * 24 * 7);         // 7 days (seconds)
define('JWT_EXPIRE_LONG', 60 * 60 * 24 * 30);        // 30 days
define('AES_KEY',         getenv('AES_KEY')         ?: 'CHANGE_THIS_AES_256_KEY_32BYTES!');
define('AES_IV',          getenv('AES_IV')          ?: '16_BYTES_IV_HERE');

// Admin login layers
define('ADMIN_VERIFY_PASS_HASH', getenv('ADMIN_VERIFY_HASH') ?: password_hash('admin_verify_default', PASSWORD_ARGON2ID));

// ── Roblox ─────────────────────────────────────────────────────
define('ROBLOX_API_KEY',    getenv('ROBLOX_API_KEY')    ?: '');
define('ROBLOX_UNIVERSE_ID',getenv('ROBLOX_UNIVERSE_ID')?: '');
define('ROBLOX_PLACE_ID',   getenv('ROBLOX_PLACE_ID')   ?: '');

// ── Discord ────────────────────────────────────────────────────
define('DISCORD_BOT_TOKEN',   getenv('DISCORD_BOT_TOKEN')   ?: '');
define('DISCORD_CLIENT_ID',   getenv('DISCORD_CLIENT_ID')   ?: '');
define('DISCORD_CLIENT_SECRET',getenv('DISCORD_CLIENT_SECRET')?: '');
define('DISCORD_REDIRECT_URI', APP_URL . '/api/auth.php?action=sso_callback&provider=discord');
define('DISCORD_GUILD_ID',    getenv('DISCORD_GUILD_ID')    ?: '');
define('DISCORD_WEBHOOK_URL', getenv('DISCORD_WEBHOOK_URL') ?: '');

// ── Google OAuth ───────────────────────────────────────────────
define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI',  APP_URL . '/api/auth.php?action=sso_callback&provider=google');

// ── Payment Gateway (e.g., ECPay / Stripe) ────────────────────
define('PAYMENT_MERCHANT_ID',  getenv('PAYMENT_MERCHANT_ID')  ?: '');
define('PAYMENT_HASH_KEY',     getenv('PAYMENT_HASH_KEY')     ?: '');
define('PAYMENT_HASH_IV',      getenv('PAYMENT_HASH_IV')      ?: '');
define('PAYMENT_WEBHOOK_SECRET',getenv('PAYMENT_WEBHOOK_SECRET')?: '');
define('PAYMENT_SANDBOX',      APP_ENV === 'development');

// ── Email (SMTP) ───────────────────────────────────────────────
define('SMTP_HOST',     getenv('SMTP_HOST')     ?: 'smtp.gmail.com');
define('SMTP_PORT',     getenv('SMTP_PORT')     ?: 587);
define('SMTP_USER',     getenv('SMTP_USER')     ?: '');
define('SMTP_PASS',     getenv('SMTP_PASS')     ?: '');
define('SMTP_FROM',     getenv('SMTP_FROM')     ?: 'noreply@anime-infinite.com');
define('SMTP_FROM_NAME','Anime Infinite');

// ── SMS ────────────────────────────────────────────────────────
define('SMS_API_KEY',  getenv('SMS_API_KEY')  ?: '');
define('SMS_SENDER',   'AnimeInfnit');

// ── Rate Limiting ──────────────────────────────────────────────
define('RATE_LIMIT_LOGIN',   5);   // max 5 login attempts per window
define('RATE_LIMIT_API',     60);  // max 60 API requests per window
define('RATE_LIMIT_WINDOW',  60);  // window in seconds
define('RATE_LIMIT_REDEEM',  10);  // max 10 redeem attempts per hour

// ── Role Levels ────────────────────────────────────────────────
const ROLE_LEVELS = [
    1  => 'User',
    2  => 'VIP',
    3  => 'YouTuber',
    4  => 'TechContributor',
    5  => 'GuideExpert',
    6  => 'CommunityLeader',
    7  => 'PioneerTester',
    8  => 'Helper',
    9  => 'SupportTech',
    10 => 'BasicAdmin',
    11 => 'AdvancedAdmin',
    12 => 'SuperAdmin',
];

const ROLE_NAMES_ZH = [
    1  => '一般使用者',
    2  => 'VIP 使用者',
    3  => 'YouTuber',
    4  => '技術貢獻者',
    5  => '攻略達人',
    6  => '社群領袖',
    7  => '先鋒測試員',
    8  => '小幫手',
    9  => '客服技術人員',
    10 => '基本管理員',
    11 => '進階管理員',
    12 => '總管理員',
];

// ── Maintenance mode ───────────────────────────────────────────
define('MAINTENANCE_FILE', __DIR__ . '/../.maintenance');

function isMaintenanceMode(): bool {
    return file_exists(MAINTENANCE_FILE);
}

// ── CORS ───────────────────────────────────────────────────────
$allowedOrigins = [
    APP_URL,
    'http://localhost',
    'http://localhost:8080',
    'http://127.0.0.1',
];

function setCORSHeaders(): void {
    global $allowedOrigins;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── JSON output ────────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonSuccess(array $data = [], string $message = 'ok'): void {
    jsonResponse(array_merge(['success' => true, 'message' => $message], $data));
}

function jsonError(string $message, int $code = 400, array $extra = []): void {
    jsonResponse(array_merge(['success' => false, 'message' => $message], $extra), $code);
}

// ── Rate Limiter (APCu or file-based fallback) ─────────────────
function checkRateLimit(string $key, int $max, int $window): bool {
    $cacheKey = "rl_{$key}";
    if (function_exists('apcu_fetch')) {
        $count = apcu_fetch($cacheKey) ?: 0;
        if ($count >= $max) return false;
        apcu_store($cacheKey, $count + 1, $window);
        return true;
    }
    // File-based fallback
    $file = sys_get_temp_dir() . '/ai_rl_' . md5($cacheKey) . '.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $now  = time();
    if (isset($data['expires']) && $data['expires'] < $now) $data = [];
    $count = ($data['count'] ?? 0);
    if ($count >= $max) return false;
    file_put_contents($file, json_encode(['count' => $count + 1, 'expires' => $now + $window]));
    return true;
}

// ── AES-256-CBC Encrypt / Decrypt ─────────────────────────────
function aiEncrypt(string $plaintext): string {
    $iv         = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', AES_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ciphertext);
}

function aiDecrypt(string $encoded): ?string {
    $decoded    = base64_decode($encoded);
    $iv         = substr($decoded, 0, 16);
    $ciphertext = substr($decoded, 16);
    $result     = openssl_decrypt($ciphertext, 'AES-256-CBC', AES_KEY, OPENSSL_RAW_DATA, $iv);
    return $result !== false ? $result : null;
}

// ── JWT ────────────────────────────────────────────────────────
function jwtEncode(array $payload): string {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(base64url_decode($payload), true);
    if (!$data || (isset($data['exp']) && $data['exp'] < time())) return null;
    return $data;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

// ── Get current auth user from JWT Bearer header ───────────────
function getAuthUser(bool $require = true): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        if ($require) jsonError('UNAUTHORIZED', 401);
        return null;
    }
    $payload = jwtDecode($m[1]);
    if (!$payload) {
        if ($require) jsonError('UNAUTHORIZED', 401);
        return null;
    }
    return $payload;
}

function requireAdmin(int $minLevel = 8): array {
    $user = getAuthUser(true);
    if (($user['role_level'] ?? 0) < $minLevel) {
        jsonError('FORBIDDEN', 403);
    }
    return $user;
}

// ── Audit log writer ───────────────────────────────────────────
function writeAuditLog(string $action, array $context = [], ?int $actorId = null): void {
    try {
        require_once __DIR__ . '/database.php';
        Database::execute(
            "INSERT INTO audit_logs (actor_id, action, context, ip, ua, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $actorId,
                $action,
                json_encode($context, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]
        );
    } catch (Throwable $e) {
        error_log('[Audit] ' . $e->getMessage());
    }
}

// ── Discord Webhook push ───────────────────────────────────────
function pushDiscordWebhook(string $content, array $embeds = []): bool {
    if (!DISCORD_WEBHOOK_URL) return false;
    $payload = ['content' => $content];
    if ($embeds) $payload['embeds'] = $embeds;
    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

// ── Maintenance redirect ───────────────────────────────────────
function checkMaintenance(): void {
    if (!isMaintenanceMode()) return;
    $user = getAuthUser(false);
    if ($user && ($user['role_level'] ?? 0) >= 8) return; // admins pass through
    header('Location: /maintenance/index.html');
    exit;
}

// ── Request body helper ────────────────────────────────────────
function getJsonBody(): array {
    static $body = null;
    if ($body === null) {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
    }
    return $body;
}

// ── Input sanitiser ───────────────────────────────────────────
function sanitize(string $input, int $maxLen = 255): string {
    return mb_substr(strip_tags(trim($input)), 0, $maxLen);
}

// ── Generate random code ───────────────────────────────────────
function generateCode(int $length = 10): string {
    $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $result;
}

// ── Init ───────────────────────────────────────────────────────
date_default_timezone_set('Asia/Taipei');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
if (!APP_DEBUG) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
setCORSHeaders();
require_once __DIR__ . '/database.php';
