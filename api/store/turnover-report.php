<?php
/**
 * 回転率・調理時間・客層分析レポート API
 * M-2: 回転率・調理時間レポート強化
 * M-5: 客層・価格帯分析
 *
 * GET /api/store/turnover-report.php?store_id=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
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
$to   = $_GET['to']   ?? date('Y-m-d');

$range = get_business_day_range($pdo, $storeId, $from, $to);

// ===== 目標調理時間 =====
$targetCookSec = 900; // デフォルト15分（店舗別設定は未実装）

// Phase 4d-5c-a (2026-04-21): 売上系集計でだけ「全 voided 注文」を除外する。
//   調理時間 (cooking analysis) / table_sessions ベースの回転率は運用実績なので除外しない。
//   migration-pwa4d5a 未適用環境は動的にフォールバック。
$hasPaymentVoidColForReport = false;
try { $pdo->query('SELECT void_status FROM payments LIMIT 0'); $hasPaymentVoidColForReport = true; }
catch (PDOException $e) {}
$voidExclude = $hasPaymentVoidColForReport
  ? ' AND NOT (EXISTS (SELECT 1 FROM payments p_any
                         WHERE p_any.store_id = o.store_id
                           AND JSON_CONTAINS(p_any.order_ids, JSON_QUOTE(o.id)))
              AND NOT EXISTS (SELECT 1 FROM payments p_act
                               WHERE p_act.store_id = o.store_id
                                 AND JSON_CONTAINS(p_act.order_ids, JSON_QUOTE(o.id))
                                 AND (p_act.void_status IS NULL OR p_act.void_status <> \'voided\')))'
  : '';

// ===== 1. テーブル回転率 =====
$stmt = $pdo->prepare(
    'SELECT ts.id AS session_id, ts.table_id, t.table_code, ts.guest_count,
            ts.started_at, ts.closed_at,
            TIMESTAMPDIFF(MINUTE, ts.started_at, ts.closed_at) AS stay_min
     FROM table_sessions ts
     JOIN tables t ON t.id = ts.table_id
     WHERE ts.store_id = ? AND ts.status IN ("paid","closed")
       AND ts.started_at >= ? AND ts.started_at < ?
       AND ts.closed_at IS NOT NULL
     ORDER BY ts.started_at'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
$sessions = $stmt->fetchAll();

// テーブル別集計
$tableStats = [];
$allStayMinutes = [];
foreach ($sessions as $s) {
    $tc = $s['table_code'];
    $stayMin = max(1, (int)$s['stay_min']);
    $allStayMinutes[] = $stayMin;
    if (!isset($tableStats[$tc])) {
        $tableStats[$tc] = [
            'tableCode' => $tc,
            'turnCount' => 0,
            'totalStay' => 0,
            'totalGuests' => 0,
        ];
    }
    $tableStats[$tc]['turnCount']++;
    $tableStats[$tc]['totalStay'] += $stayMin;
    $gc = (int)($s['guest_count'] ?: 0);
    $tableStats[$tc]['totalGuests'] += $gc;
}

foreach ($tableStats as &$ts) {
    $ts['avgStay'] = $ts['turnCount'] > 0 ? round($ts['totalStay'] / $ts['turnCount']) : 0;
}
unset($ts);

$avgStayAll = count($allStayMinutes) > 0
    ? round(array_sum($allStayMinutes) / count($allStayMinutes))
    : 0;

$stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM tables WHERE store_id = ?');
$stmt->execute([$storeId]);
$tableCount = (int)$stmt->fetchColumn();

$turnoverSummary = [
    'totalSessions'  => count($sessions),
    'avgStayMinutes' => $avgStayAll,
    'tableCount'     => $tableCount,
    'avgTurnover'    => $tableCount > 0
        ? round(count($sessions) / $tableCount, 1)
        : 0,
];

// ===== 2. 食事時間帯別（ランチ/ディナー） =====
$mealPeriods = [
    'lunch'  => ['period' => 'lunch',  'label' => 'ランチ (10-15時)', 'sessions' => 0, 'guests' => 0, 'totalStay' => 0, 'revenue' => 0],
    'dinner' => ['period' => 'dinner', 'label' => 'ディナー (17-23時)', 'sessions' => 0, 'guests' => 0, 'totalStay' => 0, 'revenue' => 0],
    'other'  => ['period' => 'other',  'label' => 'その他', 'sessions' => 0, 'guests' => 0, 'totalStay' => 0, 'revenue' => 0],
];

foreach ($sessions as $s) {
    $hour = (int)date('G', strtotime($s['started_at']));
    if ($hour >= 10 && $hour < 15) {
        $period = 'lunch';
    } elseif ($hour >= 17 && $hour < 23) {
        $period = 'dinner';
    } else {
        $period = 'other';
    }
    $stayMin = max(1, (int)$s['stay_min']);
    $mealPeriods[$period]['sessions']++;
    $mealPeriods[$period]['guests'] += (int)($s['guest_count'] ?: 0);
    $mealPeriods[$period]['totalStay'] += $stayMin;
}

foreach ($mealPeriods as &$mp) {
    $mp['avgStay'] = $mp['sessions'] > 0 ? round($mp['totalStay'] / $mp['sessions']) : 0;
}
unset($mp);

// 時間帯別売上を注文から取得 (Phase 4d-5c-a: voided 全 payments の orders を除外)
$stmt = $pdo->prepare(
    'SELECT HOUR(o.created_at) AS h, COUNT(*) AS cnt,
            COALESCE(SUM(o.total_amount), 0) AS rev
     FROM orders o
     WHERE o.store_id = ? AND o.status = "paid"
       AND o.created_at >= ? AND o.created_at < ?' . $voidExclude . '
     GROUP BY HOUR(o.created_at)
     ORDER BY h'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
$hourlyOrders = $stmt->fetchAll();

foreach ($hourlyOrders as $ho) {
    $h = (int)$ho['h'];
    if ($h >= 10 && $h < 15) {
        $mealPeriods['lunch']['revenue'] += (int)$ho['rev'];
    } elseif ($h >= 17 && $h < 23) {
        $mealPeriods['dinner']['revenue'] += (int)$ho['rev'];
    } else {
        $mealPeriods['other']['revenue'] += (int)$ho['rev'];
    }
}

// ===== 3. 時間帯別効率 + peakHour =====
$hourlyEfficiency = [];
foreach ($sessions as $s) {
    $h = (int)date('G', strtotime($s['started_at']));
    if (!isset($hourlyEfficiency[$h])) {
        $hourlyEfficiency[$h] = [
            'hour' => $h,
            'sessions' => 0,
            'guests' => 0,
            'totalStay' => 0,
        ];
    }
    $hourlyEfficiency[$h]['sessions']++;
    $hourlyEfficiency[$h]['guests'] += (int)($s['guest_count'] ?: 0);
    $hourlyEfficiency[$h]['totalStay'] += max(1, (int)$s['stay_min']);
}
foreach ($hourlyOrders as $ho) {
    $h = (int)$ho['h'];
    if (!isset($hourlyEfficiency[$h])) {
        $hourlyEfficiency[$h] = [
            'hour' => $h,
            'sessions' => 0,
            'guests' => 0,
            'totalStay' => 0,
        ];
    }
    $hourlyEfficiency[$h]['orders'] = (int)$ho['cnt'];
    $hourlyEfficiency[$h]['revenue'] = (int)$ho['rev'];
}
foreach ($hourlyEfficiency as &$he) {
    $he['avgStay'] = $he['sessions'] > 0 ? round($he['totalStay'] / $he['sessions']) : 0;
    if (!isset($he['orders'])) $he['orders'] = 0;
    if (!isset($he['revenue'])) $he['revenue'] = 0;
}
unset($he);
ksort($hourlyEfficiency);

// ピーク時間帯
$peakHour = null;
$peakSessions = 0;
foreach ($hourlyEfficiency as $he) {
    if ($he['sessions'] > $peakSessions) {
        $peakSessions = $he['sessions'];
        $peakHour = $he['hour'];
    }
}

// ===== 4. 調理時間分析（品目別） =====
$cookingByItem = [];
$hasOrderItems = false;
try {
    $pdo->query('SELECT 1 FROM order_items LIMIT 0');
    $hasOrderItems = true;
} catch (PDOException $e) {}

if ($hasOrderItems) {
    $stmt = $pdo->prepare(
        'SELECT oi.name, oi.status,
                TIMESTAMPDIFF(SECOND, o.created_at, oi.updated_at) AS cook_sec
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.store_id = ? AND o.status = "paid"
           AND o.created_at >= ? AND o.created_at < ?
           AND oi.status IN ("ready","served")'
    );
    $stmt->execute([$storeId, $range['start'], $range['end']]);
    $oiRows = $stmt->fetchAll();

    foreach ($oiRows as $row) {
        $name = $row['name'];
        $sec  = max(0, (int)$row['cook_sec']);
        if ($sec <= 0 || $sec > 7200) continue;
        if (!isset($cookingByItem[$name])) {
            $cookingByItem[$name] = ['name' => $name, 'count' => 0, 'totalSec' => 0, 'times' => []];
        }
        $cookingByItem[$name]['count']++;
        $cookingByItem[$name]['totalSec'] += $sec;
        $cookingByItem[$name]['times'][] = $sec;
    }
} else {
    $stmt = $pdo->prepare(
        'SELECT items, created_at, prepared_at, ready_at
         FROM orders
         WHERE store_id = ? AND status = "paid"
           AND created_at >= ? AND created_at < ?
           AND (prepared_at IS NOT NULL OR ready_at IS NOT NULL)'
    );
    $stmt->execute([$storeId, $range['start'], $range['end']]);
    $fallbackOrders = $stmt->fetchAll();

    foreach ($fallbackOrders as $fo) {
        $refTime = $fo['ready_at'] ?: $fo['prepared_at'];
        $sec = strtotime($refTime) - strtotime($fo['created_at']);
        if ($sec <= 0 || $sec > 7200) continue;
        $items = json_decode($fo['items'], true) ?: [];
        foreach ($items as $item) {
            $name = $item['name'] ?? '不明';
            if (!isset($cookingByItem[$name])) {
                $cookingByItem[$name] = ['name' => $name, 'count' => 0, 'totalSec' => 0, 'times' => []];
            }
            $cookingByItem[$name]['count']++;
            $cookingByItem[$name]['totalSec'] += $sec;
            $cookingByItem[$name]['times'][] = $sec;
        }
    }
}

$cookingAnalysis = [];
foreach ($cookingByItem as $ci) {
    sort($ci['times']);
    $avg = $ci['count'] > 0 ? round($ci['totalSec'] / $ci['count']) : 0;
    $median = $ci['count'] > 0 ? $ci['times'][(int)(count($ci['times']) / 2)] : 0;
    $max = $ci['count'] > 0 ? end($ci['times']) : 0;
    $cookingAnalysis[] = [
        'name'   => $ci['name'],
        'count'  => $ci['count'],
        'avg'    => $avg,
        'median' => $median,
        'max'    => $max,
    ];
}
usort($cookingAnalysis, function ($a, $b) { return $b['avg'] - $a['avg']; });

// 全体の調理時間サマリー + 超過率
$allCookTimes = [];
foreach ($cookingByItem as $ci) {
    $allCookTimes = array_merge($allCookTimes, $ci['times']);
}
$cookingSummary = null;
if (count($allCookTimes) > 0) {
    sort($allCookTimes);
    $overTargetCount = 0;
    foreach ($allCookTimes as $ct) {
        if ($ct > $targetCookSec) $overTargetCount++;
    }
    $cookingSummary = [
        'avg'            => round(array_sum($allCookTimes) / count($allCookTimes)),
        'median'         => $allCookTimes[(int)(count($allCookTimes) / 2)],
        'max'            => end($allCookTimes),
        'count'          => count($allCookTimes),
        'targetSec'      => $targetCookSec,
        'overTargetRate' => round($overTargetCount / count($allCookTimes) * 100, 1),
    ];
}

// ===== 5. 客層・価格帯分析 =====

// 5a. 組人数別の支出分析（table_sessions JOIN orders）
$guestGroups = [];
// guest_count > 0 のセッションのみ対象
$validSessions = array_filter($sessions, function ($s) {
    return (int)($s['guest_count'] ?: 0) > 0;
});

$totalGuests = 0;
foreach ($validSessions as $s) {
    $totalGuests += (int)$s['guest_count'];
}

// セッション別に注文合計を取得
$sessionSpend = [];
if (count($validSessions) > 0) {
    // table_id + 時間範囲で注文をマッチ
    // Phase 4d-5c-a: voided 全 payments の orders を客層支出分析から除外
    $stmt = $pdo->prepare(
        'SELECT o.table_id,
                COALESCE(SUM(o.total_amount), 0) AS spend
         FROM orders o
         WHERE o.store_id = ? AND o.status = "paid"
           AND o.created_at >= ? AND o.created_at < ?' . $voidExclude . '
         GROUP BY o.table_id'
    );
    $stmt->execute([$storeId, $range['start'], $range['end']]);
    $tableSpendRows = $stmt->fetchAll();
    foreach ($tableSpendRows as $tsr) {
        $sessionSpend[$tsr['table_id']] = (int)$tsr['spend'];
    }
}

// guest_count でグループ化
$guestBuckets = [];
foreach ($validSessions as $s) {
    $gc = (int)$s['guest_count'];
    $label = $gc >= 5 ? '5名以上' : $gc . '名';
    if (!isset($guestBuckets[$label])) {
        $guestBuckets[$label] = [
            'guestLabel'   => $label,
            'sessionCount' => 0,
            'totalGuests'  => 0,
            'totalSpend'   => 0,
        ];
    }
    $guestBuckets[$label]['sessionCount']++;
    $guestBuckets[$label]['totalGuests'] += $gc;
    $guestBuckets[$label]['totalSpend'] += isset($sessionSpend[$s['table_id']]) ? $sessionSpend[$s['table_id']] : 0;
}

foreach ($guestBuckets as &$gb) {
    $gb['avgSpendGroup']  = $gb['sessionCount'] > 0 ? round($gb['totalSpend'] / $gb['sessionCount']) : 0;
    $gb['avgSpendPerson'] = $gb['totalGuests'] > 0 ? round($gb['totalSpend'] / $gb['totalGuests']) : 0;
}
unset($gb);
$guestGroups = array_values($guestBuckets);

// 5b. 価格帯別注文分布（3段階）(Phase 4d-5c-a: voided 全 payments の orders を除外)
$stmt = $pdo->prepare(
    'SELECT o.total_amount FROM orders o
     WHERE o.store_id = ? AND o.status = "paid"
       AND o.created_at >= ? AND o.created_at < ?' . $voidExclude
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
$paidOrders = $stmt->fetchAll();

$priceBands = [
    ['band' => 'under500',  'label' => '¥500未満',      'min' => 0,    'max' => 499,       'orderCount' => 0, 'totalRevenue' => 0],
    ['band' => 'mid',       'label' => '¥500〜¥1,000',  'min' => 500,  'max' => 999,       'orderCount' => 0, 'totalRevenue' => 0],
    ['band' => 'over1000',  'label' => '¥1,000以上',    'min' => 1000, 'max' => PHP_INT_MAX, 'orderCount' => 0, 'totalRevenue' => 0],
];

foreach ($paidOrders as $po) {
    $amt = (int)$po['total_amount'];
    foreach ($priceBands as &$band) {
        if ($amt >= $band['min'] && $amt <= $band['max']) {
            $band['orderCount']++;
            $band['totalRevenue'] += $amt;
            break;
        }
    }
    unset($band);
}

$orderCount = count($paidOrders);
$totalRevenue = 0;
foreach ($paidOrders as $po) {
    $totalRevenue += (int)$po['total_amount'];
}
$avgPerOrder = $orderCount > 0 ? round($totalRevenue / $orderCount) : 0;
$avgPerGuest = $totalGuests > 0 ? round($totalRevenue / $totalGuests) : 0;

// 5c. 時間帯別客層
$hourlyCustomers = [];
foreach ($validSessions as $s) {
    $h = (int)date('G', strtotime($s['started_at']));
    $gc = (int)$s['guest_count'];
    $spend = isset($sessionSpend[$s['table_id']]) ? $sessionSpend[$s['table_id']] : 0;
    if (!isset($hourlyCustomers[$h])) {
        $hourlyCustomers[$h] = [
            'hour' => $h,
            'sessionCount' => 0,
            'totalGuests' => 0,
            'totalSpend' => 0,
        ];
    }
    $hourlyCustomers[$h]['sessionCount']++;
    $hourlyCustomers[$h]['totalGuests'] += $gc;
    $hourlyCustomers[$h]['totalSpend'] += $spend;
}
foreach ($hourlyCustomers as &$hc) {
    $hc['avgGuestCount'] = $hc['sessionCount'] > 0 ? round($hc['totalGuests'] / $hc['sessionCount'], 1) : 0;
    $hc['avgSpend']      = $hc['sessionCount'] > 0 ? round($hc['totalSpend'] / $hc['sessionCount']) : 0;
}
unset($hc);
ksort($hourlyCustomers);

// 価格帯レスポンス整形
$priceBandResult = [];
foreach ($priceBands as $b) {
    $priceBandResult[] = [
        'band'         => $b['band'],
        'label'        => $b['label'],
        'orderCount'   => $b['orderCount'],
        'totalRevenue' => $b['totalRevenue'],
    ];
}

json_response([
    'turnover' => [
        'summary' => $turnoverSummary,
        'byTable' => array_values($tableStats),
    ],
    'overallAvgStay' => $avgStayAll,
    'mealPeriods'      => array_values($mealPeriods),
    'hourlyEfficiency' => array_values($hourlyEfficiency),
    'peakHour'         => $peakHour,
    'cooking' => [
        'summary' => $cookingSummary,
        'byItem'  => array_slice($cookingAnalysis, 0, 30),
    ],
    'customer' => [
        'totalGroups'     => count($validSessions),
        'totalGuests'     => $totalGuests,
        'avgPerOrder'     => $avgPerOrder,
        'avgPerGuest'     => $avgPerGuest,
        'guestGroups'     => $guestGroups,
        'priceBands'      => $priceBandResult,
        'hourlyCustomers' => array_values($hourlyCustomers),
    ],
    'period' => ['from' => $from, 'to' => $to],
]);
