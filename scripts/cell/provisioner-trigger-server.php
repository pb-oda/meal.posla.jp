#!/usr/bin/env php
<?php
/**
 * Localhost-only event trigger for on-demand cell provisioning.
 *
 * Run this as a long-lived service on the provisioner host. It accepts a small
 * internal HTTP request from POSLA signup code and starts provision-ready-cells
 * in the background. No cron/timer is required for the normal signup path.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}

$rootDir = dirname(__DIR__, 2);

function trigger_env($key, $default = '') {
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

function load_env_file_for_trigger($path) {
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

function write_response($client, $status, array $payload) {
    $reasons = [
        200 => 'OK',
        202 => 'Accepted',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        413 => 'Payload Too Large',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $reason = isset($reasons[$status]) ? $reasons[$status] : 'OK';
    fwrite($client, "HTTP/1.1 {$status} {$reason}\r\n");
    fwrite($client, "Content-Type: application/json; charset=utf-8\r\n");
    fwrite($client, "Content-Length: " . strlen($body) . "\r\n");
    fwrite($client, "Connection: close\r\n\r\n");
    fwrite($client, $body);
}

function parse_http_request($client) {
    $buffer = '';
    while (strpos($buffer, "\r\n\r\n") === false && strlen($buffer) < 32768) {
        $chunk = fread($client, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }
    $headerEnd = strpos($buffer, "\r\n\r\n");
    if ($headerEnd === false) {
        return null;
    }

    $headerText = substr($buffer, 0, $headerEnd);
    $body = substr($buffer, $headerEnd + 4);
    $lines = explode("\r\n", $headerText);
    $requestLine = array_shift($lines);
    if (!preg_match('#^([A-Z]+)\s+(\S+)\s+HTTP/1\.[01]$#', (string)$requestLine, $m)) {
        return null;
    }

    $headers = [];
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $headers[$name] = trim(substr($line, $pos + 1));
    }

    $length = isset($headers['content-length']) ? (int)$headers['content-length'] : 0;
    if ($length > 65536) {
        return ['too_large' => true];
    }
    while (strlen($body) < $length) {
        $chunk = fread($client, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    return [
        'method' => $m[1],
        'path' => parse_url($m[2], PHP_URL_PATH) ?: '/',
        'headers' => $headers,
        'body' => substr($body, 0, $length),
    ];
}

function spawn_provisioner($rootDir, $requestId, $source) {
    if ($requestId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $requestId)) {
        throw new RuntimeException('Invalid request_id.');
    }

    $parts = [
        PHP_BINARY,
        $rootDir . '/scripts/cell/provision-ready-cells.php',
    ];
    if ($requestId !== '') {
        $parts[] = '--request-id=' . $requestId;
    } else {
        $parts[] = '--limit=1';
    }
    if (trigger_env('POSLA_PROVISIONER_TRIGGER_DRY_RUN') === '1') {
        $parts[] = '--dry-run';
    }

    $logPath = trigger_env('POSLA_PROVISIONER_TRIGGER_LOG', sys_get_temp_dir() . '/posla-provisioner-trigger.log');
    $cmd = 'cd ' . escapeshellarg($rootDir)
         . ' && printf ' . escapeshellarg("\n[" . date('c') . "] source={$source} request_id={$requestId}\n")
         . ' >> ' . escapeshellarg($logPath)
         . ' && ' . implode(' ', array_map('escapeshellarg', $parts))
         . ' >> ' . escapeshellarg($logPath)
         . ' 2>&1 &';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0) {
        throw new RuntimeException('Failed to spawn provisioner.');
    }

    return [
        'request_id' => $requestId,
        'dry_run' => trigger_env('POSLA_PROVISIONER_TRIGGER_DRY_RUN') === '1',
        'log' => $logPath,
    ];
}

function handle_trigger_request(array $request, $rootDir) {
    if (isset($request['too_large']) && $request['too_large']) {
        return [413, ['ok' => false, 'error' => 'payload_too_large']];
    }
    if (!$request) {
        return [400, ['ok' => false, 'error' => 'bad_request']];
    }

    if ($request['method'] === 'GET' && $request['path'] === '/health') {
        return [200, ['ok' => true, 'service' => 'posla-provisioner-trigger']];
    }

    if ($request['path'] !== '/run') {
        return [404, ['ok' => false, 'error' => 'not_found']];
    }
    if ($request['method'] !== 'POST') {
        return [405, ['ok' => false, 'error' => 'method_not_allowed']];
    }

    $secret = (string)trigger_env('POSLA_PROVISIONER_TRIGGER_SECRET');
    if ($secret === '') {
        return [503, ['ok' => false, 'error' => 'trigger_secret_not_configured']];
    }
    $given = isset($request['headers']['x-posla-provisioner-secret'])
        ? (string)$request['headers']['x-posla-provisioner-secret']
        : '';
    if ($given === '' || !hash_equals($secret, $given)) {
        return [401, ['ok' => false, 'error' => 'unauthorized']];
    }

    $body = json_decode((string)$request['body'], true);
    if (!is_array($body)) {
        return [400, ['ok' => false, 'error' => 'invalid_json']];
    }
    $requestId = trim((string)($body['request_id'] ?? ''));
    $source = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', (string)($body['source'] ?? 'unknown'));
    if ($source === '') {
        $source = 'unknown';
    }

    try {
        $result = spawn_provisioner($rootDir, $requestId, $source);
        return [202, ['ok' => true, 'accepted' => true, 'provisioner' => $result]];
    } catch (Throwable $e) {
        return [500, ['ok' => false, 'error' => 'spawn_failed', 'message' => $e->getMessage()]];
    }
}

load_env_file_for_trigger(trigger_env('POSLA_PROVISIONER_TRIGGER_ENV_FILE', $rootDir . '/docker/env/app.env'));

$host = trigger_env('POSLA_PROVISIONER_TRIGGER_HOST', '127.0.0.1');
$port = (int)trigger_env('POSLA_PROVISIONER_TRIGGER_PORT', '19091');
if ($port < 1024 || $port > 65535) {
    fwrite(STDERR, "Invalid POSLA_PROVISIONER_TRIGGER_PORT\n");
    exit(2);
}
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
    && trigger_env('POSLA_PROVISIONER_TRIGGER_ALLOW_NONLOCAL_BIND') !== '1') {
    fwrite(STDERR, "Refusing to bind non-local host. Set POSLA_PROVISIONER_TRIGGER_ALLOW_NONLOCAL_BIND=1 only when protected by network ACLs.\n");
    exit(2);
}
if (trigger_env('POSLA_PROVISIONER_TRIGGER_SECRET') === '') {
    fwrite(STDERR, "POSLA_PROVISIONER_TRIGGER_SECRET is required.\n");
    exit(2);
}

$address = ($host === '::1' ? 'tcp://[::1]:' : 'tcp://' . $host . ':') . $port;
$server = stream_socket_server($address, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed to listen on {$address}: {$errstr} ({$errno})\n");
    exit(2);
}

fwrite(STDOUT, "POSLA provisioner trigger listening on {$address}\n");
while (true) {
    $client = @stream_socket_accept($server, -1);
    if (!$client) {
        continue;
    }
    stream_set_timeout($client, 5);
    $request = parse_http_request($client);
    list($status, $payload) = handle_trigger_request($request ?: [], $rootDir);
    write_response($client, $status, $payload);
    fclose($client);
}
