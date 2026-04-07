<?php
/**
 * Stripe Connect 状態確認 API
 * L-13: 認証済みユーザーが自テナントの Connect 状態を確認する
 *
 * GET /api/connect/status.php
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-connect.php';

$method = require_method(['GET']);
$user = require_auth();
$pdo = get_db();

$tenantId = $user['tenant_id'];

// テナントの Connect 情報取得
$connectInfo = get_tenant_connect_info($pdo, $tenantId);

if (!$connectInfo) {
    json_response([
        'connected' => false,
        'account_id' => null,
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'onboarding_complete' => false,
    ]);
}

$accountId = $connectInfo['stripe_connect_account_id'];
$onboardingComplete = (int)$connectInfo['connect_onboarding_complete'];

// Stripe からリアルタイムステータスを取得
$platformConfig = get_platform_stripe_config($pdo);
$secretKey = $platformConfig['secret_key'];

$chargesEnabled = false;
$payoutsEnabled = false;

if ($secretKey) {
    $acctStatus = get_connect_account($secretKey, $accountId);
    if ($acctStatus['success']) {
        $chargesEnabled = $acctStatus['charges_enabled'];
        $payoutsEnabled = $acctStatus['payouts_enabled'];
    }
}

json_response([
    'connected' => true,
    'account_id' => $accountId,
    'charges_enabled' => $chargesEnabled,
    'payouts_enabled' => $payoutsEnabled,
    'onboarding_complete' => $onboardingComplete === 1,
]);
