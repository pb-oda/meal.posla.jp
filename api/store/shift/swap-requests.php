<?php
/**
 * シフト交代/欠勤連絡 API
 *
 * GET    ?store_id=xxx                         → 依頼一覧
 * GET    ?action=candidates&assignment_id=xxx  → 交代候補
 * POST   body:{shift_assignment_id,request_type,reason,candidate_user_id}
 * PATCH  ?id=xxx body:{action,replacement_user_id,response_note}
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

function ssr_time_to_minutes($time)
{
    $time = (string)$time;
    if (strlen($time) < 5) {
        return 0;
    }
    return ((int)substr($time, 0, 2)) * 60 + (int)substr($time, 3, 2);
}

function ssr_overlap_minutes($aStart, $aEnd, $bStart, $bEnd)
{
    $as = ssr_time_to_minutes($aStart);
    $ae = ssr_time_to_minutes($aEnd);
    $bs = ssr_time_to_minutes($bStart);
    $be = ssr_time_to_minutes($bEnd);
    if ($ae < $as) $ae += 1440;
    if ($be < $bs) $be += 1440;
    return max(0, min($ae, $be) - max($as, $bs));
}

function ssr_display_name($row)
{
    return ($row['display_name'] ?? '') !== '' ? $row['display_name'] : ($row['username'] ?? '');
}

function ssr_load_assignment($pdo, $tenantId, $storeId, $assignmentId)
{
    $stmt = $pdo->prepare(
        'SELECT sa.*, u.display_name, u.username
         FROM shift_assignments sa
         JOIN users u ON u.id = sa.user_id
         WHERE sa.id = ? AND sa.tenant_id = ? AND sa.store_id = ?'
    );
    $stmt->execute([$assignmentId, $tenantId, $storeId]);
    return $stmt->fetch();
}

function ssr_candidate_rows($pdo, $tenantId, $storeId, $assignment)
{
    $stmtStaff = $pdo->prepare(
        'SELECT u.id, u.display_name, u.username
         FROM users u
         JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
         WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role = ?
           AND u.id != ?
         ORDER BY u.display_name, u.username'
    );
    $stmtStaff->execute([$storeId, $tenantId, 'staff', $assignment['user_id']]);
    $staffRows = $stmtStaff->fetchAll();
    if (count($staffRows) === 0) {
        return ['candidates' => [], 'excluded' => []];
    }

    $ids = array_column($staffRows, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $stmtAvail = $pdo->prepare(
        "SELECT user_id, availability, preferred_start, preferred_end
         FROM shift_availabilities
         WHERE tenant_id = ? AND store_id = ? AND target_date = ?
           AND user_id IN ({$ph})"
    );
    $stmtAvail->execute(array_merge([$tenantId, $storeId, $assignment['shift_date']], $ids));
    $availMap = [];
    foreach ($stmtAvail->fetchAll() as $av) {
        $availMap[$av['user_id']] = $av;
    }

    $stmtAssignments = $pdo->prepare(
        "SELECT user_id, start_time, end_time
         FROM shift_assignments
         WHERE tenant_id = ? AND shift_date = ?
           AND user_id IN ({$ph})"
    );
    $stmtAssignments->execute(array_merge([$tenantId, $assignment['shift_date']], $ids));
    $assignMap = [];
    foreach ($stmtAssignments->fetchAll() as $a) {
        if (!isset($assignMap[$a['user_id']])) {
            $assignMap[$a['user_id']] = [];
        }
        $assignMap[$a['user_id']][] = $a;
    }

    $stmtRole = $pdo->prepare(
        "SELECT user_id, role_type, COUNT(*) AS role_count
         FROM shift_assignments
         WHERE tenant_id = ?
           AND user_id IN ({$ph})
           AND role_type IS NOT NULL AND role_type != ''
           AND shift_date BETWEEN ? AND ?
         GROUP BY user_id, role_type"
    );
    $historyStart = date('Y-m-d', strtotime($assignment['shift_date'] . ' -90 days'));
    $stmtRole->execute(array_merge([$tenantId], $ids, [$historyStart, $assignment['shift_date']]));
    $roleMap = [];
    foreach ($stmtRole->fetchAll() as $r) {
        if (!isset($roleMap[$r['user_id']])) {
            $roleMap[$r['user_id']] = [];
        }
        $roleMap[$r['user_id']][$r['role_type']] = (int)$r['role_count'];
    }

    $candidates = [];
    $excluded = [];
    foreach ($staffRows as $sr) {
        $av = isset($availMap[$sr['id']]) ? $availMap[$sr['id']] : null;
        if ($av && $av['availability'] === 'unavailable') {
            $excluded[] = ['user_id' => $sr['id'], 'display_name' => ssr_display_name($sr), 'reason' => '希望不可'];
            continue;
        }
        if ($av && $av['preferred_start'] && $av['preferred_end'] &&
            ssr_overlap_minutes($av['preferred_start'], $av['preferred_end'], $assignment['start_time'], $assignment['end_time']) <= 0) {
            $excluded[] = ['user_id' => $sr['id'], 'display_name' => ssr_display_name($sr), 'reason' => '希望時間外'];
            continue;
        }

        $conflict = false;
        if (isset($assignMap[$sr['id']])) {
            foreach ($assignMap[$sr['id']] as $as) {
                if (ssr_overlap_minutes($as['start_time'], $as['end_time'], $assignment['start_time'], $assignment['end_time']) > 0) {
                    $conflict = true;
                    break;
                }
            }
        }
        if ($conflict) {
            $excluded[] = ['user_id' => $sr['id'], 'display_name' => ssr_display_name($sr), 'reason' => '同時間帯にシフトあり'];
            continue;
        }

        $role = $assignment['role_type'];
        $roleKnown = isset($roleMap[$sr['id']]);
        $roleMatch = !$role || !$roleKnown || isset($roleMap[$sr['id']][$role]);
        $score = 0;
        if ($av && $av['availability'] === 'preferred') $score += 20;
        elseif ($av && $av['availability'] === 'available') $score += 10;
        if ($roleMatch) $score += 5;

        $candidates[] = [
            'user_id' => $sr['id'],
            'display_name' => ssr_display_name($sr),
            'availability' => $av ? $av['availability'] : 'not_submitted',
            'preferred_start' => $av ? $av['preferred_start'] : null,
            'preferred_end' => $av ? $av['preferred_end'] : null,
            'role_match' => $roleMatch,
            'score' => $score,
        ];
    }

    usort($candidates, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            return strcmp($a['display_name'], $b['display_name']);
        }
        return $b['score'] - $a['score'];
    });

    return ['candidates' => $candidates, 'excluded' => $excluded];
}

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST', 'PATCH']);
$user   = require_auth();

$pdo      = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'candidates') {
    $assignmentId = $_GET['assignment_id'] ?? '';
    if ($assignmentId === '') {
        json_error('MISSING_ASSIGNMENT', 'assignment_id が必要です', 400);
    }
    $assignment = ssr_load_assignment($pdo, $tenantId, $storeId, $assignmentId);
    if (!$assignment) {
        json_error('NOT_FOUND', 'シフトが見つかりません', 404);
    }
    if ($user['role'] === 'staff' && $assignment['user_id'] !== $user['user_id']) {
        json_error('FORBIDDEN', '自分のシフトのみ操作できます', 403);
    }
    $result = ssr_candidate_rows($pdo, $tenantId, $storeId, $assignment);
    json_response([
        'assignment' => [
            'id' => $assignment['id'],
            'shift_date' => $assignment['shift_date'],
            'start_time' => $assignment['start_time'],
            'end_time' => $assignment['end_time'],
            'role_type' => $assignment['role_type'],
        ],
        'candidates' => $result['candidates'],
        'excluded' => $result['excluded'],
    ]);
}

if ($method === 'GET') {
    if ($user['role'] === 'staff') {
        $stmt = $pdo->prepare(
            "SELECT sr.*, sa.shift_date, sa.start_time, sa.end_time, sa.role_type,
                    req.display_name AS requester_name, req.username AS requester_username,
                    cand.display_name AS candidate_name, cand.username AS candidate_username,
                    repl.display_name AS replacement_name, repl.username AS replacement_username
             FROM shift_swap_requests sr
             JOIN shift_assignments sa ON sa.id = sr.shift_assignment_id
             JOIN users req ON req.id = sr.requester_user_id
             LEFT JOIN users cand ON cand.id = sr.candidate_user_id
             LEFT JOIN users repl ON repl.id = sr.replacement_user_id
             WHERE sr.tenant_id = ? AND sr.store_id = ?
               AND (sr.requester_user_id = ? OR sa.user_id = ?)
             ORDER BY sr.created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$tenantId, $storeId, $user['user_id'], $user['user_id']]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT sr.*, sa.shift_date, sa.start_time, sa.end_time, sa.role_type,
                    req.display_name AS requester_name, req.username AS requester_username,
                    cand.display_name AS candidate_name, cand.username AS candidate_username,
                    repl.display_name AS replacement_name, repl.username AS replacement_username
             FROM shift_swap_requests sr
             JOIN shift_assignments sa ON sa.id = sr.shift_assignment_id
             JOIN users req ON req.id = sr.requester_user_id
             LEFT JOIN users cand ON cand.id = sr.candidate_user_id
             LEFT JOIN users repl ON repl.id = sr.replacement_user_id
             WHERE sr.tenant_id = ? AND sr.store_id = ?
             ORDER BY FIELD(sr.status, 'pending','approved','rejected','cancelled'), sr.created_at DESC
             LIMIT 100"
        );
        $stmt->execute([$tenantId, $storeId]);
    }
    json_response(['requests' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = get_json_body();
    $assignmentId = $body['shift_assignment_id'] ?? '';
    $requestType = $body['request_type'] ?? 'swap';
    $candidateUserId = isset($body['candidate_user_id']) && $body['candidate_user_id'] !== '' ? $body['candidate_user_id'] : null;
    $reason = isset($body['reason']) ? trim($body['reason']) : null;

    if ($assignmentId === '') {
        json_error('MISSING_ASSIGNMENT', 'shift_assignment_id が必要です', 400);
    }
    if (!in_array($requestType, ['swap', 'absence'], true)) {
        json_error('INVALID_TYPE', 'request_type は swap / absence のいずれかです', 400);
    }

    $assignment = ssr_load_assignment($pdo, $tenantId, $storeId, $assignmentId);
    if (!$assignment) {
        json_error('NOT_FOUND', 'シフトが見つかりません', 404);
    }
    if ($user['role'] === 'staff' && $assignment['user_id'] !== $user['user_id']) {
        json_error('FORBIDDEN', '自分のシフトのみ申請できます', 403);
    }
    if ($assignment['shift_date'] < date('Y-m-d')) {
        json_error('PAST_SHIFT', '過去のシフトは申請できません', 400);
    }

    $stmtExisting = $pdo->prepare(
        'SELECT id FROM shift_swap_requests
         WHERE tenant_id = ? AND store_id = ? AND shift_assignment_id = ?
           AND status = \'pending\'
         LIMIT 1'
    );
    $stmtExisting->execute([$tenantId, $storeId, $assignmentId]);
    if ($stmtExisting->fetch()) {
        json_error('REQUEST_EXISTS', 'このシフトはすでに未対応の申請があります', 400);
    }

    if ($candidateUserId !== null) {
        $stmtCandidate = $pdo->prepare(
            'SELECT 1
             FROM users u
             JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
             WHERE u.id = ? AND u.tenant_id = ? AND u.is_active = 1 AND u.role = ?'
        );
        $stmtCandidate->execute([$storeId, $candidateUserId, $tenantId, 'staff']);
        if (!$stmtCandidate->fetch()) {
            json_error('INVALID_CANDIDATE', '候補スタッフが見つかりません', 400);
        }
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_swap_requests
            (id, tenant_id, store_id, shift_assignment_id, request_type,
             requester_user_id, candidate_user_id, status, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
    );
    $stmt->execute([
        $id, $tenantId, $storeId, $assignmentId, $requestType,
        $assignment['user_id'], $candidateUserId, $reason,
    ]);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_swap_request_create', 'shift_swap_request', $id,
        null,
        ['assignment_id' => $assignmentId, 'request_type' => $requestType, 'candidate_user_id' => $candidateUserId]
    );

    json_response(['id' => $id, 'status' => 'pending'], 201);
}

if ($method === 'PATCH') {
    require_role('manager');

    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT sr.*, sa.user_id AS current_user_id, sa.shift_date, sa.start_time, sa.end_time, sa.role_type, sa.note
         FROM shift_swap_requests sr
         JOIN shift_assignments sa ON sa.id = sr.shift_assignment_id
         WHERE sr.id = ? AND sr.tenant_id = ? AND sr.store_id = ?'
    );
    $stmt->execute([$id, $tenantId, $storeId]);
    $request = $stmt->fetch();
    if (!$request) {
        json_error('NOT_FOUND', '申請が見つかりません', 404);
    }
    if ($request['status'] !== 'pending') {
        json_error('ALREADY_HANDLED', 'この申請は対応済みです', 400);
    }

    $body = get_json_body();
    $patchAction = $body['action'] ?? '';
    $responseNote = isset($body['response_note']) ? trim($body['response_note']) : null;

    if ($patchAction === 'approve') {
        $replacementUserId = isset($body['replacement_user_id']) && $body['replacement_user_id'] !== ''
            ? $body['replacement_user_id'] : $request['candidate_user_id'];

        $pdo->beginTransaction();
        try {
            if ($replacementUserId !== null) {
                $stmtMember = $pdo->prepare(
                    'SELECT u.display_name, u.username
                     FROM users u
                     JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
                     WHERE u.id = ? AND u.tenant_id = ? AND u.is_active = 1 AND u.role = ?'
                );
                $stmtMember->execute([$storeId, $replacementUserId, $tenantId, 'staff']);
                $replacement = $stmtMember->fetch();
                if (!$replacement) {
                    $pdo->rollBack();
                    json_error('INVALID_REPLACEMENT', '交代スタッフが見つかりません', 400);
                }

                $stmtConflict = $pdo->prepare(
                    'SELECT start_time, end_time
                     FROM shift_assignments
                     WHERE tenant_id = ? AND user_id = ? AND shift_date = ?
                       AND id != ?'
                );
                $stmtConflict->execute([$tenantId, $replacementUserId, $request['shift_date'], $request['shift_assignment_id']]);
                foreach ($stmtConflict->fetchAll() as $c) {
                    if (ssr_overlap_minutes($c['start_time'], $c['end_time'], $request['start_time'], $request['end_time']) > 0) {
                        $pdo->rollBack();
                        json_error('SHIFT_CONFLICT', '交代スタッフは同時間帯に別シフトがあります', 400);
                    }
                }

                $note = trim((string)$request['note']);
                $append = '交代承認: ' . $request['requester_user_id'] . ' → ' . $replacementUserId;
                $note = $note === '' ? $append : ($note . "\n" . $append);

                $stmtUpdateShift = $pdo->prepare(
                    'UPDATE shift_assignments
                     SET user_id = ?, note = ?, status = \'published\'
                     WHERE id = ? AND tenant_id = ? AND store_id = ?'
                );
                $stmtUpdateShift->execute([$replacementUserId, $note, $request['shift_assignment_id'], $tenantId, $storeId]);
            }

            $stmtUpdate = $pdo->prepare(
                'UPDATE shift_swap_requests
                 SET status = \'approved\', replacement_user_id = ?, response_note = ?, responded_by = ?
                 WHERE id = ? AND tenant_id = ? AND store_id = ?'
            );
            $stmtUpdate->execute([$replacementUserId, $responseNote, $user['user_id'], $id, $tenantId, $storeId]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[shift_swap_requests] approve failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
            json_error('DB_ERROR', '承認に失敗しました', 500);
        }

        write_audit_log(
            $pdo, $user, $storeId,
            'shift_swap_request_approve', 'shift_swap_request', $id,
            $request,
            ['replacement_user_id' => $replacementUserId, 'response_note' => $responseNote]
        );

        json_response([
            'approved' => true,
            'replacement_user_id' => $replacementUserId,
            'needs_fill' => $replacementUserId === null,
            'assignment' => [
                'id' => $request['shift_assignment_id'],
                'shift_date' => $request['shift_date'],
                'start_time' => $request['start_time'],
                'end_time' => $request['end_time'],
                'role_type' => $request['role_type'],
            ],
        ]);
    }

    if ($patchAction === 'reject') {
        $stmtUpdate = $pdo->prepare(
            'UPDATE shift_swap_requests
             SET status = \'rejected\', response_note = ?, responded_by = ?
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmtUpdate->execute([$responseNote, $user['user_id'], $id, $tenantId, $storeId]);
        write_audit_log($pdo, $user, $storeId, 'shift_swap_request_reject', 'shift_swap_request', $id, $request, ['response_note' => $responseNote]);
        json_response(['rejected' => true]);
    }

    if ($patchAction === 'cancel') {
        $stmtUpdate = $pdo->prepare(
            'UPDATE shift_swap_requests
             SET status = \'cancelled\', response_note = ?, responded_by = ?
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmtUpdate->execute([$responseNote, $user['user_id'], $id, $tenantId, $storeId]);
        write_audit_log($pdo, $user, $storeId, 'shift_swap_request_cancel', 'shift_swap_request', $id, $request, ['response_note' => $responseNote]);
        json_response(['cancelled' => true]);
    }

    json_error('INVALID_ACTION', 'action は approve / reject / cancel のいずれかです', 400);
}
