<?php
/**
 * codex-ops-platform read-only cell snapshot.
 *
 * This endpoint is intentionally separate from public ping.php. It returns
 * aggregated operational state only, and requires a shared read secret.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/feature-flags.php';

$method = require_method(['GET']);
require_codex_ops_read_access();

$cellId = defined('APP_CELL_ID') ? (string)APP_CELL_ID : 'unknown';
$snapshot = array_merge([
    'ok' => true,
    'time' => date('c'),
    'contract_version' => 'codex-ops-cell-snapshot-v1',
], app_deployment_metadata());

try {
    $pdo = get_db();
} catch (Throwable $e) {
    $snapshot['ok'] = false;
    $snapshot['db'] = ['status' => 'ng'];
    $snapshot['cron'] = ['status' => 'unknown', 'last_heartbeat' => null, 'lag_sec' => null];
    json_response($snapshot, 503);
}

$snapshot['db'] = build_db_health($pdo);
$snapshot['cron'] = build_cron_health($pdo);
$snapshot['registry'] = build_registry_snapshot($pdo, $cellId);
$snapshot['deployments'] = fetch_recent_deployments($pdo, $cellId);
$snapshot['migrations'] = fetch_migration_summary($pdo, $cellId);
$snapshot['feature_flags'] = fetch_feature_flag_snapshot($pdo);
$snapshot['errors'] = fetch_error_summary($pdo);
$snapshot['monitor_events'] = fetch_monitor_event_summary($pdo);
$snapshot['tier0'] = fetch_tier0_payment_cashier_snapshot($pdo);

if (($snapshot['db']['status'] ?? 'ng') !== 'ok') {
    $snapshot['ok'] = false;
}
if (!empty($snapshot['cron']['lag_sec']) && (int)$snapshot['cron']['lag_sec'] > 900) {
    $snapshot['ok'] = false;
}
if (!empty($snapshot['tier0']['status']) && $snapshot['tier0']['status'] === 'error') {
    $snapshot['ok'] = false;
}

json_response($snapshot);

function require_codex_ops_read_access(): void
{
    $opsSecret = getenv('POSLA_OPS_READ_SECRET') ?: '';
    $cronSecret = getenv('POSLA_CRON_SECRET') ?: '';
    $sentOpsSecret = $_SERVER['HTTP_X_POSLA_OPS_SECRET'] ?? '';
    $sentCronSecret = $_SERVER['HTTP_X_POSLA_CRON_SECRET'] ?? '';

    if ($opsSecret !== '' && hash_equals($opsSecret, $sentOpsSecret)) {
        return;
    }
    if ($cronSecret !== '' && hash_equals($cronSecret, $sentCronSecret)) {
        return;
    }

    json_error('FORBIDDEN', 'codex-ops read secret is required', 403);
}

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );
        $stmt->execute([$tableName, $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function build_db_health(PDO $pdo): array
{
    try {
        $pdo->query('SELECT 1');
        return ['status' => 'ok'];
    } catch (PDOException $e) {
        return ['status' => 'ng'];
    }
}

function build_cron_health(PDO $pdo): array
{
    $heartbeat = null;
    $lag = null;

    try {
        $stmt = $pdo->query(
            "SELECT setting_value
             FROM posla_settings
             WHERE setting_key = 'monitor_last_heartbeat'
             LIMIT 1"
        );
        $heartbeat = $stmt ? $stmt->fetchColumn() : null;
        if ($heartbeat) {
            $lag = time() - strtotime((string)$heartbeat);
        }
    } catch (PDOException $e) {
        return ['status' => 'unknown', 'last_heartbeat' => null, 'lag_sec' => null];
    }

    return [
        'status' => ($heartbeat && $lag !== null && $lag <= 900) ? 'ok' : 'warn',
        'last_heartbeat' => $heartbeat ?: null,
        'lag_sec' => $lag,
    ];
}

function build_registry_snapshot(PDO $pdo, string $cellId): array
{
    if (!table_exists($pdo, 'posla_cell_registry')) {
        return ['available' => false, 'cell' => null, 'cells' => []];
    }

    try {
        $selectSql =
            'SELECT cell_id, tenant_id, tenant_slug, tenant_name, environment, status,
                    app_base_url, health_url, php_image, deploy_version, cron_enabled,
                    last_ping_at, updated_at
             FROM posla_cell_registry';

        $stmt = $pdo->prepare(
            $selectSql . '
             WHERE cell_id = ?
             LIMIT 1'
        );
        $stmt->execute([$cellId]);
        $row = $stmt->fetch();
        if ($row) {
            $row['cron_enabled'] = !empty($row['cron_enabled']) ? 1 : 0;
        }

        $allStmt = $pdo->query(
            $selectSql . '
             ORDER BY
               CASE status
                 WHEN "active" THEN 1
                 WHEN "maintenance" THEN 2
                 WHEN "provisioning" THEN 3
                 WHEN "planned" THEN 4
                 ELSE 9
               END,
               updated_at DESC,
               cell_id ASC
             LIMIT 200'
        );
        $cells = $allStmt ? $allStmt->fetchAll() : [];
        for ($i = 0; $i < count($cells); $i++) {
            $cells[$i]['cron_enabled'] = !empty($cells[$i]['cron_enabled']) ? 1 : 0;
        }

        return ['available' => true, 'cell' => $row ?: null, 'cells' => $cells];
    } catch (PDOException $e) {
        return ['available' => true, 'cell' => null, 'cells' => []];
    }
}

function fetch_recent_deployments(PDO $pdo, string $cellId): array
{
    if (!table_exists($pdo, 'posla_cell_deployments')) {
        return ['available' => false, 'recent' => []];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT cell_id, deploy_version, php_image, status, deployed_by, notes, created_at
             FROM posla_cell_deployments
             WHERE cell_id = ?
             ORDER BY created_at DESC
             LIMIT 5'
        );
        $stmt->execute([$cellId]);
        return ['available' => true, 'recent' => $stmt->fetchAll()];
    } catch (PDOException $e) {
        return ['available' => true, 'recent' => []];
    }
}

function fetch_migration_summary(PDO $pdo, string $cellId): array
{
    if (!table_exists($pdo, 'schema_migrations')) {
        return ['available' => false, 'latest' => [], 'failed_24h' => 0];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT migration, checksum, status, applied_at, applied_by
             FROM schema_migrations
             WHERE cell_id = ?
             ORDER BY applied_at DESC
             LIMIT 10'
        );
        $stmt->execute([$cellId]);
        $latest = $stmt->fetchAll();

        $failed = $pdo->prepare(
            "SELECT COUNT(*)
             FROM schema_migrations
             WHERE cell_id = ?
               AND status <> 'applied'
               AND applied_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $failed->execute([$cellId]);

        return [
            'available' => true,
            'latest' => $latest,
            'failed_24h' => (int)$failed->fetchColumn(),
        ];
    } catch (PDOException $e) {
        return ['available' => true, 'latest' => [], 'failed_24h' => 0];
    }
}

function fetch_feature_flag_snapshot(PDO $pdo): array
{
    if (!posla_feature_flags_available($pdo)) {
        return ['available' => false, 'flags' => []];
    }

    $flags = posla_feature_flag_resolve_all($pdo);
    return [
        'available' => true,
        'flags' => array_values($flags),
    ];
}

function fetch_error_summary(PDO $pdo): array
{
    if (!table_exists($pdo, 'error_log')) {
        return ['available' => false, 'recent_5m' => [], 'tier0_15m' => []];
    }

    try {
        $recent = $pdo->query(
            "SELECT error_no, code, http_status, request_path,
                    COUNT(*) AS count, MAX(created_at) AS latest_at
             FROM error_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             GROUP BY error_no, code, http_status, request_path
             ORDER BY count DESC, latest_at DESC
             LIMIT 20"
        )->fetchAll();

        $tier0 = $pdo->query(
            "SELECT error_no, code, http_status, request_path,
                    COUNT(*) AS count, MAX(created_at) AS latest_at
             FROM error_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND (
                 code IN (
                   'ALREADY_PAID','AMOUNT_MISMATCH','CHECKOUT_FAILED','GATEWAY_ERROR',
                   'GATEWAY_NOT_CONFIGURED','NO_GATEWAY','ONLINE_PAYMENT_REQUIRED',
                   'PAYMENT_GATEWAY_ERROR','PAYMENT_NOT_AVAILABLE','PAYMENT_NOT_CONFIGURED',
                   'PAYMENT_NOT_CONFIRMED','PAYMENT_NOT_FOUND','PAYMENT_RECORD_FAILED',
                   'REFUND_FAILED','SMAREGI_API_ERROR','SMAREGI_NOT_CONFIGURED',
                   'STRIPE_MISMATCH','VERIFICATION_FAILED'
                 )
                 OR request_path LIKE '%payment%'
                 OR request_path LIKE '%checkout%'
                 OR request_path LIKE '%cashier%'
                 OR request_path LIKE '%receipt%'
                 OR request_path LIKE '%smaregi%'
               )
             GROUP BY error_no, code, http_status, request_path
             ORDER BY count DESC, latest_at DESC
             LIMIT 20"
        )->fetchAll();

        return [
            'available' => true,
            'recent_5m' => normalize_count_rows($recent),
            'tier0_15m' => normalize_count_rows($tier0),
        ];
    } catch (PDOException $e) {
        return ['available' => true, 'recent_5m' => [], 'tier0_15m' => []];
    }
}

function fetch_monitor_event_summary(PDO $pdo): array
{
    if (!table_exists($pdo, 'monitor_events')) {
        return ['available' => false, 'unresolved' => [], 'critical_1h' => 0];
    }

    try {
        $stmt = $pdo->query(
            "SELECT event_type, severity, source, title, tenant_id, store_id, created_at
             FROM monitor_events
             WHERE resolved = 0
             ORDER BY created_at DESC
             LIMIT 20"
        );
        $critical = $pdo->query(
            "SELECT COUNT(*)
             FROM monitor_events
             WHERE severity IN ('error','critical')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        return [
            'available' => true,
            'unresolved' => $stmt->fetchAll(),
            'critical_1h' => (int)$critical->fetchColumn(),
        ];
    } catch (PDOException $e) {
        return ['available' => true, 'unresolved' => [], 'critical_1h' => 0];
    }
}

function fetch_tier0_payment_cashier_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'payments_15m' => null,
        'pending_payment_orders_over_15m' => null,
        'gateway_status_24h' => [],
        'pending_refunds_over_10m' => null,
        'emergency_unresolved' => null,
    ];

    try {
        if (table_exists($pdo, 'payments')) {
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM payments
                 WHERE paid_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $snapshot['payments_15m'] = (int)$stmt->fetchColumn();

            if (column_exists($pdo, 'payments', 'gateway_status')) {
                $rows = $pdo->query(
                    "SELECT COALESCE(gateway_name, 'none') AS gateway_name,
                            COALESCE(gateway_status, 'none') AS gateway_status,
                            COUNT(*) AS count
                     FROM payments
                     WHERE paid_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     GROUP BY gateway_name, gateway_status
                     ORDER BY count DESC"
                )->fetchAll();
                $snapshot['gateway_status_24h'] = normalize_count_rows($rows);
            }

            if (column_exists($pdo, 'payments', 'refund_status')) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM payments
                     WHERE refund_status = 'pending'
                       AND COALESCE(refunded_at, created_at) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
                );
                $snapshot['pending_refunds_over_10m'] = (int)$stmt->fetchColumn();
                if ($snapshot['pending_refunds_over_10m'] > 0) {
                    $snapshot['status'] = 'error';
                }
            }
        }

        if (table_exists($pdo, 'orders')) {
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM orders
                 WHERE status = 'pending_payment'
                   AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $snapshot['pending_payment_orders_over_15m'] = (int)$stmt->fetchColumn();
            if ($snapshot['pending_payment_orders_over_15m'] > 0) {
                $snapshot['status'] = 'warn';
            }
        }

        if (table_exists($pdo, 'emergency_payments') && column_exists($pdo, 'emergency_payments', 'resolution_status')) {
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM emergency_payments
                 WHERE resolution_status IN ('unresolved','pending')
                    OR status IN ('conflict','pending_review','failed')"
            );
            $snapshot['emergency_unresolved'] = (int)$stmt->fetchColumn();
            if ($snapshot['emergency_unresolved'] > 0 && $snapshot['status'] === 'ok') {
                $snapshot['status'] = 'warn';
            }
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function normalize_count_rows(array $rows): array
{
    $result = [];
    foreach ($rows as $row) {
        if (isset($row['count'])) {
            $row['count'] = (int)$row['count'];
        }
        if (isset($row['http_status'])) {
            $row['http_status'] = (int)$row['http_status'];
        }
        $result[] = $row;
    }
    return $result;
}
