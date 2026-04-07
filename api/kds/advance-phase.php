<?php
/**
 * コースフェーズ手動発火 API
 *
 * POST /api/kds/advance-phase.php
 * Body: { store_id, table_id }
 *
 * 現フェーズの全注文を served に更新し、次フェーズの注文を自動生成。
 * auto_fire_min = NULL（手動）の場合にKDSから呼ばれる。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/order-items.php';

require_method(['POST']);
$user = require_auth();

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$tableId = $data['table_id'] ?? null;

if (!$storeId || !$tableId) {
    json_error('VALIDATION', 'store_id と table_id は必須です', 400);
}
require_store_access($storeId);

$pdo = get_db();

// アクティブなコースセッション取得
$stmt = $pdo->prepare(
    'SELECT * FROM table_sessions
     WHERE store_id = ? AND table_id = ? AND course_id IS NOT NULL
       AND status IN ("seated","eating")
       AND current_phase_number IS NOT NULL
     ORDER BY started_at DESC LIMIT 1'
);
$stmt->execute([$storeId, $tableId]);
$session = $stmt->fetch();

if (!$session) {
    json_error('NO_COURSE_SESSION', 'アクティブなコースセッションが見つかりません', 404);
}

$courseId = $session['course_id'];
$currentPhase = (int)$session['current_phase_number'];

// 現フェーズの未完了注文を served に更新
$pdo->prepare(
    'UPDATE orders SET status = "served", served_at = NOW(), updated_at = NOW()
     WHERE store_id = ? AND table_id = ? AND course_id = ? AND current_phase = ?
       AND status NOT IN ("served", "paid", "cancelled")'
)->execute([$storeId, $tableId, $courseId, $currentPhase]);

// 次フェーズ取得
$nextPhaseNum = $currentPhase + 1;
$stmt = $pdo->prepare(
    'SELECT * FROM course_phases WHERE course_id = ? AND phase_number = ?'
);
$stmt->execute([$courseId, $nextPhaseNum]);
$nextPhase = $stmt->fetch();

if (!$nextPhase) {
    json_response([
        'ok' => true,
        'message' => 'コース全フェーズ完了',
        'completed' => true,
    ]);
}

// 次フェーズの注文を生成
$items = json_decode($nextPhase['items'], true);
if ($items && is_array($items)) {
    $orderItems = [];
    foreach ($items as $item) {
        $orderItems[] = [
            'id'    => $item['id'] ?? '',
            'name'  => $item['name'] ?? '',
            'price' => 0,
            'qty'   => (int)($item['qty'] ?? 1),
        ];
    }

    $orderId = generate_uuid();
    $pdo->prepare(
        'INSERT INTO orders (id, store_id, table_id, items, total_amount, status, order_type, course_id, current_phase, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, "pending", "dine_in", ?, ?, NOW(), NOW())'
    )->execute([
        $orderId, $storeId, $tableId,
        json_encode($orderItems, JSON_UNESCAPED_UNICODE),
        $courseId, $nextPhaseNum
    ]);

    // order_items テーブルにも書き込み
    insert_order_items($pdo, $orderId, $storeId, $orderItems);
}

// セッション更新
$pdo->prepare(
    'UPDATE table_sessions SET current_phase_number = ?, phase_fired_at = NOW() WHERE id = ?'
)->execute([$nextPhaseNum, $session['id']]);

json_response([
    'ok' => true,
    'message' => 'フェーズ ' . $nextPhaseNum . ' を開始しました',
    'currentPhase' => $nextPhaseNum,
    'completed' => false,
]);
