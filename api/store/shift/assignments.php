<?php
/**
 * シフト割当 API（L-3）
 *
 * GET    ?store_id=xxx&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD  → 週間シフト一覧
 * POST   body:{...}                                                → シフト割当作成
 * PATCH  ?id=xxx body:{...}                                        → シフト割当更新
 * DELETE ?id=xxx&store_id=xxx                                      → シフト割当削除
 * POST   ?action=publish   body:{store_id,start_date,end_date}     → ドラフト→公開
 * POST   ?action=apply-template  body:{...}                        → テンプレートから一括生成
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

function shift_assignment_role_allowed($pdo, $tenantId, $storeId, $role)
{
    if ($role === null || $role === '') {
        return true;
    }
    if (!preg_match('/^[a-z0-9_-]{1,20}$/', (string)$role)) {
        return false;
    }
    if (in_array($role, ['kitchen', 'hall'], true)) {
        return true;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM shift_work_positions
         WHERE tenant_id = ? AND store_id = ? AND code = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$tenantId, $storeId, $role]);
    return (bool)$stmt->fetch();
}

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user   = require_auth();

$pdo      = get_db();
$tenantId = $user['tenant_id'];

// プランチェック
if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

// =============================================
// GET: シフト一覧（週間）
// =============================================
if ($method === 'GET') {
    $startDate = $_GET['start_date'] ?? '';
    $endDate   = $_GET['end_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_error('INVALID_DATE', 'start_date, end_date を YYYY-MM-DD 形式で指定してください', 400);
    }

    // staff は自分のシフトのみ
    if ($user['role'] === 'staff') {
        $stmt = $pdo->prepare(
            'SELECT sa.id, sa.user_id, sa.shift_date, sa.start_time, sa.end_time,
                    sa.break_minutes, sa.role_type, sa.status, sa.note, sa.created_by,
                    sa.help_request_id,
                    u.display_name, u.username,
                    helper_store.name AS helper_store_name
             FROM shift_assignments sa
             JOIN users u ON u.id = sa.user_id
             LEFT JOIN shift_help_requests shr ON shr.id = sa.help_request_id
             LEFT JOIN stores helper_store ON helper_store.id = shr.to_store_id
             WHERE sa.tenant_id = ? AND sa.store_id = ?
               AND sa.shift_date BETWEEN ? AND ?
               AND sa.user_id = ?
             ORDER BY sa.shift_date, sa.start_time'
        );
        $stmt->execute([$tenantId, $storeId, $startDate, $endDate, $user['user_id']]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT sa.id, sa.user_id, sa.shift_date, sa.start_time, sa.end_time,
                    sa.break_minutes, sa.role_type, sa.status, sa.note, sa.created_by,
                    sa.help_request_id,
                    u.display_name, u.username,
                    helper_store.name AS helper_store_name
             FROM shift_assignments sa
             JOIN users u ON u.id = sa.user_id
             LEFT JOIN shift_help_requests shr ON shr.id = sa.help_request_id
             LEFT JOIN stores helper_store ON helper_store.id = shr.to_store_id
             WHERE sa.tenant_id = ? AND sa.store_id = ?
               AND sa.shift_date BETWEEN ? AND ?
             ORDER BY sa.shift_date, sa.start_time'
        );
        $stmt->execute([$tenantId, $storeId, $startDate, $endDate]);
    }

    $assignments = $stmt->fetchAll();
    foreach ($assignments as &$a) {
        $a['break_minutes'] = (int)$a['break_minutes'];
    }
    unset($a);

    // スタッフ一覧（カレンダー描画用 — staffロールのみ）
    // セキュリティ: staff ロールは自分以外のスタッフ情報を取得不可
    if ($user['role'] === 'staff') {
        $stmtUsers = $pdo->prepare(
            'SELECT u.id, u.display_name, u.username
             FROM users u
             WHERE u.id = ? AND u.tenant_id = ? AND u.is_active = 1 AND u.role = ?'
        );
        $stmtUsers->execute([$user['user_id'], $tenantId, 'staff']);
        $users = $stmtUsers->fetchAll();
    } else {
        $stmtUsers = $pdo->prepare(
            'SELECT u.id, u.display_name, u.username
             FROM users u
             JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
             WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role = ?
             ORDER BY u.display_name'
        );
        $stmtUsers->execute([$storeId, $tenantId, 'staff']);
        $users = $stmtUsers->fetchAll();
    }

    // 希望シフト（カレンダー上で参照表示用）
    // セキュリティ: staff ロールは自分の希望シフトのみ取得可
    if ($user['role'] === 'staff') {
        $stmtAvail = $pdo->prepare(
            'SELECT id, user_id, target_date, availability, preferred_start, preferred_end, note
             FROM shift_availabilities
             WHERE tenant_id = ? AND store_id = ? AND target_date BETWEEN ? AND ?
               AND user_id = ?
             ORDER BY target_date, user_id'
        );
        $stmtAvail->execute([$tenantId, $storeId, $startDate, $endDate, $user['user_id']]);
        $availabilities = $stmtAvail->fetchAll();
    } else {
        $stmtAvail = $pdo->prepare(
            'SELECT id, user_id, target_date, availability, preferred_start, preferred_end, note
             FROM shift_availabilities
             WHERE tenant_id = ? AND store_id = ? AND target_date BETWEEN ? AND ?
             ORDER BY target_date, user_id'
        );
        $stmtAvail->execute([$tenantId, $storeId, $startDate, $endDate]);
        $availabilities = $stmtAvail->fetchAll();
    }

    // L-3 Phase 2: 時給情報（カレンダー人件費リアルタイム計算用）
    $hourlyRates = ['default' => null, 'by_user' => []];
    if ($user['role'] !== 'staff') {
        $stmtDefaultRate = $pdo->prepare(
            'SELECT default_hourly_rate FROM shift_settings WHERE store_id = ? AND tenant_id = ?'
        );
        $stmtDefaultRate->execute([$storeId, $tenantId]);
        $settingsRow = $stmtDefaultRate->fetch();
        $hourlyRates['default'] = ($settingsRow && $settingsRow['default_hourly_rate'] !== null)
            ? (int)$settingsRow['default_hourly_rate'] : null;

        $stmtUserRates = $pdo->prepare(
            'SELECT user_id, hourly_rate FROM user_stores WHERE store_id = ? AND hourly_rate IS NOT NULL'
        );
        $stmtUserRates->execute([$storeId]);
        foreach ($stmtUserRates->fetchAll() as $ur) {
            $hourlyRates['by_user'][$ur['user_id']] = (int)$ur['hourly_rate'];
        }
    }

    json_response([
        'assignments'    => $assignments,
        'users'          => $users,
        'availabilities' => $availabilities,
        'hourly_rates'   => $hourlyRates,
    ]);
}

// ── ここから書き込み系: manager以上 ──
$action = $_GET['action'] ?? '';

// =============================================
// POST ?action=confirm: スタッフ本人が確定シフトを確認済みにする
// =============================================
if ($method === 'POST' && $action === 'confirm') {
    $body = get_json_body();
    $id = $body['id'] ?? ($_GET['id'] ?? '');
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $stmtOld = $pdo->prepare(
        'SELECT * FROM shift_assignments
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmtOld->execute([$id, $tenantId, $storeId]);
    $old = $stmtOld->fetch();
    if (!$old) {
        json_error('NOT_FOUND', 'シフト割当が見つかりません', 404);
    }
    if ($user['role'] === 'staff' && $old['user_id'] !== $user['user_id']) {
        json_error('FORBIDDEN', '自分のシフトのみ確認できます', 403);
    }
    if ($old['status'] === 'draft') {
        json_error('NOT_PUBLISHED', '下書きシフトは確認できません', 400);
    }

    $stmt = $pdo->prepare(
        'UPDATE shift_assignments
         SET status = \'confirmed\'
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$id, $tenantId, $storeId]);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_assignment_confirm', 'shift_assignment', $id,
        $old,
        ['status' => 'confirmed']
    );

    json_response(['confirmed' => true]);
}

// =============================================
// POST ?action=publish: ドラフト → 公開
// =============================================
if ($method === 'POST' && $action === 'publish') {
    require_role('manager');
    $body      = get_json_body();
    $startDate = $body['start_date'] ?? '';
    $endDate   = $body['end_date'] ?? '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_error('INVALID_DATE', 'start_date, end_date を YYYY-MM-DD 形式で指定してください', 400);
    }

    $stmt = $pdo->prepare(
        'UPDATE shift_assignments
         SET status = \'published\'
         WHERE tenant_id = ? AND store_id = ?
           AND shift_date BETWEEN ? AND ?
           AND status = \'draft\''
    );
    $stmt->execute([$tenantId, $storeId, $startDate, $endDate]);
    $count = $stmt->rowCount();

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_publish', 'shift_assignment', null,
        null,
        ['start_date' => $startDate, 'end_date' => $endDate, 'published_count' => $count]
    );

    json_response(['published_count' => $count]);
}

// =============================================
// POST ?action=apply-template: テンプレートから一括生成
// =============================================
if ($method === 'POST' && $action === 'apply-template') {
    require_role('manager');
    $body      = get_json_body();
    $startDate = $body['start_date'] ?? '';
    $endDate   = $body['end_date'] ?? '';
    $userAssignments = $body['user_assignments'] ?? [];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_error('INVALID_DATE', 'start_date, end_date を YYYY-MM-DD 形式で指定してください', 400);
    }
    if (empty($userAssignments)) {
        json_error('NO_ASSIGNMENTS', 'user_assignments を指定してください', 400);
    }

    // テンプレートを一括取得
    $templateIds = array_unique(array_column($userAssignments, 'template_id'));
    $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, day_of_week, start_time, end_time, role_hint
         FROM shift_templates
         WHERE id IN ({$placeholders})
           AND tenant_id = ? AND store_id = ? AND is_active = 1"
    );
    $stmt->execute(array_merge($templateIds, [$tenantId, $storeId]));
    $templates = [];
    foreach ($stmt->fetchAll() as $t) {
        $templates[$t['id']] = $t;
    }

    // 既存シフトを取得（重複チェック用）
    $stmtExist = $pdo->prepare(
        'SELECT user_id, shift_date
         FROM shift_assignments
         WHERE tenant_id = ? AND store_id = ?
           AND shift_date BETWEEN ? AND ?'
    );
    $stmtExist->execute([$tenantId, $storeId, $startDate, $endDate]);
    $existingMap = [];
    foreach ($stmtExist->fetchAll() as $e) {
        $existingMap[$e['user_id'] . '_' . $e['shift_date']] = true;
    }

    // 日付ループ
    $stmtInsert = $pdo->prepare(
        'INSERT INTO shift_assignments
            (id, tenant_id, store_id, user_id, shift_date,
             start_time, end_time, break_minutes, role_type, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, \'draft\', ?)'
    );

    $created = 0;
    $skipped = 0;
    $current = new DateTime($startDate);
    $end     = new DateTime($endDate);

    while ($current <= $end) {
        $dow  = (int)$current->format('w'); // 0=日 ... 6=土
        $date = $current->format('Y-m-d');

        foreach ($userAssignments as $ua) {
            $tplId  = $ua['template_id'] ?? '';
            $userId = $ua['user_id'] ?? '';

            if (!isset($templates[$tplId])) {
                continue;
            }
            $tpl = $templates[$tplId];

            // 曜日一致チェック
            if ((int)$tpl['day_of_week'] !== $dow) {
                continue;
            }

            // 重複チェック
            $key = $userId . '_' . $date;
            if (isset($existingMap[$key])) {
                $skipped++;
                continue;
            }

            $newId = generate_uuid();
            $stmtInsert->execute([
                $newId, $tenantId, $storeId, $userId, $date,
                $tpl['start_time'], $tpl['end_time'], $tpl['role_hint'],
                $user['user_id'],
            ]);
            $existingMap[$key] = true;
            $created++;
        }

        $current->modify('+1 day');
    }

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_template_apply', 'shift_assignment', null,
        null,
        ['start_date' => $startDate, 'end_date' => $endDate, 'created' => $created, 'skipped' => $skipped]
    );

    json_response(['created' => $created, 'skipped' => $skipped]);
}

// =============================================
// POST: シフト割当作成（単体）
// =============================================
if ($method === 'POST' && $action === '') {
    require_role('manager');
    $body = get_json_body();

    $userId    = $body['user_id'] ?? '';
    $shiftDate = $body['shift_date'] ?? '';
    $startTime = $body['start_time'] ?? '';
    $endTime   = $body['end_time'] ?? '';

    if ($userId === '') {
        json_error('MISSING_USER', 'user_id を指定してください', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDate)) {
        json_error('INVALID_DATE', 'shift_date を YYYY-MM-DD 形式で指定してください', 400);
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) ||
        !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        json_error('INVALID_TIME', '時刻は HH:MM 形式で指定してください', 400);
    }
    if ($startTime >= $endTime) {
        json_error('INVALID_TIME_RANGE', '終了時刻は開始時刻より後にしてください', 400);
    }

    $breakMinutes = isset($body['break_minutes']) ? (int)$body['break_minutes'] : 0;
    $roleType     = isset($body['role_type']) && $body['role_type'] !== '' ? $body['role_type'] : null;
    $note         = isset($body['note']) ? trim($body['note']) : null;

    if (!shift_assignment_role_allowed($pdo, $tenantId, $storeId, $roleType)) {
        json_error('INVALID_ROLE', 'role_type は登録済みの持ち場から選択してください', 400);
    }

    // ユーザーの店舗所属チェック
    $stmtCheck = $pdo->prepare(
        'SELECT 1 FROM user_stores WHERE user_id = ? AND store_id = ?'
    );
    $stmtCheck->execute([$userId, $storeId]);
    if (!$stmtCheck->fetch()) {
        // オーナーは user_stores に登録なくても全店舗アクセス可だが、
        // シフト割当対象はその店舗に所属するスタッフのみ
        $stmtOwner = $pdo->prepare(
            'SELECT role FROM users WHERE id = ? AND tenant_id = ?'
        );
        $stmtOwner->execute([$userId, $tenantId]);
        $targetUser = $stmtOwner->fetch();
        if (!$targetUser || $targetUser['role'] !== 'owner') {
            json_error('USER_NOT_IN_STORE', '指定されたユーザーはこの店舗に所属していません', 400);
        }
    }

    $id = generate_uuid();

    $stmt = $pdo->prepare(
        'INSERT INTO shift_assignments
            (id, tenant_id, store_id, user_id, shift_date,
             start_time, end_time, break_minutes, role_type, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id, $tenantId, $storeId, $userId, $shiftDate,
        $startTime, $endTime, $breakMinutes, $roleType, $note,
        $user['user_id'],
    ]);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_assignment_create', 'shift_assignment', $id,
        null,
        ['user_id' => $userId, 'shift_date' => $shiftDate, 'start_time' => $startTime, 'end_time' => $endTime]
    );

    json_response([
        'id'            => $id,
        'user_id'       => $userId,
        'shift_date'    => $shiftDate,
        'start_time'    => $startTime,
        'end_time'      => $endTime,
        'break_minutes' => $breakMinutes,
        'role_type'     => $roleType,
        'status'        => 'draft',
        'note'          => $note,
    ], 201);
}

// =============================================
// PATCH: シフト割当更新
// =============================================
if ($method === 'PATCH') {
    require_role('manager');

    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM shift_assignments
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$id, $tenantId, $storeId]);
    $old = $stmt->fetch();
    if (!$old) {
        json_error('NOT_FOUND', 'シフト割当が見つかりません', 404);
    }

    $body    = get_json_body();
    $updates = [];
    $params  = [];

    if (isset($body['start_time'])) {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $body['start_time'])) {
            json_error('INVALID_TIME', '時刻は HH:MM 形式で指定してください', 400);
        }
        $updates[] = 'start_time = ?';
        $params[]  = $body['start_time'];
    }

    if (isset($body['end_time'])) {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $body['end_time'])) {
            json_error('INVALID_TIME', '時刻は HH:MM 形式で指定してください', 400);
        }
        $updates[] = 'end_time = ?';
        $params[]  = $body['end_time'];
    }

    if (isset($body['break_minutes'])) {
        $updates[] = 'break_minutes = ?';
        $params[]  = (int)$body['break_minutes'];
    }

    if (array_key_exists('role_type', $body)) {
        $rt = $body['role_type'];
        if (!shift_assignment_role_allowed($pdo, $tenantId, $storeId, $rt)) {
            json_error('INVALID_ROLE', 'role_type は登録済みの持ち場から選択してください', 400);
        }
        $updates[] = 'role_type = ?';
        $params[]  = ($rt === '' ? null : $rt);
    }

    if (array_key_exists('note', $body)) {
        $updates[] = 'note = ?';
        $params[]  = $body['note'];
    }

    if (isset($body['status'])) {
        $s = $body['status'];
        if (!in_array($s, ['draft', 'published', 'confirmed'], true)) {
            json_error('INVALID_STATUS', 'status は draft / published / confirmed のいずれかです', 400);
        }
        $updates[] = 'status = ?';
        $params[]  = $s;
    }

    if (empty($updates)) {
        json_error('NO_FIELDS', '更新するフィールドが指定されていません', 400);
    }

    // start/end 整合性チェック
    $newStart = $body['start_time'] ?? $old['start_time'];
    $newEnd   = $body['end_time'] ?? $old['end_time'];
    if ($newStart >= $newEnd) {
        json_error('INVALID_TIME_RANGE', '終了時刻は開始時刻より後にしてください', 400);
    }

    $params[] = $id;
    $params[] = $tenantId;
    $params[] = $storeId;

    $sql = 'UPDATE shift_assignments SET ' . implode(', ', $updates) .
           ' WHERE id = ? AND tenant_id = ? AND store_id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_assignment_update', 'shift_assignment', $id,
        $old, $body
    );

    // 更新後のレコードを返す
    $stmt = $pdo->prepare(
        'SELECT id, user_id, shift_date, start_time, end_time,
                break_minutes, role_type, status, note
         FROM shift_assignments
         WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    $row = $stmt->fetch();
    $row['break_minutes'] = (int)$row['break_minutes'];

    json_response($row);
}

// =============================================
// DELETE: シフト割当削除
// =============================================
if ($method === 'DELETE') {
    require_role('manager');

    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM shift_assignments
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$id, $tenantId, $storeId]);
    $old = $stmt->fetch();
    if (!$old) {
        json_error('NOT_FOUND', 'シフト割当が見つかりません', 404);
    }

    $stmt = $pdo->prepare(
        'DELETE FROM shift_assignments
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$id, $tenantId, $storeId]);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_assignment_delete', 'shift_assignment', $id,
        $old, null
    );

    json_response(['deleted' => true]);
}
