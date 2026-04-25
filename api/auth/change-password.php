<?php
/**
 * P1-5: 自己パスワード変更API（テナントユーザー向け）
 *
 * POST /api/auth/change-password.php
 * Body: { "current_password": "...", "new_password": "..." }
 *
 * - 現セッションは維持
 * - 同ユーザーの他セッションは user_sessions.is_active = 0 で無効化
 * - 監査ログ password_changed を記録
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';
require_once __DIR__ . '/../lib/password-policy.php';

require_method(['POST']);

$user = require_auth();

$input           = get_json_body();
$currentPassword = $input['current_password'] ?? '';
$newPassword     = $input['new_password'] ?? '';

if (empty($currentPassword)) {
    json_error('MISSING_FIELDS', '現在のパスワードを入力してください', 400);
}
if (empty($newPassword)) {
    json_error('MISSING_FIELDS', '新しいパスワードを入力してください', 400);
}

validate_password_strength($newPassword);

$pdo = get_db();

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND tenant_id = ?');
$stmt->execute([$user['user_id'], $user['tenant_id']]);
$row = $stmt->fetch();

if (!$row) {
    json_error('USER_NOT_FOUND', 'ユーザーが見つかりません', 404);
}

if (!password_verify($currentPassword, $row['password_hash'])) {
    json_error('INVALID_CURRENT_PASSWORD', '現在のパスワードが正しくありません', 401);
}

if ($currentPassword === $newPassword) {
    json_error('SAME_PASSWORD', '新しいパスワードは現在のパスワードと異なるものを設定してください', 400);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?')
    ->execute([$newHash, $user['user_id'], $user['tenant_id']]);

// 他セッション無効化（user_sessions テーブル未作成環境でも落ちないように try-catch）
try {
    $currentSessionId = session_id();
    if ($currentSessionId) {
        $pdo->prepare(
            'UPDATE user_sessions SET is_active = 0
             WHERE user_id = ? AND tenant_id = ? AND session_id <> ?'
        )->execute([$user['user_id'], $user['tenant_id'], $currentSessionId]);
    }
} catch (PDOException $e) {
    // user_sessions テーブル未作成時はスキップ
    error_log('[P1-12][api/auth/change-password.php:68] password_change_invalidate_others: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// 監査ログ記録
$auditUser = [
    'user_id'   => $user['user_id'],
    'tenant_id' => $user['tenant_id'],
    'username'  => $user['username'] ?? '',
    'role'      => $user['role'] ?? '',
];
write_audit_log($pdo, $auditUser, null, 'password_changed', 'user', $user['user_id'], null, null, null);

json_response(['changed' => true]);
