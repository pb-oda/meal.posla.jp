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

require_method(['POST']);
$pdo = get_db();

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

json_response([
    'received'     => true,
    'tenant_slug'  => $row['slug'],
    'event_count'  => count($events),
    'first_type'   => $firstType,
]);
