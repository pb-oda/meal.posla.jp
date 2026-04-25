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
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-connect.php';

$tenantId = $_GET['tenant_id'] ?? '';
$state    = $_GET['state'] ?? '';
if (!$tenantId) {
    http_response_code(400);
    echo 'tenant_id が必要です';
    exit;
}

// H-10: state parameter 検証（CSRF 対策）
// onboard.php で発行した token と session 値を hash_equals で照合。
// 不一致なら owner-dashboard に state_error としてリダイレクト。
// 外部の悪意ある site からの `<img src=.../callback.php?tenant_id=X>` CSRF を遮断する。
start_auth_session();
$expectedState = $_SESSION['connect_state_' . $tenantId] ?? null;
if (!$state || !$expectedState || !hash_equals((string)$expectedState, (string)$state)) {
    header('Location: ' . app_url('/admin/owner-dashboard.html') . '?tab=payment&connect=state_error');
    exit;
}
// one-time use: 検証成功後に session から削除
unset($_SESSION['connect_state_' . $tenantId]);

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

$redirectBase = '/admin/owner-dashboard.html';
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
$redirectUrl = app_url($redirectBase) . '?tab=payment&connect=' . $connectParam;

header('Location: ' . $redirectUrl);
exit;
