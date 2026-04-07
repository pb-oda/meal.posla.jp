<?php
/**
 * レジ開け/締め API
 *
 * GET  /api/kds/cash-log.php?store_id=xxx    — 当日のログ
 * POST /api/kds/cash-log.php                 — エントリ追加
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';

require_method(['GET', 'POST']);
$user = require_auth();
$pdo = get_db();

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $businessDay = get_business_day($pdo, $storeId);

    $stmt = $pdo->prepare(
        'SELECT cl.*, u.display_name AS user_name
         FROM cash_log cl
         LEFT JOIN users u ON u.id = cl.user_id
         WHERE cl.store_id = ? AND cl.created_at >= ? AND cl.created_at < ?
         ORDER BY cl.created_at ASC'
    );
    $stmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);

    json_response([
        'entries'     => $stmt->fetchAll(),
        'businessDay' => $businessDay['date'],
    ]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $type = $data['type'] ?? null;
    $amount = isset($data['amount']) ? (int)$data['amount'] : 0;
    $note = trim($data['note'] ?? '');

    if (!$storeId || !$type) json_error('MISSING_FIELDS', 'store_id と type は必須です', 400);
    require_store_access($storeId);

    $validTypes = ['open', 'close', 'cash_in', 'cash_out', 'cash_sale'];
    if (!in_array($type, $validTypes)) json_error('INVALID_TYPE', '無効な種別です', 400);

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$id, $storeId, $user['user_id'], $type, $amount, $note]);

    json_response(['ok' => true, 'id' => $id], 201);
}
