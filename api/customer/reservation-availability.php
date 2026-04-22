<?php
/**
 * L-9 予約管理 (客側) — 空席計算 + 店舗設定取得
 *
 * GET ?store_id=xxx&date=YYYY-MM-DD&party_size=N      … 1日の空きスロット
 * GET ?store_id=xxx&action=heatmap&from=YYYY-MM-DD&days=N&party_size=N
 *                                                     … カレンダー混雑度ヒート
 * GET ?store_id=xxx&action=info                       … 店舗予約設定 (online_enabled / 営業時間など)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/reservation-availability.php';
require_once __DIR__ . '/../lib/reservation-deposit.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['GET']);

// H-03: カレンダー全期間大量スキャン DoS 防御 — 1IP あたり 30 回 / 5 分
check_rate_limit('customer-reservation-availability', 30, 300);

$pdo = get_db();

$storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);

// 公開店舗チェック
$sStmt = $pdo->prepare('SELECT id, tenant_id, name, name_en FROM stores WHERE id = ? AND is_active = 1');
$sStmt->execute([$storeId]);
$store = $sStmt->fetch();
if (!$store) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);

$settings = get_reservation_settings($pdo, $storeId);
if ((int)$settings['online_enabled'] !== 1) {
    json_error('RESERVATION_DISABLED', 'この店舗ではオンライン予約を受け付けていません', 403);
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'info') {
    $depositAvailable = reservation_deposit_is_available($pdo, $storeId, $store['tenant_id'], $settings);
    json_response([
        'store' => [
            'id' => $store['id'],
            'name' => $store['name'],
            'name_en' => $store['name_en'],
        ],
        'open_time' => substr($settings['open_time'], 0, 5),
        'close_time' => substr($settings['close_time'], 0, 5),
        'last_order_offset_min' => (int)$settings['last_order_offset_min'],
        'slot_interval_min' => (int)$settings['slot_interval_min'],
        'default_duration_min' => (int)$settings['default_duration_min'],
        'lead_time_hours' => (int)$settings['lead_time_hours'],
        'max_advance_days' => (int)$settings['max_advance_days'],
        'min_party_size' => (int)$settings['min_party_size'],
        'max_party_size' => (int)$settings['max_party_size'],
        'require_phone' => (int)$settings['require_phone'],
        'require_email' => (int)$settings['require_email'],
        'cancel_deadline_hours' => (int)$settings['cancel_deadline_hours'],
        'notes_to_customer' => $settings['notes_to_customer'],
        'weekly_closed_days' => $settings['weekly_closed_days'],
        'deposit_enabled' => (int)$settings['deposit_enabled'],
        'deposit_per_person' => (int)$settings['deposit_per_person'],
        'deposit_min_party_size' => (int)$settings['deposit_min_party_size'],
        'deposit_available' => $depositAvailable,
        'ai_chat_enabled' => (int)$settings['ai_chat_enabled'],
    ]);
}

if ($action === 'heatmap') {
    $from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
    $days = isset($_GET['days']) ? max(1, min(60, (int)$_GET['days'])) : 30;
    $partySize = isset($_GET['party_size']) ? max(1, (int)$_GET['party_size']) : 2;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) json_error('INVALID_DATE', '日付形式が不正です', 400);

    purge_expired_holds($pdo, $storeId);
    $heat = compute_daily_heatmap($pdo, $storeId, $from, $days, $partySize);
    json_response(['heatmap' => $heat]);
}

// デフォルト = 日次スロット
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$partySize = isset($_GET['party_size']) ? max(1, (int)$_GET['party_size']) : 2;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('INVALID_DATE', '日付形式が不正です', 400);
if ($partySize < (int)$settings['min_party_size']) json_error('INVALID_PARTY_SIZE', '人数が下限未満です', 400);
if ($partySize > (int)$settings['max_party_size']) json_error('INVALID_PARTY_SIZE', '人数が上限超過です', 400);

// 日付範囲チェック
$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime($today . ' +' . (int)$settings['max_advance_days'] . ' days'));
if ($date < $today) json_error('PAST_DATE', '過去日は予約できません', 400);
if ($date > $maxDate) json_error('TOO_FAR', '受付期間を超えています', 400);

purge_expired_holds($pdo, $storeId);
$slots = compute_slot_availability($pdo, $storeId, $date, $partySize);
json_response(['date' => $date, 'party_size' => $partySize, 'slots' => $slots]);
