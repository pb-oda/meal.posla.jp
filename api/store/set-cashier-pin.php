<?php
/**
 * CP1: 担当スタッフ PIN 設定 API
 *
 * POST /api/store/set-cashier-pin.php
 * Body: { user_id, pin }
 *
 * - manager 以上: 自店舗の他スタッフの PIN を設定可
 * - staff: 自分の PIN のみ変更可 (user_id を自分以外指定すると 403)
 *
 * PIN 仕様:
 * - 4〜8 桁の数字のみ
 * - bcrypt ハッシュで users.cashier_pin_hash に保存
 * - device ロールには設定不可 (人間ではないため)
 *
 * 監査ログ: cashier_pin_set イベント
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);
$user = require_auth();

$data = get_json_body();
$targetUserId = $data['user_id'] ?? null;
$pin          = isset($data['pin']) ? trim((string)$data['pin']) : '';

if (!$targetUserId) json_error('VALIDATION', 'user_id は必須です', 400);
if ($pin === '') json_error('VALIDATION', 'pin は必須です', 400);
if (!preg_match('/^\d{4,8}$/', $pin)) {
    json_error('INVALID_PIN', 'PIN は 4〜8 桁の数字で入力してください', 400);
}

// 弱い PIN を弾く (連番・全部同じ等)
if (preg_match('/^(\d)\1+$/', $pin)) {
    json_error('WEAK_PIN', '同じ数字の繰り返しは PIN として使えません', 400);
}
if (in_array($pin, ['1234', '12345', '123456', '1234567', '12345678', '0000', '00000', '000000', '00000000'], true)) {
    json_error('WEAK_PIN', '推測されやすい PIN は使えません', 400);
}

$pdo = get_db();

// 対象ユーザー取得
$stmt = $pdo->prepare(
    'SELECT u.id, u.tenant_id, u.role, u.display_name, u.username
     FROM users u WHERE u.id = ? AND u.tenant_id = ?'
);
$stmt->execute([$targetUserId, $user['tenant_id']]);
$target = $stmt->fetch();

if (!$target) json_error('NOT_FOUND', '対象ユーザーが見つかりません', 404);
if ($target['role'] === 'device') {
    json_error('VALIDATION', 'device ロールには PIN を設定できません', 400);
}

// 権限チェック
$isOwnPin = ($target['id'] === $user['user_id']);
$isManagerOrAbove = in_array($user['role'], ['manager', 'owner'], true);

if (!$isOwnPin && !$isManagerOrAbove) {
    json_error('FORBIDDEN', '他人の PIN は manager 以上でないと設定できません', 403);
}

// manager は自店舗のスタッフのみ操作可
if (!$isOwnPin && $user['role'] === 'manager') {
    // 対象が同じ店舗に所属しているかチェック
    $myStoreIds = $user['store_ids'] ?? [];
    if (!empty($myStoreIds)) {
        $ph = implode(',', array_fill(0, count($myStoreIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_stores WHERE user_id = ? AND store_id IN (' . $ph . ')'
        );
        $stmt->execute(array_merge([$targetUserId], $myStoreIds));
        if ($stmt->fetchColumn() == 0) {
            json_error('FORBIDDEN', 'この店舗のスタッフではありません', 403);
        }
    }
}

// PIN ハッシュ化して保存
try {
    $pdo->query('SELECT cashier_pin_hash FROM users LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'cashier_pin_hash カラムが未作成です。migration-cp1-cashier-pin.sql を実行してください。', 500);
}

$hash = password_hash($pin, PASSWORD_BCRYPT);
$pdo->prepare(
    'UPDATE users SET cashier_pin_hash = ?, cashier_pin_updated_at = NOW() WHERE id = ?'
)->execute([$hash, $targetUserId]);

// 監査ログ
write_audit_log($pdo, $user, null, 'cashier_pin_set', 'user', $targetUserId, null, [
    'target_username' => $target['username'],
    'target_display_name' => $target['display_name'],
    'is_own' => $isOwnPin ? 1 : 0,
], null);

json_response(['ok' => true]);
