<?php
/**
 * サブスクリプション Checkout API
 * L-11: オーナーが Stripe Checkout でサブスクリプションを開始する
 *
 * POST /api/subscription/checkout.php
 * Body: { "plan": "standard"|"pro"|"enterprise" }
 * Response: { "ok": true, "data": { "checkout_url": "https://checkout.stripe.com/..." } }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-billing.php';

$method = require_method(['POST']);
$user = require_role('owner');
$pdo = get_db();

$input = get_json_body();
$plan = isset($input['plan']) ? $input['plan'] : '';

if (!in_array($plan, ['standard', 'pro', 'enterprise'], true)) {
    json_error('INVALID_PLAN', 'プランは standard / pro / enterprise のいずれかです', 400);
}

// Stripe設定取得
$config = get_stripe_config($pdo);
$secretKey = isset($config['stripe_secret_key']) ? $config['stripe_secret_key'] : '';

if (!$secretKey) {
    json_error('STRIPE_NOT_CONFIGURED', 'Stripe APIキーが設定されていません', 500);
}

// プランに対応する Price ID
$priceKey = 'stripe_price_' . $plan;
$priceId = isset($config[$priceKey]) ? $config[$priceKey] : '';

if (!$priceId) {
    json_error('PRICE_NOT_CONFIGURED', 'このプランの料金設定がされていません', 500);
}

// テナント情報取得
$tenantId = $user['tenant_id'];
$stmt = $pdo->prepare('SELECT id, name, slug, stripe_customer_id FROM tenants WHERE id = ?');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    json_error('TENANT_NOT_FOUND', 'テナントが見つかりません', 404);
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
    $stmt = $pdo->prepare('UPDATE tenants SET stripe_customer_id = ? WHERE id = ?');
    $stmt->execute([$customerId, $tenantId]);
}

// success/cancel URL構築
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host . '/public/admin/owner-dashboard.html';
$successUrl = $baseUrl . '?subscription=success';
$cancelUrl = $baseUrl . '?subscription=cancel';

// Checkout Session 作成
$checkoutResult = create_billing_checkout_session($secretKey, $customerId, $priceId, $successUrl, $cancelUrl);

if (!$checkoutResult['success']) {
    json_error('STRIPE_ERROR', $checkoutResult['error'], 500);
}

json_response(['checkout_url' => $checkoutResult['url']]);
