<?php
/**
 * サブスクリプション状態取得 API
 * L-11 / P1-35: オーナーが自テナントのサブスク状態を確認する
 *
 * GET /api/subscription/status.php
 * Response (P1-35 拡張):
 *   {
 *     "ok": true,
 *     "data": {
 *       "plan": null,                  // 互換のため残置 (常に null)
 *       "subscription_status": "trialing"|"active"|"past_due"|"canceled"|"none",
 *       "current_period_end": "...",
 *       "has_subscription": bool,
 *       "store_count": int,
 *       "has_hq_broadcast": bool,
 *       "monthly_total": int,          // 円
 *       "pricing_breakdown": [
 *         { "label":"基本料金", "unit":20000, "qty":1, "subtotal":20000 },
 *         { "label":"追加店舗", "unit":17000, "qty":N-1, "subtotal":... },
 *         { "label":"本部一括配信", "unit":3000, "qty":N, "subtotal":... }  // 任意
 *       ]
 *     }
 *   }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-billing.php';

// α-1 価格定義 (POSLA 側で固定管理。Stripe からの動的取得は不要)
define('ALPHA1_PRICE_BASE', 20000);
define('ALPHA1_PRICE_ADDITIONAL_STORE', 17000);
define('ALPHA1_PRICE_HQ_BROADCAST', 3000);

$method = require_method(['GET']);
$user = require_role('owner');
$pdo = get_db();

$tenantId = $user['tenant_id'];

$stmt = $pdo->prepare(
    'SELECT subscription_status, current_period_end, stripe_subscription_id, stripe_customer_id, hq_menu_broadcast
     FROM tenants WHERE id = ?'
);
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    json_error('TENANT_NOT_FOUND', 'テナントが見つかりません', 404);
}

// 店舗数を tenants 配下から自動算出
$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM stores WHERE tenant_id = ? AND is_active = 1');
$cntStmt->execute([$tenantId]);
$storeCount = (int)$cntStmt->fetchColumn();
if ($storeCount < 1) $storeCount = 1;

$hasHqBroadcast = !empty($tenant['hq_menu_broadcast']);

// 価格内訳を組み立て
$pricingBreakdown = [];
$pricingBreakdown[] = [
    'label'    => '基本料金',
    'unit'     => ALPHA1_PRICE_BASE,
    'qty'      => 1,
    'subtotal' => ALPHA1_PRICE_BASE,
];

if ($storeCount > 1) {
    $addQty = $storeCount - 1;
    $pricingBreakdown[] = [
        'label'    => '追加店舗',
        'unit'     => ALPHA1_PRICE_ADDITIONAL_STORE,
        'qty'      => $addQty,
        'subtotal' => ALPHA1_PRICE_ADDITIONAL_STORE * $addQty,
    ];
}

if ($hasHqBroadcast) {
    $pricingBreakdown[] = [
        'label'    => '本部一括配信',
        'unit'     => ALPHA1_PRICE_HQ_BROADCAST,
        'qty'      => $storeCount,
        'subtotal' => ALPHA1_PRICE_HQ_BROADCAST * $storeCount,
    ];
}

$monthlyTotal = 0;
foreach ($pricingBreakdown as $line) {
    $monthlyTotal += $line['subtotal'];
}

$responseData = [
    'plan'                => null, // P1-35: 互換のため残置 (旧 standard/pro/enterprise は廃止)
    'subscription_status' => $tenant['subscription_status'],
    'current_period_end'  => $tenant['current_period_end'],
    'has_subscription'    => !empty($tenant['stripe_subscription_id']),
    'store_count'         => $storeCount,
    'has_hq_broadcast'    => $hasHqBroadcast,
    'monthly_total'       => $monthlyTotal,
    'pricing_breakdown'   => $pricingBreakdown,
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
