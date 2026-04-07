<?php
/**
 * サブスクリプション状態取得 API
 * L-11: オーナーが自テナントのサブスク状態を確認する
 *
 * GET /api/subscription/status.php
 * Response: { "ok": true, "data": { "plan": "...", "subscription_status": "...", ... } }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-billing.php';

$method = require_method(['GET']);
$user = require_role('owner');
$pdo = get_db();

$tenantId = $user['tenant_id'];

$stmt = $pdo->prepare(
    'SELECT plan, subscription_status, current_period_end, stripe_subscription_id, stripe_customer_id
     FROM tenants WHERE id = ?'
);
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    json_error('TENANT_NOT_FOUND', 'テナントが見つかりません', 404);
}

$responseData = [
    'plan'                => $tenant['plan'],
    'subscription_status' => $tenant['subscription_status'],
    'current_period_end'  => $tenant['current_period_end'],
    'has_subscription'    => !empty($tenant['stripe_subscription_id']),
];

// Stripe から最新情報を取得（オプション）
if ($tenant['stripe_subscription_id']) {
    $config = get_stripe_config($pdo);
    $secretKey = isset($config['stripe_secret_key']) ? $config['stripe_secret_key'] : '';

    if ($secretKey) {
        $subResult = get_subscription($secretKey, $tenant['stripe_subscription_id']);
        if ($subResult['success'] && $subResult['data']) {
            $subData = $subResult['data'];
            $responseData['stripe_status'] = isset($subData['status']) ? $subData['status'] : null;

            if (isset($subData['current_period_end'])) {
                $responseData['stripe_period_end'] = date('c', (int)$subData['current_period_end']);
            }
        }
    }
}

json_response($responseData);
