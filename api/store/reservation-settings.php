<?php
/**
 * L-9 予約管理 — 店舗別設定
 * GET   ?store_id=xxx       … 設定取得 (なければデフォルト)
 * PATCH { store_id, ... }    … 部分更新 (UPSERT)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/reservation-availability.php';
require_once __DIR__ . '/../lib/reservation-deposit.php';

$method = require_method(['GET', 'PATCH']);
$user = require_role('staff');
$pdo = get_db();

if ($method === 'GET') {
    $storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
    if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);
    require_store_access($storeId);

    $settings = get_reservation_settings($pdo, $storeId);
    $sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores WHERE id = ?');
    $sStmt->execute([$storeId]);
    $store = $sStmt->fetch();
    $depositAvailable = $store ? reservation_deposit_is_available($pdo, $storeId, $store['tenant_id'], $settings) : false;

    json_response([
        'settings' => $settings,
        'deposit_available' => $depositAvailable,
    ]);
}

if ($method === 'PATCH') {
    if ($user['role'] === 'staff') json_error('FORBIDDEN', '設定変更は manager 以上のみ可能です', 403);
    $body = get_json_body();
    $storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
    if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);
    require_store_access($storeId);

    $allowed = [
        'online_enabled' => 'int01',
        'lead_time_hours' => 'intpos',
        'max_advance_days' => 'intpos',
        'default_duration_min' => 'intpos',
        'slot_interval_min' => 'intpos',
        'max_party_size' => 'intpos',
        'min_party_size' => 'intpos',
        'open_time' => 'time',
        'close_time' => 'time',
        'last_order_offset_min' => 'intnonneg',
        'weekly_closed_days' => 'string',
        'require_phone' => 'int01',
        'require_email' => 'int01',
        'buffer_before_min' => 'intnonneg',
        'buffer_after_min' => 'intnonneg',
        'notes_to_customer' => 'string',
        'cancel_deadline_hours' => 'intnonneg',
        'deposit_enabled' => 'int01',
        'deposit_per_person' => 'intnonneg',
        'deposit_min_party_size' => 'intpos',
        'reminder_24h_enabled' => 'int01',
        'reminder_2h_enabled' => 'int01',
        'ai_chat_enabled' => 'int01',
        'notification_email' => 'string',
    ];

    $sets = [];
    $params = [];
    foreach ($allowed as $key => $type) {
        if (!array_key_exists($key, $body)) continue;
        $v = $body[$key];
        if ($type === 'int01') $v = ((int)$v === 1) ? 1 : 0;
        elseif ($type === 'intpos') { $v = (int)$v; if ($v < 1) json_error('INVALID_VALUE', $key . ' は 1 以上', 400); }
        elseif ($type === 'intnonneg') { $v = (int)$v; if ($v < 0) json_error('INVALID_VALUE', $key . ' は 0 以上', 400); }
        elseif ($type === 'time') {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)$v)) json_error('INVALID_TIME', $key . ' は HH:MM', 400);
            if (strlen($v) === 5) $v .= ':00';
        }
        elseif ($type === 'string') { $v = $v === null ? null : (string)$v; if ($key === 'notification_email' && $v !== null && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) json_error('INVALID_EMAIL', '通知メールが不正です', 400); }
        $sets[] = $key;
        $params[] = $v;
    }
    if (empty($sets)) json_error('NO_FIELDS', '更新項目がありません', 400);

    // UPSERT
    $existsStmt = $pdo->prepare('SELECT 1 FROM reservation_settings WHERE store_id = ?');
    $existsStmt->execute([$storeId]);
    if ($existsStmt->fetch()) {
        $sql = 'UPDATE reservation_settings SET ' . implode(', ', array_map(function($k) { return $k . ' = ?'; }, $sets)) . ', updated_at = NOW() WHERE store_id = ?';
        $params[] = $storeId;
        $pdo->prepare($sql)->execute($params);
    } else {
        $cols = array_merge(['store_id'], $sets);
        $place = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO reservation_settings (' . implode(',', $cols) . ', created_at, updated_at) VALUES (' . $place . ', NOW(), NOW())';
        array_unshift($params, $storeId);
        $pdo->prepare($sql)->execute($params);
    }

    $settings = get_reservation_settings($pdo, $storeId);
    json_response(['settings' => $settings]);
}
