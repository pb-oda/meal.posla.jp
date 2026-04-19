<?php
/**
 * L-9 予約管理 (客側) — 予約変更
 *
 * POST { id, edit_token, party_size?, reserved_at?, memo? }
 *  - cancel_deadline_hours 以内なら変更拒否
 *  - 時間/人数変更時は空席再確認
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/reservation-availability.php';

require_method(['POST']);
check_rate_limit('reserve-update:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 10, 60);

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
if (in_array($r['status'], ['cancelled','seated','no_show','completed'], true)) json_error('ALREADY_FINAL', '変更できない状態です', 400);

$reservedTs = strtotime($r['reserved_at']);
$settings = get_reservation_settings($pdo, $r['store_id']);
$deadlineTs = $reservedTs - (int)$settings['cancel_deadline_hours'] * 3600;
if (time() > $deadlineTs) json_error('CANCEL_DEADLINE_PASSED', '変更締切時刻を過ぎています', 400);

$sets = []; $params = [];
$newParty = (int)$r['party_size'];
$newReservedAt = $r['reserved_at'];

if (isset($body['party_size'])) {
    $newParty = (int)$body['party_size'];
    if ($newParty < (int)$settings['min_party_size'] || $newParty > (int)$settings['max_party_size']) json_error('INVALID_PARTY_SIZE', '人数が範囲外です', 400);
}
if (isset($body['reserved_at'])) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string)$body['reserved_at'])) json_error('INVALID_DATETIME', '日時形式が不正です', 400);
    $ts = strtotime($body['reserved_at']);
    if (!$ts) json_error('INVALID_DATETIME', '日時を解釈できません', 400);
    if ($ts < time() + (int)$settings['lead_time_hours'] * 3600) json_error('LEAD_TIME_VIOLATION', '予約締切時刻を過ぎています', 400);
    $newReservedAt = date('Y-m-d H:i:s', $ts);
}

// 時間 or 人数の変更があれば空席再確認 (自分を除外)
if ($newReservedAt !== $r['reserved_at'] || $newParty !== (int)$r['party_size']) {
    $date = substr($newReservedAt, 0, 10);
    $timeStr = substr($newReservedAt, 11, 5);
    $slots = compute_slot_availability($pdo, $r['store_id'], $date, $newParty, $r['id']);
    $matched = null;
    foreach ($slots as $s) if ($s['time'] === $timeStr) { $matched = $s; break; }
    if (!$matched || !$matched['available']) json_error('SLOT_UNAVAILABLE', 'お選びの時間は満席です', 409);
    $sets[] = 'party_size = ?'; $params[] = $newParty;
    $sets[] = 'reserved_at = ?'; $params[] = $newReservedAt;
    // テーブル再割当
    $tableIds = isset($matched['suggested_tables']) ? $matched['suggested_tables'] : [];
    $sets[] = 'assigned_table_ids = ?'; $params[] = $tableIds ? json_encode($tableIds, JSON_UNESCAPED_UNICODE) : null;
}

if (isset($body['memo'])) { $sets[] = 'memo = ?'; $params[] = (string)$body['memo']; }

if (empty($sets)) json_error('NO_CHANGES', '変更項目がありません', 400);
$sets[] = 'updated_at = NOW()';
$params[] = $id;
$pdo->prepare('UPDATE reservations SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

$stmt2 = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
$stmt2->execute([$id]);
$r2 = $stmt2->fetch();
json_response(['reservation' => [
    'id' => $r2['id'], 'status' => $r2['status'], 'reserved_at' => $r2['reserved_at'], 'party_size' => (int)$r2['party_size'], 'memo' => $r2['memo'],
]]);
