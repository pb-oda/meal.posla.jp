<?php
/**
 * L-9 予約管理 (客側) — 予約照会
 *
 * GET ?id=xxx&t=editToken[&phone=xxx]
 *  - edit_token または (id + customer_phone) で本人確認
 *  - 認証不要 (URL知ってる本人 + token がカギ)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['GET']);

// H-03: 予約 ID 推測による個人情報覗き防御 — 1IP あたり 30 回 / 5 分
check_rate_limit('customer-reservation-detail', 30, 300);

$pdo = get_db();

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
$token = isset($_GET['t']) ? trim($_GET['t']) : '';
$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
if (!$id) json_error('MISSING_ID', 'id が必要です', 400);

$stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) json_error('NOT_FOUND', '予約が見つかりません', 404);

$ok = false;
if ($token && $r['edit_token'] && hash_equals((string)$r['edit_token'], (string)$token)) $ok = true;
if (!$ok && $phone && $r['customer_phone'] && $phone === $r['customer_phone']) $ok = true;
if (!$ok) json_error('FORBIDDEN', '本人確認に失敗しました', 403);

// store info
$sStmt = $pdo->prepare('SELECT s.id, s.name, ss.receipt_phone AS phone, ss.receipt_address AS address FROM stores s LEFT JOIN store_settings ss ON ss.store_id = s.id WHERE s.id = ?');
$sStmt->execute([$r['store_id']]);
$store = $sStmt->fetch();

$settings = $pdo->prepare('SELECT cancel_deadline_hours, notes_to_customer FROM reservation_settings WHERE store_id = ?');
$settings->execute([$r['store_id']]);
$cfg = $settings->fetch();

json_response([
    'reservation' => [
        'id' => $r['id'],
        'store_id' => $r['store_id'],
        'customer_name' => $r['customer_name'],
        'customer_phone' => $r['customer_phone'],
        'customer_email' => $r['customer_email'],
        'party_size' => (int)$r['party_size'],
        'reserved_at' => $r['reserved_at'],
        'duration_min' => (int)$r['duration_min'],
        'status' => $r['status'],
        'memo' => $r['memo'],
        'course_name' => $r['course_name'],
        'language' => $r['language'],
        'deposit_required' => (int)$r['deposit_required'],
        'deposit_amount' => (int)$r['deposit_amount'],
        'deposit_status' => $r['deposit_status'],
    ],
    'store' => $store,
    'cancel_deadline_hours' => $cfg ? (int)$cfg['cancel_deadline_hours'] : 3,
    'notes_to_customer' => $cfg ? $cfg['notes_to_customer'] : null,
]);
