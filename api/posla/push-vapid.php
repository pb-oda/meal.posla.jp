<?php
/**
 * POSLA 管理画面 PWA/Push タブ用情報 API
 *
 * GET /api/posla/push-vapid.php
 *   - VAPID 状態 / 購読統計 / 送信統計
 *
 * POST /api/posla/push-vapid.php
 *   - action=get_public_key
 *   - action=get_private_pem
 *   - action=get_backup_bundle
 *   - action=generate_and_apply
 *
 * 方針:
 * - POSLA 管理者専用
 * - GET の統計取得は既存互換を維持
 * - 再生成は確認付きで明示実行のみ許可する
 * - 秘密鍵を返す操作は明示 action のみで、error_log に監査用の痕跡を残す
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/posla-settings.php';

$method = require_method(['GET', 'POST']);
$admin = require_posla_admin();
$pdo = get_db();

$publicKey = (string)(get_posla_setting($pdo, 'web_push_vapid_public') ?? '');
$privatePem = (string)(get_posla_setting($pdo, 'web_push_vapid_private_pem') ?? '');

function _posla_push_vapid_filename(string $suffix): string
{
    return 'posla-vapid-' . $suffix . '-' . date('Ymd-His') . '.txt';
}

function _posla_push_vapid_log_event(array $admin, string $action, array $meta = []): void
{
    $email = (string)($admin['email'] ?? '');
    $parts = [
        '[POSLA_PUSH_VAPID]',
        'admin=' . $email,
        'action=' . $action,
        'ip=' . ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    foreach ($meta as $key => $value) {
        $parts[] = $key . '=' . str_replace(["\r", "\n"], ' ', (string)$value);
    }
    @error_log(implode(' ', $parts));
}

function _posla_push_vapid_build_bundle(string $publicKey, string $privatePem): string
{
    return "POSLA VAPID backup bundle\n"
        . 'exported_at=' . date('c') . "\n\n"
        . "[public_key]\n"
        . $publicKey . "\n\n"
        . "[private_pem]\n"
        . $privatePem . "\n";
}

function _posla_push_vapid_base64url(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function _posla_push_vapid_generate_pair(): array
{
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('openssl 拡張が必要です');
    }

    $key = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($key === false) {
        throw new RuntimeException('openssl_pkey_new に失敗しました');
    }

    $privatePem = '';
    if (!openssl_pkey_export($key, $privatePem)) {
        throw new RuntimeException('openssl_pkey_export に失敗しました');
    }

    $details = openssl_pkey_get_details($key);
    if (!$details || empty($details['ec']['x']) || empty($details['ec']['y'])) {
        throw new RuntimeException('EC 公開鍵の抽出に失敗しました');
    }

    $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $rawPublic = "\x04" . $x . $y;

    if (strlen($rawPublic) !== 65) {
        throw new RuntimeException('公開鍵長が不正です');
    }

    return [
        'public_key' => _posla_push_vapid_base64url($rawPublic),
        'private_pem' => $privatePem,
    ];
}

function _posla_push_vapid_upsert_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO posla_settings (setting_key, setting_value, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute([$key, $value]);
}

function _posla_push_vapid_enabled_subscriptions(PDO $pdo): int
{
    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM push_subscriptions WHERE enabled = 1');
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function _posla_push_vapid_disable_enabled_subscriptions(PDO $pdo): int
{
    try {
        $stmt = $pdo->prepare(
            'UPDATE push_subscriptions
                SET enabled = 0,
                    revoked_at = NOW(),
                    updated_at = NOW()
              WHERE enabled = 1'
        );
        $stmt->execute();
        return (int)$stmt->rowCount();
    } catch (PDOException $e) {
        return 0;
    }
}

if ($method === 'POST') {
    $body = get_json_body();
    $action = trim((string)($body['action'] ?? ''));

    if ($action === 'get_public_key') {
        if ($publicKey === '') {
            json_error('VAPID_NOT_CONFIGURED', 'VAPID 公開鍵が未設定です', 400);
        }
        _posla_push_vapid_log_event($admin, $action);
        json_response([
            'action' => $action,
            'label' => 'VAPID 公開鍵',
            'value' => $publicKey,
            'filename' => _posla_push_vapid_filename('public'),
            'mime_type' => 'text/plain',
        ]);
    }

    if ($action === 'get_private_pem') {
        if ($privatePem === '') {
            json_error('VAPID_NOT_CONFIGURED', 'VAPID 秘密鍵が未設定です', 400);
        }
        _posla_push_vapid_log_event($admin, $action);
        json_response([
            'action' => $action,
            'label' => 'VAPID 秘密鍵',
            'value' => $privatePem,
            'filename' => _posla_push_vapid_filename('private'),
            'mime_type' => 'application/x-pem-file',
        ]);
    }

    if ($action === 'get_backup_bundle') {
        if ($publicKey === '' || $privatePem === '') {
            json_error('VAPID_NOT_CONFIGURED', 'VAPID 鍵ペアが未設定です', 400);
        }
        _posla_push_vapid_log_event($admin, $action);
        json_response([
            'action' => $action,
            'label' => 'VAPID 退避用 bundle',
            'value' => _posla_push_vapid_build_bundle($publicKey, $privatePem),
            'filename' => _posla_push_vapid_filename('backup'),
            'mime_type' => 'text/plain',
        ]);
    }

    if ($action === 'generate_and_apply') {
        $confirmRegenerate = !empty($body['confirm_regenerate']);
        $enabledBefore = _posla_push_vapid_enabled_subscriptions($pdo);
        $hasExistingPair = ($publicKey !== '' || $privatePem !== '');

        if (($hasExistingPair || $enabledBefore > 0) && !$confirmRegenerate) {
            json_error('CONFIRM_REQUIRED', '既存の VAPID 鍵または有効購読があるため、再生成の確認が必要です', 409);
        }

        try {
            $generated = _posla_push_vapid_generate_pair();

            $pdo->beginTransaction();
            _posla_push_vapid_upsert_setting($pdo, 'web_push_vapid_public', $generated['public_key']);
            _posla_push_vapid_upsert_setting($pdo, 'web_push_vapid_private_pem', $generated['private_pem']);
            $disabledSubscriptions = _posla_push_vapid_disable_enabled_subscriptions($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @error_log('[POSLA_PUSH_VAPID] action=generate_and_apply failed=' . $e->getMessage());
            json_error('VAPID_GENERATE_FAILED', 'VAPID 鍵の生成または適用に失敗しました', 500);
        }

        _posla_push_vapid_log_event($admin, $action, [
            'rotated' => $hasExistingPair ? '1' : '0',
            'enabled_before' => (string)$enabledBefore,
            'disabled_subscriptions' => (string)$disabledSubscriptions,
        ]);

        json_response([
            'action' => $action,
            'label' => 'VAPID 退避用 bundle',
            'value' => _posla_push_vapid_build_bundle($generated['public_key'], $generated['private_pem']),
            'filename' => _posla_push_vapid_filename('backup'),
            'mime_type' => 'text/plain',
            'summary' => [
                'generated' => true,
                'rotated' => $hasExistingPair,
                'enabled_subscriptions_before' => $enabledBefore,
                'disabled_subscriptions' => $disabledSubscriptions,
            ],
        ]);
    }

    json_error('INVALID_ACTION', '未対応の action です', 400);
}

// 購読統計
$subsStats = ['total' => 0, 'enabled' => 0, 'tenants_with_subs' => 0, 'by_role' => []];
try {
    $stmt = $pdo->query(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS enabled
           FROM push_subscriptions'
    );
    $row = $stmt->fetch();
    if ($row) {
        $subsStats['total'] = (int)$row['total'];
        $subsStats['enabled'] = (int)$row['enabled'];
    }
    $t = $pdo->query('SELECT COUNT(DISTINCT tenant_id) FROM push_subscriptions WHERE enabled = 1');
    $subsStats['tenants_with_subs'] = (int)$t->fetchColumn();
    $r = $pdo->query("SELECT role, COUNT(*) AS cnt FROM push_subscriptions WHERE enabled = 1 GROUP BY role");
    foreach ($r->fetchAll() as $rr) {
        $subsStats['by_role'][(string)$rr['role']] = (int)$rr['cnt'];
    }
} catch (PDOException $e) { /* push_subscriptions 未存在時は 0 のまま */ }

