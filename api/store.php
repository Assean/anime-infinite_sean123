<?php
/**
 * ANIME INFINITE — Store API
 * api/store.php
 */

require_once __DIR__ . '/../config/config.php';

$body   = getJsonBody();
$action = sanitize($body['action'] ?? $_GET['action'] ?? '');

// Webhook doesn't need auth
if ($action === 'payment_webhook') {
    handlePaymentWebhook();
}

$auth = getAuthUser(true);
$uid  = (int)$auth['uid'];

switch ($action) {
    case 'get_products':   getProducts();             break;
    case 'create_order':   createOrder($uid, $body);  break;
    case 'get_order':      getOrder($uid, $body);     break;
    case 'get_orders':     getOrders($uid);           break;
    case 'check_status':   checkOrderStatus($uid, $body); break;
    default: jsonError('未知操作', 400);
}

// ══════════════════════════════════════════════════════════════════
function getProducts(): void {
    $products = Database::query(
        "SELECT id, category, name, sub_title, description, icon,
                price_twd, original_price_twd, discount_pct,
                reward_json, is_active, is_popular, is_recommended,
                stock, sort_order
         FROM store_products
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC"
    );

    foreach ($products as &$p) {
        $p['reward'] = json_decode($p['reward_json'] ?? '{}', true);
        unset($p['reward_json']);
    }

    jsonSuccess(['products' => $products]);
}

// ══════════════════════════════════════════════════════════════════
function createOrder(int $uid, array $body): void {
    $productId = (int)($body['product_id'] ?? 0);
    $payMethod = sanitize($body['pay_method'] ?? 'credit', 20);

    if (!$productId) jsonError('請選擇商品');

    // Verify user's Roblox binding
    $user = Database::queryOne(
        "SELECT nickname, email, verifications, role_level FROM users WHERE id = ? LIMIT 1",
        [$uid]
    );
    if (!$user) jsonError('找不到使用者', 404);

    $v = is_string($user['verifications']) ? json_decode($user['verifications'], true) : [];
    if (empty($v['roblox'])) {
        jsonError('請先完成 Roblox 帳號綁定才能購買商品');
    }

    // Get product
    $product = Database::queryOne(
        "SELECT * FROM store_products WHERE id = ? AND is_active = 1 LIMIT 1",
        [$productId]
    );
    if (!$product) jsonError('商品不存在或已下架');

    // Check stock
    if ($product['stock'] !== null && $product['stock'] <= 0) {
        jsonError('此商品已售完');
    }

    // Calculate final price (VIP discount)
    $price = (int)$product['price_twd'];
    if ((int)$user['role_level'] >= 2) {
        $price = (int)floor($price * 0.9); // VIP 9折
    }

    // Generate order number
    $orderNo = 'AI' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));

    Database::beginTransaction();
    try {
        $orderId = Database::insert(
            "INSERT INTO orders
                (order_no, user_id, product_id, product_name, amount_twd,
                 pay_method, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())",
            [$orderNo, $uid, $productId, $product['name'], $price, $payMethod]
        );

        // Decrement stock if limited
        if ($product['stock'] !== null) {
            Database::execute(
                "UPDATE store_products SET stock = stock - 1 WHERE id = ? AND stock > 0",
                [$productId]
            );
        }

        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        error_log('[CreateOrder] ' . $e->getMessage());
        jsonError('建立訂單失敗，請稍後再試', 500);
    }

    writeAuditLog('create_order', [
        'order_no'  => $orderNo,
        'product'   => $product['name'],
        'amount'    => $price,
        'pay_method'=> $payMethod,
    ], $uid);

    // Build payment URL
    $paymentUrl = buildPaymentUrl($orderNo, $price, $product['name'], $payMethod, $user['email']);

    jsonSuccess([
        'order_id'    => $orderId,
        'order_no'    => $orderNo,
        'amount'      => $price,
        'payment_url' => $paymentUrl,
    ], '訂單建立成功，即將導向付款頁面');
}

