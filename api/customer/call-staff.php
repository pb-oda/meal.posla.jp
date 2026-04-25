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
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/push.php';

$method = require_method(['GET', 'POST']);

// S1: レートリミット — 1IP あたり 10回/10分
if ($method === 'POST') {
    check_rate_limit('call-staff', 10, 600);
}

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

// PWA Phase 2b: ハンディ・KDS・レジへ Web Push 通知 (送信失敗は無視: fail-open)
//   顧客→スタッフ呼び出しは業務上の最重要通知。push_send_to_store で当該店舗の
//   全 enabled 購読 (handy / kds / cashier / pos-register) に届ける。
try {
    push_send_to_store($pdo, $storeId, 'call_staff', [
        'title' => 'お客様呼び出し: テーブル ' . $tableCode,
        'body'  => $reason,
        'url'   => '/handy/index.html',
        'tag'   => 'call_staff_' . $tableCode,
    ]);
} catch (\Throwable $e) {
    // 業務処理を止めない (顧客側のレスポンスを必ず返す)
}

json_response(['alert_id' => $alertId], 201);
