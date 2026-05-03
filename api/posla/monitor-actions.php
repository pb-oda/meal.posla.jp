<?php
/**
 * POSLA 管理者向け監視操作 API
 *
 * POST /api/posla/monitor-actions.php
 * Body:
 *   { "action": "run_health_check" }
 *   { "action": "send_test_alert", "tenant_id"?: "...", "store_id"?: "...", "message"?: "..." }
 *   { "action": "send_ops_mail_test", "message"?: "...", "dry_run"?: true }
 *
 * 方針:
 *   - 実際の監視処理は既存の /api/cron/monitor-health.php を内部 HTTP で呼び出す
 *   - POSLA 管理者 UI からは CLI を触らずに monitor-health を再実行できる
 *   - テスト通知は error_log に試験行を挿入し、既存の昇格ロジックをそのまま通す
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../lib/mail.php';

$method = require_method(['POST']);
$admin = require_posla_admin();
$pdo = get_db();
$body = get_json_body();
$action = isset($body['action']) ? trim((string)$body['action']) : '';

if ($action === '') {
    json_error('MISSING_ACTION', 'action が必要です', 400);
}

if ($action === 'run_health_check') {
    $run = _run_monitor_health_internal();
    json_response([
        'message' => 'monitor-health を実行しました',
        'run' => $run,
    ]);
}

if ($action === 'send_test_alert') {
    $nowSuffix = date('YmdHis');
    $tenantId = isset($body['tenant_id']) ? trim((string)$body['tenant_id']) : '';
    $storeId = isset($body['store_id']) ? trim((string)$body['store_id']) : '';
    $message = isset($body['message']) ? trim((string)$body['message']) : '';
    $destination = _resolve_monitor_destination($pdo);
    _assert_monitor_runner_ready();

    if ($tenantId === '') {
        $tenantId = 'tenant-monitor-test-' . $nowSuffix;
    }
    if ($storeId === '') {
        $storeId = 'store-monitor-test-' . $nowSuffix;
    }
    if ($message === '') {
        $message = 'POSLA管理画面から送信したGoogle Chat テスト通知';
    }

    _insert_test_error_log_rows($pdo, $tenantId, $storeId, $message, $admin);
    $run = _run_monitor_health_internal();

    json_response([
        'message' => 'テスト通知を送信しました',
        'scope' => [
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'error_no' => 'E1025',
            'error_code' => 'SERVER_ERROR',
        ],
        'destination' => $destination,
        'run' => $run,
    ]);
}

if ($action === 'send_ops_mail_test') {
    $message = isset($body['message']) ? trim((string)$body['message']) : '';
    $dryRun = !empty($body['dry_run']);
    $settings = _fetch_monitor_settings($pdo);
    $recipient = trim((string)($settings['ops_notify_email'] ?? ''));
    $transport = _resolve_mail_transport();
    $subject = '[POSLA] 運用通知メール テスト ' . date('Y-m-d H:i:s');
    $bodyText = "POSLA 管理画面から送信したテスト通知です。\n"
        . '送信者: ' . (!empty($admin['display_name']) ? $admin['display_name'] : ($admin['email'] ?? 'posla-admin')) . "\n"
        . '日時: ' . date('Y-m-d H:i:s') . "\n\n"
        . ($message !== '' ? $message : 'メール通知経路の疎通確認です。');

    if ($recipient === '') {
        json_error('NOT_CONFIGURED', 'ops_notify_email が未設定です', 400);
    }

    if ($transport === 'none') {
        json_error('MAIL_TRANSPORT_UNAVAILABLE', 'メール送信関数が利用できません', 500);
    }

    if ($dryRun) {
        json_response([
            'message' => '運用通知メールの送信内容を確認しました',
            'mail' => [
                'recipient' => $recipient,
                'transport' => $transport,
                'subject' => $subject,
                'body_preview' => mb_substr($bodyText, 0, 400),
            ],
        ]);
    }

    $sent = _send_ops_mail_test($recipient, $subject, $bodyText, $transport);
    if (!$sent) {
        json_error('MAIL_SEND_FAILED', '運用通知メールの送信に失敗しました', 502);
    }

    json_response([
        'message' => '運用通知メールを送信しました',
        'mail' => [
            'recipient' => $recipient,
            'transport' => $transport,
            'subject' => $subject,
        ],
    ]);
}

json_error('INVALID_ACTION', '未対応の action です', 400);

function _insert_test_error_log_rows($pdo, $tenantId, $storeId, $message, $admin)
{
    $stmt = $pdo->prepare(
        'INSERT INTO error_log
            (error_no, code, message, http_status,
             tenant_id, store_id, user_id, username, role,
             request_method, request_path, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'posla-admin-monitor-test';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $username = !empty($admin['display_name']) ? $admin['display_name'] : ($admin['email'] ?? 'posla-admin');
    $requestPath = '/api/posla/monitor-actions.php?action=send_test_alert';
    $i = 0;

    for ($i = 0; $i < 3; $i++) {
        $stmt->execute([
            'E1025',
            'SERVER_ERROR',
            mb_substr($message, 0, 1000),
            500,
            $tenantId,
            $storeId,
            $admin['admin_id'],
            mb_substr($username, 0, 50),
            'posla_admin',
            'POST',
            $requestPath,
            $ipAddress,
            mb_substr($userAgent, 0, 500),
        ]);
    }
}

function _run_monitor_health_internal()
{
    _assert_monitor_runner_ready();

    $secret = getenv('POSLA_CRON_SECRET') ?: '';
    $url = _internal_monitor_health_url();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-POSLA-CRON-SECRET: ' . $secret],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        json_error('MONITOR_RUN_FAILED', 'monitor-health の内部実行に失敗しました: ' . $curlError, 502);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        json_error('MONITOR_RUN_FAILED', 'monitor-health の応答解析に失敗しました', 502);
    }

    if ($httpCode < 200 || $httpCode >= 300 || empty($json['ok'])) {
        $message = 'monitor-health の内部実行に失敗しました';
        if (!empty($json['error']['message'])) {
            $message .= ': ' . $json['error']['message'];
        }
        json_error('MONITOR_RUN_FAILED', $message, 502);
    }

    return [
        'http_status' => $httpCode,
        'result' => $json['result'] ?? [],
    ];
}

function _assert_monitor_runner_ready()
{
    if (!function_exists('curl_init')) {
        json_error('CURL_REQUIRED', 'cURL 拡張が必要です', 500);
    }

    $secret = getenv('POSLA_CRON_SECRET') ?: '';
    if ($secret === '') {
        json_error('NOT_CONFIGURED', 'POSLA_CRON_SECRET が未設定です', 500);
    }
}

function _internal_monitor_health_url()
{
    $base = trim((string)(getenv('POSLA_INTERNAL_BASE_URL') ?: ''));
    if ($base === '') {
        $port = trim((string)(getenv('PORT') ?: ''));
        $base = $port !== '' ? 'http://127.0.0.1:' . $port : 'http://127.0.0.1';
    }
    return rtrim($base, '/') . '/api/cron/monitor-health.php';
}

function _resolve_monitor_destination($pdo)
{
    $settings = _fetch_monitor_settings($pdo);
    if (!empty($settings['google_chat_webhook_url'])) {
        return 'google_chat';
    }
    if (!empty($settings['slack_webhook_url'])) {
        return 'slack_legacy';
    }

    json_error('NOT_CONFIGURED', 'google_chat_webhook_url が未設定です', 400);
}

function _fetch_monitor_settings($pdo)
{
    try {
        $stmt = $pdo->query(
            "SELECT setting_key, setting_value
               FROM posla_settings
              WHERE setting_key IN ('google_chat_webhook_url', 'slack_webhook_url', 'ops_notify_email')"
        );
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    } catch (PDOException $e) {
        json_error('FETCH_FAILED', '監視通知先の確認に失敗しました', 500);
    }
}

function _resolve_mail_transport()
{
    return posla_mail_transport_label();
}

function _send_ops_mail_test($to, $subject, $body, $transport)
{
    $result = posla_send_mail($to, $subject, $body, [
        'from_name' => 'POSLA 通知テスト',
        'from_email' => posla_mail_default_from_email(),
    ]);
    return !empty($result['success']);
}
