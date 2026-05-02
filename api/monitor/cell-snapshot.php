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
$snapshot['reservations'] = fetch_reservation_operations_snapshot($pdo);
$snapshot['shifts'] = fetch_shift_operations_snapshot($pdo);
$snapshot['takeout'] = fetch_takeout_operations_snapshot($pdo);
$snapshot['menu_inventory'] = fetch_menu_inventory_operations_snapshot($pdo);
$snapshot['customer_line'] = fetch_customer_line_operations_snapshot($pdo);
$snapshot['external_integrations'] = fetch_external_integrations_operations_snapshot($pdo);
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
foreach (['kds', 'register', 'reservations', 'shifts', 'takeout', 'menu_inventory', 'customer_line', 'external_integrations'] as $section) {
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

function fetch_reservation_operations_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'thresholds' => [
            'pending_warn_min' => 15,
            'arrival_warn_min' => 30,
            'notification_queue_warn_min' => 10,
        ],
        'reservations_available' => false,
        'notifications_available' => false,
        'pending_reservations_over_15m' => null,
        'pending_reservations' => [],
        'arrival_unhandled_over_30m' => null,
        'arrival_unhandled' => [],
        'failed_notifications_24h' => null,
        'failed_notifications' => [],
        'queued_notifications_over_10m' => null,
        'queued_notifications' => [],
    ];

    try {
        if (table_exists($pdo, 'reservations')) {
            $snapshot['reservations_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM reservations
                 WHERE status = 'pending'
                   AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $snapshot['pending_reservations_over_15m'] = (int)$stmt->fetchColumn();
            if ($snapshot['pending_reservations_over_15m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT r.id AS reservation_id, r.tenant_id,
                        t.slug AS tenant_slug, t.name AS tenant_name,
                        r.store_id, s.slug AS store_slug, s.name AS store_name,
                        r.customer_name, r.party_size, r.reserved_at, r.status,
                        r.source, r.created_at, r.updated_at
                 FROM reservations r
                 LEFT JOIN stores s ON s.id = r.store_id
                 LEFT JOIN tenants t ON t.id = r.tenant_id
                 WHERE r.status = 'pending'
                   AND r.created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY r.created_at ASC
                 LIMIT 10"
            );
            $snapshot['pending_reservations'] = $rows ? $rows->fetchAll() : [];

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM reservations
                 WHERE status = 'confirmed'
                   AND reserved_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                   AND table_session_id IS NULL"
            );
            $snapshot['arrival_unhandled_over_30m'] = (int)$stmt->fetchColumn();
            if ($snapshot['arrival_unhandled_over_30m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT r.id AS reservation_id, r.tenant_id,
                        t.slug AS tenant_slug, t.name AS tenant_name,
                        r.store_id, s.slug AS store_slug, s.name AS store_name,
                        r.customer_name, r.party_size, r.reserved_at, r.status,
                        r.source, r.created_at, r.updated_at
                 FROM reservations r
                 LEFT JOIN stores s ON s.id = r.store_id
                 LEFT JOIN tenants t ON t.id = r.tenant_id
                 WHERE r.status = 'confirmed'
                   AND r.reserved_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                   AND r.table_session_id IS NULL
                 ORDER BY r.reserved_at ASC
                 LIMIT 10"
            );
            $snapshot['arrival_unhandled'] = $rows ? $rows->fetchAll() : [];
        }

        if (table_exists($pdo, 'reservation_notifications_log')) {
            $snapshot['notifications_available'] = true;
            $resolvedClause = column_exists($pdo, 'reservation_notifications_log', 'resolved_at')
                ? 'AND resolved_at IS NULL'
                : '';
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM reservation_notifications_log
                 WHERE status = 'failed'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   " . $resolvedClause
            );
            $snapshot['failed_notifications_24h'] = (int)$stmt->fetchColumn();
            if ($snapshot['failed_notifications_24h'] > 0) {
                raise_operational_status($snapshot, 'error');
            }

            $rows = $pdo->query(
                "SELECT rnl.id AS notification_id, rnl.reservation_id, rnl.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        rnl.notification_type, rnl.channel, rnl.status,
                        rnl.error_message, rnl.created_at, rnl.sent_at
                 FROM reservation_notifications_log rnl
                 LEFT JOIN stores s ON s.id = rnl.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE rnl.status = 'failed'
                   AND rnl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   " . str_replace('resolved_at', 'rnl.resolved_at', $resolvedClause) . "
                 ORDER BY rnl.created_at DESC
                 LIMIT 10"
            );
            $snapshot['failed_notifications'] = $rows ? $rows->fetchAll() : [];

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM reservation_notifications_log
                 WHERE status = 'queued'
                   AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
            );
            $snapshot['queued_notifications_over_10m'] = (int)$stmt->fetchColumn();
            if ($snapshot['queued_notifications_over_10m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT rnl.id AS notification_id, rnl.reservation_id, rnl.store_id,
                        s.slug AS store_slug, s.name AS store_name,
                        t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        rnl.notification_type, rnl.channel, rnl.status,
                        rnl.created_at, rnl.sent_at
                 FROM reservation_notifications_log rnl
                 LEFT JOIN stores s ON s.id = rnl.store_id
                 LEFT JOIN tenants t ON t.id = s.tenant_id
                 WHERE rnl.status = 'queued'
                   AND rnl.created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                 ORDER BY rnl.created_at ASC
                 LIMIT 10"
            );
            $snapshot['queued_notifications'] = $rows ? $rows->fetchAll() : [];
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function fetch_shift_operations_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'thresholds' => [
            'clock_in_missing_warn_min' => 30,
            'open_attendance_error_hours' => 12,
            'help_request_warn_min' => 30,
        ],
        'assignments_available' => false,
        'attendance_available' => false,
        'help_requests_available' => false,
        'clock_in_missing_over_30m' => null,
        'clock_in_missing' => [],
        'open_attendance_over_12h' => null,
        'open_attendance' => [],
        'pending_help_requests_over_30m' => null,
        'pending_help_requests' => [],
    ];

    try {
        if (table_exists($pdo, 'shift_assignments')) {
            $snapshot['assignments_available'] = true;
            $hasAttendance = table_exists($pdo, 'attendance_logs');
            $snapshot['attendance_available'] = $hasAttendance;

            if ($hasAttendance) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM shift_assignments sa
                     WHERE sa.status IN ('published','confirmed')
                       AND TIMESTAMP(sa.shift_date, sa.start_time) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                       AND TIMESTAMP(sa.shift_date, sa.end_time) > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                       AND NOT EXISTS (
                         SELECT 1
                         FROM attendance_logs al
                         WHERE al.shift_assignment_id = sa.id
                            OR (al.tenant_id = sa.tenant_id AND al.user_id = sa.user_id AND DATE(al.clock_in) = sa.shift_date)
                       )"
                );
                $snapshot['clock_in_missing_over_30m'] = (int)$stmt->fetchColumn();
                if ($snapshot['clock_in_missing_over_30m'] > 0) {
                    raise_operational_status($snapshot, 'warn');
                }

                $rows = $pdo->query(
                    "SELECT sa.id AS shift_assignment_id, sa.tenant_id,
                            t.slug AS tenant_slug, t.name AS tenant_name,
                            sa.store_id, s.slug AS store_slug, s.name AS store_name,
                            sa.user_id, u.display_name, sa.shift_date, sa.start_time,
                            sa.end_time, sa.role_type, sa.status
                     FROM shift_assignments sa
                     LEFT JOIN stores s ON s.id = sa.store_id
                     LEFT JOIN tenants t ON t.id = sa.tenant_id
                     LEFT JOIN users u ON u.id = sa.user_id
                     WHERE sa.status IN ('published','confirmed')
                       AND TIMESTAMP(sa.shift_date, sa.start_time) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                       AND TIMESTAMP(sa.shift_date, sa.end_time) > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                       AND NOT EXISTS (
                         SELECT 1
                         FROM attendance_logs al
                         WHERE al.shift_assignment_id = sa.id
                            OR (al.tenant_id = sa.tenant_id AND al.user_id = sa.user_id AND DATE(al.clock_in) = sa.shift_date)
                       )
                     ORDER BY sa.shift_date ASC, sa.start_time ASC
                     LIMIT 10"
                );
                $snapshot['clock_in_missing'] = $rows ? $rows->fetchAll() : [];

                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM attendance_logs
                     WHERE clock_out IS NULL
                       AND clock_in < DATE_SUB(NOW(), INTERVAL 12 HOUR)"
                );
                $snapshot['open_attendance_over_12h'] = (int)$stmt->fetchColumn();
                if ($snapshot['open_attendance_over_12h'] > 0) {
                    raise_operational_status($snapshot, 'error');
                }

                $rows = $pdo->query(
                    "SELECT al.id AS attendance_id, al.tenant_id,
                            t.slug AS tenant_slug, t.name AS tenant_name,
                            al.store_id, s.slug AS store_slug, s.name AS store_name,
                            al.user_id, u.display_name, al.shift_assignment_id,
                            al.clock_in, al.status
                     FROM attendance_logs al
                     LEFT JOIN stores s ON s.id = al.store_id
                     LEFT JOIN tenants t ON t.id = al.tenant_id
                     LEFT JOIN users u ON u.id = al.user_id
                     WHERE al.clock_out IS NULL
                       AND al.clock_in < DATE_SUB(NOW(), INTERVAL 12 HOUR)
                     ORDER BY al.clock_in ASC
                     LIMIT 10"
                );
                $snapshot['open_attendance'] = $rows ? $rows->fetchAll() : [];
            }
        }

        if (table_exists($pdo, 'shift_help_requests')) {
            $snapshot['help_requests_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM shift_help_requests
                 WHERE status = 'pending'
                   AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );
            $snapshot['pending_help_requests_over_30m'] = (int)$stmt->fetchColumn();
            if ($snapshot['pending_help_requests_over_30m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT shr.id AS help_request_id, shr.tenant_id,
                        t.slug AS tenant_slug, t.name AS tenant_name,
                        shr.from_store_id, fs.slug AS from_store_slug, fs.name AS from_store_name,
                        shr.to_store_id, ts.slug AS to_store_slug, ts.name AS to_store_name,
                        shr.requested_date, shr.start_time, shr.end_time,
                        shr.requested_staff_count, shr.role_hint, shr.status, shr.created_at
                 FROM shift_help_requests shr
                 LEFT JOIN tenants t ON t.id = shr.tenant_id
                 LEFT JOIN stores fs ON fs.id = shr.from_store_id
                 LEFT JOIN stores ts ON ts.id = shr.to_store_id
                 WHERE shr.status = 'pending'
                   AND shr.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 ORDER BY shr.created_at ASC
                 LIMIT 10"
            );
            $snapshot['pending_help_requests'] = $rows ? $rows->fetchAll() : [];
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function fetch_takeout_operations_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'thresholds' => [
            'pending_payment_warn_min' => 15,
            'pickup_overdue_error_min' => 15,
            'ready_waiting_warn_min' => 30,
            'arrival_waiting_warn_min' => 10,
        ],
        'orders_available' => false,
        'pending_payment_over_15m' => null,
        'pending_payment_orders' => [],
        'pickup_overdue_over_15m' => null,
        'pickup_overdue_orders' => [],
        'ready_waiting_over_30m' => null,
        'ready_waiting_orders' => [],
        'ready_notification_failed_24h' => null,
        'ready_notification_failed_orders' => [],
        'arrival_waiting_over_10m' => null,
        'arrival_waiting_orders' => [],
    ];

    if (!table_exists($pdo, 'orders')) {
        return $snapshot;
    }

    $snapshot['orders_available'] = true;
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM orders
             WHERE order_type = 'takeout'
               AND status = 'pending_payment'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $snapshot['pending_payment_over_15m'] = (int)$stmt->fetchColumn();
        if ($snapshot['pending_payment_over_15m'] > 0) {
            raise_operational_status($snapshot, 'warn');
        }

        $snapshot['pending_payment_orders'] = fetch_takeout_order_rows(
            $pdo,
            "o.order_type = 'takeout'
             AND o.status = 'pending_payment'
             AND o.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            'o.updated_at ASC'
        );

        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM orders
             WHERE order_type = 'takeout'
               AND status IN ('pending','preparing')
               AND pickup_at IS NOT NULL
               AND pickup_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $snapshot['pickup_overdue_over_15m'] = (int)$stmt->fetchColumn();
        if ($snapshot['pickup_overdue_over_15m'] > 0) {
            raise_operational_status($snapshot, 'error');
        }

        $snapshot['pickup_overdue_orders'] = fetch_takeout_order_rows(
            $pdo,
            "o.order_type = 'takeout'
             AND o.status IN ('pending','preparing')
             AND o.pickup_at IS NOT NULL
             AND o.pickup_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            'o.pickup_at ASC'
        );

        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM orders
             WHERE order_type = 'takeout'
               AND status = 'ready'
               AND pickup_at IS NOT NULL
               AND pickup_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        $snapshot['ready_waiting_over_30m'] = (int)$stmt->fetchColumn();
        if ($snapshot['ready_waiting_over_30m'] > 0) {
            raise_operational_status($snapshot, 'warn');
        }

        $snapshot['ready_waiting_orders'] = fetch_takeout_order_rows(
            $pdo,
            "o.order_type = 'takeout'
             AND o.status = 'ready'
             AND o.pickup_at IS NOT NULL
             AND o.pickup_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
            'o.pickup_at ASC'
        );

        if (column_exists($pdo, 'orders', 'takeout_ready_notification_status')) {
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM orders
                 WHERE order_type = 'takeout'
                   AND takeout_ready_notification_status = 'failed'
                   AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $snapshot['ready_notification_failed_24h'] = (int)$stmt->fetchColumn();
            if ($snapshot['ready_notification_failed_24h'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $snapshot['ready_notification_failed_orders'] = fetch_takeout_order_rows(
                $pdo,
                "o.order_type = 'takeout'
                 AND o.takeout_ready_notification_status = 'failed'
                 AND o.updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                'o.updated_at DESC'
            );
        }

        if (column_exists($pdo, 'orders', 'takeout_arrived_at')) {
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM orders
                 WHERE order_type = 'takeout'
                   AND takeout_arrived_at IS NOT NULL
                   AND status NOT IN ('served','paid','cancelled')
                   AND takeout_arrived_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
            );
            $snapshot['arrival_waiting_over_10m'] = (int)$stmt->fetchColumn();
            if ($snapshot['arrival_waiting_over_10m'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $snapshot['arrival_waiting_orders'] = fetch_takeout_order_rows(
                $pdo,
                "o.order_type = 'takeout'
                 AND o.takeout_arrived_at IS NOT NULL
                 AND o.status NOT IN ('served','paid','cancelled')
                 AND o.takeout_arrived_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                'o.takeout_arrived_at ASC'
            );
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function fetch_takeout_order_rows(PDO $pdo, string $where, string $orderBy): array
{
    $optionalColumns = [];
    foreach (['takeout_ready_notification_status', 'takeout_ready_notification_error', 'takeout_ops_status', 'takeout_arrived_at'] as $column) {
        if (column_exists($pdo, 'orders', $column)) {
            $optionalColumns[] = 'o.' . $column;
        }
    }
    $optionalSelect = count($optionalColumns) > 0 ? ', ' . implode(', ', $optionalColumns) : '';

    $rows = $pdo->query(
        "SELECT o.id AS order_id, o.store_id,
                s.slug AS store_slug, s.name AS store_name,
                t.id AS tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                o.customer_name, o.customer_phone, o.status, o.total_amount,
                o.pickup_at, o.created_at, o.updated_at" . $optionalSelect . "
         FROM orders o
         LEFT JOIN stores s ON s.id = o.store_id
         LEFT JOIN tenants t ON t.id = s.tenant_id
         WHERE " . $where . "
         ORDER BY " . $orderBy . "
         LIMIT 10"
    );
    return $rows ? $rows->fetchAll() : [];
}

function fetch_menu_inventory_operations_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'thresholds' => [
            'api_error_error_min' => 1,
            'negative_stock_error_min' => 1,
            'low_stock_warn_min' => 1,
        ],
        'menu_available' => false,
        'inventory_available' => false,
        'recipes_available' => false,
        'active_menu_templates' => null,
        'sold_out_menu_templates' => null,
        'store_sold_out_overrides' => null,
        'local_menu_items' => null,
        'low_stock_ingredients' => null,
        'low_stock_items' => [],
        'negative_stock_ingredients' => null,
        'negative_stock_items' => [],
        'ingredients_without_threshold' => null,
        'active_menu_without_recipe' => null,
        'api_errors_15m' => null,
        'api_error_rows' => [],
    ];

    try {
        if (table_exists($pdo, 'menu_templates')) {
            $snapshot['menu_available'] = true;

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM menu_templates
                 WHERE is_active = 1"
            );
            $snapshot['active_menu_templates'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM menu_templates
                 WHERE is_active = 1
                   AND is_sold_out = 1"
            );
            $snapshot['sold_out_menu_templates'] = (int)$stmt->fetchColumn();

            if (table_exists($pdo, 'recipes')) {
                $snapshot['recipes_available'] = true;
                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM menu_templates mt
                     WHERE mt.is_active = 1
                       AND NOT EXISTS (
                         SELECT 1
                         FROM recipes r
                         WHERE r.menu_template_id = mt.id
                       )"
                );
                $snapshot['active_menu_without_recipe'] = (int)$stmt->fetchColumn();
            }
        }

        if (table_exists($pdo, 'store_menu_overrides')) {
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM store_menu_overrides
                 WHERE is_sold_out = 1"
            );
            $snapshot['store_sold_out_overrides'] = (int)$stmt->fetchColumn();
        }

        if (table_exists($pdo, 'store_local_items')) {
            $stmt = $pdo->query('SELECT COUNT(*) FROM store_local_items');
            $snapshot['local_menu_items'] = (int)$stmt->fetchColumn();
        }

        if (table_exists($pdo, 'ingredients')) {
            $snapshot['inventory_available'] = true;

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM ingredients
                 WHERE is_active = 1
                   AND low_stock_threshold IS NOT NULL
                   AND stock_quantity <= low_stock_threshold"
            );
            $snapshot['low_stock_ingredients'] = (int)$stmt->fetchColumn();
            if ($snapshot['low_stock_ingredients'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }

            $rows = $pdo->query(
                "SELECT i.id AS ingredient_id, i.tenant_id,
                        t.slug AS tenant_slug, t.name AS tenant_name,
                        i.name, i.unit, i.stock_quantity, i.low_stock_threshold, i.updated_at
                 FROM ingredients i
                 LEFT JOIN tenants t ON t.id = i.tenant_id
                 WHERE i.is_active = 1
                   AND i.low_stock_threshold IS NOT NULL
                   AND i.stock_quantity <= i.low_stock_threshold
                 ORDER BY (i.stock_quantity - i.low_stock_threshold) ASC, i.updated_at DESC
                 LIMIT 10"
            );
            $snapshot['low_stock_items'] = $rows ? $rows->fetchAll() : [];

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM ingredients
                 WHERE is_active = 1
                   AND stock_quantity < 0"
            );
            $snapshot['negative_stock_ingredients'] = (int)$stmt->fetchColumn();
            if ($snapshot['negative_stock_ingredients'] > 0) {
                raise_operational_status($snapshot, 'error');
            }

            $rows = $pdo->query(
                "SELECT i.id AS ingredient_id, i.tenant_id,
                        t.slug AS tenant_slug, t.name AS tenant_name,
                        i.name, i.unit, i.stock_quantity, i.low_stock_threshold, i.updated_at
                 FROM ingredients i
                 LEFT JOIN tenants t ON t.id = i.tenant_id
                 WHERE i.is_active = 1
                   AND i.stock_quantity < 0
                 ORDER BY i.stock_quantity ASC, i.updated_at DESC
                 LIMIT 10"
            );
            $snapshot['negative_stock_items'] = $rows ? $rows->fetchAll() : [];

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM ingredients
                 WHERE is_active = 1
                   AND low_stock_threshold IS NULL"
            );
            $snapshot['ingredients_without_threshold'] = (int)$stmt->fetchColumn();
        }

        $apiErrors = fetch_operation_error_log_snapshot(
            $pdo,
            "(request_path LIKE '%/menu%'
              OR request_path LIKE '%/ingredients%'
              OR request_path LIKE '%/recipes%'
              OR request_path LIKE '%inventory%'
              OR code IN ('IMPORT_FAILED','MIGRATION','FILE_READ_ERROR','DELETE_FAILED'))"
        );
        $snapshot['api_errors_15m'] = $apiErrors['count'];
        $snapshot['api_error_rows'] = $apiErrors['rows'];
        if ((int)$snapshot['api_errors_15m'] > 0) {
            raise_operational_status($snapshot, 'error');
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function fetch_customer_line_operations_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'thresholds' => [
            'api_error_error_min' => 1,
            'enabled_missing_token_error_min' => 1,
            'expired_link_tokens_warn_min' => 1,
        ],
        'line_settings_available' => false,
        'line_links_available' => false,
        'line_tokens_available' => false,
        'enabled_tenants' => null,
        'enabled_missing_token' => null,
        'enabled_missing_token_tenants' => [],
        'linked_customers' => null,
        'stale_link_interactions_over_90d' => null,
        'expired_unused_tokens' => null,
        'api_errors_15m' => null,
        'api_error_rows' => [],
    ];

    try {
        if (table_exists($pdo, 'tenant_line_settings')) {
            $snapshot['line_settings_available'] = true;

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM tenant_line_settings
                 WHERE is_enabled = 1"
            );
            $snapshot['enabled_tenants'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM tenant_line_settings
                 WHERE is_enabled = 1
                   AND (channel_access_token IS NULL OR channel_access_token = '')"
            );
            $snapshot['enabled_missing_token'] = (int)$stmt->fetchColumn();
            if ($snapshot['enabled_missing_token'] > 0) {
                raise_operational_status($snapshot, 'error');
            }

            $rows = $pdo->query(
                "SELECT tls.tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                        tls.is_enabled, tls.notify_reservation_created,
                        tls.notify_reservation_reminder_day, tls.notify_takeout_ready,
                        tls.last_webhook_at, tls.last_webhook_event_type, tls.updated_at
                 FROM tenant_line_settings tls
                 LEFT JOIN tenants t ON t.id = tls.tenant_id
                 WHERE tls.is_enabled = 1
                   AND (tls.channel_access_token IS NULL OR tls.channel_access_token = '')
                 ORDER BY tls.updated_at DESC
                 LIMIT 10"
            );
            $snapshot['enabled_missing_token_tenants'] = $rows ? $rows->fetchAll() : [];
        }

        if (table_exists($pdo, 'reservation_customer_line_links')) {
            $snapshot['line_links_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM reservation_customer_line_links
                 WHERE link_status = 'linked'"
            );
            $snapshot['linked_customers'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM reservation_customer_line_links
                 WHERE link_status = 'linked'
                   AND last_interaction_at IS NOT NULL
                   AND last_interaction_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            );
            $snapshot['stale_link_interactions_over_90d'] = (int)$stmt->fetchColumn();
        }

        if (table_exists($pdo, 'reservation_customer_line_link_tokens')) {
            $snapshot['line_tokens_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM reservation_customer_line_link_tokens
                 WHERE used_at IS NULL
                   AND revoked_at IS NULL
                   AND expires_at < NOW()"
            );
            $snapshot['expired_unused_tokens'] = (int)$stmt->fetchColumn();
            if ($snapshot['expired_unused_tokens'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }
        }

        $apiErrors = fetch_operation_error_log_snapshot(
            $pdo,
            "(request_path LIKE '%/line/%'
              OR request_path LIKE '%line-%'
              OR request_path LIKE '%line_%'
              OR code IN ('LINE_NOT_CONFIGURED','NOT_CONFIGURED','TOKEN_ISSUE_FAILED','UNLINK_FAILED','INVALID_SIGNATURE'))"
        );
        $snapshot['api_errors_15m'] = $apiErrors['count'];
        $snapshot['api_error_rows'] = $apiErrors['rows'];
        if ((int)$snapshot['api_errors_15m'] > 0) {
            raise_operational_status($snapshot, 'error');
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function fetch_external_integrations_operations_snapshot(PDO $pdo): array
{
    $snapshot = [
        'status' => 'ok',
        'thresholds' => [
            'api_error_error_min' => 1,
            'smaregi_token_expiring_warn_hours' => 24,
            'smaregi_token_expired_error_min' => 1,
            'smaregi_menu_sync_warn_hours' => 24,
            'stripe_missing_secret_error_min' => 1,
        ],
        'tenants_available' => false,
        'smaregi_store_mapping_available' => false,
        'stripe_gateway_tenants' => null,
        'stripe_missing_secret_tenants' => null,
        'smaregi_connected_tenants' => null,
        'smaregi_token_expired' => null,
        'smaregi_token_expiring_24h' => null,
        'smaregi_unsynced_stores_24h' => null,
        'api_errors_15m' => null,
        'api_error_rows' => [],
    ];

    try {
        if (table_exists($pdo, 'tenants')) {
            $snapshot['tenants_available'] = true;

            if (column_exists($pdo, 'tenants', 'payment_gateway') && column_exists($pdo, 'tenants', 'stripe_secret_key')) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM tenants
                     WHERE is_active = 1
                       AND payment_gateway = 'stripe'"
                );
                $snapshot['stripe_gateway_tenants'] = (int)$stmt->fetchColumn();

                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM tenants
                     WHERE is_active = 1
                       AND payment_gateway = 'stripe'
                       AND (stripe_secret_key IS NULL OR stripe_secret_key = '')"
                );
                $snapshot['stripe_missing_secret_tenants'] = (int)$stmt->fetchColumn();
                if ($snapshot['stripe_missing_secret_tenants'] > 0) {
                    raise_operational_status($snapshot, 'error');
                }
            }

            if (column_exists($pdo, 'tenants', 'smaregi_contract_id') && column_exists($pdo, 'tenants', 'smaregi_token_expires_at')) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM tenants
                     WHERE is_active = 1
                       AND smaregi_contract_id IS NOT NULL
                       AND smaregi_contract_id <> ''"
                );
                $snapshot['smaregi_connected_tenants'] = (int)$stmt->fetchColumn();

                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM tenants
                     WHERE is_active = 1
                       AND smaregi_contract_id IS NOT NULL
                       AND smaregi_contract_id <> ''
                       AND smaregi_token_expires_at IS NOT NULL
                       AND smaregi_token_expires_at <= NOW()"
                );
                $snapshot['smaregi_token_expired'] = (int)$stmt->fetchColumn();
                if ($snapshot['smaregi_token_expired'] > 0) {
                    raise_operational_status($snapshot, 'error');
                }

                $stmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM tenants
                     WHERE is_active = 1
                       AND smaregi_contract_id IS NOT NULL
                       AND smaregi_contract_id <> ''
                       AND smaregi_token_expires_at > NOW()
                       AND smaregi_token_expires_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)"
                );
                $snapshot['smaregi_token_expiring_24h'] = (int)$stmt->fetchColumn();
                if ($snapshot['smaregi_token_expiring_24h'] > 0) {
                    raise_operational_status($snapshot, 'warn');
                }
            }
        }

        if (table_exists($pdo, 'smaregi_store_mapping')) {
            $snapshot['smaregi_store_mapping_available'] = true;
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM smaregi_store_mapping
                 WHERE sync_enabled = 1
                   AND (last_menu_sync IS NULL OR last_menu_sync < DATE_SUB(NOW(), INTERVAL 24 HOUR))"
            );
            $snapshot['smaregi_unsynced_stores_24h'] = (int)$stmt->fetchColumn();
            if ($snapshot['smaregi_unsynced_stores_24h'] > 0) {
                raise_operational_status($snapshot, 'warn');
            }
        }

        $apiErrors = fetch_operation_error_log_snapshot(
            $pdo,
            "(request_path LIKE '%/smaregi/%'
              OR request_path LIKE '%stripe%'
              OR request_path LIKE '%checkout%'
              OR request_path LIKE '%payment%'
              OR request_path LIKE '%ai-%'
              OR code IN ('SMAREGI_API_ERROR','SMAREGI_NOT_CONFIGURED','GEMINI_ERROR','GEMINI_NETWORK','GEMINI_PARSE','AI_FAILED','AI_NOT_CONFIGURED','CHECKOUT_FAILED','GATEWAY_ERROR','PAYMENT_GATEWAY_ERROR'))"
        );
        $snapshot['api_errors_15m'] = $apiErrors['count'];
        $snapshot['api_error_rows'] = $apiErrors['rows'];
        if ((int)$snapshot['api_errors_15m'] > 0) {
            raise_operational_status($snapshot, 'error');
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function fetch_operation_error_log_snapshot(PDO $pdo, string $filterSql): array
{
    if (!table_exists($pdo, 'error_log')) {
        return ['available' => false, 'count' => null, 'rows' => []];
    }

    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM error_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND http_status >= 500
               AND " . $filterSql
        );
        $count = (int)$stmt->fetchColumn();

        $rows = $pdo->query(
            "SELECT e.error_no, e.code, e.http_status, e.request_path,
                    e.tenant_id, t.slug AS tenant_slug, t.name AS tenant_name,
                    e.store_id, s.slug AS store_slug, s.name AS store_name,
                    COUNT(*) AS count, MAX(e.created_at) AS latest_at
             FROM error_log e
             LEFT JOIN stores s ON s.id = e.store_id
             LEFT JOIN tenants t ON t.id = COALESCE(e.tenant_id, s.tenant_id)
             WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND e.http_status >= 500
               AND " . $filterSql . "
             GROUP BY e.error_no, e.code, e.http_status, e.request_path,
                      e.tenant_id, t.slug, t.name, e.store_id, s.slug, s.name
             ORDER BY count DESC, latest_at DESC
             LIMIT 10"
        );

        return [
            'available' => true,
            'count' => $count,
            'rows' => $rows ? normalize_count_rows($rows->fetchAll()) : [],
        ];
    } catch (PDOException $e) {
        return ['available' => true, 'count' => null, 'rows' => []];
    }
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
        'monitored_total' => null,
        'ignored_total' => null,
        'ignored_by_reason' => [],
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
        $hasMonitoringEnabled = column_exists($pdo, 'handy_terminals', 'monitoring_enabled');
        $hasOperationalStatus = column_exists($pdo, 'handy_terminals', 'operational_status');
        $hasBusinessHoursOnly = column_exists($pdo, 'handy_terminals', 'monitor_business_hours_only');
        $hasReservationSettings = table_exists($pdo, 'reservation_settings');
        $select = [
            'ht.id AS terminal_id',
            'ht.tenant_id',
            't.slug AS tenant_slug',
            't.name AS tenant_name',
            'ht.store_id',
            's.slug AS store_slug',
            's.name AS store_name',
            's.is_active AS store_is_active',
            's.timezone AS store_timezone',
            'ht.device_uid',
            'ht.device_label',
            'ht.terminal_mode',
            'ht.sound_enabled',
            'ht.realert_enabled',
            'ht.realert_interval_sec',
            'ht.last_seen_at',
            'ht.updated_at',
        ];
        $select[] = $hasReservationSettings ? 'rs.open_time' : 'NULL AS open_time';
        $select[] = $hasReservationSettings ? 'rs.close_time' : 'NULL AS close_time';
        $select[] = $hasReservationSettings ? 'rs.weekly_closed_days' : 'NULL AS weekly_closed_days';
        $select[] = $hasMonitoringEnabled ? 'ht.monitoring_enabled' : '1 AS monitoring_enabled';
        $select[] = $hasOperationalStatus ? 'ht.operational_status' : "'active' AS operational_status";
        $select[] = $hasBusinessHoursOnly ? 'ht.monitor_business_hours_only' : '1 AS monitor_business_hours_only';
        $reservationSettingsJoin = $hasReservationSettings ? 'LEFT JOIN reservation_settings rs ON rs.store_id = ht.store_id' : '';

        $rows = $pdo->query(
            "SELECT " . implode(', ', $select) . "
             FROM handy_terminals ht
             LEFT JOIN tenants t ON t.id = ht.tenant_id
             LEFT JOIN stores s ON s.id = ht.store_id
             " . $reservationSettingsJoin . "
             ORDER BY ht.updated_at DESC
             LIMIT 500"
        );
        $terminals = $rows ? $rows->fetchAll() : [];
        $snapshot['total'] = count($terminals);
        $snapshot['monitored_total'] = 0;
        $snapshot['ignored_total'] = 0;
        $snapshot['stale_over_10m'] = 0;
        $snapshot['stale_over_30m'] = 0;
        $modeCounts = [];
        $ignoredByReason = [];
        $now = time();

        foreach ($terminals as $terminal) {
            $mode = (string)($terminal['terminal_mode'] ?? 'unknown');
            $modeCounts[$mode] = ($modeCounts[$mode] ?? 0) + 1;

            $ignoreReason = terminal_heartbeat_ignore_reason($terminal);
            if ($ignoreReason !== '') {
                $snapshot['ignored_total']++;
                $ignoredByReason[$ignoreReason] = ($ignoredByReason[$ignoreReason] ?? 0) + 1;
                continue;
            }

            $snapshot['monitored_total']++;
            $lastSeenTs = !empty($terminal['last_seen_at']) ? strtotime((string)$terminal['last_seen_at']) : false;
            $staleSec = $lastSeenTs === false ? null : ($now - $lastSeenTs);
            if ($lastSeenTs === false || $staleSec >= 600) {
                $snapshot['stale_over_10m']++;
                if (count($snapshot['stale_terminals']) < 20) {
                    $terminal['stale_sec'] = $staleSec;
                    $snapshot['stale_terminals'][] = $terminal;
                }
            }
            if ($lastSeenTs === false || $staleSec >= 1800) {
                $snapshot['stale_over_30m']++;
            }
        }

        foreach ($modeCounts as $mode => $count) {
            $snapshot['mode_counts'][] = ['terminal_mode' => $mode, 'count' => (int)$count];
        }
        foreach ($ignoredByReason as $reason => $count) {
            $snapshot['ignored_by_reason'][] = ['reason' => $reason, 'count' => (int)$count];
        }

        if ($snapshot['stale_over_10m'] > 0) {
            raise_operational_status($snapshot, 'warn');
        }
        if ($snapshot['stale_over_30m'] > 0) {
            raise_operational_status($snapshot, 'error');
        }
    } catch (PDOException $e) {
        $snapshot['status'] = 'unknown';
    }

    return $snapshot;
}

