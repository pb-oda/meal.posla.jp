<?php
/**
 * tenant 単位 LINE連携設定 API（owner 専用）
 *
 * GET   /api/owner/line-settings.php
 * PATCH /api/owner/line-settings.php
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../config/app.php';

require_method(['GET', 'PATCH']);
$user = require_role('owner');
$pdo = get_db();

function line_settings_table_exists($pdo)
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $pdo->query('SELECT tenant_id FROM tenant_line_settings LIMIT 0');
        $exists = true;
    } catch (PDOException $e) {
        $exists = false;
    }
    return $exists;
}

function mask_secret_value($value)
{
    if (!$value) {
        return null;
    }
    $len = strlen($value);
    if ($len <= 4) {
        return str_repeat('●', $len);
    }
    return substr($value, 0, 4) . str_repeat('●', min(8, $len - 4));
}

function normalize_flag($value)
{
    return ($value === 1 || $value === '1' || $value === true) ? 1 : 0;
}

function build_line_settings_payload($tenant, $row)
{
    $row = is_array($row) ? $row : [];
    return [
        'tenant_id'                       => $tenant['id'],
        'tenant_slug'                     => $tenant['slug'],
        'webhook_url'                     => app_url('/api/line/webhook.php?tenant=' . rawurlencode($tenant['slug'])),
        'migration_applied'               => true,
        'is_enabled'                      => (int)($row['is_enabled'] ?? 0),
        'channel_access_token_set'        => !empty($row['channel_access_token']),
        'channel_access_token_masked'     => mask_secret_value($row['channel_access_token'] ?? null),
        'channel_secret_set'              => !empty($row['channel_secret']),
        'channel_secret_masked'           => mask_secret_value($row['channel_secret'] ?? null),
        'liff_id'                         => $row['liff_id'] ?? '',
        'notify_reservation_created'      => (int)($row['notify_reservation_created'] ?? 0),
        'notify_reservation_reminder_day' => (int)($row['notify_reservation_reminder_day'] ?? 0),
        'notify_takeout_ready'            => (int)($row['notify_takeout_ready'] ?? 0),
        'last_webhook_at'                 => $row['last_webhook_at'] ?? null,
        'last_webhook_event_type'         => $row['last_webhook_event_type'] ?? null,
        'last_webhook_event_count'        => isset($row['last_webhook_event_count']) ? (int)$row['last_webhook_event_count'] : 0,
    ];
}

function get_tenant_row($pdo, $tenantId)
{
    $stmt = $pdo->prepare('SELECT id, slug, name FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant) {
        json_error('NOT_FOUND', 'テナントが見つかりません', 404);
    }
    return $tenant;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tenant = get_tenant_row($pdo, $user['tenant_id']);

    if (!line_settings_table_exists($pdo)) {
        json_response([
            'line' => [
                'tenant_id'                       => $tenant['id'],
                'tenant_slug'                     => $tenant['slug'],
                'webhook_url'                     => app_url('/api/line/webhook.php?tenant=' . rawurlencode($tenant['slug'])),
                'migration_applied'               => false,
                'is_enabled'                      => 0,
                'channel_access_token_set'        => false,
                'channel_access_token_masked'     => null,
                'channel_secret_set'              => false,
                'channel_secret_masked'           => null,
                'liff_id'                         => '',
                'notify_reservation_created'      => 0,
                'notify_reservation_reminder_day' => 0,
                'notify_takeout_ready'            => 0,
                'last_webhook_at'                 => null,
                'last_webhook_event_type'         => null,
                'last_webhook_event_count'        => 0,
            ],
        ]);
    }

    $stmt = $pdo->prepare('SELECT * FROM tenant_line_settings WHERE tenant_id = ?');
    $stmt->execute([$user['tenant_id']]);
    $row = $stmt->fetch();

    json_response(['line' => build_line_settings_payload($tenant, $row)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    if (!line_settings_table_exists($pdo)) {
        json_error('NOT_CONFIGURED', 'LINE連携用マイグレーションが未適用です', 503);
    }

    $data = get_json_body();
    $tenant = get_tenant_row($pdo, $user['tenant_id']);

    $stmt = $pdo->prepare('SELECT * FROM tenant_line_settings WHERE tenant_id = ?');
    $stmt->execute([$user['tenant_id']]);
    $current = $stmt->fetch();

    $merged = [
        'channel_access_token'        => $current['channel_access_token'] ?? null,
        'channel_secret'              => $current['channel_secret'] ?? null,
        'liff_id'                     => $current['liff_id'] ?? null,
        'is_enabled'                  => isset($current['is_enabled']) ? (int)$current['is_enabled'] : 0,
        'notify_reservation_created'  => isset($current['notify_reservation_created']) ? (int)$current['notify_reservation_created'] : 0,
        'notify_reservation_reminder_day' => isset($current['notify_reservation_reminder_day']) ? (int)$current['notify_reservation_reminder_day'] : 0,
        'notify_takeout_ready'        => isset($current['notify_takeout_ready']) ? (int)$current['notify_takeout_ready'] : 0,
    ];

    $hasChanges = false;

    if (array_key_exists('channel_access_token', $data)) {
        $value = $data['channel_access_token'];
        $merged['channel_access_token'] = ($value === null || trim((string)$value) === '') ? null : trim((string)$value);
        $hasChanges = true;
    }
    if (array_key_exists('channel_secret', $data)) {
        $value = $data['channel_secret'];
        $merged['channel_secret'] = ($value === null || trim((string)$value) === '') ? null : trim((string)$value);
        $hasChanges = true;
    }
    if (array_key_exists('liff_id', $data)) {
        $value = $data['liff_id'];
        $merged['liff_id'] = ($value === null || trim((string)$value) === '') ? null : trim((string)$value);
        $hasChanges = true;
    }
    if (array_key_exists('is_enabled', $data)) {
        $merged['is_enabled'] = normalize_flag($data['is_enabled']);
        $hasChanges = true;
    }
    if (array_key_exists('notify_reservation_created', $data)) {
        $merged['notify_reservation_created'] = normalize_flag($data['notify_reservation_created']);
        $hasChanges = true;
    }
    if (array_key_exists('notify_reservation_reminder_day', $data)) {
        $merged['notify_reservation_reminder_day'] = normalize_flag($data['notify_reservation_reminder_day']);
        $hasChanges = true;
    }
    if (array_key_exists('notify_takeout_ready', $data)) {
        $merged['notify_takeout_ready'] = normalize_flag($data['notify_takeout_ready']);
        $hasChanges = true;
    }

    if (!$hasChanges) {
        json_error('NO_CHANGES', '更新項目がありません', 400);
    }

    if ($merged['is_enabled']) {
        if (empty($merged['channel_access_token']) || empty($merged['channel_secret'])) {
            json_error('INVALID_INPUT', 'LINE連携を有効にするには Channel Access Token と Channel Secret が必要です', 400);
        }
    }

    $sql = 'INSERT INTO tenant_line_settings
              (tenant_id, channel_access_token, channel_secret, liff_id, is_enabled,
               notify_reservation_created, notify_reservation_reminder_day, notify_takeout_ready)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              channel_access_token = VALUES(channel_access_token),
              channel_secret = VALUES(channel_secret),
              liff_id = VALUES(liff_id),
              is_enabled = VALUES(is_enabled),
              notify_reservation_created = VALUES(notify_reservation_created),
              notify_reservation_reminder_day = VALUES(notify_reservation_reminder_day),
              notify_takeout_ready = VALUES(notify_takeout_ready)';
    $pdo->prepare($sql)->execute([
        $user['tenant_id'],
        $merged['channel_access_token'],
        $merged['channel_secret'],
        $merged['liff_id'],
        $merged['is_enabled'],
        $merged['notify_reservation_created'],
        $merged['notify_reservation_reminder_day'],
        $merged['notify_takeout_ready'],
    ]);

    $stmt = $pdo->prepare('SELECT * FROM tenant_line_settings WHERE tenant_id = ?');
    $stmt->execute([$user['tenant_id']]);
    $saved = $stmt->fetch();

    json_response(['line' => build_line_settings_payload($tenant, $saved)]);
}
