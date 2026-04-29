<?php
/**
 * ハンディ品目調整 API
 *
 * PATCH /api/store/order-item-adjust.php
 * Body: { store_id, order_id, item_id, qty, reason? }
 *
 * 品目単位の数量変更・取消を行い、orders.items / total_amount / status を同期する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['PATCH']);
$user = require_auth();
$data = get_json_body();

$storeId = isset($data['store_id']) ? trim($data['store_id']) : '';
$orderId = isset($data['order_id']) ? trim($data['order_id']) : '';
$itemId = isset($data['item_id']) ? trim($data['item_id']) : '';
$qty = isset($data['qty']) ? (int)$data['qty'] : null;
$reason = isset($data['reason']) ? mb_substr(trim($data['reason']), 0, 200) : null;
if ($reason === '') $reason = null;

if (!$storeId || !$orderId || !$itemId || $qty === null) {
    json_error('VALIDATION', 'store_id, order_id, item_id, qty は必須です', 400);
}
if ($qty < 0 || $qty > 99) {
    json_error('VALIDATION', '数量は0〜99で指定してください', 400);
}
require_store_access($storeId);

$pdo = get_db();

try {
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'SELECT id, status, total_amount
           FROM orders
          WHERE id = ? AND store_id = ?
          FOR UPDATE'
    );
    $orderStmt->execute([$orderId, $storeId]);
    $order = $orderStmt->fetch();
    if (!$order) {
        $pdo->rollBack();
        json_error('ORDER_NOT_FOUND', '注文が見つかりません', 404);
    }
    if (in_array($order['status'], ['paid', 'cancelled'], true)) {
        $pdo->rollBack();
        json_error('ORDER_CLOSED', '会計済み・キャンセル済みの注文は変更できません', 409);
    }

    $itemStmt = $pdo->prepare(
        'SELECT id, order_id, name, price, qty, status
           FROM order_items
          WHERE id = ? AND order_id = ? AND store_id = ?
          FOR UPDATE'
    );
    $itemStmt->execute([$itemId, $orderId, $storeId]);
    $item = $itemStmt->fetch();
    if (!$item) {
        $pdo->rollBack();
        json_error('ITEM_NOT_FOUND', '品目が見つかりません', 404);
    }
    if ($item['status'] === 'cancelled') {
        $pdo->rollBack();
        json_error('ITEM_CANCELLED', '取消済みの品目は変更できません', 409);
    }
    if ($qty === 0 && $item['status'] !== 'pending' && !$reason) {
        $pdo->rollBack();
        json_error('REASON_REQUIRED', 'KDS送信後の取消には理由が必要です', 400);
    }

    if ($qty === 0) {
        $pdo->prepare(
            'UPDATE order_items
                SET status = "cancelled", updated_at = NOW()
              WHERE id = ? AND order_id = ? AND store_id = ?'
        )->execute([$itemId, $orderId, $storeId]);
    } else {
        $pdo->prepare(
            'UPDATE order_items
                SET qty = ?, updated_at = NOW()
              WHERE id = ? AND order_id = ? AND store_id = ?'
        )->execute([$qty, $itemId, $orderId, $storeId]);
    }

    $summary = rebuild_order_from_items($pdo, $storeId, $orderId);

    write_audit_log($pdo, $user, $storeId, $qty === 0 ? 'order_item_cancel' : 'order_item_qty', 'order_item', $itemId, [
        'qty' => (int)$item['qty'],
        'status' => $item['status'],
    ], [
        'qty' => $qty,
        'status' => $qty === 0 ? 'cancelled' : $item['status'],
        'order_status' => $summary['status'],
        'total_amount' => $summary['total_amount'],
    ], $reason);

    $pdo->commit();
    json_response([
        'order_id' => $orderId,
        'item_id' => $itemId,
        'qty' => $qty,
        'order_status' => $summary['status'],
        'total_amount' => $summary['total_amount'],
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[api/store/order-item-adjust.php] failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('ADJUST_FAILED', '品目の変更に失敗しました', 500);
}

function rebuild_order_from_items(PDO $pdo, string $storeId, string $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, menu_item_id, name, price, qty, options, allergen_selections, status
           FROM order_items
          WHERE order_id = ? AND store_id = ?
          ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$orderId, $storeId]);
    $rows = $stmt->fetchAll();

    $items = [];
    $totalAmount = 0;
    $activeCount = 0;
    $servedCount = 0;
    $readyBeyondCount = 0;
    $preparingCount = 0;

    for ($i = 0; $i < count($rows); $i++) {
        $row = $rows[$i];
        $status = $row['status'] ?? 'pending';
        if ($status === 'cancelled') {
            continue;
        }
        $activeCount++;
        if ($status === 'served') $servedCount++;
        if ($status === 'ready' || $status === 'served') $readyBeyondCount++;
        if ($status === 'preparing') $preparingCount++;

        $qty = (int)$row['qty'];
        $price = (int)$row['price'];
        $totalAmount += $price * $qty;
        $entry = [
            'id' => $row['menu_item_id'],
            'name' => $row['name'],
            'price' => $price,
            'qty' => $qty,
        ];
        if (!empty($row['options'])) {
            $decodedOptions = json_decode($row['options'], true);
            if (is_array($decodedOptions)) $entry['options'] = $decodedOptions;
        }
        if (!empty($row['allergen_selections'])) {
            $decodedAllergens = json_decode($row['allergen_selections'], true);
            if (is_array($decodedAllergens)) $entry['allergen_selections'] = $decodedAllergens;
        }
        $items[] = $entry;
    }

    if ($activeCount === 0) {
        $newStatus = 'cancelled';
    } elseif ($servedCount >= $activeCount) {
        $newStatus = 'served';
    } elseif ($readyBeyondCount >= $activeCount) {
        $newStatus = 'ready';
    } elseif ($preparingCount > 0) {
        $newStatus = 'preparing';
    } else {
        $newStatus = 'pending';
    }

    $sql = 'UPDATE orders SET items = ?, total_amount = ?, status = ?, updated_at = NOW()';
    $params = [json_encode($items, JSON_UNESCAPED_UNICODE), $totalAmount, $newStatus];
    if ($newStatus === 'served') {
        $sql .= ', served_at = COALESCE(served_at, NOW())';
    } else {
        $sql .= ', served_at = NULL';
    }
    $sql .= ' WHERE id = ? AND store_id = ?';
    $params[] = $orderId;
    $params[] = $storeId;
    $pdo->prepare($sql)->execute($params);

    return ['status' => $newStatus, 'total_amount' => $totalAmount];
}
