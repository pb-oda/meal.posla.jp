<?php
/**
 * テイクアウト オンライン決済確認 API（顧客向け・認証不要）
 *
 * GET ?order_id=xxx&session_id=xxx（Stripe用）
 * P1-2: Square 削除済み
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/payment-gateway.php';

$method = require_method(['GET']);
$pdo = get_db();

$orderId = $_GET['order_id'] ?? null;
$sessionId = $_GET['session_id'] ?? null;

if (!$orderId) json_error('MISSING_ORDER', 'order_idが必要です', 400);

// 注文取得（pending_payment のもののみ）
$stmt = $pdo->prepare(
    "SELECT o.id, o.store_id, o.status, o.total_amount, o.memo,
            s.tenant_id
     FROM orders o
     JOIN stores s ON s.id = o.store_id
     WHERE o.id = ? AND o.order_type = 'takeout'"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) json_error('NOT_FOUND', '注文が見つかりません', 404);

// 既に決済確認済みの場合
if ($order['status'] !== 'pending_payment') {
    json_response([
        'order_id' => $orderId,
        'payment_confirmed' => ($order['status'] !== 'cancelled'),
        'status' => $order['status'],
    ]);
}

$verified = false;
$gatewayName = '';
$externalPaymentId = '';
$gatewayStatus = '';

// L-13: Stripe Connect 経由の決済検証（プラットフォームキーで検証）
$connectUsed = false;
try {
    require_once __DIR__ . '/../lib/stripe-connect.php';
    $connectInfo = get_tenant_connect_info($pdo, $order['tenant_id']);
    if ($connectInfo && (int)$connectInfo['connect_onboarding_complete'] === 1) {
        $platformConfig = get_platform_stripe_config($pdo);
        if ($platformConfig['secret_key']) {
            // session_id 取得（URLパラメータ or 注文メモ）
            if (!$sessionId) {
                if (preg_match('/\[stripe_session:([^\]]+)\]/', $order['memo'] ?? '', $m)) {
                    $sessionId = $m[1];
                }
            }
            if ($sessionId) {
                $result = verify_stripe_checkout($platformConfig['secret_key'], $sessionId);
                if ($result['success']) {
                    $verified = true;
                    $gatewayName = 'stripe_connect';
                    $externalPaymentId = $result['payment_intent_id'] ?: $sessionId;
                    $gatewayStatus = $result['payment_status'] ?: 'paid';
                    $connectUsed = true;
                } else {
                    json_error('PAYMENT_NOT_CONFIRMED', $result['error'] ?: '決済が確認できませんでした', 402);
                }
            }
        }
    }
} catch (Exception $e) {
    // stripe-connect.php 未存在やカラム未存在時はスキップ（C-3にフォールスルー）
}

// C-3 / P1-2: 従来の直接決済検証（Connect 未使用 + Stripe のみ）
if (!$connectUsed) {
    $gwConfig = get_payment_gateway_config($pdo, $order['tenant_id']);
    if (!$gwConfig || $gwConfig['gateway'] !== 'stripe') {
        json_error('NO_GATEWAY', '決済ゲートウェイが設定されていません', 500);
    }

    // Stripe: セッションID で確認
    // URLパラメータのsession_idが無い場合、注文メモから取得
    if (!$sessionId) {
        if (preg_match('/\[stripe_session:([^\]]+)\]/', $order['memo'] ?? '', $m)) {
            $sessionId = $m[1];
        }
    }
    if (!$sessionId) json_error('MISSING_SESSION', 'session_idが必要です', 400);

    $result = verify_stripe_checkout($gwConfig['token'], $sessionId);
    if ($result['success']) {
        $verified = true;
        $gatewayName = 'stripe';
        $externalPaymentId = $result['payment_intent_id'] ?: $sessionId;
        $gatewayStatus = $result['payment_status'] ?: 'paid';
    } else {
        json_error('PAYMENT_NOT_CONFIRMED', $result['error'] ?: '決済が確認できませんでした', 402);
    }
}

if (!$verified) {
    json_error('VERIFICATION_FAILED', '決済の確認に失敗しました', 500);
}

// 決済成功 → orders.status を pending に更新（調理開始可能）
$pdo->prepare("UPDATE orders SET status = 'pending', updated_at = NOW() WHERE id = ?")->execute([$orderId]);

// payments テーブルに記録
try {
    $paymentId = bin2hex(random_bytes(16));
    $cols = 'id, store_id, table_id, total_amount, payment_method, received_amount, change_amount, order_ids, created_at';
    $vals = '?, ?, NULL, ?, "online", ?, 0, ?, NOW()';
    $params = [
        $paymentId,
        $order['store_id'],
        (int)$order['total_amount'],
        (int)$order['total_amount'],
        json_encode([$orderId]),
    ];

    // ゲートウェイ情報カラム存在チェック
    $hasGwCols = false;
    try {
        $pdo->query('SELECT gateway_name FROM payments LIMIT 0');
        $hasGwCols = true;
    } catch (PDOException $e) {}

    if ($hasGwCols) {
        $cols .= ', gateway_name, external_payment_id, gateway_status';
        $vals .= ', ?, ?, ?';
        $params[] = $gatewayName;
        $params[] = $externalPaymentId;
        $params[] = $gatewayStatus;
    }

    $pdo->prepare('INSERT INTO payments (' . $cols . ') VALUES (' . $vals . ')')->execute($params);
} catch (PDOException $e) {
    // payments記録失敗は致命的ではない（注文ステータスは更新済み）
    error_log('[P1-12][api/customer/takeout-payment.php:142] takeout_payment_record: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

json_response([
    'order_id' => $orderId,
    'payment_confirmed' => true,
    'status' => 'pending',
]);
