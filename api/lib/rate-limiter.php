<?php
/**
 * IPレートリミッター（S-1）
 *
 * デフォルトは従来通り /tmp/posla_rate_limit/ のファイル保存。
 * Cloud Run のように複数 app instance が並ぶ環境では
 * POSLA_RATE_LIMIT_STORE=redis を指定し、Memorystore / Redis に保存する。
 */

function _posla_rate_limit_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function _posla_rate_limit_bool_env(string $key, bool $default): bool
{
    $value = strtolower(trim(_posla_rate_limit_env($key, $default ? '1' : '0')));
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
}

function _posla_rate_limit_int_env(string $key, int $default): int
{
    $value = (int)_posla_rate_limit_env($key, (string)$default);
    return $value >= 0 ? $value : $default;
}

function _posla_rate_limit_float_env(string $key, float $default): float
{
    $value = (float)_posla_rate_limit_env($key, (string)$default);
    return $value > 0 ? $value : $default;
}

function _posla_rate_limit_client_ip(): string
{
    $source = strtolower(trim(_posla_rate_limit_env('POSLA_RATE_LIMIT_CLIENT_IP_SOURCE', 'remote_addr')));
    if ($source === 'x_forwarded_for') {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $parts = explode(',', $xff);
            foreach ($parts as $part) {
                $candidate = trim($part);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
    }

    // M-4: デフォルトは REMOTE_ADDR のみ使用（X-Forwarded-For は明示 opt-in）。
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function _posla_rate_limit_redis_prefix(): string
{
    $explicit = _posla_rate_limit_env('POSLA_REDIS_RATE_LIMIT_PREFIX');
    if ($explicit !== '') {
        return $explicit;
    }

    $cellId = _posla_rate_limit_env('POSLA_CELL_ID', 'default');
    $cellId = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $cellId);
    return 'posla:' . $cellId . ':rate:';
}

function _posla_rate_limit_store(): string
{
    $store = strtolower(trim(_posla_rate_limit_env('POSLA_RATE_LIMIT_STORE', 'files')));
    return $store === 'file' ? 'files' : $store;
}

function _posla_rate_limit_redis_client()
{
    if (!extension_loaded('redis') || !class_exists('Redis')) {
        error_log('[POSLA_RATE_LIMIT] POSLA_RATE_LIMIT_STORE=redis but phpredis extension is not loaded.');
        return null;
    }

    $host = _posla_rate_limit_env('POSLA_REDIS_HOST');
    if ($host === '') {
        error_log('[POSLA_RATE_LIMIT] POSLA_RATE_LIMIT_STORE=redis but POSLA_REDIS_HOST is empty.');
        return null;
    }

    try {
        $redis = new Redis();
        $redis->connect(
            $host,
            _posla_rate_limit_int_env('POSLA_REDIS_PORT', 6379),
            _posla_rate_limit_float_env('POSLA_REDIS_TIMEOUT_SEC', 1.5)
        );

        if (defined('Redis::OPT_READ_TIMEOUT')) {
            $redis->setOption(Redis::OPT_READ_TIMEOUT, _posla_rate_limit_float_env('POSLA_REDIS_READ_TIMEOUT_SEC', 1.5));
        }

        $auth = _posla_rate_limit_env('POSLA_REDIS_AUTH');
        if ($auth !== '') {
            $redis->auth($auth);
        }

        $database = _posla_rate_limit_int_env('POSLA_REDIS_DATABASE', 0);
        if ($database > 0) {
            $redis->select($database);
        }

        return $redis;
    } catch (Throwable $e) {
        error_log('[POSLA_RATE_LIMIT] Redis rate limiter unavailable: ' . $e->getMessage());
        return null;
    }
}

function _posla_rate_limit_redis_check(string $endpoint, int $maxRequests, int $windowSeconds, string $ip): ?bool
{
    $redis = _posla_rate_limit_redis_client();
    if (!$redis) {
        return null;
    }

    try {
        $now = time();
        $windowStart = $now - $windowSeconds;
        $key = _posla_rate_limit_redis_prefix() . md5($ip . ':' . $endpoint);
        $member = $now . ':' . bin2hex(random_bytes(8));
        $ttl = max($windowSeconds + 60, 60);

        $script = "redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[2])\n"
            . "local current = redis.call('ZCARD', KEYS[1])\n"
            . "if current >= tonumber(ARGV[3]) then\n"
            . "  redis.call('EXPIRE', KEYS[1], tonumber(ARGV[4]))\n"
            . "  return 0\n"
            . "end\n"
            . "redis.call('ZADD', KEYS[1], tonumber(ARGV[1]), ARGV[5])\n"
            . "redis.call('EXPIRE', KEYS[1], tonumber(ARGV[4]))\n"
            . "return 1\n";

        $result = $redis->eval($script, [
            $key,
            (string)$now,
            (string)$windowStart,
            (string)$maxRequests,
            (string)$ttl,
            $member,
        ], 1);

        return (int)$result === 1;
    } catch (Throwable $e) {
        error_log('[POSLA_RATE_LIMIT] Redis rate limiter unavailable: ' . $e->getMessage());
        return null;
    }
}

function _posla_rate_limit_redis_exceeded(string $endpoint, int $maxRequests, int $windowSeconds, string $ip): ?bool
{
    $redis = _posla_rate_limit_redis_client();
    if (!$redis) {
        return null;
    }

    try {
        $now = time();
        $windowStart = $now - $windowSeconds;
        $key = _posla_rate_limit_redis_prefix() . md5($ip . ':' . $endpoint);
        $ttl = max($windowSeconds + 60, 60);

        $redis->zRemRangeByScore($key, '-inf', (string)$windowStart);
        $current = $redis->zCard($key);
        $redis->expire($key, $ttl);

        return (int)$current >= $maxRequests;
    } catch (Throwable $e) {
        error_log('[POSLA_RATE_LIMIT] Redis rate limiter unavailable: ' . $e->getMessage());
        return null;
    }
}

function _posla_rate_limit_file_check(string $endpoint, int $maxRequests, int $windowSeconds, string $ip): bool
{
    $dir = sys_get_temp_dir() . '/posla_rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/' . md5($ip . ':' . $endpoint) . '.json';
    $now = time();
    $windowStart = $now - $windowSeconds;

    // M-5: flock で読み書きをアトミック化（TOCTOU 競合防止）
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        // ファイルオープン失敗時はリミットなしで通過（サービス継続優先）
        return true;
    }
    flock($fp, LOCK_EX);

    $raw = '';
    $size = filesize($file);
    if ($size > 0) {
        $raw = fread($fp, $size);
    }
    $timestamps = [];
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $timestamps = $decoded;
        }
    }

    // ウィンドウ外の古いエントリを除去
    $timestamps = array_values(array_filter($timestamps, function ($ts) use ($windowStart) {
        return $ts > $windowStart;
    }));

    if (count($timestamps) >= $maxRequests) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $timestamps[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($timestamps));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

