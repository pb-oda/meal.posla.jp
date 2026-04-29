<?php
/**
 * L-8: セルフレジ — 未払い注文一覧取得
 *
 * GET /api/customer/get-bill.php?store_id=xxx&table_id=xxx&session_token=xxx
 *
 * 認証不要（session_token で検証）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
// UI-P0-1: Stripe 実設定の有無を checkout-session.php と同じ判定で検査するため
// stripe-connect.php の helper を使う。未配置環境ではフォールバック (tenant
// stripe_secret_key のみで判定) する。
if (file_exists(__DIR__ . '/../lib/stripe-connect.php')) {
    require_once __DIR__ . '/../lib/stripe-connect.php';
}

require_method(['GET']);

// H-03: session_token 推測 + 大量取得 DoS 防御 — 1IP あたり 30 回 / 5 分
check_rate_limit('get-bill', 30, 300);

$storeId      = $_GET['store_id'] ?? null;
$tableId      = $_GET['table_id'] ?? null;
$sessionToken = $_GET['session_token'] ?? null;

if (!$storeId || !$tableId || !$sessionToken) {
    json_error('MISSING_FIELDS', 'store_id, table_id, session_token は必須です', 400);
}

$pdo = get_db();

// ── session_token 検証 ──
$stmt = $pdo->prepare(
    'SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ? AND is_active = 1'
);
$stmt->execute([$tableId, $storeId]);
$tableRow = $stmt->fetch();

if (!$tableRow || !$tableRow['session_token'] || !hash_equals($tableRow['session_token'], (string)$sessionToken)) {
    json_error('INVALID_SESSION', 'セッションが無効です', 403);
}
if ($tableRow['session_token_expires_at'] && strtotime($tableRow['session_token_expires_at']) < time()) {
    json_error('INVALID_SESSION', 'セッションの有効期限が切れています', 403);
}

try {
    $activeStmt = $pdo->prepare(
        "SELECT id FROM table_sessions
         WHERE table_id = ? AND store_id = ?
           AND status IN ('seated', 'eating', 'bill_requested')
         LIMIT 1"
    );
    $activeStmt->execute([$tableId, $storeId]);
    if (!$activeStmt->fetch()) {
        json_error('INVALID_SESSION', 'セッションが無効です', 403);
    }
} catch (PDOException $e) {
    // table_sessions 未作成時は従来動作
}

// ── 未払い注文を取得 ──
$stmt = $pdo->prepare(
    'SELECT id, items, total_amount, status, created_at
     FROM orders
     WHERE table_id = ? AND store_id = ? AND session_token = ?
       AND status NOT IN (\'paid\', \'cancelled\')
     ORDER BY created_at ASC'
);
$stmt->execute([$tableId, $storeId, $sessionToken]);
$orders = $stmt->fetchAll();

if (empty($orders)) {
    json_error('NO_UNPAID_ORDERS', '未払いの注文がありません', 404);
}

// ── store_settings から self_checkout_enabled + payment_gateway を取得 ──
$selfCheckoutEnabled = false;
$paymentAvailable = false;
try {
    $stmt = $pdo->prepare(
        'SELECT ss.self_checkout_enabled
         FROM store_settings ss
         WHERE ss.store_id = ?'
    );
    $stmt->execute([$storeId]);
    $ssRow = $stmt->fetch();
    $selfCheckoutEnabled = $ssRow ? (bool)(int)$ssRow['self_checkout_enabled'] : false;
} catch (PDOException $e) {
    // self_checkout_enabled カラム未存在時は false
    $selfCheckoutEnabled = false;
}

// UI-P0-1: payment_gateway + Stripe 実設定 (Connect 完了 or tenant key) を
// 併せて判定することで checkout-session.php が PAYMENT_NOT_CONFIGURED を
// 返す状況と整合する (button が出るのに押すと失敗する不整合を防ぐ)。
$stmt = $pdo->prepare(
    'SELECT t.id AS tenant_id, t.payment_gateway, t.stripe_secret_key
     FROM stores s
     JOIN tenants t ON t.id = s.tenant_id
     WHERE s.id = ?'
);
$stmt->execute([$storeId]);
$gwRow = $stmt->fetch();
$paymentGateway = $gwRow ? ($gwRow['payment_gateway'] ?? 'none') : 'none';
$tenantId       = $gwRow ? ($gwRow['tenant_id'] ?? null) : null;
$tenantStripeKey = $gwRow ? ($gwRow['stripe_secret_key'] ?? null) : null;

$stripeReady = false;
// (a) Stripe Connect 完了 + platform secret_key
if ($tenantId && function_exists('get_tenant_connect_info') && function_exists('get_platform_stripe_config')) {
    try {
        $connectInfo = get_tenant_connect_info($pdo, $tenantId);
        if ($connectInfo
            && (int)($connectInfo['connect_onboarding_complete'] ?? 0) === 1
            && !empty($connectInfo['stripe_connect_account_id'])) {
            $platformConfig = get_platform_stripe_config($pdo);
            if (!empty($platformConfig['secret_key'])) {
                $stripeReady = true;
            }
        }
    } catch (Exception $e) {
        // helper 内エラーはフォールバック路線へ
    }
}
// (b) tenants.stripe_secret_key 設定済み
if (!$stripeReady && !empty($tenantStripeKey)) {
    $stripeReady = true;
}

$paymentAvailable = $selfCheckoutEnabled
                 && $paymentGateway !== 'none'
                 && $stripeReady;

// ── 税率計算 ──
$subtotal10 = 0;
$tax10 = 0;
$subtotal8 = 0;
$tax8 = 0;
$totalAmount = 0;

$responseOrders = [];
foreach ($orders as $order) {
    $totalAmount += (int)$order['total_amount'];

    $items = json_decode($order['items'] ?? '[]', true);
    if (!is_array($items)) $items = [];

    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? 1);
        $price = (int)($item['price'] ?? 0);
        $lineTotal = $price * $qty;
        $taxRate = isset($item['taxRate']) ? (int)$item['taxRate'] : 10;

        if ($taxRate === 8) {
            $sub = (int)floor($lineTotal / 1.08);
            $subtotal8 += $sub;
            $tax8 += $lineTotal - $sub;
        } else {
            $sub = (int)floor($lineTotal / 1.10);
            $subtotal10 += $sub;
            $tax10 += $lineTotal - $sub;
        }
    }

    $responseOrders[] = [
        'id'           => $order['id'],
        'items'        => $items,
        'total_amount' => (int)$order['total_amount'],
        'created_at'   => $order['created_at'],
    ];
}

json_response([
    'orders'  => $responseOrders,
    'summary' => [
        'subtotal_10' => $subtotal10,
        'tax_10'      => $tax10,
        'subtotal_8'  => $subtotal8,
        'tax_8'       => $tax8,
        'total'       => $totalAmount,
    ],
    'self_checkout_enabled' => $selfCheckoutEnabled,
    'payment_available'     => $paymentAvailable,
]);
