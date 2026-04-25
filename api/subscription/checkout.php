<?php
/**
 * サブスクリプション Checkout API
 * L-11 / P1-35: オーナーが Stripe Checkout で α-1 サブスクリプションを開始する
 *
 * POST /api/subscription/checkout.php
 * Body: { "hq_broadcast": bool }  (任意。デフォルト false)
 * Response: { "ok": true, "data": { "checkout_url": "https://checkout.stripe.com/..." } }
 *
 * α-1 構成:
 *   line_items[0] = stripe_price_base (¥20,000/月 × 1)
 *   line_items[1] = stripe_price_additional_store (¥17,000/月 × max(0, store_count-1))
 *   line_items[2] = stripe_price_hq_broadcast (¥3,000/月 × store_count)  (hq_broadcast=true 時のみ)
 *
 * 初回契約時のみ subscription_data[trial_period_days]=30 を付与（30日無料トライアル）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-billing.php';

$method = require_method(['POST']);
$user = require_role('owner');
$pdo = get_db();

$input = get_json_body();
$hqBroadcast = !empty($input['hq_broadcast']);

// Stripe設定取得
$config = get_stripe_config($pdo);
$secretKey = isset($config['stripe_secret_key']) ? $config['stripe_secret_key'] : '';

if (!$secretKey) {
    json_error('STRIPE_NOT_CONFIGURED', 'Stripe APIキーが設定されていません', 500);
}

// テナント情報取得
$tenantId = $user['tenant_id'];
$stmt = $pdo->prepare(
    'SELECT id, name, slug, stripe_customer_id, stripe_subscription_id, subscription_status
     FROM tenants WHERE id = ?'
);
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    json_error('TENANT_NOT_FOUND', 'テナントが見つかりません', 404);
}

// 既に subscription を持っているオーナーは Customer Portal に誘導する
// (POSLA 側で 1 customer = 1 active subscription の前提を維持)
$existingStatus = $tenant['subscription_status'];
if (!empty($tenant['stripe_subscription_id']) && !in_array($existingStatus, ['none', 'canceled'], true)) {
    json_error('ALREADY_SUBSCRIBED', '既にサブスクリプションがあります。Customer Portal から変更してください', 400);
}

// 店舗数を tenants 配下から自動算出
$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM stores WHERE tenant_id = ? AND is_active = 1');
$cntStmt->execute([$tenantId]);
$storeCount = (int)$cntStmt->fetchColumn();

if ($storeCount < 1) {
    json_error('NO_STORES', '店舗を1つ以上登録してください', 400);
}

// α-1 line_items を組み立て
$lineItems = build_alpha1_line_items($config, $storeCount, $hqBroadcast);
if ($lineItems === null) {
    json_error('PRICE_NOT_CONFIGURED', 'Stripe Price ID (基本/追加店舗/本部一括配信) が設定されていません', 500);
}

// Stripe Customer が未作成なら作成
$customerId = $tenant['stripe_customer_id'];
if (!$customerId) {
    $ownerEmail = isset($user['email']) ? $user['email'] : null;
    $createResult = create_stripe_customer($secretKey, $tenant['name'], $ownerEmail, $tenantId);

    if (!$createResult['success']) {
        json_error('STRIPE_ERROR', $createResult['error'], 500);
    }

    $customerId = $createResult['customer_id'];

    // DBに保存
    $upd = $pdo->prepare('UPDATE tenants SET stripe_customer_id = ? WHERE id = ?');
    $upd->execute([$customerId, $tenantId]);
}

// success/cancel URL構築
$baseUrl = app_url('/admin/owner-dashboard.html');
$successUrl = $baseUrl . '?subscription=success';
$cancelUrl = $baseUrl . '?subscription=cancel';

// 初回契約 (subscription_status='none' かつ stripe_subscription_id 未設定) なら 30日トライアル
$isFirstTime = ($existingStatus === 'none' || $existingStatus === null) && empty($tenant['stripe_subscription_id']);
$extraParams = [];
if ($isFirstTime) {
    $extraParams['subscription_data[trial_period_days]'] = 30;
}

// Checkout Session 作成
$checkoutResult = create_billing_checkout_session(
    $secretKey, $customerId, $lineItems, $successUrl, $cancelUrl, $extraParams
);

if (!$checkoutResult['success']) {
    json_error('STRIPE_ERROR', $checkoutResult['error'], 500);
}

json_response(['checkout_url' => $checkoutResult['url']]);
