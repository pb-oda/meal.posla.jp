<?php
/**
 * L-9 予約管理 (客側) — キャンセル
 *
 * POST { id, edit_token, reason? }
 *  - cancel_deadline_hours 以内なら、デポジットあれば没収 (capture)
 *  - それより早ければ release (返金)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/reservation-availability.php';
require_once __DIR__ . '/../lib/reservation-deposit.php';
require_once __DIR__ . '/../lib/reservation-notifier.php';

require_method(['POST']);
check_rate_limit('reserve-cancel:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 5, 60);

$pdo = get_db();
$body = get_json_body();
$id = isset($body['id']) ? trim($body['id']) : '';
$token = isset($body['edit_token']) ? trim($body['edit_token']) : '';
if (!$id || !$token) json_error('MISSING_PARAM', 'id と edit_token が必要です', 400);

$stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) json_error('NOT_FOUND', '予約が見つかりません', 404);
if (!hash_equals((string)$r['edit_token'], (string)$token)) json_error('FORBIDDEN', '本人確認に失敗しました', 403);
if (in_array($r['status'], ['cancelled','seated','no_show','completed'], true)) json_error('ALREADY_FINAL', 'この予約は既に確定状態です', 400);

$reservedTs = strtotime($r['reserved_at']);
$settings = get_reservation_settings($pdo, $r['store_id']);
$deadlineTs = $reservedTs - (int)$settings['cancel_deadline_hours'] * 3600;
$pastDeadline = time() > $deadlineTs;

$reason = isset($body['reason']) ? mb_substr((string)$body['reason'], 0, 200) : 'customer_cancel';
$pdo->prepare("UPDATE reservations SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ?, updated_at = NOW() WHERE id = ?")->execute([$reason, $id]);

$depositOutcome = null;
if ((int)$r['deposit_required'] === 1 && $r['deposit_payment_intent_id'] && $r['deposit_status'] === 'authorized') {
    $sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores WHERE id = ?');
    $sStmt->execute([$r['store_id']]);
    $store = $sStmt->fetch();
    if ($pastDeadline) {
        $cap = reservation_deposit_capture($pdo, $r, $store);
        if ($cap['success']) {
            $pdo->prepare("UPDATE reservations SET deposit_status = 'captured' WHERE id = ?")->execute([$id]);
            $depositOutcome = 'captured';
        }
    } else {
        $rel = reservation_deposit_release($pdo, $r, $store);
        if ($rel['success']) {
            $pdo->prepare("UPDATE reservations SET deposit_status = 'released' WHERE id = ?")->execute([$id]);
            $depositOutcome = 'released';
        }
    }
}

if ($r['customer_phone']) {
    $pdo->prepare('UPDATE reservation_customers SET cancel_count = cancel_count + 1 WHERE store_id = ? AND customer_phone = ?')
        ->execute([$r['store_id'], $r['customer_phone']]);
}

if ($r['customer_email']) {
    $r2 = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $r2->execute([$id]);
    send_reservation_notification($pdo, $r2->fetch(), 'cancel');
}

json_response(['ok' => true, 'past_deadline' => $pastDeadline, 'deposit_outcome' => $depositOutcome]);
