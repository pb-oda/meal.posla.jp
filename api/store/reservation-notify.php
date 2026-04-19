<?php
/**
 * L-9 予約管理 — 手動通知再送
 * POST { reservation_id, store_id, type: 'confirm'|'reminder_24h'|'reminder_2h'|'cancel' }
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/reservation-notifier.php';

require_method(['POST']);
$user = require_role('staff');
$pdo = get_db();

$body = get_json_body();
$resId = isset($body['reservation_id']) ? trim($body['reservation_id']) : '';
$storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
$type = isset($body['type']) ? trim($body['type']) : 'confirm';
if (!$resId || !$storeId) json_error('MISSING_PARAM', 'reservation_id と store_id が必要です', 400);
if (!in_array($type, ['confirm','reminder_24h','reminder_2h','cancel','no_show'], true)) {
    json_error('INVALID_TYPE', '不正な通知タイプ', 400);
}
require_store_access($storeId);

$stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND store_id = ?');
$stmt->execute([$resId, $storeId]);
$r = $stmt->fetch();
if (!$r) json_error('RESERVATION_NOT_FOUND', '予約が見つかりません', 404);
if (empty($r['customer_email'])) json_error('NO_EMAIL', 'メールアドレスが登録されていません', 400);

$editUrl = 'https://eat.posla.jp/public/customer/reserve-detail.html?id=' . urlencode($resId) . '&t=' . urlencode($r['edit_token'] ?: '');
$result = send_reservation_notification($pdo, $r, $type, ['edit_url' => $editUrl]);
if ($result['success']) {
    json_response(['ok' => true, 'sent' => true]);
}
json_error('SEND_FAILED', $result['error'] ?: '送信失敗', 500);
