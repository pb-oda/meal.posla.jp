<?php
/**
 * I-1 運用支援エージェント Phase 1: ヘルスチェック + 異常検知
 *
 * 推奨実行: 5分毎 (Sakura cron)
 *
 * やること:
 *   1. POSLA_PHP_ERROR_LOG の末尾を走査 → 直近 5 分の ERROR/FATAL を検出
 *   2. subscription_events / reservation_notifications_log の 'failed' を検知
 *   3. posla_settings.monitor_last_heartbeat を更新 (外部から監視される用)
 *
 * 異常検知時:
 *   - monitor_events に記録
 *   - Google Chat webhook 通知 (posla_settings.google_chat_webhook_url)
 *     ※ 旧 slack_webhook_url は fallback 用に温存
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
require_once __DIR__ . '/../lib/push.php';
require_once __DIR__ . '/../config/app.php';
$pdo = get_db();

$now = date('Y-m-d H:i:s');
$result = ['php_errors' => 0, 'stripe_failed' => 0, 'reserve_mail_failed' => 0, 'api_errors' => 0, 'alert_sent' => 0, 'slack_sent' => 0];

// posla_settings から通知先を取得
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM posla_settings WHERE setting_key IN ('google_chat_webhook_url','slack_webhook_url','ops_notify_email')");
    foreach ($stmt->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (PDOException $e) { /* テーブル未存在は無視 */ }

// heartbeat 更新
try {
    $pdo->prepare("UPDATE posla_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'monitor_last_heartbeat'")
        ->execute([$now]);
} catch (PDOException $e) { /* ignore */ }

// -------- 1. PHP エラーログ走査 (直近 5 分) --------
$logFile = POSLA_PHP_ERROR_LOG;
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
        $destination = _notifyOpsAlert($settings, '⚠️ POSLA: PHP エラー ' . count($recentErrors) . ' 件', $detail);
        _logEvent($pdo, 'php_error', 'error', 'php_errors.log', 'PHP エラーを検知 (' . count($recentErrors) . ' 件)', $detail, null, null, $destination !== '');
        $result['php_errors'] = count($recentErrors);
        if ($destination !== '') {
            $result['alert_sent']++;
            if ($destination === 'slack_legacy') $result['slack_sent']++;
        }
    }
}

