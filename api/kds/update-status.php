<?php
/**
 * KDS注文ステータス更新 API
 *
 * PATCH /api/kds/update-status.php
 * Body: { order_id, status, store_id }
 *
 * ステータス遷移: pending → preparing → ready → served → paid
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['PATCH']);
$user = require_auth();

$data = get_json_body();
$orderId = $data['order_id'] ?? null;
$newStatus = $data['status'] ?? null;
$storeId = $data['store_id'] ?? null;

if (!$orderId || !$newStatus || !$storeId) {
    json_error('MISSING_FIELDS', 'order_id, status, store_id は必須です', 400);
}

require_store_access($storeId);

$validStatuses = ['pending', 'preparing', 'ready', 'served', 'paid', 'cancelled'];
if (!in_array($newStatus, $validStatuses)) {
    json_error('INVALID_STATUS', '無効なステータスです', 400);
}

$pdo = get_db();

// 現在の注文確認
$stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ? AND store_id = ?');
$stmt->execute([$orderId, $storeId]);
$order = $stmt->fetch();
if (!$order) json_error('ORDER_NOT_FOUND', '注文が見つかりません', 404);

// タイムスタンプマッピング
$timestampCol = [
    'preparing' => 'prepared_at',
    'ready'     => 'ready_at',
    'served'    => 'served_at',
    'paid'      => 'paid_at',
];

$sql = 'UPDATE orders SET status = ?, updated_at = NOW()';
$params = [$newStatus];

if (isset($timestampCol[$newStatus])) {
    $sql .= ', ' . $timestampCol[$newStatus] . ' = NOW()';
}

$sql .= ' WHERE id = ?';
$params[] = $orderId;

$pdo->prepare($sql)->execute($params);

// 監査ログ
$auditAction = ($newStatus === 'cancelled') ? 'order_cancel' : 'order_status';
write_audit_log($pdo, $user, $storeId, $auditAction, 'order', $orderId, ['status' => $order['status']], ['status' => $newStatus], null);

// order_items も連動更新（存在する場合。paid/cancelled は order_items 側では served 扱いしない）
$oiTimestampCol = [
    'preparing' => 'prepared_at',
    'ready'     => 'ready_at',
    'served'    => 'served_at',
];
// order_items の status は pending/preparing/ready/served/cancelled のみ
$oiStatus = $newStatus;
if ($oiStatus === 'paid') $oiStatus = 'served';
if (in_array($oiStatus, ['pending', 'preparing', 'ready', 'served', 'cancelled'])) {
    try {
        $oiSql = 'UPDATE order_items SET status = ?, updated_at = NOW()';
        $oiParams = [$oiStatus];
        if (isset($oiTimestampCol[$oiStatus])) {
            $oiSql .= ', ' . $oiTimestampCol[$oiStatus] . ' = NOW()';
        }
        $oiSql .= ' WHERE order_id = ? AND store_id = ? AND status != "cancelled"';
        $oiParams[] = $orderId;
        $oiParams[] = $storeId;
        $pdo->prepare($oiSql)->execute($oiParams);

        // O-5: ready一括更新時 → 品目ごとにハンディ通知を作成
        if ($oiStatus === 'ready') {
            try {
                // テーブル情報取得
                $tblStmt = $pdo->prepare(
                    'SELECT o.table_id, t.table_code, o.order_type, o.customer_name
                     FROM orders o
                     LEFT JOIN tables t ON t.id = o.table_id AND t.store_id = o.store_id
                     WHERE o.id = ? AND o.store_id = ?'
                );
                $tblStmt->execute([$orderId, $storeId]);
                $tblInfo = $tblStmt->fetch();

                if ($tblInfo) {
                    $alertTableId = $tblInfo['table_id'] ?: 'TAKEOUT';
                    $alertTableCode = $tblInfo['table_code'] ?: ('テイクアウト' . ($tblInfo['customer_name'] ? ' ' . $tblInfo['customer_name'] : ''));

                    // 対象品目を取得
                    $itemsStmt = $pdo->prepare(
                        'SELECT id, name FROM order_items WHERE order_id = ? AND store_id = ? AND status = "ready"'
                    );
                    $itemsStmt->execute([$orderId, $storeId]);
                    $readyItems = $itemsStmt->fetchAll();

                    foreach ($readyItems as $ri) {
                        $alertId = generate_uuid();
                        $pdo->prepare(
                            "INSERT INTO call_alerts (id, store_id, table_id, table_code, reason, type, order_item_id, item_name, status)
                             VALUES (?, ?, ?, ?, ?, 'product_ready', ?, ?, 'pending')"
                        )->execute([
                            $alertId, $storeId, $alertTableId, $alertTableCode,
                            ($ri['name'] ?? '') . ' できました', $ri['id'], $ri['name'] ?? ''
                        ]);
                    }
                }
            } catch (PDOException $e) {
                // call_alerts に type カラムがない場合は無視
                error_log('[P1-12][api/kds/update-status.php:123] kds_product_ready_notify: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
            }
        }
    } catch (PDOException $e) {
        // order_items テーブル未作成時はスキップ
        if (strpos($e->getMessage(), 'order_items') === false) {
            throw $e;
        }
    }
}

json_response(['ok' => true]);
