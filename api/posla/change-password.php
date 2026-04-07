<?php
/**
 * P1-5: POSLA管理者 自己パスワード変更API
 *
 * POST /api/posla/change-password.php
 * Body: { "current_password": "...", "new_password": "..." }
 *
 * - 現セッションは維持
 * - POSLA管理者用セッション管理テーブルが存在しないため、他セッション無効化はスキップ
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

// POSLA管理者用セッション管理テーブル(posla_admin_sessions)は未実装のため
// 他セッション無効化処理はスキップ

// POSLA側に audit_log 運用が無いため error_log で簡易記録
error_log(sprintf(
    'posla_admin password_changed: admin_id=%s email=%s ip=%s',
    $admin['admin_id'],
    $admin['email'],
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
));

json_response(['changed' => true]);
