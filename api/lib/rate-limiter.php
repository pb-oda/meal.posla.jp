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

    // M-4: REMOTE_ADDR のみ使用（X-Forwarded-For はスプーフ可能なため信頼しない）
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

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

    // 制限チェック
    if (count($timestamps) >= $maxRequests) {
        flock($fp, LOCK_UN);
        fclose($fp);
        json_error('RATE_LIMITED', 'リクエスト回数の上限に達しました。しばらくしてからお試しください。', 429);
    }

    // 現在時刻を記録してファイルに書き戻し
    $timestamps[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($timestamps));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}
