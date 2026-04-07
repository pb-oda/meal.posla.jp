<?php
/**
 * P1-1c: Stripe Connect 解除
 *
 * POST /api/connect/disconnect.php
 *
 * owner 認証必須。テナントの Stripe Connect 連携情報をリセットする。
 * Stripe ダッシュボード上の Connected Account 自体は削除しない（履歴保持 + 再接続のため）。
 * 完全削除したい場合は Stripe ダッシュボードから手動で行うようUI側で案内する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

$user = require_role('owner');
require_method(['POST']);

$pdo = get_db();
$tenantId = $user['tenant_id'];

// 旧値を取得（監査ログ用）
$stmt = $pdo->prepare(
    'SELECT stripe_connect_account_id, connect_onboarding_complete FROM tenants WHERE id = ?'
);
$stmt->execute([$tenantId]);
$row = $stmt->fetch();
if (!$row) {
    json_error('NOT_FOUND', 'テナントが見つかりません', 404);
}

$oldValue = [
    'stripe_connect_account_id'   => $row['stripe_connect_account_id'],
    'connect_onboarding_complete' => (int)$row['connect_onboarding_complete'],
];

// Connect 関連カラムをリセット（charges_enabled / payouts_enabled は tenants に存在しない。
// status.php は Stripe API からリアルタイム取得しているため、ここでの更新は不要）
$stmt = $pdo->prepare(
    'UPDATE tenants SET
        stripe_connect_account_id = NULL,
        connect_onboarding_complete = 0
     WHERE id = ?'
);
$stmt->execute([$tenantId]);

// 監査ログ
write_audit_log(
    $pdo,
    $user,
    null,
    'settings_update',
    'settings',
    null,
    $oldValue,
    [
        'stripe_connect_account_id'   => null,
        'connect_onboarding_complete' => 0,
    ],
    'Stripe Connect 解除'
);

json_response(['disconnected' => true]);
