<?php
/**
 * 予約通知チャネルの接続テスト。
 *
 * POST /api/store/reservation-notification-test.php
 *   { store_id, channel: "sms", phone }
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/reservation-availability.php';

require_method(['POST']);
$user = require_role('manager');
$pdo = get_db();

$body = get_json_body();
$storeId = trim((string)($body['store_id'] ?? ''));
if ($storeId === '') json_error('MISSING_STORE', 'store_id が必要です', 400);
require_store_access($storeId);

$channel = trim((string)($body['channel'] ?? 'sms'));
if ($channel !== 'sms') json_error('INVALID_CHANNEL', 'channel は sms のみ指定できます', 400);

$phone = trim((string)($body['phone'] ?? ''));
if ($phone === '' || mb_strlen($phone) > 40 || !preg_match('/^[0-9+\-\s()]+$/', $phone)) {
    json_error('INVALID_PHONE', 'テスト送信先の電話番号が不正です', 400);
}

$settings = get_reservation_settings($pdo, $storeId);
if ((int)($settings['sms_enabled'] ?? 0) !== 1) {
    json_error('SMS_DISABLED', 'SMS Webhook通知が無効です', 400);
}
$url = trim((string)($settings['sms_webhook_url'] ?? ''));
if ($url === '') json_error('SMS_WEBHOOK_MISSING', 'SMS Webhook URL が未設定です', 400);
if (!preg_match('/^https:\/\//', $url)) {
    json_error('SMS_WEBHOOK_REQUIRES_HTTPS', 'SMS Webhook URL は https:// が必要です', 400);
}
if (!function_exists('curl_init')) {
    json_error('CURL_UNAVAILABLE', 'curl が利用できません', 500);
}

$payload = [
    'type' => 'connection_test',
    'store_id' => $storeId,
    'reservation_id' => null,
    'phone' => $phone,
    'message' => 'POSLA SMS接続テスト ' . date('Y-m-d H:i:s'),
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 10,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$ok = (!$err && $code >= 200 && $code < 300);
json_response([
    'sent' => $ok,
    'http_status' => $code,
    'error' => $ok ? null : ($err ?: ('HTTP_' . $code . ':' . mb_substr((string)$raw, 0, 160))),
]);
