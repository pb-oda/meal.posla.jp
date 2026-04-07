<?php
/**
 * アクティブセッション管理 API（S-3）
 *
 * GET    /api/store/active-sessions.php?store_id=xxx  — アクティブセッション一覧
 * DELETE /api/store/active-sessions.php               — セッション無効化
 *
 * manager 以上のみアクセス可能
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM user_sessions LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'user_sessions テーブルが未作成です。migration-g2-user-sessions.sql を実行してください。', 500);
}

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();

    // アクティブセッションを取得（ownerはテナント全体、それ以外は店舗のスタッフのみ）
    if ($user['role'] === 'owner') {
        $stmt = $pdo->prepare(
            'SELECT us.id, us.user_id, u.username, u.display_name, u.role,
                    us.session_id, us.device_label, us.ip_address,
                    us.login_at, us.last_active_at
             FROM user_sessions us
             JOIN users u ON u.id = us.user_id
             WHERE us.tenant_id = ? AND us.is_active = 1
             ORDER BY us.last_active_at DESC'
        );
        $stmt->execute([$tenantId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT us.id, us.user_id, u.username, u.display_name, u.role,
                    us.session_id, us.device_label, us.ip_address,
                    us.login_at, us.last_active_at
             FROM user_sessions us
             JOIN users u ON u.id = us.user_id
             JOIN user_stores ust ON ust.user_id = us.user_id AND ust.store_id = ?
             WHERE us.tenant_id = ? AND us.is_active = 1
             ORDER BY us.last_active_at DESC'
        );
        $stmt->execute([$storeId, $tenantId]);
    }
    $sessions = $stmt->fetchAll();

    json_response(['sessions' => $sessions]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = get_json_body();
    $sessionId = $data['session_id'] ?? null;

    if (!$sessionId) json_error('MISSING_FIELDS', 'session_id は必須です', 400);

    // テナント境界チェック
    $stmt = $pdo->prepare('SELECT id FROM user_sessions WHERE session_id = ? AND tenant_id = ?');
    $stmt->execute([$sessionId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'セッションが見つかりません', 404);

    $pdo->prepare('UPDATE user_sessions SET is_active = 0 WHERE session_id = ? AND tenant_id = ?')
        ->execute([$sessionId, $tenantId]);

    json_response(['ok' => true]);
}
