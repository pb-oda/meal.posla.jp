<?php
/**
 * 顧客注文ステータス ポーリング API（認証なし）
 *
 * GET /api/customer/order-status.php?store_id=xxx&table_id=xxx&session_token=xxx
 *
 * N-2: お客様画面で注文の調理状況をリアルタイム表示するためのエンドポイント
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['GET']);

// H-03: ステータス ポーリング DoS 防御 — 1IP あたり 60 回 / 5 分（5 秒ポーリング想定）
check_rate_limit('customer-order-status', 60, 300);

$storeId      = $_GET['store_id']      ?? null;
$tableId      = $_GET['table_id']      ?? null;
$sessionToken = $_GET['session_token'] ?? null;

if (!$storeId || !$tableId || !$sessionToken) {
    json_error('MISSING_FIELDS', 'store_id, table_id, session_token は必須です', 400);
}

$pdo = get_db();

// セッショントークン検証
$stmt = $pdo->prepare('SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ?');
$stmt->execute([$tableId, $storeId]);
$tokenRow = $stmt->fetch();
if ($tokenRow) {
    $dbToken   = $tokenRow['session_token'];
    $expiresAt = $tokenRow['session_token_expires_at'];
    if (!$dbToken || $sessionToken !== $dbToken) {
        json_error('INVALID_SESSION', 'セッションが無効です', 403);
    }
    if ($expiresAt && strtotime($expiresAt) < time()) {
        json_error('INVALID_SESSION', 'セッションが期限切れです', 403);
    }
}

// 該当セッションの注文を取得
$hasMemo = false;
try {
    $pdo->query('SELECT memo FROM orders LIMIT 0');
    $hasMemo = true;
} catch (PDOException $e) {}

$selectCols = 'id, status, created_at, total_amount';
if ($hasMemo) {
    $selectCols .= ', memo';
}

$stmt = $pdo->prepare(
    'SELECT ' . $selectCols . '
     FROM orders
     WHERE table_id = ? AND session_token = ? AND store_id = ?
       AND status != ?
       AND session_token IS NOT NULL
     ORDER BY created_at DESC'
);
$stmt->execute([$tableId, $sessionToken, $storeId, 'cancelled']);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orders)) {
    json_response(['orders' => [], 'rated_item_ids' => []]);
    return;
}

// order_items テーブル存在チェック
$hasOrderItems = false;
try {
    $pdo->query('SELECT 1 FROM order_items LIMIT 0');
    $hasOrderItems = true;
} catch (PDOException $e) {}

// order_items から品目を一括取得
$orderItemsMap = [];
if ($hasOrderItems) {
    $orderIdList = array_column($orders, 'id');
    $ph = implode(',', array_fill(0, count($orderIdList), '?'));
    $oiStmt = $pdo->prepare(
        'SELECT id AS item_id, order_id, menu_item_id, name, qty, status, ready_at, served_at
         FROM order_items
         WHERE order_id IN (' . $ph . ') AND store_id = ?
         ORDER BY created_at ASC'
    );
    $oiParams = $orderIdList;
    $oiParams[] = $storeId;
    $oiStmt->execute($oiParams);
    foreach ($oiStmt->fetchAll(PDO::FETCH_ASSOC) as $oi) {
        $orderItemsMap[$oi['order_id']][] = $oi;
    }
}

// items をアセンブル
foreach ($orders as &$o) {
    if (isset($orderItemsMap[$o['id']])) {
        $o['items'] = $orderItemsMap[$o['id']];
    } else {
        // order_items がない場合は orders.items JSON からフォールバック
        // ただし orders テーブルの items カラムは SELECT していないので再取得
        $fallbackStmt = $pdo->prepare('SELECT items FROM orders WHERE id = ?');
        $fallbackStmt->execute([$o['id']]);
        $itemsJson = $fallbackStmt->fetchColumn();
        $decoded = $itemsJson ? json_decode($itemsJson, true) : [];
        $o['items'] = array_map(function ($item) use ($o) {
            return [
                'item_id'      => null,
                'menu_item_id' => $item['id'] ?? null,
                'name'         => $item['name'] ?? '',
                'qty'          => (int)($item['qty'] ?? 1),
                'status'       => $o['status'],
                'ready_at'     => null,
                'served_at'    => null,
            ];
        }, $decoded);
    }
    if (!$hasMemo) {
        $o['memo'] = null;
    }
}
unset($o);

// 既評価の order_item_id を取得
$ratedItemIds = [];
try {
    $stmt = $pdo->prepare(
        'SELECT order_item_id FROM satisfaction_ratings
         WHERE store_id = ? AND session_token = ?'
    );
    $stmt->execute([$storeId, $sessionToken]);
    $ratedItemIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'order_item_id');
} catch (PDOException $e) {
    // satisfaction_ratings テーブル未作成
    error_log('[P1-12][api/customer/order-status.php:132] fetch_rated_items: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

json_response([
    'orders'         => $orders,
    'rated_item_ids' => $ratedItemIds,
]);
