<?php
/**
 * Web Push Message Encryption (RFC 8291 aes128gcm) — 純 PHP 実装
 *
 * PWA Phase 2b / 2026-04-20
 *
 * 要件: PHP 8.2 + openssl + gmp + hash_hmac (HKDF 用)
 * 外部ライブラリ不使用 (Composer なしで運用するため)。
 *
 * 入力: subscription の p256dh (公開鍵 raw 65 バイト Base64URL) と auth (秘密鍵 raw Base64URL),
 *      payload (UTF-8 string)
 * 出力: aes128gcm 形式の暗号化バイナリ (Content-Encoding: aes128gcm でそのまま POST する)
 *
 * 暗号化フロー (RFC 8291):
 *   1. ephemeral ECDH P-256 鍵ペア (as_private, as_public) を生成
 *   2. ECDH: ikm = ECDH(as_private, ua_public)  ← UA = User Agent (subscription owner)
 *   3. PRK_key = HKDF-Extract(salt=auth_secret, ikm)
 *   4. key_info = "WebPush: info\0" || ua_public || as_public
 *   5. IKM2 = HKDF-Expand(PRK_key, key_info, 32)
 *   6. salt (random 16 バイト)
 *   7. PRK = HKDF-Extract(salt, IKM2)
 *   8. CEK = HKDF-Expand(PRK, "Content-Encoding: aes128gcm\0", 16)
 *   9. NONCE = HKDF-Expand(PRK, "Content-Encoding: nonce\0", 12)
 *  10. padded_payload = payload || 0x02 || padding (0x00 * n)
 *      ※ 0x02 = "delimiter", 最後のレコードを示す
 *  11. ciphertext = AES-128-GCM(CEK, NONCE, padded_payload, AAD=空)
 *  12. body = salt(16) || rs(4) || idlen(1) || keyid(=as_public 65) || ciphertext
 *
 * 使い方:
 *   require_once __DIR__ . '/web-push-encrypt.php';
 *   $body = web_push_encrypt_payload($payloadJson, $p256dhB64u, $authB64u);
 */

if (!function_exists('_wp_b64u_decode')) {
    function _wp_b64u_decode($str)
    {
        $padded = $str . str_repeat('=', (4 - strlen($str) % 4) % 4);
        return base64_decode(strtr($padded, '-_', '+/'));
    }
}

if (!function_exists('_wp_b64u_encode')) {
    function _wp_b64u_encode($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

if (!function_exists('_wp_pem_from_raw_p256_public')) {
    /**
     * 65 バイト raw P-256 公開鍵 (04 || X || Y) を openssl 用 SubjectPublicKeyInfo PEM に変換。
     */
    function _wp_pem_from_raw_p256_public($raw65)
    {
        if (strlen($raw65) !== 65 || $raw65[0] !== "\x04") {
            throw new \RuntimeException('Invalid P-256 raw public key (length or leading byte)');
        }
        // ASN.1 SubjectPublicKeyInfo prefix for ecPublicKey + prime256v1
        // 30 59 30 13 06 07 2a 86 48 ce 3d 02 01 06 08 2a 86 48 ce 3d 03 01 07 03 42 00 (then 65 bytes)
        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        $der = $prefix . $raw65;
        $b64 = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $b64 . "-----END PUBLIC KEY-----\n";
    }
}

if (!function_exists('_wp_hkdf_extract')) {
    function _wp_hkdf_extract($salt, $ikm)
    {
        // HMAC-SHA256(salt, ikm)
        return hash_hmac('sha256', $ikm, $salt, true);
    }
}

if (!function_exists('_wp_hkdf_expand')) {
    function _wp_hkdf_expand($prk, $info, $length)
    {
        // RFC 5869: T(N) = HMAC(prk, T(N-1) || info || N), output = first L bytes
        $output = '';
        $T = '';
        $i = 1;
        while (strlen($output) < $length) {
            $T = hash_hmac('sha256', $T . $info . chr($i), $prk, true);
            $output .= $T;
            $i++;
        }
        return substr($output, 0, $length);
    }
}

if (!function_exists('_wp_hkdf')) {
    function _wp_hkdf($salt, $ikm, $info, $length)
    {
        $prk = _wp_hkdf_extract($salt, $ikm);
        return _wp_hkdf_expand($prk, $info, $length);
    }
}

if (!function_exists('_wp_generate_ephemeral_p256')) {
    /**
     * 一時 ECDH P-256 鍵ペアを生成する。
     * @return array { 'private_pem' => string, 'public_raw' => 65bytes }
     */
    function _wp_generate_ephemeral_p256()
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC
        ]);
        if ($key === false) {
            throw new \RuntimeException('Failed to generate ephemeral EC key: ' . openssl_error_string());
        }
        $privatePem = '';
        if (!openssl_pkey_export($key, $privatePem)) {
            throw new \RuntimeException('Failed to export private key');
        }
        $details = openssl_pkey_get_details($key);
        if (!$details || empty($details['ec']['x']) || empty($details['ec']['y'])) {
            throw new \RuntimeException('Failed to extract EC details');
        }
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        return [
            'private_pem' => $privatePem,
            'public_raw'  => "\x04" . $x . $y,
        ];
    }
}

