<?php
/**
 * スタッフ呼び出し API（認証不要）
 *
 * POST /api/customer/call-staff.php
 *
 * Body: { store_id, table_id, table_code, reason }
 * 同じテーブルのpending状態の古いアラートは削除（重複防止）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

$method = require_method(['GET', 'POST']);

// ── GET: アラートステータス確認 ──
if ($method === 'GET') {
    $alertId = $_GET['alert_id'] ?? null;
    if (!$alertId) {
        json_error('MISSING_ALERT_ID', 'alert_id は必須です', 400);
    }

    $pdo = get_db();
    try {
        $stmt = $pdo->prepare(
            'SELECT status FROM call_alerts WHERE id = ?'
        );
        $stmt->execute([$alertId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        $row = null;
    }

    if (!$row) {
        json_response(['status' => 'not_found']);
    } else {
        json_response(['status' => $row['status']]);
    }
    exit;
}

// ── POST: 呼び出し送信 ──
$data = get_json_body();
$storeId   = $data['store_id']   ?? null;
$tableId   = $data['table_id']   ?? null;
$tableCode = $data['table_code'] ?? '';
$reason    = $data['reason']     ?? 'スタッフ呼び出し';

if (!$storeId || !$tableId) {
    json_error('MISSING_FIELDS', 'store_id, table_id は必須です', 400);
}

// reason のバリデーション（100文字以内）
if (mb_strlen($reason) > 100) {
    $reason = mb_substr($reason, 0, 100);
}

$pdo = get_db();

// テーブル存在確認
$stmt = $pdo->prepare(
    'SELECT id, table_code FROM tables WHERE id = ? AND store_id = ? AND is_active = 1'
);
$stmt->execute([$tableId, $storeId]);
$table = $stmt->fetch();

if (!$table) {
    json_error('TABLE_NOT_FOUND', 'テーブルが見つかりません', 404);
}

// table_code をDBから取得（クライアント送信値より信頼性が高い）
$tableCode = $table['table_code'];

// 同テーブルの古いpendingアラートを削除（重複防止）
$stmt = $pdo->prepare(
    'DELETE FROM call_alerts WHERE store_id = ? AND table_id = ? AND status = ?'
);
$stmt->execute([$storeId, $tableId, 'pending']);

// 新規アラート作成
$alertId = generate_uuid();
$stmt = $pdo->prepare(
    'INSERT INTO call_alerts (id, store_id, table_id, table_code, reason, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())'
);
$stmt->execute([$alertId, $storeId, $tableId, $tableCode, $reason, 'pending']);

json_response(['alert_id' => $alertId], 201);