// ══════════════════════════════════════════════════════════════════
function buildPaymentUrl(
    string $orderNo,
    int    $amount,
    string $itemName,
    string $method,
    string $email
): ?string {
    if (!PAYMENT_MERCHANT_ID) {
        // Dev mode: simulate payment success
        return null;
    }

    // Example: ECPay AIO integration
    $params = [
        'MerchantID'        => PAYMENT_MERCHANT_ID,
        'MerchantTradeNo'   => $orderNo,
        'MerchantTradeDate' => date('Y/m/d H:i:s'),
        'PaymentType'       => 'aio',
        'TotalAmount'       => $amount,
        'TradeDesc'         => 'Anime Infinite 儲值',
        'ItemName'          => $itemName,
        'ReturnURL'         => APP_URL . '/api/store.php?action=payment_webhook',
        'ClientBackURL'     => APP_URL . '/store.html?paid=1',
        'ChoosePayment'     => match($method) {
            'linepay' => 'LINE_PAY',
            'atm'     => 'ATM',
            default   => 'Credit',
        },
        'EncryptType'       => 1,
        'Email'             => $email,
    ];

    // Generate CheckMacValue
    $params['CheckMacValue'] = generateCheckMac($params);

    $sandbox = PAYMENT_SANDBOX
        ? 'https://payment-stage.ecpay.com.tw'
        : 'https://payment.ecpay.com.tw';

    return $sandbox . '/Cashier/AioCheckOut/V5?' . http_build_query($params);
}

function generateCheckMac(array $params): string {
    ksort($params);
    $str = 'HashKey=' . PAYMENT_HASH_KEY . '&' . http_build_query($params) . '&HashIV=' . PAYMENT_HASH_IV;
    $str = urlencode($str);
    $str = strtolower($str);
    return strtoupper(md5($str));
}

// ══════════════════════════════════════════════════════════════════
function handlePaymentWebhook(): void {
    $raw  = file_get_contents('php://input');
    $data = [];
    parse_str($raw, $data);

    // Verify CheckMacValue to prevent forgery
    $receivedMac = $data['CheckMacValue'] ?? '';
    unset($data['CheckMacValue']);

    if (PAYMENT_MERCHANT_ID) {
        $expected = generateCheckMac($data);
        if (!hash_equals($expected, $receivedMac)) {
            error_log('[Webhook] CheckMacValue mismatch');
            http_response_code(400);
            echo '0|ErrorMessage';
            exit;
        }
    }

    $orderNo     = sanitize($data['MerchantTradeNo'] ?? '');
    $rtnCode     = (int)($data['RtnCode'] ?? 0);
    $tradeNo     = sanitize($data['TradeNo'] ?? '', 30);
    $amountPaid  = (int)($data['TradeAmt'] ?? 0);

    if (!$orderNo) { echo '0|InvalidOrder'; exit; }

    // Fetch order
    $order = Database::queryOne(
        "SELECT * FROM orders WHERE order_no = ? AND status = 'pending' LIMIT 1",
        [$orderNo]
    );

    if (!$order) { echo '1|OK'; exit; } // Already processed

    Database::beginTransaction();
    try {
        if ($rtnCode === 1) {
            // Payment success
            Database::execute(
                "UPDATE orders SET status = 'paid', paid_at = NOW(), gateway_trade_no = ? WHERE order_no = ?",
                [$tradeNo, $orderNo]
            );

            // Grant rewards
            $product = Database::queryOne(
                "SELECT * FROM store_products WHERE id = ? LIMIT 1",
                [$order['product_id']]
            );
            if ($product) {
                grantProductReward($order['user_id'], $product, $order['id']);
            }

            Database::commit();
            writeAuditLog('payment_success', ['order_no' => $orderNo, 'amount' => $amountPaid], $order['user_id']);

            // Push Discord notification
            pushDiscordWebhook("💰 新儲值完成：{$order['product_name']} — NT\${$amountPaid}");

        } else {
            // Payment failed
            Database::execute(
                "UPDATE orders SET status = 'failed', gateway_trade_no = ? WHERE order_no = ?",
                [$tradeNo, $orderNo]
            );
            Database::commit();
            writeAuditLog('payment_failed', ['order_no' => $orderNo, 'rtn_code' => $rtnCode], $order['user_id']);
        }
    } catch (Throwable $e) {
        Database::rollback();
        error_log('[Webhook] ' . $e->getMessage());
        echo '0|ServerError';
        exit;
    }

    echo '1|OK';
    exit;
}

