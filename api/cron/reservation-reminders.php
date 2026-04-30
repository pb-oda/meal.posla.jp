<?php
/**
 * L-9 予約管理 — 自動リマインダー送信 (cron)
 *
 * 推奨実行頻度: 5〜10 分ごと
 *
 * - 24h前リマインダー: 23〜25h後に reserved_at を持ち、reminder_24h_sent_at が NULL の confirmed/pending を対象
 * - 2h前リマインダー: 90〜150分後、reminder_2h_sent_at が NULL
 * - メールアドレス必須
 * - 各店舗の reservation_settings.reminder_*_enabled を尊重
 *
 * 認証: cron 専用。CLI 実行 or 共有秘密ヘッダ
 */

if (php_sapi_name() !== 'cli') {
    // CLI 以外: 共有シークレット必須
    // 環境変数 POSLA_CRON_SECRET が未設定なら HTTP 経路は無効（CLI 専用化）
    // 既定値 'change-me' へのフォールバックは脆弱なため廃止
    $expected = getenv('POSLA_CRON_SECRET') ?: '';
    if (!$expected || ($_SERVER['HTTP_X_POSLA_CRON_SECRET'] ?? '') !== $expected) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/reservation-availability.php';
require_once __DIR__ . '/../lib/reservation-notifier.php';
require_once __DIR__ . '/../config/app.php';

$pdo = get_db();

$processed = ['reminder_24h' => 0, 'reminder_2h' => 0, 'retry_sent' => 0, 'retry_failed' => 0, 'failed' => 0, 'skipped_disabled' => 0, 'failed_ids' => [], 'manager_attention_ids' => []];

function posla_reservation_send_reminder($pdo, $r, $type, &$processed, $retryLog = null) {
    $settings = get_reservation_settings($pdo, $r['store_id']);
    $flag = $type === 'reminder_24h' ? 'reminder_24h_enabled' : 'reminder_2h_enabled';
    if ((int)$settings[$flag] !== 1) {
        $processed['skipped_disabled']++;
        return false;
    }
    $editUrl = app_url('/customer/reserve-detail.html') . '?id=' . urlencode($r['id']) . '&t=' . urlencode($r['edit_token'] ?: '');
    $res = send_reservation_notification($pdo, $r, $type, ['edit_url' => $editUrl]);
    $sentCol = $type === 'reminder_24h' ? 'reminder_24h_sent_at' : 'reminder_2h_sent_at';
    if ($res['success']) {
        $pdo->prepare('UPDATE reservations SET ' . $sentCol . ' = NOW() WHERE id = ?')->execute([$r['id']]);
        if ($retryLog) {
            $pdo->prepare('UPDATE reservation_notifications_log SET resolved_at = NOW(), manager_attention = 0 WHERE id = ?')->execute([$retryLog['id']]);
            $processed['retry_sent']++;
        } else {
            $processed[$type]++;
        }
        return true;
    }

    $processed[$retryLog ? 'retry_failed' : 'failed']++;
    $processed['failed_ids'][] = $r['id'];
    if ($retryLog) {
        $nextRetry = min(max(0, (int)$retryLog['retry_count']) + 1, max(1, (int)$settings['reminder_retry_max']));
        $managerAttention = $nextRetry >= max(1, (int)$settings['reminder_retry_max']) ? 1 : 0;
        if ($managerAttention) $processed['manager_attention_ids'][] = $r['id'];
        $minutes = max(1, (int)$settings['reminder_retry_minutes']) * max(1, $nextRetry);
        $pdo->prepare(
            'UPDATE reservation_notifications_log
                SET retry_count = ?, next_retry_at = DATE_ADD(NOW(), INTERVAL ' . (int)$minutes . ' MINUTE), manager_attention = ?, error_message = ?
              WHERE id = ?'
        )->execute([$nextRetry, $managerAttention, $res['error'] ?: 'SEND_FAILED', $retryLog['id']]);
        if (!empty($res['email_log_id'])) {
            $pdo->prepare(
                'UPDATE reservation_notifications_log
                    SET retry_count = ?, next_retry_at = DATE_ADD(NOW(), INTERVAL ' . (int)$minutes . ' MINUTE), manager_attention = ?, resolved_at = NOW()
                  WHERE id = ?'
            )->execute([$nextRetry, $managerAttention, $res['email_log_id']]);
        }
        $pdo->prepare(
            'UPDATE reservation_notifications_log
                SET resolved_at = NOW()
              WHERE reservation_id = ? AND store_id = ? AND notification_type = ? AND status = "failed"
                AND id <> ? AND resolved_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
        )->execute([$r['id'], $r['store_id'], $type, $retryLog['id']]);
    }
    return false;
}

// 24h
$stmt = $pdo->prepare(
    "SELECT r.* FROM reservations r
     WHERE r.status IN ('confirmed','pending')
       AND r.reminder_24h_sent_at IS NULL
       AND r.reserved_at BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR) AND DATE_ADD(NOW(), INTERVAL 25 HOUR)"
);
$stmt->execute();
foreach ($stmt->fetchAll() as $r) {
    posla_reservation_send_reminder($pdo, $r, 'reminder_24h', $processed);
}

// 2h
$stmt2 = $pdo->prepare(
    "SELECT r.* FROM reservations r
     WHERE r.status IN ('confirmed','pending')
       AND r.reminder_2h_sent_at IS NULL
       AND r.reserved_at BETWEEN DATE_ADD(NOW(), INTERVAL 90 MINUTE) AND DATE_ADD(NOW(), INTERVAL 150 MINUTE)"
);
$stmt2->execute();
foreach ($stmt2->fetchAll() as $r) {
    posla_reservation_send_reminder($pdo, $r, 'reminder_2h', $processed);
}

// 失敗済みリマインドの自動再送
$retryStmt = $pdo->prepare(
    "SELECT nl.id AS log_id, nl.retry_count, nl.notification_type, r.*
       FROM reservation_notifications_log nl
       JOIN reservations r ON r.id = nl.reservation_id AND r.store_id = nl.store_id
      WHERE nl.status = 'failed'
        AND nl.resolved_at IS NULL
        AND nl.notification_type IN ('reminder_24h','reminder_2h')
        AND nl.next_retry_at IS NOT NULL
        AND nl.next_retry_at <= NOW()
        AND r.status IN ('confirmed','pending')
        AND ((nl.notification_type = 'reminder_24h' AND r.reminder_24h_sent_at IS NULL)
          OR (nl.notification_type = 'reminder_2h' AND r.reminder_2h_sent_at IS NULL))
      ORDER BY nl.next_retry_at ASC
      LIMIT 50"
);
$retryStmt->execute();
foreach ($retryStmt->fetchAll() as $row) {
    $log = ['id' => $row['log_id'], 'retry_count' => $row['retry_count']];
    $type = $row['notification_type'];
    unset($row['log_id'], $row['retry_count'], $row['notification_type']);
    posla_reservation_send_reminder($pdo, $row, $type, $processed, $log);
}

if (php_sapi_name() === 'cli') {
    echo "[L-9] reminders: " . json_encode($processed) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'processed' => $processed]);
}
