<?php
/**
 * PHP session storage configuration.
 *
 * POSLA keeps session validity metadata in MySQL (`user_sessions`,
 * `posla_admin_sessions`), but the actual PHP `$_SESSION` payload must survive
 * app container rebuilds. In production-like Docker, set POSLA_SESSION_STORE=redis.
 */

function posla_session_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function posla_session_int_env(string $key, int $default): int
{
    $value = (int)posla_session_env($key, (string)$default);
    return $value > 0 ? $value : $default;
}

function posla_session_bool_env(string $key, bool $default): bool
{
    $value = strtolower(trim(posla_session_env($key, $default ? '1' : '0')));
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
}

function posla_session_fail(string $message): void
{
    error_log('[POSLA_SESSION] ' . $message);
    if (!posla_session_bool_env('POSLA_SESSION_REDIS_REQUIRED', true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => 'SESSION_STORE_UNAVAILABLE',
            'message' => 'セッション保存先を利用できません',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function posla_session_unavailable(string $message): void
{
    error_log('[POSLA_SESSION] ' . $message);
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => 'SESSION_STORE_UNAVAILABLE',
            'message' => 'セッション保存先を利用できません',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function posla_start_session_or_fail(): void
{
    if (@session_start() !== true) {
        posla_session_unavailable('session_start failed.');
    }
}

function posla_redis_session_prefix(): string
{
    $explicit = posla_session_env('POSLA_REDIS_SESSION_PREFIX');
    if ($explicit !== '') {
        return $explicit;
    }

    $cellId = posla_session_env('POSLA_CELL_ID', 'default');
    $cellId = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $cellId);
    return 'posla:' . $cellId . ':sess:';
}

function posla_redis_session_save_path(): string
{
    $host = posla_session_env('POSLA_REDIS_HOST', 'redis');
    $port = posla_session_int_env('POSLA_REDIS_PORT', 6379);

    $params = [
        'database' => posla_session_int_env('POSLA_REDIS_DATABASE', 0),
        'prefix' => posla_redis_session_prefix(),
        'timeout' => posla_session_env('POSLA_REDIS_TIMEOUT_SEC', '1.5'),
        'read_timeout' => posla_session_env('POSLA_REDIS_READ_TIMEOUT_SEC', '1.5'),
    ];

    $auth = posla_session_env('POSLA_REDIS_AUTH');
    if ($auth !== '') {
        $params['auth'] = $auth;
    }

    return 'tcp://' . $host . ':' . $port . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function posla_configure_session_store(): void
{
    static $configured = false;
    if ($configured || session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $configured = true;

    ini_set('session.gc_maxlifetime', (string)posla_session_int_env('POSLA_SESSION_GC_MAXLIFETIME_SEC', 28800));

    $store = strtolower(posla_session_env('POSLA_SESSION_STORE', 'files'));
    if ($store === '' || $store === 'file') {
        $store = 'files';
    }
    if ($store !== 'redis') {
        return;
    }

    if (!extension_loaded('redis')) {
        posla_session_fail('POSLA_SESSION_STORE=redis but phpredis extension is not loaded.');
        return;
    }

    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', posla_redis_session_save_path());
}
