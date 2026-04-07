<?php
/**
 * Stripe Customer Portal API
 * L-11: オーナーが Customer Portal でプラン変更・カード変更・解約を行う
 *
 * POST /api/subscription/portal.php
 * Response: { "ok": true, "data": { "portal_url": "https://billing.stripe.com/..." } }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-billing.php';

$method = require_method(['POST']);
$user = require_role('owner');
$pdo = get_db();

// Stripe設定取得
$config = get_stripe_config($pdo);
$secretKey = isset($config['stripe_secret_key']) ? $config['stripe_secret_key'] : '';

if (!$secretKey) {
    json_error('STRIPE_NOT_CONFIGURED', 'Stripe APIキーが設定されていません', 500);
}

// テナントの Stripe Customer ID 取得
$tenantId = $user['tenant_id'];
$stmt = $pdo->prepare('SELECT stripe_customer_id FROM tenants WHERE id = ?');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant || !$tenant['stripe_customer_id']) {
    json_error('NO_SUBSCRIPTION', 'サブスクリプションが未設定です。先にCheckoutを完了してください', 400);
}

// return URL構築
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$returnUrl = $protocol . '://' . $host . '/public/admin/owner-dashboard.html';

// Portal Session 作成
$portalResult = create_portal_session($secretKey, $tenant['stripe_customer_id'], $returnUrl);

if (!$portalResult['success']) {
    json_error('STRIPE_ERROR', $portalResult['error'], 500);
}

json_response(['portal_url' => $portalResult['url']]);