if (!function_exists('web_push_encrypt_payload')) {
    /**
     * Web Push aes128gcm 暗号化を行う。
     *
     * @param string $payload      送信したい文字列 (例: JSON)
     * @param string $p256dhB64u   subscription.keys.p256dh (65 バイト raw を Base64URL)
     * @param string $authB64u     subscription.keys.auth (16 バイト raw を Base64URL)
     * @return array {
     *   'body'           => binary 暗号化済みボディ,
     *   'as_public_b64u' => 一時公開鍵 (Crypto-Key ヘッダ等に使う場合用),
     * }
     */
    function web_push_encrypt_payload($payload, $p256dhB64u, $authB64u)
    {
        $uaPublicRaw = _wp_b64u_decode($p256dhB64u);
        $authSecret  = _wp_b64u_decode($authB64u);

        if (strlen($uaPublicRaw) !== 65) {
            throw new \RuntimeException('Invalid p256dh length: ' . strlen($uaPublicRaw));
        }
        if (strlen($authSecret) < 1) {
            throw new \RuntimeException('Invalid auth secret');
        }

        // 1-2. ephemeral 鍵生成 + ECDH
        $eph = _wp_generate_ephemeral_p256();
        $ephPriv = openssl_pkey_get_private($eph['private_pem']);
        if ($ephPriv === false) {
            throw new \RuntimeException('Failed to load ephemeral private key');
        }
        $uaPubPem = _wp_pem_from_raw_p256_public($uaPublicRaw);
        $uaPubKey = openssl_pkey_get_public($uaPubPem);
        if ($uaPubKey === false) {
            throw new \RuntimeException('Failed to load UA public key: ' . openssl_error_string());
        }

        // ECDH 共有秘密 (32 バイト)
        $ikm = openssl_pkey_derive($uaPubKey, $ephPriv, 32);
        if ($ikm === false) {
            throw new \RuntimeException('ECDH derivation failed: ' . openssl_error_string());
        }

        // 3-5. PRK_key + HKDF-Expand で IKM2
        // RFC 8291: key_info = "WebPush: info\0" || ua_public(65) || as_public(65)
        $keyInfo = "WebPush: info\x00" . $uaPublicRaw . $eph['public_raw'];
        $prkKey = _wp_hkdf_extract($authSecret, $ikm);
        $ikm2 = _wp_hkdf_expand($prkKey, $keyInfo, 32);

        // 6-9. 新 salt + CEK + NONCE
        $salt = random_bytes(16);
        $prk = _wp_hkdf_extract($salt, $ikm2);
        $cek = _wp_hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = _wp_hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);

        // 10. padding: payload || 0x02 || (任意のゼロ埋め)
        // 簡易実装: パディングなし (delimiter 0x02 だけ)。最大 record size=4096 内に収まる前提
        $padded = $payload . "\x02";

        // 11. AES-128-GCM
        $tag = '';
        $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('AES-GCM encryption failed: ' . openssl_error_string());
        }
        $ciphertextWithTag = $ciphertext . $tag;

        // 12. body 構築
        // header = salt(16) || rs(4 BE) || idlen(1) || keyid(65)
        $rs = 4096;  // record size
        $rsBin = pack('N', $rs);   // 4 byte big-endian
        $idlen = chr(65);
        $body = $salt . $rsBin . $idlen . $eph['public_raw'] . $ciphertextWithTag;

        return [
            'body' => $body,
            'as_public_b64u' => _wp_b64u_encode($eph['public_raw']),
        ];
    }
}
