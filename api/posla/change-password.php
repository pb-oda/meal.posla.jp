<?php
/**
 * P1-5: POSLA管理者 自己パスワード変更API
 *
 * POST /api/posla/change-password.php
 * Body: { "current_password": "...", "new_password": "..." }
 *
 * - 現セッションは維持
 * - P1-6d: 現セッション以外の posla_admin_sessions レコードを削除
 * - POSLA側に audit_log 運用が無いため、error_log で簡易記録
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../lib/password-policy.php';

require_method(['POST']);

$admin = require_posla_admin();

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

$stmt = $pdo->prepare('SELECT password_hash FROM posla_admins WHERE id = ?');
$stmt->execute([$admin['admin_id']]);
$row = $stmt->fetch();

if (!$row) {
    json_error('ADMIN_NOT_FOUND', '管理者が見つかりません', 404);
}

if (!password_verify($currentPassword, $row['password_hash'])) {
    json_error('INVALID_CURRENT_PASSWORD', '現在のパスワードが正しくありません', 401);
}

if ($currentPassword === $newPassword) {
    json_error('SAME_PASSWORD', '新しいパスワードは現在のパスワードと異なるものを設定してください', 400);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

$pdo->prepare('UPDATE posla_admins SET password_hash = ? WHERE id = ?')
    ->execute([$newHash, $admin['admin_id']]);

// P1-6d: 現セッション以外の posla_admin_sessions レコードを削除
// （攻撃者がセッション窃取済みの場合の乗っ取り防止）
// 失敗してもパスワード変更処理は止めない（既存挙動を壊さない）
try {
    $currentSessionId = session_id();
    $delStmt = $pdo->prepare(
        'DELETE FROM posla_admin_sessions
         WHERE admin_id = ? AND session_id != ?'
    );
    $delStmt->execute([$admin['admin_id'], $currentSessionId]);
    error_log(sprintf(
        'posla_admin sessions invalidated: admin_id=%s deleted=%d',
        $admin['admin_id'],
        $delStmt->rowCount()
    ));
} catch (Exception $e) {
    error_log('posla_admin_sessions cleanup failed: ' . $e->getMessage());
}

// POSLA側に audit_log 運用が無いため error_log で簡易記録
error_log(sprintf(
    'posla_admin password_changed: admin_id=%s email=%s ip=%s',
    $admin['admin_id'],
    $admin['email'],
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
));

json_response(['changed' => true]);
