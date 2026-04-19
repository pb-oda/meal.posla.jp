<?php
/**
 * I-1 運用支援エージェント Phase 1: ヘルスチェック + 異常検知
 *
 * 推奨実行: 5分毎 (Sakura cron)
 *
 * やること:
 *   1. /home/odah/log/php_errors.log の末尾を走査 → 直近 5 分の ERROR/FATAL を検出
 *   2. subscription_events / reservation_notifications_log の 'failed' を検知
 *   3. posla_settings.monitor_last_heartbeat を更新 (外部から監視される用)
 *
 * 異常検知時:
 *   - monitor_events に記録
 *   - Slack webhook 通知 (posla_settings.slack_webhook_url)
 *   - 同一イベント連続3件以上 → Hiro個人メール (ops_notify_email) へ
 *
 * 認証: CLI または X-POSLA-CRON-SECRET ヘッダ
 */

if (php_sapi_name() !== 'cli') {
    $expected = getenv('POSLA_CRON_SECRET') ?: '';
    if (!$expected || ($_SERVER['HTTP_X_POSLA_CRON_SECRET'] ?? '') !== $expected) {
        http_response_code(403); echo 'forbidden'; exit;
    }
}

require_once __DIR__ . '/../lib/db.php';
$pdo = get_db();

$now = date('Y-m-d H:i:s');
$result = ['php_errors' => 0, 'stripe_failed' => 0, 'reserve_mail_failed' => 0, 'api_errors' => 0, 'slack_sent' => 0];

// posla_settings から通知先を取得
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM posla_settings WHERE setting_key IN ('slack_webhook_url','ops_notify_email')");
    foreach ($stmt->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (PDOException $e) { /* テーブル未存在は無視 */ }

// heartbeat 更新
try {
    $pdo->prepare("UPDATE posla_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'monitor_last_heartbeat'")
        ->execute([$now]);
} catch (PDOException $e) { /* ignore */ }

// -------- 1. PHP エラーログ走査 (直近 5 分) --------
$logFile = '/home/odah/log/php_errors.log';
if (is_readable($logFile)) {
    $cutoff = time() - 5 * 60;
    $lines = _tail($logFile, 200);
    $recentErrors = [];
    foreach ($lines as $line) {
        if (preg_match('/\[(\d{2}-\w+-\d{4} \d{2}:\d{2}:\d{2})[^\]]*\]/', $line, $m)) {
            $ts = strtotime($m[1]);
            if ($ts >= $cutoff && preg_match('/PHP (Fatal|Parse|Warning) error/i', $line)) {
                $recentErrors[] = substr($line, 0, 400);
            }
        }
    }
    if (!empty($recentErrors)) {
        $detail = implode("\n", array_slice($recentErrors, 0, 5));
        _logEvent($pdo, 'php_error', 'error', 'php_errors.log', 'PHP エラーを検知 (' . count($recentErrors) . ' 件)', $detail);
        _notifySlack($settings, '⚠️ POSLA: PHP エラー ' . count($recentErrors) . ' 件', $detail);
        $result['php_errors'] = count($recentErrors);
        $result['slack_sent']++;
    }
}

// -------- 2. Stripe Webhook 失敗検知 (subscription_events の最近の error_message) --------
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM subscription_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND (error_message IS NOT NULL AND error_message <> '')"
    );
    $stmt->execute();
    $n = (int)$stmt->fetchColumn();
    if ($n > 0) {
        _logEvent($pdo, 'stripe_webhook_fail', 'warn', 'subscription_events', 'Stripe Webhook 失敗 (' . $n . ' 件) 直近5分', null);
        _notifySlack($settings, '⚠️ POSLA: Stripe Webhook 失敗 ' . $n . ' 件 (直近5分)', '詳細は subscription_events テーブル参照');
        $result['stripe_failed'] = $n;
        $result['slack_sent']++;
    }
} catch (PDOException $e) { /* subscription_events 未存在は無視 */ }

// -------- 3. 予約通知メール失敗検知 --------
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservation_notifications_log WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    $stmt->execute();
    $n = (int)$stmt->fetchColumn();
    if ($n > 0) {
        _logEvent($pdo, 'custom', 'warn', 'reservation_notifications_log', '予約メール送信失敗 (' . $n . ' 件) 直近5分', null);
        $result['reserve_mail_failed'] = $n;
    }
} catch (PDOException $e) { /* 未存在は無視 */ }