// -------- 2. Stripe Webhook 失敗検知 (subscription_events の最近の error_message) --------
//   Phase 2b: tenant_id ごとに集計し、失敗が発生したテナントの manager/owner に
//             important_error Web Push を送る (重複抑制は _pushImportantError 内で実施)。
try {
    $stmt = $pdo->prepare(
        "SELECT tenant_id, COUNT(*) AS cnt
           FROM subscription_events
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            AND (error_message IS NOT NULL AND error_message <> '')
          GROUP BY tenant_id"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $total = 0;
    foreach ($rows as $r) $total += (int)$r['cnt'];
    if ($total > 0) {
        $destination = _notifyOpsAlert($settings, '⚠️ POSLA: Stripe Webhook 失敗 ' . $total . ' 件 (直近5分)', '詳細は subscription_events テーブル参照');
        _logEvent($pdo, 'stripe_webhook_fail', 'warn', 'subscription_events', 'Stripe Webhook 失敗 (' . $total . ' 件) 直近5分', null, null, null, $destination !== '');
        foreach ($rows as $r) {
            if (!empty($r['tenant_id'])) {
                _pushImportantError(
                    $pdo, $r['tenant_id'], 'stripe_webhook_fail',
                    'Stripe Webhook 失敗',
                    '直近 5 分に ' . (int)$r['cnt'] . ' 件失敗。subscription_events を確認してください'
                );
            }
        }
        $result['stripe_failed'] = $total;
        if ($destination !== '') {
            $result['alert_sent']++;
            if ($destination === 'slack_legacy') $result['slack_sent']++;
        }
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
        "SELECT error_no, code, http_status, tenant_id, store_id, request_path,
                COUNT(*) AS cnt, MAX(message) AS msg
         FROM error_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         GROUP BY error_no, code, http_status, tenant_id, store_id, request_path
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
        $tenantId = isset($r['tenant_id']) ? trim((string)$r['tenant_id']) : '';
        $storeId = isset($r['store_id']) ? trim((string)$r['store_id']) : '';
        $requestPath = isset($r['request_path']) ? trim((string)$r['request_path']) : '';
        $requestPathLabel = $requestPath !== '' ? $requestPath : '-';
        $detail = implode("\n", [
            'テナント: ' . ($tenantId !== '' ? $tenantId : '-'),
            '店舗: ' . ($storeId !== '' ? $storeId : '-'),
            'エラー番号: ' . $tag,
            'エラーコード: ' . $r['code'],
            'HTTP: ' . $r['http_status'],
            '件数: ' . $r['cnt'] . ' 件 / 直近5分',
            '経路: ' . $requestPathLabel,
            '内容: ' . (string)$r['msg'],
            'ソース: error_log',
        ]);
        // 同一 errorNo + tenant/store/path の重複通知を防ぐため、直近 30 分のイベントを確認
        $dup = $pdo->prepare(
            "SELECT COUNT(*) FROM monitor_events
             WHERE source = 'error_log'
               AND title = ?
               AND COALESCE(tenant_id, '') = ?
               AND COALESCE(store_id, '') = ?
               AND detail LIKE ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        $dup->execute([$title, $tenantId, $storeId, '%経路: ' . $requestPathLabel . '%']);
        if ((int)$dup->fetchColumn() === 0) {
            $destination = _notifyOpsAlert($settings, ($sev === 'error' ? '🔴' : '⚠️') . ' POSLA: ' . $title, $detail);
            _logEvent($pdo, 'custom', $sev, 'error_log', $title, $detail, $tenantId, $storeId, $destination !== '');
            if ($destination !== '') {
                $result['alert_sent']++;
                if ($destination === 'slack_legacy') $result['slack_sent']++;
            }
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

function _logEvent($pdo, $type, $severity, $source, $title, $detail, $tenantId = null, $storeId = null, $notifiedWebhook = false) {
    try {
        $id = bin2hex(random_bytes(18));
        $pdo->prepare(
            "INSERT INTO monitor_events
                (id, event_type, severity, source, title, detail, tenant_id, store_id, notified_slack, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $id,
            $type,
            $severity,
            $source,
            mb_substr($title, 0, 250),
            mb_substr($detail ?? '', 0, 1000),
            $tenantId,
            $storeId,
            $notifiedWebhook ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        error_log('[I-1][monitor] log_event_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }
}

function _notifyOpsAlert($settings, $title, $detail) {
    $googleChatUrl = trim((string)($settings['google_chat_webhook_url'] ?? ''));
    if ($googleChatUrl !== '') {
        return _notifyGoogleChat($googleChatUrl, $title, $detail) ? 'google_chat' : '';
    }

    $slackUrl = trim((string)($settings['slack_webhook_url'] ?? ''));
    if ($slackUrl !== '') {
        return _notifySlack($slackUrl, $title, $detail) ? 'slack_legacy' : '';
    }

    return '';
}

function _notifyGoogleChat($url, $title, $detail) {
    $payload = json_encode([
        'text' => $title . "\n" . mb_substr((string)($detail ?? ''), 0, 3000)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return _postWebhookJson($url, $payload);
}

function _notifySlack($url, $title, $detail) {
    $payload = json_encode([
        'text' => $title,
        'attachments' => [['text' => mb_substr($detail ?? '', 0, 2000), 'color' => '#ff9800']]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return _postWebhookJson($url, $payload);
}

function _postWebhookJson($url, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $raw = @curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($raw !== false && $httpCode >= 200 && $httpCode < 300);
}

/**
 * Phase 2b: 重要エラーを該当テナントの manager/owner に Web Push する。
 *
 * スパム防止:
 *   - 同 tenant_id + 同 tag (= $type) で直近 5 分に 2xx 送信済みならスキップ
 *   - push_send_log (migration-pwa2b-push-log.sql) を参照
 *
 * 呼び出し元:
 *   - monitor-health.php Stripe Webhook 失敗検知 (tenant 別に集計したあと 1 件ずつ呼ぶ)
 *   - 今後 reservation_notifications_log 失敗 / error_log 閾値超過も tenant_id を
 *     引いた上で本関数を呼べば通知できる (将来拡張ポイント)
 *
 * 送信先:
 *   - そのテナントの「代表店舗」(is_active=1 で最古の store) に紐付けた manager/owner
 *   - owner-dashboard の store_id=NULL 購読も push_send_to_roles の OR 条件で拾う (§9.14.5)
 *   - POSLA 運営 (posla_admins) は独自認証系のため対象外。Slack 通知は継続される
 */
function _pushImportantError($pdo, $tenantId, $type, $title, $detail) {
    try {
        // 重複抑制: 直近 5 分に同 tenant+type で送信済みなら skip
        $dupStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM push_send_log
              WHERE tenant_id = ?
                AND type = 'important_error'
                AND tag = ?
                AND status_code >= 200 AND status_code < 300
                AND sent_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        $dupStmt->execute([$tenantId, $type]);
        if ((int)$dupStmt->fetchColumn() > 0) return;

        // 代表店舗を取得 (push_send_to_roles の店舗絞り込みに使う)
        $storeStmt = $pdo->prepare(
            "SELECT id FROM stores WHERE tenant_id = ? AND is_active = 1 ORDER BY created_at ASC LIMIT 1"
        );
        $storeStmt->execute([$tenantId]);
        $storeId = $storeStmt->fetchColumn();
        if (!$storeId) return;

        push_send_to_roles($pdo, $storeId, ['manager', 'owner'], 'important_error', [
            'title' => '重要エラー: ' . $title,
            'body'  => mb_substr((string)$detail, 0, 140),
            'url'   => '/admin/dashboard.html',
            'tag'   => $type,
        ]);
    } catch (\Throwable $e) {
        error_log('[I-1][push] important_error_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }
}

function _sendOpsMail($to, $subject, $body) {
    if (function_exists('mb_language')) { mb_language('Japanese'); mb_internal_encoding('UTF-8'); }
    $fromName = 'POSLA 運用監視';
    $fromEmail = APP_FROM_EMAIL;
    $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
    $headers = "From: " . $fromHeader . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    if (function_exists('mb_send_mail')) @mb_send_mail($to, $subject, $body, $headers);
    else @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}
