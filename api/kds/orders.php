<?php
/**
 * KDS注文ポーリング API
 *
 * GET /api/kds/orders.php?store_id=xxx&since=xxx
 *
 * staff以上。3秒間隔ポーリングでKDSに注文一覧を返す。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';
require_once __DIR__ . '/../lib/order-items.php';

require_method(['GET']);
$user = require_auth();
$storeId = require_store_param();

$pdo = get_db();

$stationId = $_GET['station_id'] ?? null;
$businessDay = get_business_day($pdo, $storeId);

// --- orders テーブルのカラム存在チェック（マイグレーション未適用対応） ---
$orderCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM orders");
    foreach ($colStmt->fetchAll() as $col) {
        $orderCols[$col['Field']] = true;
    }
} catch (PDOException $e) {
    // H-14: browser 応答から PDO 内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/kds/orders.php] db_error: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '注文データの取得に失敗しました', 500);
}

$hasOrderType   = isset($orderCols['order_type']);
$hasCourseId    = isset($orderCols['course_id']);
$hasRemovedItems = isset($orderCols['removed_items']);
$hasMemo        = isset($orderCols['memo']);

// ステーションフィルタリング用: 許可カテゴリIDを取得
$allowedCategories = null;
if ($stationId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT r.category_id FROM kds_routing_rules r
             JOIN kds_stations s ON s.id = r.station_id
             WHERE r.station_id = ? AND s.store_id = ? AND s.is_active = 1'
        );
        $stmt->execute([$stationId, $storeId]);
        $allowedCategories = array_column($stmt->fetchAll(), 'category_id');
    } catch (PDOException $e) {
        // kds_stations / kds_routing_rules テーブル未作成時はスキップ
        error_log('[P1-12][kds/orders.php:52] fetch_kds_routing: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }
}

// メニューアイテムID → カテゴリID のマップ（ステーション用）
$itemCategoryMap = [];
if ($allowedCategories !== null && !empty($allowedCategories)) {
    // テンプレートメニュー
    $stmt = $pdo->prepare(
        'SELECT s.id AS store_id, mt.id AS item_id, mt.category_id
         FROM stores s JOIN menu_templates mt ON mt.tenant_id = s.tenant_id
         WHERE s.id = ? AND mt.is_active = 1'
    );
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() as $row) {
        $itemCategoryMap[$row['item_id']] = $row['category_id'];
    }
    // ローカルメニュー
    $stmt = $pdo->prepare(
        'SELECT id AS item_id, category_id FROM store_local_items WHERE store_id = ?'
    );
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() as $row) {
        $itemCategoryMap[$row['item_id']] = $row['category_id'];
    }
}

// コースフェーズ自動発火チェック（ポーリング駆動）
if ($hasCourseId) {
    try {
        check_course_auto_fire($pdo, $storeId);
    } catch (PDOException $e) {
        // table_sessions 未作成時はスキップ
    }
}

// 当日の有効な注文を取得（カラム存在に応じてSELECT構築）
$selectCols = 'o.id, o.table_id, COALESCE(t.table_code, "") AS table_code,
               o.items, o.total_amount, o.status,
               o.created_at, o.updated_at,
               o.prepared_at, o.ready_at, o.served_at, o.paid_at,
               o.payment_method, o.received_amount, o.change_amount';

if ($hasOrderType) {
    $selectCols .= ', o.order_type, o.staff_id, o.customer_name, o.pickup_at';
}
if ($hasCourseId) {
    $selectCols .= ', o.course_id, o.current_phase';
}
if ($hasMemo) {
    $selectCols .= ', o.memo';
}

$sql = 'SELECT ' . $selectCols . '
        FROM orders o
        LEFT JOIN tables t ON t.id = o.table_id
        WHERE o.store_id = ?
        AND o.created_at >= ? AND o.created_at < ?';
$params = [$storeId, $businessDay['start'], $businessDay['end']];

// KDSキッチン画面: 調理関連ステータスのみ / 会計画面: 未会計全て
$view = $_GET['view'] ?? 'kitchen';
if ($view === 'kitchen') {
    $sql .= ' AND o.status IN ("pending", "preparing", "ready")';
} else {
    $sql .= ' AND o.status != "paid"';
}

$sql .= ' ORDER BY o.created_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// order_items テーブル存在チェック
$hasOrderItems = false;
try {
    $pdo->query('SELECT 1 FROM order_items LIMIT 0');
    $hasOrderItems = true;
} catch (PDOException $e) {
    // テーブル未作成
}

// order_items のカラム存在チェック
$hasAllergen = false;
$hasPaymentId = false;
if ($hasOrderItems) {
    try {
        $pdo->query('SELECT allergen_selections FROM order_items LIMIT 0');
        $hasAllergen = true;
    } catch (PDOException $e) {}
    try {
        $pdo->query('SELECT payment_id FROM order_items LIMIT 0');
        $hasPaymentId = true;
    } catch (PDOException $e) {}
}

// order_items から品目を一括取得（存在する場合）
$orderItemsMap = []; // order_id => [items]
if ($hasOrderItems && !empty($orders)) {
    $orderIdList = array_column($orders, 'id');
    $ph = implode(',', array_fill(0, count($orderIdList), '?'));
    $oiSelect = 'id AS item_id, order_id, menu_item_id, name, price, qty, options, status';
    if ($hasAllergen) {
        $oiSelect .= ', allergen_selections';
    }
    if ($hasPaymentId) {
        $oiSelect .= ', payment_id';
    }
    $oiStmt = $pdo->prepare(
        'SELECT ' . $oiSelect . '
         FROM order_items WHERE order_id IN (' . $ph . ') ORDER BY created_at ASC'
    );
    $oiStmt->execute($orderIdList);
    foreach ($oiStmt->fetchAll() as $oi) {
        $oi['options'] = $oi['options'] ? json_decode($oi['options'], true) : null;
        $oi['id'] = $oi['menu_item_id']; // 既存のフロントエンドが item.id を参照するため
        if (!$hasPaymentId) {
            $oi['payment_id'] = null;
        }
        $orderItemsMap[$oi['order_id']][] = $oi;
    }
}

// items をデコード + ステーションフィルタリング
$result = [];
foreach ($orders as &$o) {
    // order_items にレコードがあればそちらを使用、なければJSONフォールバック
    if (isset($orderItemsMap[$o['id']])) {
        $o['items'] = $orderItemsMap[$o['id']];
    } else {
        $decoded = json_decode($o['items'], true) ?: [];
        // JSONフォールバック: item_id と status を付与
        $o['items'] = array_map(function ($item) {
            $item['item_id'] = null;
            $item['status'] = 'pending';
            return $item;
        }, $decoded);
    }

    // マイグレーション未適用時のデフォルト値
    if (!$hasOrderType) {
        $o['order_type'] = 'dine_in';
        $o['staff_id'] = null;
        $o['customer_name'] = null;
        $o['pickup_at'] = null;
    }
    if (!$hasCourseId) {
        $o['course_id'] = null;
        $o['current_phase'] = null;
    }
    if (!$hasMemo) {
        $o['memo'] = null;
    }

    // ステーション指定時: 該当カテゴリの品目のみ残す
    if ($allowedCategories !== null) {
        $o['items'] = array_values(array_filter($o['items'], function ($item) use ($allowedCategories, $itemCategoryMap) {
            $catId = $itemCategoryMap[$item['id']] ?? null;
            return $catId && in_array($catId, $allowedCategories, true);
        }));
        // 該当品目0件の注文は除外
        if (empty($o['items'])) continue;
    }

    $result[] = $o;
}

// ステーション一覧も返す（UI用）
$stationList = [];
try {
    $stmt = $pdo->prepare(
        'SELECT id, name, name_en FROM kds_stations
         WHERE store_id = ? AND is_active = 1 ORDER BY sort_order'
    );
    $stmt->execute([$storeId]);
    $stationList = $stmt->fetchAll();
} catch (PDOException $e) {
    // テーブル未作成時はスキップ
    error_log('[P1-12][kds/orders.php:229] fetch_station_list: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// コース注文にフェーズ名を付与
if ($hasCourseId) {
    $coursePhaseNames = [];
    foreach ($result as &$o) {
        if (!empty($o['course_id']) && !empty($o['current_phase'])) {
            $cacheKey = $o['course_id'] . '_' . $o['current_phase'];
            if (!isset($coursePhaseNames[$cacheKey])) {
                try {
                    $stmt2 = $pdo->prepare(
                        'SELECT cp.name AS phase_name, ct.name AS course_name
                         FROM course_phases cp
                         JOIN course_templates ct ON ct.id = cp.course_id
                         WHERE cp.course_id = ? AND cp.phase_number = ?'
                    );
                    $stmt2->execute([$o['course_id'], $o['current_phase']]);
                    $coursePhaseNames[$cacheKey] = $stmt2->fetch() ?: [];
                } catch (PDOException $e) {
                    $coursePhaseNames[$cacheKey] = [];
                }
            }
            $cpn = $coursePhaseNames[$cacheKey];
            $o['course_name'] = $cpn['course_name'] ?? '';
            $o['phase_name'] = $cpn['phase_name'] ?? '';
        }
    }
    unset($o);
}

// N-4: 低評価アラート取得（直近30分以内、rating <= 2）
$lowRatingAlerts = [];
try {
    $pdo->query('SELECT id FROM satisfaction_ratings LIMIT 0');
    $alertStmt = $pdo->prepare(
        'SELECT sr.order_id, sr.order_item_id, sr.item_name, sr.rating, sr.table_id, sr.created_at
         FROM satisfaction_ratings sr
         WHERE sr.store_id = ? AND sr.rating <= 2
           AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
         ORDER BY sr.created_at DESC'
    );
    $alertStmt->execute([$storeId]);
    $lowRatingAlerts = $alertStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // satisfaction_ratings テーブル未作成
    error_log('[P1-12][kds/orders.php:274] fetch_low_rating_alerts: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

json_response([
    'orders'            => $result,
    'stations'          => $stationList,
    'businessDay'       => $businessDay['date'],
    'low_rating_alerts' => $lowRatingAlerts,
]);

// ---- コースフェーズ自動発火チェック ----
function check_course_auto_fire(PDO $pdo, string $storeId): void
{
    // アクティブなコースセッションを取得
    $stmt = $pdo->prepare(
        'SELECT ts.* FROM table_sessions ts
         WHERE ts.store_id = ? AND ts.course_id IS NOT NULL
           AND ts.status IN ("seated","eating")
           AND ts.current_phase_number IS NOT NULL'
    );
    $stmt->execute([$storeId]);
    $sessions = $stmt->fetchAll();

    foreach ($sessions as $ts) {
        $courseId = $ts['course_id'];
        $currentPhase = (int)$ts['current_phase_number'];
        $tableId = $ts['table_id'];
        $phaseFiredAt = $ts['phase_fired_at'];

        // 現フェーズの全注文を確認
        $stmt2 = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = "served" THEN 1 ELSE 0 END) AS served,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) AS cancelled
             FROM orders
             WHERE store_id = ? AND table_id = ? AND course_id = ? AND current_phase = ?'
        );
        $stmt2->execute([$storeId, $tableId, $courseId, $currentPhase]);
        $counts = $stmt2->fetch();

        $total = (int)$counts['total'];
        $served = (int)$counts['served'];
        $cancelled = (int)$counts['cancelled'];

        // 全品が served または cancelled でなければスキップ
        if ($total === 0 || ($served + $cancelled) < $total) continue;

        // 次フェーズ取得
        $nextPhaseNum = $currentPhase + 1;
        $stmt3 = $pdo->prepare(
            'SELECT * FROM course_phases WHERE course_id = ? AND phase_number = ?'
        );
        $stmt3->execute([$courseId, $nextPhaseNum]);
        $nextPhase = $stmt3->fetch();

        // 次フェーズなし → コース完了
        if (!$nextPhase) continue;

        // auto_fire_min チェック
        $autoFireMin = $nextPhase['auto_fire_min'];
        if ($autoFireMin === null) {
            // 手動発火待ち → スキップ
            continue;
        }

        $autoFireMin = (int)$autoFireMin;
        if ($autoFireMin > 0 && $phaseFiredAt) {
            $firedTime = strtotime($phaseFiredAt);
            $fireAt = $firedTime + ($autoFireMin * 60);
            if (time() < $fireAt) continue; // まだ時間に達していない
        }

        // 発火: 次フェーズの注文を自動生成
        $items = json_decode($nextPhase['items'], true);
        if (!$items || !is_array($items)) continue;

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
        $stmt4 = $pdo->prepare(
            'INSERT INTO orders (id, store_id, table_id, items, total_amount, status, order_type, course_id, current_phase, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, "pending", "dine_in", ?, ?, NOW(), NOW())'
        );
        $stmt4->execute([
            $orderId, $storeId, $tableId,
            json_encode($orderItems, JSON_UNESCAPED_UNICODE),
            $courseId, $nextPhaseNum
        ]);

        // order_items テーブルにも書き込み
        insert_order_items($pdo, $orderId, $storeId, $orderItems);

        // セッション更新
        $pdo->prepare(
            'UPDATE table_sessions SET current_phase_number = ?, phase_fired_at = NOW() WHERE id = ?'
        )->execute([$nextPhaseNum, $ts['id']]);
    }
}
