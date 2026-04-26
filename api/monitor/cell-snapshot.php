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
require_once __DIR__ . '/../lib/tenant-onboarding.php';
require_once __DIR__ . '/../posla/tenant-insights-helper.php';

$method = require_method(['GET']);
require_codex_ops_read_access();

$cellId = defined('APP_CELL_ID') ? (string)APP_CELL_ID : 'unknown';
$sourceId = defined('APP_OPS_SOURCE_ID') ? (string)APP_OPS_SOURCE_ID : $cellId;
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
$snapshot['ops_source'] = build_ops_source_snapshot($pdo, $sourceId);
$snapshot['registry'] = build_registry_snapshot($pdo, $cellId);
$snapshot['onboarding'] = posla_fetch_onboarding_snapshot($pdo);
$snapshot['deployments'] = fetch_recent_deployments($pdo, $cellId);
$snapshot['migrations'] = fetch_migration_summary($pdo, $cellId);
$snapshot['feature_flags'] = fetch_feature_flag_snapshot($pdo);
$snapshot['tenant_insights'] = fetch_tenant_insight_snapshot($pdo, empty($snapshot['registry']['cell']));
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
        return ['available' => false, 'cell' => null, 'control_cell' => null, 'cells' => []];
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
        $controlRow = $stmt->fetch();
        if ($controlRow) {
            $controlRow['cron_enabled'] = !empty($controlRow['cron_enabled']) ? 1 : 0;
        }

        $customerCell = null;
        if ($controlRow && !empty($controlRow['tenant_id']) && !empty($controlRow['tenant_slug'])) {
            $customerCell = $controlRow;
        }

        $allStmt = $pdo->query(
            $selectSql . '
             WHERE tenant_id IS NOT NULL
               AND tenant_slug IS NOT NULL
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

        return [
            'available' => true,
            'cell' => $customerCell,
            'control_cell' => $controlRow ?: null,
            'cells' => $cells,
        ];
    } catch (PDOException $e) {
        return ['available' => true, 'cell' => null, 'control_cell' => null, 'cells' => []];
    }
}

function build_ops_source_snapshot(PDO $pdo, string $sourceId): array
{
    $fallback = [
        'source_id' => $sourceId,
        'label' => 'POSLA control source',
        'environment' => defined('APP_ENVIRONMENT') ? APP_ENVIRONMENT : null,
        'status' => 'unknown',
        'base_url' => defined('APP_BASE_URL') ? APP_BASE_URL : null,
        'ping_url' => null,
        'snapshot_url' => null,
        'auth_type' => 'unknown',
        'updated_at' => null,
    ];

    if (!table_exists($pdo, 'posla_ops_sources')) {
        return [
            'available' => false,
            'source' => $fallback,
            'auth' => build_ops_source_auth_state('unknown'),
        ];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT source_id, label, environment, status, base_url, ping_url,
                    snapshot_url, auth_type, notes, updated_at
             FROM posla_ops_sources
             WHERE source_id = ?
             LIMIT 1'
        );
        $stmt->execute([$sourceId]);
        $row = $stmt->fetch();

        return [
            'available' => true,
            'source' => $row ?: $fallback,
            'auth' => build_ops_source_auth_state($row['auth_type'] ?? 'unknown'),
        ];
    } catch (PDOException $e) {
        return [
            'available' => true,
            'source' => $fallback,
            'auth' => build_ops_source_auth_state('unknown'),
        ];
    }
}

function fetch_tenant_insight_snapshot(PDO $pdo, bool $includeCellSnapshots): array
{
    if (!table_exists($pdo, 'tenants')) {
        return ['available' => false, 'tenants' => []];
    }

    try {
        return [
            'available' => true,
            'tenants' => posla_fetch_tenant_insights($pdo, null, $includeCellSnapshots),
        ];
    } catch (Throwable $e) {
        return ['available' => true, 'tenants' => [], 'error' => 'tenant insight unavailable'];
    }
}

