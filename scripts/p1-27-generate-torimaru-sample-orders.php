<?php
/**
 * ============================================================
 * 【P1-27 SCRIPT】Enterprise デモテナント「炭火焼鳥 とりまる」
 * 過去30日 × 5店舗 のサンプル注文データ生成スクリプト
 * ============================================================
 *
 * 用途: 営業デモ専用。本番環境では実行しないこと。
 *
 * 前提: sql/migration-p1-27-torimaru-demo-tenant.sql が先に実行済み。
 *
 * 実行: php scripts/p1-27-generate-torimaru-sample-orders.php
 *
 * 仕様:
 *   - 期間: 本日から遡って30日分（昨日まで）
 *   - 営業時間: 17:00〜23:00（19-21時ピーク）
 *   - 1日あたり注文数:
 *       渋谷:    70-80 (Aランク店)
 *       新宿:    65-75
 *       池袋:    40-50
 *       銀座:    25-35 (high_price_bias 0.6: 高単価メニュー優先)
 *       恵比寿:  20-30
 *   - 1注文あたり: 3-8品目
 *   - 銀座店の high_price_bias = 0.6:
 *       各品目選択時、60% の確率で各カテゴリの上位半数（高価格帯）から選択
 *   - status: paid (全件完了済み)
 *   - payment_method: cash:card = 6:4
 *   - 担当: 各店 staff1/staff2 のどちらかをランダム
 *   - 提供時間: created_at → prepared_at(+1-3min) → ready_at(+5-15min) → served_at(+1-3min) → paid_at(+30-90min)
 *   - 全行 トランザクション内、原子性を保つ
 *   - 冪等性: 既に torimaru の注文が10件以上ある場合は exit（再実行防止）
 *   - 再現性: mt_srand(20260408) で乱数シード固定
 * ============================================================
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../api/lib/db.php';

// 再現性のため乱数シード固定
mt_srand(20260408);

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ──────────────────────────────────────────────
// テナント存在確認
// ──────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
$stmt->execute(['torimaru']);
$tenantId = $stmt->fetchColumn();
if (!$tenantId) {
    exit("torimaru テナントが見つかりません。先に sql/migration-p1-27-torimaru-demo-tenant.sql を実行してください。\n");
}
echo "Tenant ID: $tenantId\n";

// ──────────────────────────────────────────────
// 冪等性チェック (テナントの店舗の注文を集計)
// ──────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM orders o
     INNER JOIN stores s ON s.id = o.store_id
     WHERE s.tenant_id = ?'
);
$stmt->execute([$tenantId]);
$existingCount = (int)$stmt->fetchColumn();
if ($existingCount >= 10) {
    exit("torimaru テナントには既に {$existingCount} 件の注文が存在します。再実行を防ぐため終了します。\n");
}

// ──────────────────────────────────────────────
// 店舗別 1日注文数レンジ + high_price_bias
// ──────────────────────────────────────────────
$storeConfig = array(
    's-torimaru-shibuya'   => array('range' => array(70, 80), 'high_price_bias' => 0.0),
    's-torimaru-shinjuku'  => array('range' => array(65, 75), 'high_price_bias' => 0.0),
    's-torimaru-ikebukuro' => array('range' => array(40, 50), 'high_price_bias' => 0.0),
    's-torimaru-ginza'     => array('range' => array(25, 35), 'high_price_bias' => 0.6),
    's-torimaru-ebisu'     => array('range' => array(20, 30), 'high_price_bias' => 0.0),
);

// ──────────────────────────────────────────────
// メニューテンプレート取得 (id, name, base_price)
// ──────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT id, name, base_price, category_id FROM menu_templates WHERE tenant_id = ? AND is_active = 1'
);
$stmt->execute([$tenantId]);
$menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($menus)) {
    exit("メニューテンプレートが見つかりません。\n");
}
echo "Menus loaded: " . count($menus) . "\n";

// カテゴリ別に分類
$menusByCat = array(
    'c-torimaru-yakitori' => array(),
    'c-torimaru-side'     => array(),
    'c-torimaru-drink'    => array(),
);
foreach ($menus as $m) {
    $menusByCat[$m['category_id']][] = $m;
}

// 各カテゴリの「高価格帯（上位半数）」リストを事前計算
// high_price_bias 適用時はこちらから選択
$menusByCatHighPrice = array();
foreach ($menusByCat as $cat => $list) {
    $sorted = $list;
    usort($sorted, function($a, $b) {
        return (int)$b['base_price'] - (int)$a['base_price'];
    });
    $halfCount = max(1, intval(count($sorted) / 2));
    $menusByCatHighPrice[$cat] = array_slice($sorted, 0, $halfCount);
}

// ──────────────────────────────────────────────
// 各店舗のスタッフ ID 取得
// ──────────────────────────────────────────────
$staffByStore = array();
foreach (array_keys($storeConfig) as $storeId) {
    $stmt = $pdo->prepare(
        'SELECT u.id FROM users u
         INNER JOIN user_stores us ON us.user_id = u.id
         WHERE u.tenant_id = ? AND u.role = ? AND us.store_id = ?'
    );
    $stmt->execute(array($tenantId, 'staff', $storeId));
    $staffByStore[$storeId] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($staffByStore[$storeId])) {
        exit("店舗 $storeId のスタッフが見つかりません。\n");
    }
}
echo "Staff loaded for all 5 stores.\n";

// ──────────────────────────────────────────────
// 各店舗のテーブル ID 取得
// ──────────────────────────────────────────────
$tablesByStore = array();
foreach (array_keys($storeConfig) as $storeId) {
    $stmt = $pdo->prepare('SELECT id FROM tables WHERE store_id = ?');
    $stmt->execute(array($storeId));
    $tablesByStore[$storeId] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tablesByStore[$storeId])) {
        exit("店舗 $storeId のテーブルが見つかりません。\n");
    }
}
echo "Tables loaded for all 5 stores.\n";

// ──────────────────────────────────────────────
// ヘルパー関数
// ──────────────────────────────────────────────

// 17-23時の重み付きピックアップ (19-21時集中)
function pickHourWeighted() {
    $weights = array(17 => 1, 18 => 2, 19 => 4, 20 => 5, 21 => 4, 22 => 2, 23 => 1);
    $bag = array();
    foreach ($weights as $h => $w) {
        for ($i = 0; $i < $w; $i++) $bag[] = $h;
    }
    return $bag[array_rand($bag)];
}

// カテゴリから1品ピック (high_price_bias 適用)
// $bias = 0.0..1.0: その確率で「上位半数（高価格帯）」プールから選ぶ
function pickFromCategory($menusByCat, $menusByCatHighPrice, $cat, $bias) {
    if ($bias > 0 && (mt_rand(1, 1000) <= intval($bias * 1000))) {
        $pool = $menusByCatHighPrice[$cat];
    } else {
        $pool = $menusByCat[$cat];
    }
    return $pool[array_rand($pool)];
}

// 1注文あたりの品目選択 (ドリンク必須 + 串中心 + サイド)
function pickItems($menusByCat, $menusByCatHighPrice, $bias) {
    $items = array();
    // ドリンク 1-3 杯
    $drinkCnt = mt_rand(1, 3);
    for ($i = 0; $i < $drinkCnt; $i++) {
        $items[] = pickFromCategory($menusByCat, $menusByCatHighPrice, 'c-torimaru-drink', $bias);
    }
    // 串 2-5 本 (種類重複を許容)
    $skewerCnt = mt_rand(2, 5);
    for ($i = 0; $i < $skewerCnt; $i++) {
        $items[] = pickFromCategory($menusByCat, $menusByCatHighPrice, 'c-torimaru-yakitori', $bias);
    }
    // サイドメニュー 0-2 品
    $sideCnt = mt_rand(0, 2);
    for ($i = 0; $i < $sideCnt; $i++) {
        $items[] = pickFromCategory($menusByCat, $menusByCatHighPrice, 'c-torimaru-side', $bias);
    }

    // 同じmenu_idをqty集約 (リアルなorder_items構造)
    $aggregated = array();
    foreach ($items as $m) {
        if (!isset($aggregated[$m['id']])) {
            $aggregated[$m['id']] = array(
                'id'    => $m['id'],
                'name'  => $m['name'],
                'price' => (int)$m['base_price'],
                'qty'   => 0,
            );
        }
        $aggregated[$m['id']]['qty']++;
    }
    return array_values($aggregated);
}

// ──────────────────────────────────────────────
// メイン: 30日 × 5店舗 ループ
// ──────────────────────────────────────────────
$insOrder = $pdo->prepare(
    "INSERT INTO orders
     (id, store_id, table_id, items, total_amount, status, order_type, payment_method, staff_id,
      session_token, created_at, prepared_at, ready_at, served_at, paid_at)
     VALUES
     (?, ?, ?, ?, ?, 'paid', 'handy', ?, ?, ?, ?, ?, ?, ?, ?)"
);
$insItem = $pdo->prepare(
    "INSERT INTO order_items
     (id, order_id, store_id, menu_item_id, name, price, qty, status, created_at, prepared_at, ready_at, served_at)
     VALUES
     (?, ?, ?, ?, ?, ?, ?, 'served', ?, ?, ?, ?)"
);

$totalOrders = 0;
$totalItems = 0;
$tz = new DateTimeZone('Asia/Tokyo');

$pdo->beginTransaction();
try {
    // daysAgo: 30 → 1 (本日は除く、昨日まで)
    for ($daysAgo = 30; $daysAgo >= 1; $daysAgo--) {
        $baseDate = new DateTime('now', $tz);
        $baseDate->modify("-{$daysAgo} days");

        foreach ($storeConfig as $storeId => $cfg) {
            $range = $cfg['range'];
            $bias  = $cfg['high_price_bias'];
            $orderCount = mt_rand($range[0], $range[1]);

            for ($i = 0; $i < $orderCount; $i++) {
                $hour = pickHourWeighted();
                $minute = mt_rand(0, 59);
                $second = mt_rand(0, 59);

                $createdAt = clone $baseDate;
                $createdAt->setTime($hour, $minute, $second);

                // 提供時間タイムスタンプ
                $preparedAt = clone $createdAt;
                $preparedAt->modify('+' . mt_rand(60, 180) . ' seconds');
                $readyAt    = clone $preparedAt;
                $readyAt->modify('+' . mt_rand(300, 900) . ' seconds');
                $servedAt   = clone $readyAt;
                $servedAt->modify('+' . mt_rand(60, 180) . ' seconds');
                $paidAt     = clone $servedAt;
                $paidAt->modify('+' . mt_rand(1800, 5400) . ' seconds');

                $orderId      = generate_uuid();
                $sessionToken = bin2hex(random_bytes(16));
                $tableId      = $tablesByStore[$storeId][array_rand($tablesByStore[$storeId])];
                $staffId      = $staffByStore[$storeId][array_rand($staffByStore[$storeId])];
                $payment      = (mt_rand(1, 10) <= 6) ? 'cash' : 'card';

                $items = pickItems($menusByCat, $menusByCatHighPrice, $bias);
                $totalAmount = 0;
                foreach ($items as $it) {
                    $totalAmount += $it['price'] * $it['qty'];
                }

                // orders.items は JSON カラム
                $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

                $insOrder->execute(array(
                    $orderId,
                    $storeId,
                    $tableId,
                    $itemsJson,
                    $totalAmount,
                    $payment,
                    $staffId,
                    $sessionToken,
                    $createdAt->format('Y-m-d H:i:s'),
                    $preparedAt->format('Y-m-d H:i:s'),
                    $readyAt->format('Y-m-d H:i:s'),
                    $servedAt->format('Y-m-d H:i:s'),
                    $paidAt->format('Y-m-d H:i:s'),
                ));

                // order_items
                foreach ($items as $it) {
                    $insItem->execute(array(
                        generate_uuid(),
                        $orderId,
                        $storeId,
                        $it['id'],
                        $it['name'],
                        $it['price'],
                        $it['qty'],
                        $createdAt->format('Y-m-d H:i:s'),
                        $preparedAt->format('Y-m-d H:i:s'),
                        $readyAt->format('Y-m-d H:i:s'),
                        $servedAt->format('Y-m-d H:i:s'),
                    ));
                    $totalItems++;
                }
                $totalOrders++;
            }
        }

        // 進捗表示
        echo sprintf("Day %2d/30 done (orders: %d, items: %d)\n", 31 - $daysAgo, $totalOrders, $totalItems);
    }

    $pdo->commit();
    echo "\n========== 完了 ==========\n";
    echo "注文 INSERT: $totalOrders 件\n";
    echo "明細 INSERT: $totalItems 件\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "全件ロールバックしました。\n";
    exit(1);
}
