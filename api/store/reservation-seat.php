<?php
/**
 * L-9 予約管理 — 着席処理
 *
 * POST { reservation_id, store_id [, table_ids] }
 *  - table_sessions を起票し reservation を seated に更新
 *  - tables.session_token を新規発行 (4h TTL)
 *  - レスポンス: { reservation_id, table_session_id, session_token, table_id, qr_url }
 *
 * 既存スキーマに準拠:
 *   table_sessions: id, store_id, table_id, status('seated'), guest_count, started_at, ...
 *   tables.session_token / session_token_expires_at  (← 客側 menu.html はここを参照)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/reservation-deposit.php';
require_once __DIR__ . '/../config/app.php';

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
if (in_array($r['status'], ['cancelled', 'no_show', 'completed'], true)) {
    json_error('INVALID_STATUS', 'この予約は着席できません: ' . $r['status'], 400);
}
if ($r['status'] === 'seated' && $r['table_session_id']) {
    json_response(['reservation_id' => $resId, 'table_session_id' => $r['table_session_id'], 'note' => 'already_seated']);
}

// テーブル割当
$tableIds = [];
if (!empty($body['table_ids']) && is_array($body['table_ids'])) {
    $tableIds = array_values($body['table_ids']);
} elseif ($r['assigned_table_ids']) {
    $arr = json_decode($r['assigned_table_ids'], true);
    if (is_array($arr)) $tableIds = $arr;
}
if (empty($tableIds)) {
    json_error('NO_TABLE_ASSIGNED', '着席先テーブルが指定されていません', 400);
}
$primaryTableId = $tableIds[0];

$tStmt = $pdo->prepare('SELECT id FROM tables WHERE id = ? AND store_id = ? AND is_active = 1');
$tStmt->execute([$primaryTableId, $storeId]);
if (!$tStmt->fetch()) json_error('INVALID_TABLE', 'テーブルが店舗に属していません', 400);

// 既存アクティブセッション (paid/closed 以外) があれば衝突
$existsStmt = $pdo->prepare("SELECT id FROM table_sessions WHERE table_id = ? AND store_id = ? AND status NOT IN ('paid','closed') LIMIT 1");
$existsStmt->execute([$primaryTableId, $storeId]);
if ($existsStmt->fetch()) {
    json_error('TABLE_OCCUPIED', 'テーブルは既に使用中です', 409);
}

$sessionId = bin2hex(random_bytes(18));
$sessionToken = bin2hex(random_bytes(32));
$hasWaitlistCallStatus = false;
try {
    $colStmt = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'reservations'
           AND COLUMN_NAME = 'waitlist_call_status'"
    );
    $hasWaitlistCallStatus = ((int)$colStmt->fetchColumn() > 0);
} catch (Exception $e) {
    $hasWaitlistCallStatus = false;
}
$pdo->beginTransaction();
try {
    // table_sessions 起票 (既存スキーマに合わせて guest_count + status='seated')
    $pdo->prepare(
        "INSERT INTO table_sessions (id, store_id, table_id, status, guest_count, started_at, reservation_id)
         VALUES (?, ?, ?, 'seated', ?, NOW(), ?)"
    )->execute([$sessionId, $storeId, $primaryTableId, (int)$r['party_size'], $resId]);

    // tables.session_token を発行 (4 時間 TTL) — 客側 menu.html はここを参照
    $pdo->prepare("UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ? AND store_id = ?")
        ->execute([$sessionToken, $primaryTableId, $storeId]);

    // reservation 更新
    if ($hasWaitlistCallStatus) {
        $pdo->prepare(
            "UPDATE reservations
             SET status = 'seated',
                 seated_at = NOW(),
                 table_session_id = ?,
                 assigned_table_ids = ?,
                 waitlist_call_status = 'seated',
                 updated_at = NOW()
             WHERE id = ? AND store_id = ?"
        )->execute([$sessionId, json_encode($tableIds, JSON_UNESCAPED_UNICODE), $resId, $storeId]);
    } else {
        $pdo->prepare(
            "UPDATE reservations SET status = 'seated', seated_at = NOW(), table_session_id = ?, assigned_table_ids = ?, updated_at = NOW() WHERE id = ? AND store_id = ?"
        )->execute([$sessionId, json_encode($tableIds, JSON_UNESCAPED_UNICODE), $resId, $storeId]);
    }

    // 顧客 visit_count
    if ($r['customer_phone']) {
        $pdo->prepare('UPDATE reservation_customers SET visit_count = visit_count + 1, last_visit_at = NOW() WHERE store_id = ? AND customer_phone = ?')
            ->execute([$storeId, $r['customer_phone']]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す（既存）
    error_log('[L-9][reservation-seat] ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('SEAT_FAILED', '着席処理に失敗しました', 500);
}

// デポジットがあれば release
if ($r['deposit_payment_intent_id']) {
    $sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores WHERE id = ?');
    $sStmt->execute([$storeId]);
    $store = $sStmt->fetch();
    $rel = reservation_deposit_release($pdo, $r, $store);
    if ($rel['success']) {
        $pdo->prepare("UPDATE reservations SET deposit_status = 'released' WHERE id = ?")->execute([$resId]);
    }
}

$qrUrl = app_url('/customer/menu.html') . '?store_id=' . urlencode($storeId) . '&table_id=' . urlencode($primaryTableId);

json_response([
    'ok' => true,
    'reservation_id' => $resId,
    'table_session_id' => $sessionId,
    'session_token' => $sessionToken,
    'table_id' => $primaryTableId,
    'qr_url' => $qrUrl,
]);
