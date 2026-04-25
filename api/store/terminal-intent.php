<?php
/**
 * Stripe Terminal PaymentIntent 作成 API
 * L-13 + P1-1: Pattern B (Connect) と Pattern A (テナント自前 stripe_secret_key) 両対応
 *
 * Pattern B 優先 > Pattern A の順で判定する。
 *
 * POST /api/store/terminal-intent.php
 * Body: { amount: int (円) }
 *
 * Returns: { client_secret, payment_intent_id }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/payment-gateway.php';
require_once __DIR__ . '/../lib/stripe-connect.php';

$method = require_method(['POST']);
// P1a: device ロール（レジ端末）からも呼び出せるように manager 制限を撤廃。
// 認証 + 同テナント縛りで十分な保護を確保する。
$user = require_auth();
$pdo = get_db();

$data = get_json_body();
$amount   = isset($data['amount']) ? (int)$data['amount'] : 0;
$storeId  = isset($data['store_id']) ? (string)$data['store_id'] : '';
$tableId  = isset($data['table_id']) ? (string)$data['table_id'] : '';
$orderIds = isset($data['order_ids']) && is_array($data['order_ids']) ? $data['order_ids'] : [];

if ($amount <= 0) {
    json_error('VALIDATION', '金額は1以上を指定してください', 400);
}
if (!$storeId) {
    json_error('VALIDATION', 'store_id は必須です', 400);
}
if (!$tableId && empty($orderIds)) {
    json_error('VALIDATION', 'table_id または order_ids が必要です', 400);
}
require_store_access($storeId);

// S2 P0 #4: サーバー側で正規金額を再計算してクライアント送信値と照合
$expectedAmount = 0;
if (!empty($orderIds)) {
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $ordStmt = $pdo->prepare(
        "SELECT total_amount FROM orders
         WHERE store_id = ? AND id IN ($ph) AND status NOT IN ('paid', 'cancelled')"
    );
    $ordStmt->execute(array_merge([$storeId], $orderIds));
    foreach ($ordStmt->fetchAll() as $o) {
        $expectedAmount += (int)$o['total_amount'];
    }
} else {
    $ordStmt = $pdo->prepare(
        "SELECT total_amount FROM orders
         WHERE store_id = ? AND table_id = ? AND status NOT IN ('paid', 'cancelled')"
    );
    $ordStmt->execute([$storeId, $tableId]);
    foreach ($ordStmt->fetchAll() as $o) {
        $expectedAmount += (int)$o['total_amount'];
    }
}

if ($expectedAmount <= 0) {
    json_error('NOT_FOUND', '対象の未会計注文が見つかりません', 404);
}
if ($amount !== $expectedAmount) {
    error_log('[terminal-intent] amount_mismatch: client=' . $amount . ' server=' . $expectedAmount . ' store=' . $storeId, 3, POSLA_PHP_ERROR_LOG);
    json_error('AMOUNT_MISMATCH', '金額がサーバー側計算値と一致しません', 400);
}

$tenantId    = $user['tenant_id'];
$referenceId = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));

// PaymentIntent metadata に注文紐付け情報を埋込 (process-payment.php で照合)
$intentMetadata = [
    'tenant_id'       => $tenantId,
    'store_id'        => $storeId,
    'table_id'        => $tableId,
    'order_ids'       => implode(',', $orderIds),
    'expected_amount' => (string)$expectedAmount,
    'purpose'         => 'pos_terminal',
];

// Pattern B 優先判定: Connect オンボーディング完了済か
$connectInfo = get_tenant_connect_info($pdo, $tenantId);
$useConnect = ($connectInfo && (int)$connectInfo['connect_onboarding_complete'] === 1);

$result = null;

if ($useConnect) {
    // ── Pattern B: プラットフォームキー + Connect Account + application_fee ──
    $accountId = $connectInfo['stripe_connect_account_id'];
    $platformConfig = get_platform_stripe_config($pdo);
    $secretKey = $platformConfig['secret_key'];
    if (!$secretKey) {
        json_error('STRIPE_NOT_CONFIGURED', 'プラットフォームのStripe APIキーが設定されていません', 500);
    }
    $appFee = calculate_application_fee($amount, $platformConfig['fee_percent']);
    $result = create_stripe_connect_terminal_intent($secretKey, $accountId, $amount, 'jpy', $appFee, $referenceId, $intentMetadata);
} else {
    // ── Pattern A: テナント自前 stripe_secret_key ──
    $gwConfig = get_payment_gateway_config($pdo, $tenantId);
    if (!$gwConfig || $gwConfig['gateway'] !== 'stripe' || empty($gwConfig['token'])) {
        json_error('NOT_CONNECTED', 'Stripe Terminal が利用できる設定がありません', 400);
    }
    $result = create_stripe_terminal_intent($gwConfig['token'], $amount, 'jpy', $referenceId, $intentMetadata);
}

if (!$result['success']) {
    json_error('STRIPE_ERROR', $result['error'] ?: 'PaymentIntentの作成に失敗しました', 502);
}

json_response([
    'client_secret' => $result['client_secret'],
    'payment_intent_id' => $result['payment_intent_id'],
]);
