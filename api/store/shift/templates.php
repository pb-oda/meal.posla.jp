<?php
/**
 * シフトテンプレート API（L-3）
 *
 * GET    ?store_id=xxx           → テンプレート一覧
 * POST   body:{...}             → テンプレート作成
 * PATCH  ?id=xxx body:{...}     → テンプレート更新
 * DELETE ?id=xxx&store_id=xxx   → テンプレート削除（論理削除）
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user   = require_auth();
require_role('manager');

$pdo      = get_db();
$tenantId = $user['tenant_id'];

// プランチェック
if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'シフト管理はProプラン以上で利用できます', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

// =============================================
// GET: テンプレート一覧
// =============================================
if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, name, day_of_week, start_time, end_time,
                required_staff, role_hint, is_active, created_at, updated_at
         FROM shift_templates
         WHERE tenant_id = ? AND store_id = ? AND is_active = 1
         ORDER BY day_of_week, start_time'
    );
    $stmt->execute([$tenantId, $storeId]);
    $rows = $stmt->fetchAll();

    // 型変換
    foreach ($rows as &$r) {
        $r['day_of_week']    = (int)$r['day_of_week'];
        $r['required_staff'] = (int)$r['required_staff'];
        $r['is_active']      = (int)$r['is_active'];
    }
    unset($r);

    json_response(['templates' => $rows]);
}

// =============================================
// POST: テンプレート作成
// =============================================
if ($method === 'POST') {
    $body = get_json_body();

    // バリデーション
    $name = trim($body['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 100) {
        json_error('INVALID_NAME', 'テンプレート名は1〜100文字で入力してください', 400);
    }

    $dayOfWeek = isset($body['day_of_week']) ? (int)$body['day_of_week'] : -1;
    if ($dayOfWeek < 0 || $dayOfWeek > 6) {
        json_error('INVALID_DAY', 'day_of_week は 0（日）〜 6（土）で指定してください', 400);
    }

    $startTime = $body['start_time'] ?? '';
    $endTime   = $body['end_time'] ?? '';
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) ||
        !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        json_error('INVALID_TIME', '時刻は HH:MM 形式で指定してください', 400);
    }
    if ($startTime >= $endTime) {
        json_error('INVALID_TIME_RANGE', '終了時刻は開始時刻より後にしてください', 400);
    }

    $requiredStaff = isset($body['required_staff']) ? (int)$body['required_staff'] : 1;
    if ($requiredStaff < 1 || $requiredStaff > 20) {
        json_error('INVALID_STAFF', 'required_staff は 1〜20 で指定してください', 400);
    }

    $roleHint = isset($body['role_hint']) && $body['role_hint'] !== '' ? $body['role_hint'] : null;
    if ($roleHint !== null && !in_array($roleHint, ['kitchen', 'hall'], true)) {
        json_error('INVALID_ROLE', 'role_hint は kitchen / hall のいずれかです', 400);
    }

    $id = generate_uuid();

    $stmt = $pdo->prepare(
        'INSERT INTO shift_templates
            (id, tenant_id, store_id, name, day_of_week,
             start_time, end_time, required_staff, role_hint)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id, $tenantId, $storeId, $name, $dayOfWeek,
        $startTime, $endTime, $requiredStaff, $roleHint,
    ]);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_template_create', 'shift_template', $id,
        null,
        ['name' => $name, 'day_of_week' => $dayOfWeek, 'start_time' => $startTime, 'end_time' => $endTime, 'required_staff' => $requiredStaff, 'role_hint' => $roleHint]
    );

    json_response([
        'id'             => $id,
        'name'           => $name,
        'day_of_week'    => $dayOfWeek,
        'start_time'     => $startTime,
        'end_time'       => $endTime,
        'required_staff' => $requiredStaff,
        'role_hint'      => $roleHint,
    ], 201);
}

// =============================================
// PATCH: テンプレート更新
// =============================================
if ($method === 'PATCH') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    // 既存レコード取得
    $stmt = $pdo->prepare(
        'SELECT * FROM shift_templates
         WHERE id = ? AND tenant_id = ? AND store_id = ? AND is_active = 1'
    );
    $stmt->execute([$id, $tenantId, $storeId]);
    $old = $stmt->fetch();
    if (!$old) {
        json_error('NOT_FOUND', 'テンプレートが見つかりません', 404);
    }

    $body = get_json_body();
    $updates = [];
    $params  = [];

    // name
    if (isset($body['name'])) {
        $name = trim($body['name']);
        if ($name === '' || mb_strlen($name) > 100) {
            json_error('INVALID_NAME', 'テンプレート名は1〜100文字で入力してください', 400);
        }
        $updates[] = 'name = ?';
        $params[]  = $name;
    }

    // day_of_week
    if (isset($body['day_of_week'])) {
        $v = (int)$body['day_of_week'];
        if ($v < 0 || $v > 6) {
            json_error('INVALID_DAY', 'day_of_week は 0〜6 で指定してください', 400);
        }
        $updates[] = 'day_of_week = ?';
        $params[]  = $v;
    }

    // start_time
    if (isset($body['start_time'])) {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $body['start_time'])) {
            json_error('INVALID_TIME', '時刻は HH:MM 形式で指定してください', 400);
        }
        $updates[] = 'start_time = ?';
        $params[]  = $body['start_time'];
    }

    // end_time
    if (isset($body['end_time'])) {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $body['end_time'])) {
            json_error('INVALID_TIME', '時刻は HH:MM 形式で指定してください', 400);
        }
        $updates[] = 'end_time = ?';
        $params[]  = $body['end_time'];
    }

    // required_staff
    if (isset($body['required_staff'])) {
        $v = (int)$body['required_staff'];
        if ($v < 1 || $v > 20) {
            json_error('INVALID_STAFF', 'required_staff は 1〜20 で指定してください', 400);
        }
        $updates[] = 'required_staff = ?';
        $params[]  = $v;
    }

    // role_hint
    if (array_key_exists('role_hint', $body)) {
        $rh = $body['role_hint'];
        if ($rh !== null && $rh !== '' && !in_array($rh, ['kitchen', 'hall'], true)) {
            json_error('INVALID_ROLE', 'role_hint は kitchen / hall のいずれかです', 400);
        }
        $updates[] = 'role_hint = ?';
        $params[]  = ($rh === '' ? null : $rh);
    }

    if (empty($updates)) {
        json_error('NO_FIELDS', '更新するフィールドが指定されていません', 400);
    }

    // start/end の整合性チェック
    $newStart = $body['start_time'] ?? $old['start_time'];
    $newEnd   = $body['end_time'] ?? $old['end_time'];
    if ($newStart >= $newEnd) {
        json_error('INVALID_TIME_RANGE', '終了時刻は開始時刻より後にしてください', 400);
    }

    $params[] = $id;
    $params[] = $tenantId;
    $params[] = $storeId;

    $sql = 'UPDATE shift_templates SET ' . implode(', ', $updates) .
           ' WHERE id = ? AND tenant_id = ? AND store_id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_template_update', 'shift_template', $id,
        $old, $body
    );

    // 更新後のレコードを返す
    $stmt = $pdo->prepare(
        'SELECT id, name, day_of_week, start_time, end_time,
                required_staff, role_hint, is_active
         FROM shift_templates
         WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    $row = $stmt->fetch();
    $row['day_of_week']    = (int)$row['day_of_week'];
    $row['required_staff'] = (int)$row['required_staff'];
    $row['is_active']      = (int)$row['is_active'];

    json_response($row);
}

// =============================================
// DELETE: テンプレート論理削除
// =============================================
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM shift_templates
         WHERE id = ? AND tenant_id = ? AND store_id = ? AND is_active = 1'
    );
    $stmt->execute([$id, $tenantId, $storeId]);
    $old = $stmt->fetch();
    if (!$old) {
        json_error('NOT_FOUND', 'テンプレートが見つかりません', 404);
    }

    $stmt = $pdo->prepare(
        'UPDATE shift_templates SET is_active = 0
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$id, $tenantId, $storeId]);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_template_delete', 'shift_template', $id,
        $old, ['is_active' => 0]
    );

    json_response(['deleted' => true]);
}
