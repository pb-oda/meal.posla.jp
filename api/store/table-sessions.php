<?php
/**
 * テーブルセッション API
 *
 * GET    /api/store/table-sessions.php?store_id=xxx          — アクティブ一覧
 * POST   /api/store/table-sessions.php                       — 着席（セッション開始）
 * PATCH  /api/store/table-sessions.php?id=xxx                — ステータス更新
 * DELETE /api/store/table-sessions.php?id=xxx&store_id=xxx   — セッション強制終了
 *
 * スタッフ以上。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/order-items.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_auth();
$pdo = get_db();

// テーブル存在チェック（migration未適用時のフォールバック）
try {
    $pdo->query('SELECT 1 FROM table_sessions LIMIT 0');
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['sessions' => []]);
    }
    json_error('MIGRATION_REQUIRED', 'この機能にはデータベースの更新が必要です', 500);
}

// ---- GET: アクティブセッション一覧 ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();

    $stmt = $pdo->prepare(
        'SELECT ts.*, t.table_code,
                tlp.name AS plan_name, tlp.price AS plan_price,
                ct.name AS course_name, ct.price AS course_price
         FROM table_sessions ts
         JOIN tables t ON t.id = ts.table_id
         LEFT JOIN time_limit_plans tlp ON tlp.id = ts.plan_id
         LEFT JOIN course_templates ct ON ct.id = ts.course_id
         WHERE ts.store_id = ? AND ts.status NOT IN ("paid", "closed")
         ORDER BY ts.started_at'
    );
    $stmt->execute([$storeId]);
    $sessions = $stmt->fetchAll();

    $now = time();
    $result = [];
    foreach ($sessions as $s) {
        $elapsed = $now - strtotime($s['started_at']);
        $result[] = [
            'id'                 => $s['id'],
            'tableId'            => $s['table_id'],
            'tableCode'          => $s['table_code'],
            'status'             => $s['status'],
            'guestCount'         => (int)($s['guest_count'] ?? 0),
            'startedAt'          => $s['started_at'],
            'elapsedMin'         => max(0, (int)floor($elapsed / 60)),
            'planId'             => $s['plan_id'],
            'planName'           => $s['plan_name'] ?? null,
            'planPrice'          => $s['plan_price'] !== null ? (int)$s['plan_price'] : null,
            'timeLimitMin'       => $s['time_limit_min'] ? (int)$s['time_limit_min'] : null,
            'lastOrderMin'       => $s['last_order_min'] ? (int)$s['last_order_min'] : null,
            'expiresAt'          => $s['expires_at'],
            'lastOrderAt'        => $s['last_order_at'],
            'courseId'           => $s['course_id'] ?? null,
            'courseName'         => $s['course_name'] ?? null,
            'coursePrice'        => $s['course_price'] !== null ? (int)$s['course_price'] : null,
            'currentPhaseNumber' => $s['current_phase_number'] !== null ? (int)$s['current_phase_number'] : null,
        ];
    }

    json_response(['sessions' => $result]);
}

// ---- POST: 着席（セッション開始） ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $tableId = $data['table_id'] ?? null;
    $guestCount = isset($data['guest_count']) ? (int)$data['guest_count'] : null;
    $planId = $data['plan_id'] ?? null;
    $courseId = $data['course_id'] ?? null;
    $memo = isset($data['memo']) && $data['memo'] !== '' ? $data['memo'] : null;

    if (!$storeId || !$tableId) {
        json_error('VALIDATION', 'store_id と table_id は必須です', 400);
    }
    require_store_access($storeId);

    // プランとコースは排他
    if ($planId && $courseId) {
        json_error('VALIDATION', 'プランとコースは同時に選択できません', 400);
    }

    // 既にアクティブなセッションがあるかチェック
    $stmt = $pdo->prepare(
        'SELECT id FROM table_sessions
         WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
    );
    $stmt->execute([$storeId, $tableId]);
    if ($stmt->fetch()) {
        json_error('ALREADY_ACTIVE', 'このテーブルには既にアクティブなセッションがあります', 409);
    }

    // プラン情報取得
    $timeLimitMin = null;
    $lastOrderMin = null;
    $expiresAt = null;
    $lastOrderAt = null;

    if ($planId) {
        $stmt = $pdo->prepare('SELECT * FROM time_limit_plans WHERE id = ? AND store_id = ? AND is_active = 1');
        $stmt->execute([$planId, $storeId]);
        $plan = $stmt->fetch();
        if (!$plan) {
            json_error('PLAN_NOT_FOUND', 'プランが見つかりません', 404);
        }
        $timeLimitMin = (int)$plan['duration_min'];
        $lastOrderMin = (int)$plan['last_order_min'];
        $now = new DateTime();
        $expiresAt = (clone $now)->modify("+{$timeLimitMin} minutes")->format('Y-m-d H:i:s');
        $loMinutes = $timeLimitMin - $lastOrderMin;
        $lastOrderAt = (clone $now)->modify("+{$loMinutes} minutes")->format('Y-m-d H:i:s');
    }

    // コース情報取得
    $currentPhaseNumber = null;
    $phaseFiredAt = null;
    if ($courseId) {
        $stmt = $pdo->prepare('SELECT * FROM course_templates WHERE id = ? AND store_id = ? AND is_active = 1');
        $stmt->execute([$courseId, $storeId]);
        $course = $stmt->fetch();
        if (!$course) {
            json_error('COURSE_NOT_FOUND', 'コースが見つかりません', 404);
        }
        // フェーズ1の存在確認
        $stmt = $pdo->prepare('SELECT * FROM course_phases WHERE course_id = ? AND phase_number = 1');
        $stmt->execute([$courseId]);
        $phase1 = $stmt->fetch();
        if (!$phase1) {
            json_error('NO_PHASE', 'コースにフェーズが設定されていません', 400);
        }
        $currentPhaseNumber = 1;
        $phaseFiredAt = date('Y-m-d H:i:s');
    }

    $id = generate_uuid();

    // memo カラム存在チェック
    $hasMemoCol = false;
    try {
        $pdo->query('SELECT memo FROM table_sessions LIMIT 0');
        $hasMemoCol = true;
    } catch (PDOException $e) {}

    if ($hasMemoCol) {
        $stmt = $pdo->prepare(
            'INSERT INTO table_sessions (id, store_id, table_id, status, guest_count, started_at,
             plan_id, time_limit_min, last_order_min, expires_at, last_order_at,
             course_id, current_phase_number, phase_fired_at, memo)
             VALUES (?, ?, ?, "seated", ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $storeId, $tableId, $guestCount,
            $planId, $timeLimitMin, $lastOrderMin, $expiresAt, $lastOrderAt,
            $courseId, $currentPhaseNumber, $phaseFiredAt, $memo
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO table_sessions (id, store_id, table_id, status, guest_count, started_at,
             plan_id, time_limit_min, last_order_min, expires_at, last_order_at,
             course_id, current_phase_number, phase_fired_at)
             VALUES (?, ?, ?, "seated", ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $storeId, $tableId, $guestCount,
            $planId, $timeLimitMin, $lastOrderMin, $expiresAt, $lastOrderAt,
            $courseId, $currentPhaseNumber, $phaseFiredAt
        ]);
    }

    // コース: フェーズ1の注文を自動生成
    if ($courseId && isset($phase1)) {
        fire_course_phase_orders($pdo, $storeId, $tableId, $courseId, $phase1);
    }

    json_response([
        'id'          => $id,
        'expiresAt'   => $expiresAt,
        'lastOrderAt' => $lastOrderAt,
        'courseId'     => $courseId,
    ], 201);
}

// ---- PATCH: ステータス更新 ----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    require_store_access($storeId);

    $stmt = $pdo->prepare('SELECT * FROM table_sessions WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    $session = $stmt->fetch();
    if (!$session) json_error('NOT_FOUND', 'セッションが見つかりません', 404);

    $fields = [];
    $params = [];

    if (isset($data['status'])) {
        $allowed = ['seated', 'eating', 'bill_requested', 'paid', 'closed'];
        if (!in_array($data['status'], $allowed)) {
            json_error('INVALID_STATUS', '無効なステータスです', 400);
        }
        $fields[] = 'status = ?';
        $params[] = $data['status'];

        if (in_array($data['status'], ['paid', 'closed'])) {
            $fields[] = 'closed_at = NOW()';
        }
    }

    if (isset($data['guest_count'])) {
        $fields[] = 'guest_count = ?';
        $params[] = (int)$data['guest_count'];
    }

    // O-4: メモ更新（カラム存在時のみ）
    if (array_key_exists('memo', $data)) {
        try {
            $pdo->query('SELECT memo FROM table_sessions LIMIT 0');
            $fields[] = 'memo = ?';
            $params[] = ($data['memo'] !== null && $data['memo'] !== '') ? $data['memo'] : null;
        } catch (PDOException $e) {
            // memo カラム未作成時はスキップ
        }
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);
    $params[] = $id;
    $pdo->prepare('UPDATE table_sessions SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ---- DELETE: セッション強制終了 ----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id || !$storeId) json_error('MISSING_PARAMS', 'idとstore_idが必要です', 400);
    require_store_access($storeId);

    // テーブルIDを取得（セッショントークンリセット用）
    $stmt = $pdo->prepare('SELECT table_id FROM table_sessions WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    $sessionTableId = $stmt->fetchColumn();

    $pdo->prepare(
        'UPDATE table_sessions SET status = "closed", closed_at = NOW() WHERE id = ? AND store_id = ?'
    )->execute([$id, $storeId]);

    // 未会計注文をキャンセル（テーブルリセット）
    if ($sessionTableId) {
        $pdo->prepare(
            'UPDATE orders SET status = "cancelled", updated_at = NOW() WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "cancelled")'
        )->execute([$storeId, $sessionTableId]);
    }

    // セッショントークンをリセット（古いQRコードを無効化）
    if ($sessionTableId) {
        $newToken = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?')
            ->execute([$newToken, $sessionTableId]);
    }

    json_response(['ok' => true]);
}

// ---- コースフェーズ注文自動生成ヘルパー ----
function fire_course_phase_orders(PDO $pdo, string $storeId, string $tableId, string $courseId, array $phase): void
{
    $items = json_decode($phase['items'], true);
    if (!$items || !is_array($items)) return;

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
    $stmt = $pdo->prepare(
        'INSERT INTO orders (id, store_id, table_id, items, total_amount, status, order_type, course_id, current_phase, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, "pending", "dine_in", ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $orderId, $storeId, $tableId,
        json_encode($orderItems, JSON_UNESCAPED_UNICODE),
        $courseId, (int)$phase['phase_number']
    ]);

    // order_items テーブルにも書き込み
    insert_order_items($pdo, $orderId, $storeId, $orderItems);
}
