<?php
/**
 * シフト希望提出依頼 API
 *
 * GET   ?store_id=xxx                         → 依頼一覧
 * POST  body:{target_start_date,target_end_date,due_date,title,message}
 * PATCH ?id=xxx body:{action:mark_read|close|cancel|reopen}
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

function saa_valid_date($date)
{
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function saa_days_inclusive($startDate, $endDate)
{
    $start = strtotime($startDate . ' 00:00:00');
    $end = strtotime($endDate . ' 00:00:00');
    if ($start === false || $end === false || $end < $start) {
        return 0;
    }
    return (int)floor(($end - $start) / 86400) + 1;
}

function saa_clean_text($value, $maxLen)
{
    $value = trim((string)$value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLen, 'UTF-8');
    }
    return substr($value, 0, $maxLen);
}

function saa_enrich_announcements($pdo, $tenantId, $storeId, $userId, $rows)
{
    $stmtSubmitted = $pdo->prepare(
        'SELECT COUNT(DISTINCT target_date)
         FROM shift_availabilities
         WHERE tenant_id = ? AND store_id = ? AND user_id = ?
           AND target_date BETWEEN ? AND ?'
    );

    foreach ($rows as &$row) {
        $requiredDays = saa_days_inclusive($row['target_start_date'], $row['target_end_date']);
        $stmtSubmitted->execute([
            $tenantId,
            $storeId,
            $userId,
            $row['target_start_date'],
            $row['target_end_date'],
        ]);
        $submittedDays = (int)$stmtSubmitted->fetchColumn();
        $row['required_days'] = $requiredDays;
        $row['submitted_days'] = $submittedDays;
        $row['missing_days'] = max(0, $requiredDays - $submittedDays);
        $row['is_submitted'] = ($requiredDays > 0 && $submittedDays >= $requiredDays);
        $row['is_read'] = !empty($row['read_at']);
    }
    unset($row);

    return $rows;
}

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST', 'PATCH']);
$user = require_auth();

$pdo = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'シフト管理はProプラン以上で利用できます', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

if ($method === 'GET') {
    if ($user['role'] === 'staff') {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.target_start_date, a.target_end_date, a.due_date,
                    a.title, a.message, a.status, a.created_at,
                    u.display_name AS created_by_name, u.username AS created_by_username,
                    r.read_at
             FROM shift_availability_announcements a
             LEFT JOIN users u ON u.id = a.created_by AND u.tenant_id = a.tenant_id
             LEFT JOIN shift_availability_announcement_reads r
               ON r.tenant_id = a.tenant_id
              AND r.store_id = a.store_id
              AND r.announcement_id = a.id
              AND r.user_id = ?
             WHERE a.tenant_id = ? AND a.store_id = ?
               AND a.status = ?
               AND a.target_end_date >= ?
             ORDER BY a.due_date ASC, a.created_at DESC
             LIMIT 10'
        );
        $stmt->execute([$user['user_id'], $tenantId, $storeId, 'active', date('Y-m-d')]);
        $rows = saa_enrich_announcements($pdo, $tenantId, $storeId, $user['user_id'], $stmt->fetchAll());
        $unsubmitted = 0;
        foreach ($rows as $row) {
            if (!$row['is_submitted']) {
                $unsubmitted++;
            }
        }
        json_response([
            'announcements' => $rows,
            'unsubmitted_count' => $unsubmitted,
        ]);
    }

    require_role('manager');

    $stmtStaff = $pdo->prepare(
        "SELECT COUNT(DISTINCT u.id)
         FROM users u
         JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
         WHERE u.tenant_id = ? AND u.role = 'staff' AND u.is_active = 1"
    );
    $stmtStaff->execute([$storeId, $tenantId]);
    $staffCount = (int)$stmtStaff->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT a.id, a.target_start_date, a.target_end_date, a.due_date,
                a.title, a.message, a.status, a.created_at,
                u.display_name AS created_by_name, u.username AS created_by_username,
                (SELECT COUNT(*)
                   FROM shift_availability_announcement_reads r
                  WHERE r.tenant_id = a.tenant_id
                    AND r.store_id = a.store_id
                    AND r.announcement_id = a.id) AS read_count
         FROM shift_availability_announcements a
         LEFT JOIN users u ON u.id = a.created_by AND u.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND a.store_id = ?
         ORDER BY FIELD(a.status, \'active\', \'closed\', \'cancelled\'), a.created_at DESC
         LIMIT 20'
    );
    $stmt->execute([$tenantId, $storeId]);
    $rows = $stmt->fetchAll();
    $stmtSubmitted = $pdo->prepare(
        "SELECT sa.user_id, COUNT(DISTINCT sa.target_date) AS submitted_days
         FROM shift_availabilities sa
         JOIN users u ON u.id = sa.user_id AND u.tenant_id = sa.tenant_id
         JOIN user_stores us ON us.user_id = u.id AND us.store_id = sa.store_id
         WHERE sa.tenant_id = ? AND sa.store_id = ?
           AND u.role = 'staff' AND u.is_active = 1
           AND sa.target_date BETWEEN ? AND ?
         GROUP BY sa.user_id"
    );
    foreach ($rows as &$row) {
        $requiredDays = saa_days_inclusive($row['target_start_date'], $row['target_end_date']);
        $stmtSubmitted->execute([$tenantId, $storeId, $row['target_start_date'], $row['target_end_date']]);
        $submittedStaff = 0;
        foreach ($stmtSubmitted->fetchAll() as $submittedRow) {
            if ((int)$submittedRow['submitted_days'] >= $requiredDays) {
                $submittedStaff++;
            }
        }
        $row['staff_count'] = $staffCount;
        $row['submitted_staff_count'] = $submittedStaff;
        $row['unsubmitted_staff_count'] = max(0, $staffCount - $submittedStaff);
        $row['read_count'] = (int)$row['read_count'];
    }
    unset($row);

    json_response(['announcements' => $rows]);
}

if ($method === 'POST') {
    require_role('manager');

    $body = get_json_body();
    $startDate = $body['target_start_date'] ?? '';
    $endDate = $body['target_end_date'] ?? '';
    $dueDate = $body['due_date'] ?? '';
    if (!saa_valid_date($startDate) || !saa_valid_date($endDate) || !saa_valid_date($dueDate)) {
        json_error('INVALID_DATE', 'target_start_date, target_end_date, due_date は YYYY-MM-DD 形式で指定してください', 400);
    }
    if ($startDate > $endDate) {
        json_error('INVALID_DATE_RANGE', '対象期間の終了日は開始日以降にしてください', 400);
    }
    if (saa_days_inclusive($startDate, $endDate) > 31) {
        json_error('TOO_LONG_RANGE', '一度に依頼できる対象期間は最大31日です', 400);
    }

    $title = saa_clean_text($body['title'] ?? 'シフト希望提出依頼', 120);
    if ($title === '') {
        $title = 'シフト希望提出依頼';
    }
    $message = saa_clean_text($body['message'] ?? '', 1000);
    if ($message === '') {
        $message = '対象期間のシフト希望を期限までに提出してください。';
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_availability_announcements
            (id, tenant_id, store_id, target_start_date, target_end_date,
             due_date, title, message, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $tenantId,
        $storeId,
        $startDate,
        $endDate,
        $dueDate,
        $title,
        $message,
        'active',
        $user['user_id'],
    ]);

    write_audit_log(
        $pdo,
        $user,
        $storeId,
        'shift_availability_announcement_create',
        'shift_availability_announcement',
        $id,
        null,
        [
            'target_start_date' => $startDate,
            'target_end_date' => $endDate,
            'due_date' => $dueDate,
            'title' => $title,
        ]
    );

    json_response([
        'announcement' => [
            'id' => $id,
            'target_start_date' => $startDate,
            'target_end_date' => $endDate,
            'due_date' => $dueDate,
            'title' => $title,
            'message' => $message,
            'status' => 'active',
        ],
    ]);
}

if ($method === 'PATCH') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM shift_availability_announcements
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$id, $tenantId, $storeId]);
    $old = $stmt->fetch();
    if (!$old) {
        json_error('NOT_FOUND', '希望提出依頼が見つかりません', 404);
    }

    $body = get_json_body();
    $action = $body['action'] ?? '';

    if ($action === 'mark_read') {
        $readId = generate_uuid();
        $stmtRead = $pdo->prepare(
            'INSERT INTO shift_availability_announcement_reads
                (id, tenant_id, store_id, announcement_id, user_id, read_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE read_at = NOW()'
        );
        $stmtRead->execute([$readId, $tenantId, $storeId, $id, $user['user_id']]);
        json_response(['marked_read' => true]);
    }

    require_role('manager');
    $statusMap = [
        'close' => 'closed',
        'cancel' => 'cancelled',
        'reopen' => 'active',
    ];
    if (!isset($statusMap[$action])) {
        json_error('INVALID_ACTION', 'action は mark_read / close / cancel / reopen のいずれかです', 400);
    }

    $newStatus = $statusMap[$action];
    $stmtUpdate = $pdo->prepare(
        'UPDATE shift_availability_announcements
         SET status = ?, updated_at = NOW()
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmtUpdate->execute([$newStatus, $id, $tenantId, $storeId]);

    write_audit_log(
        $pdo,
        $user,
        $storeId,
        'shift_availability_announcement_' . $action,
        'shift_availability_announcement',
        $id,
        ['status' => $old['status']],
        ['status' => $newStatus]
    );

    json_response(['updated' => true, 'status' => $newStatus]);
}
