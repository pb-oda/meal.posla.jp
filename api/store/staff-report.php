<?php
/**
 * スタッフ別評価 + 監査・不正検知レポート API
 * M-1: スタッフ別評価レポート
 * M-3: 監査・不正検知
 *
 * GET /api/store/staff-report.php?store_id=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';

require_method(['GET']);
$user = require_role('manager');
$pdo = get_db();

$storeId  = require_store_param();
require_store_access($storeId);
$tenantId = $user['tenant_id'];

$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');

$range = get_business_day_range($pdo, $storeId, $from, $to);

// ===== スタッフ一覧取得 =====
$stmt = $pdo->prepare(
    'SELECT u.id, u.display_name, u.role
     FROM users u
     JOIN user_stores us ON us.user_id = u.id
     WHERE us.store_id = ? AND u.tenant_id = ? AND u.is_active = 1
     ORDER BY u.display_name'
);
$stmt->execute([$storeId, $tenantId]);
$staffRows = $stmt->fetchAll();

$staffMap = [];
foreach ($staffRows as $sr) {
    $staffMap[$sr['id']] = [
        'userId'      => $sr['id'],
        'displayName' => $sr['display_name'],
        'role'        => $sr['role'],
    ];
}

// ===== A: スタッフ別注文実績 =====
$stmt = $pdo->prepare(
    'SELECT staff_id,
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) AS paid_orders,
            SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_orders,
            COALESCE(SUM(CASE WHEN status = "paid" THEN total_amount ELSE 0 END), 0) AS total_revenue
     FROM orders
     WHERE store_id = ? AND staff_id IS NOT NULL
       AND created_at >= ? AND created_at < ?
     GROUP BY staff_id'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
$orderStats = $stmt->fetchAll();

$orderByStaff = [];
foreach ($orderStats as $os) {
    $orderByStaff[$os['staff_id']] = $os;
}

// ===== B: スタッフ別満足度 =====
$ratingByStaff = [];
try {
    $pdo->query('SELECT 1 FROM satisfaction_ratings LIMIT 0');
    $stmt = $pdo->prepare(
        'SELECT o.staff_id,
                AVG(sr.rating) AS avg_rating,
                COUNT(sr.id) AS rating_count
         FROM satisfaction_ratings sr
         JOIN orders o ON o.id = sr.order_id
         WHERE sr.store_id = ? AND o.staff_id IS NOT NULL
           AND sr.created_at >= ? AND sr.created_at < ?
         GROUP BY o.staff_id'
    );
    $stmt->execute([$storeId, $range['start'], $range['end']]);
    $ratingRows = $stmt->fetchAll();
    foreach ($ratingRows as $rr) {
        $ratingByStaff[$rr['staff_id']] = $rr;
    }
} catch (PDOException $e) {
    // satisfaction_ratings テーブルが存在しない場合
}

// スタッフ別実績を統合
$staffPerformance = [];
foreach ($staffMap as $uid => $info) {
    $os = isset($orderByStaff[$uid]) ? $orderByStaff[$uid] : null;
    $rs = isset($ratingByStaff[$uid]) ? $ratingByStaff[$uid] : null;

    $totalOrders     = $os ? (int)$os['total_orders'] : 0;
    $paidOrders      = $os ? (int)$os['paid_orders'] : 0;
    $cancelledOrders = $os ? (int)$os['cancelled_orders'] : 0;
    $totalRevenue    = $os ? (int)$os['total_revenue'] : 0;

    $staffPerformance[] = [
        'userId'          => $uid,
        'displayName'     => $info['displayName'],
        'role'            => $info['role'],
        'totalOrders'     => $totalOrders,
        'paidOrders'      => $paidOrders,
        'cancelledOrders' => $cancelledOrders,
        'cancelRate'      => $totalOrders > 0 ? round($cancelledOrders / $totalOrders * 100, 1) : 0,
        'totalRevenue'    => $totalRevenue,
        'avgOrderValue'   => $paidOrders > 0 ? round($totalRevenue / $paidOrders) : 0,
        'avgRating'       => $rs ? round((float)$rs['avg_rating'], 1) : null,
        'ratingCount'     => $rs ? (int)$rs['rating_count'] : 0,
    ];
}

// ===== C: スタッフ別キャンセル詳細 =====
$cancelDetails = [];
$hasAuditLog = false;
try {
    $pdo->query('SELECT 1 FROM audit_log LIMIT 0');
    $hasAuditLog = true;
} catch (PDOException $e) {}

if ($hasAuditLog) {
    $stmt = $pdo->prepare(
        'SELECT user_id, username, reason, HOUR(created_at) AS h
         FROM audit_log
         WHERE tenant_id = ? AND store_id = ? AND action = "order_cancel"
           AND created_at >= ? AND created_at < ?'
    );
    $stmt->execute([$tenantId, $storeId, $range['start'], $range['end']]);
    $cancelRows = $stmt->fetchAll();

    $cancelBucket = [];
    foreach ($cancelRows as $cr) {
        $uid = $cr['user_id'];
        if (!isset($cancelBucket[$uid])) {
            $name = isset($staffMap[$uid]) ? $staffMap[$uid]['displayName'] : ($cr['username'] ?: '不明');
            $cancelBucket[$uid] = [
                'userId'      => $uid,
                'displayName' => $name,
                'cancelCount' => 0,
                'reasons'     => [],
                'hourlyDist'  => [],
            ];
        }
        $cancelBucket[$uid]['cancelCount']++;

        $reason = $cr['reason'] ?: '理由なし';
        if (!isset($cancelBucket[$uid]['reasons'][$reason])) {
            $cancelBucket[$uid]['reasons'][$reason] = 0;
        }
        $cancelBucket[$uid]['reasons'][$reason]++;

        $h = (int)$cr['h'];
        if (!isset($cancelBucket[$uid]['hourlyDist'][$h])) {
            $cancelBucket[$uid]['hourlyDist'][$h] = 0;
        }
        $cancelBucket[$uid]['hourlyDist'][$h]++;
    }
    $cancelDetails = array_values($cancelBucket);
}

// ===== D: レジ差異検出（日別） =====
$registerDiscrepancies = [];

// 対象期間のcash_logを全件取得
$stmt = $pdo->prepare(
    'SELECT cl.*, u.display_name AS user_name, DATE(cl.created_at) AS log_date
     FROM cash_log cl
     LEFT JOIN users u ON u.id = cl.user_id
     WHERE cl.store_id = ? AND cl.created_at >= ? AND cl.created_at < ?
     ORDER BY cl.created_at'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
$allCashLogs = $stmt->fetchAll();

// 日別にグルーピング
$dailyCashLog = [];
foreach ($allCashLogs as $cl) {
    $d = $cl['log_date'];
    if (!isset($dailyCashLog[$d])) {
        $dailyCashLog[$d] = [];
    }
    $dailyCashLog[$d][] = $cl;
}

foreach ($dailyCashLog as $date => $entries) {
    $openAmount = 0;
    $cashSales  = 0;
    $cashIn     = 0;
    $cashOut    = 0;
    $closeAmount = null;
    $openedBy = null;
    $closedBy = null;

    foreach ($entries as $e) {
        switch ($e['type']) {
            case 'open':
                $openAmount = (int)$e['amount'];
                $openedBy = $e['user_name'] ?: '不明';
                break;
            case 'cash_sale':
                $cashSales += (int)$e['amount'];
                break;
            case 'cash_in':
                $cashIn += (int)$e['amount'];
                break;
            case 'cash_out':
                $cashOut += (int)$e['amount'];
                break;
            case 'close':
                $closeAmount = (int)$e['amount'];
                $closedBy = $e['user_name'] ?: '不明';
                break;
        }
    }

    $expectedBalance = $openAmount + $cashSales + $cashIn - $cashOut;
    $overshort = $closeAmount !== null ? $closeAmount - $expectedBalance : null;

    $registerDiscrepancies[] = [
        'date'            => $date,
        'openedBy'        => $openedBy,
        'closedBy'        => $closedBy,
        'expectedBalance' => $expectedBalance,
        'closeAmount'     => $closeAmount,
        'overshort'       => $overshort,
        'isAlert'         => $overshort !== null && abs($overshort) > 500,
    ];
}

// ===== E: 深夜帯アラート =====
$lateNightOps = [];
if ($hasAuditLog) {
    $stmt = $pdo->prepare(
        'SELECT user_id, username, action, created_at, reason
         FROM audit_log
         WHERE tenant_id = ? AND store_id = ?
           AND created_at >= ? AND created_at < ?
           AND action IN ("order_cancel", "cash_out", "settings_update")
           AND (HOUR(created_at) >= 23 OR HOUR(created_at) < 5)
         ORDER BY created_at'
    );
    $stmt->execute([$tenantId, $storeId, $range['start'], $range['end']]);
    $lnRows = $stmt->fetchAll();

    foreach ($lnRows as $ln) {
        $lateNightOps[] = [
            'userId'    => $ln['user_id'],
            'username'  => $ln['username'] ?: '不明',
            'action'    => $ln['action'],
            'createdAt' => $ln['created_at'],
            'reason'    => $ln['reason'],
        ];
    }
}

// ===== F: 異常検知サマリー =====
$alerts = [];

// F-1: キャンセル率 >= 20% のスタッフ
foreach ($staffPerformance as $sp) {
    if ($sp['totalOrders'] > 0 && $sp['cancelRate'] >= 20) {
        $alerts[] = [
            'type'      => 'high_cancel_rate',
            'severity'  => 'danger',
            'message'   => $sp['displayName'] . ' のキャンセル率が ' . $sp['cancelRate'] . '% です',
            'staffName' => $sp['displayName'],
            'datetime'  => null,
        ];
    }
}

// F-2: レジ差異 >= ¥500
foreach ($registerDiscrepancies as $rd) {
    if ($rd['isAlert']) {
        $alerts[] = [
            'type'      => 'register_discrepancy',
            'severity'  => abs($rd['overshort']) >= 1000 ? 'danger' : 'warning',
            'message'   => $rd['date'] . ' のレジ差異: ' . ($rd['overshort'] >= 0 ? '+' : '') . number_format($rd['overshort']) . '円',
            'staffName' => $rd['closedBy'],
            'datetime'  => $rd['date'],
        ];
    }
}

// F-3: 深夜帯操作
if (count($lateNightOps) > 0) {
    $alerts[] = [
        'type'      => 'late_night_ops',
        'severity'  => 'warning',
        'message'   => '深夜帯（23:00〜5:00）の操作が ' . count($lateNightOps) . ' 件あります',
        'staffName' => null,
        'datetime'  => null,
    ];
}

// F-4: 1時間以内にキャンセル3件以上
if ($hasAuditLog) {
    $stmt = $pdo->prepare(
        'SELECT user_id, username, created_at
         FROM audit_log
         WHERE tenant_id = ? AND store_id = ? AND action = "order_cancel"
           AND created_at >= ? AND created_at < ?
         ORDER BY user_id, created_at'
    );
    $stmt->execute([$tenantId, $storeId, $range['start'], $range['end']]);
    $cancelTimeline = $stmt->fetchAll();

    // ユーザーごとにグループ化して連続3件チェック
    $byUser = [];
    foreach ($cancelTimeline as $ct) {
        $uid = $ct['user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = ['username' => $ct['username'], 'times' => []];
        }
        $byUser[$uid]['times'][] = strtotime($ct['created_at']);
    }

    $burstAlertedUsers = [];
    foreach ($byUser as $uid => $data) {
        $times = $data['times'];
        if (count($times) < 3) continue;
        for ($i = 0; $i <= count($times) - 3; $i++) {
            if ($times[$i + 2] - $times[$i] <= 3600) {
                if (!isset($burstAlertedUsers[$uid])) {
                    $name = isset($staffMap[$uid]) ? $staffMap[$uid]['displayName'] : ($data['username'] ?: '不明');
                    $alerts[] = [
                        'type'      => 'cancel_burst',
                        'severity'  => 'danger',
                        'message'   => $name . ' が1時間以内にキャンセル3件以上',
                        'staffName' => $name,
                        'datetime'  => date('Y-m-d H:i', $times[$i]),
                    ];
                    $burstAlertedUsers[$uid] = true;
                }
                break;
            }
        }
    }
}

json_response([
    'staffPerformance'      => $staffPerformance,
    'cancelDetails'         => $cancelDetails,
    'registerDiscrepancies' => $registerDiscrepancies,
    'lateNightOps'          => $lateNightOps,
    'alerts'                => $alerts,
    'period'                => ['from' => $from, 'to' => $to],
]);
