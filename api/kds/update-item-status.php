<?php
/**
 * KDS品目単位ステータス更新 API
 *
 * PATCH /api/kds/update-item-status.php
 * Body: { item_id, status, store_id }
 *
 * ステータス遷移: pending → preparing → ready → served
 * 全品目が served/cancelled になると親注文も自動で served に更新する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['PATCH']);
$user = require_auth();

$data = get_json_body();
$itemId = $data['item_id'] ?? null;
$newStatus = $data['status'] ?? null;
$storeId = $data['store_id'] ?? null;

if (!$itemId || !$newStatus || !$storeId) {
    json_error('MISSING_FIELDS', 'item_id, status, store_id は必須です', 400);
}

require_store_access($storeId);

$validStatuses = ['pending', 'preparing', 'ready', 'served', 'cancelled'];
if (!in_array($newStatus, $validStatuses)) {
    json_error('INVALID_STATUS', '無効なステータスです', 400);
}

$pdo = get_db();

// 品目確認（マルチテナント境界: store_id で絞り込み）
$stmt = $pdo->prepare('SELECT id, order_id, name, status FROM order_items WHERE id = ? AND store_id = ?');
$stmt->execute([$itemId, $storeId]);
$item = $stmt->fetch();
if (!$item) json_error('ITEM_NOT_FOUND', '品目が見つかりません', 404);

// ステータス更新 + タイムスタンプ
$sql = 'UPDATE order_items SET status = ?, updated_at = NOW()';
$params = [$newStatus];

$timestampMap = [
    'preparing' => 'prepared_at',
    'ready'     => 'ready_at',
    'served'    => 'served_at',
];
if (isset($timestampMap[$newStatus])) {
    $sql .= ', ' . $timestampMap[$newStatus] . ' = NOW()';
}

$sql .= ' WHERE id = ? AND store_id = ?';
$params[] = $itemId;
$params[] = $storeId;

$pdo->prepare($sql)->execute($params);

// 監査ログ
write_audit_log($pdo, $user, $storeId, 'item_status', 'order_item', $itemId, ['status' => $item['status']], ['status' => $newStatus], null);

// O-5: ready になったらハンディに通知（call_alerts に product_ready レコード作成）
if ($newStatus === 'ready') {
    try {
        $tableStmt = $pdo->prepare(
            'SELECT o.table_id, t.table_code, o.order_type, o.customer_name
             FROM orders o
             LEFT JOIN tables t ON t.id = o.table_id AND t.store_id = o.store_id
             WHERE o.id = ? AND o.store_id = ?'
        );
        $tableStmt->execute([$item['order_id'], $storeId]);
        $tableInfo = $tableStmt->fetch();
        if ($tableInfo) {
            $alertTableId = $tableInfo['table_id'] ?: 'TAKEOUT';
            $alertTableCode = $tableInfo['table_code'] ?: ('テイクアウト' . ($tableInfo['customer_name'] ? ' ' . $tableInfo['customer_name'] : ''));
            $alertId = generate_uuid();
            $notifyStmt = $pdo->prepare(
                "INSERT INTO call_alerts (id, store_id, table_id, table_code, reason, type, order_item_id, item_name, status)
                 VALUES (?, ?, ?, ?, ?, 'product_ready', ?, ?, 'pending')"
            );
            $notifyStmt->execute([
                $alertId, $storeId, $alertTableId, $alertTableCode,
                ($item['name'] ?? '') . ' できました', $itemId, $item['name'] ?? ''
            ]);
        }
    } catch (PDOException $e) {
        // call_alerts に type カラムがない場合は無視（グレースフルデグラデーション）
    }
}

// 品目ステータスから親注文ステータスを連動更新
$orderId = $item['order_id'];
$stmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN status NOT IN ("cancelled") THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status = "preparing" THEN 1 ELSE 0 END) AS preparing_count,
        SUM(CASE WHEN status IN ("ready", "served") THEN 1 ELSE 0 END) AS ready_beyond,
        SUM(CASE WHEN status = "served" THEN 1 ELSE 0 END) AS served_count,
        SUM(CASE WHEN status IN ("served", "cancelled") THEN 1 ELSE 0 END) AS done
     FROM order_items WHERE order_id = ?'
);
$stmt->execute([$orderId]);
$counts = $stmt->fetch();

$orderCompleted = false;
$active = (int)$counts['active'];
$newOrderStatus = null;

if ($active > 0) {
    if ((int)$counts['served_count'] >= $active) {
        $newOrderStatus = 'served';
        $orderCompleted = true;
    } elseif ((int)$counts['ready_beyond'] >= $active) {
        $newOrderStatus = 'ready';
    } elseif ((int)$counts['preparing_count'] > 0) {
        $newOrderStatus = 'preparing';
    }
}

if ($newOrderStatus) {
    $orderUpdateSql = 'UPDATE orders SET status = ?, updated_at = NOW()';
    if ($newOrderStatus === 'served') {
        $orderUpdateSql .= ', served_at = NOW()';
    }
    $orderUpdateSql .= ' WHERE id = ? AND store_id = ?';
    $pdo->prepare($orderUpdateSql)->execute([$newOrderStatus, $orderId, $storeId]);
}

json_response([
    'item_id'         => $itemId,
    'status'          => $newStatus,
    'order_completed' => $orderCompleted,
]);
