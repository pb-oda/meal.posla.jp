<?php
/**
 * 併売分析（バスケット分析）API
 * M-4: バスケット分析
 *
 * GET /api/store/basket-analysis.php?store_id=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD&min_support=3
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

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$minSupport = max(1, (int)($_GET['min_support'] ?? 3));

$range = get_business_day_range($pdo, $storeId, $from, $to);

// ===== A: 注文ごとの品目リスト取得 =====
$orderItems = []; // [ orderId => [品目名, ...], ... ]

$hasOrderItems = false;
try {
    $pdo->query('SELECT 1 FROM order_items LIMIT 0');
    $hasOrderItems = true;
} catch (PDOException $e) {}

if ($hasOrderItems) {
    // order_items テーブルを使用（より正確）
    $stmt = $pdo->prepare(
        'SELECT order_id, name FROM order_items
         WHERE store_id = ? AND status != ?
           AND created_at >= ? AND created_at < ?
         ORDER BY order_id'
    );
    $stmt->execute([$storeId, 'cancelled', $range['start'], $range['end']]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $oid = $r['order_id'];
        $name = $r['name'] ?: '不明';
        if (!isset($orderItems[$oid])) {
            $orderItems[$oid] = [];
        }
        $orderItems[$oid][] = $name;
    }
} else {
    // フォールバック: orders.items JSON
    $stmt = $pdo->prepare(
        'SELECT id, items FROM orders
         WHERE store_id = ? AND status = ?
           AND created_at >= ? AND created_at < ?
         ORDER BY id
         LIMIT 5000'
    );
    $stmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $o) {
        $items = json_decode($o['items'], true) ?: [];
        $names = [];
        foreach ($items as $item) {
            $name = isset($item['name']) ? $item['name'] : '不明';
            $qty = isset($item['qty']) ? (int)$item['qty'] : 1;
            for ($q = 0; $q < $qty; $q++) {
                $names[] = $name;
            }
        }
        if (count($names) > 0) {
            $orderItems[$o['id']] = $names;
        }
    }
}

$totalOrders = count($orderItems);

// ===== B: 単品出現回数 + ペア集計 =====
$itemCounts = [];  // 品目名 => 出現注文数
$pairCounts = [];  // "品目A|||品目B" => 出現回数

foreach ($orderItems as $oid => $names) {
    // 重複除去（同一注文内の同品目は1回カウント）
    $unique = array_unique($names);

    // 1注文あたり品目数20超えはスキップ（メモリ保護）
    if (count($unique) > 20) continue;

    // 単品カウント
    foreach ($unique as $name) {
        if (!isset($itemCounts[$name])) {
            $itemCounts[$name] = 0;
        }
        $itemCounts[$name]++;
    }

    // ペア生成（2品目以上の注文のみ）
    $sorted = array_values($unique);
    sort($sorted);
    $n = count($sorted);
    if ($n < 2) continue;

    for ($i = 0; $i < $n - 1; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $key = $sorted[$i] . '|||' . $sorted[$j];
            if (!isset($pairCounts[$key])) {
                $pairCounts[$key] = 0;
            }
            $pairCounts[$key]++;
        }
    }
}

// ===== C: min_support フィルタ + 指標計算 =====
$pairs = [];
foreach ($pairCounts as $key => $support) {
    if ($support < $minSupport) continue;

    $parts = explode('|||', $key);
    $itemA = $parts[0];
    $itemB = $parts[1];

    $countA = isset($itemCounts[$itemA]) ? $itemCounts[$itemA] : 0;
    $countB = isset($itemCounts[$itemB]) ? $itemCounts[$itemB] : 0;

    $confidenceAB = $countA > 0 ? round($support / $countA * 100, 1) : 0;
    $confidenceBA = $countB > 0 ? round($support / $countB * 100, 1) : 0;
    $lift = ($countA > 0 && $countB > 0 && $totalOrders > 0)
        ? round($support * $totalOrders / ($countA * $countB), 2)
        : 0;

    $pairs[] = [
        'itemA'        => $itemA,
        'itemB'        => $itemB,
        'support'      => $support,
        'confidenceAB' => $confidenceAB,
        'confidenceBA' => $confidenceBA,
        'lift'         => $lift,
    ];
}

// support 降順ソート、上位30件
usort($pairs, function ($a, $b) {
    return $b['support'] - $a['support'];
});
$pairs = array_slice($pairs, 0, 30);

// ===== D: 単品ランキング（上位20件） =====
arsort($itemCounts);
$topItems = [];
$rank = 0;
foreach ($itemCounts as $name => $count) {
    $topItems[] = ['name' => $name, 'count' => $count];
    $rank++;
    if ($rank >= 20) break;
}

json_response([
    'totalOrders' => $totalOrders,
    'totalItems'  => count($itemCounts),
    'pairs'       => $pairs,
    'topItems'    => $topItems,
    'period'      => ['from' => $from, 'to' => $to],
]);
