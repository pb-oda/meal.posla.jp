<?php
/**
 * 店舗リビジョン取得 API（軽量・ポーリングゲート用）
 *
 * GET /api/store/revisions.php?store_id=xxx&channels=kds_orders,call_alerts
 *   → { ok: true, data: { revisions: { kds_orders: "2026-04-20 12:34:56", call_alerts: "2026-04-20 12:30:01" } } }
 *
 * 用途:
 *   既存の重いポーリング API（kds/orders.php, kds/call-alerts.php 等）を毎回叩く前に、
 *   このエンドポイントで「直近の変更時刻」を取得し、変化が無ければスキップする「軽量ゲート」。
 *
 *   既存テーブルの updated_at / created_at / acknowledged_at から派生するため、
 *   新規テーブルや schema 変更は不要。
 *
 * 認証:
 *   require_auth() + require_store_access() でマルチテナント境界を担保。
 *
 * 設計方針:
 *   - レスポンスは menu-version.php と同方針（軽量・JSON）
 *   - 各チャネルは独立した SELECT MAX(...) アグリゲート（インデックスヒット）
 *   - チャネル未対応・テーブル未作成時は null を返す（フロントは null をキャッシュミス扱い）
 *   - 不明な channel 名は無視（互換性のため）
 *
 * サポートチャネル（Phase 1）:
 *   - kds_orders   : orders + order_items の最新 updated_at
 *   - call_alerts  : call_alerts の最新 created_at / acknowledged_at
 *
 * 将来追加（Phase 2 候補）:
 *   - table_status : table_sessions / tables の状態変化
 *   - takeout      : orders.order_type='takeout' の変化
 *   - menu         : menu-version.php と同等
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';

require_method(['GET']);
$user = require_auth();
$storeId = require_store_param();   // 内部で require_store_access 済み

$pdo = get_db();

$channelsParam = $_GET['channels'] ?? '';
$channels = array_values(array_filter(array_map('trim', explode(',', $channelsParam))));
if (empty($channels)) {
    // デフォルト: 主要チャネル全て
    $channels = ['kds_orders', 'call_alerts'];
}

$revisions = [];

foreach ($channels as $channel) {
    if ($channel === 'kds_orders') {
        $revisions['kds_orders'] = _rev_kds_orders($pdo, $storeId);
    } elseif ($channel === 'call_alerts') {
        $revisions['call_alerts'] = _rev_call_alerts($pdo, $storeId);
    }
    // 不明な channel は黙って無視（前方互換性）
}

json_response(['revisions' => $revisions]);


/**
 * KDS 注文の最新変更時刻
 *   - 当日営業日の orders + order_items の MAX(updated_at)
 *   - kitchen view で表示される注文（pending/preparing/ready/served）が対象だが、
 *     ゲート用途なので business_day 範囲の全 status を見れば十分（false negative を避ける）
 */
function _rev_kds_orders(PDO $pdo, string $storeId): ?string
{
    try {
        $businessDay = get_business_day($pdo, $storeId);
    } catch (Exception $e) {
        // business-day 計算失敗時は ゲート無効化（フロントが従来通り fetch）
        return null;
    }

    $maxOrders = null;
    $maxItems  = null;

    try {
        $stmt = $pdo->prepare(
            'SELECT MAX(updated_at) AS latest FROM orders
             WHERE store_id = ? AND created_at >= ? AND created_at < ?'
        );
        $stmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);
        $row = $stmt->fetch();
        $maxOrders = $row && $row['latest'] ? $row['latest'] : null;
    } catch (PDOException $e) {
        error_log('[revisions.php] kds_orders/orders MAX error: ' . $e->getMessage());
        return null;
    }

    // order_items は updated_at があるので JOIN で取る
    // インデックス: idx_oi_store_status (store_id, status) でフィルタ + 直近行スキャン
    try {
        $stmt = $pdo->prepare(
            'SELECT MAX(oi.updated_at) AS latest FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             WHERE oi.store_id = ?
               AND o.created_at >= ? AND o.created_at < ?'
        );
        $stmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);
        $row = $stmt->fetch();
        $maxItems = $row && $row['latest'] ? $row['latest'] : null;
    } catch (PDOException $e) {
        // order_items 未作成は許容（orders だけで判定継続）
        $maxItems = null;
    }

    if ($maxOrders === null && $maxItems === null) return null;
    if ($maxOrders === null) return $maxItems;
    if ($maxItems === null) return $maxOrders;
    return ($maxOrders >= $maxItems) ? $maxOrders : $maxItems;
}

/**
 * 呼び出しアラートの最新変更時刻
 *   - call_alerts には updated_at が無いので、created_at と acknowledged_at の最大値で代用
 *   - INSERT (新着) / UPDATE status=acknowledged の両方を捕捉できる
 *   - インデックス: idx_store_status (store_id, status) でフィルタ
 */
function _rev_call_alerts(PDO $pdo, string $storeId): ?string
{
    try {
        $stmt = $pdo->prepare(
            'SELECT MAX(GREATEST(created_at, COALESCE(acknowledged_at, created_at))) AS latest
             FROM call_alerts
             WHERE store_id = ?'
        );
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();
        return $row && $row['latest'] ? $row['latest'] : null;
    } catch (PDOException $e) {
        // call_alerts 未作成は許容
        return null;
    }
}