function build_ops_source_auth_state(string $authType): array
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
            'SELECT migration_key AS migration, checksum_sha256 AS checksum, status, applied_at, applied_by
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
            "SELECT e.error_no, e.code, e.http_status, e.request_path,
                    e.tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                    e.store_id, s.slug AS store_slug, s.name AS store_name,
                    COUNT(*) AS count, MAX(e.created_at) AS latest_at
             FROM error_log e
             LEFT JOIN stores s ON s.id = e.store_id
             LEFT JOIN tenants t ON t.id = COALESCE(e.tenant_id, s.tenant_id)
             WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             GROUP BY e.error_no, e.code, e.http_status, e.request_path,
                      e.tenant_id, t.slug, t.name, e.store_id, s.slug, s.name
             ORDER BY count DESC, latest_at DESC
             LIMIT 20"
        )->fetchAll();

        $tier0 = $pdo->query(
            "SELECT e.error_no, e.code, e.http_status, e.request_path,
                    e.tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                    e.store_id, s.slug AS store_slug, s.name AS store_name,
                    COUNT(*) AS count, MAX(e.created_at) AS latest_at
             FROM error_log e
             LEFT JOIN stores s ON s.id = e.store_id
             LEFT JOIN tenants t ON t.id = COALESCE(e.tenant_id, s.tenant_id)
             WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND (
                 e.code IN (
                   'ALREADY_PAID','AMOUNT_MISMATCH','CHECKOUT_FAILED','GATEWAY_ERROR',
                   'GATEWAY_NOT_CONFIGURED','NO_GATEWAY','ONLINE_PAYMENT_REQUIRED',
                   'PAYMENT_GATEWAY_ERROR','PAYMENT_NOT_AVAILABLE','PAYMENT_NOT_CONFIGURED',
                   'PAYMENT_NOT_CONFIRMED','PAYMENT_NOT_FOUND','PAYMENT_RECORD_FAILED',
                   'REFUND_FAILED','SMAREGI_API_ERROR','SMAREGI_NOT_CONFIGURED',
                   'STRIPE_MISMATCH','VERIFICATION_FAILED'
                 )
                 OR e.request_path LIKE '%payment%'
                 OR e.request_path LIKE '%checkout%'
                 OR e.request_path LIKE '%cashier%'
                 OR e.request_path LIKE '%receipt%'
                 OR e.request_path LIKE '%smaregi%'
               )
             GROUP BY e.error_no, e.code, e.http_status, e.request_path,
                      e.tenant_id, t.slug, t.name, e.store_id, s.slug, s.name
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
            "SELECT me.event_type, me.severity, me.source, me.title,
                    me.tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                    me.store_id, s.slug AS store_slug, s.name AS store_name,
                    me.created_at
             FROM monitor_events me
             LEFT JOIN stores s ON s.id = me.store_id
             LEFT JOIN tenants t ON t.id = COALESCE(me.tenant_id, s.tenant_id)
             WHERE me.resolved = 0
             ORDER BY me.created_at DESC
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
        'pending_payment_orders' => [],
        'gateway_status_24h' => [],
        'gateway_problem_payments_24h' => null,
        'gateway_problem_payments' => [],
        'pending_refunds_over_10m' => null,
        'pending_refunds' => [],
        'emergency_unresolved' => null,
        'emergency_unresolved_items' => [],
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

                $problemCountStmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM payments
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                       AND gateway_name IS NOT NULL
                       AND gateway_name <> ''
                       AND COALESCE(gateway_status, '') NOT IN ('', 'none', 'succeeded')"
                );
                $snapshot['gateway_problem_payments_24h'] = (int)$problemCountStmt->fetchColumn();

                $problemStmt = $pdo->query(
                    "SELECT p.id AS payment_id, p.store_id,
                            s.slug AS store_slug, s.name AS store_name,
                            t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                            p.total_amount, p.payment_method, p.gateway_name, p.gateway_status,
                            p.external_payment_id, p.created_at, p.paid_at
                     FROM payments p
                     LEFT JOIN stores s ON s.id = p.store_id
                     LEFT JOIN tenants t ON t.id = s.tenant_id
                     WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                       AND p.gateway_name IS NOT NULL
                       AND p.gateway_name <> ''
                       AND COALESCE(p.gateway_status, '') NOT IN ('', 'none', 'succeeded')
                     ORDER BY p.created_at DESC
                     LIMIT 10"
                );
                $snapshot['gateway_problem_payments'] = $problemStmt ? $problemStmt->fetchAll() : [];
                if ($snapshot['gateway_problem_payments_24h'] > 0) {
                    tier0_raise_status($snapshot, 'warn');
                }
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
                    tier0_raise_status($snapshot, 'error');
                }

                $refundStmt = $pdo->query(
                    "SELECT p.id AS payment_id, p.store_id,
                            s.slug AS store_slug, s.name AS store_name,
                            t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                            p.total_amount, p.payment_method, p.gateway_name, p.gateway_status,
                            p.refund_status, p.refund_amount, p.refund_id,
                            p.created_at, p.paid_at, p.refunded_at
                     FROM payments p
                     LEFT JOIN stores s ON s.id = p.store_id
                     LEFT JOIN tenants t ON t.id = s.tenant_id
                     WHERE p.refund_status = 'pending'
                       AND COALESCE(p.refunded_at, p.created_at) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                     ORDER BY COALESCE(p.refunded_at, p.created_at) ASC
                     LIMIT 10"
                );
                $snapshot['pending_refunds'] = $refundStmt ? $refundStmt->fetchAll() : [];
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
                tier0_raise_status($snapshot, 'warn');
            }

            $orderStmt = $pdo->query(
                "SELECT o.id AS order_id, o.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        o.table_id, o.total_amount, o.status, o.order_type,
                        o.created_at, o.updated_at
                 FROM orders o
                 LEFT JOIN stores s ON s.id = o.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE o.status = 'pending_payment'
                   AND o.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY o.updated_at ASC
                 LIMIT 10"
            );
            $snapshot['pending_payment_orders'] = $orderStmt ? $orderStmt->fetchAll() : [];
        }

        if (table_exists($pdo, 'emergency_payments') && column_exists($pdo, 'emergency_payments', 'resolution_status')) {
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM emergency_payments
                 WHERE resolution_status IN ('unresolved','pending')
                    OR status IN ('conflict','pending_review','failed')"
            );
            $snapshot['emergency_unresolved'] = (int)$stmt->fetchColumn();
            if ($snapshot['emergency_unresolved'] > 0) {
                tier0_raise_status($snapshot, 'warn');
            }

            $emergencyStmt = $pdo->query(
                "SELECT ep.id AS emergency_payment_id,
                        ep.tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        ep.store_id, s.slug AS store_slug, s.name AS store_name,
                        ep.local_emergency_payment_id, ep.table_id, ep.table_code,
                        ep.total_amount, ep.payment_method, ep.status, ep.resolution_status,
                        ep.conflict_reason, ep.server_received_at, ep.updated_at
                 FROM emergency_payments ep
                 LEFT JOIN tenants t ON t.id = ep.tenant_id
                 LEFT JOIN stores s ON s.id = ep.store_id
                 WHERE ep.resolution_status IN ('unresolved','pending')
                    OR ep.status IN ('conflict','pending_review','failed')
                 ORDER BY ep.server_received_at DESC
                 LIMIT 10"
            );
            $snapshot['emergency_unresolved_items'] = $emergencyStmt ? $emergencyStmt->fetchAll() : [];
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function tier0_raise_status(array &$snapshot, string $status): void
{
    $rank = ['ok' => 0, 'warn' => 1, 'error' => 2, 'unknown' => 3];
    $current = $snapshot['status'] ?? 'ok';
    if (($rank[$status] ?? 0) > ($rank[$current] ?? 0)) {
        $snapshot['status'] = $status;
    }
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
