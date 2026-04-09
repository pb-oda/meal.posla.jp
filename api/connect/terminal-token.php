<?php
/**
 * Stripe Terminal Connection Token 発行 API
 * L-13 + P1-1: Pattern B (Connect) と Pattern A (テナント自前 stripe_secret_key) 両対応
 *
 * Pattern B 優先 > Pattern A の順で判定する。
 *
 * POST /api/connect/terminal-token.php
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-connect.php';
require_once __DIR__ . '/../lib/payment-gateway.php';

$method = require_method(['POST']);
// P1a: device ロール（レジ端末）からも呼び出せるように manager 制限を撤廃。
// 認証 + 同テナント縛りで十分な保護を確保する。
$user = require_auth();
$pdo = get_db();

$tenantId = $user['tenant_id'];

// Pattern B 優先判定: Connect オンボーディング完了済か
$connectInfo = get_tenant_connect_info($pdo, $tenantId);
$useConnect = ($connectInfo && (int)$connectInfo['connect_onboarding_complete'] === 1);

$tokenResult = null;

if ($useConnect) {
    // ── Pattern B: プラットフォームキー + Stripe-Account ヘッダ ──
    $accountId = $connectInfo['stripe_connect_account_id'];
    $platformConfig = get_platform_stripe_config($pdo);
    $secretKey = $platformConfig['secret_key'];
    if (!$secretKey) {
        json_error('STRIPE_NOT_CONFIGURED', 'プラットフォームのStripe APIキーが設定されていません', 500);
    }
    $tokenResult = create_terminal_connection_token($secretKey, $accountId);
} else {
    // ── Pattern A: テナント自前 stripe_secret_key ──
    $gwConfig = get_payment_gateway_config($pdo, $tenantId);
    if (!$gwConfig || $gwConfig['gateway'] !== 'stripe' || empty($gwConfig['token'])) {
        json_error('NOT_CONNECTED', 'Stripe Terminal が利用できる設定がありません', 400);
    }
    $tokenResult = create_terminal_connection_token($gwConfig['token'], null);
}

if (!$tokenResult['success']) {
    json_error('STRIPE_ERROR', $tokenResult['error'], 500);
}

json_response(['secret' => $tokenResult['secret']]);
