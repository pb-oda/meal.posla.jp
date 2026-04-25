<?php
/**
 * I-1 外部 uptime サービス向け死活応答
 *
 * GET /api/monitor/ping.php
 *  → 200 OK + { ok: true, db: 'ok'|'ng', last_heartbeat: ..., cron_lag_sec: N }
 *  DB 接続失敗時も 200 を返し、レスポンス内容で判定してもらう (Sakura がダウンしたらそもそも応答しない)
 *
 * 外部サービス (UptimeRobot / cron-job.org など) から 5 分毎に叩いてもらい、
 * 応答なし / ok:false → Hiro メールへアラート
 *
 * 認証なし (公開ヘルスチェック)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/app.php';

$result = array_merge([
    'ok' => true,
    'time' => date('c'),
    'db' => 'unknown',
    'last_heartbeat' => null,
    'cron_lag_sec' => null,
], app_deployment_metadata());

try {
    require_once __DIR__ . '/../lib/db.php';
    $pdo = get_db();
    $pdo->query('SELECT 1');
    $result['db'] = 'ok';
    try {
        $stmt = $pdo->query("SELECT setting_value FROM posla_settings WHERE setting_key = 'monitor_last_heartbeat' LIMIT 1");
        $hb = $stmt->fetchColumn();
        if ($hb) {
            $result['last_heartbeat'] = $hb;
            $result['cron_lag_sec'] = time() - strtotime($hb);
            // cron が 15 分以上止まっていたら警告
            if ($result['cron_lag_sec'] > 900) $result['ok'] = false;
        }
    } catch (PDOException $e) { /* ignore */ }
} catch (Exception $e) {
    $result['ok'] = false;
    $result['db'] = 'ng';
    $result['error'] = substr($e->getMessage(), 0, 200);
}

http_response_code($result['ok'] ? 200 : 503);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
