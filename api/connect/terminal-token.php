<?php
/**
 * Stripe Terminal Connection Token 発行 API
 * L-13: Connect Account 用の Terminal トークンを発行する
 *
 * POST /api/connect/terminal-token.php
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-connect.php';

$method = require_method(['POST']);
$user = require_role('manager');
$pdo = get_db();

$tenantId = $user['tenant_id'];

// テナントの Connect 情報取得
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

// Connection Token 発行
$tokenResult = create_terminal_connection_token($secretKey, $accountId);
if (!$tokenResult['success']) {
    json_error('STRIPE_ERROR', $tokenResult['error'], 500);
}

json_response(['secret' => $tokenResult['secret']]);
