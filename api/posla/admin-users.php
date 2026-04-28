<?php
/**
 * POSLA管理者 ユーザー管理 API
 *
 * GET   /api/posla/admin-users.php
 * POST  /api/posla/admin-users.php   { email, display_name, password }
 * PATCH /api/posla/admin-users.php   { id, display_name?, is_active?, new_password? }
 * DELETE /api/posla/admin-users.php  { id, confirm_email }
 *
 * 方針:
 * - 現状の posla_admins スキーマを維持し、ロール分離は入れない
 * - 自分自身の無効化/削除と、最後の有効管理者の無効化/削除は不可
 * - 自分自身のパスワード変更は既存 change-password.php を使う
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/../lib/password-policy.php';
require_once __DIR__ . '/../lib/mail.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$pdo = get_db();

function _posla_admin_users_active_count(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM posla_admins WHERE is_active = 1');
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function _posla_admin_users_fetch_list(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT pa.id, pa.email, pa.display_name, pa.is_active, pa.last_login_at, pa.created_at, pa.updated_at,
                (SELECT COUNT(*) FROM posla_admin_sessions ps WHERE ps.admin_id = pa.id AND ps.is_active = 1) AS active_session_count
           FROM posla_admins pa
          ORDER BY pa.is_active DESC, pa.created_at ASC'
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function _posla_admin_users_audit_value(array $row): array
{
    return [
        'email' => $row['email'] ?? null,
        'display_name' => $row['display_name'] ?? null,
        'is_active' => !empty($row['is_active']) ? 1 : 0,
    ];
}

function _posla_admin_users_send_invite_mail($to, $displayName, $password, $actorName)
{
    if (!$to) {
        return false;
    }

    $subject = '【POSLA】POSLA管理者アカウントが追加されました';
    $loginUrl = app_url('/posla-admin/index.html');
    $actorLabel = $actorName ? $actorName : 'POSLA 管理者';
    $body = ($displayName ?: 'ご担当者') . " 様\n\n"
          . "POSLA管理画面の管理者アカウントが追加されました。\n\n"
          . "■ ログインURL: {$loginUrl}\n"
          . "■ メールアドレス: {$to}\n"
          . "■ 初期パスワード: {$password}\n"
          . "■ 追加者: {$actorLabel}\n\n"
          . "初回ログイン後は、右上メニューの「パスワード変更」から必ず初期パスワードを変更してください。\n\n"
          . "ご不明な点は " . APP_SUPPORT_EMAIL . " までお問い合わせください。\n\n"
          . "--\nPOSLA 運営チーム";

    $result = posla_send_mail($to, $subject, $body, [
        'from_name' => 'POSLA',
        'from_email' => APP_FROM_EMAIL,
    ]);
    return !empty($result['success']);
}

if ($method === 'GET') {
    $admins = _posla_admin_users_fetch_list($pdo);
    $summary = [
        'total' => count($admins),
        'active' => 0,
        'inactive' => 0,
    ];

    foreach ($admins as $row) {
        if ((int)$row['is_active'] === 1) {
            $summary['active']++;
        } else {
            $summary['inactive']++;
        }
    }

    json_response([
        'admins' => $admins,
        'summary' => $summary,
        'current_admin_id' => $admin['admin_id'],
    ]);
}

if ($method === 'POST') {
    $body = get_json_body();
    $email = trim((string)($body['email'] ?? ''));
    $displayName = trim((string)($body['display_name'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($email === '' || $displayName === '' || $password === '') {
        json_error('MISSING_FIELDS', 'メールアドレス、表示名、パスワードは必須です', 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('INVALID_EMAIL', 'メールアドレスが不正です', 400);
    }

    validate_password_strength($password);

    $dupStmt = $pdo->prepare('SELECT id FROM posla_admins WHERE email = ? LIMIT 1');
    $dupStmt->execute([$email]);
    if ($dupStmt->fetch()) {
        json_error('DUPLICATE_EMAIL', 'このメールアドレスは既に使用されています', 409);
    }

    $id = generate_uuid();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO posla_admins (id, email, password_hash, display_name, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $stmt->execute([$id, $email, $hash, $displayName]);

    $mailSent = _posla_admin_users_send_invite_mail(
        $email,
        $displayName,
        $password,
        $admin['display_name'] ?: $admin['email']
    );

    if (!$mailSent) {
        error_log(sprintf(
            'posla_admin invite mail failed: actor=%s target=%s email=%s ip=%s',
            $admin['admin_id'],
            $id,
            $email,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ));
    }

    error_log(sprintf(
        'posla_admin created: actor=%s target=%s email=%s ip=%s',
        $admin['admin_id'],
        $id,
        $email,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ));

    json_response([
        'admin' => [
            'id' => $id,
            'email' => $email,
            'display_name' => $displayName,
            'is_active' => 1,
        ],
        'mail_sent' => $mailSent,
        'mail_warning' => $mailSent ? null : '管理者は追加済みですが、通知メールは送信できませんでした。初期パスワードを手動で案内してください。',
    ], 201);
}

if ($method === 'PATCH') {
    $body = get_json_body();
    $id = trim((string)($body['id'] ?? ''));
    if ($id === '') {
        json_error('MISSING_ID', 'id は必須です', 400);
    }

    $stmt = $pdo->prepare('SELECT id, email, display_name, is_active FROM posla_admins WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        json_error('NOT_FOUND', '管理者が見つかりません', 404);
    }

    $sets = [];
    $params = [];

    if (array_key_exists('display_name', $body)) {
        $displayName = trim((string)$body['display_name']);
        if ($displayName === '') {
            json_error('INVALID_NAME', '表示名は空にできません', 400);
        }
        $sets[] = 'display_name = ?';
        $params[] = $displayName;
    }

    if (array_key_exists('is_active', $body)) {
        $nextActive = !empty($body['is_active']) ? 1 : 0;
        if ((int)$target['is_active'] === 1 && $nextActive === 0) {
            if ($id === $admin['admin_id']) {
                json_error('SELF_DEACTIVATE_FORBIDDEN', '自分自身はこの画面から無効化できません', 403);
            }
            if (_posla_admin_users_active_count($pdo) <= 1) {
                json_error('LAST_ACTIVE_ADMIN', '最後の有効管理者は無効化できません', 409);
            }
        }
        $sets[] = 'is_active = ?';
        $params[] = $nextActive;
    }

    if (array_key_exists('new_password', $body)) {
        $newPassword = (string)$body['new_password'];
        if ($newPassword === '') {
            json_error('INVALID_PASSWORD', '新しいパスワードを入力してください', 400);
        }
        if ($id === $admin['admin_id']) {
            json_error('SELF_PASSWORD_RESET_FORBIDDEN', '自分自身のパスワード変更は「パスワード変更」から実行してください', 403);
        }
        validate_password_strength($newPassword);
        $sets[] = 'password_hash = ?';
        $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    if (empty($sets)) {
        json_error('NO_CHANGES', '更新項目がありません', 400);
    }

    $pdo->beginTransaction();
    try {
        $params[] = $id;
        $sql = 'UPDATE posla_admins SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?';
        $pdo->prepare($sql)->execute($params);

        if ((array_key_exists('is_active', $body) && empty($body['is_active'])) || array_key_exists('new_password', $body)) {
            $pdo->prepare(
                'UPDATE posla_admin_sessions SET is_active = 0 WHERE admin_id = ?'
            )->execute([$id]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('posla_admin update failed: ' . $e->getMessage());
        json_error('DB_ERROR', '管理者の更新に失敗しました', 500);
    }

    error_log(sprintf(
        'posla_admin updated: actor=%s target=%s email=%s ip=%s',
        $admin['admin_id'],
        $id,
        $target['email'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ));

    $stmt = $pdo->prepare(
        'SELECT pa.id, pa.email, pa.display_name, pa.is_active, pa.last_login_at, pa.created_at, pa.updated_at,
                (SELECT COUNT(*) FROM posla_admin_sessions ps WHERE ps.admin_id = pa.id AND ps.is_active = 1) AS active_session_count
           FROM posla_admins pa
          WHERE pa.id = ?'
    );
    $stmt->execute([$id]);

    json_response([
        'admin' => $stmt->fetch(),
    ]);
}

if ($method === 'DELETE') {
    $body = get_json_body();
    $id = trim((string)($body['id'] ?? ''));
    $confirmEmail = trim((string)($body['confirm_email'] ?? ''));

    if ($id === '') {
        json_error('MISSING_ID', 'id は必須です', 400);
    }
    if ($id === $admin['admin_id']) {
        json_error('SELF_DELETE_FORBIDDEN', '現在ログイン中の管理者は削除できません', 403);
    }

    $stmt = $pdo->prepare('SELECT id, email, display_name, is_active FROM posla_admins WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        json_error('NOT_FOUND', '管理者が見つかりません', 404);
    }
    if ($confirmEmail !== (string)$target['email']) {
        json_error('CONFIRM_EMAIL_MISMATCH', '削除確認として管理者メールアドレスを正確に入力してください', 400);
    }
    if ((int)$target['is_active'] === 1 && _posla_admin_users_active_count($pdo) <= 1) {
        json_error('LAST_ACTIVE_ADMIN', '最後の有効管理者は削除できません', 409);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE posla_admin_sessions SET is_active = 0 WHERE admin_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM posla_admin_sessions WHERE admin_id = ?')->execute([$id]);
        $deleteStmt = $pdo->prepare('DELETE FROM posla_admins WHERE id = ?');
        $deleteStmt->execute([$id]);
        if ($deleteStmt->rowCount() < 1) {
            throw new RuntimeException('admin row was not deleted');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('posla_admin delete failed: ' . $e->getMessage());
        json_error('DB_ERROR', '管理者の削除に失敗しました', 500);
    }

    posla_admin_write_audit_log(
        $pdo,
        $admin,
        'posla_admin_delete',
        'posla_admin',
        $id,
        _posla_admin_users_audit_value($target),
        ['deleted' => true],
        'POSLA管理画面から管理者を削除',
        generate_uuid()
    );

    error_log(sprintf(
        'posla_admin deleted: actor=%s target=%s email=%s ip=%s',
        $admin['admin_id'],
        $id,
        $target['email'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ));

    json_response(['message' => '管理者を削除しました']);
}
