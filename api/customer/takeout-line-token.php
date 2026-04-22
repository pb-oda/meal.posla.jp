<?php
/**
 * L-17 Phase 3A: テイクアウト LINE ひも付け one-time token (customer API)
 *
 * POST /api/customer/takeout-line-token.php
 *   body: { order_id, phone }
 *   -> { token, expires_at, tenant_slug, store_name }
 *
 * 顧客自身が takeout 完了画面 / 注文照会画面から token を発行するための
 * customer-scoped API。既存 takeout status 照会と同じ (order_id, phone) 照合
 * で本人確認する (takeout-orders.php?action=status と同等の threat model)。
 *
 * 発行成功時は顧客が "LINK:XXXXXX" を店舗 LINE OA に送信すると、
 * webhook.php の message 分岐が token を消費して link を作る。
 *
 * 安全策:
 *   - 電話番号だけでは権限なし (order_id + phone の照合必須)
 *   - active token が既に存在する場合は再発行せず既存を返す (spam 防止)
 *   - rate-limit: IP + order_id で連打を抑止
 *   - tenant_line_settings.is_enabled=0 の店舗では発行しない
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/takeout-line-token.php';

require_method(['POST']);
$pdo = get_db();

$data = get_json_body();
$orderId = isset($data['order_id']) ? trim((string)$data['order_id']) : '';
$phone   = isset($data['phone'])    ? trim((string)$data['phone'])    : '';

if ($orderId === '') json_error('MISSING_ORDER', 'order_id が必要です', 400);
if ($phone === '')   json_error('MISSING_PHONE', 'phone が必要です', 400);

// rate-limit (IP + order_id で 10 分あたり 5 回まで)
$rlKey = 'takeout-line-token:' . $orderId;
if (!check_rate_limit($rlKey, 5, 600)) {
    json_error('RATE_LIMIT', 'しばらく時間をおいて再度お試しください', 429);
}

// 注文本人確認 (既存 takeout status と同じ pattern)
$stmt = $pdo->prepare(
    "SELECT o.id, o.store_id, o.status, o.customer_phone, o.customer_name, o.pickup_at,
            s.tenant_id, s.name AS store_name, t.slug AS tenant_slug
       FROM orders o
       JOIN stores s  ON s.id = o.store_id
       JOIN tenants t ON t.id = s.tenant_id
      WHERE o.id = ? AND o.customer_phone = ? AND o.order_type = 'takeout'"
);
$stmt->execute([$orderId, $phone]);
$order = $stmt->fetch();
if (!$order) {
    json_error('NOT_FOUND', '注文が見つかりません', 404);
}

// 終端 status (served / paid / cancelled) では発行しない
$terminalStatuses = ['served', 'paid', 'cancelled'];
if (in_array($order['status'], $terminalStatuses, true)) {
    json_error('ORDER_TERMINAL', 'この注文はすでに完了しているため、LINE連携は利用できません', 400);
}

// tenant_line_settings が有効か確認 (is_enabled=0 / token 未設定なら発行しない)
try {
    $stmt = $pdo->prepare(
        'SELECT channel_access_token, is_enabled, notify_takeout_ready
           FROM tenant_line_settings WHERE tenant_id = ?'
    );
    $stmt->execute([$order['tenant_id']]);
    $settings = $stmt->fetch();
} catch (PDOException $e) {
    json_error('LINE_NOT_CONFIGURED', 'この店舗では LINE 連携が有効化されていません', 400);
}
if (!$settings || (int)$settings['is_enabled'] !== 1 || empty($settings['channel_access_token'])) {
    json_error('LINE_NOT_CONFIGURED', 'この店舗では LINE 連携が有効化されていません', 400);
}

// migration 未適用の場合は controlled error
if (!takeout_token_table_exists($pdo)) {
    json_error('NOT_CONFIGURED', 'LINE連携用マイグレーション (Phase 3A) が未適用です', 503);
}

try {
    $tokenRow = takeout_token_issue(
        $pdo,
        $order['tenant_id'],
        $order['store_id'],
        $order['id'],
        $phone,
        30
    );
} catch (Exception $e) {
    error_log('[L-17 3A customer_token_issue] ' . $e->getMessage());
    json_error('TOKEN_ISSUE_FAILED', 'トークン発行に失敗しました', 500);
}
if (!$tokenRow) {
    json_error('TOKEN_ISSUE_FAILED', 'トークン発行に失敗しました (衝突が続きました)', 500);
}

json_response([
    'token'            => $tokenRow['token'],
    'expires_at'       => $tokenRow['expires_at'],
    'issued_at'        => $tokenRow['issued_at'],
    'tenant_slug'      => $order['tenant_slug'],
    'store_name'       => $order['store_name'],
    'takeout_ready_enabled' => (int)$settings['notify_takeout_ready'],
]);
