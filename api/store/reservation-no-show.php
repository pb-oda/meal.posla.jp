<?php
/**
 * L-9 予約管理 — no-show 処理
 * POST { reservation_id, store_id }
 *  - status='no_show' に更新
 *  - デポジットがあれば capture
 *  - 顧客の no_show_count++
 *  - 通知メール
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/reservation-deposit.php';
require_once __DIR__ . '/../lib/reservation-notifier.php';
require_once __DIR__ . '/../lib/reservation-history.php';
require_once __DIR__ . '/../lib/reservation-waitlist.php';

require_method(['POST']);
$user = require_role('staff');
$pdo = get_db();

$body = get_json_body();
$resId = isset($body['reservation_id']) ? trim($body['reservation_id']) : '';
$storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
if (!$resId || !$storeId) json_error('MISSING_PARAM', 'reservation_id と store_id が必要です', 400);
require_store_access($storeId);

$stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND store_id = ?');
$stmt->execute([$resId, $storeId]);
$r = $stmt->fetch();
if (!$r) json_error('RESERVATION_NOT_FOUND', '予約が見つかりません', 404);
if (!in_array($r['status'], ['confirmed','pending'], true)) {
    json_error('INVALID_STATUS', 'no-show 設定できないステータス: ' . $r['status'], 400);
}

$sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores WHERE id = ?');
$sStmt->execute([$storeId]);
$store = $sStmt->fetch();

$capturedAmount = 0;
$captureError = null;
if ((int)$r['deposit_required'] === 1 && $r['deposit_payment_intent_id'] && $r['deposit_status'] === 'authorized') {
    $cap = reservation_deposit_capture($pdo, $r, $store);
    if ($cap['success']) {
        $capturedAmount = (int)$r['deposit_amount'];
        $pdo->prepare("UPDATE reservations SET deposit_status = 'captured' WHERE id = ?")->execute([$resId]);
    } else {
        $captureError = $cap['error'];
        error_log('[L-9][no-show] capture_failed res=' . $resId . ' err=' . $cap['error'], 3, POSLA_PHP_ERROR_LOG);
    }
}

try {
    $pdo->prepare(
        "UPDATE reservations
         SET status = 'no_show',
             arrival_followup_status = 'no_show_confirmed',
             arrival_followup_at = NOW(),
             arrival_followup_user_id = ?,
             updated_at = NOW()
         WHERE id = ? AND store_id = ?"
    )->execute([$user['user_id'], $resId, $storeId]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'arrival_followup_status') === false) {
        throw $e;
    }
    $pdo->prepare("UPDATE reservations SET status = 'no_show', updated_at = NOW() WHERE id = ? AND store_id = ?")->execute([$resId, $storeId]);
}
if ($r['customer_phone']) {
    $pdo->prepare('UPDATE reservation_customers SET no_show_count = no_show_count + 1 WHERE store_id = ? AND customer_phone = ?')
        ->execute([$storeId, $r['customer_phone']]);
}

$rAfterStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND store_id = ?');
$rAfterStmt->execute([$resId, $storeId]);
$rAfter = $rAfterStmt->fetch();
if ($rAfter) {
    reservation_history_record_fields(
        $pdo,
        $r,
        $rAfter,
        ['status','arrival_followup_status','arrival_followup_at','arrival_followup_user_id'],
        'staff',
        $user['user_id'],
        $user['username'] ?? $user['displayName'] ?? '',
        'no_show'
    );
}
$waitlistNotify = reservation_waitlist_notify_open_slot($pdo, $storeId, $r['reserved_at'], (int)$r['party_size'], 'reservation_no_show');

if ($r['customer_email']) {
    $r2 = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $r2->execute([$resId]);
    send_reservation_notification($pdo, $r2->fetch(), 'no_show');
}

json_response(['ok' => true, 'captured_amount' => $capturedAmount, 'capture_error' => $captureError, 'waitlist_notify' => $waitlistNotify]);
