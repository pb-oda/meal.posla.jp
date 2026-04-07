<?php
/**
 * L-8: セルフレジ — Stripe Checkout Session 作成
 *
 * POST /api/customer/checkout-session.php
 * body: { store_id, table_id, session_token }
 *
 * 認証不要（session_token で検証）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/payment-gateway.php';

require_method(['POST']);

$data = get_json_body();
$storeId      = $data['store_id'] ?? null;
$tableId      = $data['table_id'] ?? null;
$sessionToken = $data['session_token'] ?? null;

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

if (!$tableRow || $tableRow['session_token'] !== $sessionToken) {
    json_error('INVALID_SESSION', 'セッションが無効です', 403);
}
if ($tableRow['session_token_expires_at'] && strtotime($tableRow['session_token_expires_at']) < time()) {
    json_error('INVALID_SESSION', 'セッションの有効期限が切れています', 403);
}

// ── self_checkout_enabled チェック ──
$selfCheckoutEnabled = false;
try {
    $stmt = $pdo->prepare('SELECT self_checkout_enabled FROM store_settings WHERE store_id = ?');
    $stmt->execute([$storeId]);
    $ssRow = $stmt->fetch();
    $selfCheckoutEnabled = $ssRow ? (bool)(int)$ssRow['self_checkout_enabled'] : false;
} catch (PDOException $e) {
    $selfCheckoutEnabled = false;
}

if (!$selfCheckoutEnabled) {
    json_error('SELF_CHECKOUT_DISABLED', 'セルフレジは有効になっていません', 403);
}

// ── 未払い注文を取得 ──
$stmt = $pdo->prepare(
    'SELECT id, items, total_amount
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

// ── 税込合計を算出 ──
$totalAmount = 0;
foreach ($orders as $order) {
    $totalAmount += (int)$order['total_amount'];
}

if ($totalAmount <= 0) {
    json_error('INVALID_AMOUNT', '合計金額が不正です', 400);
}

// ── 店舗名を取得 ──
$stmt = $pdo->prepare('SELECT name FROM stores WHERE id = ?');
$stmt->execute([$storeId]);
$storeRow = $stmt->fetch();
$storeName = $storeRow ? $storeRow['name'] : 'お会計';

// ── テナント情報を取得 ──
$stmt = $pdo->prepare('SELECT tenant_id FROM stores WHERE id = ?');
$stmt->execute([$storeId]);
$storeInfo = $stmt->fetch();
if (!$storeInfo) {
    json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
}
$tenantId = $storeInfo['tenant_id'];

// ── success_url / cancel_url を組み立て ──
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base = $scheme . '://' . $host;
$successUrl = $base . '/public/customer/menu.html?store_id=' . urlencode($storeId)
    . '&table_id=' . urlencode($tableId)
    . '&checkout=success&stripe_session_id={CHECKOUT_SESSION_ID}';
$cancelUrl = $base . '/public/customer/menu.html?store_id=' . urlencode($storeId)
    . '&table_id=' . urlencode($tableId)
    . '&checkout=cancel';

$orderName = $storeName . ' お会計';
$currency = 'jpy';

// ── Stripe 決済設定を取得して Checkout Session 作成 ──
$checkoutResult = null;

// 1. Stripe Connect を確認
try {
    require_once __DIR__ . '/../lib/stripe-connect.php';
    $connectInfo = get_tenant_connect_info($pdo, $tenantId);
    if ($connectInfo && (int)$connectInfo['connect_onboarding_complete'] === 1
        && !empty($connectInfo['stripe_connect_account_id'])) {
        $platformConfig = get_platform_stripe_config($pdo);
        if (!empty($platformConfig['secret_key'])) {
            $feePercent = (float)($platformConfig['fee_percent'] ?? 0);
            $applicationFee = (int)floor($totalAmount * $feePercent / 100);
            $checkoutResult = create_stripe_connect_checkout_session(
                $platformConfig['secret_key'],
                $connectInfo['stripe_connect_account_id'],
                $totalAmount,
                $currency,
                $applicationFee,
                $orderName,
                $successUrl,
                $cancelUrl
            );
        }
    }
} catch (Exception $e) {
    // stripe-connect.php 未存在時はフォールスルー
}

// 2. Connect なければ直接 Stripe
if (!$checkoutResult) {
    $stmt = $pdo->prepare('SELECT stripe_secret_key FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $tenantRow = $stmt->fetch();
    $stripeKey = $tenantRow ? ($tenantRow['stripe_secret_key'] ?? null) : null;

    if (!$stripeKey) {
        json_error('PAYMENT_NOT_CONFIGURED', 'オンライン決済が設定されていません', 503);
    }

    $checkoutResult = create_stripe_checkout_session(
        $stripeKey,
        $totalAmount,
        $currency,
        $orderName,
        $successUrl,
        $cancelUrl
    );
}

if (!$checkoutResult || !$checkoutResult['success']) {
    $errMsg = $checkoutResult ? ($checkoutResult['error'] ?? '決済セッション作成に失敗しました') : '決済セッション作成に失敗しました';
    json_error('CHECKOUT_FAILED', $errMsg, 502);
}

json_response([
    'checkout_url' => $checkoutResult['checkout_url'],
    'session_id'   => $checkoutResult['session_id'],
    'total'        => $totalAmount,
]);
