<?php
/**
 * tenant 単位 LINE webhook 受信口（Phase 1 土台）
 *
 * POST /api/line/webhook.php?tenant={tenant_slug}
 *
 * Phase 1 では署名検証と受信記録のみ行い、
 * 予約/注文への副作用はまだ発生させない。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/line-link.php';

require_method(['POST']);
$pdo = get_db();

/**
 * LINE webhook の events 配列を分岐処理 (L-17 Phase 2A-1 スケルトン)
 *
 * Phase 2A-1 では下記方針:
 *   - message     : 既リンク user の last_interaction_at を touch (0 行でも安全)
 *   - follow      : no-op (Phase 2A-2 で account linking token 発行)
 *   - unfollow    : no-op (Phase 2A-2 で link_status='unlinked' に)
 *   - accountLink : no-op (Phase 2A-2 で token 検証 + upsert)
 *   - それ以外    : ignore
 *
 * 予約 / 注文 / 会計処理は呼ばない。処理失敗で webhook 全体を落とさない。
 */
function line_dispatch_events($pdo, $tenantId, $events)
{
    if (!is_array($events) || count($events) === 0) {
        return;
    }
    for ($i = 0; $i < count($events); $i++) {
        $ev = $events[$i];
        if (!is_array($ev)) continue;
        $type = isset($ev['type']) && is_string($ev['type']) ? $ev['type'] : '';
        $source = isset($ev['source']) && is_array($ev['source']) ? $ev['source'] : [];
        $lineUserId = isset($source['userId']) && is_string($source['userId']) ? $source['userId'] : '';
        if ($lineUserId === '') {
            continue;
        }
        try {
            if ($type === 'message') {
                // 既リンクなら最終 interaction を更新。未リンクは 0 行で安全
                line_link_touch_interaction($pdo, $tenantId, $lineUserId);
            } elseif ($type === 'follow') {
                // Phase 2A-2: one-time token を生成して LINE 側にメッセージ送信
                // (予約登録時の phone/email と紐付ける経路)。現時点は no-op。
            } elseif ($type === 'unfollow') {
                // Phase 2A-2: link_status='unlinked' に遷移させる。現時点は no-op
                // (誤解除の副作用を避けるため段階適用)。
            } elseif ($type === 'accountLink') {
                // Phase 2A-2: nonce 検証 + reservation_customers と upsert。現時点は no-op。
            }
        } catch (Exception $e) {
            // Phase 2A-1: dispatch エラーで webhook 全体を 500 にしない
            // (LINE 側は 2xx 以外で retry するため影響大)
            error_log('[L-17 2A-1 dispatch] ' . $type . ': ' . $e->getMessage());
        }
    }
}

function line_settings_table_exists_webhook($pdo)
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $pdo->query('SELECT tenant_id FROM tenant_line_settings LIMIT 0');
        $exists = true;
    } catch (PDOException $e) {
        $exists = false;
    }
    return $exists;
}

function line_verify_signature($secret, $body, $signature)
{
    if ($secret === '' || $signature === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
    return hash_equals($expected, $signature);
}

if (!line_settings_table_exists_webhook($pdo)) {
    json_error('NOT_CONFIGURED', 'LINE連携用マイグレーションが未適用です', 503);
}

$tenantSlug = trim((string)($_GET['tenant'] ?? ''));
if ($tenantSlug === '') {
    json_error('MISSING_TENANT', 'tenant は必須です', 400);
}

$stmt = $pdo->prepare(
    'SELECT t.id AS tenant_id, t.slug, tls.channel_secret, tls.is_enabled
       FROM tenants t
       LEFT JOIN tenant_line_settings tls ON tls.tenant_id = t.id
      WHERE t.slug = ? AND t.is_active = 1'
);
$stmt->execute([$tenantSlug]);
$row = $stmt->fetch();

if (!$row) {
    json_error('NOT_FOUND', 'テナントが見つかりません', 404);
}

if (empty($row['channel_secret'])) {
    json_error('NOT_CONFIGURED', 'LINE Channel Secret が設定されていません', 400);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    json_error('EMPTY_BODY', 'リクエストボディが空です', 400);
}

$signature = trim((string)($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? ''));
if (!line_verify_signature((string)$row['channel_secret'], $rawBody, $signature)) {
    json_error('INVALID_SIGNATURE', 'Webhook署名の検証に失敗しました', 400);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    json_error('INVALID_JSON', 'リクエストのJSONが不正です', 400);
}

$events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [];
$firstType = null;
if (!empty($events) && isset($events[0]['type']) && is_string($events[0]['type'])) {
    $firstType = substr($events[0]['type'], 0, 50);
}

$pdo->prepare(
    'UPDATE tenant_line_settings
        SET last_webhook_at = NOW(),
            last_webhook_event_type = ?,
            last_webhook_event_count = ?
      WHERE tenant_id = ?'
)->execute([
    $firstType,
    count($events),
    $row['tenant_id'],
]);

// L-17 Phase 2A-1: イベント分岐 (Phase 1 の last_webhook_* 更新後に実施、
// dispatch 失敗で webhook 応答を落とさない)
line_dispatch_events($pdo, $row['tenant_id'], $events);

json_response([
    'received'     => true,
    'tenant_slug'  => $row['slug'],
    'event_count'  => count($events),
    'first_type'   => $firstType,
]);
