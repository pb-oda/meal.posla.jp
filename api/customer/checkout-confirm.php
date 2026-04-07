<?php
/**
 * L-8: セルフレジ — 決済確認 + 注文完了処理
 *
 * POST /api/customer/checkout-confirm.php
 * body: { store_id, table_id, session_token, stripe_session_id }
 *
 * Stripe Checkout 完了後にフロントから呼ばれる。
 * 決済を検証し、注文を paid にし、セッションを閉じる。
 *
 * 認証不要（session_token + stripe_session_id で検証）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/payment-gateway.php';

require_method(['POST']);

$data = get_json_body();
$storeId        = $data['store_id'] ?? null;
$tableId        = $data['table_id'] ?? null;
$sessionToken   = $data['session_token'] ?? null;
$stripeSessionId = $data['stripe_session_id'] ?? null;

if (!$storeId || !$tableId || !$sessionToken || !$stripeSessionId) {
    json_error('MISSING_FIELDS', 'store_id, table_id, session_token, stripe_session_id は必須です', 400);
}

$pdo = get_db();

// ── session_token 検証 ──
$stmt = $pdo->prepare(
    'SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ? AND is_active = 1'
);
$stmt->execute([$tableId, $storeId]);
$tableRow = $stmt->fetch();

if (!$tableRow || $tableRow['session_token'] !== $sessionToken) {
    json_error('INVALID_SESSION', 'セッションが無効です', 403);
}
if ($tableRow['session_token_expires_at'] && strtotime($tableRow['session_token_expires_at']) < time()) {
    json_error('INVALID_SESSION', 'セッションの有効期限が切れています', 403);
}

// ── テナント情報取得 ──
$stmt = $pdo->prepare('SELECT tenant_id FROM stores WHERE id = ?');
$stmt->execute([$storeId]);
$storeInfo = $stmt->fetch();
if (!$storeInfo) {
    json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
}
$tenantId = $storeInfo['tenant_id'];

// ── Stripe 決済検証 ──
$verified = false;
$gatewayName = null;
$externalPaymentId = null;
$gatewayStatus = null;

// 1. Stripe Connect を確認
try {
    require_once __DIR__ . '/../lib/stripe-connect.php';
    $connectInfo = get_tenant_connect_info($pdo, $tenantId);
    if ($connectInfo && (int)$connectInfo['connect_onboarding_complete'] === 1
        && !empty($connectInfo['stripe_connect_account_id'])) {
        $platformConfig = get_platform_stripe_config($pdo);
        if (!empty($platformConfig['secret_key'])) {
            $result = verify_stripe_checkout($platformConfig['secret_key'], $stripeSessionId);
            if ($result['success']) {
                $pStatus = $result['payment_status'] ?? '';
                if ($pStatus === 'paid') {
                    $verified = true;
                    $gatewayName = 'stripe_connect';
                    $externalPaymentId = $result['payment_intent_id'] ?: $stripeSessionId;
                    $gatewayStatus = 'succeeded';
                } else {
                    json_error('PAYMENT_NOT_CONFIRMED', '決済が完了していません (status: ' . $pStatus . ')', 402);
                }
            }
        }
    }
} catch (Exception $e) {
    // stripe-connect.php 未存在時はフォールスルー
}

// 2. Connect で検証できなければ直接 Stripe
if (!$verified) {
    $stmt = $pdo->prepare('SELECT stripe_secret_key FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $tenantRow = $stmt->fetch();
    $stripeKey = $tenantRow ? ($tenantRow['stripe_secret_key'] ?? null) : null;

    if (!$stripeKey) {
        json_error('PAYMENT_GATEWAY_ERROR', 'Stripe設定が見つかりません', 502);
    }

    $result = verify_stripe_checkout($stripeKey, $stripeSessionId);
    if (!$result['success']) {
        json_error('PAYMENT_GATEWAY_ERROR', $result['error'] ?: '決済検証に失敗しました', 502);
    }

    $pStatus = $result['payment_status'] ?? '';
    if ($pStatus !== 'paid') {
        json_error('PAYMENT_NOT_CONFIRMED', '決済が完了していません (status: ' . $pStatus . ')', 402);
    }

    $verified = true;
    $gatewayName = 'stripe';
    $externalPaymentId = $result['payment_intent_id'] ?: $stripeSessionId;
    $gatewayStatus = 'succeeded';
}

// ── 二重処理防止（冪等性） ──
$hasGatewayCols = false;
try {
    $pdo->query('SELECT gateway_name FROM payments LIMIT 0');
    $hasGatewayCols = true;
} catch (PDOException $e) {}

if ($hasGatewayCols && $externalPaymentId) {
    $stmt = $pdo->prepare('SELECT id FROM payments WHERE external_payment_id = ?');
    $stmt->execute([$externalPaymentId]);
    $existingPayment = $stmt->fetch();
    if ($existingPayment) {
        // 既に処理済み → 既存の payment_id を返す
        json_response([
            'payment_id'     => $existingPayment['id'],
            'already_processed' => true,
        ]);
    }
}

// ── 未払い注文を取得 ──
$stmt = $pdo->prepare(
    'SELECT id, items, total_amount
     FROM orders
     WHERE table_id = ? AND store_id = ? AND session_token = ?
       AND status NOT IN (\'paid\', \'cancelled\')
     ORDER BY created_at ASC'
);
$stmt->execute([$tableId, $storeId, $sessionToken]);
$orders = $stmt->fetchAll();

if (empty($orders)) {
    json_error('NO_UNPAID_ORDERS', '未払いの注文がありません', 404);
}

// ── 税込合計算出 ──
$subtotal10 = 0;
$tax10 = 0;
$subtotal8 = 0;
$tax8 = 0;
$totalAmount = 0;
$orderIdList = [];

foreach ($orders as $order) {
    $totalAmount += (int)$order['total_amount'];
    $orderIdList[] = $order['id'];

    $items = json_decode($order['items'] ?? '[]', true);
    if (!is_array($items)) $items = [];

    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? 1);
        $price = (int)($item['price'] ?? 0);
        $lineTotal = $price * $qty;
        $taxRate = isset($item['taxRate']) ? (int)$item['taxRate'] : 10;

        if ($taxRate === 8) {
            $sub = (int)floor($lineTotal / 1.08);
            $subtotal8 += $sub;
            $tax8 += $lineTotal - $sub;
        } else {
            $sub = (int)floor($lineTotal / 1.10);
            $subtotal10 += $sub;
            $tax10 += $lineTotal - $sub;
        }
    }
}

// ── session_id を取得 ──
$sessionId = null;
try {
    $stmt = $pdo->prepare(
        'SELECT id FROM table_sessions WHERE store_id = ? AND table_id = ? AND status NOT IN (\'paid\', \'closed\') ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$storeId, $tableId]);
    $sessRow = $stmt->fetch();
    $sessionId = $sessRow ? $sessRow['id'] : null;
} catch (PDOException $e) {
    // table_sessions 未存在時はスキップ
}

// ── カラム存在チェック ──
$hasMergedCols = false;
try {
    $pdo->query('SELECT merged_table_ids FROM payments LIMIT 0');
    $hasMergedCols = true;
} catch (PDOException $e) {}

// ── トランザクション ──
$paymentId = generate_uuid();
$paymentMethod = 'card';
$receivedAmount = $totalAmount;
$changeAmount = 0;

$pdo->beginTransaction();
try {
    // payments レコード作成
    if ($hasMergedCols) {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, merged_table_ids, merged_session_ids, session_id, order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8, total_amount, payment_method, received_amount, change_amount, is_partial, user_id, paid_at, created_at)
             VALUES (?, ?, ?, NULL, NULL, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NOW(), NOW())'
        )->execute([
            $paymentId, $storeId, $tableId, $sessionId,
            json_encode($orderIdList),
            $subtotal10, $tax10, $subtotal8, $tax8,
            $totalAmount, $paymentMethod, $receivedAmount, $changeAmount,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, session_id, order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8, total_amount, payment_method, received_amount, change_amount, is_partial, user_id, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NOW(), NOW())'
        )->execute([
            $paymentId, $storeId, $tableId, $sessionId,
            json_encode($orderIdList),
            $subtotal10, $tax10, $subtotal8, $tax8,
            $totalAmount, $paymentMethod, $receivedAmount, $changeAmount,
        ]);
    }

    // gateway 情報を UPDATE
    if ($hasGatewayCols && $gatewayName) {
        $pdo->prepare(
            'UPDATE payments SET gateway_name = ?, external_payment_id = ?, gateway_status = ? WHERE id = ?'
        )->execute([$gatewayName, $externalPaymentId, $gatewayStatus, $paymentId]);
    }

    // 注文ステータスを paid に更新
    if (!empty($orderIdList)) {
        $placeholders = implode(',', array_fill(0, count($orderIdList), '?'));
        $pdo->prepare(
            'UPDATE orders SET status = ?, payment_method = ?, received_amount = ?, change_amount = ?,
                    paid_at = NOW(), updated_at = NOW()
             WHERE id IN (' . $placeholders . ')'
        )->execute(array_merge(['paid', $paymentMethod, $receivedAmount, $changeAmount], $orderIdList));
    }

    // テーブルセッションを閉じる + トークン再生成
    $newToken = bin2hex(random_bytes(16));
    $pdo->prepare(
        'UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?'
    )->execute([$newToken, $tableId]);

    try {
        $pdo->prepare(
            'UPDATE table_sessions SET status = \'paid\', closed_at = NOW()
             WHERE store_id = ? AND table_id = ? AND status NOT IN (\'paid\', \'closed\')'
        )->execute([$storeId, $tableId]);
    } catch (PDOException $e) {
        // table_sessions 未存在時はスキップ
    }

    // ── レシピ連動在庫引き落とし ──
    try {
        $pdo->query('SELECT 1 FROM recipes LIMIT 0');
        $pdo->query('SELECT 1 FROM ingredients LIMIT 0');

        $itemQtyMap = [];
        foreach ($orders as $order) {
            $oItems = json_decode($order['items'] ?? '[]', true);
            if (!is_array($oItems)) continue;
            foreach ($oItems as $oi) {
                $itemId = $oi['id'] ?? null;
                if (!$itemId) continue;
                $itemQtyMap[$itemId] = ($itemQtyMap[$itemId] ?? 0) + (int)($oi['qty'] ?? 1);
            }
        }

        if (!empty($itemQtyMap)) {
            $menuIds = array_keys($itemQtyMap);
            $ph = implode(',', array_fill(0, count($menuIds), '?'));
            $recipeStmt = $pdo->prepare(
                "SELECT r.menu_template_id, r.ingredient_id, r.quantity
                 FROM recipes r WHERE r.menu_template_id IN ($ph)"
            );
            $recipeStmt->execute($menuIds);
            $recipeRows = $recipeStmt->fetchAll();

            $deductions = [];
            foreach ($recipeRows as $rr) {
                $menuId = $rr['menu_template_id'];
                $ingId = $rr['ingredient_id'];
                $recipeQty = (float)$rr['quantity'];
                $orderQty = $itemQtyMap[$menuId] ?? 0;
                $deductions[$ingId] = ($deductions[$ingId] ?? 0) + ($recipeQty * $orderQty);
            }

            $updateStmt = $pdo->prepare(
                'UPDATE ingredients SET stock_quantity = stock_quantity - ? WHERE id = ?'
            );
            foreach ($deductions as $ingId => $amount) {
                $updateStmt->execute([$amount, $ingId]);
            }
        }
    } catch (PDOException $e) {
        // recipes/ingredients テーブル未作成時はスキップ
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[L-8 checkout-confirm] ' . $e->getMessage());
    json_error('PAYMENT_GATEWAY_ERROR', '決済処理に失敗しました: ' . $e->getMessage(), 502);
}

json_response([
    'payment_id'     => $paymentId,
    'total'          => $totalAmount,
    'subtotal_10'    => $subtotal10,
    'tax_10'         => $tax10,
    'subtotal_8'     => $subtotal8,
    'tax_8'          => $tax8,
    'payment_method' => $paymentMethod,
    'gateway_name'   => $gatewayName,
    'paid_at'        => date('Y-m-d H:i:s'),
]);
