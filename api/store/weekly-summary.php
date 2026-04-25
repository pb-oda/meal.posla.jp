<?php
/**
 * RPT-P1-1: 週次サマリー API
 *
 * 過去 7 日間の KPI をまとめて返す (前週比較つき)。
 * 既存 sales-report.php / turnover-report.php を置き換えるのではなく、
 * store dashboard の上位 summary layer として追加する軽量 KPI エンドポイント。
 *
 * GET /api/store/weekly-summary.php?store_id=xxx
 *
 * 期間:
 *   thisWeek: 過去 7 日間 (today 含む) = today - 6 days 〜 today
 *   lastWeek: その前 7 日間            = today - 13 days 〜 today - 7 days
 *
 * KPIs:
 *   - totalRevenue  : voided 除外済 orders.total_amount 合計
 *   - orderCount    : paid orders の件数
 *   - guestCount    : table_sessions.guest_count の清算済合計
 *   - avgOrderValue : totalRevenue / orderCount
 *   - topItems (thisWeek only, top 3) : name / qty / revenue
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

// ===== 期間算出 =====
// thisWeek: today - 6 〜 today (7 日間、今日含む)
// lastWeek: today - 13 〜 today - 7 (7 日間)
$today = date('Y-m-d');
$thisFrom = date('Y-m-d', strtotime($today . ' -6 days'));
$thisTo   = $today;
$lastFrom = date('Y-m-d', strtotime($today . ' -13 days'));
$lastTo   = date('Y-m-d', strtotime($today . ' -7 days'));

$thisRange = get_business_day_range($pdo, $storeId, $thisFrom, $thisTo);
$lastRange = get_business_day_range($pdo, $storeId, $lastFrom, $lastTo);

// ===== voided 除外句 (sales-report.php と同じパターン) =====
// payments.void_status 未適用環境はフォールバック (除外句なし)
$hasPaymentVoidCol = false;
try { $pdo->query('SELECT void_status FROM payments LIMIT 0'); $hasPaymentVoidCol = true; }
catch (PDOException $e) {}

$voidExclude = $hasPaymentVoidCol
  ? ' AND NOT (EXISTS (SELECT 1 FROM payments p_any
                         WHERE p_any.store_id = o.store_id
                           AND JSON_CONTAINS(p_any.order_ids, JSON_QUOTE(o.id)))
              AND NOT EXISTS (SELECT 1 FROM payments p_act
                               WHERE p_act.store_id = o.store_id
                                 AND JSON_CONTAINS(p_act.order_ids, JSON_QUOTE(o.id))
                                 AND (p_act.void_status IS NULL OR p_act.void_status <> \'voided\')))'
  : '';

// ===== 集計ヘルパ =====
/**
 * 指定期間の KPI を集計。items (top ranking) が必要なときは $withItems=true
 */
function _weekly_collect(PDO $pdo, string $storeId, array $range, string $voidExclude, bool $withItems): array {
    // 売上 / 注文件数 (voided 除外)
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount), 0) AS total_revenue
         FROM orders o
         WHERE o.store_id = ? AND o.status = ? AND o.created_at >= ? AND o.created_at < ?' . $voidExclude
    );
    $stmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
    $row = $stmt->fetch();
    $orderCount = (int)($row['order_count'] ?? 0);
    $totalRevenue = (int)($row['total_revenue'] ?? 0);
    $avgOrderValue = $orderCount > 0 ? (int)round($totalRevenue / $orderCount) : 0;

    // 客数: table_sessions (清算済 / closed) の guest_count 合計。
    // turnover-report.php と揃え、voided 除外はしない (運用実績として数える)
    $guestCount = 0;
    try {
        $gs = $pdo->prepare(
            'SELECT COALESCE(SUM(guest_count), 0) AS g
             FROM table_sessions
             WHERE store_id = ? AND status IN (?, ?)
               AND started_at >= ? AND started_at < ?'
        );
        $gs->execute([$storeId, 'paid', 'closed', $range['start'], $range['end']]);
        $gRow = $gs->fetch();
        $guestCount = (int)($gRow['g'] ?? 0);
    } catch (PDOException $e) {
        // table_sessions 未適用環境はフォールバック (0)
    }

    $result = [
        'totalRevenue'  => $totalRevenue,
        'orderCount'    => $orderCount,
        'guestCount'    => $guestCount,
        'avgOrderValue' => $avgOrderValue,
    ];

    if ($withItems) {
        // Top items (orders.items JSON 集約)
        $itemStmt = $pdo->prepare(
            'SELECT o.items
             FROM orders o
             WHERE o.store_id = ? AND o.status = ? AND o.created_at >= ? AND o.created_at < ?' . $voidExclude
        );
        $itemStmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
        $ranking = [];
        foreach ($itemStmt->fetchAll() as $o) {
            $items = json_decode($o['items'], true) ?: [];
            foreach ($items as $item) {
                $name = isset($item['name']) ? (string)$item['name'] : '不明';
                if (!isset($ranking[$name])) {
                    $ranking[$name] = ['name' => $name, 'qty' => 0, 'revenue' => 0];
                }
                $qty = (int)($item['qty'] ?? 1);
                $price = (int)($item['price'] ?? 0);
                $ranking[$name]['qty'] += $qty;
                $ranking[$name]['revenue'] += $price * $qty;
            }
        }
        usort($ranking, function ($a, $b) { return $b['revenue'] - $a['revenue']; });
        $result['topItems'] = array_slice(array_values($ranking), 0, 3);
    }

    return $result;
}

$thisWeek = _weekly_collect($pdo, $storeId, $thisRange, $voidExclude, true);
$lastWeek = _weekly_collect($pdo, $storeId, $lastRange, $voidExclude, false);

// ===== 前週比 (0 除算ガード) =====
function _weekly_delta_pct(int $now, int $prev): ?int {
    if ($prev <= 0) return null; // 前週ゼロなら比率算出不能
    return (int)round((($now - $prev) / $prev) * 100);
}

$deltas = [
    'revenuePct'       => _weekly_delta_pct($thisWeek['totalRevenue'], $lastWeek['totalRevenue']),
    'orderPct'         => _weekly_delta_pct($thisWeek['orderCount'], $lastWeek['orderCount']),
    'guestPct'         => _weekly_delta_pct($thisWeek['guestCount'], $lastWeek['guestCount']),
    'avgOrderValuePct' => _weekly_delta_pct($thisWeek['avgOrderValue'], $lastWeek['avgOrderValue']),
];

json_response([
    'thisWeek' => $thisWeek,
    'lastWeek' => $lastWeek,
    'deltas'   => $deltas,
    'period'   => [
        'this' => ['from' => $thisFrom, 'to' => $thisTo],
        'last' => ['from' => $lastFrom, 'to' => $lastTo],
    ],
]);
