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
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['GET']);

// S1: レートリミット — 1IP あたり 30回/10分
check_rate_limit('table-session', 30, 600);

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

// S6: アクティブセッション必須 + PIN検証（自動着席を廃止）
try {
    $stmt = $pdo->prepare(
        "SELECT id FROM table_sessions
         WHERE table_id = ? AND store_id = ?
           AND status IN ('seated', 'eating', 'bill_requested')
         LIMIT 1"
    );
    $stmt->execute([$tableId, $storeId]);
    $activeSession = $stmt->fetch();

    if (!$activeSession) {
        // 会計後/清掃待ちは顧客側から新しい session_token を取り直せないよう先に遮断する
        $cdStmt = $pdo->prepare(
            "SELECT status, closed_at FROM table_sessions
             WHERE table_id = ? AND store_id = ?
               AND status IN ('paid', 'closed', 'cleaning')
             ORDER BY COALESCE(closed_at, started_at) DESC
             LIMIT 1"
        );
        $cdStmt->execute([$tableId, $storeId]);
        $cdRow = $cdStmt->fetch();
        if ($cdRow && (
            $cdRow['status'] === 'cleaning'
            || ($cdRow['closed_at'] && (time() - strtotime($cdRow['closed_at'])) < 300)
        )) {
            json_error('SESSION_COOLDOWN', 'お会計が完了しています。新しいご注文はスタッフにお声がけください。', 403);
        }

        // L-9: 自動着席判定 — (A) スタッフのテーブル開放認証 OR (B) 予約マッチング
        $autoSeated = null;

        // (A) ホワイトリスト方式: next_session_token (スタッフ認証) を検証
        try {
            $nsStmt = $pdo->prepare('SELECT next_session_token, next_session_token_expires_at FROM tables WHERE id = ?');
            $nsStmt->execute([$tableId]);
            $nsRow = $nsStmt->fetch();
            if ($nsRow && $nsRow['next_session_token'] && $nsRow['next_session_token_expires_at']
                && strtotime($nsRow['next_session_token_expires_at']) > time()) {
                // 認証成功 → セッション作成 + ワンタイム消費
                $newSid = bin2hex(random_bytes(18));
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO table_sessions (id, store_id, table_id, status, started_at) VALUES (?, ?, ?, 'seated', NOW())")
                        ->execute([$newSid, $storeId, $tableId]);
                    $pdo->prepare('UPDATE tables SET next_session_token = NULL, next_session_token_expires_at = NULL, next_session_opened_by_user_id = NULL, next_session_opened_at = NULL WHERE id = ?')
                        ->execute([$tableId]);
                    $pdo->commit();
                    $autoSeated = $newSid;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('[L-9][table-session] staff_open_seat_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
                }
            }
        } catch (PDOException $e) {
            // next_session_token カラム未作成時はスキップ
        }

        // (B) 予約マッチング: 現在時刻 ±30分以内に該当テーブルの予約があるか
        if (!$autoSeated) {
            try {
                $rsStmt = $pdo->prepare(
                    "SELECT id, party_size FROM reservations
                     WHERE store_id = ? AND status IN ('confirmed','pending')
                       AND JSON_SEARCH(assigned_table_ids, 'one', ?) IS NOT NULL
                       AND reserved_at BETWEEN DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                     ORDER BY ABS(TIMESTAMPDIFF(SECOND, reserved_at, NOW())) ASC LIMIT 1"
                );
                $rsStmt->execute([$storeId, $tableId]);
                $rsRow = $rsStmt->fetch();
                if ($rsRow) {
                    $newSid = bin2hex(random_bytes(18));
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("INSERT INTO table_sessions (id, store_id, table_id, status, guest_count, started_at, reservation_id) VALUES (?, ?, ?, 'seated', ?, NOW(), ?)")
                            ->execute([$newSid, $storeId, $tableId, (int)$rsRow['party_size'], $rsRow['id']]);
                        $pdo->prepare("UPDATE reservations SET status = 'seated', seated_at = NOW(), table_session_id = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$newSid, $rsRow['id']]);
                        $pdo->commit();
                        $autoSeated = $newSid;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log('[L-9][table-session] reservation_match_seat_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
                    }
                }
            } catch (PDOException $e) {
                // reservations / reservation_id カラム未作成時はスキップ
            }
        }

        if ($autoSeated) {
            // 着席成功 → activeSession として扱う (以降のロジックで session_token 発行)
            $activeSession = ['id' => $autoSeated];
        } else {
            // 認証も予約マッチも成立せず → スタッフ操作を待つ
            json_error('NO_ACTIVE_SESSION', 'スタッフが着席操作を行うまでお待ちください。', 403);
        }
    }

    // F-QR1: sub_token 付きアクセスはスタッフが発行済み → PIN スキップ
    $inputSubToken = $_GET['sub_token'] ?? null;
    $skipPin = false;
    if ($inputSubToken) {
        try {
            $subCheck = $pdo->prepare(
                'SELECT id FROM table_sub_sessions WHERE sub_token = ? AND table_session_id = ? AND closed_at IS NULL'
            );
            $subCheck->execute([$inputSubToken, $activeSession['id']]);
            if ($subCheck->fetch()) $skipPin = true;
        } catch (PDOException $e) {
            // table_sub_sessions 未作成時は無視
        }
    }

    // S6: PIN検証（session_pin カラム存在時のみ）— sub_token 認証済みならスキップ
    if (!$skipPin) try {
        $pinStmt = $pdo->prepare('SELECT session_pin FROM table_sessions WHERE id = ?');
        $pinStmt->execute([$activeSession['id']]);
        $pinRow = $pinStmt->fetch();
        if ($pinRow && $pinRow['session_pin']) {
            $inputPin = $_GET['pin'] ?? null;
            if (!$inputPin) {
                json_error('PIN_REQUIRED', '着席PINを入力してください。', 403);
            }
            // C-1: PIN 専用レートリミット（ブルートフォース防御: 5回/10分）
            check_rate_limit('pin-verify:' . $tableId, 5, 600);
            if (!hash_equals($pinRow['session_pin'], $inputPin)) {
                json_error('INVALID_PIN', 'PINが正しくありません。スタッフにお声がけください。', 403);
            }
        }
    } catch (PDOException $e) {
        // session_pin カラム未作成時はPINチェックをスキップ
    }

    // 既存セッションあり → 既存トークンを使用（または更新）
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
    error_log('[P1-12][customer/table-session.php:107] fetch_plan_data: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
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
    error_log('[P1-12][customer/table-session.php:178] fetch_welcome_message: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
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
    error_log('[P1-12][customer/table-session.php:197] fetch_avg_wait_minutes: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// F-QR1: サブセッション（個別QR）対応
$subSessionId = null;
$subLabel = null;
$subToken = $_GET['sub_token'] ?? null;
if ($subToken) {
    try {
        $subStmt = $pdo->prepare(
            'SELECT id, label FROM table_sub_sessions
             WHERE sub_token = ? AND store_id = ? AND table_id = ? AND closed_at IS NULL'
        );
        $subStmt->execute([$subToken, $storeId, $tableId]);
        $subRow = $subStmt->fetch();
        if ($subRow) {
            $subSessionId = $subRow['id'];
            $subLabel = $subRow['label'];
        }
    } catch (PDOException $e) {
        // table_sub_sessions 未作成時はスキップ
    }
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
    'subSessionId'     => $subSessionId,
    'subLabel'         => $subLabel,
    'plan'             => $planData,
    'course'           => $courseData,
    'welcomeMessage'   => $welcomeMessage,
    'welcomeMessageEn' => $welcomeMessageEn,
    'avgWaitMinutes'   => $avgWaitMinutes,
]);