// 送信ログ統計 (直近 24 時間)
$sendStats = ['total' => 0, 'sent_ok' => 0, 'gone_disabled' => 0, 'transient' => 0, 'other_error' => 0, 'by_type' => []];
try {
    $stmt = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) AS sent_ok,
                SUM(CASE WHEN status_code IN (404, 410) THEN 1 ELSE 0 END) AS gone_disabled,
                SUM(CASE WHEN status_code = 429 OR status_code >= 500 THEN 1 ELSE 0 END) AS transient,
                SUM(CASE WHEN status_code = 401 OR status_code = 403 OR status_code = 413 THEN 1 ELSE 0 END) AS other_error
           FROM push_send_log
          WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $row = $stmt->fetch();
    if ($row) {
        foreach (['total', 'sent_ok', 'gone_disabled', 'transient', 'other_error'] as $k) {
            $sendStats[$k] = (int)$row[$k];
        }
    }
    $r = $pdo->query(
        "SELECT type, COUNT(*) AS cnt
           FROM push_send_log
          WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          GROUP BY type
          ORDER BY cnt DESC"
    );
    foreach ($r->fetchAll() as $rr) {
        $sendStats['by_type'][(string)$rr['type']] = (int)$rr['cnt'];
    }
} catch (PDOException $e) { /* push_send_log 未作成環境は 0 のまま */ }

json_response([
    'vapid' => [
        'public_key'         => $publicKey,
        'public_key_length'  => $publicKey ? strlen($publicKey) : 0,
        'private_pem_set'    => !!$privatePem,
        'private_pem_length' => $privatePem ? strlen($privatePem) : 0,
        'available'          => $publicKey && $privatePem ? true : false,
    ],
    'subscriptions' => $subsStats,
    'recent_24h'    => $sendStats,
]);