function terminal_heartbeat_ignore_reason(array $terminal): string
{
    if ((int)($terminal['monitoring_enabled'] ?? 1) === 0) {
        return 'monitoring_disabled';
    }
    $operationalStatus = strtolower(trim((string)($terminal['operational_status'] ?? 'active')));
    if (in_array($operationalStatus, ['unused', 'retired', 'disabled', 'inactive'], true)) {
        return 'terminal_not_active';
    }
    if (array_key_exists('store_is_active', $terminal) && (int)$terminal['store_is_active'] === 0) {
        return 'store_inactive';
    }
    if ((int)($terminal['monitor_business_hours_only'] ?? 1) === 1 && !terminal_heartbeat_store_is_open_now($terminal)) {
        return 'outside_business_hours';
    }
    return '';
}

function terminal_heartbeat_store_is_open_now(array $terminal): bool
{
    $openTime = trim((string)($terminal['open_time'] ?? ''));
    $closeTime = trim((string)($terminal['close_time'] ?? ''));
    if ($openTime === '' || $closeTime === '') {
        return true;
    }

    $timezoneName = trim((string)($terminal['store_timezone'] ?? 'Asia/Tokyo')) ?: 'Asia/Tokyo';
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable $e) {
        $timezone = new DateTimeZone('Asia/Tokyo');
    }
    $now = new DateTimeImmutable('now', $timezone);
    $day = (int)$now->format('w');
    if (terminal_heartbeat_weekly_closed($terminal['weekly_closed_days'] ?? '', $day)) {
        return false;
    }

    $today = $now->format('Y-m-d');
    $open = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . normalize_time_for_datetime($openTime), $timezone);
    $close = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . normalize_time_for_datetime($closeTime), $timezone);
    if (!$open || !$close) {
        return true;
    }
    if ($close <= $open) {
        $close = $close->modify('+1 day');
    }
    return $now >= $open && $now <= $close;
}

function normalize_time_for_datetime(string $time): string
{
    $time = trim($time);
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time . ':00';
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return $time;
    }
    return '00:00:00';
}

function terminal_heartbeat_weekly_closed($closedDays, int $day): bool
{
    $value = trim((string)$closedDays);
    if ($value === '') {
        return false;
    }
    $parts = preg_split('/[,\s]+/', $value);
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (is_numeric($part) && (int)$part === $day) {
            return true;
        }
    }
    return false;
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
