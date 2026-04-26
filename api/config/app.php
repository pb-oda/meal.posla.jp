<?php
/**
 * アプリケーション全体の基底設定
 *
 * 環境ごとに APP_BASE_URL / メール送信元を切り替える。
 * 本番 / cell では compose env_file または process env から値を供給し、
 * 環境変数未設定時のみ既定値へフォールバックする。
 */

if (!function_exists('_app_env_or')) {
    function _app_env_or($key, $default) {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : $default;
    }
}

if (!function_exists('_app_env_is_set')) {
    function _app_env_is_set($key) {
        $value = getenv($key);
        return ($value !== false && trim((string)$value) !== '');
    }
}

if (!function_exists('_app_config_fail')) {
    function _app_config_fail($message) {
        $payload = json_encode([
            'ok' => false,
            'error' => [
                'code' => 'APP_CONFIG_INVALID',
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
            exit(2);
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo $payload;
        exit;
    }
}

if (!defined('APP_ENVIRONMENT')) {
    define('APP_ENVIRONMENT', _app_env_or('POSLA_ENVIRONMENT', _app_env_or('POSLA_ENV', 'local')));
}

if (!function_exists('_app_is_production_environment')) {
    function _app_is_production_environment() {
        $env = strtolower(trim((string)APP_ENVIRONMENT));
        return in_array($env, ['production', 'prod'], true);
    }
}

if (!function_exists('_app_url_host')) {
    function _app_url_host($url) {
        $host = parse_url((string)$url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }
        return strtolower(trim((string)$host, '[]'));
    }
}

if (!function_exists('_app_host_is_local')) {
    function _app_host_is_local($host) {
        $host = strtolower(trim((string)$host, '[]'));
        if ($host === '') {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', 'host.docker.internal'], true)) {
            return true;
        }
        return preg_match('/^127\./', $host) === 1;
    }
}

if (!function_exists('_app_validate_public_url_for_production')) {
    function _app_validate_public_url_for_production($key, $url) {
        if (!_app_is_production_environment()) {
            return;
        }

        $scheme = strtolower((string)parse_url((string)$url, PHP_URL_SCHEME));
        $host = _app_url_host($url);
        if ($url === '' || $scheme !== 'https' || $host === '' || _app_host_is_local($host)) {
            _app_config_fail($key . ' must be an explicit public https URL in production.');
        }
    }
}

if (!defined('APP_BASE_URL')) {
    if (_app_is_production_environment() && !_app_env_is_set('POSLA_APP_BASE_URL')) {
        _app_config_fail('POSLA_APP_BASE_URL is required in production.');
    }
    define('APP_BASE_URL', rtrim(_app_env_or('POSLA_APP_BASE_URL', 'http://127.0.0.1:8081'), '/'));
    _app_validate_public_url_for_production('POSLA_APP_BASE_URL', APP_BASE_URL);
}

if (!defined('APP_FROM_EMAIL')) {
    define('APP_FROM_EMAIL', _app_env_or('POSLA_FROM_EMAIL', 'noreply@meal.posla.jp'));
}

if (!defined('APP_SUPPORT_EMAIL')) {
    define('APP_SUPPORT_EMAIL', _app_env_or('POSLA_SUPPORT_EMAIL', 'info@meal.posla.jp'));
}

if (!defined('APP_CELL_ID')) {
    define('APP_CELL_ID', _app_env_or('POSLA_CELL_ID', 'posla-control-local'));
}

if (!defined('APP_OPS_SOURCE_ID')) {
    define('APP_OPS_SOURCE_ID', _app_env_or('POSLA_OPS_SOURCE_ID', APP_CELL_ID));
}

if (!defined('APP_DEPLOY_VERSION')) {
    define('APP_DEPLOY_VERSION', _app_env_or('POSLA_DEPLOY_VERSION', 'dev'));
}

if (!function_exists('_app_csv_env')) {
    function _app_csv_env($key, array $default) {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            return $default;
        }

        $items = array_map('trim', explode(',', $value));
        $items = array_values(array_filter($items, function ($item) {
            return $item !== '';
        }));

        return $items ?: $default;
    }
}

if (!defined('APP_ALLOWED_ORIGINS')) {
    if (_app_is_production_environment() && !_app_env_is_set('POSLA_ALLOWED_ORIGINS')) {
        _app_config_fail('POSLA_ALLOWED_ORIGINS is required in production.');
    }
    $allowedOrigins = _app_csv_env('POSLA_ALLOWED_ORIGINS', [APP_BASE_URL]);
    if (_app_is_production_environment()) {
        foreach ($allowedOrigins as $origin) {
            _app_validate_public_url_for_production('POSLA_ALLOWED_ORIGINS', $origin);
        }
    }
    define('APP_ALLOWED_ORIGINS', implode(',', $allowedOrigins));
}

if (!defined('APP_ALLOWED_HOSTS')) {
    if (_app_is_production_environment() && !_app_env_is_set('POSLA_ALLOWED_HOSTS')) {
        _app_config_fail('POSLA_ALLOWED_HOSTS is required in production.');
    }
    $defaultHost = _app_url_host(APP_BASE_URL) ?: '127.0.0.1';
    $allowedHosts = _app_csv_env('POSLA_ALLOWED_HOSTS', [$defaultHost]);
    if (_app_is_production_environment()) {
        foreach ($allowedHosts as $host) {
            if (_app_host_is_local($host)) {
                _app_config_fail('POSLA_ALLOWED_HOSTS must not include localhost in production.');
            }
        }
    }
    define('APP_ALLOWED_HOSTS', implode(',', $allowedHosts));
}

// REL-HOTFIX-20260423-SERVER-READY: PHP error_log 出力先を環境変数で切り替え可能に
// 新サーバ移行時は POSLA_PHP_ERROR_LOG 環境変数で上書き可能。未設定時は現行本番のパスを維持。
if (!defined('POSLA_PHP_ERROR_LOG')) {
    define('POSLA_PHP_ERROR_LOG', _app_env_or('POSLA_PHP_ERROR_LOG', '/home/odah/log/php_errors.log'));
}

if (!function_exists('app_url')) {
    function app_url($path) {
        $path = (string)$path;
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return APP_BASE_URL . $path;
    }
}

if (!function_exists('app_webcal_url')) {
    function app_webcal_url($path) {
        $path = (string)$path;
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $host = parse_url(APP_BASE_URL, PHP_URL_HOST);
        if (!$host) {
            $host = 'localhost';
        }
        return 'webcal://' . $host . $path;
    }
}

if (!function_exists('app_allowed_origins')) {
    function app_allowed_origins() {
        return _app_csv_env('POSLA_ALLOWED_ORIGINS', explode(',', APP_ALLOWED_ORIGINS));
    }
}

if (!function_exists('app_allowed_hosts')) {
    function app_allowed_hosts() {
        return _app_csv_env('POSLA_ALLOWED_HOSTS', explode(',', APP_ALLOWED_HOSTS));
    }
}

if (!function_exists('app_deployment_metadata')) {
    function app_deployment_metadata() {
        return [
            'cell_id' => APP_CELL_ID,
            'source_id' => APP_OPS_SOURCE_ID,
            'deploy_version' => APP_DEPLOY_VERSION,
            'environment' => APP_ENVIRONMENT,
        ];
    }
}
