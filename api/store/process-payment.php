<?php
/**
 * POSレジ会計処理 API
 *
 * POST /api/store/process-payment.php
 * Body: {
 *   store_id, table_id?, order_ids?,
 *   payment_method (cash|card|qr),
 *   received_amount? (現金時の預かり金),
 *   selected_items? [{name, qty, price, taxRate?}] (個別会計),
 *   total_override? (個別会計時の合計)
 * }
 *
 * 全額会計: 注文を paid に更新、セッション終了
 * 個別会計: payments に記録のみ（注文は open のまま）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/inventory-sync.php';
require_once __DIR__ . '/../lib/payment-gateway.php';

require_method(['POST']);
$user = require_auth();

$data = get_json_body();
$storeId        = $data['store_id'] ?? null;
$tableId        = $data['table_id'] ?? null;
$orderIds       = $data['order_ids'] ?? [];
$paymentMethod  = $data['payment_method'] ?? 'cash';
$receivedAmount = isset($data['received_amount']) ? (int)$data['received_amount'] : null;
$selectedItems  = $data['selected_items'] ?? null;   // 個別会計
$totalOverride  = isset($data['total_override']) ? (int)$data['total_override'] : null;
$mergedTableIds  = $data['merged_table_ids'] ?? null;   // 合流テーブルID配列
$selectedItemIds = $data['selected_item_ids'] ?? null;   // 分割: order_item.id 配列

if (!$storeId) json_error('VALIDATION', 'store_id は必須です', 400);
if (!$tableId && empty($orderIds)) json_error('VALIDATION', 'table_id または order_ids が必要です', 400);
if (!in_array($paymentMethod, ['cash', 'card', 'qr', 'terminal'], true)) {
    json_error('VALIDATION', '不正な payment_method です', 400);
}
require_store_access($storeId);

$pdo = get_db();

// payments テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM payments LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'payments テーブルが未作成です。migration-c4-payments.sql を実行してください。', 500);
}

// ── 注文取得 ──
$orders = [];
if (is_array($mergedTableIds) && count($mergedTableIds) > 0) {
    // 合流会計: 複数テーブルの注文をまとめて取得
    $allTableIds = $mergedTableIds;
    $ph = implode(',', array_fill(0, count($allTableIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, total_amount, table_id, items FROM orders
         WHERE store_id = ? AND table_id IN (' . $ph . ') AND status NOT IN ("paid", "cancelled")'
    );
    $stmt->execute(array_merge([$storeId], $allTableIds));
    $orders = $stmt->fetchAll();
    if (!$tableId) $tableId = $allTableIds[0];
    // order_ids を上書き（合流時はフロントから送られた order_ids を使うが、安全のため再構築）
    $orderIds = array_column($orders, 'id');
} elseif (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, total_amount, table_id, items FROM orders
         WHERE store_id = ? AND id IN (' . $placeholders . ') AND status NOT IN ("paid", "cancelled")'
    );
    $stmt->execute(array_merge([$storeId], $orderIds));
    $orders = $stmt->fetchAll();
    if (!$tableId && !empty($orders) && $orders[0]['table_id']) {
        $tableId = $orders[0]['table_id'];
    }
} elseif ($tableId) {
    $stmt = $pdo->prepare(
        'SELECT id, total_amount, items FROM orders
         WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "cancelled")'
    );
    $stmt->execute([$storeId, $tableId]);
    $orders = $stmt->fetchAll();
}

// ── セッション確認 ──
$sessionInfo = null;
$planInfo = null;
$mergedSessionIds = [];
if ($tableId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT ts.id AS session_id, ts.guest_count, ts.plan_id,
                    tlp.price AS plan_price, tlp.name AS plan_name
             FROM table_sessions ts
             LEFT JOIN time_limit_plans tlp ON tlp.id = ts.plan_id
             WHERE ts.store_id = ? AND ts.table_id = ? AND ts.status NOT IN ("paid", "closed")'
        );
        $stmt->execute([$storeId, $tableId]);
        $sessionInfo = $stmt->fetch();
        if ($sessionInfo && $sessionInfo['plan_id']) {
            $planInfo = $sessionInfo;
        }

        // 合流会計時: 全テーブルのセッションIDを収集
        if (is_array($mergedTableIds) && count($mergedTableIds) > 0) {
            $ph = implode(',', array_fill(0, count($mergedTableIds), '?'));
            $msStmt = $pdo->prepare(
                'SELECT id FROM table_sessions
                 WHERE store_id = ? AND table_id IN (' . $ph . ') AND status NOT IN ("paid", "closed")'
            );
            $msStmt->execute(array_merge([$storeId], $mergedTableIds));
            $mergedSessionIds = array_column($msStmt->fetchAll(), 'id');
        }
    } catch (PDOException $e) {
        // table_sessions/time_limit_plans 未作成時はスキップ
    }
}

if (empty($orders) && !$sessionInfo) {
    json_error('NOT_FOUND', '未会計の注文またはアクティブなセッションがありません', 404);
}

// ── 個別会計 or 全額会計の判定 ──
$isPartial = is_array($selectedItems) && count($selectedItems) > 0;
$orderIdList = array_column($orders, 'id');

// ── 税額計算 ──
$subtotal10 = 0;
$tax10 = 0;
$subtotal8 = 0;
$tax8 = 0;
$totalAmount = 0;

if ($isPartial) {
    // 個別会計: selected_items から計算
    foreach ($selectedItems as $item) {
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
        $totalAmount += $lineTotal;
    }
    // total_override がある場合はそちらを使用
    if ($totalOverride !== null) {
        $totalAmount = $totalOverride;
    }
} else {
    // 全額会計
    $orderTotal = 0;
    foreach ($orders as $order) {
        $orderTotal += (int)$order['total_amount'];

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
    $totalAmount = $orderTotal;

    // プランがあればプラン料金×人数
    if ($planInfo && $planInfo['plan_price']) {
        $totalAmount = (int)$planInfo['plan_price'] * max(1, (int)$planInfo['guest_count']);
        $subtotal10 = (int)floor($totalAmount / 1.10);
        $tax10 = $totalAmount - $subtotal10;
        $subtotal8 = 0;
        $tax8 = 0;
    }
}

// 現金: お釣り計算
$changeAmount = null;
if ($paymentMethod === 'cash') {
    if ($receivedAmount === null) {
        json_error('VALIDATION', '現金決済では received_amount が必要です', 400);
    }
    if ($receivedAmount < $totalAmount) {
        json_error('VALIDATION', '預かり金が不足しています', 400);
    }
    $changeAmount = $receivedAmount - $totalAmount;
}

// ── 外部決済ゲートウェイ処理 ──
$gatewayName = null;
$externalPaymentId = null;
$gatewayStatus = null;

if ($paymentMethod !== 'cash') {
    // L-13: Stripe Terminal（物理カードリーダー経由: PIはクライアント側で完了済み）
    $terminalPaymentId = $data['terminal_payment_id'] ?? '';
    $terminalDone = false;
    if ($paymentMethod === 'terminal' && $terminalPaymentId) {
        $gatewayName = 'stripe_connect_terminal';
        $externalPaymentId = $terminalPaymentId;
        $gatewayStatus = 'succeeded';
        // DB記録は 'card' として統一（paymentsテーブル互換）
        $paymentMethod = 'card';
        $terminalDone = true;
    }

    // L-13: Stripe Connect 判定
    // Terminal経由（$terminalDone=true）の場合は既にJS SDKで決済完了済みなのでスキップ
    // Connect対面決済はTerminal JS SDK経由のみ。非Terminal（現金/通常カード）はC-3にフォールスルー
    $connectUsed = $terminalDone;

    // C-3 / P1-2: 従来の直接決済（Connect 未使用 + 自前 Stripe キーありの場合のみ）
    if (!$connectUsed) {
        $gwConfig = get_payment_gateway_config($pdo, $user['tenant_id']);
        if ($gwConfig && $gwConfig['gateway'] === 'stripe') {
            $gatewayName = 'stripe';
            $gwResult = execute_stripe_payment($gwConfig['token'], $totalAmount, 'jpy', generate_uuid());
            if (!$gwResult['success']) {
                json_error('GATEWAY_ERROR', '決済に失敗しました: ' . ($gwResult['error'] ?? '不明なエラー'), 502);
            }
            $externalPaymentId = $gwResult['external_id'];
            $gatewayStatus = $gwResult['status'];
        }
    }
}

// ── トランザクション ──
$paymentId = generate_uuid();
$sessionId = $sessionInfo ? $sessionInfo['session_id'] : null;

// merged カラム存在チェック
$hasMergedCols = false;
try {
    $pdo->query('SELECT merged_table_ids FROM payments LIMIT 0');
    $hasMergedCols = true;
} catch (PDOException $e) {}

// gateway カラム存在チェック
$hasGatewayCols = false;
try {
    $pdo->query('SELECT gateway_name FROM payments LIMIT 0');
    $hasGatewayCols = true;
} catch (PDOException $e) {}

// payment_id カラム存在チェック (order_items)
$hasOiPaymentId = false;
try {
    $pdo->query('SELECT payment_id FROM order_items LIMIT 0');
    $hasOiPaymentId = true;
} catch (PDOException $e) {}

$pdo->beginTransaction();
try {
    // payments レコード作成
    if ($hasMergedCols) {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, merged_table_ids, merged_session_ids, session_id, order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8, total_amount, payment_method, received_amount, change_amount, is_partial, user_id, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $paymentId, $storeId, $tableId,
            (is_array($mergedTableIds) && count($mergedTableIds) > 0) ? json_encode($mergedTableIds) : null,
            (!empty($mergedSessionIds)) ? json_encode($mergedSessionIds) : null,
            $sessionId,
            json_encode($orderIdList),
            $isPartial ? json_encode($selectedItems) : null,
            $subtotal10, $tax10, $subtotal8, $tax8,
            $totalAmount, $paymentMethod, $receivedAmount, $changeAmount,
            $isPartial ? 1 : 0,
            $user['user_id']
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, session_id, order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8, total_amount, payment_method, received_amount, change_amount, is_partial, user_id, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $paymentId, $storeId, $tableId, $sessionId,
            json_encode($orderIdList),
            $isPartial ? json_encode($selectedItems) : null,
            $subtotal10, $tax10, $subtotal8, $tax8,
            $totalAmount, $paymentMethod, $receivedAmount, $changeAmount,
            $isPartial ? 1 : 0,
            $user['user_id']
        ]);
    }

    // gateway情報をUPDATEで後付け（INSERTの分岐を増やさないため）
    if ($hasGatewayCols && $gatewayName) {
        $pdo->prepare(
            'UPDATE payments SET gateway_name = ?, external_payment_id = ?, gateway_status = ? WHERE id = ?'
        )->execute([$gatewayName, $externalPaymentId, $gatewayStatus, $paymentId]);
    }

    // 分割会計時: 対象 order_items の payment_id を記録
    if ($isPartial && $hasOiPaymentId && is_array($selectedItemIds) && count($selectedItemIds) > 0) {
        $ph = implode(',', array_fill(0, count($selectedItemIds), '?'));
        $pdo->prepare(
            'UPDATE order_items SET payment_id = ? WHERE id IN (' . $ph . ')'
        )->execute(array_merge([$paymentId], $selectedItemIds));
    }

    // 全額会計の場合のみ、注文を paid に更新 + セッション終了
    if (!$isPartial) {
        if (!empty($orderIdList)) {
            $placeholders = implode(',', array_fill(0, count($orderIdList), '?'));
            $pdo->prepare(
                'UPDATE orders SET status = ?, payment_method = ?, received_amount = ?, change_amount = ?,
                        paid_at = NOW(), updated_at = NOW()
                 WHERE id IN (' . $placeholders . ')'
            )->execute(array_merge(['paid', $paymentMethod, $receivedAmount, $changeAmount], $orderIdList));
        }

        // 合流会計: 全テーブルのセッションクローズ + トークン再生成
        if (is_array($mergedTableIds) && count($mergedTableIds) > 0) {
            foreach ($mergedTableIds as $mTableId) {
                $newToken = bin2hex(random_bytes(16));
                $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?')
                    ->execute([$newToken, $mTableId]);
                try {
                    $pdo->prepare(
                        'UPDATE table_sessions SET status = "paid", closed_at = NOW()
                         WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
                    )->execute([$storeId, $mTableId]);
                } catch (PDOException $e) {}
            }
        } elseif ($tableId) {
            $newToken = bin2hex(random_bytes(16));
            $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?')->execute([$newToken, $tableId]);

            try {
                $pdo->prepare(
                    'UPDATE table_sessions SET status = "paid", closed_at = NOW()
                     WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
                )->execute([$storeId, $tableId]);
            } catch (PDOException $e) {
                // table_sessions 未作成時はスキップ
            }
        }
    }

    // 分割会計の自動クローズ判定: 全品目に payment_id が付いたらクローズ
    if ($isPartial && $hasOiPaymentId && $tableId) {
        $unpaidStmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.table_id = ? AND o.store_id = ? AND o.status NOT IN ("paid","cancelled")
             AND oi.status != "cancelled" AND oi.payment_id IS NULL'
        );
        $unpaidStmt->execute([$tableId, $storeId]);
        $unpaid = $unpaidStmt->fetch();
        if ((int)$unpaid['cnt'] === 0) {
            // 全品支払済み → 自動クローズ
            $closeOrderStmt = $pdo->prepare(
                'UPDATE orders SET status = "paid", payment_method = ?, paid_at = NOW(), updated_at = NOW()
                 WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid","cancelled")'
            );
            $closeOrderStmt->execute([$paymentMethod, $storeId, $tableId]);

            $newToken = bin2hex(random_bytes(16));
            $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?')
                ->execute([$newToken, $tableId]);
            try {
                $pdo->prepare(
                    'UPDATE table_sessions SET status = "paid", closed_at = NOW()
                     WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
                )->execute([$storeId, $tableId]);
            } catch (PDOException $e) {}
        }
    }

    // cash_log に売上記録
    if ($paymentMethod === 'cash' && $totalAmount > 0) {
        $logId = generate_uuid();
        $note = $isPartial ? 'POSレジ個別会計' : 'POSレジ会計';
        $pdo->prepare(
            'INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$logId, $storeId, $user['user_id'], 'cash_sale', $totalAmount, $note]);
    }

    // ── レシピ連動在庫引き落とし ──
    try {
        $pdo->query('SELECT 1 FROM recipes LIMIT 0');
        $pdo->query('SELECT 1 FROM ingredients LIMIT 0');

        // 対象品目の集計: {menu_item_id => qty}
        $itemQtyMap = [];
        if ($isPartial && is_array($selectedItems)) {
            foreach ($selectedItems as $si) {
                $itemId = $si['id'] ?? null;
                if (!$itemId) continue;
                $itemQtyMap[$itemId] = ($itemQtyMap[$itemId] ?? 0) + (int)($si['qty'] ?? 1);
            }
        } else {
            foreach ($orders as $order) {
                $oItems = json_decode($order['items'] ?? '[]', true);
                if (!is_array($oItems)) continue;
                foreach ($oItems as $oi) {
                    $itemId = $oi['id'] ?? null;
                    if (!$itemId) continue;
                    $itemQtyMap[$itemId] = ($itemQtyMap[$itemId] ?? 0) + (int)($oi['qty'] ?? 1);
                }
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

            // 原材料ごとの合計消費量を計算
            $deductions = []; // ingredient_id => total_deduction
            foreach ($recipeRows as $rr) {
                $menuId = $rr['menu_template_id'];
                $ingId = $rr['ingredient_id'];
                $recipeQty = (float)$rr['quantity'];
                $orderQty = $itemQtyMap[$menuId] ?? 0;
                $deductions[$ingId] = ($deductions[$ingId] ?? 0) + ($recipeQty * $orderQty);
            }

            // 一括在庫引き落とし（マイナス在庫許容）
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

    // ── 在庫→品切れ自動同期（S-5） ──
    try {
        sync_sold_out_from_inventory($pdo, $user['tenant_id'], $storeId, false);
    } catch (Exception $e) {
        // 品切れ同期失敗は会計を阻害しない
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    json_error('DB_ERROR', '会計処理に失敗しました: ' . $e->getMessage(), 500);
}

json_response([
    'payment_id'        => $paymentId,
    'totalAmount'       => $totalAmount,
    'subtotal10'        => $subtotal10,
    'tax10'             => $tax10,
    'subtotal8'         => $subtotal8,
    'tax8'              => $tax8,
    'changeAmount'      => $changeAmount,
    'orderCount'        => count($orders),
    'paymentMethod'     => $paymentMethod,
    'isPartial'         => $isPartial,
    'gatewayName'       => $gatewayName,
    'externalPaymentId' => $externalPaymentId,
]);
