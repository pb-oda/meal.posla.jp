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
$user = require_role('manager');
$pdo = get_db();

$data = get_json_body();
$amount = isset($data['amount']) ? (int)$data['amount'] : 0;

if ($amount <= 0) {
    json_error('VALIDATION', '金額は1以上を指定してください', 400);
}

$tenantId = $user['tenant_id'];
$referenceId = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));

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
    $result = create_stripe_connect_terminal_intent($secretKey, $accountId, $amount, 'jpy', $appFee, $referenceId);
} else {
    // ── Pattern A: テナント自前 stripe_secret_key ──
    $gwConfig = get_payment_gateway_config($pdo, $tenantId);
    if (!$gwConfig || $gwConfig['gateway'] !== 'stripe' || empty($gwConfig['token'])) {
        json_error('NOT_CONNECTED', 'Stripe Terminal が利用できる設定がありません', 400);
    }
    $result = create_stripe_terminal_intent($gwConfig['token'], $amount, 'jpy', $referenceId);
}

if (!$result['success']) {
    json_error('STRIPE_ERROR', $result['error'] ?: 'PaymentIntentの作成に失敗しました', 502);
}

json_response([
    'client_secret' => $result['client_secret'],
    'payment_intent_id' => $result['payment_intent_id'],
]);
