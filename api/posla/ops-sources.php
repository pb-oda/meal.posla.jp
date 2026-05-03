<?php
/**
 * POSLA管理者 OP source 参照 API
 *
 * GET   /api/posla/ops-sources.php
 *
 * posla_ops_sources は顧客 cell ではなく、codex-ops-platform が
 * POSLA snapshot を読む入口を管理するためのテーブル。
 * Source の作成・更新はOP側で行う。
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../config/app.php';

require_posla_admin();
$method = require_method(['GET']);
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
