<?php
/**
 * Best-effort trigger for host-side cell provisioning.
 *
 * Web requests must not run deploy/Docker work directly. They only notify a
 * localhost-only provisioner service after an onboarding request becomes
 * ready_for_cell.
 */

require_once __DIR__ . '/../config/app.php';

function posla_provisioner_trigger_env($key, $default = '') {
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

function posla_provisioner_trigger_allowed_url($url) {
    $parts = parse_url((string)$url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower(trim((string)$parts['host'], '[]'));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    $localHosts = [
        '127.0.0.1',
        'localhost',
        '::1',
        'host.docker.internal',
    ];
    if (in_array($host, $localHosts, true)) {
        return true;
    }

    return posla_provisioner_trigger_env('POSLA_PROVISIONER_ALLOW_REMOTE_TRIGGER') === '1';
}

function posla_trigger_cell_provisioner(PDO $pdo, $tenantId, $source) {
    $url = trim(posla_provisioner_trigger_env('POSLA_PROVISIONER_TRIGGER_URL'));
    $secret = (string)posla_provisioner_trigger_env('POSLA_PROVISIONER_TRIGGER_SECRET');
    if ($url === '' || $secret === '') {
        error_log('[provisioner-trigger] disabled tenant=' . $tenantId . ' source=' . $source, 3, POSLA_PHP_ERROR_LOG);
        return;
    }

    if (!posla_provisioner_trigger_allowed_url($url)) {
        error_log('[provisioner-trigger] rejected non-local trigger_url tenant=' . $tenantId . ' url=' . $url, 3, POSLA_PHP_ERROR_LOG);
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT request_id, status
               FROM posla_tenant_onboarding_requests
              WHERE tenant_id = ?
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([(string)$tenantId]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'ready_for_cell') {
            return;
        }

        $payload = json_encode([
            'request_id' => (string)$row['request_id'],
            'tenant_id' => (string)$tenantId,
            'source' => (string)$source,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!function_exists('curl_init')) {
            error_log('[provisioner-trigger] curl_unavailable tenant=' . $tenantId, 3, POSLA_PHP_ERROR_LOG);
            return;
        }

        $timeout = (int)posla_provisioner_trigger_env('POSLA_PROVISIONER_TRIGGER_TIMEOUT_SEC', '2');
        if ($timeout < 1 || $timeout > 10) {
            $timeout = 2;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-POSLA-Provisioner-Secret: ' . $secret,
        ]);

        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || $code < 200 || $code >= 300) {
            error_log(
                '[provisioner-trigger] trigger_failed tenant=' . $tenantId
                . ' request=' . $row['request_id']
                . ' http=' . $code
                . ' err=' . $err
                . ' body=' . substr((string)$response, 0, 200),
                3,
                POSLA_PHP_ERROR_LOG
            );
        }
    } catch (Throwable $e) {
        error_log('[provisioner-trigger] exception tenant=' . $tenantId . ' err=' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }
}
