#!/usr/bin/env php
<?php
/**
 * Host-side on-demand cell provisioner.
 *
 * This script is intentionally CLI-only. Stripe webhooks only move signup
 * requests to ready_for_cell; a localhost-only trigger service or operator
 * runs this script and creates the dedicated cell outside the web request
 * lifecycle.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(2);
}

$rootDir = dirname(__DIR__, 2);

function usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/cell/provision-ready-cells.php [--limit=1] [--request-id=<id>] [--dry-run]\n\n";
    echo "Environment:\n";
    echo "  POSLA_CELL_PROVISIONER_DB_HOST      Control DB host for this provisioner script (default: 127.0.0.1 when app env host is db)\n";
    echo "  POSLA_CELL_PROVISIONER_DB_PORT      Control DB port (default: 3306)\n";
    echo "  POSLA_CELL_HTTP_PORT_BASE           First auto-assigned cell HTTP port (default: 18081)\n";
    echo "  POSLA_CELL_DB_PORT_BASE             First auto-assigned cell DB port (default: 13306)\n";
    echo "  POSLA_CELL_APP_URL_PATTERN          Public URL pattern, e.g. https://{tenant_slug}.<production-domain>\n";
    echo "  POSLA_CELL_PROVISIONER_ALLOW_LOCAL_URL=1 allows localhost app URLs outside local/pseudo-prod environments\n";
    echo "  POSLA_CELL_PROVISIONER_ENV_FILE     Env file to load first (default: docker/env/app.env)\n";
}

function parse_args(array $argv): array
{
    $opts = [
        'limit' => 1,
        'request_id' => '',
        'dry_run' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            usage();
            exit(0);
        }
        if ($arg === '--dry-run') {
            $opts['dry_run'] = true;
            continue;
        }
        if (strpos($arg, '--limit=') === 0) {
            $opts['limit'] = max(1, min(10, (int)substr($arg, 8)));
            continue;
        }
        if (strpos($arg, '--request-id=') === 0) {
            $opts['request_id'] = trim(substr($arg, 13));
            continue;
        }
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        usage();
        exit(2);
    }

    return $opts;
}

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '' || getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function env_or(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

function running_inside_container(): bool
{
    return is_file('/.dockerenv');
}

function control_pdo(): PDO
{
    $host = env_or('POSLA_CELL_PROVISIONER_DB_HOST');
    if ($host === '') {
        $host = env_or('POSLA_LOCAL_DB_HOST', env_or('POSLA_DB_HOST', '127.0.0.1'));
    }
    if ($host === 'db' && !running_inside_container()) {
        $host = '127.0.0.1';
    }

    $port = env_or('POSLA_CELL_PROVISIONER_DB_PORT', '3306');
    $name = env_or('POSLA_CELL_PROVISIONER_DB_NAME', env_or('POSLA_LOCAL_DB_NAME', env_or('POSLA_DB_NAME')));
    $user = env_or('POSLA_CELL_PROVISIONER_DB_USER', env_or('POSLA_LOCAL_DB_USER', env_or('POSLA_DB_USER')));
    $pass = env_or('POSLA_CELL_PROVISIONER_DB_PASS', env_or('POSLA_LOCAL_DB_PASS', env_or('POSLA_DB_PASS')));

    if ($name === '' || $user === '') {
        throw new RuntimeException('Control DB environment is incomplete.');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function sql_string(?string $value): string
{
    return trim((string)$value);
}

function sanitize_cell_id(string $value): string
{
    $cellId = strtolower(trim($value));
    $cellId = preg_replace('/[^a-z0-9_-]+/', '-', $cellId);
    $cellId = trim((string)$cellId, '-_');
    return $cellId !== '' ? substr($cellId, 0, 80) : ('cell-' . bin2hex(random_bytes(4)));
}

function parse_port(?string $value): int
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $parts = parse_url($value);
    if (is_array($parts) && isset($parts['port'])) {
        return (int)$parts['port'];
    }
    if (preg_match('/:(\d+)(?:\/|$)/', $value, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function collect_used_ports(PDO $pdo, string $rootDir): array
{
    $used = ['http' => [], 'db' => []];
    $rows = $pdo->query('SELECT app_base_url, health_url, db_host FROM posla_cell_registry')->fetchAll();
    foreach ($rows as $row) {
        $httpPort = parse_port($row['app_base_url'] ?? '') ?: parse_port($row['health_url'] ?? '');
        $dbPort = parse_port($row['db_host'] ?? '');
        if ($httpPort > 0) {
            $used['http'][$httpPort] = true;
        }
        if ($dbPort > 0) {
            $used['db'][$dbPort] = true;
        }
    }

    $registry = $rootDir . '/cells/registry.tsv';
    if (is_file($registry)) {
        $lines = file($registry, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $cols = explode("\t", $line);
            if (isset($cols[8]) && ctype_digit($cols[8])) {
                $used['http'][(int)$cols[8]] = true;
            }
            if (isset($cols[9]) && ctype_digit($cols[9])) {
                $used['db'][(int)$cols[9]] = true;
            }
        }
    }

    return $used;
}

function next_port(array &$used, int $base): int
{
    $port = $base;
    while (isset($used[$port])) {
        $port++;
    }
    $used[$port] = true;
    return $port;
}

function localish_environment(): bool
{
    $env = strtolower(env_or('POSLA_CELL_PROVISIONER_ENVIRONMENT', env_or('POSLA_ENVIRONMENT', env_or('POSLA_ENV'))));
    return in_array($env, ['local', 'development', 'dev', 'pseudo-prod', 'staging', 'test'], true);
}

function app_base_url(string $cellId, string $tenantSlug, int $httpPort): string
{
    $pattern = env_or('POSLA_CELL_APP_URL_PATTERN');
    if ($pattern !== '') {
        return str_replace(
            ['{cell_id}', '{tenant_slug}', '{http_port}'],
            [$cellId, $tenantSlug, (string)$httpPort],
            $pattern
        );
    }
    if (!localish_environment() && env_or('POSLA_CELL_PROVISIONER_ALLOW_LOCAL_URL') !== '1') {
        throw new RuntimeException(
            'POSLA_CELL_APP_URL_PATTERN is required outside local/pseudo-prod environments. '
            . 'Refusing to create a production customer cell with a localhost login URL.'
        );
    }
    return 'http://127.0.0.1:' . $httpPort;
}

function random_secret(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function mask_command(array $parts): string
{
    return implode(' ', array_map(function ($part) {
        $part = (string)$part;
        return preg_match('/^[A-Za-z0-9_\/.:\-]+$/', $part) ? $part : escapeshellarg($part);
    }, $parts));
}

function run_command(array $parts, array $env, bool $dryRun, string $rootDir): void
{
    echo ($dryRun ? '[dry-run] ' : '') . mask_command($parts) . "\n";
    if ($dryRun) {
        return;
    }

    $cmd = implode(' ', array_map('escapeshellarg', $parts));
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnv = array_merge($_ENV, $env);
    foreach (['PATH', 'HOME', 'USER', 'SHELL', 'TMPDIR'] as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '' && !isset($processEnv[$key])) {
            $processEnv[$key] = $value;
        }
    }
    foreach ($env as $key => $value) {
        $processEnv[$key] = $value;
    }
    $process = proc_open($cmd, $descriptor, $pipes, $rootDir, $processEnv);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start command: ' . mask_command($parts));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($stdout !== '') {
        echo $stdout;
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    if ($code !== 0) {
        throw new RuntimeException('Command failed with exit ' . $code . ': ' . mask_command($parts));
    }
}

function fetch_ready_requests(PDO $pdo, array $opts): array
{
    if ($opts['request_id'] !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM posla_tenant_onboarding_requests
              WHERE request_id = ? AND status = 'ready_for_cell'
              LIMIT 1"
        );
        $stmt->execute([$opts['request_id']]);
        $row = $stmt->fetch();
        return $row ? [$row] : [];
    }

    $limit = (int)$opts['limit'];
    $stmt = $pdo->query(
        "SELECT * FROM posla_tenant_onboarding_requests
          WHERE status = 'ready_for_cell'
          ORDER BY COALESCE(payment_confirmed_at, updated_at), id
          LIMIT " . $limit
    );
    return $stmt->fetchAll();
}

function reserve_request(PDO $pdo, string $requestId, bool $dryRun): ?array
{
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM posla_tenant_onboarding_requests WHERE request_id = ? FOR UPDATE');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'ready_for_cell') {
        $pdo->commit();
        return null;
    }
    if (!$dryRun) {
        $upd = $pdo->prepare(
            "UPDATE posla_tenant_onboarding_requests
                SET status = 'cell_provisioning',
                    provisioned_at = COALESCE(provisioned_at, NOW()),
                    notes = 'On-demand provisioner started.',
                    updated_at = CURRENT_TIMESTAMP
              WHERE request_id = ?"
        );
        $upd->execute([$requestId]);
    }
    $pdo->commit();
    return $row;
}

function owner_password_hash(PDO $pdo, array $row): string
{
    if (!empty($row['owner_user_id'])) {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$row['owner_user_id'], $row['tenant_id']]);
        $hash = $stmt->fetchColumn();
        if ($hash) {
            return (string)$hash;
        }
    }
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE tenant_id = ? AND role = 'owner' LIMIT 1");
    $stmt->execute([$row['tenant_id']]);
    $hash = $stmt->fetchColumn();
    if (!$hash) {
        throw new RuntimeException('Owner password hash not found for tenant_id=' . $row['tenant_id']);
    }
    return (string)$hash;
}

function update_registry_success(PDO $pdo, array $row, array $target): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO posla_cell_registry
            (cell_id, tenant_id, tenant_slug, tenant_name, environment, status,
             app_base_url, health_url, db_host, db_name, db_user, uploads_path,
             php_image, deploy_version, cron_enabled, notes)
         VALUES
            (?, ?, ?, ?, ?, 'active',
             ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE
             tenant_id = VALUES(tenant_id),
             tenant_slug = VALUES(tenant_slug),
             tenant_name = VALUES(tenant_name),
             environment = VALUES(environment),
             status = 'active',
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
             updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        $target['cell_id'],
        $row['tenant_id'],
        $row['tenant_slug'],
        $row['tenant_name'],
        $target['environment'],
        $target['app_base_url'],
        $target['health_url'],
        $target['db_host'],
        $target['db_name'],
        $target['db_user'],
        $target['uploads_path'],
        $target['php_image'],
        $target['deploy_version'],
        'On-demand provisioner completed.',
    ]);

    $upd = $pdo->prepare(
        "UPDATE posla_tenant_onboarding_requests
            SET status = 'active',
                cell_id = ?,
                provisioned_at = COALESCE(provisioned_at, NOW()),
                activated_at = NOW(),
                notes = 'On-demand provisioner completed.',
                last_error = NULL,
                updated_at = CURRENT_TIMESTAMP
          WHERE request_id = ?"
    );
    $upd->execute([$target['cell_id'], $row['request_id']]);
}

function update_registry_failed(PDO $pdo, array $row, string $cellId, string $error): void
{
    $error = mb_substr($error, 0, 1000);
    $upd = $pdo->prepare(
        "UPDATE posla_tenant_onboarding_requests
            SET status = 'failed',
                cell_id = ?,
                last_error = ?,
                notes = 'On-demand provisioner failed.',
                updated_at = CURRENT_TIMESTAMP
          WHERE request_id = ?"
    );
    $upd->execute([$cellId, $error, $row['request_id']]);

    $reg = $pdo->prepare(
        "INSERT INTO posla_cell_registry
            (cell_id, tenant_id, tenant_slug, tenant_name, environment, status, notes)
         VALUES (?, ?, ?, ?, ?, 'failed', ?)
         ON DUPLICATE KEY UPDATE
            status = 'failed',
            notes = VALUES(notes),
            updated_at = CURRENT_TIMESTAMP"
    );
    $reg->execute([
        $cellId,
        $row['tenant_id'],
        $row['tenant_slug'],
        $row['tenant_name'],
        env_or('POSLA_CELL_PROVISIONER_ENVIRONMENT', env_or('POSLA_ENVIRONMENT', 'production')),
        $error,
    ]);
}

function send_ready_mail(array $row, array $target): void
{
    $to = sql_string($row['owner_email'] ?? '');
    if ($to === '') {
        return;
    }

    if (function_exists('mb_language')) {
        mb_language('Japanese');
        mb_internal_encoding('UTF-8');
    }

    $displayName = sql_string($row['owner_display_name'] ?? '') ?: sql_string($row['owner_username'] ?? '');
    $tenantName = sql_string($row['tenant_name'] ?? '');
    $username = sql_string($row['owner_username'] ?? '');
    $loginUrl = rtrim((string)$target['app_base_url'], '/') . '/admin/index.html';
    $support = env_or('POSLA_SUPPORT_EMAIL', 'info@posla.jp');
    $fromEmail = env_or('POSLA_FROM_EMAIL', 'noreply@posla.jp');
    $subject = '【POSLA】専用環境の準備が完了しました';
    $body = "{$displayName} 様\n\n"
          . "POSLA の専用環境の準備が完了しました。\n\n"
          . "■ ご契約名: {$tenantName}\n"
          . "■ ログインURL: {$loginUrl}\n"
          . "■ ユーザー名: {$username}\n"
          . "■ パスワード: お申込み時に設定されたもの\n\n"
          . "30日間無料トライアル中です。期間内のキャンセルで請求は発生しません。\n\n"
          . "ログインできない場合は {$support} までご連絡ください。\n\n"
          . "--\nPOSLA 運営チーム";
    $fromName = 'POSLA';
    $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
    $headers = "From: " . $fromHeader . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";

    if (function_exists('mb_send_mail')) {
        @mb_send_mail($to, $subject, $body, $headers);
    } else {
        @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }
    echo "Ready mail queued for {$to}\n";
}

function provision_request(PDO $pdo, array $row, array &$usedPorts, bool $dryRun, string $rootDir): void
{
    $tenantSlug = sql_string($row['tenant_slug']);
    $cellId = sanitize_cell_id(sql_string($row['cell_id']) ?: $tenantSlug);
    $httpBase = (int)env_or('POSLA_CELL_HTTP_PORT_BASE', '18081');
    $dbBase = (int)env_or('POSLA_CELL_DB_PORT_BASE', '13306');
    $httpPort = next_port($usedPorts['http'], $httpBase);
    $dbPort = next_port($usedPorts['db'], $dbBase);
    $baseUrl = app_base_url($cellId, $tenantSlug, $httpPort);
    $dbName = 'posla_' . preg_replace('/[^A-Za-z0-9_]/', '_', $tenantSlug);
    $dbUser = env_or('POSLA_CELL_DB_USER', 'posla_app');
    $environment = env_or('POSLA_CELL_PROVISIONER_ENVIRONMENT', env_or('POSLA_ENVIRONMENT', 'production'));
    $deployVersion = env_or('POSLA_DEPLOY_VERSION', 'dev');
    $phpImage = env_or('POSLA_PHP_IMAGE', 'posla_php_cell:dev');
    $ownerHash = owner_password_hash($pdo, $row);
    $storeSlug = sql_string($row['store_slug']) ?: 'default';
    $storeName = sql_string($row['store_name']) ?: (sql_string($row['tenant_name']) . ' Main Store');
    $ownerUsername = sql_string($row['owner_username']) ?: ($tenantSlug . '-owner');
    $ownerDisplayName = sql_string($row['owner_display_name']) ?: $ownerUsername;
    $ownerEmail = sql_string($row['owner_email']);
    $subscriptionStatus = 'trialing';
    $target = [
        'cell_id' => $cellId,
        'app_base_url' => $baseUrl,
        'health_url' => rtrim($baseUrl, '/') . '/api/monitor/ping.php',
        'db_host' => '127.0.0.1:' . $dbPort,
        'db_name' => $dbName,
        'db_user' => $dbUser,
        'uploads_path' => 'cells/' . $cellId . '/uploads',
        'php_image' => $phpImage,
        'deploy_version' => $deployVersion,
        'environment' => $environment,
    ];

    echo "Provisioning request {$row['request_id']} -> {$cellId}\n";

    $initEnv = [
        'POSLA_CELL_DB_PASSWORD' => random_secret(),
        'POSLA_CELL_DB_ROOT_PASSWORD' => random_secret(),
        'POSLA_CELL_DB_NAME' => $dbName,
        'POSLA_CELL_DB_USER' => $dbUser,
        'POSLA_ENVIRONMENT' => $environment,
        'POSLA_DEPLOY_VERSION' => $deployVersion,
        'POSLA_PHP_IMAGE' => $phpImage,
    ];
    run_command(['scripts/cell/cell.sh', 'init', $cellId, $tenantSlug, $baseUrl, (string)$httpPort, (string)$dbPort], $initEnv, $dryRun, $rootDir);
    run_command(['scripts/cell/cell.sh', $cellId, 'build'], [], $dryRun, $rootDir);
    run_command(['scripts/cell/cell.sh', $cellId, 'deploy'], [], $dryRun, $rootDir);

    $onboardEnv = [
        'POSLA_OWNER_PASSWORD_HASH' => $ownerHash,
        'POSLA_TENANT_ID' => (string)$row['tenant_id'],
        'POSLA_STORE_ID' => (string)$row['store_id'],
        'POSLA_OWNER_USER_ID' => (string)$row['owner_user_id'],
        'POSLA_STORE_SLUG' => $storeSlug,
        'POSLA_SUBSCRIPTION_STATUS' => $subscriptionStatus,
        'POSLA_HQ_MENU_BROADCAST' => !empty($row['hq_menu_broadcast']) ? '1' : '0',
    ];
    run_command([
        'scripts/cell/cell.sh',
        $cellId,
        'onboard-tenant',
        $tenantSlug,
        sql_string($row['tenant_name']),
        $storeName,
        $ownerUsername,
        $ownerDisplayName,
        $ownerEmail,
    ], $onboardEnv, $dryRun, $rootDir);

    run_command(['scripts/cell/cell.sh', $cellId, 'smoke'], ['POSLA_CELL_SMOKE_STRICT' => '1'], $dryRun, $rootDir);

    if (!$dryRun) {
        update_registry_success($pdo, $row, $target);
        send_ready_mail($row, $target);
    }
}

$opts = parse_args($argv);
$envFile = env_or('POSLA_CELL_PROVISIONER_ENV_FILE', $rootDir . '/docker/env/app.env');
load_env_file($envFile);

try {
    $pdo = control_pdo();
    $rows = fetch_ready_requests($pdo, $opts);
    if (!$rows) {
        echo "No ready_for_cell requests.\n";
        exit(0);
    }

    $usedPorts = collect_used_ports($pdo, $rootDir);
    foreach ($rows as $candidate) {
        $row = reserve_request($pdo, $candidate['request_id'], $opts['dry_run']);
        if (!$row) {
            continue;
        }
        $cellId = sanitize_cell_id(sql_string($row['cell_id']) ?: sql_string($row['tenant_slug']));
        try {
            provision_request($pdo, $row, $usedPorts, $opts['dry_run'], $rootDir);
        } catch (Throwable $e) {
            if (!$opts['dry_run']) {
                update_registry_failed($pdo, $row, $cellId, $e->getMessage());
            }
            throw $e;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Provisioner failed: " . $e->getMessage() . "\n");
    exit(1);
}
