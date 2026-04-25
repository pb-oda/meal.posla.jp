<?php
/**
 * 顧客注文履歴取得 API（認証なし）
 *
 * GET /api/customer/order-history.php?store_id=xxx&table_id=xxx&session_token=xxx
 *
 * 同一テーブルセッション内の過去注文を返す。
 * session_token がテーブルに紐づく値と一致する場合のみ応答。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['GET']);

// H-03: session_token 使い回し濫用防御 — 1IP あたり 30 回 / 5 分
check_rate_limit('customer-order-history', 30, 300);

$storeId      = $_GET['store_id']      ?? null;
$tableId      = $_GET['table_id']      ?? null;
$sessionToken = $_GET['session_token'] ?? null;

if (!$storeId || !$tableId || !$sessionToken) {
    json_error('MISSING_FIELDS', 'store_id, table_id, session_token は必須です', 400);
}

$pdo = get_db();

// セッショントークン検証（C-2 と同じパターン）
$stmt = $pdo->prepare('SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ?');
$stmt->execute([$tableId, $storeId]);
$tokenRow = $stmt->fetch();

if (!$tokenRow || !$tokenRow['session_token'] || !hash_equals($tokenRow['session_token'], (string)$sessionToken)) {
    json_error('INVALID_SESSION', 'このQRコードは無効です。スタッフにお声がけください。', 403);
}
if ($tokenRow['session_token_expires_at'] && strtotime($tokenRow['session_token_expires_at']) < time()) {
    json_error('INVALID_SESSION', 'このQRコードは無効です。スタッフにお声がけください。', 403);
}

// SELF-P1-4: guest_alias カラム存在チェック (migration-self-p1-4-guest-alias.sql)
$hasGuestAliasCol = false;
try {
    $pdo->query('SELECT guest_alias FROM orders LIMIT 0');
    $hasGuestAliasCol = true;
} catch (PDOException $e) {}

$selectCols = 'id, items, total_amount, created_at, status';
if ($hasGuestAliasCol) {
    $selectCols .= ', guest_alias';
}

// 同一セッションの注文を取得
$stmt = $pdo->prepare(
    "SELECT " . $selectCols . "
     FROM orders
     WHERE table_id = ? AND session_token = ? AND store_id = ?
       AND status != 'cancelled'
       AND session_token IS NOT NULL
     ORDER BY created_at DESC"
);
$stmt->execute([$tableId, $sessionToken, $storeId]);
$rows = $stmt->fetchAll();

$orders = [];
foreach ($rows as $row) {
    $items = json_decode($row['items'], true) ?: [];
    $orders[] = [
        'id'           => $row['id'],
        'items'        => $items,
        'total_amount' => (int) $row['total_amount'],
        'created_at'   => $row['created_at'],
        'status'       => $row['status'],
        // SELF-P1-4: guest_alias (任意ゲスト名) — migration 未適用時は null
        'guest_alias'  => $hasGuestAliasCol ? ($row['guest_alias'] ?? null) : null,
    ];
}

json_response(['orders' => $orders]);
