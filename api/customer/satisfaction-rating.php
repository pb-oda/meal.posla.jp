<?php
/**
 * 満足度評価送信 API（認証なし）
 *
 * POST /api/customer/satisfaction-rating.php
 *
 * Body: { store_id, table_id, session_token, order_id, order_item_id, menu_item_id, item_name, rating }
 *
 * N-4: 品目提供後にお客様が5段階評価を送信
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rating-reasons.php';
require_once __DIR__ . '/../lib/push.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['POST']);

// H-03: 評価スパム / low_rating Push 詐称防御 — 1IP あたり 10 回 / 5 分
check_rate_limit('satisfaction-rating', 10, 300);

$data = get_json_body();
$storeId      = $data['store_id']      ?? null;
$tableId      = $data['table_id']      ?? null;
$sessionToken = $data['session_token'] ?? null;
$orderId      = $data['order_id']      ?? null;
$orderItemId  = $data['order_item_id'] ?? null;
$menuItemId   = $data['menu_item_id']  ?? null;
$itemName     = $data['item_name']     ?? null;
$rating       = isset($data['rating']) ? (int)$data['rating'] : null;
// R1: 低評価理由 (任意)。rating <= 2 のときだけ保存対象。
$reasonCodeIn = isset($data['reason_code']) ? (string)$data['reason_code'] : null;
$reasonTextIn = isset($data['reason_text']) ? (string)$data['reason_text'] : null;

if (!$storeId || !$tableId || !$sessionToken || !$orderId || $rating === null) {
    json_error('MISSING_FIELDS', '必須フィールドが不足しています', 400);
}

if ($rating < 1 || $rating > 5) {
    json_error('INVALID_RATING', '評価は1〜5の整数で指定してください', 400);
}

// R1: rating <= 2 のときだけ reason_code を許可リスト検証する
//   rating >= 3 では reason 自体を保存しないため、検証も行わない (rating-only 送信との後方互換)
if ($rating <= 2 && !rating_reason_is_valid_code($reasonCodeIn)) {
    json_error('INVALID_REASON', '理由コードが正しくありません', 400);
}

// rating >= 3 では reason は保存しない (UIから来ても無視 = 後方互換)
$reasonCode = ($rating <= 2 && $reasonCodeIn !== null && $reasonCodeIn !== '') ? $reasonCodeIn : null;
$reasonText = ($rating <= 2) ? rating_reason_normalize_text($reasonTextIn) : null;

$pdo = get_db();

// セッショントークン検証
//   R1 修正: 旧実装は tables 行が見つからないケース (誤った table_id / store_id) を
//   黙って素通りさせていた。これを INVALID_SESSION で必ず弾く。
$stmt = $pdo->prepare('SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ?');
$stmt->execute([$tableId, $storeId]);
$tokenRow = $stmt->fetch();
if (!$tokenRow) {
    json_error('INVALID_SESSION', 'セッションが無効です', 403);
}
$dbToken   = $tokenRow['session_token'];
$expiresAt = $tokenRow['session_token_expires_at'];
if (!$dbToken || !hash_equals((string)$dbToken, (string)$sessionToken)) {
    json_error('INVALID_SESSION', 'セッションが無効です', 403);
}
if ($expiresAt && strtotime($expiresAt) < time()) {
    json_error('INVALID_SESSION', 'セッションが期限切れです', 403);
}

// R1 修正: order_id がこの店舗・テーブル・セッションに属するか検証
//   旧実装は order_id を無検証で INSERT していたため、自由記述 reason_text が
//   無関係な注文に紐付けられる risk があった。
$stmt = $pdo->prepare(
    'SELECT id FROM orders
     WHERE id = ? AND store_id = ? AND table_id = ? AND session_token = ?
     LIMIT 1'
);
$stmt->execute([$orderId, $storeId, $tableId, $sessionToken]);
if (!$stmt->fetch()) {
    json_error('ORDER_NOT_FOUND', '指定された注文がこのセッションに見つかりません', 404);
}

// R1 修正: order_item_id 指定時は、該当 order に属するかも検証
//   (order_items テーブル未作成環境では検証スキップ — 既存運用との互換性維持)
if ($orderItemId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM order_items
             WHERE id = ? AND order_id = ? AND store_id = ?
             LIMIT 1'
        );
        $stmt->execute([$orderItemId, $orderId, $storeId]);
        if (!$stmt->fetch()) {
            json_error('ITEM_NOT_FOUND', '指定された品目がこの注文に見つかりません', 404);
        }
    } catch (PDOException $e) {
        // order_items テーブル未作成 → 検証スキップ (既存スキーマ互換)
    }
}

// 重複チェック: 同一 order_item_id + session_token
$existingId = null;
if ($orderItemId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM satisfaction_ratings
             WHERE order_item_id = ? AND session_token = ? AND store_id = ?'
        );
        $stmt->execute([$orderItemId, $sessionToken, $storeId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $existingId = $existing['id'];
        }
    } catch (PDOException $e) {
        // テーブル未作成 → INSERT で失敗する（下記 catch で処理）
        error_log('[P1-12][api/customer/satisfaction-rating.php:65] check_existing_rating: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }
}

// R1: reason_code / reason_text カラム存在チェック (migration-r1 未適用環境でも壊れない)
$hasReasonCols = false;
try {
    $pdo->query('SELECT reason_code, reason_text FROM satisfaction_ratings LIMIT 0');
    $hasReasonCols = true;
} catch (PDOException $e) {
    // カラム未追加 → reason は無視して既存スキーマで保存
}

try {
    if ($existingId) {
        // UPDATE（再評価）
        if ($hasReasonCols) {
            $stmt = $pdo->prepare(
                'UPDATE satisfaction_ratings
                    SET rating = ?, reason_code = ?, reason_text = ?, created_at = NOW()
                  WHERE id = ?'
            );
            $stmt->execute([$rating, $reasonCode, $reasonText, $existingId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE satisfaction_ratings SET rating = ?, created_at = NOW() WHERE id = ?'
            );
            $stmt->execute([$rating, $existingId]);
        }
        _notify_low_rating_if_needed($pdo, $rating, $storeId, $itemName, $reasonText, $existingId);
        json_response(['id' => $existingId]);
    } else {
        // INSERT
        $id = generate_uuid();
        if ($hasReasonCols) {
            $stmt = $pdo->prepare(
                'INSERT INTO satisfaction_ratings (id, store_id, order_id, order_item_id, menu_item_id, item_name, rating, reason_code, reason_text, session_token, table_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $id, $storeId, $orderId, $orderItemId,
                $menuItemId, $itemName ? mb_substr($itemName, 0, 200) : null,
                $rating, $reasonCode, $reasonText,
                $sessionToken, $tableId
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO satisfaction_ratings (id, store_id, order_id, order_item_id, menu_item_id, item_name, rating, session_token, table_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $id, $storeId, $orderId, $orderItemId,
                $menuItemId, $itemName ? mb_substr($itemName, 0, 200) : null,
                $rating, $sessionToken, $tableId
            ]);
        }
        _notify_low_rating_if_needed($pdo, $rating, $storeId, $itemName, $reasonText, $id);
        json_response(['id' => $id], 201);
    }
} catch (PDOException $e) {
    json_error('DB_ERROR', 'evaluation テーブルが未作成の可能性があります', 500);
}

/**
 * PWA Phase 2b: 低評価 (1〜2 の星) を受けたら manager / owner に Web Push を飛ばす。
 * 送信失敗は握りつぶす (お客様への評価レスポンスを止めない)。
 *
 * tag に ratingId を含めることで、同じ manager/owner ユーザーが短時間に別の低評価を
 * 連続で受け取っても、レート制限 (60 秒 / user_id+type+tag) に抑制されないようにする
 * (Phase 2b レビュー指摘 #3)。同じ評価を UPDATE した場合のみ同じ tag になり抑制される。
 */
function _notify_low_rating_if_needed($pdo, $rating, $storeId, $itemName, $reasonText, $ratingId)
{
    if ($rating > 2) return;
    try {
        $body = '★' . $rating . ' 低評価が入りました';
        if ($itemName) $body .= ' / ' . mb_substr($itemName, 0, 40);
        if ($reasonText) $body .= ' / ' . mb_substr($reasonText, 0, 40);
        push_send_to_roles($pdo, $storeId, ['manager', 'owner'], 'low_rating', [
            'title' => '満足度: 低評価',
            'body'  => $body,
            'url'   => '/public/admin/dashboard.html',
            'tag'   => 'low_rating_' . $ratingId,
        ]);
    } catch (\Throwable $e) {
        // 業務処理を止めない
    }
}
