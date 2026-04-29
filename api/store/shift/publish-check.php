<?php
/**
 * シフト公開前チェック API
 *
 * GET ?store_id=xxx&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 *
 * 週間シフトを公開する前に、人数不足・役割不足・休憩未設定・希望未提出・
 * 予約/売上予測ベースの必要人数・勤怠差分・他店舗ヘルプ候補を集約して返す。
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';

function spc_time_to_minutes($time)
{
    $time = (string)$time;
    if (strlen($time) < 5) {
        return 0;
    }
    return ((int)substr($time, 0, 2)) * 60 + (int)substr($time, 3, 2);
}

function spc_minutes_between($startTime, $endTime)
{
    $start = spc_time_to_minutes($startTime);
    $end = spc_time_to_minutes($endTime);
    if ($end < $start) {
        $end += 1440;
    }
    return max(0, $end - $start);
}

function spc_overlap_minutes($aStartTime, $aEndTime, $bStartMin, $bEndMin)
{
    $aStart = spc_time_to_minutes($aStartTime);
    $aEnd = spc_time_to_minutes($aEndTime);
    if ($aEnd < $aStart) {
        $aEnd += 1440;
    }
    return max(0, min($aEnd, $bEndMin) - max($aStart, $bStartMin));
}

function spc_slot_label($startMin, $endMin)
{
    $sh = floor($startMin / 60) % 24;
    $eh = floor($endMin / 60) % 24;
    return sprintf('%02d:00-%02d:00', $sh, $eh);
}

function spc_date_list($startDate, $endDate)
{
    $dates = [];
    $cur = new DateTime($startDate);
    $end = new DateTime($endDate);
    while ($cur <= $end) {
        $dates[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    return $dates;
}

function spc_same_weekday_counts($startDate, $endDate)
{
    $counts = array_fill(0, 7, 0);
    $cur = new DateTime($startDate);
    $end = new DateTime($endDate);
    while ($cur <= $end) {
        $counts[(int)$cur->format('w')]++;
        $cur->modify('+1 day');
    }
    return $counts;
}

function spc_display_name($row)
{
    return ($row['display_name'] ?? '') !== '' ? $row['display_name'] : ($row['username'] ?? '');
}

function spc_role_label($role)
{
    if ($role === 'hall') {
        return 'ホール';
    }
    if ($role === 'kitchen') {
        return 'キッチン';
    }
    return '指定なし';
}

start_auth_session();
handle_preflight();
$method = require_method(['GET']);
$user = require_auth();
require_role('manager');

$pdo = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    json_error('INVALID_DATE', 'start_date, end_date を YYYY-MM-DD 形式で指定してください', 400);
}
if ($endDate < $startDate) {
    json_error('INVALID_DATE_RANGE', 'end_date は start_date 以降を指定してください', 400);
}
$periodDays = (new DateTime($startDate))->diff(new DateTime($endDate))->days + 1;
if ($periodDays > 31) {
    json_error('DATE_RANGE_TOO_LONG', '公開前チェックは最大31日分までです', 400);
}

$dates = spc_date_list($startDate, $endDate);
$today = date('Y-m-d');

// ── 基本データ ──
$stmtUsers = $pdo->prepare(
    'SELECT u.id, u.display_name, u.username
     FROM users u
     JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
     WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role = ?
     ORDER BY u.display_name, u.username'
);
$stmtUsers->execute([$storeId, $tenantId, 'staff']);
$users = $stmtUsers->fetchAll();

$stmtSettings = $pdo->prepare(
    'SELECT default_break_minutes, early_clock_in_minutes, overtime_threshold_minutes,
            default_hourly_rate, target_labor_cost_ratio
     FROM shift_settings
     WHERE store_id = ? AND tenant_id = ?'
);
$stmtSettings->execute([$storeId, $tenantId]);
$settings = $stmtSettings->fetch();
$defaultBreakMinutes = $settings ? (int)$settings['default_break_minutes'] : 60;
$defaultHourlyRate = ($settings && $settings['default_hourly_rate'] !== null) ? (int)$settings['default_hourly_rate'] : null;
$targetLaborCostRatio = ($settings && isset($settings['target_labor_cost_ratio'])) ? (float)$settings['target_labor_cost_ratio'] : 30.00;
$lateGraceMinutes = 5;
$overtimeGraceMinutes = 15;

$stmtRates = $pdo->prepare(
    'SELECT user_id, hourly_rate
     FROM user_stores
     WHERE store_id = ?'
);
$stmtRates->execute([$storeId]);
$hourlyRates = [];
foreach ($stmtRates->fetchAll() as $rateRow) {
    if ($rateRow['hourly_rate'] !== null) {
        $hourlyRates[$rateRow['user_id']] = (int)$rateRow['hourly_rate'];
    }
}

$stmtPositions = $pdo->prepare(
    'SELECT code, label
     FROM shift_work_positions
     WHERE tenant_id = ? AND store_id = ? AND is_active = 1
     ORDER BY sort_order, label'
);
$stmtPositions->execute([$tenantId, $storeId]);
$positionLabels = ['hall' => 'ホール', 'kitchen' => 'キッチン'];
foreach ($stmtPositions->fetchAll() as $pos) {
    $positionLabels[$pos['code']] = $pos['label'];
}

$stmtAssignments = $pdo->prepare(
    'SELECT sa.id, sa.user_id, sa.shift_date, sa.start_time, sa.end_time,
            sa.break_minutes, sa.role_type, sa.status, sa.note,
            u.display_name, u.username
     FROM shift_assignments sa
     JOIN users u ON u.id = sa.user_id
     WHERE sa.tenant_id = ? AND sa.store_id = ?
       AND sa.shift_date BETWEEN ? AND ?
     ORDER BY sa.shift_date, sa.start_time, u.display_name'
);
$stmtAssignments->execute([$tenantId, $storeId, $startDate, $endDate]);
$assignments = $stmtAssignments->fetchAll();

$stmtAvail = $pdo->prepare(
    'SELECT user_id, target_date, availability, preferred_start, preferred_end, submitted_at
     FROM shift_availabilities
     WHERE tenant_id = ? AND store_id = ?
       AND target_date BETWEEN ? AND ?
     ORDER BY target_date, user_id'
);
$stmtAvail->execute([$tenantId, $storeId, $startDate, $endDate]);
$availabilities = $stmtAvail->fetchAll();

$stmtTemplates = $pdo->prepare(
    'SELECT id, name, day_of_week, start_time, end_time, required_staff, role_hint
     FROM shift_templates
     WHERE tenant_id = ? AND store_id = ? AND is_active = 1
     ORDER BY day_of_week, start_time'
);
$stmtTemplates->execute([$tenantId, $storeId]);
$templates = $stmtTemplates->fetchAll();

// ── 売上予測: 過去8週間の同曜日・同時間帯平均 ──
$lookbackStart = date('Y-m-d', strtotime($startDate . ' -56 days'));
$lookbackEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));
$weekdayDivisors = spc_same_weekday_counts($lookbackStart, $lookbackEnd);

$stmtSales = $pdo->prepare(
    'SELECT DAYOFWEEK(o.created_at) - 1 AS weekday,
            HOUR(o.created_at) AS hour_of_day,
            COUNT(*) AS order_count,
            SUM(o.total_amount) AS revenue
     FROM orders o
     JOIN stores s ON s.id = o.store_id AND s.tenant_id = ?
     WHERE o.store_id = ? AND o.status = ?
       AND DATE(o.created_at) BETWEEN ? AND ?
     GROUP BY weekday, hour_of_day'
);
$stmtSales->execute([$tenantId, $storeId, 'paid', $lookbackStart, $lookbackEnd]);
$salesMap = [];
foreach ($stmtSales->fetchAll() as $row) {
    $wd = (int)$row['weekday'];
    $hour = (int)$row['hour_of_day'];
    $divisor = max(1, $weekdayDivisors[$wd]);
    if (!isset($salesMap[$wd])) {
        $salesMap[$wd] = [];
    }
    $salesMap[$wd][$hour] = [
        'avg_orders' => round(((int)$row['order_count']) / $divisor, 1),
        'avg_revenue' => (int)round(((int)$row['revenue']) / $divisor),
    ];
}

// ── 予約: 対象期間の予約人数 ──
$stmtReservations = $pdo->prepare(
    'SELECT DATE(reserved_at) AS target_date,
            HOUR(reserved_at) AS hour_of_day,
            COUNT(*) AS reservation_count,
            SUM(party_size) AS party_size
     FROM reservations
     WHERE tenant_id = ? AND store_id = ?
       AND DATE(reserved_at) BETWEEN ? AND ?
       AND status IN (\'pending\', \'confirmed\', \'seated\')
     GROUP BY DATE(reserved_at), HOUR(reserved_at)'
);
$stmtReservations->execute([$tenantId, $storeId, $startDate, $endDate]);
$reservationMap = [];
foreach ($stmtReservations->fetchAll() as $row) {
    $d = $row['target_date'];
    $h = (int)$row['hour_of_day'];
    if (!isset($reservationMap[$d])) {
        $reservationMap[$d] = [];
    }
    $reservationMap[$d][$h] = [
        'reservation_count' => (int)$row['reservation_count'],
        'party_size' => (int)$row['party_size'],
    ];
}

// ── マップ化 ──
$assignmentsByDate = [];
$assignmentIds = [];
$minHour = 8;
$maxHour = 23;
foreach ($assignments as $a) {
    $assignmentIds[] = $a['id'];
    if (!isset($assignmentsByDate[$a['shift_date']])) {
        $assignmentsByDate[$a['shift_date']] = [];
    }
    $assignmentsByDate[$a['shift_date']][] = $a;
    $minHour = min($minHour, max(0, floor(spc_time_to_minutes($a['start_time']) / 60)));
    $maxHour = max($maxHour, min(24, (int)ceil(spc_time_to_minutes($a['end_time']) / 60)));
}

$templatesByDow = [];
foreach ($templates as $t) {
    $dow = (int)$t['day_of_week'];
    if (!isset($templatesByDow[$dow])) {
        $templatesByDow[$dow] = [];
    }
    $templatesByDow[$dow][] = $t;
    $minHour = min($minHour, max(0, floor(spc_time_to_minutes($t['start_time']) / 60)));
    $maxHour = max($maxHour, min(24, (int)ceil(spc_time_to_minutes($t['end_time']) / 60)));
}

$availMap = [];
foreach ($availabilities as $av) {
    $availMap[$av['user_id'] . '_' . $av['target_date']] = $av;
}

// ── 勤怠差分 ──
$stmtAttendance = $pdo->prepare(
    'SELECT a.id, a.user_id, a.shift_assignment_id, a.clock_in, a.clock_out,
            a.break_minutes, a.status, u.display_name, u.username
     FROM attendance_logs a
     JOIN users u ON u.id = a.user_id
     WHERE a.tenant_id = ? AND a.store_id = ?
       AND DATE(a.clock_in) BETWEEN ? AND ?
     ORDER BY a.clock_in'
);
$stmtAttendance->execute([$tenantId, $storeId, $startDate, $endDate]);
$attendanceRows = $stmtAttendance->fetchAll();
$attendanceByShift = [];
$attendanceByUserDate = [];
foreach ($attendanceRows as $att) {
    if (!empty($att['shift_assignment_id'])) {
        $attendanceByShift[$att['shift_assignment_id']] = $att;
    }
    $d = substr($att['clock_in'], 0, 10);
    $key = $att['user_id'] . '_' . $d;
    if (!isset($attendanceByUserDate[$key])) {
        $attendanceByUserDate[$key] = [];
    }
    $attendanceByUserDate[$key][] = $att;
}

$attendanceDiffs = [];
foreach ($assignments as $a) {
    if ($a['shift_date'] > $today) {
        continue;
    }

    $att = isset($attendanceByShift[$a['id']]) ? $attendanceByShift[$a['id']] : null;
    if (!$att) {
        $key = $a['user_id'] . '_' . $a['shift_date'];
        $att = isset($attendanceByUserDate[$key][0]) ? $attendanceByUserDate[$key][0] : null;
    }

    $displayName = spc_display_name($a);
    $scheduledStartTs = strtotime($a['shift_date'] . ' ' . $a['start_time']);
    $scheduledEndTs = strtotime($a['shift_date'] . ' ' . $a['end_time']);
    if ($scheduledEndTs < $scheduledStartTs) {
        $scheduledEndTs += 86400;
    }
    $scheduledMinutes = max(0, (int)(($scheduledEndTs - $scheduledStartTs) / 60) - (int)$a['break_minutes']);

    if (!$att) {
        if ($a['shift_date'] < $today && $a['status'] !== 'draft') {
            $attendanceDiffs[] = [
                'type' => 'no_attendance',
                'level' => 'warning',
                'user_id' => $a['user_id'],
                'display_name' => $displayName,
                'shift_date' => $a['shift_date'],
                'scheduled_start' => substr($a['start_time'], 0, 5),
                'scheduled_end' => substr($a['end_time'], 0, 5),
                'clock_in' => null,
                'clock_out' => null,
                'scheduled_minutes' => $scheduledMinutes,
                'actual_minutes' => null,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'message' => $displayName . ' ' . $a['shift_date'] . ' は確定シフトに対する勤怠打刻がありません',
            ];
        }
        continue;
    }

    $clockInTs = strtotime($att['clock_in']);
    $clockOutTs = $att['clock_out'] ? strtotime($att['clock_out']) : time();
    $actualMinutes = max(0, (int)(($clockOutTs - $clockInTs) / 60) - (int)$att['break_minutes']);
    $lateMinutes = max(0, (int)floor(($clockInTs - $scheduledStartTs) / 60));
    $earlyLeaveMinutes = $att['clock_out'] ? max(0, (int)floor(($scheduledEndTs - $clockOutTs) / 60)) : 0;
    $overtimeMinutes = max(0, $actualMinutes - $scheduledMinutes);

    if ($lateMinutes > $lateGraceMinutes || $earlyLeaveMinutes > $lateGraceMinutes || $overtimeMinutes > $overtimeGraceMinutes) {
        $bits = [];
        if ($lateMinutes > $lateGraceMinutes) {
            $bits[] = '遅刻' . $lateMinutes . '分';
        }
        if ($earlyLeaveMinutes > $lateGraceMinutes) {
            $bits[] = '早退' . $earlyLeaveMinutes . '分';
        }
        if ($overtimeMinutes > $overtimeGraceMinutes) {
            $bits[] = '残業' . $overtimeMinutes . '分';
        }
        $attendanceDiffs[] = [
            'type' => 'attendance_diff',
            'level' => $lateMinutes > 15 ? 'warning' : 'info',
            'user_id' => $a['user_id'],
            'display_name' => $displayName,
            'shift_date' => $a['shift_date'],
            'scheduled_start' => substr($a['start_time'], 0, 5),
            'scheduled_end' => substr($a['end_time'], 0, 5),
            'clock_in' => $att['clock_in'],
            'clock_out' => $att['clock_out'],
            'scheduled_minutes' => $scheduledMinutes,
            'actual_minutes' => $actualMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'message' => $displayName . ' ' . $a['shift_date'] . ': ' . implode(' / ', $bits),
        ];
    }
}

// ── 希望未提出 ──
$missingAvailabilities = [];
foreach ($users as $u) {
    $submittedDays = 0;
    $missingDates = [];
    foreach ($dates as $date) {
        if (isset($availMap[$u['id'] . '_' . $date])) {
            $submittedDays++;
        } else {
            $missingDates[] = $date;
        }
    }
    if ($submittedDays < count($dates)) {
        $name = spc_display_name($u);
        $missingAvailabilities[] = [
            'user_id' => $u['id'],
            'display_name' => $name,
            'submitted_days' => $submittedDays,
            'missing_days' => count($missingDates),
            'missing_dates' => $missingDates,
            'reminder_text' => $name . "さん、" . $startDate . "〜" . $endDate . " のシフト希望が未提出です。勤務できる日と時間帯をPOSLAから提出してください。",
        ];
    }
}

// ── 他店舗ヘルプ候補用の事前取得 ──
$stmtOtherStaff = $pdo->prepare(
    'SELECT u.id, u.display_name, u.username, us.store_id, s.name AS store_name
     FROM users u
     JOIN user_stores us ON us.user_id = u.id
     JOIN stores s ON s.id = us.store_id
     WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role = ?
       AND us.store_id != ? AND s.is_active = 1
     ORDER BY s.name, u.display_name, u.username'
);
$stmtOtherStaff->execute([$tenantId, 'staff', $storeId]);
$otherStaff = $stmtOtherStaff->fetchAll();
$otherStaffById = [];
$otherStaffIds = [];
foreach ($otherStaff as $os) {
    $otherStaffById[$os['id']] = $os;
    $otherStaffIds[] = $os['id'];
}

$otherAvailMap = [];
if (count($otherStaff) > 0) {
    $stmtOtherAvail = $pdo->prepare(
        'SELECT user_id, store_id, target_date, availability, preferred_start, preferred_end
         FROM shift_availabilities
         WHERE tenant_id = ?
           AND target_date BETWEEN ? AND ?'
    );
    $stmtOtherAvail->execute([$tenantId, $startDate, $endDate]);
    foreach ($stmtOtherAvail->fetchAll() as $av) {
        $otherAvailMap[$av['user_id'] . '_' . $av['store_id'] . '_' . $av['target_date']] = $av;
    }
}

$otherAssignmentsByUserDate = [];
if (count($otherStaffIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($otherStaffIds), '?'));
    $stmtOtherAssign = $pdo->prepare(
        "SELECT user_id, shift_date, start_time, end_time
         FROM shift_assignments
         WHERE tenant_id = ?
           AND user_id IN ({$placeholders})
           AND shift_date BETWEEN ? AND ?"
    );
    $stmtOtherAssign->execute(array_merge([$tenantId], $otherStaffIds, [$startDate, $endDate]));
    foreach ($stmtOtherAssign->fetchAll() as $oa) {
        $key = $oa['user_id'] . '_' . $oa['shift_date'];
        if (!isset($otherAssignmentsByUserDate[$key])) {
            $otherAssignmentsByUserDate[$key] = [];
        }
        $otherAssignmentsByUserDate[$key][] = $oa;
    }
}

$roleHistory = [];
if (count($otherStaffIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($otherStaffIds), '?'));
    $historyStart = date('Y-m-d', strtotime($startDate . ' -90 days'));
    $stmtRoleHistory = $pdo->prepare(
        "SELECT user_id, role_type, COUNT(*) AS role_count
         FROM shift_assignments
         WHERE tenant_id = ?
           AND user_id IN ({$placeholders})
           AND role_type IS NOT NULL AND role_type != ''
           AND shift_date BETWEEN ? AND ?
         GROUP BY user_id, role_type"
    );
    $stmtRoleHistory->execute(array_merge([$tenantId], $otherStaffIds, [$historyStart, $endDate]));
    foreach ($stmtRoleHistory->fetchAll() as $rh) {
        if (!isset($roleHistory[$rh['user_id']])) {
            $roleHistory[$rh['user_id']] = [];
        }
        $roleHistory[$rh['user_id']][$rh['role_type']] = (int)$rh['role_count'];
    }
}

// ── カバレッジ集計 ──
$coverage = [];
$warnings = [];
$helpCandidates = [];
$draftCount = 0;
$publishedCount = 0;
$confirmedCount = 0;
$scheduledLaborCost = 0;
$scheduledLaborCostKnown = false;
$scheduledMinutesByUser = [];
$scheduledMinutesByUserDate = [];
$scheduledDatesByUser = [];
$laborRisks = [];
$unconfirmedAssignments = [];

foreach ($assignments as $a) {
    if ($a['status'] === 'draft') {
        $draftCount++;
    } elseif ($a['status'] === 'published') {
        $publishedCount++;
        if ($a['shift_date'] >= $today) {
            $unconfirmedAssignments[] = [
                'assignment_id' => $a['id'],
                'user_id' => $a['user_id'],
                'display_name' => spc_display_name($a),
                'shift_date' => $a['shift_date'],
                'start_time' => substr($a['start_time'], 0, 5),
                'end_time' => substr($a['end_time'], 0, 5),
                'role_type' => $a['role_type'],
            ];
        }
    } elseif ($a['status'] === 'confirmed') {
        $confirmedCount++;
    }

    $durationMinutes = max(0, spc_minutes_between($a['start_time'], $a['end_time']) - (int)$a['break_minutes']);
    if (!isset($scheduledMinutesByUser[$a['user_id']])) {
        $scheduledMinutesByUser[$a['user_id']] = 0;
        $scheduledDatesByUser[$a['user_id']] = [];
    }
    $scheduledMinutesByUser[$a['user_id']] += $durationMinutes;
    $scheduledDatesByUser[$a['user_id']][$a['shift_date']] = true;
    $udKey = $a['user_id'] . '_' . $a['shift_date'];
    if (!isset($scheduledMinutesByUserDate[$udKey])) {
        $scheduledMinutesByUserDate[$udKey] = [
            'display_name' => spc_display_name($a),
            'minutes' => 0,
            'date' => $a['shift_date'],
        ];
    }
    $scheduledMinutesByUserDate[$udKey]['minutes'] += $durationMinutes;

    $rate = isset($hourlyRates[$a['user_id']]) ? $hourlyRates[$a['user_id']] : $defaultHourlyRate;
    if ($rate !== null) {
        $scheduledLaborCostKnown = true;
        $scheduledLaborCost += (int)round(($durationMinutes / 60) * $rate);
    }
}

foreach ($scheduledMinutesByUser as $uid => $mins) {
    if ($periodDays <= 7 && $mins > 40 * 60) {
        $name = '';
        foreach ($assignments as $a) {
            if ($a['user_id'] === $uid) {
                $name = spc_display_name($a);
                break;
            }
        }
        $laborRisks[] = [
            'type' => 'scheduled_weekly_overtime',
            'level' => 'warning',
            'user_id' => $uid,
            'display_name' => $name,
            'message' => $name . 'の予定労働時間が週40時間を超えています（' . round($mins / 60, 1) . 'h）',
        ];
    }
    $dates = array_keys($scheduledDatesByUser[$uid]);
    sort($dates);
    $consecutive = 1;
    $maxConsecutive = count($dates) > 0 ? 1 : 0;
    for ($di = 1; $di < count($dates); $di++) {
        $prev = new DateTime($dates[$di - 1]);
        $cur = new DateTime($dates[$di]);
        if ($prev->diff($cur)->days === 1) {
            $consecutive++;
            if ($consecutive > $maxConsecutive) $maxConsecutive = $consecutive;
        } else {
            $consecutive = 1;
        }
    }
    if ($maxConsecutive >= 6) {
        $name = '';
        foreach ($assignments as $a) {
            if ($a['user_id'] === $uid) {
                $name = spc_display_name($a);
                break;
            }
        }
        $laborRisks[] = [
            'type' => 'scheduled_consecutive_days',
            'level' => 'warning',
            'user_id' => $uid,
            'display_name' => $name,
            'message' => $name . 'が' . $maxConsecutive . '日連続勤務予定です',
        ];
    }
}

foreach ($scheduledMinutesByUserDate as $riskRow) {
    if ($riskRow['minutes'] > 480) {
        $laborRisks[] = [
            'type' => 'scheduled_daily_overtime',
            'level' => 'info',
            'date' => $riskRow['date'],
            'display_name' => $riskRow['display_name'],
            'message' => $riskRow['display_name'] . ' ' . $riskRow['date'] . ': 予定労働時間が8時間を超えています（' . round($riskRow['minutes'] / 60, 1) . 'h）',
        ];
    }
}

$forecastRevenueTotal = 0;

foreach ($dates as $date) {
    $dow = (int)date('w', strtotime($date));
    for ($hour = $minHour; $hour < $maxHour; $hour++) {
        $slotStart = $hour * 60;
        $slotEnd = ($hour + 1) * 60;
        $slotAssignments = [];
        $roleCounts = [];
        $unsetRoleCount = 0;

        $dayAssignments = isset($assignmentsByDate[$date]) ? $assignmentsByDate[$date] : [];
        foreach ($dayAssignments as $a) {
            if (spc_overlap_minutes($a['start_time'], $a['end_time'], $slotStart, $slotEnd) <= 0) {
                continue;
            }
            $slotAssignments[] = $a;
            if ($a['role_type']) {
                if (!isset($roleCounts[$a['role_type']])) {
                    $roleCounts[$a['role_type']] = 0;
                }
                $roleCounts[$a['role_type']]++;
            } else {
                $unsetRoleCount++;
            }
        }

        $templateRequired = 0;
        $requiredRoles = [];
        $slotTemplates = isset($templatesByDow[$dow]) ? $templatesByDow[$dow] : [];
        foreach ($slotTemplates as $tpl) {
            if (spc_overlap_minutes($tpl['start_time'], $tpl['end_time'], $slotStart, $slotEnd) <= 0) {
                continue;
            }
            $req = (int)$tpl['required_staff'];
            $templateRequired += $req;
            if ($tpl['role_hint']) {
                if (!isset($requiredRoles[$tpl['role_hint']])) {
                    $requiredRoles[$tpl['role_hint']] = 0;
                }
                $requiredRoles[$tpl['role_hint']] += $req;
            }
        }

        $sales = isset($salesMap[$dow][$hour]) ? $salesMap[$dow][$hour] : ['avg_orders' => 0, 'avg_revenue' => 0];
        $reservation = isset($reservationMap[$date][$hour]) ? $reservationMap[$date][$hour] : ['reservation_count' => 0, 'party_size' => 0];
        $forecastRevenueTotal += (int)$sales['avg_revenue'];

        $forecastRequired = 0;
        if ($sales['avg_orders'] > 0 || $reservation['party_size'] > 0 || $sales['avg_revenue'] > 0) {
            $forecastRequired = max(
                1,
                (int)ceil($sales['avg_orders'] / 8),
                (int)ceil($reservation['party_size'] / 12),
                (int)ceil($sales['avg_revenue'] / 35000)
            );
        }

        $requiredTotal = max($templateRequired, $forecastRequired);
        $scheduledCount = count($slotAssignments);
        if ($requiredTotal === 0 && $scheduledCount === 0 && $sales['avg_orders'] <= 0 && $reservation['party_size'] <= 0) {
            continue;
        }

        $shortageCount = max(0, $requiredTotal - $scheduledCount);
        $slotWarnings = [];
        if ($shortageCount > 0) {
            $level = $shortageCount >= 2 ? 'error' : 'warning';
            $message = $date . ' ' . spc_slot_label($slotStart, $slotEnd) . ': 必要' . $requiredTotal . '人に対して予定' . $scheduledCount . '人（不足' . $shortageCount . '人）';
            $slotWarnings[] = $message;
            $warnings[] = [
                'type' => 'staff_shortage',
                'level' => $level,
                'date' => $date,
                'time' => spc_slot_label($slotStart, $slotEnd),
                'message' => $message,
            ];
        }
        foreach ($requiredRoles as $roleCode => $requiredRoleCount) {
            $actualRoleCount = isset($roleCounts[$roleCode]) ? $roleCounts[$roleCode] : 0;
            if ($requiredRoleCount > $actualRoleCount) {
                $roleLabel = isset($positionLabels[$roleCode]) ? $positionLabels[$roleCode] : $roleCode;
                $message = $date . ' ' . spc_slot_label($slotStart, $slotEnd) . ': ' . $roleLabel . 'が不足（必要' . $requiredRoleCount . ' / 予定' . $actualRoleCount . '）';
                $slotWarnings[] = $message;
                $warnings[] = [
                    'type' => 'role_shortage',
                    'level' => 'warning',
                    'date' => $date,
                    'time' => spc_slot_label($slotStart, $slotEnd),
                    'message' => $message,
                ];
            }
        }

        $coverageRow = [
            'date' => $date,
            'time' => spc_slot_label($slotStart, $slotEnd),
            'hour' => $hour,
            'scheduled_count' => $scheduledCount,
            'required_count' => $requiredTotal,
            'shortage_count' => $shortageCount,
            'hall_count' => isset($roleCounts['hall']) ? $roleCounts['hall'] : 0,
            'kitchen_count' => isset($roleCounts['kitchen']) ? $roleCounts['kitchen'] : 0,
            'unset_role_count' => $unsetRoleCount,
            'required_hall' => isset($requiredRoles['hall']) ? $requiredRoles['hall'] : 0,
            'required_kitchen' => isset($requiredRoles['kitchen']) ? $requiredRoles['kitchen'] : 0,
            'role_counts' => $roleCounts,
            'required_roles' => $requiredRoles,
            'forecast_required' => $forecastRequired,
            'forecast_orders' => $sales['avg_orders'],
            'forecast_revenue' => $sales['avg_revenue'],
            'reservation_count' => $reservation['reservation_count'],
            'reservation_party_size' => $reservation['party_size'],
            'warnings' => $slotWarnings,
        ];
        $coverage[] = $coverageRow;

        if ($shortageCount > 0 && count($helpCandidates) < 12) {
            $roleForCandidate = null;
            foreach ($requiredRoles as $roleCode => $requiredRoleCount) {
                $actualRoleCount = isset($roleCounts[$roleCode]) ? $roleCounts[$roleCode] : 0;
                if ($requiredRoleCount > $actualRoleCount) {
                    $roleForCandidate = $roleCode;
                    break;
                }
            }

            $candidates = [];
            foreach ($otherStaff as $os) {
                $avKey = $os['id'] . '_' . $os['store_id'] . '_' . $date;
                $av = isset($otherAvailMap[$avKey]) ? $otherAvailMap[$avKey] : null;
                if ($av && $av['availability'] === 'unavailable') {
                    continue;
                }
                if ($av && $av['preferred_start'] && $av['preferred_end']) {
                    if (spc_overlap_minutes($av['preferred_start'], $av['preferred_end'], $slotStart, $slotEnd) <= 0) {
                        continue;
                    }
                }

                $conflict = false;
                $assignKey = $os['id'] . '_' . $date;
                if (isset($otherAssignmentsByUserDate[$assignKey])) {
                    foreach ($otherAssignmentsByUserDate[$assignKey] as $oa) {
                        if (spc_overlap_minutes($oa['start_time'], $oa['end_time'], $slotStart, $slotEnd) > 0) {
                            $conflict = true;
                            break;
                        }
                    }
                }
                if ($conflict) {
                    continue;
                }

                $roleKnown = isset($roleHistory[$os['id']]);
                $roleMatch = !$roleForCandidate || !$roleKnown || isset($roleHistory[$os['id']][$roleForCandidate]);
                $score = 0;
                if ($av && $av['availability'] === 'preferred') {
                    $score += 20;
                } elseif ($av && $av['availability'] === 'available') {
                    $score += 10;
                }
                if ($roleMatch) {
                    $score += 5;
                }
                $candidates[] = [
                    'user_id' => $os['id'],
                    'display_name' => spc_display_name($os),
                    'store_id' => $os['store_id'],
                    'store_name' => $os['store_name'],
                    'availability' => $av ? $av['availability'] : 'not_submitted',
                    'preferred_start' => $av ? $av['preferred_start'] : null,
                    'preferred_end' => $av ? $av['preferred_end'] : null,
                    'role_match' => $roleMatch,
                    'score' => $score,
                ];
            }

            usort($candidates, function ($a, $b) {
                if ($a['score'] === $b['score']) {
                    return strcmp($a['store_name'] . $a['display_name'], $b['store_name'] . $b['display_name']);
                }
                return $b['score'] - $a['score'];
            });
            $helpCandidates[] = [
                'date' => $date,
                'time' => spc_slot_label($slotStart, $slotEnd),
                'start_time' => sprintf('%02d:00', $hour),
                'end_time' => sprintf('%02d:00', ($hour + 1) % 24),
                'role_type' => $roleForCandidate,
                'shortage_count' => $shortageCount,
                'candidates' => array_slice($candidates, 0, 5),
            ];
        }
    }
}

// ── 休憩未設定/不足 ──
foreach ($assignments as $a) {
    $duration = spc_minutes_between($a['start_time'], $a['end_time']);
    $break = (int)$a['break_minutes'];
    $requiredBreak = 0;
    if ($duration > 480) {
        $requiredBreak = 60;
    } elseif ($duration > 360) {
        $requiredBreak = 45;
    }
    if ($requiredBreak > 0 && $break < $requiredBreak) {
        $warnings[] = [
            'type' => 'break_shortage',
            'level' => $break === 0 ? 'error' : 'warning',
            'date' => $a['shift_date'],
            'time' => substr($a['start_time'], 0, 5) . '-' . substr($a['end_time'], 0, 5),
            'message' => spc_display_name($a) . ' ' . $a['shift_date'] . ': ' . round($duration / 60, 1) . '時間勤務で休憩' . $break . '分（目安' . $requiredBreak . '分以上）',
        ];
    }
}

foreach ($missingAvailabilities as $m) {
    $warnings[] = [
        'type' => 'missing_availability',
        'level' => $m['submitted_days'] === 0 ? 'warning' : 'info',
        'date' => null,
        'time' => null,
        'message' => $m['display_name'] . 'の希望提出が不足しています（未提出' . $m['missing_days'] . '日）',
    ];
}

foreach ($attendanceDiffs as $diff) {
    $warnings[] = [
        'type' => $diff['type'],
        'level' => $diff['level'],
        'date' => $diff['shift_date'],
        'time' => $diff['scheduled_start'] . '-' . $diff['scheduled_end'],
        'message' => $diff['message'],
    ];
}

$laborCostRatio = null;
if ($scheduledLaborCostKnown && $forecastRevenueTotal > 0) {
    $laborCostRatio = round(($scheduledLaborCost / $forecastRevenueTotal) * 100, 1);
    if ($targetLaborCostRatio > 0 && $laborCostRatio > $targetLaborCostRatio) {
        $level = $laborCostRatio >= $targetLaborCostRatio + 10 ? 'error' : 'warning';
        $warnings[] = [
            'type' => 'labor_cost_ratio',
            'level' => $level,
            'date' => null,
            'time' => null,
            'message' => '予定人件費率が目標を超えています（予定' . $laborCostRatio . '% / 目標' . $targetLaborCostRatio . '%）',
        ];
    }
}

foreach ($laborRisks as $risk) {
    $warnings[] = [
        'type' => $risk['type'],
        'level' => $risk['level'],
        'date' => isset($risk['date']) ? $risk['date'] : null,
        'time' => null,
        'message' => $risk['message'],
    ];
}

if (count($unconfirmedAssignments) > 0) {
    $warnings[] = [
        'type' => 'unconfirmed_assignments',
        'level' => 'info',
        'date' => null,
        'time' => null,
        'message' => '確定シフトをまだ確認していないスタッフがいます（' . count($unconfirmedAssignments) . '件）',
    ];
}

$errorCount = 0;
$warningCount = 0;
$infoCount = 0;
foreach ($warnings as $w) {
    if ($w['level'] === 'error') {
        $errorCount++;
    } elseif ($w['level'] === 'warning') {
        $warningCount++;
    } else {
        $infoCount++;
    }
}

json_response([
    'period' => [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'days' => count($dates),
    ],
    'metrics' => [
        'staff_count' => count($users),
        'assignment_count' => count($assignments),
        'draft_count' => $draftCount,
        'published_count' => $publishedCount,
        'confirmed_count' => $confirmedCount,
        'error_count' => $errorCount,
        'warning_count' => $warningCount,
        'info_count' => $infoCount,
        'default_break_minutes' => $defaultBreakMinutes,
        'scheduled_labor_cost' => $scheduledLaborCostKnown ? $scheduledLaborCost : null,
        'forecast_revenue_total' => $forecastRevenueTotal,
        'labor_cost_ratio' => $laborCostRatio,
        'target_labor_cost_ratio' => $targetLaborCostRatio,
        'unconfirmed_count' => count($unconfirmedAssignments),
        'labor_risk_count' => count($laborRisks),
    ],
    'warnings' => $warnings,
    'coverage' => $coverage,
    'missing_availabilities' => $missingAvailabilities,
    'attendance_diffs' => $attendanceDiffs,
    'labor_risks' => $laborRisks,
    'unconfirmed_assignments' => $unconfirmedAssignments,
    'help_candidates' => $helpCandidates,
    'rules' => [
        'forecast' => '過去8週間の同曜日・同時間帯売上、対象期間の予約人数、テンプレート必要人数から最大値を採用',
        'break' => '6時間超は45分、8時間超は60分を休憩目安として警告',
        'attendance' => '予定開始から5分超の遅れ、予定終了より5分超の早退、予定労働時間より15分超の残業を表示',
        'labor_cost' => '予定人件費を過去売上平均と比較し、店舗設定の目標人件費率を超えると警告',
    ],
]);
