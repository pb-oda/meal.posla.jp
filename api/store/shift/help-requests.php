<?php
/**
 * ヘルプ要請 API（L-3 Phase 3）
 *
 * GET    ?store_id=xxx                        → 送信/受信の要請一覧
 * GET    ?action=list-stores&store_id=xxx     → 同一テナント内の他店舗一覧
 * GET    ?action=list-staff&store_id=xxx      → 指定店舗のスタッフ一覧
 * POST   body:{...}                           → ヘルプ要請作成
 * PATCH  ?id=xxx body:{action,assigned_user_ids,...} → 承認/却下/キャンセル
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST', 'PATCH']);
$user   = require_auth();

$pdo      = get_db();
$tenantId = $user['tenant_id'];

// プランチェック: enterprise のみ
if (!check_plan_feature($pdo, $tenantId, 'shift_help_request')) {
    json_error('PLAN_REQUIRED', 'この機能はenterpriseプランで利用可能です', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

// =============================================
// GET: 要請一覧 / 店舗一覧 / スタッフ一覧
// =============================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    // ── 同一テナント内の他店舗一覧 ──
    if ($action === 'list-stores') {
        $stmt = $pdo->prepare(
            'SELECT id, name FROM stores
             WHERE tenant_id = ? AND id != ? AND is_active = 1
             ORDER BY name'
        );
        $stmt->execute([$tenantId, $storeId]);
        json_response(['stores' => $stmt->fetchAll()]);
    }

    // ── 指定店舗のスタッフ一覧（承認ダイアログ用）──
    if ($action === 'list-staff') {
        $targetStoreId = $_GET['target_store_id'] ?? '';
        if ($targetStoreId === '') {
            json_error('MISSING_PARAM', 'target_store_id が必要です', 400);
        }
        // テナント内の店舗か検証
        $stmtCheck = $pdo->prepare(
            'SELECT id FROM stores WHERE id = ? AND tenant_id = ? AND is_active = 1'
        );
        $stmtCheck->execute([$targetStoreId, $tenantId]);
        if (!$stmtCheck->fetch()) {
            json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
        }

        $stmtStaff = $pdo->prepare(
            'SELECT u.id, u.display_name, u.username
             FROM users u
             JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
             WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role = ?
             ORDER BY u.display_name'
        );
        $stmtStaff->execute([$targetStoreId, $tenantId, 'staff']);
        json_response(['staff' => $stmtStaff->fetchAll()]);
    }

    // ── 送信/受信の要請一覧 ──
    require_role('manager');

    // オプションフィルタ
    $statusFilter = $_GET['status'] ?? '';
    $dateFrom     = $_GET['date_from'] ?? '';
    $dateTo       = $_GET['date_to'] ?? '';

    $filterSql    = '';
    $filterParams = [];
    if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
        $filterSql .= ' AND shr.status = ?';
        $filterParams[] = $statusFilter;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $filterSql .= ' AND shr.requested_date >= ?';
        $filterParams[] = $dateFrom;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $filterSql .= ' AND shr.requested_date <= ?';
        $filterParams[] = $dateTo;
    }

    // 送信（from_store = 自店舗）
    $stmtSent = $pdo->prepare(
        'SELECT shr.*, fs.name AS from_store_name, ts.name AS to_store_name,
                u.display_name AS requesting_user_name
         FROM shift_help_requests shr
         JOIN stores fs ON fs.id = shr.from_store_id
         JOIN stores ts ON ts.id = shr.to_store_id
         JOIN users u ON u.id = shr.requesting_user_id
         WHERE shr.tenant_id = ? AND shr.from_store_id = ?' . $filterSql . '
         ORDER BY shr.created_at DESC'
    );
    $stmtSent->execute(array_merge([$tenantId, $storeId], $filterParams));
    $sentRows = $stmtSent->fetchAll();

    // 受信（to_store = 自店舗）
    $stmtReceived = $pdo->prepare(
        'SELECT shr.*, fs.name AS from_store_name, ts.name AS to_store_name,
                u.display_name AS requesting_user_name
         FROM shift_help_requests shr
         JOIN stores fs ON fs.id = shr.from_store_id
         JOIN stores ts ON ts.id = shr.to_store_id
         JOIN users u ON u.id = shr.requesting_user_id
         WHERE shr.tenant_id = ? AND shr.to_store_id = ?' . $filterSql . '
         ORDER BY shr.created_at DESC'
    );
    $stmtReceived->execute(array_merge([$tenantId, $storeId], $filterParams));
    $receivedRows = $stmtReceived->fetchAll();

    // 各要請に assigned_staff を付与
    $allIds = [];
    foreach ($sentRows as $r) { $allIds[] = $r['id']; }
    foreach ($receivedRows as $r) { $allIds[] = $r['id']; }

    $staffMap = []; // request_id => [{user_id, display_name, username}]
    if (count($allIds) > 0) {
        $ph = implode(',', array_fill(0, count($allIds), '?'));
        $stmtStaff = $pdo->prepare(
            "SELECT sha.help_request_id, sha.user_id, sha.shift_assignment_id,
                    u.display_name, u.username
             FROM shift_help_assignments sha
             JOIN users u ON u.id = sha.user_id
             WHERE sha.help_request_id IN ({$ph})
             ORDER BY u.display_name"
        );
        $stmtStaff->execute($allIds);
        foreach ($stmtStaff->fetchAll() as $s) {
            $staffMap[$s['help_request_id']][] = [
                'user_id'             => $s['user_id'],
                'display_name'        => $s['display_name'],
                'username'            => $s['username'],
                'shift_assignment_id' => $s['shift_assignment_id'],
            ];
        }
    }

    $formatRows = function($rows) use ($staffMap) {
        $result = [];
        foreach ($rows as $r) {
            $r['assigned_staff'] = isset($staffMap[$r['id']]) ? $staffMap[$r['id']] : [];
            $r['requested_staff_count'] = (int)$r['requested_staff_count'];
            $result[] = $r;
        }
        return $result;
    };

    json_response([
        'sent'     => $formatRows($sentRows),
        'received' => $formatRows($receivedRows),
    ]);
}

// ── ここから書き込み系: manager以上 ──
require_role('manager');

// =============================================
// POST: ヘルプ要請作成
// =============================================
if ($method === 'POST') {
    $body = get_json_body();

    $toStoreId          = $body['to_store_id'] ?? '';
    $requestedDate      = $body['requested_date'] ?? '';
    $startTime          = $body['start_time'] ?? '';
    $endTime            = $body['end_time'] ?? '';
    $requestedStaffCount = isset($body['requested_staff_count']) ? (int)$body['requested_staff_count'] : 1;
    $roleHint           = isset($body['role_hint']) && $body['role_hint'] !== '' ? $body['role_hint'] : null;
    $note               = isset($body['note']) ? trim($body['note']) : null;

    // バリデーション
    if ($toStoreId === '') {
        json_error('MISSING_TO_STORE', '送信先店舗を指定してください', 400);
    }
    if ($toStoreId === $storeId) {
        json_error('SAME_STORE', '自店舗には要請できません', 400);
    }

    // to_store がテナント内か検証
    $stmtToStore = $pdo->prepare(
        'SELECT id FROM stores WHERE id = ? AND tenant_id = ? AND is_active = 1'
    );
    $stmtToStore->execute([$toStoreId, $tenantId]);
    if (!$stmtToStore->fetch()) {
        json_error('INVALID_STORE', '送信先店舗が見つかりません', 400);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
        json_error('INVALID_DATE', '日付を YYYY-MM-DD 形式で指定してください', 400);
    }
    if ($requestedDate < date('Y-m-d')) {
        json_error('PAST_DATE', '過去の日付は指定できません', 400);
    }

    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) ||
        !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        json_error('INVALID_TIME', '時刻は HH:MM 形式で指定してください', 400);
    }
    if ($startTime >= $endTime) {
        json_error('INVALID_TIME_RANGE', '終了時刻は開始時刻より後にしてください', 400);
    }

    if ($requestedStaffCount < 1 || $requestedStaffCount > 10) {
        json_error('INVALID_COUNT', '必要人数は1〜10の範囲で指定してください', 400);
    }

    if ($roleHint !== null && !in_array($roleHint, ['kitchen', 'hall'], true)) {
        json_error('INVALID_ROLE', 'role_hint は kitchen / hall のいずれかです', 400);
    }

    $id = generate_uuid();

    $stmt = $pdo->prepare(
        'INSERT INTO shift_help_requests
            (id, tenant_id, from_store_id, to_store_id, requested_date,
             start_time, end_time, requested_staff_count, role_hint,
             status, requesting_user_id, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?)'
    );
    $stmt->execute([
        $id, $tenantId, $storeId, $toStoreId, $requestedDate,
        $startTime, $endTime, $requestedStaffCount, $roleHint,
        $user['user_id'], $note,
    ]);

    // 作成した要請を返却
    $stmtFetch = $pdo->prepare(
        'SELECT shr.*, fs.name AS from_store_name, ts.name AS to_store_name
         FROM shift_help_requests shr
         JOIN stores fs ON fs.id = shr.from_store_id
         JOIN stores ts ON ts.id = shr.to_store_id
         WHERE shr.id = ?'
    );
    $stmtFetch->execute([$id]);
    $created = $stmtFetch->fetch();
    $created['assigned_staff'] = [];
    $created['requested_staff_count'] = (int)$created['requested_staff_count'];

    json_response($created, 201);
}

// =============================================
// PATCH: 承認 / 却下 / キャンセル
// =============================================
if ($method === 'PATCH') {
    $requestId = $_GET['id'] ?? '';
    if ($requestId === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $body   = get_json_body();
    $action = $body['action'] ?? '';

    // 要請を取得
    $stmtReq = $pdo->prepare(
        'SELECT * FROM shift_help_requests
         WHERE id = ? AND tenant_id = ?'
    );
    $stmtReq->execute([$requestId, $tenantId]);
    $request = $stmtReq->fetch();
    if (!$request) {
        json_error('NOT_FOUND', 'ヘルプ要請が見つかりません', 404);
    }

    // ── 承認 ──
    if ($action === 'approve') {
        // to_store のマネージャーのみ
        if ($request['to_store_id'] !== $storeId) {
            json_error('FORBIDDEN', '承認は要請先の店舗マネージャーのみ可能です', 403);
        }
        if ($request['status'] !== 'pending') {
            json_error('INVALID_STATUS', 'この要請は既に処理済みです', 400);
        }

        $assignedUserIds = $body['assigned_user_ids'] ?? [];
        $responseNote    = isset($body['response_note']) ? trim($body['response_note']) : null;

        if (empty($assignedUserIds)) {
            json_error('NO_STAFF', '派遣するスタッフを選択してください', 400);
        }

        // スタッフが to_store に所属しているか検証
        $phUsers = implode(',', array_fill(0, count($assignedUserIds), '?'));
        $stmtCheckUsers = $pdo->prepare(
            "SELECT us.user_id FROM user_stores us
             WHERE us.store_id = ? AND us.user_id IN ({$phUsers})"
        );
        $stmtCheckUsers->execute(array_merge([$storeId], $assignedUserIds));
        $validUserIds = array_column($stmtCheckUsers->fetchAll(), 'user_id');

        $invalidUsers = array_diff($assignedUserIds, $validUserIds);
        if (count($invalidUsers) > 0) {
            json_error('INVALID_USERS', '一部のスタッフがこの店舗に所属していません', 400);
        }

        // from_store の break_minutes デフォルトを取得
        $stmtBreak = $pdo->prepare(
            'SELECT default_break_minutes FROM shift_settings
             WHERE store_id = ? AND tenant_id = ?'
        );
        $stmtBreak->execute([$request['from_store_id'], $tenantId]);
        $breakRow = $stmtBreak->fetch();
        $defaultBreak = $breakRow ? (int)$breakRow['default_break_minutes'] : 60;

        // 重複シフトチェック（警告用）
        $warnings = [];
        $phWarn = implode(',', array_fill(0, count($assignedUserIds), '?'));
        $stmtExist = $pdo->prepare(
            "SELECT sa.user_id, u.display_name
             FROM shift_assignments sa
             JOIN users u ON u.id = sa.user_id
             WHERE sa.tenant_id = ?
               AND sa.user_id IN ({$phWarn})
               AND sa.shift_date = ?
             GROUP BY sa.user_id, u.display_name"
        );
        $stmtExist->execute(array_merge([$tenantId], $assignedUserIds, [$request['requested_date']]));
        foreach ($stmtExist->fetchAll() as $w) {
            $warnings[] = ($w['display_name'] ?: $w['user_id']) . ' は ' . $request['requested_date'] . ' に既にシフトがあります';
        }

        // トランザクション
        $pdo->beginTransaction();
        try {
            // 1. status を approved に更新
            $stmtUpdate = $pdo->prepare(
                'UPDATE shift_help_requests
                 SET status = \'approved\', responding_user_id = ?, response_note = ?
                 WHERE id = ? AND tenant_id = ?'
            );
            $stmtUpdate->execute([$user['user_id'], $responseNote, $requestId, $tenantId]);

            // 2-6. 各スタッフの処理
            $stmtInsertHA = $pdo->prepare(
                'INSERT INTO shift_help_assignments (id, help_request_id, user_id, shift_assignment_id)
                 VALUES (?, ?, ?, ?)'
            );
            $stmtInsertSA = $pdo->prepare(
                'INSERT INTO shift_assignments
                    (id, tenant_id, store_id, user_id, shift_date,
                     start_time, end_time, break_minutes, role_type,
                     status, help_request_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'published\', ?, ?)'
            );
            $stmtUpdateHA = $pdo->prepare(
                'UPDATE shift_help_assignments SET shift_assignment_id = ? WHERE id = ?'
            );

            foreach ($assignedUserIds as $uid) {
                $haId = generate_uuid();
                $saId = generate_uuid();

                // shift_help_assignments INSERT
                $stmtInsertHA->execute([$haId, $requestId, $uid, null]);

                // shift_assignments INSERT（from_store に作成）
                $stmtInsertSA->execute([
                    $saId, $tenantId, $request['from_store_id'], $uid,
                    $request['requested_date'],
                    $request['start_time'], $request['end_time'],
                    $defaultBreak, $request['role_hint'],
                    $requestId, $user['user_id'],
                ]);

                // shift_help_assignments.shift_assignment_id を更新
                $stmtUpdateHA->execute([$saId, $haId]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
            error_log('[H-14][api/store/shift/help-requests.php] approve_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
            json_error('DB_ERROR', '承認処理に失敗しました', 500);
        }

        // 更新後の要請を返却
        $stmtResult = $pdo->prepare(
            'SELECT shr.*, fs.name AS from_store_name, ts.name AS to_store_name
             FROM shift_help_requests shr
             JOIN stores fs ON fs.id = shr.from_store_id
             JOIN stores ts ON ts.id = shr.to_store_id
             WHERE shr.id = ?'
        );
        $stmtResult->execute([$requestId]);
        $result = $stmtResult->fetch();
        $result['requested_staff_count'] = (int)$result['requested_staff_count'];
        $result['warnings'] = $warnings;

        json_response($result);
    }

    // ── 却下 ──
    if ($action === 'reject') {
        if ($request['to_store_id'] !== $storeId) {
            json_error('FORBIDDEN', '却下は要請先の店舗マネージャーのみ可能です', 403);
        }
        if ($request['status'] !== 'pending') {
            json_error('INVALID_STATUS', 'この要請は既に処理済みです', 400);
        }

        $responseNote = isset($body['response_note']) ? trim($body['response_note']) : null;

        $stmtUpdate = $pdo->prepare(
            'UPDATE shift_help_requests
             SET status = \'rejected\', responding_user_id = ?, response_note = ?
             WHERE id = ? AND tenant_id = ?'
        );
        $stmtUpdate->execute([$user['user_id'], $responseNote, $requestId, $tenantId]);

        json_response(['status' => 'rejected']);
    }

    // ── キャンセル ──
    if ($action === 'cancel') {
        if ($request['from_store_id'] !== $storeId) {
            json_error('FORBIDDEN', 'キャンセルは要請元の店舗マネージャーのみ可能です', 403);
        }
        if ($request['status'] !== 'pending') {
            json_error('INVALID_STATUS', 'pending 以外の要請はキャンセルできません', 400);
        }

        $stmtUpdate = $pdo->prepare(
            'UPDATE shift_help_requests
             SET status = \'cancelled\'
             WHERE id = ? AND tenant_id = ?'
        );
        $stmtUpdate->execute([$requestId, $tenantId]);

        json_response(['status' => 'cancelled']);
    }

    json_error('INVALID_ACTION', 'action は approve / reject / cancel のいずれかです', 400);
}
