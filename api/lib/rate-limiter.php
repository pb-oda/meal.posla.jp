<?php
/**
 * ファイルベース IPレートリミッター（S-1）
 *
 * /tmp/posla_rate_limit/ にIPごとのファイルを作成し、
 * ウィンドウ内のリクエスト数を制限する。
 * DBテーブル追加不要。サーバー再起動でリセットされるが問題なし。
 */

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
    $dir = sys_get_temp_dir() . '/posla_rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // IP取得（X-Forwarded-For 優先、最初のIPのみ）
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($forwarded[0]);
    }

    $file = $dir . '/' . md5($ip . ':' . $endpoint) . '.json';
    $now = time();
    $windowStart = $now - $windowSeconds;

    // 既存記録を読み込み
    $timestamps = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $timestamps = $decoded;
            }
        }
    }

    // ウィンドウ外の古いエントリを除去
    $timestamps = array_values(array_filter($timestamps, function ($ts) use ($windowStart) {
        return $ts > $windowStart;
    }));

    // 制限チェック
    if (count($timestamps) >= $maxRequests) {
        json_error('RATE_LIMITED', 'リクエスト回数の上限に達しました。しばらくしてからお試しください。', 429);
    }

    // 現在時刻を記録
    $timestamps[] = $now;
    @file_put_contents($file, json_encode($timestamps), LOCK_EX);

    return true;
}