// -------- 3-b. CB1: error_log の集計 → 閾値超過で monitor_events に昇格 --------
//   - 同一 errorNo が直近 5 分で 20 件以上 → warn (UI バグ or 攻撃の可能性)
//   - 5xx 系エラー (DB_ERROR / GATEWAY_ERROR 等) が 3 件以上 → error
//   - 認証系エラー (E3xxx 401/403) が 30 件以上 → warn (ブルートフォースの可能性)
try {
    $cb1 = $pdo->query(
        "SELECT error_no, code, http_status, COUNT(*) AS cnt, MAX(message) AS msg
         FROM error_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         GROUP BY error_no, code, http_status
         HAVING cnt >= 20
            OR (http_status >= 500 AND cnt >= 3)
            OR (http_status IN (401, 403) AND cnt >= 30)
         ORDER BY cnt DESC
         LIMIT 10"
    );
    $rows = $cb1 ? $cb1->fetchAll() : [];
    foreach ($rows as $r) {
        $sev = ((int)$r['http_status'] >= 500) ? 'error' : 'warn';
        $tag = $r['error_no'] ? $r['error_no'] : $r['code'];
        $title = '[' . $tag . '] ' . $r['code'] . ' が ' . $r['cnt'] . ' 件 (直近5分)';
        $detail = 'http_status=' . $r['http_status'] . "\nmessage=" . $r['msg'];
        // 同一 errorNo の重複通知を防ぐため、直近 30 分のイベントを確認
        $dup = $pdo->prepare(
            "SELECT COUNT(*) FROM monitor_events
             WHERE source = 'error_log' AND title LIKE ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        $dup->execute(['[' . $tag . ']%']);
        if ((int)$dup->fetchColumn() === 0) {
            _logEvent($pdo, 'custom', $sev, 'error_log', $title, $detail);
            _notifySlack($settings, ($sev === 'error' ? '🔴' : '⚠️') . ' POSLA: ' . $title, $detail);
            $result['slack_sent']++;
        }
        $result['api_errors'] += (int)$r['cnt'];
    }
} catch (PDOException $e) { /* error_log 未存在は無視 */ }

// -------- 4. 連続失敗 → メール送信 --------
try {
    $critStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM monitor_events WHERE severity IN ('error','critical') AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND notified_email = 0"
    );
    $critStmt->execute();
    $critCount = (int)$critStmt->fetchColumn();
    if ($critCount >= 3 && !empty($settings['ops_notify_email'])) {
        _sendOpsMail($settings['ops_notify_email'], 'POSLA 連続異常検知', '直近15分で ' . $critCount . ' 件の異常が記録されています。/api/monitor/events を確認してください。');
        $pdo->prepare("UPDATE monitor_events SET notified_email = 1 WHERE notified_email = 0 AND severity IN ('error','critical') AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->execute();
    }
} catch (PDOException $e) { /* ignore */ }

if (php_sapi_name() === 'cli') {
    echo "[I-1] monitor: " . json_encode($result) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'result' => $result]);
}

// ---------- Helper ----------
function _tail($filepath, $lines) {
    $f = @fopen($filepath, 'rb');
    if (!$f) return [];
    fseek($f, 0, SEEK_END);
    $size = ftell($f);
    $pos = max(0, $size - 30000);
    fseek($f, $pos);
    $content = fread($f, $size - $pos);
    fclose($f);
    $arr = explode("\n", $content);
    return array_slice($arr, -$lines);
}

function _logEvent($pdo, $type, $severity, $source, $title, $detail) {
    try {
        $id = bin2hex(random_bytes(18));
        $pdo->prepare(
            "INSERT INTO monitor_events (id, event_type, severity, source, title, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$id, $type, $severity, $source, mb_substr($title, 0, 250), mb_substr($detail ?? '', 0, 1000)]);
    } catch (PDOException $e) {
        error_log('[I-1][monitor] log_event_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }
}

function _notifySlack($settings, $title, $detail) {
    $url = $settings['slack_webhook_url'] ?? '';
    if (!$url) return;
    $payload = json_encode([
        'text' => $title,
        'attachments' => [['text' => mb_substr($detail ?? '', 0, 2000), 'color' => '#ff9800']]
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function _sendOpsMail($to, $subject, $body) {
    if (function_exists('mb_language')) { mb_language('Japanese'); mb_internal_encoding('UTF-8'); }
    $fromName = 'POSLA 運用監視';
    $fromEmail = 'noreply@eat.posla.jp';
    $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
    $headers = "From: " . $fromHeader . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    if (function_exists('mb_send_mail')) @mb_send_mail($to, $subject, $body, $headers);
    else @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}
