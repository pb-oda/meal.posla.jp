<?php
/**
 * データベース接続 & UUID生成
 */

/**
 * PDO シングルトンを返す
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $config_path = __DIR__ . '/../config/database.php';

    if (file_exists($config_path)) {
        $config = require $config_path;
    } else {
        $envOr = function ($key, $default) {
            $value = getenv($key);
            return ($value !== false && $value !== '') ? $value : $default;
        };
        $localOr = function ($localKey, $key, $default) use ($envOr) {
            $value = getenv($localKey);
            if ($value !== false && $value !== '') {
                return $value;
            }
            return $envOr($key, $default);
        };

        if ($envOr('POSLA_ENV', '') === 'local') {
            $config = [
                'host'     => $localOr('POSLA_LOCAL_DB_HOST', 'POSLA_DB_HOST', ''),
                'socket'   => '',
                'dbname'   => $localOr('POSLA_LOCAL_DB_NAME', 'POSLA_DB_NAME', ''),
                'username' => $localOr('POSLA_LOCAL_DB_USER', 'POSLA_DB_USER', ''),
                'password' => $localOr('POSLA_LOCAL_DB_PASS', 'POSLA_DB_PASS', ''),
                'charset'  => 'utf8mb4',
            ];
        } else {
            $config = [
                'host'     => $envOr('POSLA_DB_HOST', ''),
                'socket'   => $envOr('POSLA_DB_SOCKET', ''),
                'dbname'   => $envOr('POSLA_DB_NAME', ''),
                'username' => $envOr('POSLA_DB_USER', ''),
                'password' => $envOr('POSLA_DB_PASS', ''),
                'charset'  => 'utf8mb4',
            ];
        }

        if ($config['dbname'] === '' || $config['username'] === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => ['code' => 'DB_CONFIG_MISSING', 'message' => 'Database configuration not found']]);
            exit;
        }
    }

    if (!empty($config['socket'])) {
        $dsn = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            $config['socket'],
            $config['dbname'],
            $config['charset'] ?? 'utf8mb4'
        );
    } else {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['dbname'],
            $config['charset'] ?? 'utf8mb4'
        );
    }

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * UUID v4 を生成
 */
function generate_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
