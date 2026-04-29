<?php
/**
 * F-QR1: サブセッション管理 API
 *
 * GET  /api/store/sub-sessions.php?store_id=xxx&table_session_id=yyy
 *   → 指定セッションのサブセッション一覧
 *
 * POST /api/store/sub-sessions.php
 *   Body: { store_id, table_session_id, table_id, label? }
 *   → サブセッション作成 + sub_token 発行
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST']);
$user = require_auth();

$pdo = get_db();

// テーブル存在チェック（graceful degradation）
try {
    $pdo->query('SELECT 1 FROM table_sub_sessions LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION_REQUIRED', 'サブセッション機能のマイグレーションが未適用です', 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    $sessionId = $_GET['table_session_id'] ?? '';
    if (!$sessionId) json_error('VALIDATION', 'table_session_id は必須です', 400);

    $stmt = $pdo->prepare(
        'SELECT id, sub_token, label, created_at, closed_at
         FROM table_sub_sessions
         WHERE table_session_id = ? AND store_id = ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([$sessionId, $storeId]);
    $subs = $stmt->fetchAll();

    // 各サブセッションの注文数・合計金額
    $hasSubCol = false;
    try {
        $pdo->query('SELECT sub_session_id FROM orders LIMIT 0');
        $hasSubCol = true;
    } catch (PDOException $e) {}

    $result = [];
    foreach ($subs as $s) {
        $entry = [
            'id'        => $s['id'],
            'subToken'  => $s['sub_token'],
            'label'     => $s['label'],
            'createdAt' => $s['created_at'],
            'closedAt'  => $s['closed_at'],
            'orderCount' => 0,
            'totalAmount' => 0,
        ];
        if ($hasSubCol) {
            $oStmt = $pdo->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
                 FROM orders WHERE sub_session_id = ? AND status != 'cancelled'"
            );
            $oStmt->execute([$s['id']]);
            $oRow = $oStmt->fetch();
            $entry['orderCount'] = (int)$oRow['cnt'];
            $entry['totalAmount'] = (int)$oRow['total'];
        }
        $result[] = $entry;
    }

    json_response(['subSessions' => $result]);
}

// POST: サブセッション作成
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) json_error('VALIDATION', 'リクエストボディが不正です', 400);

$storeId   = $data['store_id'] ?? '';
$sessionId = $data['table_session_id'] ?? '';
$tableId   = $data['table_id'] ?? '';
$label     = isset($data['label']) ? mb_substr(trim($data['label']), 0, 50) : null;

if (!$storeId || !$sessionId || !$tableId) {
    json_error('VALIDATION', 'store_id, table_session_id, table_id は必須です', 400);
}

require_store_access($storeId);

// 親セッションの存在確認
$stmt = $pdo->prepare(
    "SELECT id FROM table_sessions
     WHERE id = ? AND store_id = ?
       AND status IN ('seated', 'eating', 'bill_requested')"
);
$stmt->execute([$sessionId, $storeId]);
if (!$stmt->fetch()) {
    json_error('SESSION_NOT_ACTIVE', 'アクティブなセッションが見つかりません', 404);
}

// サブセッション上限チェック（1セッション最大10）
$cntStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM table_sub_sessions WHERE table_session_id = ? AND closed_at IS NULL'
);
$cntStmt->execute([$sessionId]);
if ((int)$cntStmt->fetchColumn() >= 10) {
    json_error('SUB_SESSION_LIMIT', 'サブセッションの上限（10）に達しています', 400);
}

// 自動ラベル生成
if (!$label) {
    $seqStmt = $pdo->prepare('SELECT COUNT(*) FROM table_sub_sessions WHERE table_session_id = ?');
    $seqStmt->execute([$sessionId]);
    $seq = (int)$seqStmt->fetchColumn() + 1;
    $label = chr(64 + $seq) . '様'; // A様, B様, C様...
}

$subId = generate_uuid();
$subToken = bin2hex(random_bytes(16));

$pdo->prepare(
    'INSERT INTO table_sub_sessions (id, table_session_id, store_id, table_id, sub_token, label, created_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())'
)->execute([$subId, $sessionId, $storeId, $tableId, $subToken, $label]);

// 擬似本番では canonical URL を返す
$qrUrl = app_url('/customer/menu.html') . '?store_id=' . urlencode($storeId)
    . '&table_id=' . urlencode($tableId) . '&sub_token=' . urlencode($subToken);

json_response([
    'subSession' => [
        'id'       => $subId,
        'subToken' => $subToken,
        'label'    => $label,
        'qrUrl'    => $qrUrl,
    ],
]);
