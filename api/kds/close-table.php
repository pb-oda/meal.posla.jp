<?php
// @deprecated 2026-04-02 — process-payment.php に統一済み。将来削除予定。
/**
 * テーブル会計 API
 *
 * POST /api/kds/close-table.php
 * Body: { store_id, table_id, payment_method, received_amount }
 *
 * テーブルの全未会計注文を paid にし、セッショントークンをリセット。
 * 食べ放題プランがある場合はプラン料金×人数を請求額とする。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['POST']);
$user = require_auth();

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$tableId = $data['table_id'] ?? null;
$orderIds = $data['order_ids'] ?? [];
$paymentMethod = $data['payment_method'] ?? 'cash';
$receivedAmount = isset($data['received_amount']) ? (int)$data['received_amount'] : null;

if (!$storeId) json_error('VALIDATION', 'store_id は必須です', 400);
if (!$tableId && empty($orderIds)) json_error('VALIDATION', 'table_id または order_ids が必要です', 400);
require_store_access($storeId);

$pdo = get_db();

// 注文を取得: order_ids指定時はIDで、table_id指定時はテーブルで検索
$orders = [];
if (!empty($orderIds)) {
    // order_ids で直接指定（テイクアウト等 table_id が null の場合）
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, total_amount, table_id FROM orders
         WHERE store_id = ? AND id IN (' . $placeholders . ') AND status NOT IN ("paid", "cancelled")'
    );
    $stmt->execute(array_merge([$storeId], $orderIds));
    $orders = $stmt->fetchAll();
    // table_id が取れればセッション検索に使う
    if (!$tableId && !empty($orders) && $orders[0]['table_id']) {
        $tableId = $orders[0]['table_id'];
    }
} elseif ($tableId) {
    $stmt = $pdo->prepare(
        'SELECT id, total_amount FROM orders
         WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "cancelled")'
    );
    $stmt->execute([$storeId, $tableId]);
    $orders = $stmt->fetchAll();
}

// アクティブセッション確認（table_idがある場合のみ）
$sessionInfo = null;
$planInfo = null;
if ($tableId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT ts.id AS session_id, ts.guest_count, ts.plan_id,
                    tlp.price AS plan_price, tlp.name AS plan_name
             FROM table_sessions ts
             LEFT JOIN time_limit_plans tlp ON tlp.id = ts.plan_id
             WHERE ts.store_id = ? AND ts.table_id = ? AND ts.status NOT IN ("paid", "closed")'
        );
        $stmt->execute([$storeId, $tableId]);
        $sessionInfo = $stmt->fetch();
        if ($sessionInfo && $sessionInfo['plan_id']) {
            $planInfo = $sessionInfo;
        }
    } catch (PDOException $e) {
        // table_sessions/time_limit_plans 未作成時はスキップ
    }
}

// 注文もセッションもない場合はエラー
if (empty($orders) && !$sessionInfo) {
    json_error('NOT_FOUND', '未会計の注文またはアクティブなセッションがありません', 404);
}

$orderTotal = array_sum(array_column($orders, 'total_amount'));
$totalAmount = $orderTotal;

// プランがあればプラン料金×人数を請求額とする
if ($planInfo && $planInfo['plan_price']) {
    $totalAmount = (int)$planInfo['plan_price'] * max(1, (int)$planInfo['guest_count']);
}

$changeAmount = $receivedAmount !== null ? max(0, $receivedAmount - $totalAmount) : 0;

$pdo->beginTransaction();
try {
    // 未会計注文があればpaidに更新
    if (!empty($orders)) {
        $orderIds = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $pdo->prepare(
            'UPDATE orders SET status = ?, payment_method = ?, received_amount = ?, change_amount = ?,
                    paid_at = NOW(), updated_at = NOW()
             WHERE id IN (' . $placeholders . ')'
        )->execute(array_merge(['paid', $paymentMethod, $receivedAmount, $changeAmount], $orderIds));
    }

    // セッショントークンリセット（テーブルがある場合のみ）
    if ($tableId) {
        $newToken = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?')->execute([$newToken, $tableId]);

        // テーブルセッションも終了（存在すれば）
        try {
            $pdo->prepare(
                'UPDATE table_sessions SET status = "paid", closed_at = NOW()
                 WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
            )->execute([$storeId, $tableId]);
        } catch (PDOException $e) {
            // table_sessions 未作成時はスキップ
        }
    }

    // cash_log に売上記録（cashの場合、金額 > 0）
    if ($paymentMethod === 'cash' && $totalAmount > 0) {
        $logId = generate_uuid();
        $pdo->prepare(
            'INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$logId, $storeId, $user['user_id'], 'cash_sale', $totalAmount, 'テーブル会計']);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    json_error('DB_ERROR', '会計処理に失敗しました: ' . $e->getMessage(), 500);
}

json_response([
    'ok'           => true,
    'totalAmount'  => $totalAmount,
    'changeAmount' => $changeAmount,
    'orderCount'   => count($orders),
]);
