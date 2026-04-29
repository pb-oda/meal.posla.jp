<?php
/**
 * シフト集計 API（L-3）
 *
 * GET ?store_id=xxx&period=weekly&date=2026-04-07    → 週次サマリー
 * GET ?store_id=xxx&period=monthly&date=2026-04      → 月次サマリー
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';

start_auth_session();
handle_preflight();
$method = require_method(['GET']);
$user   = require_auth();
require_role('manager');

$pdo      = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

$period = $_GET['period'] ?? 'weekly';
$date   = $_GET['date'] ?? '';

// 期間算出
if ($period === 'weekly') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_error('INVALID_DATE', 'date を YYYY-MM-DD 形式で指定してください', 400);
    }
    $startDate = $date;
    $dt = new DateTime($date);
    $dt->modify('+6 days');
    $endDate = $dt->format('Y-m-d');
    $periodLabel = $startDate . ' ~ ' . $endDate;
} elseif ($period === 'monthly') {
    if (!preg_match('/^\d{4}-\d{2}$/', $date)) {
        json_error('INVALID_DATE', 'date を YYYY-MM 形式で指定してください', 400);
    }
    $startDate = $date . '-01';
    $dt = new DateTime($startDate);
    $endDate = $dt->format('Y-m-t');
    $periodLabel = $date;
} else {
    json_error('INVALID_PERIOD', 'period は weekly / monthly のいずれかです', 400);
}

// 設定取得（残業閾値 + デフォルト時給 + 休憩）
$stmtSettings = $pdo->prepare(
    'SELECT overtime_threshold_minutes, default_hourly_rate, default_break_minutes,
            target_labor_cost_ratio
     FROM shift_settings
     WHERE store_id = ? AND tenant_id = ?'
);
$stmtSettings->execute([$storeId, $tenantId]);
$settings = $stmtSettings->fetch();
$overtimeThreshold = $settings ? (int)$settings['overtime_threshold_minutes'] : 480;
$defaultHourlyRate = ($settings && $settings['default_hourly_rate'] !== null) ? (int)$settings['default_hourly_rate'] : null;
$defaultBreakMinutes = $settings ? (int)$settings['default_break_minutes'] : 60;
$targetLaborCostRatio = ($settings && isset($settings['target_labor_cost_ratio'])) ? (float)$settings['target_labor_cost_ratio'] : 30.00;

// L-3 Phase 2: スタッフ個別時給マップ取得
$stmtRates = $pdo->prepare(
    'SELECT us.user_id, us.hourly_rate
     FROM user_stores us
     WHERE us.store_id = ?'
);
$stmtRates->execute([$storeId]);
$hourlyRateMap = []; // { user_id: int|null }
foreach ($stmtRates->fetchAll() as $rr) {
    $hourlyRateMap[$rr['user_id']] = $rr['hourly_rate'] !== null ? (int)$rr['hourly_rate'] : null;
}

// ── スタッフ別集計 ──
$stmt = $pdo->prepare(
    'SELECT a.user_id,
            u.display_name,
            u.username,
            COUNT(*) AS attendance_count,
            SUM(CASE WHEN a.status = \'late\' THEN 1 ELSE 0 END) AS late_count,
            SUM(
                TIMESTAMPDIFF(MINUTE,
                    a.clock_in,
                    COALESCE(a.clock_out, NOW())
                ) - a.break_minutes
            ) AS total_minutes
     FROM attendance_logs a
     JOIN users u ON u.id = a.user_id
     WHERE a.tenant_id = ? AND a.store_id = ?
       AND DATE(a.clock_in) BETWEEN ? AND ?
       AND a.status IN (\'completed\', \'working\')
     GROUP BY a.user_id, u.display_name, u.username
     ORDER BY u.display_name'
);
$stmt->execute([$tenantId, $storeId, $startDate, $endDate]);
$staffRows = $stmt->fetchAll();

// L-3 Phase 2: 個別勤怠レコード取得（深夜時間・休憩チェック・連続勤務チェック用）
$stmtDetail = $pdo->prepare(
    'SELECT a.user_id, DATE(a.clock_in) AS work_date,
            a.clock_in, a.clock_out, a.break_minutes,
            TIMESTAMPDIFF(MINUTE, a.clock_in, COALESCE(a.clock_out, NOW())) - a.break_minutes AS worked_minutes
     FROM attendance_logs a
     WHERE a.tenant_id = ? AND a.store_id = ?
       AND DATE(a.clock_in) BETWEEN ? AND ?
       AND a.status IN (\'completed\', \'working\')
     ORDER BY a.user_id, a.clock_in'
);
$stmtDetail->execute([$tenantId, $storeId, $startDate, $endDate]);
$detailRows = $stmtDetail->fetchAll();

// 深夜時間計算ヘルパー（22:00〜05:00の重複分数を算出）
function calcNightMinutes($clockIn, $clockOut) {
    if (!$clockIn || !$clockOut) return 0;
    $inTs  = strtotime($clockIn);
    $outTs = strtotime($clockOut);
    if ($outTs <= $inTs) return 0;

    $nightMins = 0;
    // 日ごとにループ（最大2日分: 当日22時〜翌5時）
    $dayStart = strtotime(date('Y-m-d', $inTs));
    $dayEnd   = strtotime(date('Y-m-d', $outTs));

    for ($day = $dayStart; $day <= $dayEnd; $day += 86400) {
        // 当日の深夜帯1: 00:00〜05:00
        $n1Start = $day;
        $n1End   = $day + 5 * 3600;
        // 当日の深夜帯2: 22:00〜24:00
        $n2Start = $day + 22 * 3600;
        $n2End   = $day + 24 * 3600;

        // 勤務時間との重複を計算
        foreach ([[$n1Start, $n1End], [$n2Start, $n2End]] as $band) {
            $overlapStart = max($inTs, $band[0]);
            $overlapEnd   = min($outTs, $band[1]);
            if ($overlapEnd > $overlapStart) {
                $nightMins += ($overlapEnd - $overlapStart) / 60;
            }
        }
    }
    return (int)$nightMins;
}

// ユーザー別の深夜分数・日別勤務日リストを集計
$userNightMinutes = [];  // { user_id: int }
$userWorkDates = [];     // { user_id: [date1, date2, ...] }
$userDailyRecords = [];  // { user_id: [{work_date, worked_minutes, break_minutes}, ...] }
foreach ($detailRows as $d) {
    $uid = $d['user_id'];
    if (!isset($userNightMinutes[$uid])) {
        $userNightMinutes[$uid] = 0;
        $userWorkDates[$uid] = [];
        $userDailyRecords[$uid] = [];
    }
    $userNightMinutes[$uid] += calcNightMinutes($d['clock_in'], $d['clock_out']);
    $userWorkDates[$uid][] = $d['work_date'];
    $userDailyRecords[$uid][] = [
        'work_date'      => $d['work_date'],
        'worked_minutes' => max(0, (int)$d['worked_minutes']),
        'break_minutes'  => (int)$d['break_minutes'],
    ];
}

$staffSummary = [];
$totalMinutes = 0;
$laborCostTotal = 0;
$nightPremiumTotal = 0;
$laborWarnings = [];

foreach ($staffRows as $r) {
    $uid  = $r['user_id'];
    $mins = max(0, (int)$r['total_minutes']);
    $hours = round($mins / 60, 1);

    // 期間内の日数からovertime計算
    $normalMaxMinutes = $overtimeThreshold * (int)$r['attendance_count'];
    $overtimeMinutes  = max(0, $mins - $normalMaxMinutes);

    // L-3 Phase 2: 時給決定（個別 > 店舗デフォルト > null）
    $rate = isset($hourlyRateMap[$uid]) && $hourlyRateMap[$uid] !== null
            ? $hourlyRateMap[$uid]
            : $defaultHourlyRate;

    // 深夜時間
    $nightMins = isset($userNightMinutes[$uid]) ? $userNightMinutes[$uid] : 0;
    $nightHours = round($nightMins / 60, 1);

    // 人件費計算
    $laborCost = null;
    $nightPremium = 0;
    if ($rate !== null) {
        $normalMins = $mins - $nightMins;
        $laborCost = (int)round(($normalMins / 60) * $rate + ($nightMins / 60) * $rate * 1.25);
        $nightPremium = (int)round(($nightMins / 60) * $rate * 0.25);
        $laborCostTotal += $laborCost;
        $nightPremiumTotal += $nightPremium;
    }

    $staffSummary[] = [
        'user_id'          => $uid,
        'display_name'     => $r['display_name'],
        'username'         => $r['username'],
        'total_hours'      => $hours,
        'total_minutes'    => $mins,
        'days_worked'      => (int)$r['attendance_count'],
        'overtime_hours'   => round($overtimeMinutes / 60, 1),
        'attendance_count' => (int)$r['attendance_count'],
        'late_count'       => (int)$r['late_count'],
        'night_hours'      => $nightHours,
        'hourly_rate'      => $rate,
        'labor_cost'       => $laborCost,
        'night_premium'    => $nightPremium,
    ];

    $totalMinutes += $mins;

    // ── 労基法チェック ──
    $displayName = $r['display_name'] ?: $r['username'];

    // 1. 週40時間超過（weeklyモードのみ）
    if ($period === 'weekly' && $mins > 40 * 60) {
        $laborWarnings[] = [
            'type'         => 'weekly_overtime',
            'level'        => 'error',
            'user_id'      => $uid,
            'display_name' => $displayName,
            'date'         => null,
            'message'      => $displayName . 'の週労働時間が40時間を超えています（' . $hours . 'h）',
        ];
    }

    // 2-3. 休憩不足チェック（個別レコード）
    if (isset($userDailyRecords[$uid])) {
        foreach ($userDailyRecords[$uid] as $rec) {
            if ($rec['worked_minutes'] > 480 && $rec['break_minutes'] < 60) {
                $laborWarnings[] = [
                    'type'         => 'insufficient_break',
                    'level'        => 'error',
                    'user_id'      => $uid,
                    'display_name' => $displayName,
                    'date'         => $rec['work_date'],
                    'message'      => $displayName . ' ' . $rec['work_date'] . ': ' . round($rec['worked_minutes'] / 60, 1) . 'h勤務で休憩' . $rec['break_minutes'] . '分（8h超は60分以上必要）',
                ];
            } elseif ($rec['worked_minutes'] > 360 && $rec['break_minutes'] < 45) {
                $laborWarnings[] = [
                    'type'         => 'insufficient_break',
                    'level'        => 'warning',
                    'user_id'      => $uid,
                    'display_name' => $displayName,
                    'date'         => $rec['work_date'],
                    'message'      => $displayName . ' ' . $rec['work_date'] . ': ' . round($rec['worked_minutes'] / 60, 1) . 'h勤務で休憩' . $rec['break_minutes'] . '分（6h超は45分以上必要）',
                ];
            }

            // 6. 1日8時間超
            if ($rec['worked_minutes'] > 480) {
                $laborWarnings[] = [
                    'type'         => 'daily_overtime',
                    'level'        => 'warning',
                    'user_id'      => $uid,
                    'display_name' => $displayName,
                    'date'         => $rec['work_date'],
                    'message'      => $displayName . ' ' . $rec['work_date'] . ': 1日の労働時間が8時間を超えています（' . round($rec['worked_minutes'] / 60, 1) . 'h）',
                ];
            }
        }
    }

    // 4. 連続勤務チェック（6日以上）
    if (isset($userWorkDates[$uid]) && count($userWorkDates[$uid]) >= 6) {
        $dates = array_unique($userWorkDates[$uid]);
        sort($dates);
        $consecutive = 1;
        $maxConsecutive = 1;
        for ($i = 1; $i < count($dates); $i++) {
            $prev = new DateTime($dates[$i - 1]);
            $curr = new DateTime($dates[$i]);
            $diff = $prev->diff($curr)->days;
            if ($diff === 1) {
                $consecutive++;
                if ($consecutive > $maxConsecutive) $maxConsecutive = $consecutive;
            } else {
                $consecutive = 1;
            }
        }
        if ($maxConsecutive >= 6) {
            $laborWarnings[] = [
                'type'         => 'consecutive_days',
                'level'        => 'error',
                'user_id'      => $uid,
                'display_name' => $displayName,
                'date'         => null,
                'message'      => $displayName . 'が' . $maxConsecutive . '日連続勤務です（6日以上は法定休日侵害の可能性）',
            ];
        }
    }

    // 5. 深夜勤務（info）
    if ($nightMins > 0) {
        $laborWarnings[] = [
            'type'         => 'night_work',
            'level'        => 'info',
            'user_id'      => $uid,
            'display_name' => $displayName,
            'date'         => null,
            'message'      => $displayName . 'に深夜勤務があります（' . $nightHours . 'h）。25%割増が適用されます',
        ];
    }
}

// ── 日別集計 ──
$stmtDaily = $pdo->prepare(
    'SELECT DATE(a.clock_in) AS work_date,
            COUNT(DISTINCT a.user_id) AS staff_count,
            SUM(
                TIMESTAMPDIFF(MINUTE,
                    a.clock_in,
                    COALESCE(a.clock_out, NOW())
                ) - a.break_minutes
            ) AS total_minutes
     FROM attendance_logs a
     WHERE a.tenant_id = ? AND a.store_id = ?
       AND DATE(a.clock_in) BETWEEN ? AND ?
       AND a.status IN (\'completed\', \'working\')
     GROUP BY DATE(a.clock_in)
     ORDER BY work_date'
);
$stmtDaily->execute([$tenantId, $storeId, $startDate, $endDate]);
$dailyRows = $stmtDaily->fetchAll();

// L-3 Phase 2: 日別の人件費計算用に個別レコードを日付ごとにグループ化
$dailyLaborCosts = []; // { date: cost }
foreach ($detailRows as $d) {
    $uid = $d['user_id'];
    $wDate = $d['work_date'];
    $rate = isset($hourlyRateMap[$uid]) && $hourlyRateMap[$uid] !== null
            ? $hourlyRateMap[$uid]
            : $defaultHourlyRate;
    if ($rate !== null) {
        $wMins = max(0, (int)$d['worked_minutes']);
        $nMins = calcNightMinutes($d['clock_in'], $d['clock_out']);
        $nrMins = $wMins - $nMins;
        $cost = (int)round(($nrMins / 60) * $rate + ($nMins / 60) * $rate * 1.25);
        if (!isset($dailyLaborCosts[$wDate])) $dailyLaborCosts[$wDate] = 0;
        $dailyLaborCosts[$wDate] += $cost;
    }
}

$dailySummary = [];
foreach ($dailyRows as $d) {
    $mins = max(0, (int)$d['total_minutes']);
    $wDate = $d['work_date'];
    $dailySummary[] = [
        'date'        => $wDate,
        'staff_count' => (int)$d['staff_count'],
        'total_hours' => round($mins / 60, 1),
        'labor_cost'  => isset($dailyLaborCosts[$wDate]) ? $dailyLaborCosts[$wDate] : null,
    ];
}

$stmtRevenue = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0) AS total_revenue
     FROM orders o
     JOIN stores s ON s.id = o.store_id AND s.tenant_id = ?
     WHERE o.store_id = ? AND o.status = ?
       AND DATE(o.created_at) BETWEEN ? AND ?'
);
$stmtRevenue->execute([$tenantId, $storeId, 'paid', $startDate, $endDate]);
$revenueRow = $stmtRevenue->fetch();
$periodRevenue = $revenueRow ? (int)$revenueRow['total_revenue'] : 0;
$laborCostRatio = ($periodRevenue > 0 && $laborCostTotal > 0) ? round(($laborCostTotal / $periodRevenue) * 100, 1) : null;
if ($laborCostRatio !== null && $laborCostRatio > $targetLaborCostRatio) {
    $laborWarnings[] = [
        'type'         => 'actual_labor_cost_ratio',
        'level'        => $laborCostRatio >= $targetLaborCostRatio + 10 ? 'error' : 'warning',
        'user_id'      => null,
        'display_name' => null,
        'date'         => null,
        'message'      => '実績人件費率が目標を超えています（実績' . $laborCostRatio . '% / 目標' . $targetLaborCostRatio . '%）',
    ];
}

// ── シフト充足率（割当 vs 実出勤）──
$stmtAssigned = $pdo->prepare(
    'SELECT shift_date, COUNT(*) AS assigned_count
     FROM shift_assignments
     WHERE tenant_id = ? AND store_id = ?
       AND shift_date BETWEEN ? AND ?
     GROUP BY shift_date'
);
$stmtAssigned->execute([$tenantId, $storeId, $startDate, $endDate]);
$assignedMap = [];
foreach ($stmtAssigned->fetchAll() as $a) {
    $assignedMap[$a['shift_date']] = (int)$a['assigned_count'];
}

json_response([
    'period'              => $periodLabel,
    'total_hours'         => round($totalMinutes / 60, 1),
    'staff_summary'       => $staffSummary,
    'daily_summary'       => $dailySummary,
    'assigned_map'        => $assignedMap,
    'labor_cost_total'    => $laborCostTotal,
    'night_premium_total' => $nightPremiumTotal,
    'period_revenue'      => $periodRevenue,
    'labor_cost_ratio'    => $laborCostRatio,
    'target_labor_cost_ratio' => $targetLaborCostRatio,
    'labor_warnings'      => $laborWarnings,
]);
