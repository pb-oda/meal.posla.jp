<?php
/**
 * VAPID 鍵生成 CLI スクリプト (PWA Phase 2b / 2026-04-20)
 *
 * 使い方:
 *   php scripts/generate-vapid-keys.php
 *
 * 出力 (stdout):
 *   - web_push_vapid_public  : Base64URL 公開鍵 (87 chars / 65 バイト uncompressed P-256)
 *   - web_push_vapid_private_pem : EC PRIVATE KEY PEM
 *   - サーバへ投入する SQL 文
 *
 * 投入手順:
 *   1. このスクリプトを実行し、出力された SQL をコピー
 *   2. mysql クライアントで posla_settings に INSERT
 *   3. /api/push/config.php の available が true になることを確認
 *
 * 注意:
 *   - 1 回生成したら使い続ける (再生成すると既存購読が無効化される — 公開鍵が変わるため)
 *   - 秘密鍵は posla_settings.web_push_vapid_private_pem に保存。
 *     api/posla/settings.php の _public_setting_keys() 許可リストには絶対に追加しない
 *   - openssl 拡張が必要 (PHP 8.2 標準)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is for CLI execution only.\n";
    exit(1);
}

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "ERROR: openssl extension is required.\n");
    exit(1);
}

// P-256 (secp256r1 / prime256v1) で EC 鍵ペアを生成
$key = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC
]);

if ($key === false) {
    fwrite(STDERR, "ERROR: openssl_pkey_new failed.\n");
    while ($e = openssl_error_string()) fwrite(STDERR, "  " . $e . "\n");
    exit(1);
}

// 秘密鍵 PEM を取得
$privatePem = '';
if (!openssl_pkey_export($key, $privatePem)) {
    fwrite(STDERR, "ERROR: openssl_pkey_export failed.\n");
    exit(1);
}

// 公開鍵を取り出して uncompressed point (04 || X || Y) を作る
$details = openssl_pkey_get_details($key);
if (!$details || empty($details['ec']['x']) || empty($details['ec']['y'])) {
    fwrite(STDERR, "ERROR: openssl_pkey_get_details failed (EC details missing).\n");
    exit(1);
}

// X / Y は 32 バイト固定 (左ゼロ詰め)
$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$rawPublic = "\x04" . $x . $y;   // 65 バイト uncompressed

if (strlen($rawPublic) !== 65) {
    fwrite(STDERR, "ERROR: raw public key length is " . strlen($rawPublic) . " (expected 65).\n");
    exit(1);
}

// Base64URL エンコード (= なし)
function _b64u($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$publicB64u = _b64u($rawPublic);

echo "=== POSLA VAPID 鍵生成 完了 ===\n\n";
echo "[公開鍵 / Base64URL / " . strlen($publicB64u) . " 文字]\n";
echo $publicB64u . "\n\n";
echo "[秘密鍵 / PEM]\n";
echo $privatePem . "\n";

echo "\n=== 投入 SQL (コピーしてサーバーで実行) ===\n\n";

// SQL 文字列内の特殊文字をエスケープ
$pubSql = addslashes($publicB64u);
$privSql = addslashes($privatePem);

echo "INSERT INTO posla_settings (setting_key, setting_value) VALUES\n";
echo "  ('web_push_vapid_public', '" . $pubSql . "'),\n";
echo "  ('web_push_vapid_private_pem', '" . $privSql . "')\n";
echo "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);\n\n";

echo "=== 検証コマンド ===\n";
echo "mysql -h <host> -u <user> -p <db> -e \\\n";
echo "  \"SELECT setting_key, LENGTH(setting_value) AS len FROM posla_settings WHERE setting_key LIKE 'web_push_vapid%';\"\n\n";

echo "=== 注意 ===\n";
echo "- 1 回生成したら使い続ける (再生成 = 全購読無効化)\n";
echo "- 秘密鍵は posla_settings 内のみ。api/posla/settings.php の _public_setting_keys() に追加しない\n";
echo "- 投入後、フロントの「通知: サーバー未設定」が「通知: 未設定 (タップで許可)」に変わる\n";
