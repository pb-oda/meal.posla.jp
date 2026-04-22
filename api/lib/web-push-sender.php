<?php
/**
 * Web Push 送信 — cURL POST + 410/404 ハンドリング
 *
 * PWA Phase 2b / 2026-04-20
 *
 * 役割:
 *   - 暗号化済みボディ + VAPID Authorization ヘッダで push エンドポイントへ POST
 *   - 410 Gone / 404 Not Found を受けたら push_subscriptions の購読を soft delete
 *   - 失敗してもキャッチして上位に返す (業務 API を巻き込まない fail-open)
 *
 * 依存:
 *   - api/lib/web-push-encrypt.php (web_push_encrypt_payload)
 *   - api/lib/web-push-vapid.php   (web_push_vapid_auth_header)
 *   - cURL extension
 *
 * 使い方:
 *   require_once __DIR__ . '/web-push-sender.php';
 *   $result = web_push_send_one($pdo, $subscriptionRow, $payloadJson, $vapidPublicB64u, $vapidPrivatePem);
 *   // $result = ['ok' => bool, 'status' => int, 'reason' => string]
 */

require_once __DIR__ . '/web-push-encrypt.php';
require_once __DIR__ . '/web-push-vapid.php';

if (!function_exists('_web_push_disable_by_endpoint_hash')) {
    /**
     * push_subscriptions を endpoint_hash で soft delete する。
     * 410/404 を受けた購読を即座に無効化するために使う。
     */
    function _web_push_disable_by_endpoint_hash($pdo, $endpointHash, $reason)
    {
        $stmt = $pdo->prepare(
            'UPDATE push_subscriptions
                SET enabled = 0, revoked_at = NOW()
              WHERE endpoint_hash = ? AND enabled = 1'
        );
        $stmt->execute([$endpointHash]);
    }
}

if (!function_exists('_web_push_insert_send_log')) {
    /**
     * push_send_log に 1 行 INSERT する (Phase 2b レビュー対応: 送信監査 + レート制限).
     *
     * 失敗しても業務処理を巻き込まないよう try/catch で握りつぶし。
     * push_send_log テーブル未作成環境でも沈黙で無視する (migration-pwa2b-push-log.sql 未適用時)。
     *
     * PII 方針: title / body はログに残さない。type と固定ラベル tag のみ。
     *
     * @param PDO    $pdo
     * @param array  $sub         push_subscriptions の 1 行 (id/tenant_id/store_id/user_id 含む想定)
     * @param string $type        'call_staff' / 'call_alert' / 'low_rating' / 'important_error' / 'push_test'
     * @param string|null $tag    固定ラベル (payload.tag と同じ)
     * @param int|null $statusCode  HTTP ステータス (0=送信前失敗)
     * @param string $reason      'sent' / 'gone_disabled' / 'transient_failure' / 'encrypt_or_sign_failed' 等
     */
    function _web_push_insert_send_log($pdo, $sub, $type, $tag, $statusCode, $reason)
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO push_send_log
                   (id, tenant_id, store_id, subscription_id, user_id, type, tag, status_code, reason, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                bin2hex(random_bytes(18)),
                isset($sub['tenant_id']) ? $sub['tenant_id'] : null,
                isset($sub['store_id']) ? $sub['store_id'] : null,
                isset($sub['id']) ? $sub['id'] : null,
                isset($sub['user_id']) ? $sub['user_id'] : null,
                mb_substr((string)$type, 0, 40),
                ($tag !== null && $tag !== '') ? mb_substr((string)$tag, 0, 100) : null,
                ($statusCode !== null) ? (int)$statusCode : null,
                mb_substr((string)$reason, 0, 60),
            ]);
        } catch (\Throwable $e) {
            // push_send_log 未作成 / INSERT 失敗 → 業務処理を止めない (fail-open)
        }
    }
}

