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

$pdo->prepare("UPDATE reservations SET status = 'no_show', updated_at = NOW() WHERE id = ?")->execute([$resId]);
if ($r['customer_phone']) {
    $pdo->prepare('UPDATE reservation_customers SET no_show_count = no_show_count + 1 WHERE store_id = ? AND customer_phone = ?')
        ->execute([$storeId, $r['customer_phone']]);
}

if ($r['customer_email']) {
    $r2 = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $r2->execute([$resId]);
    send_reservation_notification($pdo, $r2->fetch(), 'no_show');
}

json_response(['ok' => true, 'captured_amount' => $capturedAmount, 'capture_error' => $captureError]);
