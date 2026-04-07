<?php
/**
 * 全店舗横断レポート API（オーナー専用）
 *
 * GET /api/owner/cross-store-report.php?from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';

require_method(['GET']);
$user = require_role('owner');
$pdo = get_db();
$tenantId = $user['tenant_id'];

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$filterStoreId = $_GET['store_id'] ?? '';

// テナントの店舗（store_id指定時は1店舗のみ）
if ($filterStoreId) {
    $stmt = $pdo->prepare('SELECT id, name, slug FROM stores WHERE tenant_id = ? AND id = ? AND is_active = 1');
    $stmt->execute([$tenantId, $filterStoreId]);
} else {
    $stmt = $pdo->prepare('SELECT id, name, slug FROM stores WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
    $stmt->execute([$tenantId]);
}
$stores = $stmt->fetchAll();

$storeReports = [];
$totalRevenue = 0;
$totalOrders = 0;

foreach ($stores as $store) {
    $range = get_business_day_range($pdo, $store['id'], $from, $to);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS order_count, COALESCE(SUM(total_amount), 0) AS revenue,
                COALESCE(AVG(total_amount), 0) AS avg_order
         FROM orders
         WHERE store_id = ? AND status = ? AND created_at >= ? AND created_at < ?'
    );
    $stmt->execute([$store['id'], 'paid', $range['start'], $range['end']]);
    $data = $stmt->fetch();

    // 提供時間分析（served_at が記録されている注文のみ）
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS cnt,
            AVG(TIMESTAMPDIFF(SECOND, created_at, served_at)) AS avg_total,
            AVG(TIMESTAMPDIFF(SECOND, created_at, prepared_at)) AS avg_wait,
            AVG(TIMESTAMPDIFF(SECOND, prepared_at, ready_at)) AS avg_cook,
            AVG(TIMESTAMPDIFF(SECOND, ready_at, served_at)) AS avg_deliver,
            MIN(TIMESTAMPDIFF(SECOND, created_at, served_at)) AS min_total,
            MAX(TIMESTAMPDIFF(SECOND, created_at, served_at)) AS max_total
         FROM orders
         WHERE store_id = ? AND status = ? AND created_at >= ? AND created_at < ?
           AND served_at IS NOT NULL AND prepared_at IS NOT NULL AND ready_at IS NOT NULL'
    );
    $stmt->execute([$store['id'], 'paid', $range['start'], $range['end']]);
    $timing = $stmt->fetch();

    // キャンセル注文集計
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cancel_count, COALESCE(SUM(total_amount), 0) AS cancel_amount
         FROM orders
         WHERE store_id = ? AND status = ? AND created_at >= ? AND created_at < ?'
    );
    $stmt->execute([$store['id'], 'cancelled', $range['start'], $range['end']]);
    $cancelData = $stmt->fetch();

    $report = [
        'storeId'   => $store['id'],
        'storeName' => $store['name'],
        'storeSlug' => $store['slug'],
        'orderCount'   => (int)$data['order_count'],
        'revenue'      => (int)$data['revenue'],
        'avgOrderValue' => (int)$data['avg_order'],
        'cancelCount'  => (int)($cancelData['cancel_count'] ?? 0),
        'cancelAmount' => (int)($cancelData['cancel_amount'] ?? 0),
        'timing' => [
            'sampleCount'   => (int)($timing['cnt'] ?? 0),
            'avgTotal'      => (int)($timing['avg_total'] ?? 0),
            'avgWait'       => (int)($timing['avg_wait'] ?? 0),
            'avgCook'       => (int)($timing['avg_cook'] ?? 0),
            'avgDeliver'    => (int)($timing['avg_deliver'] ?? 0),
            'minTotal'      => (int)($timing['min_total'] ?? 0),
            'maxTotal'      => (int)($timing['max_total'] ?? 0),
        ],
    ];

    $storeReports[] = $report;
    $totalRevenue += $report['revenue'];
    $totalOrders += $report['orderCount'];
}

// 全店舗提供時間サマリー
$totalTimingSamples = 0;
$totalTimingSum = 0;
$totalWaitSum = 0;
$totalCookSum = 0;
$totalDeliverSum = 0;
foreach ($storeReports as $sr) {
    $t = $sr['timing'];
    if ($t['sampleCount'] > 0) {
        $totalTimingSamples += $t['sampleCount'];
        $totalTimingSum += $t['avgTotal'] * $t['sampleCount'];
        $totalWaitSum += $t['avgWait'] * $t['sampleCount'];
        $totalCookSum += $t['avgCook'] * $t['sampleCount'];
        $totalDeliverSum += $t['avgDeliver'] * $t['sampleCount'];
    }
}
$timingSummary = [
    'sampleCount' => $totalTimingSamples,
    'avgTotal'    => $totalTimingSamples > 0 ? (int)($totalTimingSum / $totalTimingSamples) : 0,
    'avgWait'     => $totalTimingSamples > 0 ? (int)($totalWaitSum / $totalTimingSamples) : 0,
    'avgCook'     => $totalTimingSamples > 0 ? (int)($totalCookSum / $totalTimingSamples) : 0,
    'avgDeliver'  => $totalTimingSamples > 0 ? (int)($totalDeliverSum / $totalTimingSamples) : 0,
];

// キャンセルサマリー
$totalCancelCount = 0;
$totalCancelAmount = 0;
foreach ($storeReports as $sr) {
    $totalCancelCount += $sr['cancelCount'];
    $totalCancelAmount += $sr['cancelAmount'];
}

// 全店舗品目ランキング + キャンセル品目ランキング
$storeIds = array_column($stores, 'id');
$itemRanking = [];
$cancelItemRanking = [];
if (!empty($storeIds)) {
    $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
    // 簡易的にfromとtoの日付で範囲指定（全店舗共通のcutoffは使わない）
    $fromDt = $from . ' 00:00:00';
    $toDt = date('Y-m-d', strtotime($to . ' +1 day')) . ' 12:00:00';

    // 売上品目
    $stmt = $pdo->prepare(
        "SELECT items FROM orders
         WHERE store_id IN ($placeholders) AND status = 'paid'
         AND created_at >= ? AND created_at < ?"
    );
    $stmt->execute(array_merge($storeIds, [$fromDt, $toDt]));
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $items = json_decode($row['items'], true) ?: [];
        foreach ($items as $item) {
            $name = $item['name'] ?? '不明';
            if (!isset($itemRanking[$name])) $itemRanking[$name] = ['name' => $name, 'qty' => 0, 'revenue' => 0];
            $itemRanking[$name]['qty'] += (int)($item['qty'] ?? 1);
            $itemRanking[$name]['revenue'] += (int)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
        }
    }
    usort($itemRanking, function ($a, $b) { return $b['revenue'] - $a['revenue']; });

    // キャンセル品目
    $stmt = $pdo->prepare(
        "SELECT items FROM orders
         WHERE store_id IN ($placeholders) AND status = 'cancelled'
         AND created_at >= ? AND created_at < ?"
    );
    $stmt->execute(array_merge($storeIds, [$fromDt, $toDt]));
    $cancelRows = $stmt->fetchAll();

    foreach ($cancelRows as $row) {
        $items = json_decode($row['items'], true) ?: [];
        foreach ($items as $item) {
            $name = $item['name'] ?? '不明';
            if (!isset($cancelItemRanking[$name])) $cancelItemRanking[$name] = ['name' => $name, 'qty' => 0, 'lostRevenue' => 0];
            $cancelItemRanking[$name]['qty'] += (int)($item['qty'] ?? 1);
            $cancelItemRanking[$name]['lostRevenue'] += (int)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
        }
    }
    usort($cancelItemRanking, function ($a, $b) { return $b['qty'] - $a['qty']; });
}

// 迷った品目ランキング（cart_events テーブル）
$hesitationRanking = [];
try {
    $pdo->query('SELECT 1 FROM cart_events LIMIT 0');
    if (!empty($storeIds)) {
        $stmt = $pdo->prepare(
            "SELECT item_id, item_name,
                    SUM(action = 'add') AS add_count,
                    SUM(action = 'remove') AS remove_count
             FROM cart_events
             WHERE store_id IN ($placeholders)
               AND created_at >= ? AND created_at < ?
             GROUP BY item_id, item_name
             HAVING remove_count > 0
             ORDER BY remove_count DESC
             LIMIT 20"
        );
        $stmt->execute(array_merge($storeIds, [$fromDt, $toDt]));
        $hesRows = $stmt->fetchAll();
        foreach ($hesRows as $hr) {
            $addCnt = (int)$hr['add_count'];
            $rmCnt = (int)$hr['remove_count'];
            $hesitationRanking[] = [
                'name'           => $hr['item_name'],
                'addCount'       => $addCnt,
                'removeCount'    => $rmCnt,
                'hesitationRate' => $addCnt > 0 ? round($rmCnt / $addCnt * 100, 1) : 0,
            ];
        }
    }
} catch (Exception $e) {
    // cart_events テーブル未作成 — スキップ
}

json_response([
    'summary' => [
        'totalRevenue' => $totalRevenue,
        'totalOrders'  => $totalOrders,
        'storeCount'   => count($stores),
        'avgOrderValue' => $totalOrders > 0 ? (int)($totalRevenue / $totalOrders) : 0,
        'cancelCount'  => $totalCancelCount,
        'cancelAmount' => $totalCancelAmount,
    ],
    'timingSummary' => $timingSummary,
    'stores'      => $storeReports,
    'itemRanking' => array_slice(array_values($itemRanking), 0, 20),
    'cancelItemRanking' => array_slice(array_values($cancelItemRanking), 0, 20),
    'hesitationRanking' => $hesitationRanking,
    'period'      => ['from' => $from, 'to' => $to],
]);