if (!function_exists('web_push_send_one')) {
    /**
     * 1 件の購読に対して Web Push を送信する。
     *
     * @param PDO    $pdo
     * @param array  $sub              push_subscriptions の 1 行 (endpoint, p256dh, auth_key, endpoint_hash 必須)
     * @param string $payloadJson      送信ペイロード (UTF-8 文字列)
     * @param string $vapidPublicB64u  VAPID 公開鍵 (Base64URL)
     * @param string $vapidPrivatePem  VAPID 秘密鍵 (PEM)
     * @param string $subject          VAPID contact (mailto: 等)
     * @return array { ok: bool, status: int, reason: string }
     */
    function web_push_send_one($pdo, $sub, $payloadJson, $vapidPublicB64u, $vapidPrivatePem, $subject = 'mailto:contact@posla.jp', $type = '', $tag = null)
    {
        if (empty($sub['endpoint']) || empty($sub['p256dh']) || empty($sub['auth_key'])) {
            $r = ['ok' => false, 'status' => 0, 'reason' => 'invalid_subscription'];
            _web_push_insert_send_log($pdo, $sub, $type, $tag, $r['status'], $r['reason']);
            return $r;
        }

        try {
            // 1. ペイロード暗号化 (RFC 8291 aes128gcm)
            $enc = web_push_encrypt_payload($payloadJson, $sub['p256dh'], $sub['auth_key']);
            $body = $enc['body'];

            // 2. VAPID Authorization ヘッダ生成
            $authHeader = web_push_vapid_auth_header(
                $sub['endpoint'],
                $vapidPrivatePem,
                $vapidPublicB64u,
                $subject,
                43200
            );
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'status' => 0, 'reason' => 'encrypt_or_sign_failed: ' . $e->getMessage()];
            _web_push_insert_send_log($pdo, $sub, $type, $tag, $r['status'], $r['reason']);
            return $r;
        }

        // 3. cURL POST
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authHeader,
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400',                // 24 時間配信猶予
                'Urgency: normal',           // RFC 8030 §5.3
                'Content-Length: ' . strlen($body),
            ],
        ]);

        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $r = ['ok' => false, 'status' => 0, 'reason' => 'curl_error: ' . mb_substr($curlErr, 0, 40)];
            _web_push_insert_send_log($pdo, $sub, $type, $tag, $r['status'], $r['reason']);
            return $r;
        }

        // 4. ステータス判定
        if ($status >= 200 && $status < 300) {
            // 201 Created / 200 OK / 202 Accepted = 成功
            $r = ['ok' => true, 'status' => $status, 'reason' => 'sent'];
        } elseif ($status === 410 || $status === 404) {
            // 購読失効 → soft delete
            if (!empty($sub['endpoint_hash'])) {
                _web_push_disable_by_endpoint_hash($pdo, $sub['endpoint_hash'], 'http_' . $status);
            }
            $r = ['ok' => false, 'status' => $status, 'reason' => 'gone_disabled'];
        } elseif ($status === 413) {
            $r = ['ok' => false, 'status' => $status, 'reason' => 'payload_too_large'];
        } elseif ($status === 429 || $status >= 500) {
            // 一時失敗 (購読は残す)
            $r = ['ok' => false, 'status' => $status, 'reason' => 'transient_failure'];
        } else {
            // 401/403 = VAPID 失敗 等
            $r = ['ok' => false, 'status' => $status, 'reason' => 'http_error'];
        }

        _web_push_insert_send_log($pdo, $sub, $type, $tag, $r['status'], $r['reason']);
        return $r;
    }
}

if (!function_exists('web_push_send_batch')) {
    /**
     * 購読リストに対してまとめて送信する。
     * 1 件ごとに同期送信 (cURL multi は使わない: 件数が少ない前提 + シンプル優先)。
     *
     * @param PDO    $pdo
     * @param array  $subs             push_subscriptions の行配列
     * @param array  $payloadArr       通知ペイロード (json_encode 前の連想配列)
     * @param string $vapidPublicB64u
     * @param string $vapidPrivatePem
     * @param string $subject
     * @return array { sent: int, failed: int, disabled: int, results: [] }
     */
    function web_push_send_batch($pdo, $subs, $payloadArr, $vapidPublicB64u, $vapidPrivatePem, $subject = 'mailto:contact@posla.jp', $type = '', $tag = null)
    {
        $payloadJson = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return ['sent' => 0, 'failed' => 0, 'disabled' => 0, 'results' => [], 'error' => 'json_encode_failed'];
        }
        if ($tag === null && isset($payloadArr['tag'])) {
            $tag = (string)$payloadArr['tag'];
        }

        $sent = 0;
        $failed = 0;
        $disabled = 0;
        $results = [];

        foreach ($subs as $sub) {
            $r = web_push_send_one($pdo, $sub, $payloadJson, $vapidPublicB64u, $vapidPrivatePem, $subject, $type, $tag);
            if ($r['ok']) {
                $sent++;
            } else {
                $failed++;
                if ($r['reason'] === 'gone_disabled') $disabled++;
            }
            $results[] = [
                'sub_id' => isset($sub['id']) ? $sub['id'] : null,
                'status' => $r['status'],
                'reason' => $r['reason'],
            ];
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'disabled' => $disabled,
            'results' => $results,
        ];
    }
}
