<?php
/**
 * AI シフト提案 一括適用 API（L-3 / P1-21）
 *
 * POST ?store_id=xxx
 * body: {
 *   store_id, start_date, end_date,
 *   suggestions: [ { user_id, shift_date, start_time, end_time, role_type } ],
 *   replace_existing: true
 * }
 *
 * 既存の note='AI提案' のシフトを期間で一括削除してから新規 INSERT する。
 * 手動で作成された note != 'AI提案' のシフトは削除しない。
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

start_auth_session();
handle_preflight();
require_method(['POST']);
$user = require_auth();

$pdo      = get_db();
$tenantId = $user['tenant_id'];

// プランチェック
if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'シフト管理はProプラン以上で利用できます', 403);
}

$storeId = require_store_param();
require_store_access($storeId);
require_role('manager');

$body = get_json_body();

$startDate      = $body['start_date'] ?? '';
$endDate        = $body['end_date'] ?? '';
$suggestions    = $body['suggestions'] ?? [];
$replaceExisting = !empty($body['replace_existing']);

// ── 入力バリデーション ──
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    json_error('INVALID_INPUT', 'start_date, end_date を YYYY-MM-DD 形式で指定してください', 400);
}
if ($startDate > $endDate) {
    json_error('INVALID_INPUT', 'start_date は end_date 以前である必要があります', 400);
}
if (!is_array($suggestions) || count($suggestions) === 0) {
    json_error('INVALID_INPUT', 'suggestions が空です', 400);
}

// 各 suggestion の形式を事前検証（トランザクション開始前）
foreach ($suggestions as $idx => $sg) {
    if (empty($sg['user_id'])) {
        json_error('INVALID_INPUT', 'suggestions[' . $idx . '].user_id が指定されていません', 400);
    }
    if (!isset($sg['shift_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sg['shift_date'])) {
        json_error('INVALID_INPUT', 'suggestions[' . $idx . '].shift_date を YYYY-MM-DD 形式で指定してください', 400);
    }
    if ($sg['shift_date'] < $startDate || $sg['shift_date'] > $endDate) {
        json_error('INVALID_INPUT', 'suggestions[' . $idx . '].shift_date が期間外です', 400);
    }
    if (!isset($sg['start_time']) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $sg['start_time']) ||
        !isset($sg['end_time']) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $sg['end_time'])) {
        json_error('INVALID_INPUT', 'suggestions[' . $idx . '].start_time / end_time は HH:MM 形式で指定してください', 400);
    }
    if ($sg['start_time'] >= $sg['end_time']) {
        json_error('INVALID_INPUT', 'suggestions[' . $idx . '] の終了時刻は開始時刻より後にしてください', 400);
    }
    if (isset($sg['role_type']) && $sg['role_type'] !== null && $sg['role_type'] !== '' &&
        !in_array($sg['role_type'], ['kitchen', 'hall'], true)) {
        json_error('INVALID_INPUT', 'suggestions[' . $idx . '].role_type は kitchen / hall のいずれかです', 400);
    }
}

// ── 全 user_id がこの店舗に所属しているか事前確認（マルチテナント境界） ──
$userIds = array_unique(array_map(function ($sg) { return $sg['user_id']; }, $suggestions));
$placeholders = implode(',', array_fill(0, count($userIds), '?'));

// user_stores に紐付くスタッフ
$stmtCheck = $pdo->prepare(
    "SELECT DISTINCT us.user_id
     FROM user_stores us
     JOIN users u ON u.id = us.user_id
     WHERE us.user_id IN ({$placeholders}) AND us.store_id = ? AND u.tenant_id = ?"
);
$stmtCheck->execute(array_merge($userIds, [$storeId, $tenantId]));
$validUsers = [];
foreach ($stmtCheck->fetchAll() as $r) {
    $validUsers[$r['user_id']] = true;
}

// オーナーは user_stores 登録なくても可
$missing = [];
foreach ($userIds as $uid) {
    if (!isset($validUsers[$uid])) {
        $missing[] = $uid;
    }
}
if (!empty($missing)) {
    $mPlaceholders = implode(',', array_fill(0, count($missing), '?'));
    $stmtOwner = $pdo->prepare(
        "SELECT id FROM users WHERE id IN ({$mPlaceholders}) AND tenant_id = ? AND role = 'owner'"
    );
    $stmtOwner->execute(array_merge($missing, [$tenantId]));
    foreach ($stmtOwner->fetchAll() as $r) {
        $validUsers[$r['id']] = true;
    }
}

// P1a: device ロールはシフト割当不可（KDS/レジ端末専用アカウント）
$dPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
$stmtDevice = $pdo->prepare(
    "SELECT id, display_name FROM users WHERE id IN ({$dPlaceholders}) AND tenant_id = ? AND role = 'device'"
);
$stmtDevice->execute(array_merge($userIds, [$tenantId]));
$deviceRows = $stmtDevice->fetchAll();
if (!empty($deviceRows)) {
    json_error('DEVICE_NOT_ASSIGNABLE', 'デバイスアカウント(' . $deviceRows[0]['display_name'] . ')はシフト割当の対象外です', 400);
}

foreach ($userIds as $uid) {
    if (!isset($validUsers[$uid])) {
        json_error('USER_NOT_IN_STORE', '指定されたユーザー(' . $uid . ')はこの店舗に所属していません', 400);
    }
}

// ── トランザクション ──
$deleted  = 0;
$inserted = 0;

try {
    $pdo->beginTransaction();

    if ($replaceExisting) {
        $stmtDel = $pdo->prepare(
            "DELETE FROM shift_assignments
             WHERE tenant_id = ? AND store_id = ?
               AND shift_date BETWEEN ? AND ?
               AND note = 'AI提案'"
        );
        $stmtDel->execute([$tenantId, $storeId, $startDate, $endDate]);
        $deleted = $stmtDel->rowCount();
    }

    $stmtIns = $pdo->prepare(
        'INSERT INTO shift_assignments
            (id, tenant_id, store_id, user_id, shift_date,
             start_time, end_time, break_minutes, role_type, status, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, \'draft\', \'AI提案\', ?)'
    );

    foreach ($suggestions as $sg) {
        $newId = generate_uuid();
        $roleType = (isset($sg['role_type']) && $sg['role_type'] !== '' && $sg['role_type'] !== null)
            ? $sg['role_type'] : null;
        $stmtIns->execute([
            $newId,
            $tenantId,
            $storeId,
            $sg['user_id'],
            $sg['shift_date'],
            $sg['start_time'],
            $sg['end_time'],
            $roleType,
            $user['user_id'],
        ]);
        $inserted++;
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[P1-21][apply-ai-suggestions.php] ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    json_error('DB_ERROR', 'AI提案の適用に失敗しました', 500);
}

write_audit_log(
    $pdo, $user, $storeId,
    'shift_ai_apply', 'shift_assignment', null,
    null,
    [
        'start_date'       => $startDate,
        'end_date'         => $endDate,
        'replace_existing' => $replaceExisting,
        'deleted'          => $deleted,
        'inserted'         => $inserted,
    ]
);

json_response([
    'deleted'  => $deleted,
    'inserted' => $inserted,
]);
