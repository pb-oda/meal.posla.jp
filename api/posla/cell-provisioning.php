<?php
/**
 * POSLA管理者 Cell Provisioning API
 *
 * GET   /api/posla/cell-provisioning.php
 * PATCH /api/posla/cell-provisioning.php
 *
 * Web UI never runs deploy commands directly. This endpoint exposes the
 * provisioning queue, command plan, and control DB status update hooks.
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/../lib/tenant-onboarding.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH']);
$pdo = get_db();

function cell_provisioning_table_exists(PDO $pdo, string $tableName): bool
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

function cell_provisioning_string(array $input, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $input) || $input[$key] === null) {
        return $default;
    }
    return trim((string)$input[$key]);
}

function cell_provisioning_nullable(array $input, string $key): ?string
{
    $value = cell_provisioning_string($input, $key);
    return $value === '' ? null : $value;
}

function cell_provisioning_valid_cell_id(string $cellId): bool
{
    return preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $cellId) === 1;
}

function cell_provisioning_fetch_request(PDO $pdo, string $requestId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT request_id, request_source, status,
                tenant_id, tenant_slug, tenant_name,
                store_id, store_slug, store_name,
                owner_user_id, owner_username, owner_email, owner_display_name,
                requested_store_count, hq_menu_broadcast, cell_id,
                notes, last_error, requested_at, payment_confirmed_at,
                provisioned_at, activated_at, updated_at
         FROM posla_tenant_onboarding_requests
         WHERE request_id = ?
         LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function cell_provisioning_update_request_status(PDO $pdo, string $requestId, string $status, array $data = []): void
{
    $sets = ['status = ?', 'updated_at = CURRENT_TIMESTAMP'];
    $params = [posla_onboarding_status($status)];
    $fields = [
        'cell_id',
        'notes',
        'last_error',
        'payment_confirmed_at',
        'provisioned_at',
        'activated_at',
    ];
    $i = 0;

    for ($i = 0; $i < count($fields); $i++) {
        $field = $fields[$i];
        if (array_key_exists($field, $data)) {
            $sets[] = $field . ' = ?';
            $params[] = $data[$field] === null ? null : (string)$data[$field];
        }
    }

    $params[] = $requestId;
    $stmt = $pdo->prepare(
        'UPDATE posla_tenant_onboarding_requests
         SET ' . implode(', ', $sets) . '
         WHERE request_id = ?'
    );
    $stmt->execute($params);
}

function cell_provisioning_fetch_items(PDO $pdo): array
{
    $usedPorts = cell_provisioning_collect_used_ports($pdo);
    $stmt = $pdo->query(
        'SELECT r.request_id, r.request_source, r.status,
                r.tenant_id, r.tenant_slug, r.tenant_name,
                r.store_id, r.store_slug, r.store_name,
                r.owner_user_id, r.owner_username, r.owner_email, r.owner_display_name,
                r.requested_store_count, r.hq_menu_broadcast, r.cell_id,
                r.notes, r.last_error, r.requested_at, r.payment_confirmed_at,
                r.provisioned_at, r.activated_at, r.updated_at,
                c.status AS registry_status,
                c.environment AS registry_environment,
                c.app_base_url, c.health_url, c.db_host, c.db_name, c.db_user,
                c.uploads_path, c.php_image, c.deploy_version, c.cron_enabled,
                c.last_ping_at, c.updated_at AS registry_updated_at
         FROM posla_tenant_onboarding_requests r
         LEFT JOIN posla_cell_registry c ON c.cell_id = r.cell_id
         WHERE r.status IN (\'received\',\'payment_pending\',\'ready_for_cell\',\'cell_provisioning\',\'failed\',\'active\')
         ORDER BY
           CASE r.status
             WHEN \'failed\' THEN 1
             WHEN \'ready_for_cell\' THEN 2
             WHEN \'cell_provisioning\' THEN 3
             WHEN \'payment_pending\' THEN 4
             WHEN \'received\' THEN 5
             WHEN \'active\' THEN 6
             ELSE 9
           END,
           r.updated_at DESC
         LIMIT 100'
    );

    $rows = $stmt ? $stmt->fetchAll() : [];
    $items = [];
    $i = 0;
    for ($i = 0; $i < count($rows); $i++) {
        $items[] = cell_provisioning_enrich_item($rows[$i], $i, $usedPorts);
    }

    return $items;
}

function cell_provisioning_extract_port(?string $value): int
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    if (preg_match('/:(\d+)(?:\/|$)/', $value, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function cell_provisioning_collect_used_ports(PDO $pdo): array
{
    $ports = ['http' => [], 'db' => []];
    if (!cell_provisioning_table_exists($pdo, 'posla_cell_registry')) {
        return $ports;
    }

    try {
        $stmt = $pdo->query(
            'SELECT app_base_url, health_url, db_host
             FROM posla_cell_registry'
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            $httpPort = cell_provisioning_extract_port($row['app_base_url'] ?? '');
            if (!$httpPort) {
                $httpPort = cell_provisioning_extract_port($row['health_url'] ?? '');
            }
            if ($httpPort) {
                $ports['http'][$httpPort] = true;
            }

            $dbPort = cell_provisioning_extract_port($row['db_host'] ?? '');
            if ($dbPort) {
                $ports['db'][$dbPort] = true;
            }
        }
    } catch (PDOException $e) {
        return $ports;
    }

    return $ports;
}

function cell_provisioning_next_available_port(array &$used, int $base): int
{
    $port = $base;
    while (!empty($used[$port])) {
        $port++;
    }
    $used[$port] = true;
    return $port;
}

function cell_provisioning_shell_quote(string $value): string
{
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function cell_provisioning_command_plan(array $row, int $index): array
{
    $status = (string)($row['status'] ?? '');
    $cellId = (string)($row['cell_id'] ?? '');
    $tenantSlug = (string)($row['tenant_slug'] ?? '');
    $tenantName = (string)($row['tenant_name'] ?? '');
    $storeSlug = (string)($row['store_slug'] ?? 'main');
    $storeName = (string)($row['store_name'] ?? ($tenantName . ' 本店'));
    $ownerUsername = (string)($row['owner_username'] ?? ($tenantSlug . '-owner'));
    $ownerDisplayName = (string)($row['owner_display_name'] ?? $ownerUsername);
    $ownerEmail = (string)($row['owner_email'] ?? '');
    $suggested = $row['suggested_target'] ?? cell_provisioning_suggested_target($row, $index);
    $httpPort = (int)$suggested['http_port'];
    $dbPort = (int)$suggested['db_port'];
    $baseUrl = (string)$suggested['app_base_url'];

    if ($cellId === '' || !in_array($status, ['ready_for_cell', 'cell_provisioning', 'failed'], true)) {
        return [];
    }

    $initEnvParts = [
        'POSLA_CELL_DB_PASSWORD=' . cell_provisioning_shell_quote('<secure-db-password>'),
        'POSLA_CELL_DB_ROOT_PASSWORD=' . cell_provisioning_shell_quote('<secure-root-password>'),
    ];

    $envParts = [];
    if (!empty($row['tenant_id'])) {
        $envParts[] = 'POSLA_TENANT_ID=' . cell_provisioning_shell_quote((string)$row['tenant_id']);
    }
    if (!empty($row['store_id'])) {
        $envParts[] = 'POSLA_STORE_ID=' . cell_provisioning_shell_quote((string)$row['store_id']);
    }
    if (!empty($row['owner_user_id'])) {
        $envParts[] = 'POSLA_OWNER_USER_ID=' . cell_provisioning_shell_quote((string)$row['owner_user_id']);
    }
    $envParts[] = 'POSLA_STORE_SLUG=' . cell_provisioning_shell_quote($storeSlug !== '' ? $storeSlug : 'main');
    $envParts[] = 'POSLA_OWNER_PASSWORD=' . cell_provisioning_shell_quote('<secure-initial-password>');

    return [
        [
            'label' => 'cell env 作成',
            'command' => implode(' ', $initEnvParts) . ' scripts/cell/cell.sh init '
                . cell_provisioning_shell_quote($cellId) . ' '
                . cell_provisioning_shell_quote($tenantSlug) . ' '
                . cell_provisioning_shell_quote($baseUrl) . ' '
                . $httpPort . ' ' . $dbPort,
        ],
        [
            'label' => 'cell app image build',
            'command' => 'scripts/cell/cell.sh ' . cell_provisioning_shell_quote($cellId) . ' build',
        ],
        [
            'label' => 'cell deploy',
            'command' => 'scripts/cell/cell.sh ' . cell_provisioning_shell_quote($cellId) . ' deploy',
        ],
        [
            'label' => 'tenant 初期作成',
            'command' => implode(' ', $envParts) . ' scripts/cell/cell.sh '
                . cell_provisioning_shell_quote($cellId) . ' onboard-tenant '
                . cell_provisioning_shell_quote($tenantSlug) . ' '
                . cell_provisioning_shell_quote($tenantName) . ' '
                . cell_provisioning_shell_quote($storeName) . ' '
                . cell_provisioning_shell_quote($ownerUsername) . ' '
                . cell_provisioning_shell_quote($ownerDisplayName) . ' '
                . cell_provisioning_shell_quote($ownerEmail),
        ],
        [
            'label' => 'strict smoke',
            'command' => 'POSLA_CELL_SMOKE_STRICT=1 scripts/cell/cell.sh '
                . cell_provisioning_shell_quote($cellId) . ' smoke',
        ],
    ];
}

function cell_provisioning_db_key_from_slug(string $tenantSlug): string
{
    $key = preg_replace('/[^A-Za-z0-9_]/', '_', $tenantSlug);
    $key = trim((string)$key, '_');
    return $key !== '' ? $key : 'cell';
}

function cell_provisioning_suggested_target(array $row, int $index): array
{
    $usedPorts = ['http' => [], 'db' => []];
    return cell_provisioning_assign_suggested_target($row, $index, $usedPorts);
}

function cell_provisioning_assign_suggested_target(array $row, int $index, array &$usedPorts): array
{
    $cellId = (string)($row['cell_id'] ?? '');
    $tenantSlug = (string)($row['tenant_slug'] ?? '');
    $existingHttpPort = cell_provisioning_extract_port((string)($row['app_base_url'] ?? ''));
    if (!$existingHttpPort) {
        $existingHttpPort = cell_provisioning_extract_port((string)($row['health_url'] ?? ''));
    }
    $existingDbPort = cell_provisioning_extract_port((string)($row['db_host'] ?? ''));
    $httpPort = $existingHttpPort ?: cell_provisioning_next_available_port($usedPorts['http'], 18081);
    $dbPort = $existingDbPort ?: cell_provisioning_next_available_port($usedPorts['db'], 13306);
    $baseUrl = !empty($row['app_base_url'])
        ? (string)$row['app_base_url']
        : ('http://127.0.0.1:' . $httpPort);

    return [
        'cell_id' => $cellId,
        'http_port' => $httpPort,
        'db_port' => $dbPort,
        'app_base_url' => $baseUrl,
        'health_url' => rtrim($baseUrl, '/') . '/api/monitor/ping.php',
        'db_host' => '127.0.0.1:' . $dbPort,
        'db_name' => 'posla_' . cell_provisioning_db_key_from_slug($tenantSlug),
        'db_user' => 'posla_app',
        'uploads_path' => 'cells/' . $cellId . '/uploads',
        'php_image' => 'posla_php_cell:dev',
        'deploy_version' => defined('APP_DEPLOY_VERSION') ? APP_DEPLOY_VERSION : 'dev',
        'environment' => defined('APP_ENVIRONMENT') ? APP_ENVIRONMENT : 'production',
    ];
}

function cell_provisioning_next_action(array $row): string
{
    $status = (string)($row['status'] ?? '');
    $registryStatus = (string)($row['registry_status'] ?? '');
    $healthUrl = (string)($row['health_url'] ?? '');

    if ($status === 'failed') {
        return '失敗理由を確認し、修正後に cell_provisioning へ戻す';
    }
    if ($status === 'ready_for_cell') {
        return '専用cellを作成し、deploy / onboard / smoke を実行する';
    }
    if ($status === 'cell_provisioning') {
        return 'smoke結果を確認し、control registry を active に更新する';
    }
    if ($status === 'active' && ($registryStatus !== 'active' || $healthUrl === '')) {
        return 'control registry の URL / health_url を補完する';
    }
    if ($status === 'active') {
        return '監視対象として維持';
    }
    return '支払い確認または申込内容の確認';
}

function cell_provisioning_enrich_item(array $row, int $index, array &$usedPorts): array
{
    $row['hq_menu_broadcast'] = !empty($row['hq_menu_broadcast']) ? 1 : 0;
    $row['cron_enabled'] = !empty($row['cron_enabled']) ? 1 : 0;
    $row['next_action'] = cell_provisioning_next_action($row);
    $row['suggested_target'] = cell_provisioning_assign_suggested_target($row, $index, $usedPorts);
    $row['commands'] = cell_provisioning_command_plan($row, $index);
    return $row;
}

function cell_provisioning_require_tables(PDO $pdo): void
{
    if (!cell_provisioning_table_exists($pdo, 'posla_tenant_onboarding_requests')) {
        json_error('ONBOARDING_TABLE_UNAVAILABLE', 'onboarding ledger が未作成です', 503);
    }
    if (!cell_provisioning_table_exists($pdo, 'posla_cell_registry')) {
        json_error('CELL_REGISTRY_UNAVAILABLE', 'cell registry が未作成です', 503);
    }
}

cell_provisioning_require_tables($pdo);

if ($method === 'GET') {
    json_response([
        'available' => true,
        'items' => cell_provisioning_fetch_items($pdo),
    ]);
}

if ($method === 'PATCH') {
    $input = get_json_body();
    $action = cell_provisioning_string($input, 'action');
    $requestId = cell_provisioning_string($input, 'request_id');
    if ($requestId === '') {
        json_error('MISSING_REQUEST_ID', 'request_id は必須です', 400);
    }

    $oldValue = cell_provisioning_fetch_request($pdo, $requestId);
    if (!$oldValue) {
        json_error('REQUEST_NOT_FOUND', '対象の onboarding request が見つかりません', 404);
    }

    if ($action === 'update_status') {
        $rawStatus = cell_provisioning_string($input, 'status');
        $status = posla_onboarding_status($rawStatus);
        if ($rawStatus === '' || $status !== $rawStatus) {
            json_error('INVALID_STATUS', 'status が不正です', 400);
        }
        $data = [
            'notes' => cell_provisioning_nullable($input, 'notes'),
            'last_error' => cell_provisioning_nullable($input, 'last_error'),
        ];
        if ($status === 'cell_provisioning') {
            $data['provisioned_at'] = date('Y-m-d H:i:s');
        }
        if ($status === 'active') {
            $data['provisioned_at'] = $oldValue['provisioned_at'] ?: date('Y-m-d H:i:s');
            $data['activated_at'] = date('Y-m-d H:i:s');
        }

        cell_provisioning_update_request_status($pdo, $requestId, $status, $data);
        if (!empty($oldValue['tenant_id'])) {
            posla_sync_onboarding_cell_registry_for_tenant($pdo, (string)$oldValue['tenant_id']);
        }
        $newValue = cell_provisioning_fetch_request($pdo, $requestId);
        posla_admin_write_audit_log(
            $pdo,
            $admin,
            'cell_provisioning_status_update',
            'posla_tenant_onboarding_request',
            $requestId,
            $oldValue,
            $newValue,
            cell_provisioning_nullable($input, 'notes'),
            generate_uuid()
        );

        json_response([
            'message' => 'Cell provisioning status を更新しました',
            'request' => $newValue,
        ]);
    }

    if ($action === 'sync_registry') {
        $cellId = cell_provisioning_string($input, 'cell_id', (string)$oldValue['cell_id']);
        if (!cell_provisioning_valid_cell_id($cellId)) {
            json_error('INVALID_CELL_ID', 'cell_id が不正です', 400);
        }

        $status = cell_provisioning_string($input, 'registry_status', 'active');
        if (!in_array($status, ['planned', 'provisioning', 'active', 'maintenance', 'retired', 'failed'], true)) {
            json_error('INVALID_REGISTRY_STATUS', 'registry_status が不正です', 400);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO posla_cell_registry
                (cell_id, tenant_id, tenant_slug, tenant_name, environment, status,
                 app_base_url, health_url, db_host, db_name, db_user, uploads_path,
                 php_image, deploy_version, cron_enabled, notes)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                tenant_id = VALUES(tenant_id),
                tenant_slug = VALUES(tenant_slug),
                tenant_name = VALUES(tenant_name),
                environment = VALUES(environment),
                status = VALUES(status),
                app_base_url = VALUES(app_base_url),
                health_url = VALUES(health_url),
                db_host = VALUES(db_host),
                db_name = VALUES(db_name),
                db_user = VALUES(db_user),
                uploads_path = VALUES(uploads_path),
                php_image = VALUES(php_image),
                deploy_version = VALUES(deploy_version),
                cron_enabled = VALUES(cron_enabled),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $cellId,
            $oldValue['tenant_id'],
            $oldValue['tenant_slug'],
            $oldValue['tenant_name'],
            cell_provisioning_string($input, 'environment', defined('APP_ENVIRONMENT') ? APP_ENVIRONMENT : 'production'),
            $status,
            cell_provisioning_nullable($input, 'app_base_url'),
            cell_provisioning_nullable($input, 'health_url'),
            cell_provisioning_nullable($input, 'db_host'),
            cell_provisioning_nullable($input, 'db_name'),
            cell_provisioning_nullable($input, 'db_user'),
            cell_provisioning_nullable($input, 'uploads_path'),
            cell_provisioning_nullable($input, 'php_image'),
            cell_provisioning_nullable($input, 'deploy_version'),
            !empty($input['cron_enabled']) ? 1 : 0,
            cell_provisioning_nullable($input, 'notes'),
        ]);

        if ($status === 'active') {
            cell_provisioning_update_request_status($pdo, $requestId, 'active', [
                'cell_id' => $cellId,
                'provisioned_at' => $oldValue['provisioned_at'] ?: date('Y-m-d H:i:s'),
                'activated_at' => date('Y-m-d H:i:s'),
                'notes' => cell_provisioning_nullable($input, 'notes'),
            ]);
            if (!empty($oldValue['tenant_id'])) {
                posla_sync_onboarding_cell_registry_for_tenant($pdo, (string)$oldValue['tenant_id']);
            }
        }

        $newValue = cell_provisioning_fetch_request($pdo, $requestId);
        posla_admin_write_audit_log(
            $pdo,
            $admin,
            'cell_registry_sync',
            'posla_cell_registry',
            $cellId,
            $oldValue,
            $newValue,
            cell_provisioning_nullable($input, 'notes'),
            generate_uuid()
        );

        json_response([
            'message' => 'control cell registry を更新しました',
            'request' => $newValue,
        ]);
    }

    json_error('INVALID_ACTION', '未対応の action です', 400);
}
