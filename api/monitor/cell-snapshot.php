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
$snapshot['kds'] = fetch_kds_operations_snapshot($pdo);
$snapshot['register'] = fetch_register_operations_snapshot($pdo);
$snapshot['terminal_heartbeat'] = apply_terminal_heartbeat_operational_policy(
    fetch_terminal_heartbeat_snapshot($pdo),
    $snapshot
);

if (($snapshot['db']['status'] ?? 'ng') !== 'ok') {
    $snapshot['ok'] = false;
}
if (!empty($snapshot['cron']['lag_sec']) && (int)$snapshot['cron']['lag_sec'] > 900) {
    $snapshot['ok'] = false;
}
if (!empty($snapshot['tier0']['status']) && $snapshot['tier0']['status'] === 'error') {
    $snapshot['ok'] = false;
}
foreach (['kds', 'register'] as $section) {
    if (!empty($snapshot[$section]['status']) && $snapshot[$section]['status'] === 'error') {
        $snapshot['ok'] = false;
    }
}
if (terminal_heartbeat_blocks_snapshot($snapshot)) {
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

function fetch_kds_operations_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'thresholds' => [
            'preparing_warn_min' => 15,
            'ready_error_min' => 30,
            'kitchen_call_warn_min' => 10,
        ],
        'orders_available' => false,
        'order_items_available' => false,
        'call_alerts_available' => false,
        'preparing_orders_over_15m' => null,
        'preparing_orders' => [],
        'ready_orders_over_30m' => null,
        'ready_orders' => [],
        'preparing_items_over_15m' => null,
        'preparing_items' => [],
        'ready_items_over_30m' => null,
        'ready_items' => [],
        'pending_kitchen_calls_over_10m' => null,
        'pending_kitchen_calls' => [],
    ];

    try {
        if (table_exists($pdo, 'orders')) {
            $snapshot['orders_available'] = true;

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM orders
                 WHERE status = 'preparing'
                   AND COALESCE(prepared_at, updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $snapshot['preparing_orders_over_15m'] = (int)$stmt->fetchColumn();
            if ($snapshot['preparing_orders_over_15m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT o.id AS order_id, o.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        o.table_id, o.total_amount, o.status, o.order_type,
                        o.created_at, o.updated_at, o.prepared_at, o.ready_at
                 FROM orders o
                 LEFT JOIN stores s ON s.id = o.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE o.status = 'preparing'
                   AND COALESCE(o.prepared_at, o.updated_at, o.created_at) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY COALESCE(o.prepared_at, o.updated_at, o.created_at) ASC
                 LIMIT 10"
            );
            $snapshot['preparing_orders'] = $rows ? $rows->fetchAll() : [];

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM orders
                 WHERE status = 'ready'
                   AND COALESCE(ready_at, updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );
            $snapshot['ready_orders_over_30m'] = (int)$stmt->fetchColumn();
            if ($snapshot['ready_orders_over_30m'] > 0) {
                raise_operational_status($snapshot, 'error');
            }

            $rows = $pdo->query(
                "SELECT o.id AS order_id, o.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        o.table_id, o.total_amount, o.status, o.order_type,
                        o.created_at, o.updated_at, o.prepared_at, o.ready_at
                 FROM orders o
                 LEFT JOIN stores s ON s.id = o.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE o.status = 'ready'
                   AND COALESCE(o.ready_at, o.updated_at, o.created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 ORDER BY COALESCE(o.ready_at, o.updated_at, o.created_at) ASC
                 LIMIT 10"
            );
            $snapshot['ready_orders'] = $rows ? $rows->fetchAll() : [];
        }

        if (table_exists($pdo, 'order_items')) {
            $snapshot['order_items_available'] = true;

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM order_items
                 WHERE status = 'preparing'
                   AND COALESCE(prepared_at, updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $snapshot['preparing_items_over_15m'] = (int)$stmt->fetchColumn();
            if ($snapshot['preparing_items_over_15m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT oi.id AS order_item_id, oi.order_id, oi.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        oi.name, oi.qty, oi.status, oi.created_at, oi.updated_at, oi.prepared_at, oi.ready_at
                 FROM order_items oi
                 LEFT JOIN stores s ON s.id = oi.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE oi.status = 'preparing'
                   AND COALESCE(oi.prepared_at, oi.updated_at, oi.created_at) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY COALESCE(oi.prepared_at, oi.updated_at, oi.created_at) ASC
                 LIMIT 10"
            );
            $snapshot['preparing_items'] = $rows ? $rows->fetchAll() : [];

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM order_items
                 WHERE status = 'ready'
                   AND COALESCE(ready_at, updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );
            $snapshot['ready_items_over_30m'] = (int)$stmt->fetchColumn();
            if ($snapshot['ready_items_over_30m'] > 0) {
                raise_operational_status($snapshot, 'error');
            }

            $rows = $pdo->query(
                "SELECT oi.id AS order_item_id, oi.order_id, oi.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        oi.name, oi.qty, oi.status, oi.created_at, oi.updated_at, oi.prepared_at, oi.ready_at
                 FROM order_items oi
                 LEFT JOIN stores s ON s.id = oi.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE oi.status = 'ready'
                   AND COALESCE(oi.ready_at, oi.updated_at, oi.created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 ORDER BY COALESCE(oi.ready_at, oi.updated_at, oi.created_at) ASC
                 LIMIT 10"
            );
            $snapshot['ready_items'] = $rows ? $rows->fetchAll() : [];
        }

        if (table_exists($pdo, 'call_alerts')) {
            $snapshot['call_alerts_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM call_alerts
                 WHERE type IN ('kitchen_call','product_ready')
                   AND status IN ('pending','in_progress')
                   AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
            );
            $snapshot['pending_kitchen_calls_over_10m'] = (int)$stmt->fetchColumn();
            if ($snapshot['pending_kitchen_calls_over_10m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT ca.id AS call_alert_id, ca.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        ca.table_id, ca.table_code, ca.type, ca.reason, ca.item_name,
                        ca.status, ca.created_at, ca.acknowledged_at
                 FROM call_alerts ca
                 LEFT JOIN stores s ON s.id = ca.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE ca.type IN ('kitchen_call','product_ready')
                   AND ca.status IN ('pending','in_progress')
                   AND ca.created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                 ORDER BY ca.created_at ASC
                 LIMIT 10"
            );
            $snapshot['pending_kitchen_calls'] = $rows ? $rows->fetchAll() : [];
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function fetch_register_operations_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'thresholds' => [
            'bill_requested_warn_min' => 15,
            'pending_payment_warn_min' => 15,
            'open_difference_error_min' => 30,
            'unclosed_register_error_days' => 1,
        ],
        'table_sessions_available' => false,
        'orders_available' => false,
        'cash_log_available' => false,
        'pre_close_logs_available' => false,
        'bill_requested_sessions_over_15m' => null,
        'bill_requested_sessions' => [],
        'pending_payment_orders_over_15m' => null,
        'pending_payment_orders' => [],
        'open_pre_close_differences_over_30m' => null,
        'open_pre_close_differences' => [],
        'unclosed_register_days' => null,
        'unclosed_registers' => [],
        'recent_close_differences_24h' => null,
        'recent_close_differences' => [],
    ];

    try {
        if (table_exists($pdo, 'table_sessions')) {
            $snapshot['table_sessions_available'] = true;
            $hasStatusChangedAt = column_exists($pdo, 'table_sessions', 'status_changed_at');
            $billRequestedAtExpr = $hasStatusChangedAt
                ? 'COALESCE(status_changed_at, started_at)'
                : 'started_at';
            $qualifiedBillRequestedAtExpr = $hasStatusChangedAt
                ? 'COALESCE(ts.status_changed_at, ts.started_at)'
                : 'ts.started_at';
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM table_sessions
                 WHERE status = 'bill_requested'
                   AND " . $billRequestedAtExpr . " < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $snapshot['bill_requested_sessions_over_15m'] = (int)$stmt->fetchColumn();
            if ($snapshot['bill_requested_sessions_over_15m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT ts.id AS table_session_id, ts.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        ts.table_id, ts.status, ts.guest_count, ts.started_at,
                        " . $qualifiedBillRequestedAtExpr . " AS bill_requested_at,
                        ts.last_order_at
                 FROM table_sessions ts
                 LEFT JOIN stores s ON s.id = ts.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE ts.status = 'bill_requested'
                   AND " . $qualifiedBillRequestedAtExpr . " < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY bill_requested_at ASC
                 LIMIT 10"
            );
            $snapshot['bill_requested_sessions'] = $rows ? $rows->fetchAll() : [];
        }

        if (table_exists($pdo, 'orders')) {
            $snapshot['orders_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM orders
                 WHERE status = 'pending_payment'
                   AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $snapshot['pending_payment_orders_over_15m'] = (int)$stmt->fetchColumn();
            if ($snapshot['pending_payment_orders_over_15m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT o.id AS order_id, o.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        o.table_id, o.total_amount, o.status, o.order_type, o.created_at, o.updated_at
                 FROM orders o
                 LEFT JOIN stores s ON s.id = o.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE o.status = 'pending_payment'
                   AND o.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY o.updated_at ASC
                 LIMIT 10"
            );
            $snapshot['pending_payment_orders'] = $rows ? $rows->fetchAll() : [];
        }

        if (table_exists($pdo, 'register_pre_close_logs')) {
            $snapshot['pre_close_logs_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM register_pre_close_logs
                 WHERE status = 'open'
                   AND ABS(COALESCE(difference_amount, 0)) > 0
                   AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );
            $snapshot['open_pre_close_differences_over_30m'] = (int)$stmt->fetchColumn();
            if ($snapshot['open_pre_close_differences_over_30m'] > 0) {
                raise_operational_status($snapshot, 'error');
            }

            $rows = $pdo->query(
                "SELECT rpcl.id AS pre_close_log_id, rpcl.tenant_id,
                        t.slug AS tenant_slug, t.name AS tenant_name,
                        rpcl.store_id, s.slug AS store_slug, s.name AS store_name,
                        rpcl.business_day, rpcl.actual_cash_amount, rpcl.expected_cash_amount,
                        rpcl.difference_amount, rpcl.status, rpcl.created_at
                 FROM register_pre_close_logs rpcl
                 LEFT JOIN tenants t ON t.id = rpcl.tenant_id
                 LEFT JOIN stores s ON s.id = rpcl.store_id
                 WHERE rpcl.status = 'open'
                   AND ABS(COALESCE(rpcl.difference_amount, 0)) > 0
                   AND rpcl.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 ORDER BY rpcl.created_at ASC
                 LIMIT 10"
            );
            $snapshot['open_pre_close_differences'] = $rows ? $rows->fetchAll() : [];
        }

        if (table_exists($pdo, 'cash_log')) {
            $snapshot['cash_log_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM cash_log opened
                 WHERE opened.type = 'open'
                   AND opened.created_at < CURDATE()
                   AND opened.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   AND NOT EXISTS (
                     SELECT 1
                     FROM cash_log closed
                     WHERE closed.store_id = opened.store_id
                       AND closed.type = 'close'
                       AND closed.created_at > opened.created_at
                       AND closed.created_at < DATE_ADD(DATE(opened.created_at), INTERVAL 2 DAY)
                   )"
            );
            $snapshot['unclosed_register_days'] = (int)$stmt->fetchColumn();
            if ($snapshot['unclosed_register_days'] > 0) {
                raise_operational_status($snapshot, 'error');
            }

            $rows = $pdo->query(
                "SELECT opened.id AS cash_open_id, opened.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        DATE(opened.created_at) AS business_day, opened.amount, opened.created_at
                 FROM cash_log opened
                 LEFT JOIN stores s ON s.id = opened.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE opened.type = 'open'
                   AND opened.created_at < CURDATE()
                   AND opened.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   AND NOT EXISTS (
                     SELECT 1
                     FROM cash_log closed
                     WHERE closed.store_id = opened.store_id
                       AND closed.type = 'close'
                       AND closed.created_at > opened.created_at
                       AND closed.created_at < DATE_ADD(DATE(opened.created_at), INTERVAL 2 DAY)
                   )
                 ORDER BY opened.created_at ASC
                 LIMIT 10"
            );
            $snapshot['unclosed_registers'] = $rows ? $rows->fetchAll() : [];

            if (column_exists($pdo, 'cash_log', 'difference_amount')) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM cash_log
                     WHERE type = 'close'
                       AND ABS(COALESCE(difference_amount, 0)) > 0
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                );
                $snapshot['recent_close_differences_24h'] = (int)$stmt->fetchColumn();
                if ($snapshot['recent_close_differences_24h'] > 0) {
                    raise_operational_status($snapshot, 'warn');
                }

                $rows = $pdo->query(
                    "SELECT cl.id AS cash_close_id, cl.store_id,
                            s.slug AS store_slug, s.name AS store_name,
                            t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                            cl.amount, cl.expected_amount, cl.difference_amount,
                            cl.cash_sales_amount, cl.card_sales_amount, cl.qr_sales_amount,
                            cl.created_at
                     FROM cash_log cl
                     LEFT JOIN stores s ON s.id = cl.store_id
                     LEFT JOIN tenants t ON t.id = s.tenant_id
                     WHERE cl.type = 'close'
                       AND ABS(COALESCE(cl.difference_amount, 0)) > 0
                       AND cl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     ORDER BY cl.created_at DESC
                     LIMIT 10"
                );
                $snapshot['recent_close_differences'] = $rows ? $rows->fetchAll() : [];
            }
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function fetch_terminal_heartbeat_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'unknown',
        'available' => false,
        'thresholds' => [
            'stale_warn_sec' => 600,
            'stale_error_sec' => 1800,
        ],
        'total' => null,
        'mode_counts' => [],
        'stale_over_10m' => null,
        'stale_over_30m' => null,
        'stale_terminals' => [],
    ];

    if (!table_exists($pdo, 'handy_terminals')) {
        return $snapshot;
    }

    $snapshot['status'] = 'ok';
    $snapshot['available'] = true;

    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM handy_terminals');
        $snapshot['total'] = (int)$stmt->fetchColumn();

        $rows = $pdo->query(
            "SELECT terminal_mode, COUNT(*) AS count
             FROM handy_terminals
             GROUP BY terminal_mode
             ORDER BY terminal_mode ASC"
        );
        $snapshot['mode_counts'] = $rows ? normalize_count_rows($rows->fetchAll()) : [];

        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM handy_terminals
             WHERE last_seen_at IS NULL
                OR last_seen_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
        $snapshot['stale_over_10m'] = (int)$stmt->fetchColumn();
        if ($snapshot['stale_over_10m'] > 0) {
            raise_operational_status($snapshot, 'warn');
        }

        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM handy_terminals
             WHERE last_seen_at IS NULL
                OR last_seen_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        $snapshot['stale_over_30m'] = (int)$stmt->fetchColumn();
        if ($snapshot['stale_over_30m'] > 0) {
            raise_operational_status($snapshot, 'error');
        }

        $rows = $pdo->query(
            "SELECT ht.id AS terminal_id, ht.tenant_id,
                    t.slug AS tenant_slug, t.name AS tenant_name,
                    ht.store_id, s.slug AS store_slug, s.name AS store_name,
                    ht.device_uid, ht.device_label, ht.terminal_mode,
                    ht.sound_enabled, ht.realert_enabled, ht.realert_interval_sec,
                    ht.last_seen_at, ht.updated_at
             FROM handy_terminals ht
             LEFT JOIN tenants t ON t.id = ht.tenant_id
             LEFT JOIN stores s ON s.id = ht.store_id
             WHERE ht.last_seen_at IS NULL
                OR ht.last_seen_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
             ORDER BY ht.last_seen_at IS NULL DESC, ht.last_seen_at ASC, ht.updated_at ASC
             LIMIT 20"
        );
        $snapshot['stale_terminals'] = $rows ? $rows->fetchAll() : [];
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function apply_terminal_heartbeat_operational_policy(array $heartbeat, array $snapshot): array
{
    $environment = defined('APP_ENVIRONMENT') ? (string)APP_ENVIRONMENT : (string)($snapshot['environment'] ?? '');
    $cellEnvironment = (string)($snapshot['registry']['cell']['environment'] ?? ($snapshot['registry']['control_cell']['environment'] ?? ''));
    $nonProductionEnvironments = ['test', 'dev', 'development', 'local', 'demo', 'pseudo-prod'];
    $isNonProduction = in_array($environment, $nonProductionEnvironments, true)
        || in_array($cellEnvironment, $nonProductionEnvironments, true);

    $heartbeat['blocking'] = !$isNonProduction;
    $heartbeat['operator_level'] = ($heartbeat['status'] ?? 'unknown') === 'ok'
        ? 'ok'
        : ($isNonProduction ? 'check' : 'incident');
    $heartbeat['policy_reason'] = $isNonProduction
        ? 'non_production_terminal_heartbeat_is_check_only'
        : 'production_terminal_heartbeat_blocks_snapshot';

    return $heartbeat;
}

function terminal_heartbeat_blocks_snapshot(array $snapshot): bool
{
    $heartbeat = $snapshot['terminal_heartbeat'] ?? [];
    if (!is_array($heartbeat)) {
        return false;
    }
    return ($heartbeat['status'] ?? '') === 'error' && !empty($heartbeat['blocking']);
}

function raise_operational_status(array &$snapshot, string $status): void
{
    $rank = ['ok' => 0, 'warn' => 1, 'error' => 2, 'unknown' => 0];
    $current = $snapshot['status'] ?? 'ok';
    if (($rank[$status] ?? 0) > ($rank[$current] ?? 0)) {
        $snapshot['status'] = $status;
    }
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
