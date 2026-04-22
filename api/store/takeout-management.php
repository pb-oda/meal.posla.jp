<?php
/**
 * テイクアウト注文管理 API（店舗向け・認証必須）
 *
 * GET   ?store_id=xxx&date=YYYY-MM-DD&status=xxx — テイクアウト注文一覧
 * PATCH                                          — ステータス更新
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
// L-17 Phase 3B-1: status='ready' 時に LINE push を並行。ヘルパ未同梱でも落ちない
if (file_exists(__DIR__ . '/../lib/line-messaging.php')) {
    require_once __DIR__ . '/../lib/line-messaging.php';
}
if (file_exists(__DIR__ . '/../lib/takeout-line-link.php')) {
    require_once __DIR__ . '/../lib/takeout-line-link.php';
}

require_method(['GET', 'PATCH']);
$user = require_auth();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $date = $_GET['date'] ?? date('Y-m-d');
    $statusFilter = $_GET['status'] ?? null;

    $sql = "SELECT o.id AS order_id, o.customer_name, o.customer_phone, o.pickup_at,
                   o.status, o.total_amount, o.items, o.memo, o.created_at
            FROM orders o
            WHERE o.store_id = ? AND o.order_type = 'takeout'
            AND DATE(o.created_at) = ?";
    $params = [$storeId, $date];

    if ($statusFilter && $statusFilter !== 'all') {
        $sql .= " AND o.status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY o.pickup_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    // item_summary を生成
    $result = [];
    foreach ($orders as $o) {
        $items = json_decode($o['items'], true) ?: [];
        $summaryParts = [];
        foreach ($items as $item) {
            $summaryParts[] = ($item['name'] ?? '?') . ' x' . ($item['qty'] ?? 1);
        }

        $result[] = [
            'order_id' => $o['order_id'],
            'customer_name' => $o['customer_name'],
            'customer_phone' => $o['customer_phone'],
            'pickup_at' => $o['pickup_at'],
            'status' => $o['status'],
            'total_amount' => (int)$o['total_amount'],
            'item_summary' => implode(', ', $summaryParts),
            'memo' => $o['memo'],
            'created_at' => $o['created_at'],
        ];
    }

    json_response(['orders' => $result]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $orderId = $data['order_id'] ?? null;
    $newStatus = $data['status'] ?? null;

    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    if (!$orderId) json_error('MISSING_ORDER', 'order_idが必要です', 400);
    if (!$newStatus) json_error('MISSING_STATUS', 'statusが必要です', 400);
    require_store_access($storeId);

    // 注文取得
    $stmt = $pdo->prepare(
        "SELECT id, status FROM orders WHERE id = ? AND store_id = ? AND order_type = 'takeout'"
    );
    $stmt->execute([$orderId, $storeId]);
    $order = $stmt->fetch();
    if (!$order) json_error('NOT_FOUND', '注文が見つかりません', 404);

    // ステータス遷移バリデーション
    $allowed = [
        'pending' => ['preparing'],
        'pending_payment' => ['pending', 'cancelled'],
        'preparing' => ['ready'],
        'ready' => ['served'],
    ];

    $current = $order['status'];
    if (!isset($allowed[$current]) || !in_array($newStatus, $allowed[$current], true)) {
        json_error('INVALID_TRANSITION', $current . ' から ' . $newStatus . ' への遷移はできません', 400);
    }

    $pdo->prepare('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$newStatus, $orderId]);

    $note = null;
    if ($newStatus === 'served') {
        $note = '店頭支払いの場合はレジで会計してください';
    }

    // L-17 Phase 3B-1: status='ready' 時に LINE push を並行実行。
    // 送信失敗でも PATCH 応答 / status 更新は成功のまま (controlled return)。
    // tenant_line_settings.is_enabled / notify_takeout_ready / 連携済み条件は
    // takeout_notify_ready_line() 内部でチェックし、未達なら silent skip。
    if ($newStatus === 'ready' && function_exists('takeout_notify_ready_line')) {
        try {
            takeout_notify_ready_line($pdo, $orderId, $storeId);
        } catch (Exception $e) {
            error_log('[L-17 3B-1 takeout_ready_line] order=' . $orderId . ': ' . $e->getMessage());
        }
    }

    json_response(['ok' => true, 'order_id' => $orderId, 'status' => $newStatus, 'note' => $note]);
}
