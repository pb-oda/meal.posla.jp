<?php
/**
 * POSLA管理者 OP連携 source API
 *
 * GET   /api/posla/ops-sources.php
 * PATCH /api/posla/ops-sources.php
 *
 * posla_ops_sources は顧客 cell ではなく、codex-ops-platform が
 * POSLA snapshot を読む入口を管理するためのテーブル。
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/../config/app.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH']);
$pdo = get_db();

function ops_sources_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM posla_ops_sources LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ops_sources_current_source_id(): string
{
    if (defined('APP_OPS_SOURCE_ID')) {
        return (string)APP_OPS_SOURCE_ID;
    }
    if (defined('APP_CELL_ID')) {
        return (string)APP_CELL_ID;
    }
    $value = getenv('POSLA_OPS_SOURCE_ID');
    return ($value !== false && $value !== '') ? (string)$value : 'posla-control-local';
}

function ops_sources_valid_id(string $sourceId): bool
{
    return preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/', $sourceId) === 1;
}

function ops_sources_valid_url(string $value): bool
{
    if ($value === '') {
        return true;
    }
    return preg_match('/^https?:\/\/[^\s]+$/', $value) === 1 && strlen($value) <= 255;
}

function ops_sources_normalize_text($value, int $maxLength): string
{
    $text = trim((string)$value);
    if (strlen($text) > $maxLength) {
        json_error('VALUE_TOO_LONG', '入力値が長すぎます', 400);
    }
    return $text;
}

function ops_sources_fetch(PDO $pdo, string $sourceId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT source_id, label, environment, status, base_url, ping_url,
                snapshot_url, auth_type, notes, created_at, updated_at
         FROM posla_ops_sources
         WHERE source_id = ?
         LIMIT 1'
    );
    $stmt->execute([$sourceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ops_sources_fetch_all(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT source_id, label, environment, status, base_url, ping_url,
                snapshot_url, auth_type, notes, created_at, updated_at
         FROM posla_ops_sources
         ORDER BY
           CASE status
             WHEN "active" THEN 1
             WHEN "maintenance" THEN 2
             WHEN "inactive" THEN 3
             ELSE 9
           END,
           updated_at DESC,
           source_id ASC
         LIMIT 50'
    );
    return $stmt ? $stmt->fetchAll() : [];
}

function ops_sources_auth_state(string $authType): array
{
    $opsSecretSet = (getenv('POSLA_OPS_READ_SECRET') ?: '') !== '';
    $cronSecretSet = (getenv('POSLA_CRON_SECRET') ?: '') !== '';
    $headerName = 'none';

    if ($authType === 'ops_read_secret') {
        $headerName = 'X-POSLA-OPS-SECRET';
    } elseif ($authType === 'cron_secret') {
        $headerName = 'X-POSLA-CRON-SECRET';
    }

    return [
        'auth_type' => $authType,
        'header_name' => $headerName,
        'ops_read_secret_env_set' => $opsSecretSet ? 1 : 0,
        'cron_secret_env_set' => $cronSecretSet ? 1 : 0,
    ];
}

function ops_sources_fallback_source(): array
{
    $baseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : '';
    return [
        'source_id' => ops_sources_current_source_id(),
        'label' => 'POSLA control source',
        'environment' => defined('APP_ENVIRONMENT') ? APP_ENVIRONMENT : 'production',
        'status' => 'active',
        'base_url' => $baseUrl,
        'ping_url' => $baseUrl !== '' ? rtrim($baseUrl, '/') . '/api/monitor/ping.php' : '',
        'snapshot_url' => $baseUrl !== '' ? rtrim($baseUrl, '/') . '/api/monitor/cell-snapshot.php' : '',
        'auth_type' => 'ops_read_secret',
        'notes' => '',
        'created_at' => null,
        'updated_at' => null,
    ];
}

if (!ops_sources_table_exists($pdo)) {
    if ($method === 'GET') {
        $fallback = ops_sources_fallback_source();
        json_response([
            'available' => false,
            'current_source_id' => $fallback['source_id'],
            'source' => $fallback,
            'sources' => [],
            'auth' => ops_sources_auth_state($fallback['auth_type']),
        ]);
    }
    json_error('OPS_SOURCES_UNAVAILABLE', 'OP連携 source テーブルが未作成です', 503);
}

if ($method === 'GET') {
    $currentSourceId = ops_sources_current_source_id();
    $source = ops_sources_fetch($pdo, $currentSourceId);
    if (!$source) {
        $source = ops_sources_fallback_source();
    }

    json_response([
        'available' => true,
        'current_source_id' => $currentSourceId,
        'source' => $source,
        'sources' => ops_sources_fetch_all($pdo),
        'auth' => ops_sources_auth_state((string)($source['auth_type'] ?? 'ops_read_secret')),
    ]);
}

if ($method === 'PATCH') {
    $input = get_json_body();
    $sourceId = ops_sources_normalize_text($input['source_id'] ?? ops_sources_current_source_id(), 100);
    if (!ops_sources_valid_id($sourceId)) {
        json_error('INVALID_SOURCE_ID', 'source_id が不正です', 400);
    }

    $label = ops_sources_normalize_text($input['label'] ?? '', 200);
    $environment = ops_sources_normalize_text($input['environment'] ?? '', 50);
    $status = ops_sources_normalize_text($input['status'] ?? '', 30);
    $baseUrl = rtrim(ops_sources_normalize_text($input['base_url'] ?? '', 255), '/');
    $pingUrl = ops_sources_normalize_text($input['ping_url'] ?? '', 255);
    $snapshotUrl = ops_sources_normalize_text($input['snapshot_url'] ?? '', 255);
    $authType = ops_sources_normalize_text($input['auth_type'] ?? '', 30);
    $notes = ops_sources_normalize_text($input['notes'] ?? '', 2000);

    if ($label === '') {
        json_error('MISSING_LABEL', 'label は必須です', 400);
    }
    if ($environment === '') {
        json_error('MISSING_ENVIRONMENT', 'environment は必須です', 400);
    }
    if (!in_array($status, ['active', 'maintenance', 'inactive', 'failed'], true)) {
        json_error('INVALID_STATUS', 'status が不正です', 400);
    }
    if (!in_array($authType, ['ops_read_secret', 'cron_secret', 'none'], true)) {
        json_error('INVALID_AUTH_TYPE', 'auth_type が不正です', 400);
    }
    if (!ops_sources_valid_url($baseUrl) || !ops_sources_valid_url($pingUrl) || !ops_sources_valid_url($snapshotUrl)) {
        json_error('INVALID_URL', 'URL は http(s) の形式で入力してください', 400);
    }

    if ($baseUrl !== '') {
        if ($pingUrl === '') {
            $pingUrl = $baseUrl . '/api/monitor/ping.php';
        }
        if ($snapshotUrl === '') {
            $snapshotUrl = $baseUrl . '/api/monitor/cell-snapshot.php';
        }
    }

    $oldValue = ops_sources_fetch($pdo, $sourceId);

    $stmt = $pdo->prepare(
        'INSERT INTO posla_ops_sources
            (source_id, label, environment, status, base_url, ping_url,
             snapshot_url, auth_type, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            environment = VALUES(environment),
            status = VALUES(status),
            base_url = VALUES(base_url),
            ping_url = VALUES(ping_url),
            snapshot_url = VALUES(snapshot_url),
            auth_type = VALUES(auth_type),
            notes = VALUES(notes),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        $sourceId,
        $label,
        $environment,
        $status,
        $baseUrl !== '' ? $baseUrl : null,
        $pingUrl !== '' ? $pingUrl : null,
        $snapshotUrl !== '' ? $snapshotUrl : null,
        $authType,
        $notes !== '' ? $notes : null,
    ]);

    $newValue = ops_sources_fetch($pdo, $sourceId);
    posla_admin_write_audit_log(
        $pdo,
        $admin,
        $oldValue ? 'ops_source_update' : 'ops_source_create',
        'posla_ops_source',
        $sourceId,
        $oldValue,
        $newValue,
        null,
        generate_uuid()
    );

    json_response([
        'message' => 'OP連携 source を保存しました',
        'source' => $newValue,
        'auth' => ops_sources_auth_state((string)($newValue['auth_type'] ?? $authType)),
    ]);
}
