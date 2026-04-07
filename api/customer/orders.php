<?php
/**
 * 顧客注文送信 API（認証なし）
 *
 * POST /api/customer/orders.php
 *
 * Body: { store_id, table_id, items: [{id, name, price, qty}], idempotency_key, session_token }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/order-items.php';

require_method(['POST']);

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$tableId = $data['table_id'] ?? null;
$items = $data['items'] ?? [];
$removedItems = $data['removed_items'] ?? [];
$idempotencyKey = $data['idempotency_key'] ?? null;
$sessionToken = $data['session_token'] ?? null;
$memo = isset($data['memo']) ? mb_substr(trim($data['memo']), 0, 200) : null;
if ($memo === '') $memo = null;

if (!$storeId || !$tableId || empty($items)) {
    json_error('MISSING_FIELDS', 'store_id, table_id, items は必須です', 400);
}

$pdo = get_db();

// セッショントークン検証（QRコード注文テロ対策）
$stmt = $pdo->prepare('SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ?');
$stmt->execute([$tableId, $storeId]);
$tokenRow = $stmt->fetch();
if ($tokenRow) {
    $dbToken = $tokenRow['session_token'];
    $expiresAt = $tokenRow['session_token_expires_at'];
    if (!$dbToken || !$sessionToken || $sessionToken !== $dbToken) {
        json_error('INVALID_SESSION', 'このQRコードは無効です。スタッフにお声がけください。', 403);
    }
    if ($expiresAt && strtotime($expiresAt) < time()) {
        json_error('INVALID_SESSION', 'このQRコードは無効です。スタッフにお声がけください。', 403);
    }
}

// 冪等キーチェック
if ($idempotencyKey) {
    $stmt = $pdo->prepare('SELECT id FROM orders WHERE idempotency_key = ?');
    $stmt->execute([$idempotencyKey]);
    if ($existing = $stmt->fetch()) {
        json_response(['ok' => true, 'order_id' => $existing['id'], 'duplicate' => true]);
        return;
    }
}

// テーブル確認
$stmt = $pdo->prepare('SELECT id, table_code FROM tables WHERE id = ? AND store_id = ? AND is_active = 1');
$stmt->execute([$tableId, $storeId]);
$table = $stmt->fetch();
if (!$table) json_error('TABLE_NOT_FOUND', 'テーブルが見つかりません', 404);

// プランセッション中か判定
$isPlanSession = false;
try {
    $stmt = $pdo->prepare(
        "SELECT ts.plan_id FROM table_sessions ts
         WHERE ts.table_id = ? AND ts.store_id = ?
           AND ts.status IN ('seated','eating')
           AND ts.plan_id IS NOT NULL
         ORDER BY ts.started_at DESC LIMIT 1"
    );
    $stmt->execute([$tableId, $storeId]);
    if ($stmt->fetchColumn()) {
        $isPlanSession = true;
    }
} catch (Exception $e) {
    // table_sessions 未作成時はスキップ
}

// 店舗設定チェック
$stmt = $pdo->prepare('SELECT max_items_per_order, max_amount_per_order FROM store_settings WHERE store_id = ?');
$stmt->execute([$storeId]);
$settings = $stmt->fetch() ?: [];
$maxItems = (int)($settings['max_items_per_order'] ?? 10);
$maxAmount = (int)($settings['max_amount_per_order'] ?? 30000);

// 品数・金額チェック
$totalQty = 0;
$totalAmount = 0;
foreach ($items as $item) {
    $totalQty += (int)($item['qty'] ?? 1);
    $totalAmount += (int)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
}

// maxItems はプランセッション中も維持（いたずら防止）
if ($totalQty > $maxItems) {
    json_error('MAX_ITEMS_EXCEEDED', '1回の注文は' . $maxItems . '品までです。店員をお呼びください。', 400);
}
// maxAmount はプランセッション中はスキップ（全品¥0のため）
if (!$isPlanSession && $totalAmount > $maxAmount) {
    json_error('MAX_AMOUNT_EXCEEDED', '金額上限を超えています。店員をお呼びください。', 400);
}

// O-3: プランベースのラストオーダーチェック
try {
    $stmt = $pdo->prepare(
        "SELECT ts.last_order_at FROM table_sessions ts
         WHERE ts.table_id = ? AND ts.store_id = ?
           AND ts.status IN ('seated','eating')
           AND ts.last_order_at IS NOT NULL
         ORDER BY ts.started_at DESC LIMIT 1"
    );
    $stmt->execute([$tableId, $storeId]);
    $loRow = $stmt->fetch();
    if ($loRow && $loRow['last_order_at'] && strtotime($loRow['last_order_at']) < time()) {
        json_error('LAST_ORDER_PASSED', 'ラストオーダー時刻を過ぎています', 409);
    }
} catch (PDOException $e) {
    // table_sessions 未作成時はスキップ
}

// O-3: 店舗全体のラストオーダーチェック
try {
    $loStmt = $pdo->prepare('SELECT last_order_time, last_order_active FROM store_settings WHERE store_id = ?');
    $loStmt->execute([$storeId]);
    $loSettings = $loStmt->fetch();
    if ($loSettings) {
        if ((int)($loSettings['last_order_active'] ?? 0) === 1) {
            json_error('LAST_ORDER_PASSED', '現在ラストオーダーのため注文を受け付けておりません', 409);
        }
        $loTime = $loSettings['last_order_time'] ?? null;
        if ($loTime && date('H:i:s') >= $loTime) {
            json_error('LAST_ORDER_PASSED', '現在ラストオーダーのため注文を受け付けておりません', 409);
        }
    }
} catch (PDOException $e) {
    // カラム未存在時はスキップ（グレースフルデグラデーション）
}

// 品切れチェック（レースコンディション防止）
require_once __DIR__ . '/../lib/menu-resolver.php';
try {
    $resolvedMenu = resolve_store_menu($pdo, $storeId);
    $soldOutMap = [];
    foreach ($resolvedMenu as $cat) {
        foreach ($cat['items'] as $mi) {
            if (!empty($mi['soldOut'])) {
                $soldOutMap[$mi['menuItemId']] = $mi['name'];
            }
        }
    }
    $blockedNames = [];
    foreach ($items as $item) {
        $itemId = $item['id'] ?? '';
        if (isset($soldOutMap[$itemId])) {
            $blockedNames[] = $soldOutMap[$itemId];
        }
    }
    if (!empty($blockedNames)) {
        json_error('SOLD_OUT', implode('、', $blockedNames) . 'は品切れです。カートから削除してください。', 409);
    }
} catch (Exception $e) {
    // menu-resolver 未対応環境でもブロックしない
}

// 注文作成
$orderId = generate_uuid();
$itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
$removedJson = !empty($removedItems) ? json_encode($removedItems, JSON_UNESCAPED_UNICODE) : null;

try {
    $stmt = $pdo->prepare(
        'INSERT INTO orders (id, store_id, table_id, items, removed_items, total_amount, status, order_type, idempotency_key, session_token, memo, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $orderId, $storeId, $tableId,
        $itemsJson, $removedJson, $totalAmount,
        'pending', 'dine_in',
        $idempotencyKey, $sessionToken, $memo ?: null
    ]);
} catch (PDOException $e) {
    // removed_items カラム未存在（migration-a4未適用）の場合フォールバック
    if (strpos($e->getMessage(), 'removed_items') !== false) {
        $stmt = $pdo->prepare(
            'INSERT INTO orders (id, store_id, table_id, items, total_amount, status, order_type, idempotency_key, session_token, memo, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $orderId, $storeId, $tableId,
            $itemsJson, $totalAmount,
            'pending', 'dine_in',
            $idempotencyKey, $sessionToken, $memo ?: null
        ]);
    } else {
        throw $e;
    }
}

// order_items テーブルにも書き込み（品目単位ステータス管理）
insert_order_items($pdo, $orderId, $storeId, $items);

// L-15: スマレジ同期（ベストエフォート）
try {
    require_once __DIR__ . '/../smaregi/sync-order.php';
    sync_order_to_smaregi($pdo, $storeId, $orderId);
} catch (Exception $e) {
    error_log('[L-15] smaregi sync exception: ' . $e->getMessage());
}

json_response(['ok' => true, 'order_id' => $orderId], 201);
