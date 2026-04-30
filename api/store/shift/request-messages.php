<?php
/**
 * シフト申請メッセージ API
 *
 * GET  ?store_id=xxx&request_id=xxx
 * POST body:{request_id,message}
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

function srm_is_manager($user)
{
    return in_array($user['role'], ['manager', 'owner'], true);
}

function srm_load_request($pdo, $tenantId, $storeId, $requestId)
{
    $stmt = $pdo->prepare(
        'SELECT sr.*, sa.user_id AS current_assignment_user_id,
                sa.shift_date, sa.start_time, sa.end_time, sa.role_type,
                req.display_name AS requester_name, req.username AS requester_username,
                cand.display_name AS candidate_name, cand.username AS candidate_username,
                repl.display_name AS replacement_name, repl.username AS replacement_username
         FROM shift_swap_requests sr
         JOIN shift_assignments sa ON sa.id = sr.shift_assignment_id
         JOIN users req ON req.id = sr.requester_user_id
         LEFT JOIN users cand ON cand.id = sr.candidate_user_id
         LEFT JOIN users repl ON repl.id = sr.replacement_user_id
         WHERE sr.id = ? AND sr.tenant_id = ? AND sr.store_id = ?
         LIMIT 1'
    );
    $stmt->execute([$requestId, $tenantId, $storeId]);
    return $stmt->fetch();
}

function srm_can_access_request($user, $request)
{
    if (srm_is_manager($user)) {
        return true;
    }
    $userId = $user['user_id'];
    return $request['requester_user_id'] === $userId ||
        $request['candidate_user_id'] === $userId ||
        $request['replacement_user_id'] === $userId ||
        $request['current_assignment_user_id'] === $userId;
}

function srm_staff_participants($request)
{
    $ids = [];
    foreach (['requester_user_id', 'candidate_user_id', 'replacement_user_id', 'current_assignment_user_id'] as $key) {
        if (!empty($request[$key])) {
            $ids[$request[$key]] = true;
        }
    }
    return array_keys($ids);
}

function srm_manager_participants($pdo, $tenantId, $storeId)
{
    $stmt = $pdo->prepare(
        "SELECT u.id
         FROM users u
         JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
         WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role IN ('manager','owner')"
    );
    $stmt->execute([$storeId, $tenantId]);
    return array_column($stmt->fetchAll(), 'id');
}

function srm_excerpt($text)
{
    $text = trim((string)$text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 180);
    }
    return substr($text, 0, 180);
}

function srm_notify_users($pdo, $tenantId, $storeId, $requestId, $userIds, $senderId, $type, $title, $body)
{
    $stmt = $pdo->prepare(
        'INSERT INTO shift_request_notifications
            (id, tenant_id, store_id, request_id, user_id, notification_type, title, body)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $seen = [];
    foreach ($userIds as $userId) {
        if (!$userId || $userId === $senderId || isset($seen[$userId])) {
            continue;
        }
        $seen[$userId] = true;
        $stmt->execute([generate_uuid(), $tenantId, $storeId, $requestId, $userId, $type, $title, $body]);
    }
}

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST']);
$user = require_auth();

$pdo = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

if ($method === 'GET') {
    $requestId = $_GET['request_id'] ?? '';
    if ($requestId === '') {
        json_error('MISSING_REQUEST', 'request_id が必要です', 400);
    }
    $request = srm_load_request($pdo, $tenantId, $storeId, $requestId);
    if (!$request) {
        json_error('NOT_FOUND', '申請が見つかりません', 404);
    }
    if (!srm_can_access_request($user, $request)) {
        json_error('FORBIDDEN', 'この申請は閲覧できません', 403);
    }

    $stmt = $pdo->prepare(
        'SELECT m.id, m.sender_user_id, m.message_type, m.message_body, m.created_at,
                u.display_name, u.username, u.role
         FROM shift_request_messages m
         JOIN users u ON u.id = m.sender_user_id
         WHERE m.tenant_id = ? AND m.store_id = ? AND m.request_id = ?
         ORDER BY m.created_at ASC, m.id ASC
         LIMIT 200'
    );
    $stmt->execute([$tenantId, $storeId, $requestId]);
    $messages = $stmt->fetchAll();

    $stmtRead = $pdo->prepare(
        'UPDATE shift_request_notifications
         SET is_read = 1, read_at = NOW()
         WHERE tenant_id = ? AND store_id = ? AND request_id = ? AND user_id = ? AND is_read = 0'
    );
    $stmtRead->execute([$tenantId, $storeId, $requestId, $user['user_id']]);

    json_response([
        'request' => $request,
        'messages' => $messages,
    ]);
}

if ($method === 'POST') {
    $body = get_json_body();
    $requestId = $body['request_id'] ?? '';
    $message = trim((string)($body['message'] ?? ''));
    if ($requestId === '') {
        json_error('MISSING_REQUEST', 'request_id が必要です', 400);
    }
    if ($message === '') {
        json_error('MISSING_MESSAGE', 'メッセージを入力してください', 400);
    }
    if (function_exists('mb_strlen') ? mb_strlen($message) > 1000 : strlen($message) > 3000) {
        json_error('MESSAGE_TOO_LONG', 'メッセージは1000文字以内で入力してください', 400);
    }

    $request = srm_load_request($pdo, $tenantId, $storeId, $requestId);
    if (!$request) {
        json_error('NOT_FOUND', '申請が見つかりません', 404);
    }
    if (!srm_can_access_request($user, $request)) {
        json_error('FORBIDDEN', 'この申請には投稿できません', 403);
    }

    $messageId = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_request_messages
            (id, tenant_id, store_id, request_id, sender_user_id, message_type, message_body)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$messageId, $tenantId, $storeId, $requestId, $user['user_id'], 'message', $message]);

    $recipients = srm_staff_participants($request);
    if (!srm_is_manager($user)) {
        $recipients = array_merge($recipients, srm_manager_participants($pdo, $tenantId, $storeId));
    }
    $title = srm_is_manager($user) ? '店長から返信あり' : 'スタッフから返信あり';
    srm_notify_users($pdo, $tenantId, $storeId, $requestId, $recipients, $user['user_id'], 'message', $title, srm_excerpt($message));

    write_audit_log($pdo, $user, $storeId, 'shift_request_message_create', 'shift_swap_request', $requestId, null, [
        'message_id' => $messageId,
    ]);

    json_response(['id' => $messageId], 201);
}
