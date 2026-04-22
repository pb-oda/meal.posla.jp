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

// Phase 4d-5c-a (2026-04-21): 注文に紐づく全 payments が voided な orders を売上集計から除外する。
//   payments に「有効 (active)」が 1 件もなければ集計から外す。
//   有効判定: void_status IS NULL OR void_status <> 'voided' (migration 未適用環境を含む)。
//   payments を一切持たない orders は除外しない (緊急会計外の通常運用を壊さないため)。
//   分割会計の「一部 voided」は active が 1 件残れば集計に残る (会計上は全額計上を維持)。
//
// migration-pwa4d5a 未適用環境では payments.void_status カラムが無いので動的にフォールバックする。
$hasPaymentVoidColForReport = false;
try { $pdo->query('SELECT void_status FROM payments LIMIT 0'); $hasPaymentVoidColForReport = true; }
catch (PDOException $e) {}

// EXISTS payments(有効) が 1 件もなく、かつ payments(全件) は存在する → 全 voided として除外
//   (= "EXISTS any payments AND NOT EXISTS active payments" を AND で否定)
//   migration 未適用時は active 判定が効かないので除外句を入れない
$voidExclude = $hasPaymentVoidColForReport
  ? ' AND NOT (EXISTS (SELECT 1 FROM payments p_any
                         WHERE p_any.store_id = o.store_id
                           AND JSON_CONTAINS(p_any.order_ids, JSON_QUOTE(o.id)))
              AND NOT EXISTS (SELECT 1 FROM payments p_act
                               WHERE p_act.store_id = o.store_id
                                 AND JSON_CONTAINS(p_act.order_ids, JSON_QUOTE(o.id))
                                 AND (p_act.void_status IS NULL OR p_act.void_status <> \'voided\')))'
  : '';

// 基本集計 (orders に o エイリアス追加 + voided 除外条件)
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS order_count,
            COALESCE(SUM(o.total_amount), 0) AS total_revenue,
            COALESCE(AVG(o.total_amount), 0) AS avg_order_value
     FROM orders o
     WHERE o.store_id = ? AND o.status = ? AND o.created_at >= ? AND o.created_at < ?' . $voidExclude
);
$stmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
$summary = $stmt->fetch();

// 全注文取得（提供時間分析用、ただし voided 注文は itemRanking / hourly / tableSales / serviceTime からも外す）
$stmt = $pdo->prepare(
    'SELECT o.total_amount, o.items, o.created_at, o.prepared_at, o.ready_at, o.served_at, o.paid_at,
            HOUR(o.created_at) AS hour, o.table_id, t.table_code
     FROM orders o
     LEFT JOIN tables t ON t.id = o.table_id
     WHERE o.store_id = ? AND o.status = ? AND o.created_at >= ? AND o.created_at < ?' . $voidExclude . '
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
