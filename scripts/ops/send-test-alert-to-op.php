<?php
/**
 * Send one POSLA test-environment alert to the POSLA Ops ingest endpoint.
 *
 * This is CLI-only and does not write to the POSLA app database. It verifies
 * the POSLA app -> OP alert contract using the same payload shape as
 * api/cron/monitor-health.php.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$options = posla_test_ops_alert_options($argv);
$endpoint = $options['endpoint'];
$token = $options['token'];

if ($endpoint === '') {
    fwrite(STDERR, "POSLA_OP_ALERT_ENDPOINT or --endpoint is required.\n");
    exit(2);
}
if (!preg_match('/^https?:\/\//', $endpoint)) {
    fwrite(STDERR, "Endpoint must start with http:// or https://.\n");
    exit(2);
}
if (empty($options['dry_run']) && $token === '') {
    fwrite(STDERR, "POSLA_OP_ALERT_TOKEN or --token is required for send mode.\n");
    exit(2);
}

$payload = posla_test_ops_alert_payload($options);
if (!empty($options['dry_run'])) {
    echo json_encode(array(
        'ok' => true,
        'mode' => 'dry_run',
        'endpoint' => posla_test_ops_alert_endpoint_label($endpoint),
        'payload' => $payload,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$result = posla_test_ops_alert_post($endpoint, $token, $payload, (int)$options['timeout_seconds']);
$workerResults = array();
if (!empty($result['ok']) && !empty($options['run_workers'])) {
    $workerResults = posla_test_ops_alert_run_workers($endpoint, (int)$options['timeout_seconds']);
}
echo json_encode(array(
    'ok' => !empty($result['ok']),
    'mode' => 'send',
    'endpoint' => posla_test_ops_alert_endpoint_label($endpoint),
    'http_status' => $result['http_status'],
    'status' => $result['status'],
    'response' => $result['response'],
    'workers' => $workerResults,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit(!empty($result['ok']) ? 0 : 1);

function posla_test_ops_alert_options($argv)
{
    $defaults = array(
        'endpoint' => trim((string)(getenv('POSLA_OP_ALERT_ENDPOINT') ?: '')),
        'token' => trim((string)(getenv('POSLA_OP_ALERT_TOKEN') ?: '')),
        'severity' => 'critical',
        'title' => 'POSLA test environment payment checkout database outage',
        'message' => 'HTTP 500 on checkout/payment path. Database unavailable smoke event from POSLA test environment.',
        'event_type' => 'monitor_health',
        'source' => 'posla_monitor_health',
        'cell_id' => trim((string)(getenv('POSLA_CELL_ID') ?: 'posla-control-local')),
        'environment' => trim((string)(getenv('POSLA_ENVIRONMENT') ?: (getenv('POSLA_ENV') ?: 'test'))),
        'deploy_version' => trim((string)(getenv('POSLA_DEPLOY_VERSION') ?: 'dev')),
        'tenant_id' => '',
        'store_id' => '',
        'error_no' => 'POSLA_OP_SMOKE',
        'timeout_seconds' => 15,
        'dry_run' => false,
        'run_workers' => false,
    );

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if ($arg === '--dry-run') {
            $defaults['dry_run'] = true;
            continue;
        }
        if ($arg === '--run-workers') {
            $defaults['run_workers'] = true;
            continue;
        }
        if (strpos($arg, '--') !== 0 || strpos($arg, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', substr($arg, 2), 2);
        $key = str_replace('-', '_', $key);
        if (array_key_exists($key, $defaults)) {
            $defaults[$key] = trim($value);
        }
    }

    return $defaults;
}

function posla_test_ops_alert_payload($options)
{
    $sourceEventId = 'posla-test-op-' . gmdate('YmdHis');
    $fingerprintParts = array(
        $sourceEventId,
        $options['cell_id'],
        $options['environment'],
        $options['error_no'],
    );

    return array(
        'source_event_id' => $sourceEventId,
        'source' => $options['source'],
        'event_type' => $options['event_type'],
        'severity' => $options['severity'],
        'cell_id' => $options['cell_id'],
        'tenant_id' => $options['tenant_id'] !== '' ? $options['tenant_id'] : null,
        'store_id' => $options['store_id'] !== '' ? $options['store_id'] : null,
        'environment' => $options['environment'],
        'deploy_version' => $options['deploy_version'],
        'error_no' => $options['error_no'],
        'title' => mb_substr($options['title'], 0, 250, 'UTF-8'),
        'message' => mb_substr($options['message'], 0, 1200, 'UTF-8'),
        'occurred_at' => gmdate('c'),
        'fingerprint' => hash('sha256', implode('|', $fingerprintParts)),
    );
}

function posla_test_ops_alert_post($endpoint, $token, $payload, $timeoutSeconds)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return array('ok' => false, 'status' => 'json_encode_failed', 'http_status' => 0, 'response' => null);
    }

    $headers = array('Content-Type: application/json; charset=utf-8', 'Accept: application/json');
    if ($token !== '') {
        $headers[] = 'X-OPS-ALERT-TOKEN: ' . $token;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_RETURNTRANSFER => true,
        ));
        $raw = @curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return posla_test_ops_alert_result($raw, $httpStatus, $error);
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $json,
            'timeout' => max(1, $timeoutSeconds),
            'ignore_errors' => true,
        ),
    ));
    $raw = @file_get_contents($endpoint, false, $context);
    $httpStatus = posla_test_ops_alert_http_status(isset($http_response_header) ? $http_response_header : array());
    return posla_test_ops_alert_result($raw, $httpStatus, $raw === false ? 'request_failed' : '');
}

function posla_test_ops_alert_run_workers($ingestEndpoint, $timeoutSeconds)
{
    $base = posla_test_ops_alert_api_base($ingestEndpoint);
    if ($base === '') {
        return array('ok' => false, 'error' => 'api_base_not_detected');
    }

    $workflow = posla_test_ops_alert_post_json($base . '/api/workers/workflow/run', array('limit' => 10), $timeoutSeconds);
    $notification = posla_test_ops_alert_post_json($base . '/api/workers/notification/run', array('limit' => 10), $timeoutSeconds);
    $cockpit = posla_test_ops_alert_get_json($base . '/api/ops-cockpit', $timeoutSeconds);

    return array(
        'ok' => !empty($workflow['ok']) && !empty($notification['ok']) && !empty($cockpit['ok']),
        'workflow' => $workflow,
        'notification' => $notification,
        'cockpit' => $cockpit,
    );
}

function posla_test_ops_alert_api_base($endpoint)
{
    $pos = strpos((string)$endpoint, '/api/');
    if ($pos === false) {
        return '';
    }
    return rtrim(substr((string)$endpoint, 0, $pos), '/');
}

function posla_test_ops_alert_post_json($endpoint, $payload, $timeoutSeconds)
{
    return posla_test_ops_alert_request_json('POST', $endpoint, $payload, $timeoutSeconds);
}

function posla_test_ops_alert_get_json($endpoint, $timeoutSeconds)
{
    return posla_test_ops_alert_request_json('GET', $endpoint, null, $timeoutSeconds);
}

function posla_test_ops_alert_request_json($method, $endpoint, $payload, $timeoutSeconds)
{
    $json = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload !== null && $json === false) {
        return array('ok' => false, 'status' => 'json_encode_failed', 'http_status' => 0);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        $options = array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json; charset=utf-8', 'Accept: application/json'),
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_RETURNTRANSFER => true,
        );
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $options);
        $raw = @curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return posla_test_ops_alert_result($raw, $httpStatus, $error);
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => $method,
            'header' => "Content-Type: application/json; charset=utf-8\r\nAccept: application/json",
            'content' => $payload === null ? '' : $json,
            'timeout' => max(1, $timeoutSeconds),
            'ignore_errors' => true,
        ),
    ));
    $raw = @file_get_contents($endpoint, false, $context);
    $httpStatus = posla_test_ops_alert_http_status(isset($http_response_header) ? $http_response_header : array());
    return posla_test_ops_alert_result($raw, $httpStatus, $raw === false ? 'request_failed' : '');
}

function posla_test_ops_alert_result($raw, $httpStatus, $error)
{
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $ok = $raw !== false && $httpStatus >= 200 && $httpStatus < 300;
    return array(
        'ok' => $ok,
        'status' => $ok ? 'sent' : 'failed',
        'http_status' => $httpStatus,
        'response' => is_array($decoded) ? $decoded : array(
            'body_preview' => is_string($raw) ? mb_substr($raw, 0, 500, 'UTF-8') : '',
            'error' => $error,
        ),
    );
}

function posla_test_ops_alert_http_status($headers)
{
    if (!is_array($headers)) {
        return 0;
    }
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$header, $matches)) {
            return (int)$matches[1];
        }
    }
    return 0;
}

function posla_test_ops_alert_endpoint_label($endpoint)
{
    return preg_replace('/([?&](?:key|token|secret)=)[^&]+/i', '$1[REDACTED]', (string)$endpoint);
}
