<?php
/**
 * L-9 予約管理 (客側) — 事前チェックイン (来店前のメニュー閲覧 + 注文準備)
 *
 * GET ?id=xxx&t=editToken
 *  - 予約 confirmed/seated 状態で、reserved_at の前後 30 分以内なら
 *    table_session が起票済みなら session_token を返し、
 *    まだなら「事前メニュー閲覧モード」(read-only) のメニューを返す
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['GET']);

// H-03: 事前チェックイン濫用 / 予約 ID 推測防御 — 1IP あたり 30 回 / 5 分
check_rate_limit('customer-reservation-precheckin', 30, 300);

$pdo = get_db();

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
$token = isset($_GET['t']) ? trim($_GET['t']) : '';
if (!$id || !$token) json_error('MISSING_PARAM', 'id と t が必要です', 400);

$stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) json_error('NOT_FOUND', '予約が見つかりません', 404);
if (!hash_equals((string)$r['edit_token'], (string)$token)) json_error('FORBIDDEN', '本人確認に失敗しました', 403);

if ($r['status'] === 'seated' && $r['table_session_id']) {
    $tsStmt = $pdo->prepare('SELECT id, table_id, session_token FROM table_sessions WHERE id = ?');
    $tsStmt->execute([$r['table_session_id']]);
    $ts = $tsStmt->fetch();
    if ($ts) {
        $url = 'https://eat.posla.jp/public/customer/menu.html?store_id=' . urlencode($r['store_id']) . '&table_id=' . urlencode($ts['table_id']) . '&token=' . urlencode($ts['session_token']);
        json_response([
            'mode' => 'live',
            'menu_url' => $url,
            'message' => 'ご来店ありがとうございます。',
        ]);
    }
}

if (!in_array($r['status'], ['confirmed','pending'], true)) {
    json_error('NOT_AVAILABLE', '事前チェックインできない状態です', 400);
}

$reservedTs = strtotime($r['reserved_at']);
$now = time();
if ($now < $reservedTs - 30 * 60) {
    json_error('TOO_EARLY', '予約時刻 30 分前から利用できます', 400);
}

// プレビューモード (注文不可、メニュー閲覧のみ)
$previewUrl = 'https://eat.posla.jp/public/customer/menu.html?store_id=' . urlencode($r['store_id']) . '&preview=1&reservation_id=' . urlencode($id);
json_response([
    'mode' => 'preview',
    'preview_url' => $previewUrl,
    'message' => '来店時にスタッフへ予約番号 ' . $id . ' とこの画面をお見せください。',
]);
