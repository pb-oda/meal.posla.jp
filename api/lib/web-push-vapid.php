<?php
/**
 * VAPID JWT (ES256) 署名 — RFC 8292 / 純 PHP 実装
 *
 * PWA Phase 2b / 2026-04-20
 *
 * 用途:
 *   Web Push 送信時の Authorization ヘッダ用 JWT を生成する。
 *   header.payload.signature の3要素 (Base64URL) を "." で連結した文字列を返す。
 *
 * 署名:
 *   - alg = ES256 (ECDSA P-256 + SHA-256)
 *   - openssl_sign が返す DER 形式 ECDSA 署名 (SEQUENCE { INTEGER r, INTEGER s }) を
 *     RFC 7515 §3.4 に従い 64 バイト raw (r:32 || s:32) に変換する
 *
 * 必要な依存: openssl (PHP 8.2 標準)
 *
 * 使い方:
 *   require_once __DIR__ . '/web-push-vapid.php';
 *   $jwt = web_push_vapid_jwt($endpoint, $privatePem, 'mailto:contact@posla.jp');
 *   $authHeader = 'vapid t=' . $jwt . ', k=' . $publicKeyB64u;
 */

if (!function_exists('_vapid_b64u_encode')) {
    function _vapid_b64u_encode($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

if (!function_exists('_vapid_audience_from_endpoint')) {
    /**
     * endpoint URL から aud (origin: scheme://host[:port]) を組み立てる。
     */
    function _vapid_audience_from_endpoint($endpoint)
    {
        $p = parse_url($endpoint);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            throw new \RuntimeException('Invalid push endpoint URL: ' . $endpoint);
        }
        $aud = $p['scheme'] . '://' . $p['host'];
        if (!empty($p['port'])) {
            $aud .= ':' . $p['port'];
        }
        return $aud;
    }
}

if (!function_exists('_vapid_der_to_raw_es256')) {
    /**
     * DER 形式 ECDSA 署名 (SEQUENCE { INTEGER r, INTEGER s }) を
     * 64 バイト raw (r:32 || s:32) に変換する (JWS RFC 7515 §3.4)。
     *
     * DER の INTEGER は先頭ゼロ詰めや符号ビット用 0x00 が付くため、
     * 32 バイトに揃えて連結する必要がある。
     */
    function _vapid_der_to_raw_es256($der)
    {
        $offset = 0;
        $len = strlen($der);

        if ($len < 8 || ord($der[$offset]) !== 0x30) {
            throw new \RuntimeException('Invalid DER signature (no SEQUENCE)');
        }
        $offset++;

        // SEQUENCE 全体長 (短/長形式対応)
        $seqLenByte = ord($der[$offset++]);
        if ($seqLenByte & 0x80) {
            $n = $seqLenByte & 0x7f;
            if ($n > 2 || $offset + $n > $len) {
                throw new \RuntimeException('Invalid DER length encoding');
            }
            $offset += $n;  // SEQUENCE 全体長を読み飛ばす
        }

        // INTEGER r
        if (ord($der[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid DER (expected INTEGER for r)');
        }
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        // INTEGER s
        if (ord($der[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid DER (expected INTEGER for s)');
        }
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        // 先頭の 0x00 (符号ビット用) を除去
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        // 32 バイト固定にゼロ詰め (足りない場合)
        if (strlen($r) > 32 || strlen($s) > 32) {
            throw new \RuntimeException('Invalid ECDSA component length (>32)');
        }
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }
}

if (!function_exists('web_push_vapid_jwt')) {
    /**
     * VAPID JWT を生成する。
     *
     * @param string $endpoint        Push エンドポイント URL (subscription.endpoint)
     * @param string $privatePem      VAPID 秘密鍵 PEM (posla_settings.web_push_vapid_private_pem)
     * @param string $subject         contact (例: 'mailto:contact@posla.jp')
     * @param int    $ttlSec          JWT 有効期限 (秒)。デフォルト 12 時間。最大 24 時間 (RFC 8292)
     * @return string                 JWT (header.payload.signature)
     */
    function web_push_vapid_jwt($endpoint, $privatePem, $subject = 'mailto:contact@posla.jp', $ttlSec = 43200)
    {
        if ($ttlSec > 86400) $ttlSec = 86400;  // RFC 8292: 最大 24h
        if ($ttlSec < 60) $ttlSec = 60;

        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $payload = [
            'aud' => _vapid_audience_from_endpoint($endpoint),
            'exp' => time() + $ttlSec,
            'sub' => $subject,
        ];

        $headerB64 = _vapid_b64u_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = _vapid_b64u_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = $headerB64 . '.' . $payloadB64;

        // ECDSA P-256 SHA-256 署名
        $privKey = openssl_pkey_get_private($privatePem);
        if ($privKey === false) {
            throw new \RuntimeException('Failed to load VAPID private key: ' . openssl_error_string());
        }

        $derSig = '';
        $ok = openssl_sign($signingInput, $derSig, $privKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('openssl_sign failed: ' . openssl_error_string());
        }

        // DER → raw 64 バイト (r||s)
        $rawSig = _vapid_der_to_raw_es256($derSig);
        $sigB64 = _vapid_b64u_encode($rawSig);

        return $signingInput . '.' . $sigB64;
    }
}

if (!function_exists('web_push_vapid_auth_header')) {
    /**
     * Web Push 用 Authorization ヘッダを構築する (vapid scheme)。
     *
     * 形式: "vapid t=<JWT>, k=<public_key_b64u>"
     *
     * @param string $endpoint        Push エンドポイント URL
     * @param string $privatePem      VAPID 秘密鍵 PEM
     * @param string $publicKeyB64u   VAPID 公開鍵 (Base64URL, 65 バイト raw P-256)
     * @param string $subject         contact (mailto: または https:)
     * @param int    $ttlSec          JWT 有効期限 (秒)
     * @return string                 "vapid t=..., k=..."
     */
    function web_push_vapid_auth_header($endpoint, $privatePem, $publicKeyB64u, $subject = 'mailto:contact@posla.jp', $ttlSec = 43200)
    {
        $jwt = web_push_vapid_jwt($endpoint, $privatePem, $subject, $ttlSec);
        return 'vapid t=' . $jwt . ', k=' . $publicKeyB64u;
    }
}
