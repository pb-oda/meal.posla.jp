<?php
/**
 * ハンディ注文 API（スタッフ以上）
 *
 * POST /api/store/handy-order.php
 *
 * 店内飲食:
 *   { store_id, table_id, items, order_type: "handy" }
 *
 * テイクアウト:
 *   { store_id, items, order_type: "takeout", customer_name, customer_phone, pickup_at }
 *
 * スタッフがスマホ・タブレットから注文を入力する。
 * セッションから user_id を取得し staff_id として記録。
 * 顧客セルフ注文と同じ orders テーブルに保存。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/order-items.php';

require_method(['POST', 'PATCH']);
$user = require_auth();

$pdo = get_db();

// ===== PATCH: 注文修正 =====
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = get_json_body();
    $orderId = $data['order_id'] ?? null;
    $storeId = $data['store_id'] ?? null;
    if (!$orderId || !$storeId) json_error('VALIDATION', 'order_id と store_id は必須です', 400);

    require_store_access($storeId);

    // 既存注文を確認（paid/cancelled は修正不可）
    $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ? AND store_id = ?');
    $stmt->execute([$orderId, $storeId]);
    $order = $stmt->fetch();
    if (!$order) json_error('NOT_FOUND', '注文が見つかりません', 404);
    if (in_array($order['status'], ['paid', 'cancelled'])) {
        json_error('CONFLICT', '会計済み・キャンセル済みの注文は修正できません', 409);
    }

    $fields = [];
    $params = [];

    // 品目の差し替え
    if (isset($data['items']) && is_array($data['items'])) {
        $totalAmount = 0;
        foreach ($data['items'] as $item) {
            $totalAmount += (int)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
        }
        $fields[] = 'items = ?';
        $params[] = json_encode($data['items'], JSON_UNESCAPED_UNICODE);
        $fields[] = 'total_amount = ?';
        $params[] = $totalAmount;
    }

    // テイクアウト情報更新
    if (isset($data['customer_name'])) {
        $fields[] = 'customer_name = ?';
        $params[] = trim($data['customer_name']);
    }
    if (isset($data['customer_phone'])) {
        $fields[] = 'customer_phone = ?';
        $params[] = trim($data['customer_phone']);
    }
    if (isset($data['pickup_at'])) {
        $fields[] = 'pickup_at = ?';
        $params[] = $data['pickup_at'] ?: null;
    }

    if (empty($fields)) json_error('VALIDATION', '更新項目がありません', 400);

    $fields[] = 'updated_at = NOW()';
    $params[] = $orderId;

    try {
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    } catch (PDOException $e) {
        json_error('DB_ERROR', '注文更新に失敗しました: ' . $e->getMessage(), 500);
    }

    json_response(['ok' => true]);
}

// ===== POST: 新規注文作成 =====
$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$items = $data['items'] ?? [];
$orderType = $data['order_type'] ?? 'handy';

if (!$storeId || empty($items)) {
    json_error('VALIDATION', 'store_id と items は必須です', 400);
}

require_store_access($storeId);

// 注文種別バリデーション
if (!in_array($orderType, ['handy', 'takeout'])) {
    $orderType = 'handy';
}

// テーブルID（店内飲食時）
$tableId = null;
if ($orderType === 'handy') {
    $tableId = $data['table_id'] ?? null;
    if (!$tableId) json_error('VALIDATION', '店内注文にはテーブルIDが必要です', 400);

    // テーブル確認
    $stmt = $pdo->prepare('SELECT id FROM tables WHERE id = ? AND store_id = ? AND is_active = 1');
    $stmt->execute([$tableId, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'テーブルが見つかりません', 404);
}

// テイクアウト情報
$customerName = trim($data['customer_name'] ?? '');
$customerPhone = trim($data['customer_phone'] ?? '');
$pickupAt = $data['pickup_at'] ?? null;
$memo = isset($data['memo']) ? mb_substr(trim($data['memo']), 0, 200) : null;
if ($memo === '') $memo = null;

if ($orderType === 'takeout' && !$pickupAt) {
    json_error('VALIDATION', 'テイクアウト注文には受取時間が必要です', 400);
}

// ── S-6: 品切れ二重チェック ──
$itemIds = [];
foreach ($items as $item) {
    if (isset($item['id'])) $itemIds[] = $item['id'];
}

if (!empty($itemIds)) {
    $soldOutNames = [];
    $ph = implode(',', array_fill(0, count($itemIds), '?'));

    // テンプレートメニューの品切れチェック
    try {
        $stmt = $pdo->prepare(
            "SELECT mt.id, mt.name, COALESCE(smo.is_sold_out, mt.is_sold_out, 0) AS is_sold_out
             FROM menu_templates mt
             LEFT JOIN store_menu_overrides smo ON smo.template_id = mt.id AND smo.store_id = ?
             WHERE mt.id IN ($ph)"
        );
        $stmt->execute(array_merge([$storeId], $itemIds));
        while ($row = $stmt->fetch()) {
            if ($row['is_sold_out']) {
                $soldOutNames[] = $row['name'];
            }
        }
    } catch (PDOException $e) {
        // テーブル未作成時はスキップ
    }

    // 店舗限定メニューの品切れチェック
    try {
        $stmt = $pdo->prepare(
            "SELECT id, name, is_sold_out FROM store_local_items WHERE id IN ($ph) AND store_id = ?"
        );
        $stmt->execute(array_merge($itemIds, [$storeId]));
        while ($row = $stmt->fetch()) {
            if ($row['is_sold_out']) {
                $soldOutNames[] = $row['name'];
            }
        }
    } catch (PDOException $e) {
        // テーブル未作成時はスキップ
    }

    if (!empty($soldOutNames)) {
        json_error('SOLD_OUT', '品切れの品目が含まれています: ' . implode(', ', $soldOutNames), 409);
    }
}

// 金額計算
$totalAmount = 0;
foreach ($items as $item) {
    $totalAmount += (int)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
}

// 注文作成
$orderId = generate_uuid();
$itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO orders (
            id, store_id, table_id, items, total_amount, status,
            order_type, staff_id, customer_name, customer_phone, pickup_at,
            memo, created_at, updated_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $orderId,
        $storeId,
        $tableId,
        $itemsJson,
        $totalAmount,
        'pending',
        $orderType,
        $user['user_id'],
        $customerName ?: null,
        $customerPhone ?: null,
        $pickupAt,
        $memo ?: null
    ]);
} catch (PDOException $e) {
    json_error('DB_ERROR', '注文作成に失敗しました: ' . $e->getMessage(), 500);
}

// order_items テーブルにも書き込み（品目単位ステータス管理）
insert_order_items($pdo, $orderId, $storeId, $items);

json_response([
    'order_id'   => $orderId,
    'order_type' => $orderType,
    'total'      => $totalAmount,
], 201);
