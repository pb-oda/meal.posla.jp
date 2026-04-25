<?php
/**
 * Stripe Connect オンボーディング開始 API
 * L-13: オーナーが Connect Express Account を作成し、オンボーディングURLを取得する
 *
 * POST /api/connect/onboard.php
 * Response: { "ok": true, "data": { "url": "https://connect.stripe.com/..." } }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-connect.php';

$method = require_method(['POST']);
$user = require_role('owner');
$pdo = get_db();

$tenantId = $user['tenant_id'];

// プラットフォーム Stripe 設定取得
$platformConfig = get_platform_stripe_config($pdo);
$secretKey = $platformConfig['secret_key'];
if (!$secretKey) {
    json_error('STRIPE_NOT_CONFIGURED', 'プラットフォームのStripe APIキーが設定されていません', 500);
}

// テナント情報取得
$stmt = $pdo->prepare('SELECT id, name, stripe_connect_account_id FROM tenants WHERE id = ?');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();
if (!$tenant) {
    json_error('TENANT_NOT_FOUND', 'テナントが見つかりません', 404);
}

$accountId = $tenant['stripe_connect_account_id'];

// H-10: Connect callback 用 state token（CSRF 対策）
// require_role('owner') で既に session はアクティブ。random 32 桁を発行し、
// tenant_id と紐付けて $_SESSION に保存、callback.php で照合する。
// state は one-time use で、callback 側で hash_equals 照合後にクリアされる。
$stateToken = bin2hex(random_bytes(16));
$_SESSION['connect_state_' . $tenantId] = $stateToken;

// URL構築
$refreshUrl = app_url('/admin/owner-dashboard.html') . '?connect=refresh';
$returnUrl = app_url('/api/connect/callback.php') . '?tenant_id=' . urlencode($tenantId)
    . '&state=' . urlencode($stateToken);

if ($accountId) {
    // 既にアカウントあり → ステータス確認
    $acctStatus = get_connect_account($secretKey, $accountId);
    if ($acctStatus['success'] && $acctStatus['details_submitted']) {
        json_error('ALREADY_ONBOARDED', 'オンボーディングは既に完了しています', 400);
    }
    // details_submitted=false → オンボーディング途中離脱。アカウントリンク再生成
} else {
    // アカウント未作成 → 新規作成
    $ownerEmail = isset($user['email']) ? $user['email'] : null;
    $createResult = create_connect_account($secretKey, $tenant['name'], $ownerEmail);
    if (!$createResult['success']) {
        json_error('STRIPE_ERROR', $createResult['error'], 500);
    }
    $accountId = $createResult['account_id'];

    // DB保存
    $stmt = $pdo->prepare('UPDATE tenants SET stripe_connect_account_id = ? WHERE id = ?');
    $stmt->execute([$accountId, $tenantId]);
}

// アカウントリンク生成
$linkResult = create_account_link($secretKey, $accountId, $refreshUrl, $returnUrl);
if (!$linkResult['success']) {
    json_error('STRIPE_ERROR', $linkResult['error'], 500);
}

json_response(['url' => $linkResult['url']]);
