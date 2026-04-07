<?php
/**
 * 売上レポート API
 *
 * GET /api/store/sales-report.php?store_id=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
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

$range = get_business_day_range($pdo, $storeId, $from, $to);

// 基本集計
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS order_count,
            COALESCE(SUM(total_amount), 0) AS total_revenue,
            COALESCE(AVG(total_amount), 0) AS avg_order_value
     FROM orders
     WHERE store_id = ? AND status = ? AND created_at >= ? AND created_at < ?'
);
$stmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
$summary = $stmt->fetch();

// 全注文取得（提供時間分析用）
$stmt = $pdo->prepare(
    'SELECT o.total_amount, o.items, o.created_at, o.prepared_at, o.ready_at, o.served_at, o.paid_at,
            HOUR(o.created_at) AS hour, o.table_id, t.table_code
     FROM orders o
     LEFT JOIN tables t ON t.id = o.table_id
     WHERE o.store_id = ? AND o.status = ? AND o.created_at >= ? AND o.created_at < ?
     ORDER BY o.created_at'
);
$stmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
$orders = $stmt->fetchAll();

// 品目集計
$itemRanking = [];
foreach ($orders as $o) {
    $items = json_decode($o['items'], true) ?: [];
    foreach ($items as $item) {
        $name = $item['name'] ?? '不明';
        if (!isset($itemRanking[$name])) {
            $itemRanking[$name] = ['name' => $name, 'qty' => 0, 'revenue' => 0];
        }
        $itemRanking[$name]['qty'] += (int)($item['qty'] ?? 1);
        $itemRanking[$name]['revenue'] += (int)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
    }
}
usort($itemRanking, function ($a, $b) { return $b['revenue'] - $a['revenue']; });

// 時間帯別
$hourly = [];
foreach ($orders as $o) {
    $h = (int)$o['hour'];
    if (!isset($hourly[$h])) $hourly[$h] = ['hour' => $h, 'count' => 0, 'revenue' => 0];
    $hourly[$h]['count']++;
    $hourly[$h]['revenue'] += (int)$o['total_amount'];
}
ksort($hourly);

// テーブル別
$tableSales = [];
foreach ($orders as $o) {
    $tc = $o['table_code'] ?? '?';
    if (!isset($tableSales[$tc])) $tableSales[$tc] = ['tableCode' => $tc, 'count' => 0, 'revenue' => 0];
    $tableSales[$tc]['count']++;
    $tableSales[$tc]['revenue'] += (int)$o['total_amount'];
}

// 提供時間分析
$serviceTimes = [];
foreach ($orders as $o) {
    if ($o['served_at'] && $o['created_at']) {
        $sec = strtotime($o['served_at']) - strtotime($o['created_at']);
        if ($sec > 0) $serviceTimes[] = $sec;
    } elseif ($o['ready_at'] && $o['created_at']) {
        $sec = strtotime($o['ready_at']) - strtotime($o['created_at']);
        if ($sec > 0) $serviceTimes[] = $sec;
    }
}

$serviceTimeAnalysis = null;
if (!empty($serviceTimes)) {
    sort($serviceTimes);
    $serviceTimeAnalysis = [
        'avg'     => (int)(array_sum($serviceTimes) / count($serviceTimes)),
        'median'  => $serviceTimes[(int)(count($serviceTimes) / 2)],
        'min'     => $serviceTimes[0],
        'max'     => end($serviceTimes),
        'count'   => count($serviceTimes),
    ];
}

json_response([
    'summary' => [
        'orderCount'    => (int)$summary['order_count'],
        'totalRevenue'  => (int)$summary['total_revenue'],
        'avgOrderValue' => (int)$summary['avg_order_value'],
    ],
    'itemRanking'         => array_slice(array_values($itemRanking), 0, 20),
    'hourly'              => array_values($hourly),
    'tableSales'          => array_values($tableSales),
    'serviceTimeAnalysis' => $serviceTimeAnalysis,
    'period'              => ['from' => $from, 'to' => $to],
]);
