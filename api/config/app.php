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

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', rtrim(_app_env_or('POSLA_APP_BASE_URL', 'https://meal.posla.jp'), '/'));
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

if (!defined('APP_ENVIRONMENT')) {
    define('APP_ENVIRONMENT', _app_env_or('POSLA_ENVIRONMENT', _app_env_or('POSLA_ENV', 'local')));
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
    define('APP_ALLOWED_ORIGINS', implode(',', _app_csv_env('POSLA_ALLOWED_ORIGINS', [
        'https://meal.posla.jp',
    ])));
}

if (!defined('APP_ALLOWED_HOSTS')) {
    define('APP_ALLOWED_HOSTS', implode(',', _app_csv_env('POSLA_ALLOWED_HOSTS', [
        'meal.posla.jp',
    ])));
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
            $host = 'meal.posla.jp';
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