// ══════════════════════════════════════════════════════════════════
function grantProductReward(int $uid, array $product, int $orderId): void {
    $reward = json_decode($product['reward_json'] ?? '{}', true) ?? [];

    // Points
    if (!empty($reward['points'])) {
        $pts = (int)$reward['points'];
        Database::execute("UPDATE users SET points = points + ? WHERE id = ?", [$pts, $uid]);
        Database::execute(
            "INSERT INTO transactions (user_id, tx_type, description, points_delta, ref_id, status, created_at)
             VALUES (?, 'topup', ?, ?, ?, 'success', NOW())",
            [$uid, "購買：{$product['name']}", $pts, $orderId]
        );
    }

    // Battle Pass
    if (!empty($reward['battle_pass_days'])) {
        $days = (int)$reward['battle_pass_days'];
        Database::execute(
            "UPDATE users SET
                battle_pass_expires_at = DATE_ADD(
                    GREATEST(COALESCE(battle_pass_expires_at, NOW()), NOW()),
                    INTERVAL ? DAY)
             WHERE id = ?",
            [$days, $uid]
        );
    }

    // Title
    if (!empty($reward['title'])) {
        Database::execute(
            "INSERT IGNORE INTO user_titles (user_id, title_key, obtained_at) VALUES (?, ?, NOW())",
            [$uid, $reward['title']]
        );
    }

    // Roblox gems
    if (!empty($reward['gems'])) {
        Database::execute(
            "UPDATE users SET roblox_gems = roblox_gems + ? WHERE id = ?",
            [(int)$reward['gems'], $uid]
        );
    }

    // Send in-game reward via Roblox Open Cloud
    if (ROBLOX_API_KEY) {
        sendRobloxReward($uid, $reward);
    }

    // Send station notification
    sendSystemNotification($uid, '購買成功！', "您的「{$product['name']}」已成功發送至帳戶。", 'reward');
}

// ══════════════════════════════════════════════════════════════════
function sendRobloxReward(int $uid, array $reward): void {
    $user = Database::queryOne("SELECT roblox_name FROM users WHERE id = ?", [$uid]);
    if (!$user || !$user['roblox_name']) return;

    // Publish via Roblox MessagingService (Open Cloud)
    $url  = "https://apis.roblox.com/messaging-service/v1/universes/" . ROBLOX_UNIVERSE_ID . "/topics/RewardQueue";
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['message' => json_encode([
            'roblox_name' => $user['roblox_name'],
            'reward'      => $reward,
            'ts'          => time(),
        ])]),
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . ROBLOX_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ══════════════════════════════════════════════════════════════════
function sendSystemNotification(int $uid, string $title, string $content, string $type): void {
    try {
        Database::execute(
            "INSERT INTO notifications (target_type, target_uid, title, content, notif_type, created_at)
             VALUES ('single', ?, ?, ?, ?, NOW())",
            [$uid, $title, $content, $type]
        );
    } catch (Throwable $e) {
        error_log('[Notification] ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
function getOrder(int $uid, array $body): void {
    $orderNo = sanitize($body['order_no'] ?? '', 30);
    if (!$orderNo) jsonError('缺少訂單編號');

    $order = Database::queryOne(
        "SELECT o.*, p.icon FROM orders o
         LEFT JOIN store_products p ON p.id = o.product_id
         WHERE o.order_no = ? AND o.user_id = ? LIMIT 1",
        [$orderNo, $uid]
    );
    if (!$order) jsonError('找不到訂單', 404);
    jsonSuccess(['order' => $order]);
}

// ══════════════════════════════════════════════════════════════════
function getOrders(int $uid): void {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $orders = Database::query(
        "SELECT o.order_no, o.product_name, o.amount_twd, o.pay_method,
                o.status, o.created_at, o.paid_at, p.icon
         FROM orders o
         LEFT JOIN store_products p ON p.id = o.product_id
         WHERE o.user_id = ?
         ORDER BY o.created_at DESC LIMIT ? OFFSET ?",
        [$uid, $limit, $offset]
    );
    jsonSuccess(['orders' => $orders]);
}

// ══════════════════════════════════════════════════════════════════
function checkOrderStatus(int $uid, array $body): void {
    $orderNo = sanitize($body['order_no'] ?? '', 30);
    $order   = Database::queryOne(
        "SELECT status, paid_at FROM orders WHERE order_no = ? AND user_id = ? LIMIT 1",
        [$orderNo, $uid]
    );
    if (!$order) jsonError('找不到訂單', 404);
    jsonSuccess(['status' => $order['status'], 'paid_at' => $order['paid_at']]);
}
