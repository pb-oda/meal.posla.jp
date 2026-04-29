<?php
/**
 * 当日シフト状態 API
 *
 * GET ?store_id=xxx&date=YYYY-MM-DD
 *
 * 今日の欠勤連絡、未打刻、勤務中、時間帯不足、呼べる候補を返す。
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';

function sts_time_to_minutes($time)
{
    $time = (string)$time;
    if (strlen($time) < 5) return 0;
    return ((int)substr($time, 0, 2)) * 60 + (int)substr($time, 3, 2);
}

function sts_overlap_minutes($aStart, $aEnd, $bStartMin, $bEndMin)
{
    $as = sts_time_to_minutes($aStart);
    $ae = sts_time_to_minutes($aEnd);
    if ($ae < $as) $ae += 1440;
    return max(0, min($ae, $bEndMin) - max($as, $bStartMin));
}

function sts_display_name($row)
{
    return ($row['display_name'] ?? '') !== '' ? $row['display_name'] : ($row['username'] ?? '');
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

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('INVALID_DATE', 'date は YYYY-MM-DD 形式で指定してください', 400);
}

$now = date('Y-m-d H:i:s');
$nowDate = date('Y-m-d');
$nowMin = ((int)date('H')) * 60 + (int)date('i');

$stmtAssignments = $pdo->prepare(
    'SELECT sa.id, sa.user_id, sa.shift_date, sa.start_time, sa.end_time,
            sa.break_minutes, sa.role_type, sa.status,
            u.display_name, u.username
     FROM shift_assignments sa
     JOIN users u ON u.id = sa.user_id
     WHERE sa.tenant_id = ? AND sa.store_id = ? AND sa.shift_date = ?
     ORDER BY sa.start_time, u.display_name'
);
$stmtAssignments->execute([$tenantId, $storeId, $date]);
$assignments = $stmtAssignments->fetchAll();

$stmtAttendance = $pdo->prepare(
    'SELECT a.id, a.user_id, a.shift_assignment_id, a.clock_in, a.clock_out,
            a.break_minutes, a.status,
            u.display_name, u.username
     FROM attendance_logs a
     JOIN users u ON u.id = a.user_id
     WHERE a.tenant_id = ? AND a.store_id = ? AND DATE(a.clock_in) = ?
     ORDER BY a.clock_in'
);
$stmtAttendance->execute([$tenantId, $storeId, $date]);
$attendance = $stmtAttendance->fetchAll();
$attendanceByShift = [];
$attendanceByUser = [];
foreach ($attendance as $a) {
    if (!empty($a['shift_assignment_id'])) {
        $attendanceByShift[$a['shift_assignment_id']] = $a;
    }
    if (!isset($attendanceByUser[$a['user_id']])) {
        $attendanceByUser[$a['user_id']] = [];
    }
    $attendanceByUser[$a['user_id']][] = $a;
}

$stmtSwap = $pdo->prepare(
    'SELECT sr.*, sa.shift_date, sa.start_time, sa.end_time, sa.role_type,
            u.display_name, u.username
     FROM shift_swap_requests sr
     JOIN shift_assignments sa ON sa.id = sr.shift_assignment_id
     JOIN users u ON u.id = sr.requester_user_id
     WHERE sr.tenant_id = ? AND sr.store_id = ?
       AND sa.shift_date = ?
       AND sr.status IN (\'pending\', \'approved\')
     ORDER BY sr.created_at'
);
$stmtSwap->execute([$tenantId, $storeId, $date]);
$swapRows = $stmtSwap->fetchAll();
$absenceByAssignment = [];
$absenceUserIds = [];
$pendingRequests = [];
$approvedAbsences = [];
foreach ($swapRows as $sr) {
    if ($sr['request_type'] === 'absence' && $sr['status'] === 'approved' && empty($sr['replacement_user_id'])) {
        $absenceByAssignment[$sr['shift_assignment_id']] = true;
        $absenceUserIds[$sr['requester_user_id']] = true;
        $approvedAbsences[] = [
            'id' => $sr['id'],
            'assignment_id' => $sr['shift_assignment_id'],
            'display_name' => sts_display_name($sr),
            'shift_date' => $sr['shift_date'],
            'start_time' => substr($sr['start_time'], 0, 5),
            'end_time' => substr($sr['end_time'], 0, 5),
            'reason' => $sr['reason'],
        ];
    }
    if ($sr['status'] === 'pending') {
        $pendingRequests[] = [
            'id' => $sr['id'],
            'type' => $sr['request_type'],
            'display_name' => sts_display_name($sr),
            'shift_date' => $sr['shift_date'],
            'start_time' => substr($sr['start_time'], 0, 5),
            'end_time' => substr($sr['end_time'], 0, 5),
            'reason' => $sr['reason'],
        ];
    }
}

$noClockIn = [];
$working = [];
$completed = [];
$scheduledUserIds = [];
$minHour = 8;
$maxHour = 23;
foreach ($assignments as $asg) {
    if (isset($absenceByAssignment[$asg['id']])) {
        continue;
    }
    $scheduledUserIds[$asg['user_id']] = true;
    $startMin = sts_time_to_minutes($asg['start_time']);
    $endMin = sts_time_to_minutes($asg['end_time']);
    $minHour = min($minHour, max(0, floor($startMin / 60)));
    $maxHour = max($maxHour, min(24, (int)ceil($endMin / 60)));

    if (isset($attendanceByShift[$asg['id']])) {
        $att = $attendanceByShift[$asg['id']];
    } elseif (isset($attendanceByUser[$asg['user_id']][0])) {
        $att = $attendanceByUser[$asg['user_id']][0];
    } else {
        $att = null;
    }

    if ($att && $att['status'] === 'working') {
        $working[] = ['assignment_id' => $asg['id'], 'display_name' => sts_display_name($asg), 'clock_in' => $att['clock_in']];
    } elseif ($att && $att['clock_out']) {
        $completed[] = ['assignment_id' => $asg['id'], 'display_name' => sts_display_name($asg), 'clock_in' => $att['clock_in'], 'clock_out' => $att['clock_out']];
    } elseif ($date < $nowDate || ($date === $nowDate && $startMin + 15 < $nowMin)) {
        $noClockIn[] = [
            'assignment_id' => $asg['id'],
            'display_name' => sts_display_name($asg),
            'start_time' => substr($asg['start_time'], 0, 5),
            'end_time' => substr($asg['end_time'], 0, 5),
            'role_type' => $asg['role_type'],
        ];
    }
}

$stmtTemplates = $pdo->prepare(
    'SELECT day_of_week, start_time, end_time, required_staff, role_hint
     FROM shift_templates
     WHERE tenant_id = ? AND store_id = ? AND is_active = 1'
);
$stmtTemplates->execute([$tenantId, $storeId]);
$templates = $stmtTemplates->fetchAll();
$dow = (int)date('w', strtotime($date));
foreach ($templates as $tpl) {
    if ((int)$tpl['day_of_week'] !== $dow) continue;
    $minHour = min($minHour, max(0, floor(sts_time_to_minutes($tpl['start_time']) / 60)));
    $maxHour = max($maxHour, min(24, (int)ceil(sts_time_to_minutes($tpl['end_time']) / 60)));
}

$coverage = [];
for ($hour = $minHour; $hour < $maxHour; $hour++) {
    $slotStart = $hour * 60;
    $slotEnd = ($hour + 1) * 60;
    $scheduled = 0;
    foreach ($assignments as $asg) {
        if (isset($absenceByAssignment[$asg['id']])) continue;
        if (sts_overlap_minutes($asg['start_time'], $asg['end_time'], $slotStart, $slotEnd) > 0) {
            $scheduled++;
        }
    }

    $required = 0;
    foreach ($templates as $tpl) {
        if ((int)$tpl['day_of_week'] !== $dow) continue;
        if (sts_overlap_minutes($tpl['start_time'], $tpl['end_time'], $slotStart, $slotEnd) > 0) {
            $required += (int)$tpl['required_staff'];
        }
    }
    if ($required === 0 && $scheduled === 0) continue;
    $coverage[] = [
        'time' => sprintf('%02d:00-%02d:00', $hour, ($hour + 1) % 24),
        'scheduled_count' => $scheduled,
        'required_count' => $required,
        'shortage_count' => max(0, $required - $scheduled),
    ];
}

$stmtStaff = $pdo->prepare(
    'SELECT u.id, u.display_name, u.username
     FROM users u
     JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
     WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role = ?
     ORDER BY u.display_name, u.username'
);
$stmtStaff->execute([$storeId, $tenantId, 'staff']);
$callable = [];
foreach ($stmtStaff->fetchAll() as $staff) {
    if (isset($scheduledUserIds[$staff['id']])) continue;
    if (isset($absenceUserIds[$staff['id']])) continue;
    $callable[] = [
        'user_id' => $staff['id'],
        'display_name' => sts_display_name($staff),
        'reason' => '本日シフトなし',
    ];
}

json_response([
    'date' => $date,
    'server_now' => $now,
    'metrics' => [
        'assignment_count' => count($assignments),
        'working_count' => count($working),
        'completed_count' => count($completed),
        'no_clock_in_count' => count($noClockIn),
        'pending_request_count' => count($pendingRequests),
        'approved_absence_count' => count($approvedAbsences),
    ],
    'pending_requests' => $pendingRequests,
    'approved_absences' => $approvedAbsences,
    'no_clock_in' => $noClockIn,
    'working' => $working,
    'coverage' => $coverage,
    'callable_candidates' => array_slice($callable, 0, 10),
]);
