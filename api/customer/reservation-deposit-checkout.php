<?php
/**
 * L-9 予約管理 (客側) — デポジット決済の Checkout URL 再発行
 *
 * POST { id, edit_token }
 *  - status='pending' かつ deposit_status='pending' の予約に対し、
 *    Stripe Checkout Session を再生成して URL を返す
 *  - 元 session が expire したケース等で使用
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/reservation-deposit.php';

require_method(['POST']);
check_rate_limit('reserve-deposit:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 5, 60);

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
if ((int)$r['deposit_required'] !== 1) json_error('NO_DEPOSIT', 'この予約に予約金は不要です', 400);
if (!in_array($r['deposit_status'], ['pending','failed'], true)) json_error('INVALID_STATUS', '既に処理済みです: ' . $r['deposit_status'], 400);

$sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores WHERE id = ?');
$sStmt->execute([$r['store_id']]);
$store = $sStmt->fetch();

$base = 'https://eat.posla.jp/public/customer/reserve-detail.html';
$successUrl = $base . '?id=' . urlencode($id) . '&t=' . urlencode($token) . '&deposit=success';
$cancelUrl = $base . '?id=' . urlencode($id) . '&t=' . urlencode($token) . '&deposit=cancel';
$checkout = reservation_deposit_create_checkout($pdo, $r, $store, (int)$r['deposit_amount'], $successUrl, $cancelUrl);
if (!$checkout['success']) json_error('CHECKOUT_FAILED', $checkout['error'] ?: '不明', 503);
$pdo->prepare("UPDATE reservations SET deposit_session_id = ? WHERE id = ?")->execute([$checkout['session_id'], $id]);
json_response(['checkout_url' => $checkout['checkout_url'], 'session_id' => $checkout['session_id']]);
