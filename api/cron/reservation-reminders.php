<?php
/**
 * L-9 予約管理 — 自動リマインダー送信 (cron)
 *
 * 推奨実行頻度: 5〜10 分ごと
 *
 * - 24h前リマインダー: 24h±5分以内に reserved_at を持ち、reminder_24h_sent_at が NULL の confirmed/seated を対象
 * - 2h前リマインダー: 2h±5分以内、reminder_2h_sent_at が NULL
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

$pdo = get_db();

$processed = ['reminder_24h' => 0, 'reminder_2h' => 0, 'failed' => 0, 'skipped_disabled' => 0];

// 24h
$stmt = $pdo->prepare(
    "SELECT r.* FROM reservations r
     WHERE r.status IN ('confirmed','pending')
       AND r.customer_email IS NOT NULL AND r.customer_email <> ''
       AND r.reminder_24h_sent_at IS NULL
       AND r.reserved_at BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR) AND DATE_ADD(NOW(), INTERVAL 25 HOUR)"
);
$stmt->execute();
foreach ($stmt->fetchAll() as $r) {
    $settings = get_reservation_settings($pdo, $r['store_id']);
    if ((int)$settings['reminder_24h_enabled'] !== 1) {
        $processed['skipped_disabled']++;
        continue;
    }
    $editUrl = 'https://eat.posla.jp/public/customer/reserve-detail.html?id=' . urlencode($r['id']) . '&t=' . urlencode($r['edit_token'] ?: '');
    $res = send_reservation_notification($pdo, $r, 'reminder_24h', ['edit_url' => $editUrl]);
    if ($res['success']) {
        $pdo->prepare('UPDATE reservations SET reminder_24h_sent_at = NOW() WHERE id = ?')->execute([$r['id']]);
        $processed['reminder_24h']++;
    } else {
        $processed['failed']++;
    }
}

// 2h
$stmt2 = $pdo->prepare(
    "SELECT r.* FROM reservations r
     WHERE r.status IN ('confirmed','pending')
       AND r.customer_email IS NOT NULL AND r.customer_email <> ''
       AND r.reminder_2h_sent_at IS NULL
       AND r.reserved_at BETWEEN DATE_ADD(NOW(), INTERVAL 105 MINUTE) AND DATE_ADD(NOW(), INTERVAL 135 MINUTE)"
);
$stmt2->execute();
foreach ($stmt2->fetchAll() as $r) {
    $settings = get_reservation_settings($pdo, $r['store_id']);
    if ((int)$settings['reminder_2h_enabled'] !== 1) {
        $processed['skipped_disabled']++;
        continue;
    }
    $editUrl = 'https://eat.posla.jp/public/customer/reserve-detail.html?id=' . urlencode($r['id']) . '&t=' . urlencode($r['edit_token'] ?: '');
    $res = send_reservation_notification($pdo, $r, 'reminder_2h', ['edit_url' => $editUrl]);
    if ($res['success']) {
        $pdo->prepare('UPDATE reservations SET reminder_2h_sent_at = NOW() WHERE id = ?')->execute([$r['id']]);
        $processed['reminder_2h']++;
    } else {
        $processed['failed']++;
    }
}

if (php_sapi_name() === 'cli') {
    echo "[L-9] reminders: " . json_encode($processed) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'processed' => $processed]);
}
