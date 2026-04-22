<?php
/**
 * LINE Messaging API 薄ラッパ (L-17 Phase 2B-1)
 *
 * - push API: 特定 userId にプッシュ送信
 * - reply API: webhook で受信した replyToken に返信
 *
 * 呼び出し側は例外ではなく ['success'=>bool, 'http_status'=>int, 'error'=>string|null]
 * を受け取り、送信失敗時も caller (reservation-notifier.php / webhook.php) が
 * そのまま処理を続けられる設計。LINE 側は 200 系以外で retry するため、webhook
 * 応答を壊さないよう reply 側の失敗は error_log に留める。
 *
 * 実 LINE E2E 試験は channel_access_token が必要。token 未設定時は
 * caller 側で呼び出しをスキップすること (line_push_message は INVALID_TOKEN
 * を返すだけで破壊的な動作はしない)。
 */

if (!function_exists('line_messaging_post_json')) {
    function line_messaging_post_json($url, $headers, $body)
    {
        if (!function_exists('curl_init')) {
            return [
                'success'     => false,
                'http_status' => 0,
                'error'       => 'CURL_UNAVAILABLE',
                'response'    => null,
            ];
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return [
                'success'     => false,
                'http_status' => 0,
                'error'       => 'CURL_FAILED: ' . ($err ?: 'unknown'),
                'response'    => null,
            ];
        }
        $success = ($code >= 200 && $code < 300);
        $error = null;
        if (!$success) {
            $decoded = json_decode($resp, true);
            if (is_array($decoded) && isset($decoded['message'])) {
                $error = 'LINE_API_ERROR: ' . substr((string)$decoded['message'], 0, 200);
            } else {
                $error = 'LINE_API_HTTP_' . $code;
            }
        }
        return [
            'success'     => $success,
            'http_status' => $code,
            'error'       => $error,
            'response'    => $resp,
        ];
    }
}

if (!function_exists('line_push_message')) {
    /**
     * LINE user に対して push 送信。
     *
     * @param string $channelAccessToken tenant_line_settings.channel_access_token
     * @param string $lineUserId         送信先 LINE user ID
     * @param array  $messages           LINE messages array (max 5)
     * @return array ['success','http_status','error','response']
     */
    function line_push_message($channelAccessToken, $lineUserId, $messages)
    {
        if (!is_string($channelAccessToken) || trim($channelAccessToken) === '') {
            return ['success' => false, 'http_status' => 0, 'error' => 'INVALID_TOKEN', 'response' => null];
        }
        if (!is_string($lineUserId) || trim($lineUserId) === '') {
            return ['success' => false, 'http_status' => 0, 'error' => 'INVALID_USER_ID', 'response' => null];
        }
        if (!is_array($messages) || count($messages) === 0) {
            return ['success' => false, 'http_status' => 0, 'error' => 'INVALID_MESSAGES', 'response' => null];
        }
        $payload = json_encode([
            'to'       => $lineUserId,
            'messages' => $messages,
        ], JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channelAccessToken,
        ];
        return line_messaging_post_json('https://api.line.me/v2/bot/message/push', $headers, $payload);
    }
}

if (!function_exists('line_reply_message')) {
    /**
     * LINE webhook で受信した replyToken に返信。
     * 返信に失敗しても caller (webhook.php) は続行する。
     */
    function line_reply_message($channelAccessToken, $replyToken, $messages)
    {
        if (!is_string($channelAccessToken) || trim($channelAccessToken) === '') {
            return ['success' => false, 'http_status' => 0, 'error' => 'INVALID_TOKEN', 'response' => null];
        }
        if (!is_string($replyToken) || trim($replyToken) === '') {
            return ['success' => false, 'http_status' => 0, 'error' => 'INVALID_REPLY_TOKEN', 'response' => null];
        }
        if (!is_array($messages) || count($messages) === 0) {
            return ['success' => false, 'http_status' => 0, 'error' => 'INVALID_MESSAGES', 'response' => null];
        }
        $payload = json_encode([
            'replyToken' => $replyToken,
            'messages'   => $messages,
        ], JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channelAccessToken,
        ];
        return line_messaging_post_json('https://api.line.me/v2/bot/message/reply', $headers, $payload);
    }
}

if (!function_exists('line_text_message')) {
    /**
     * text-only LINE message 1 件を作るヘルパー
     */
    function line_text_message($text)
    {
        return [
            'type' => 'text',
            'text' => (string)$text,
        ];
    }
}
