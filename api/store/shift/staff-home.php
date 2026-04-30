<?php
/**
 * スタッフホーム集約 API
 *
 * GET ?store_id=xxx&date=YYYY-MM-DD
 *
 * staff 本人が最初に見る画面向けに、今日の勤務、勤怠状態、
 * 未対応シフト、担当作業、募集シフト、打刻修正申請をまとめて返す。
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';

function sh_valid_date($date)
{
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function sh_time5($time)
{
    return substr((string)$time, 0, 5);
}

function sh_decode_change_detail($value)
{
    if ($value === null || $value === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

start_auth_session();
handle_preflight();
require_method(['GET']);
$user = require_role('staff');

$pdo = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

$date = $_GET['date'] ?? date('Y-m-d');
if (!sh_valid_date($date)) {
    json_error('INVALID_DATE', 'date は YYYY-MM-DD 形式で指定してください', 400);
}

$userId = $user['user_id'];
$today = date('Y-m-d');
$futureEnd = date('Y-m-d', strtotime($date . ' +14 days'));

$stmtToday = $pdo->prepare(
    'SELECT sa.id, sa.shift_date, sa.start_time, sa.end_time,
            sa.break_minutes, sa.role_type, sa.status, sa.note,
            sa.confirmed_at, sa.confirmation_reset_at,
            sa.confirmation_reset_reason, sa.confirmation_reset_detail
     FROM shift_assignments sa
     WHERE sa.tenant_id = ? AND sa.store_id = ? AND sa.user_id = ?
       AND sa.shift_date = ?
     ORDER BY sa.start_time
     LIMIT 1'
);
$stmtToday->execute([$tenantId, $storeId, $userId, $date]);
$todayAssignment = $stmtToday->fetch();
if ($todayAssignment) {
    $todayAssignment['break_minutes'] = (int)$todayAssignment['break_minutes'];
    $todayAssignment['start_time'] = sh_time5($todayAssignment['start_time']);
    $todayAssignment['end_time'] = sh_time5($todayAssignment['end_time']);
    $todayAssignment['confirmation_required'] = (
        $todayAssignment['status'] !== 'confirmed' &&
        !empty($todayAssignment['confirmation_reset_at'])
    );
    $todayAssignment['confirmation_reset_detail'] = sh_decode_change_detail($todayAssignment['confirmation_reset_detail'] ?? null);
}

$stmtNext = $pdo->prepare(
    'SELECT id, shift_date, start_time, end_time, break_minutes, role_type, status,
            confirmation_reset_at, confirmation_reset_reason, confirmation_reset_detail
     FROM shift_assignments
     WHERE tenant_id = ? AND store_id = ? AND user_id = ?
       AND shift_date >= ?
     ORDER BY shift_date, start_time
     LIMIT 1'
);
$stmtNext->execute([$tenantId, $storeId, $userId, $date]);
$nextAssignment = $stmtNext->fetch();
if ($nextAssignment) {
    $nextAssignment['break_minutes'] = (int)$nextAssignment['break_minutes'];
    $nextAssignment['start_time'] = sh_time5($nextAssignment['start_time']);
    $nextAssignment['end_time'] = sh_time5($nextAssignment['end_time']);
    $nextAssignment['confirmation_required'] = (
        $nextAssignment['status'] !== 'confirmed' &&
        !empty($nextAssignment['confirmation_reset_at'])
    );
    $nextAssignment['confirmation_reset_detail'] = sh_decode_change_detail($nextAssignment['confirmation_reset_detail'] ?? null);
}

$stmtConfirm = $pdo->prepare(
    'SELECT id, shift_date, start_time, end_time, break_minutes, role_type, status,
            confirmation_reset_at, confirmation_reset_reason, confirmation_reset_detail
     FROM shift_assignments
     WHERE tenant_id = ? AND store_id = ? AND user_id = ?
       AND shift_date >= ? AND status = ?
     ORDER BY shift_date, start_time
     LIMIT 10'
);
$stmtConfirm->execute([$tenantId, $storeId, $userId, $today, 'published']);
$pendingConfirmations = $stmtConfirm->fetchAll();
foreach ($pendingConfirmations as &$pc) {
    $pc['break_minutes'] = (int)$pc['break_minutes'];
    $pc['start_time'] = sh_time5($pc['start_time']);
    $pc['end_time'] = sh_time5($pc['end_time']);
    $pc['confirmation_required'] = !empty($pc['confirmation_reset_at']);
    $pc['confirmation_reset_detail'] = sh_decode_change_detail($pc['confirmation_reset_detail'] ?? null);
}
unset($pc);

$stmtAttendance = $pdo->prepare(
    'SELECT id, shift_assignment_id, clock_in, clock_out, break_minutes,
            status, clock_in_method, clock_out_method, note
     FROM attendance_logs
     WHERE tenant_id = ? AND store_id = ? AND user_id = ?
       AND DATE(clock_in) = ?
     ORDER BY clock_in DESC'
);
$stmtAttendance->execute([$tenantId, $storeId, $userId, $date]);
$attendance = $stmtAttendance->fetchAll();
foreach ($attendance as &$att) {
    $att['break_minutes'] = (int)$att['break_minutes'];
}
unset($att);

$stmtWorking = $pdo->prepare(
    'SELECT id, shift_assignment_id, clock_in
     FROM attendance_logs
     WHERE tenant_id = ? AND store_id = ? AND user_id = ? AND status = ?
     ORDER BY clock_in DESC
     LIMIT 1'
);
$stmtWorking->execute([$tenantId, $storeId, $userId, 'working']);
$working = $stmtWorking->fetch();

$stmtTasks = $pdo->prepare(
    'SELECT ta.id, ta.task_date, ta.task_label, ta.status, ta.note, tt.day_part
     FROM shift_task_assignments ta
     LEFT JOIN shift_task_templates tt ON tt.id = ta.task_template_id
     WHERE ta.tenant_id = ? AND ta.store_id = ? AND ta.user_id = ?
       AND ta.task_date BETWEEN ? AND ?
     ORDER BY ta.task_date, ta.status, ta.created_at
     LIMIT 20'
);
$stmtTasks->execute([$tenantId, $storeId, $userId, $date, $futureEnd]);
$tasks = $stmtTasks->fetchAll();
$pendingTaskCount = 0;
foreach ($tasks as $task) {
    if ($task['status'] === 'pending') {
        $pendingTaskCount++;
    }
}

$stmtRequests = $pdo->prepare(
    "SELECT sr.id, sr.shift_assignment_id, sr.request_type, sr.status, sr.reason,
            sr.response_note, sr.created_at,
            sa.shift_date, sa.start_time, sa.end_time, sa.role_type
     FROM shift_swap_requests sr
     JOIN shift_assignments sa ON sa.id = sr.shift_assignment_id
     WHERE sr.tenant_id = ? AND sr.store_id = ?
       AND sr.requester_user_id = ?
     ORDER BY FIELD(sr.status, 'pending','approved','rejected','cancelled'), sr.created_at DESC
     LIMIT 10"
);
$stmtRequests->execute([$tenantId, $storeId, $userId]);
$requests = $stmtRequests->fetchAll();
$pendingRequestCount = 0;
foreach ($requests as &$req) {
    $req['start_time'] = sh_time5($req['start_time']);
    $req['end_time'] = sh_time5($req['end_time']);
    if ($req['status'] === 'pending') {
        $pendingRequestCount++;
    }
}
unset($req);

$stmtOpen = $pdo->prepare(
    'SELECT os.id, os.shift_date, os.start_time, os.end_time, os.break_minutes,
            os.role_type, os.required_skill_code, os.note,
            app.status AS my_application_status
     FROM shift_open_shifts os
     LEFT JOIN shift_open_shift_applications app
       ON app.open_shift_id = os.id
      AND app.tenant_id = os.tenant_id
      AND app.store_id = os.store_id
      AND app.user_id = ?
     WHERE os.tenant_id = ? AND os.store_id = ?
       AND os.shift_date >= ? AND os.status = ?
     ORDER BY os.shift_date, os.start_time
     LIMIT 10'
);
$stmtOpen->execute([$userId, $tenantId, $storeId, $today, 'open']);
$openShifts = $stmtOpen->fetchAll();
foreach ($openShifts as &$os) {
    $os['break_minutes'] = (int)$os['break_minutes'];
    $os['start_time'] = sh_time5($os['start_time']);
    $os['end_time'] = sh_time5($os['end_time']);
}
unset($os);

$stmtCorrections = $pdo->prepare(
    'SELECT id, attendance_log_id, target_date, request_type,
            requested_clock_in, requested_clock_out, requested_break_minutes,
            reason, status, response_note, responded_at, created_at
     FROM attendance_correction_requests
     WHERE tenant_id = ? AND store_id = ? AND user_id = ?
     ORDER BY FIELD(status, \'pending\', \'approved\', \'rejected\', \'cancelled\'), created_at DESC
     LIMIT 10'
);
$stmtCorrections->execute([$tenantId, $storeId, $userId]);
$corrections = $stmtCorrections->fetchAll();
$pendingCorrectionCount = 0;
foreach ($corrections as &$corr) {
    $corr['requested_break_minutes'] = $corr['requested_break_minutes'] !== null ? (int)$corr['requested_break_minutes'] : null;
    if ($corr['status'] === 'pending') {
        $pendingCorrectionCount++;
    }
}
unset($corr);

$stmtAvail = $pdo->prepare(
    'SELECT COUNT(*) FROM shift_availabilities
     WHERE tenant_id = ? AND store_id = ? AND user_id = ?
       AND target_date BETWEEN ? AND ?'
);
$stmtAvail->execute([$tenantId, $storeId, $userId, $today, $futureEnd]);
$availabilitySubmittedCount = (int)$stmtAvail->fetchColumn();

json_response([
    'date' => $date,
    'server_now' => date('Y-m-d H:i:s'),
    'today_assignment' => $todayAssignment ?: null,
    'next_assignment' => $nextAssignment ?: null,
    'pending_confirmations' => $pendingConfirmations,
    'attendance' => $attendance,
    'working' => $working ?: null,
    'tasks' => $tasks,
    'requests' => $requests,
    'open_shifts' => $openShifts,
    'attendance_corrections' => $corrections,
    'availability' => [
        'range_start' => $today,
        'range_end' => $futureEnd,
        'submitted_count' => $availabilitySubmittedCount,
    ],
    'metrics' => [
        'pending_confirmation_count' => count($pendingConfirmations),
        'pending_task_count' => $pendingTaskCount,
        'pending_request_count' => $pendingRequestCount,
        'open_shift_count' => count($openShifts),
        'pending_correction_count' => $pendingCorrectionCount,
    ],
]);
