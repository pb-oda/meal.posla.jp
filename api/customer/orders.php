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
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/order-validator.php';

require_method(['POST']);

// S1: レートリミット — 1IP あたり 20回/10分
check_rate_limit('customer-order', 20, 600);

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$tableId = $data['table_id'] ?? null;
$items = $data['items'] ?? [];
$removedItems = $data['removed_items'] ?? [];
$idempotencyKey = $data['idempotency_key'] ?? null;
$sessionToken = $data['session_token'] ?? null;
$subSessionId = $data['sub_session_id'] ?? null;
$memo = isset($data['memo']) ? mb_substr(trim($data['memo']), 0, 200) : null;
if ($memo === '') $memo = null;
// SELF-P1-4: 任意ゲスト名。表示整理専用、payment / split には使わない
$guestAlias = isset($data['guest_alias']) ? mb_substr(trim((string)$data['guest_alias']), 0, 32) : null;
if ($guestAlias === '') $guestAlias = null;

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

try {
    $activeStmt = $pdo->prepare(
        "SELECT id FROM table_sessions
         WHERE table_id = ? AND store_id = ?
           AND status IN ('seated', 'eating')
         LIMIT 1"
    );
    $activeStmt->execute([$tableId, $storeId]);
    if (!$activeStmt->fetch()) {
        json_error('SESSION_CLOSED', 'このQRコードは現在注文できません。スタッフにお声がけください。', 403);
    }
} catch (PDOException $e) {
    // table_sessions 未作成時は従来動作
}

// S1: per-session 注文上限 — 同一セッション内で 50件 or ¥200,000 超えたら拒否
$sessLimitStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total FROM orders WHERE table_id = ? AND session_token = ? AND status != 'cancelled'"
);
$sessLimitStmt->execute([$tableId, $sessionToken]);
$sessLimit = $sessLimitStmt->fetch();
if ($sessLimit && ($sessLimit['cnt'] >= 50 || $sessLimit['total'] >= 200000)) {
    json_error('SESSION_ORDER_LIMIT', 'このセッションの注文上限に達しました。スタッフにお声がけください。', 429);
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
$activePlanId = null;
try {
    $stmt = $pdo->prepare(
        "SELECT ts.plan_id FROM table_sessions ts
         WHERE ts.table_id = ? AND ts.store_id = ?
           AND ts.status IN ('seated','eating')
           AND ts.plan_id IS NOT NULL
         ORDER BY ts.started_at DESC LIMIT 1"
    );
    $stmt->execute([$tableId, $storeId]);
    $planRow = $stmt->fetchColumn();
    if ($planRow) {
        $isPlanSession = true;
        $activePlanId = $planRow;
    }
} catch (Exception $e) {
    // table_sessions 未作成時はスキップ
    error_log('[P1-12][customer/orders.php:77] check_plan_session: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// 店舗設定チェック
$stmt = $pdo->prepare('SELECT max_items_per_order, max_amount_per_order FROM store_settings WHERE store_id = ?');
$stmt->execute([$storeId]);
$settings = $stmt->fetch() ?: [];
$maxItems = (int)($settings['max_items_per_order'] ?? 10);
$maxAmount = (int)($settings['max_amount_per_order'] ?? 30000);

// P0 #1+#2: サーバー側で価格・商品存在・数量を強制再検証
// (クライアントから送られた items[].price は無視、正規価格で上書き)
$validated = validate_and_recompute_items($pdo, $storeId, $items, $isPlanSession, $activePlanId);
$items = $validated['items'];
$totalAmount = $validated['total_amount'];

$totalQty = 0;
foreach ($items as $item) {
    $totalQty += (int)$item['qty'];
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
    error_log('[P1-12][customer/orders.php:119] check_last_order_session: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
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
    error_log('[P1-12][customer/orders.php:137] check_last_order_store: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// 品切れ・改ざんチェックは validate_and_recompute_items() で実施済み (P0 #1+#2)

// 注文作成
$orderId = generate_uuid();
$itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
$removedJson = !empty($removedItems) ? json_encode($removedItems, JSON_UNESCAPED_UNICODE) : null;

try {
    // F-QR1: sub_session_id カラム存在チェック
    $hasSubSessionCol = false;
    try {
        $pdo->query('SELECT sub_session_id FROM orders LIMIT 0');
        $hasSubSessionCol = true;
    } catch (PDOException $e) {}

    // SELF-P1-4: guest_alias カラム存在チェック (migration-self-p1-4-guest-alias.sql)
    $hasGuestAliasCol = false;
    try {
        $pdo->query('SELECT guest_alias FROM orders LIMIT 0');
        $hasGuestAliasCol = true;
    } catch (PDOException $e) {}

    if ($hasSubSessionCol && $hasGuestAliasCol) {
        $stmt = $pdo->prepare(
            'INSERT INTO orders (id, store_id, table_id, items, removed_items, total_amount, status, order_type, idempotency_key, session_token, sub_session_id, memo, guest_alias, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $orderId, $storeId, $tableId,
            $itemsJson, $removedJson, $totalAmount,
            'pending', 'dine_in',
            $idempotencyKey, $sessionToken, $subSessionId, $memo ?: null, $guestAlias
        ]);
    } elseif ($hasSubSessionCol) {
        $stmt = $pdo->prepare(
            'INSERT INTO orders (id, store_id, table_id, items, removed_items, total_amount, status, order_type, idempotency_key, session_token, sub_session_id, memo, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $orderId, $storeId, $tableId,
            $itemsJson, $removedJson, $totalAmount,
            'pending', 'dine_in',
            $idempotencyKey, $sessionToken, $subSessionId, $memo ?: null
        ]);
    } elseif ($hasGuestAliasCol) {
        $stmt = $pdo->prepare(
            'INSERT INTO orders (id, store_id, table_id, items, removed_items, total_amount, status, order_type, idempotency_key, session_token, memo, guest_alias, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $orderId, $storeId, $tableId,
            $itemsJson, $removedJson, $totalAmount,
            'pending', 'dine_in',
            $idempotencyKey, $sessionToken, $memo ?: null, $guestAlias
        ]);
    } else {
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
    }
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

try {
    $pdo->prepare(
        'UPDATE table_sessions
            SET status = "eating"
          WHERE store_id = ? AND table_id = ? AND status = "seated"'
    )->execute([$storeId, $tableId]);
} catch (PDOException $e) {
    error_log('[customer/orders.php] session_mark_eating_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// L-15: スマレジ同期（ベストエフォート）
try {
    require_once __DIR__ . '/../smaregi/sync-order.php';
    sync_order_to_smaregi($pdo, $storeId, $orderId);
} catch (Exception $e) {
    error_log('[L-15] smaregi sync exception: ' . $e->getMessage());
}

json_response(['ok' => true, 'order_id' => $orderId], 201);
