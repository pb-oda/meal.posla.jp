<?php
/**
 * POSLA管理画面からOPへ入るための短時間launch token発行。
 *
 * secret値はレスポンス/監査ログに出さない。
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/../lib/posla-settings.php';

function posla_op_launch_secret(): string
{
    $secret = getenv('POSLA_OP_LAUNCH_SECRET');
    if ($secret === false || trim((string)$secret) === '') {
        $secret = getenv('POSLA_OPS_LAUNCH_SECRET');
    }
    return trim((string)($secret ?: ''));
}

function posla_op_launch_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function posla_op_launch_token(array $payload, string $secret): string
{
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT',
    ];
    $encodedHeader = posla_op_launch_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $encodedPayload = posla_op_launch_base64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $signed = $encodedHeader . '.' . $encodedPayload;
    $signature = posla_op_launch_base64url_encode(hash_hmac('sha256', $signed, $secret, true));
    return $signed . '.' . $signature;
}

function posla_op_launch_safe_base_url(string $value): string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^https?:\/\/[^\s]+$/', $value)) {
        return '';
    }
    return rtrim($value, '/');
}

$admin = require_posla_admin();
$method = require_method(['GET', 'POST']);
$pdo = get_db();

$opBaseUrl = posla_op_launch_safe_base_url((string)(get_posla_setting($pdo, 'codex_ops_public_url') ?: ''));
if ($opBaseUrl === '') {
    json_error('OP_URL_NOT_CONFIGURED', 'OP画面URLが未設定です', 409);
}

$secret = posla_op_launch_secret();
if ($secret === '') {
    $fallbackUrl = $opBaseUrl . '/';
    if ($method === 'GET') {
        header('Location: ' . $fallbackUrl, true, 302);
        return;
    }
    json_response([
        'launch_url' => $fallbackUrl,
        'session_enabled' => false,
        'expires_in' => 0,
    ]);
}

$now = time();
$payload = [
    'iss' => 'posla-admin',
    'aud' => 'posla-ops-platform',
    'sub' => (string)($admin['admin_id'] ?? ''),
    'email' => (string)($admin['email'] ?? ''),
    'name' => (string)($admin['display_name'] ?? ''),
    'iat' => $now,
    'nbf' => $now - 5,
    'exp' => $now + 60,
    'nonce' => bin2hex(random_bytes(16)),
];
$token = posla_op_launch_token($payload, $secret);
$launchUrl = $opBaseUrl . '/launch?token=' . rawurlencode($token);

posla_admin_write_audit_log(
    $pdo,
    $admin,
    'op_launch_token_create',
    'op_launch',
    'posla-ops-platform',
    null,
    [
        'session_enabled' => true,
        'expires_in' => 60,
        'op_base_url_set' => true,
    ],
    null,
    generate_uuid()
);

if ($method === 'GET') {
    header('Location: ' . $launchUrl, true, 302);
    return;
}

json_response([
    'launch_url' => $launchUrl,
    'session_enabled' => true,
    'expires_in' => 60,
]);
