<?php
/**
 * L-13: Stripe Terminal PaymentIntent 作成 API
 * Connect 経由の物理カードリーダー決済で使用
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

// Connect 情報取得
$connectInfo = get_tenant_connect_info($pdo, $tenantId);
if (!$connectInfo || !(int)$connectInfo['connect_onboarding_complete']) {
    json_error('NOT_CONNECTED', 'Stripe Connect が設定されていません', 400);
}

$accountId = $connectInfo['stripe_connect_account_id'];

// プラットフォーム Stripe 設定取得
$platformConfig = get_platform_stripe_config($pdo);
$secretKey = $platformConfig['secret_key'];
if (!$secretKey) {
    json_error('STRIPE_NOT_CONFIGURED', 'プラットフォームのStripe APIキーが設定されていません', 500);
}

// 手数料計算
$appFee = calculate_application_fee($amount, $platformConfig['fee_percent']);

// PaymentIntent 作成（未確定: Terminal SDK で collectPaymentMethod に使う）
$referenceId = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
$result = create_stripe_connect_terminal_intent($secretKey, $accountId, $amount, 'jpy', $appFee, $referenceId);

if (!$result['success']) {
    json_error('STRIPE_ERROR', $result['error'] ?: 'PaymentIntentの作成に失敗しました', 502);
}

json_response([
    'client_secret' => $result['client_secret'],
    'payment_intent_id' => $result['payment_intent_id'],
]);