function _posla_rate_limit_file_exceeded(string $endpoint, int $maxRequests, int $windowSeconds, string $ip): bool
{
    $dir = sys_get_temp_dir() . '/posla_rate_limit';
    if (!is_dir($dir)) {
        return false;
    }

    $file = $dir . '/' . md5($ip . ':' . $endpoint) . '.json';
    if (!is_file($file)) {
        return false;
    }

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return false;
    }

    flock($fp, LOCK_EX);

    $raw = '';
    $size = filesize($file);
    if ($size > 0) {
        $raw = fread($fp, $size);
    }

    $timestamps = [];
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $timestamps = $decoded;
        }
    }

    $windowStart = time() - $windowSeconds;
    $timestamps = array_values(array_filter($timestamps, function ($ts) use ($windowStart) {
        return (int)$ts > $windowStart;
    }));

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($timestamps));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return count($timestamps) >= $maxRequests;
}

/**
 * 既にレートリミット超過状態かを確認する（記録は追加しない）。
 */
function rate_limit_exceeded(string $endpoint, int $maxRequests, int $windowSeconds): bool
{
    $ip = _posla_rate_limit_client_ip();
    $store = _posla_rate_limit_store();

    if ($store === 'redis') {
        $exceeded = _posla_rate_limit_redis_exceeded($endpoint, $maxRequests, $windowSeconds, $ip);
        if ($exceeded === null) {
            return _posla_rate_limit_bool_env('POSLA_RATE_LIMIT_REDIS_REQUIRED', false);
        }
        return $exceeded;
    }

    return _posla_rate_limit_file_exceeded($endpoint, $maxRequests, $windowSeconds, $ip);
}

/**
 * レートリミットをチェック・記録する
 *
 * @param string $endpoint     エンドポイント識別子（例: 'ai-waiter'）
 * @param int    $maxRequests  ウィンドウ内の最大リクエスト数
 * @param int    $windowSeconds ウィンドウ秒数
 * @return true 制限内の場合
 */
function check_rate_limit(string $endpoint, int $maxRequests, int $windowSeconds): bool
{
    $ip = _posla_rate_limit_client_ip();
    $store = _posla_rate_limit_store();

    if ($store === 'redis') {
        $allowed = _posla_rate_limit_redis_check($endpoint, $maxRequests, $windowSeconds, $ip);
        if ($allowed === null && !_posla_rate_limit_bool_env('POSLA_RATE_LIMIT_REDIS_REQUIRED', false)) {
            $allowed = true;
        }
        if ($allowed === null) {
            json_error('RATE_LIMITED', 'リクエスト回数の確認に失敗しました。しばらくしてからお試しください。', 429);
        }
        if ($allowed === false) {
            json_error('RATE_LIMITED', 'リクエスト回数の上限に達しました。しばらくしてからお試しください。', 429);
        }
        return true;
    }

    if (!_posla_rate_limit_file_check($endpoint, $maxRequests, $windowSeconds, $ip)) {
        json_error('RATE_LIMITED', 'リクエスト回数の上限に達しました。しばらくしてからお試しください。', 429);
    }

    return true;
}
