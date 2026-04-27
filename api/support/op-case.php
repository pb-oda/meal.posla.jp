<?php
/**
 * Tenant incident report -> codex-ops-platform incident case bridge.
 *
 * POSLA tenant screens call this endpoint with the current page/error context.
 * This endpoint enriches the request with authenticated tenant/store/user/cell
 * metadata and forwards it to OP. POSLA business data is not written here.
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/posla-settings.php';

require_method(['POST']);

function posla_support_text(array $data, string $key, int $maxLen = 1000): string
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return '';
    }
    $value = trim((string)$data[$key]);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLen);
    }
    return substr($value, 0, $maxLen);
}

function posla_support_env(string $key): string
{
    $value = getenv($key);
    return ($value === false || $value === null) ? '' : trim((string)$value);
}

function posla_support_setting(PDO $pdo, string $settingKey, string $envKey): string
{
    $envValue = posla_support_env($envKey);
    if ($envValue !== '') {
        return $envValue;
    }
    $settingValue = get_posla_setting($pdo, $settingKey);
    return $settingValue === null ? '' : trim((string)$settingValue);
}

function posla_support_fetch_tenant(PDO $pdo, string $tenantId): array
{
    $stmt = $pdo->prepare('SELECT id, slug, name FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    return $row ? $row : ['id' => $tenantId, 'slug' => '', 'name' => ''];
}

function posla_support_fetch_store(PDO $pdo, string $tenantId, string $storeId): array
{
    if ($storeId === '') {
        return ['id' => '', 'name' => ''];
    }
    $stmt = $pdo->prepare('SELECT id, name FROM stores WHERE id = ? AND tenant_id = ? LIMIT 1');
    $stmt->execute([$storeId, $tenantId]);
    $row = $stmt->fetch();
    return $row ? $row : ['id' => $storeId, 'name' => ''];
}

function posla_support_fetch_cell(PDO $pdo, string $tenantId): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT cell_id, environment, deploy_version
               FROM posla_cell_registry
              WHERE tenant_id = ?
              ORDER BY updated_at DESC
              LIMIT 1'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        return $row ? $row : [];
    } catch (Throwable $e) {
        return [];
    }
}

function posla_support_post_json(string $url, array $payload, string $token): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'json_encode_failed'];
    }

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
    ];
    if ($token !== '') {
        $headers[] = 'X-OPS-CASE-TOKEN: ' . $token;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => ($body !== false && $status >= 200 && $status < 300),
            'status' => $status,
            'body' => $body === false ? '' : (string)$body,
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $json,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        $i = 0;
        for ($i = 0; $i < count($http_response_header); $i++) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[$i], $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }

    return [
        'ok' => ($body !== false && $status >= 200 && $status < 300),
        'status' => $status,
        'body' => $body === false ? '' : (string)$body,
        'error' => $body === false ? 'request_failed' : '',
    ];
}

$user = require_auth();
$input = get_json_body();
$pdo = get_db();

$message = posla_support_text($input, 'message', 3000);
$errorNo = strtoupper(posla_support_text($input, 'error_no', 40));
if ($message === '' && $errorNo === '') {
    json_error('VALIDATION', '障害内容またはエラー番号を入力してください', 400);
}

$storeId = posla_support_text($input, 'store_id', 36);
if ($storeId === '' && count($user['store_ids']) === 1) {
    $storeId = (string)$user['store_ids'][0];
}
if ($storeId !== '') {
    require_store_access($storeId);
}

$endpoint = posla_support_setting($pdo, 'codex_ops_case_endpoint', 'POSLA_OP_CASE_ENDPOINT');
$token = posla_support_setting($pdo, 'codex_ops_case_token', 'POSLA_OP_CASE_TOKEN');
if ($endpoint === '') {
    json_error('NOT_CONFIGURED', 'OP障害報告送信先が未設定です', 503);
}
if (!preg_match('/^https?:\/\//', $endpoint)) {
    json_error('VALIDATION', 'OP障害報告送信先URLが不正です', 400);
}

$tenant = posla_support_fetch_tenant($pdo, (string)$user['tenant_id']);
$store = posla_support_fetch_store($pdo, (string)$user['tenant_id'], $storeId);
$cell = posla_support_fetch_cell($pdo, (string)$user['tenant_id']);
$meta = function_exists('app_deployment_metadata') ? app_deployment_metadata() : [];

$payload = [
    'source' => 'posla_incident_report_form',
    'customer_name' => $tenant['name'] ?? '',
    'tenant_id' => (string)$user['tenant_id'],
    'tenant_slug' => $tenant['slug'] ?? '',
    'cell_id' => ($cell['cell_id'] ?? '') !== '' ? (string)$cell['cell_id'] : (string)($meta['cell_id'] ?? ''),
    'environment' => ($cell['environment'] ?? '') !== '' ? (string)$cell['environment'] : (string)($meta['environment'] ?? ''),
    'deploy_version' => ($cell['deploy_version'] ?? '') !== '' ? (string)$cell['deploy_version'] : (string)($meta['deploy_version'] ?? ''),
    'source_id' => (string)($meta['source_id'] ?? ''),
    'store_id' => $store['id'] ?? $storeId,
    'store_name' => $store['name'] ?? '',
    'error_no' => $errorNo,
    'occurred_at' => date('c'),
    'contact_name' => (string)($user['username'] ?? ''),
    'contact_channel' => 'posla_incident_report_form',
    'message' => $message,
    'page_url' => posla_support_text($input, 'page_url', 500),
    'screen_name' => posla_support_text($input, 'screen_name', 160),
    'user_id' => (string)($user['user_id'] ?? ''),
    'username' => (string)($user['username'] ?? ''),
    'role' => (string)($user['role'] ?? ''),
    'user_agent' => posla_support_text($input, 'user_agent', 500),
    'client_time' => posla_support_text($input, 'client_time', 80),
];

$result = posla_support_post_json($endpoint, $payload, $token);
if (!$result['ok']) {
    error_log('[support/op-case] failed status=' . $result['status'] . ' error=' . $result['error']);
    json_error('GATEWAY_ERROR', 'OPへの障害報告送信に失敗しました', 502);
}

$opBody = json_decode($result['body'], true);
json_response([
    'message' => '障害報告を送信しました',
    'case' => is_array($opBody) && isset($opBody['case']) ? $opBody['case'] : null,
]);
