<?php
/**
 * シフト持ち場 API
 *
 * GET    ?store_id=xxx             → 持ち場一覧
 * POST   body:{code,label,...}     → 持ち場追加
 * PATCH  ?id=xxx body:{...}        → 持ち場更新/無効化
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

function shift_position_code_valid($code)
{
    return preg_match('/^[a-z0-9_-]{1,20}$/', (string)$code);
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

if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, code, label, is_active, sort_order
         FROM shift_work_positions
         WHERE tenant_id = ? AND store_id = ?
         ORDER BY is_active DESC, sort_order, label'
    );
    $stmt->execute([$tenantId, $storeId]);
    $rows = $stmt->fetchAll();

    if (count($rows) === 0) {
        $defaults = [
            ['hall', 'ホール', 10],
            ['kitchen', 'キッチン', 20],
        ];
        $stmtIns = $pdo->prepare(
            'INSERT IGNORE INTO shift_work_positions
                (id, tenant_id, store_id, code, label, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        foreach ($defaults as $d) {
            $stmtIns->execute([generate_uuid(), $tenantId, $storeId, $d[0], $d[1], $d[2]]);
        }
        $stmt->execute([$tenantId, $storeId]);
        $rows = $stmt->fetchAll();
    }

    foreach ($rows as &$r) {
        $r['is_active'] = (int)$r['is_active'];
        $r['sort_order'] = (int)$r['sort_order'];
    }
    unset($r);

    json_response(['positions' => $rows]);
}

require_role('manager');

if ($method === 'POST') {
    $body = get_json_body();
    $code = strtolower(trim($body['code'] ?? ''));
    $label = trim($body['label'] ?? '');
    $sortOrder = isset($body['sort_order']) ? (int)$body['sort_order'] : 100;

    if (!shift_position_code_valid($code)) {
        json_error('INVALID_CODE', 'code は英数字・_・- の20文字以内で入力してください', 400);
    }
    if ($label === '' || mb_strlen($label) > 50) {
        json_error('INVALID_LABEL', 'label は1〜50文字で入力してください', 400);
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_work_positions
            (id, tenant_id, store_id, code, label, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            is_active = 1,
            sort_order = VALUES(sort_order)'
    );
    $stmt->execute([$id, $tenantId, $storeId, $code, $label, $sortOrder]);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_position_save', 'shift_work_position', $id,
        null,
        ['code' => $code, 'label' => $label, 'sort_order' => $sortOrder]
    );

    json_response(['saved' => true], 201);
}

if ($method === 'PATCH') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $stmtOld = $pdo->prepare(
        'SELECT * FROM shift_work_positions
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmtOld->execute([$id, $tenantId, $storeId]);
    $old = $stmtOld->fetch();
    if (!$old) {
        json_error('NOT_FOUND', '持ち場が見つかりません', 404);
    }

    $body = get_json_body();
    $updates = [];
    $params = [];

    if (isset($body['label'])) {
        $label = trim($body['label']);
        if ($label === '' || mb_strlen($label) > 50) {
            json_error('INVALID_LABEL', 'label は1〜50文字で入力してください', 400);
        }
        $updates[] = 'label = ?';
        $params[] = $label;
    }
    if (isset($body['sort_order'])) {
        $updates[] = 'sort_order = ?';
        $params[] = (int)$body['sort_order'];
    }
    if (isset($body['is_active'])) {
        $v = (int)$body['is_active'];
        if ($v !== 0 && $v !== 1) {
            json_error('INVALID_ACTIVE', 'is_active は 0 または 1 です', 400);
        }
        $updates[] = 'is_active = ?';
        $params[] = $v;
    }

    if (empty($updates)) {
        json_error('NO_FIELDS', '更新するフィールドが指定されていません', 400);
    }

    $params[] = $id;
    $params[] = $tenantId;
    $params[] = $storeId;

    $stmt = $pdo->prepare(
        'UPDATE shift_work_positions SET ' . implode(', ', $updates) .
        ' WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmt->execute($params);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_position_update', 'shift_work_position', $id,
        $old,
        $body
    );

    json_response(['updated' => true]);
}
