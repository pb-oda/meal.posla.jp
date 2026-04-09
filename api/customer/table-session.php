<?php
/**
 * テーブルセッション API（認証なし）
 *
 * GET /api/customer/table-session.php?store_id=xxx&table_id=xxx
 *
 * QRコードスキャン後、テーブルの有効性を確認し、
 * セッショントークンを返す。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

require_method(['GET']);

$storeId = $_GET['store_id'] ?? null;
$tableId = $_GET['table_id'] ?? null;

if (!$storeId || !$tableId) json_error('MISSING_PARAMS', 'store_idとtable_idが必要です', 400);

$pdo = get_db();

// テーブル存在確認
$stmt = $pdo->prepare(
    'SELECT t.id, t.table_code, t.capacity, s.name AS store_name, s.name_en AS store_name_en
     FROM tables t
     JOIN stores s ON s.id = t.store_id
     WHERE t.id = ? AND t.store_id = ? AND t.is_active = 1 AND s.is_active = 1'
);
$stmt->execute([$tableId, $storeId]);
$table = $stmt->fetch();

if (!$table) json_error('TABLE_NOT_FOUND', 'テーブルが見つかりません', 404);

// 自動着席: アクティブセッションがなければ自動作成
try {
    $stmt = $pdo->prepare(
        "SELECT id FROM table_sessions WHERE table_id = ? AND store_id = ? AND status NOT IN ('paid', 'closed') LIMIT 1"
    );
    $stmt->execute([$tableId, $storeId]);
    if (!$stmt->fetch()) {
        // アクティブセッションなし → 自動作成
        $autoSessionId = generate_uuid();
        $pdo->prepare(
            'INSERT INTO table_sessions (id, store_id, table_id, status, started_at) VALUES (?, ?, ?, "seated", NOW())'
        )->execute([$autoSessionId, $storeId, $tableId]);

        // トークンも新規生成（前の客のトークンを無効化）
        $currentToken = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?')
            ->execute([$currentToken, $tableId]);
    } else {
        // 既存セッションあり → 既存トークンを使用
        $stmt = $pdo->prepare('SELECT session_token, session_token_expires_at FROM tables WHERE id = ?');
        $stmt->execute([$tableId]);
        $tokenRow = $stmt->fetch();
        $currentToken = $tokenRow ? $tokenRow['session_token'] : null;
        $tokenExpired = $tokenRow && $tokenRow['session_token_expires_at'] && strtotime($tokenRow['session_token_expires_at']) < time();

        if (!$currentToken || $tokenExpired) {
            $currentToken = bin2hex(random_bytes(16));
            $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?')
                ->execute([$currentToken, $tableId]);
        }
    }
} catch (Exception $e) {
    // table_sessions 未作成時はフォールバック（従来動作）
    $stmt = $pdo->prepare('SELECT session_token, session_token_expires_at FROM tables WHERE id = ?');
    $stmt->execute([$tableId]);
    $tokenRow = $stmt->fetch();
    $currentToken = $tokenRow ? $tokenRow['session_token'] : null;
    $tokenExpired = $tokenRow && $tokenRow['session_token_expires_at'] && strtotime($tokenRow['session_token_expires_at']) < time();

    if (!$currentToken || $tokenExpired) {
        $currentToken = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ?')
            ->execute([$currentToken, $tableId]);
    }
}

// アクティブなプランセッションを検索
$planData = null;
try {
    $stmt = $pdo->prepare(
        "SELECT ts.plan_id, ts.expires_at, ts.last_order_at,
                tlp.name AS plan_name, tlp.name_en AS plan_name_en, tlp.duration_min
         FROM table_sessions ts
         JOIN time_limit_plans tlp ON tlp.id = ts.plan_id
         WHERE ts.table_id = ? AND ts.store_id = ?
           AND ts.status IN ('seated','eating')
           AND ts.plan_id IS NOT NULL
         ORDER BY ts.started_at DESC
         LIMIT 1"
    );
    $stmt->execute([$tableId, $storeId]);
    $session = $stmt->fetch();
    if ($session) {
        $planData = [
            'id'           => $session['plan_id'],
            'name'         => $session['plan_name'],
            'nameEn'       => $session['plan_name_en'] ?? '',
            'timeLimitMin' => (int)$session['duration_min'],
            'expiresAt'    => $session['expires_at'],
            'lastOrderAt'  => $session['last_order_at'],
        ];
    }
} catch (Exception $e) {
    // table_sessions テーブルが未作成の場合はスキップ
    error_log('[P1-12][customer/table-session.php:107] fetch_plan_data: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

// アクティブなコースセッションを検索
$courseData = null;
try {
    $stmt = $pdo->prepare(
        "SELECT ts.course_id, ts.current_phase_number,
                ct.name AS course_name, ct.name_en AS course_name_en, ct.price AS course_price
         FROM table_sessions ts
         JOIN course_templates ct ON ct.id = ts.course_id
         WHERE ts.table_id = ? AND ts.store_id = ?
           AND ts.status IN ('seated','eating')
           AND ts.course_id IS NOT NULL
         ORDER BY ts.started_at DESC
         LIMIT 1"
    );
    $stmt->execute([$tableId, $storeId]);
    $courseSession = $stmt->fetch();
    if ($courseSession) {
        // 全フェーズ取得
        $stmt2 = $pdo->prepare(
            'SELECT phase_number, name, name_en, items, auto_fire_min
             FROM course_phases
             WHERE course_id = ?
             ORDER BY phase_number'
        );
        $stmt2->execute([$courseSession['course_id']]);
        $phases = [];
        foreach ($stmt2->fetchAll() as $ph) {
            $phaseItems = json_decode($ph['items'], true) ?: [];
            $phases[] = [
                'phaseNumber' => (int)$ph['phase_number'],
                'name'        => $ph['name'],
                'nameEn'      => $ph['name_en'] ?? '',
                'autoFireMin' => $ph['auto_fire_min'] !== null ? (int)$ph['auto_fire_min'] : null,
                'items'       => array_map(function ($it) {
                    return [
                        'name'  => $it['name'] ?? '',
                        'nameEn' => $it['name_en'] ?? '',
                        'qty'   => (int)($it['qty'] ?? 1),
                    ];
                }, $phaseItems),
            ];
        }

        $courseData = [
            'id'           => $courseSession['course_id'],
            'name'         => $courseSession['course_name'],
            'nameEn'       => $courseSession['course_name_en'] ?? '',
            'price'        => (int)$courseSession['course_price'],
            'currentPhase' => (int)$courseSession['current_phase_number'],
            'phases'       => $phases,
        ];
    }
} catch (Exception $e) {
    // テーブル未作成時はスキップ
}

// ウェルカムメッセージ取得
$welcomeMessage = null;
$welcomeMessageEn = null;
try {
    $stmt = $pdo->prepare('SELECT welcome_message, welcome_message_en, brand_color, brand_logo_url, brand_display_name FROM store_settings WHERE store_id = ?');
    $stmt->execute([$storeId]);
    $wRow = $stmt->fetch();
    if ($wRow) {
        $welcomeMessage = $wRow['welcome_message'];
        $welcomeMessageEn = $wRow['welcome_message_en'];
    }
} catch (Exception $e) {
    // カラム未存在時（マイグレーション未適用）はスキップ
    error_log('[P1-12][customer/table-session.php:178] fetch_welcome_message: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

// 平均提供時間（過去1時間）
$avgWaitMinutes = null;
try {
    $stmt = $pdo->prepare(
        "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(served_at, ready_at))) AS avg_min
         FROM orders
         WHERE store_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
           AND (served_at IS NOT NULL OR ready_at IS NOT NULL)"
    );
    $stmt->execute([$storeId]);
    $avgRow = $stmt->fetch();
    if ($avgRow && $avgRow['avg_min'] !== null) {
        $avgWaitMinutes = (int) round($avgRow['avg_min']);
        if ($avgWaitMinutes <= 0) $avgWaitMinutes = null;
    }
} catch (Exception $e) {
    // スキップ
    error_log('[P1-12][customer/table-session.php:197] fetch_avg_wait_minutes: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

json_response([
    'table' => [
        'id'        => $table['id'],
        'tableCode' => $table['table_code'],
        'capacity'  => (int)$table['capacity'],
    ],
    'store' => [
        'name'   => $table['store_name'],
        'nameEn' => $table['store_name_en'] ?? '',
        'brandColor' => $wRow['brand_color'] ?? null,
        'brandLogoUrl' => $wRow['brand_logo_url'] ?? null,
        'brandDisplayName' => $wRow['brand_display_name'] ?? null,
    ],
    'sessionToken'     => $currentToken,
    'plan'             => $planData,
    'course'           => $courseData,
    'welcomeMessage'   => $welcomeMessage,
    'welcomeMessageEn' => $welcomeMessageEn,
    'avgWaitMinutes'   => $avgWaitMinutes,
]);
