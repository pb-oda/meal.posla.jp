<?php
/**
 * Stripe Connect オンボーディング完了コールバック
 * L-13: Stripe からのリダイレクト先。Connect Account のステータスを確認し、
 *        完了していれば connect_onboarding_complete=1 に更新して owner-dashboard にリダイレクト。
 *
 * GET /api/connect/callback.php?tenant_id=xxx
 * ※認証なし（Stripe からのリダイレクト）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/stripe-connect.php';

$tenantId = $_GET['tenant_id'] ?? '';
if (!$tenantId) {
    http_response_code(400);
    echo 'tenant_id が必要です';
    exit;
}

$pdo = get_db();

// テナント取得
$stmt = $pdo->prepare('SELECT id, stripe_connect_account_id FROM tenants WHERE id = ?');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant || !$tenant['stripe_connect_account_id']) {
    http_response_code(400);
    echo 'テナントまたは Connect アカウントが見つかりません';
    exit;
}

// プラットフォーム Stripe 設定取得
$platformConfig = get_platform_stripe_config($pdo);
$secretKey = $platformConfig['secret_key'];

$redirectBase = '/public/admin/owner-dashboard.html';
$connectParam = 'pending';

if ($secretKey) {
    $acctStatus = get_connect_account($secretKey, $tenant['stripe_connect_account_id']);

    if ($acctStatus['success'] && $acctStatus['charges_enabled'] && $acctStatus['details_submitted']) {
        // オンボーディング完了
        $stmt = $pdo->prepare(
            'UPDATE tenants SET connect_onboarding_complete = 1, payment_gateway = ? WHERE id = ?'
        );
        $stmt->execute(['stripe', $tenantId]);
        $connectParam = 'success';
    }
}

// owner-dashboard にリダイレクト
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$redirectUrl = $protocol . '://' . $host . $redirectBase . '?tab=payment&connect=' . $connectParam;

header('Location: ' . $redirectUrl);
exit;
