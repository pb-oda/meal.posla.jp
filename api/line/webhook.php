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
require_once __DIR__ . '/../lib/line-link-token.php';
require_once __DIR__ . '/../lib/line-messaging.php';

require_method(['POST']);
$pdo = get_db();

/**
 * LINE webhook の events 配列を分岐処理 (L-17 Phase 2A-2)
 *
 * Phase 2A-2 で実挙動を実装:
 *   - message     : body に "LINK:XXXXXX" (case insensitive) があれば token 消費
 *                   + line_link_upsert() + 「連携完了」reply。
 *                   該当しない場合は既リンク user の last_interaction_at を touch。
 *   - follow      : 案内 reply (「ご予約番号を取得後、リンクコードを送ってください」)
 *   - unfollow    : line_link_unlink_by_line_user() で soft unlink (履歴保持)
 *   - accountLink : official LINE Login 経路 (現状未採用、nonce 検証 skeleton のみ)
 *   - それ以外    : ignore
 *
 * 予約 / 注文 / 会計処理は呼ばない。処理失敗で webhook 全体を落とさない。
 * LINE reply 失敗は error_log にのみ出し、webhook 応答は 2xx を維持する
 * (LINE 側は 2xx 以外で retry するため)。
 */
function line_dispatch_events($pdo, $tenantId, $channelAccessToken, $events)
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
        $replyToken = isset($ev['replyToken']) && is_string($ev['replyToken']) ? $ev['replyToken'] : '';
        if ($lineUserId === '') {
            continue;
        }
        try {
            if ($type === 'message') {
                $msg = isset($ev['message']) && is_array($ev['message']) ? $ev['message'] : [];
                $msgType = isset($msg['type']) ? $msg['type'] : '';
                $text = ($msgType === 'text' && isset($msg['text'])) ? (string)$msg['text'] : '';

                // "LINK:XXXXXX" 形式 (case-insensitive、先頭空白許容) を検出
                $linkCode = '';
                if ($text !== '' && preg_match('/^\s*link\s*[:：]\s*([A-Za-z0-9]{4,16})\s*$/iu', $text, $m)) {
                    $linkCode = strtoupper($m[1]);
                }

                if ($linkCode !== '') {
                    $res = line_link_token_consume($pdo, $tenantId, $linkCode, $lineUserId);
                    if ($res['success']) {
                        // reply: 連携完了
                        if ($channelAccessToken && $replyToken) {
                            line_reply_message(
                                $channelAccessToken,
                                $replyToken,
                                [line_text_message("LINE連携が完了しました。\nこれからはご予約の確定などをこちらにお知らせします。")]
                            );
                        }
                    } else {
                        // reply: エラー内容に応じた簡潔なメッセージ
                        $errMap = [
                            'TOKEN_NOT_FOUND'    => 'リンクコードが見つかりませんでした。店舗から届いたコードをご確認ください。',
                            'TOKEN_ALREADY_USED' => 'このリンクコードは既に使用済みです。',
                            'TOKEN_REVOKED'      => 'このリンクコードは無効化されています。',
                            'TOKEN_EXPIRED'      => 'このリンクコードは有効期限が切れています。新しいコードを店舗にお問い合わせください。',
                        ];
                        $msgText = isset($errMap[$res['error']])
                            ? $errMap[$res['error']]
                            : 'リンクに失敗しました。お手数ですが店舗にご確認ください。';
                        if ($channelAccessToken && $replyToken) {
                            line_reply_message(
                                $channelAccessToken,
                                $replyToken,
                                [line_text_message($msgText)]
                            );
                        }
                    }
                } else {
                    // 通常メッセージ: 既リンクなら interaction touch
                    line_link_touch_interaction($pdo, $tenantId, $lineUserId);
                }
            } elseif ($type === 'follow') {
                if ($channelAccessToken && $replyToken) {
                    $text = "友だち追加ありがとうございます！\n"
                          . "ご予約の確定などをこちらにお知らせできるよう、店舗から届くリンクコード "
                          . "(例: LINK:XXXXXX) をこのトークに送信してください。";
                    line_reply_message(
                        $channelAccessToken,
                        $replyToken,
                        [line_text_message($text)]
                    );
                }
            } elseif ($type === 'unfollow') {
                // soft unlink (履歴は link_status='unlinked' で残す、再 follow 時に再リンク可能)
                line_link_unlink_by_line_user($pdo, $tenantId, $lineUserId);
            } elseif ($type === 'accountLink') {
                // official LINE Account Linking の nonce 検証経路 (現状未採用)
                // Phase 2A-2 採用: POSLA one-time token (message 経路) で完結
            }
        } catch (Exception $e) {
            // dispatch エラーで webhook 全体を 500 にしない
            error_log('[L-17 2A-2 dispatch] ' . $type . ': ' . $e->getMessage());
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
    'SELECT t.id AS tenant_id, t.slug, tls.channel_secret, tls.channel_access_token, tls.is_enabled
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

// L-17 Phase 2A-2: イベント分岐 (Phase 1 の last_webhook_* 更新後に実施、
// dispatch 失敗で webhook 応答を落とさない)
// is_enabled=0 時は follow 返信 / LINK 消費 / unfollow unlink を行わない。
// ただし signature 検証と last_webhook_* 更新は続行し、owner が設定途中でも
// webhook 到達をデバッグできるようにする。
$channelAccessToken = isset($row['channel_access_token']) ? (string)$row['channel_access_token'] : '';
$isEnabled = (int)($row['is_enabled'] ?? 0);
if ($isEnabled === 1) {
    line_dispatch_events($pdo, $row['tenant_id'], $channelAccessToken, $events);
}

json_response([
    'received'     => true,
    'tenant_slug'  => $row['slug'],
    'event_count'  => count($events),
    'first_type'   => $firstType,
]);
