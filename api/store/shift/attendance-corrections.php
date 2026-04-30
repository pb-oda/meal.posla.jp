<?php
/**
 * 打刻修正申請 API
 *
 * GET   ?store_id=xxx&status=pending
 * POST  body:{attendance_log_id?,target_date,requested_clock_in?,requested_clock_out?,requested_break_minutes?,reason}
 * PATCH ?id=xxx body:{action:approve|reject|cancel,response_note?}
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

function acr_valid_date($date)
{
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function acr_valid_datetime_or_null($value)
{
    return $value === null || $value === '' ||
        (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value));
}

function acr_normal_datetime($value)
{
    $value = trim((string)$value);
    if ($value === '') return null;
    if (strlen($value) === 16) return $value . ':00';
    return $value;
}

function acr_find_shift_assignment_id($pdo, $tenantId, $storeId, $userId, $date)
{
    $stmt = $pdo->prepare(
        'SELECT id
         FROM shift_assignments
         WHERE tenant_id = ? AND store_id = ? AND user_id = ? AND shift_date = ?
         ORDER BY start_time
         LIMIT 1'
    );
    $stmt->execute([$tenantId, $storeId, $userId, $date]);
    $id = $stmt->fetchColumn();
    return $id ?: null;
}

function acr_load_request($pdo, $tenantId, $storeId, $id)
{
    $stmt = $pdo->prepare(
        'SELECT acr.*, u.display_name, u.username
         FROM attendance_correction_requests acr
         JOIN users u ON u.id = acr.user_id
         WHERE acr.id = ? AND acr.tenant_id = ? AND acr.store_id = ?
         LIMIT 1'
    );
    $stmt->execute([$id, $tenantId, $storeId]);
    return $stmt->fetch();
}

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST', 'PATCH']);
$user = require_role('staff');

$pdo = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    if ($status !== '' && !in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
        json_error('INVALID_STATUS', 'status が不正です', 400);
    }

    $params = [$tenantId, $storeId];
    $where = 'acr.tenant_id = ? AND acr.store_id = ?';
    if ($user['role'] === 'staff') {
        $where .= ' AND acr.user_id = ?';
        $params[] = $user['user_id'];
    }
    if ($status !== '') {
        $where .= ' AND acr.status = ?';
        $params[] = $status;
    }

    $stmt = $pdo->prepare(
        'SELECT acr.id, acr.user_id, acr.attendance_log_id, acr.target_date,
                acr.request_type, acr.requested_clock_in, acr.requested_clock_out,
                acr.requested_break_minutes, acr.reason, acr.status,
                acr.response_note, acr.responded_at, acr.created_at,
                u.display_name, u.username
         FROM attendance_correction_requests acr
         JOIN users u ON u.id = acr.user_id
         WHERE ' . $where . '
         ORDER BY FIELD(acr.status, \'pending\', \'approved\', \'rejected\', \'cancelled\'), acr.created_at DESC
         LIMIT 100'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['requested_break_minutes'] = $row['requested_break_minutes'] !== null ? (int)$row['requested_break_minutes'] : null;
    }
    unset($row);
    json_response(['requests' => $rows]);
}

if ($method === 'POST') {
    if ($user['role'] !== 'staff') {
        json_error('FORBIDDEN', '打刻修正申請はスタッフ本人のみ作成できます', 403);
    }

    $body = get_json_body();
    $attendanceLogId = isset($body['attendance_log_id']) && $body['attendance_log_id'] !== '' ? (string)$body['attendance_log_id'] : null;
    $targetDate = $body['target_date'] ?? date('Y-m-d');
    $requestType = $body['request_type'] ?? 'other';
    $clockIn = acr_normal_datetime($body['requested_clock_in'] ?? null);
    $clockOut = acr_normal_datetime($body['requested_clock_out'] ?? null);
    $breakMinutes = array_key_exists('requested_break_minutes', $body) && $body['requested_break_minutes'] !== ''
        ? (int)$body['requested_break_minutes'] : null;
    $reason = trim((string)($body['reason'] ?? ''));

    if (!acr_valid_date($targetDate)) {
        json_error('INVALID_DATE', 'target_date は YYYY-MM-DD 形式で指定してください', 400);
    }
    if (!in_array($requestType, ['clock_in', 'clock_out', 'break', 'note', 'other'], true)) {
        json_error('INVALID_TYPE', 'request_type が不正です', 400);
    }
    if (!acr_valid_datetime_or_null($clockIn) || !acr_valid_datetime_or_null($clockOut)) {
        json_error('INVALID_DATETIME', '打刻時刻は YYYY-MM-DD HH:MM 形式で指定してください', 400);
    }
    if ($breakMinutes !== null && ($breakMinutes < 0 || $breakMinutes > 480)) {
        json_error('INVALID_BREAK', '休憩時間は 0〜480 分で指定してください', 400);
    }
    if ($reason === '') {
        json_error('MISSING_REASON', '申請理由を入力してください', 400);
    }
    if ($attendanceLogId === null && $clockIn === null) {
        json_error('MISSING_CLOCK_IN', '打刻なしの申請では出勤時刻を入力してください', 400);
    }
    if ($clockIn === null && $clockOut === null && $breakMinutes === null) {
        json_error('NO_FIELDS', '修正したい時刻または休憩時間を入力してください', 400);
    }

    if ($attendanceLogId !== null) {
        $stmtLog = $pdo->prepare(
            'SELECT id
             FROM attendance_logs
             WHERE id = ? AND tenant_id = ? AND store_id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmtLog->execute([$attendanceLogId, $tenantId, $storeId, $user['user_id']]);
        if (!$stmtLog->fetch()) {
            json_error('ATTENDANCE_NOT_FOUND', '対象の勤怠レコードが見つかりません', 404);
        }
    }

    $stmtExisting = $pdo->prepare(
        'SELECT id
         FROM attendance_correction_requests
         WHERE tenant_id = ? AND store_id = ? AND user_id = ?
           AND target_date = ? AND status = ?
         LIMIT 1'
    );
    $stmtExisting->execute([$tenantId, $storeId, $user['user_id'], $targetDate, 'pending']);
    if ($stmtExisting->fetch()) {
        json_error('REQUEST_EXISTS', '同じ日の未対応申請があります', 400);
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO attendance_correction_requests
            (id, tenant_id, store_id, user_id, attendance_log_id, target_date,
             request_type, requested_clock_in, requested_clock_out,
             requested_break_minutes, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $tenantId,
        $storeId,
        $user['user_id'],
        $attendanceLogId,
        $targetDate,
        $requestType,
        $clockIn,
        $clockOut,
        $breakMinutes,
        $reason,
    ]);

    write_audit_log($pdo, $user, $storeId, 'attendance_correction_request_create', 'attendance_correction_request', $id, null, [
        'target_date' => $targetDate,
        'attendance_log_id' => $attendanceLogId,
        'request_type' => $requestType,
    ]);

    json_response(['id' => $id, 'status' => 'pending'], 201);
}

if ($method === 'PATCH') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }
    $body = get_json_body();
    $action = $body['action'] ?? '';
    $responseNote = isset($body['response_note']) ? trim((string)$body['response_note']) : null;
    if (!in_array($action, ['approve', 'reject', 'cancel'], true)) {
        json_error('INVALID_ACTION', 'action は approve / reject / cancel のいずれかです', 400);
    }

    $request = acr_load_request($pdo, $tenantId, $storeId, $id);
    if (!$request) {
        json_error('NOT_FOUND', '打刻修正申請が見つかりません', 404);
    }
    if ($request['status'] !== 'pending') {
        json_error('ALREADY_HANDLED', 'この申請は処理済みです', 400);
    }

    if ($action === 'cancel') {
        if ($user['role'] !== 'staff' || $request['user_id'] !== $user['user_id']) {
            json_error('FORBIDDEN', '自分の未対応申請のみ取り消せます', 403);
        }
        $stmtCancel = $pdo->prepare(
            'UPDATE attendance_correction_requests
             SET status = ?, response_note = ?, responded_by = ?, responded_at = NOW()
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmtCancel->execute(['cancelled', $responseNote, $user['user_id'], $id, $tenantId, $storeId]);
        write_audit_log($pdo, $user, $storeId, 'attendance_correction_request_cancel', 'attendance_correction_request', $id, $request, ['response_note' => $responseNote]);
        json_response(['cancelled' => true]);
    }

    require_role('manager');

    if ($action === 'reject') {
        $stmtReject = $pdo->prepare(
            'UPDATE attendance_correction_requests
             SET status = ?, response_note = ?, responded_by = ?, responded_at = NOW()
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmtReject->execute(['rejected', $responseNote, $user['user_id'], $id, $tenantId, $storeId]);
        write_audit_log($pdo, $user, $storeId, 'attendance_correction_request_reject', 'attendance_correction_request', $id, $request, ['response_note' => $responseNote]);
        json_response(['rejected' => true]);
    }

    try {
        $pdo->beginTransaction();
        $appliedAttendanceId = $request['attendance_log_id'];

        if ($appliedAttendanceId) {
            $stmtLog = $pdo->prepare(
                'SELECT * FROM attendance_logs
                 WHERE id = ? AND tenant_id = ? AND store_id = ? AND user_id = ?
                 FOR UPDATE'
            );
            $stmtLog->execute([$appliedAttendanceId, $tenantId, $storeId, $request['user_id']]);
            $oldLog = $stmtLog->fetch();
            if (!$oldLog) {
                $pdo->rollBack();
                json_error('ATTENDANCE_NOT_FOUND', '対象の勤怠レコードが見つかりません', 404);
            }

            $updates = [];
            $params = [];
            if ($request['requested_clock_in'] !== null) {
                $updates[] = 'clock_in = ?';
                $params[] = $request['requested_clock_in'];
            }
            if ($request['requested_clock_out'] !== null) {
                $updates[] = 'clock_out = ?';
                $params[] = $request['requested_clock_out'];
                $updates[] = 'status = \'completed\'';
            }
            if ($request['requested_break_minutes'] !== null) {
                $updates[] = 'break_minutes = ?';
                $params[] = (int)$request['requested_break_minutes'];
            }
            $updates[] = 'note = ?';
            $params[] = trim((string)($oldLog['note'] ?? '') . "\n打刻修正承認: " . $request['reason']);
            $params[] = $appliedAttendanceId;
            $params[] = $tenantId;
            $params[] = $storeId;
            $stmtUpdate = $pdo->prepare(
                'UPDATE attendance_logs SET ' . implode(', ', $updates) .
                ' WHERE id = ? AND tenant_id = ? AND store_id = ?'
            );
            $stmtUpdate->execute($params);
        } else {
            if ($request['requested_clock_in'] === null) {
                $pdo->rollBack();
                json_error('MISSING_CLOCK_IN', '新規勤怠作成には出勤時刻が必要です', 400);
            }
            $appliedAttendanceId = generate_uuid();
            $shiftAssignmentId = acr_find_shift_assignment_id($pdo, $tenantId, $storeId, $request['user_id'], $request['target_date']);
            $status = $request['requested_clock_out'] !== null ? 'completed' : 'working';
            $stmtInsert = $pdo->prepare(
                'INSERT INTO attendance_logs
                    (id, tenant_id, store_id, user_id, shift_assignment_id,
                     clock_in, clock_out, break_minutes, status,
                     clock_in_method, clock_out_method, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtInsert->execute([
                $appliedAttendanceId,
                $tenantId,
                $storeId,
                $request['user_id'],
                $shiftAssignmentId,
                $request['requested_clock_in'],
                $request['requested_clock_out'],
                $request['requested_break_minutes'] !== null ? (int)$request['requested_break_minutes'] : 0,
                $status,
                'manual',
                'manual',
                '打刻修正承認: ' . $request['reason'],
            ]);
        }

        $stmtApprove = $pdo->prepare(
            'UPDATE attendance_correction_requests
             SET status = ?, attendance_log_id = ?, response_note = ?,
                 responded_by = ?, responded_at = NOW()
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmtApprove->execute(['approved', $appliedAttendanceId, $responseNote, $user['user_id'], $id, $tenantId, $storeId]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[attendance-corrections] approve failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        json_error('SERVER_ERROR', '打刻修正申請の承認に失敗しました', 500);
    }

    write_audit_log($pdo, $user, $storeId, 'attendance_correction_request_approve', 'attendance_correction_request', $id, $request, [
        'attendance_log_id' => $appliedAttendanceId,
        'response_note' => $responseNote,
    ]);

    json_response(['approved' => true, 'attendance_log_id' => $appliedAttendanceId]);
}
