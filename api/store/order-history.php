<?php
/**
 * 注文履歴 API（ページネーション付き）
 *
 * GET /api/store/order-history.php?store_id=xxx&from=&to=&status=&table_id=&q=&page=&limit=
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';

require_method(['GET']);
$user = require_role('manager');
$pdo = get_db();

$storeId = require_store_param();
require_store_access($storeId);

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? null;
$tableId = $_GET['table_id'] ?? null;
$search = $_GET['q'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$range = get_business_day_range($pdo, $storeId, $from, $to);

$where = 'o.store_id = ? AND o.created_at >= ? AND o.created_at < ?';
$params = [$storeId, $range['start'], $range['end']];

if ($status) {
    $where .= ' AND o.status = ?';
    $params[] = $status;
}
if ($tableId) {
    $where .= ' AND o.table_id = ?';
    $params[] = $tableId;
}
if ($search) {
    $where .= ' AND o.items LIKE ?';
    $params[] = '%' . $search . '%';
}

// 総数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// データ
$stmt = $pdo->prepare(
    "SELECT o.*, t.table_code
     FROM orders o
     LEFT JOIN tables t ON t.id = o.table_id
     WHERE $where
     ORDER BY o.created_at DESC
     LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

foreach ($orders as &$o) {
    $o['items'] = json_decode($o['items'], true) ?: [];
    $o['removed_items'] = json_decode($o['removed_items'] ?? 'null', true) ?: [];
    // 提供時間計算
    $o['service_time'] = null;
    if ($o['served_at'] && $o['created_at']) {
        $o['service_time'] = strtotime($o['served_at']) - strtotime($o['created_at']);
    } elseif ($o['ready_at'] && $o['created_at']) {
        $o['service_time'] = strtotime($o['ready_at']) - strtotime($o['created_at']);
    }
}
unset($o);

json_response([
    'orders'     => $orders,
    'pagination' => [
        'page'      => $page,
        'limit'     => $limit,
        'total'     => $total,
        'totalPages' => (int)ceil($total / $limit),
    ],
    'period' => ['from' => $from, 'to' => $to],
]);
