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

// テナント自前 stripe_secret_key 設定済みかチェック（Pattern A 判定用）
$tenantStripeConfigured = false;
try {
    $stmt = $pdo->prepare(
        "SELECT stripe_secret_key, payment_gateway FROM tenants WHERE id = ?"
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if ($row && !empty($row['stripe_secret_key']) && ($row['payment_gateway'] === 'stripe')) {
        $tenantStripeConfigured = true;
    }
} catch (PDOException $e) {
    // カラム未存在時はスキップ
}

// テナントの Connect 情報取得
$connectInfo = get_tenant_connect_info($pdo, $tenantId);

if (!$connectInfo) {
    // Connect 未登録 — Pattern A 判定のみ
    $terminalPattern = $tenantStripeConfigured ? 'A' : null;
    json_response([
        'connected' => false,
        'account_id' => null,
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'onboarding_complete' => false,
        'terminal_pattern' => $terminalPattern,
        'tenant_stripe_configured' => $tenantStripeConfigured,
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

// terminal_pattern 判定: Pattern B（Connect 完了 + charges_enabled）優先 > Pattern A（テナント自前）
$terminalPattern = null;
if ($onboardingComplete === 1 && $chargesEnabled) {
    $terminalPattern = 'B';
} elseif ($tenantStripeConfigured) {
    $terminalPattern = 'A';
}

json_response([
    'connected' => true,
    'account_id' => $accountId,
    'charges_enabled' => $chargesEnabled,
    'payouts_enabled' => $payoutsEnabled,
    'onboarding_complete' => $onboardingComplete === 1,
    'terminal_pattern' => $terminalPattern,
    'tenant_stripe_configured' => $tenantStripeConfigured,
]);
