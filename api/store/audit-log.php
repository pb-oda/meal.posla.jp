<?php
/**
 * 監査ログ閲覧 API（S-2 Phase 2）
 *
 * GET /api/store/audit-log.php?store_id=xxx
 *
 * フィルタ: action, user_id, from, to
 * ページング: limit (default 50, max 200), offset
 *
 * manager 以上のみアクセス可能
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET']);
$user = require_role('manager');
$pdo = get_db();

$storeId = require_store_param();
require_store_access($storeId);
$tenantId = $user['tenant_id'];

// フィルタパラメータ
$action  = $_GET['action']  ?? null;
$userId  = $_GET['user_id'] ?? null;
$from    = $_GET['from']    ?? null;
$to      = $_GET['to']      ?? null;
$limit   = min(max((int)($_GET['limit'] ?? 50), 1), 200);
$offset  = max((int)($_GET['offset'] ?? 0), 0);

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM audit_log LIMIT 0');
} catch (PDOException $e) {
    json_response(['logs' => [], 'total' => 0, 'users' => []]);
}

// WHERE句構築
$where  = ['a.tenant_id = ?'];
$params = [$tenantId];

// 店舗フィルタ: owner は全店舗、manager は自店舗 + 自分のテナントレベル操作のみ
if ($user['role'] !== 'owner') {
    $where[]  = '(a.store_id = ? OR (a.store_id IS NULL AND a.user_id = ?))';
    $params[] = $storeId;
    $params[] = $user['user_id'];
}

if ($action) {
    $where[]  = 'a.action = ?';
    $params[] = $action;
}
if ($userId) {
    $where[]  = 'a.user_id = ?';
    $params[] = $userId;
}
if ($from) {
    $where[]  = 'a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to) {
    $where[]  = 'a.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

$whereClause = implode(' AND ', $where);

// 件数取得
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a WHERE $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// データ取得（LIMIT/OFFSET は整数キャスト済みなので直接埋め込み）
$sql = "SELECT a.id, a.user_id, a.username, a.role, a.action, a.entity_type, a.entity_id,
               a.old_value, a.new_value, a.reason, a.ip_address, a.created_at
        FROM audit_log a
        WHERE $whereClause
        ORDER BY a.created_at DESC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// ユーザー一覧（フィルタドロップダウン用 — usersテーブルから取得）
if ($user['role'] === 'owner') {
    // owner はテナント全スタッフ
    $usersStmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.username, u.display_name
         FROM users u
         WHERE u.tenant_id = ? AND u.is_active = 1
         ORDER BY u.username"
    );
    $usersStmt->execute([$tenantId]);
} else {
    // manager/staff は自店舗のスタッフのみ
    $usersStmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.username, u.display_name
         FROM users u
         JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
         WHERE u.tenant_id = ? AND u.is_active = 1
         ORDER BY u.username"
    );
    $usersStmt->execute([$storeId, $tenantId]);
}
$users = $usersStmt->fetchAll();

json_response(['logs' => $logs, 'total' => $total, 'users' => $users]);
