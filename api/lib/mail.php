<?php
/**
 * POSLA mail transport helper.
 *
 * Local / legacy hosting can keep PHP mail. Cloud Run should use an API
 * transport such as SendGrid because sendmail is not available by default.
 */

function posla_mail_env($key, $default = '')
{
    $value = getenv($key);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function posla_mail_default_from_email()
{
    if (defined('APP_FROM_EMAIL')) {
        return APP_FROM_EMAIL;
    }
    return posla_mail_env('POSLA_FROM_EMAIL', 'noreply@meal.posla.jp');
}

function posla_mail_default_from_name()
{
    return posla_mail_env('POSLA_MAIL_FROM_NAME', 'POSLA');
}

function posla_mail_transport()
{
    $transport = strtolower(posla_mail_env('POSLA_MAIL_TRANSPORT', 'auto'));
    if ($transport === 'auto') {
        return posla_mail_env('POSLA_SENDGRID_API_KEY') !== '' ? 'sendgrid' : 'php';
    }
    if ($transport === 'sendgrid_api') {
        return 'sendgrid';
    }
    if ($transport === 'mail' || $transport === 'mb_send_mail') {
        return 'php';
    }
    return $transport;
}

function posla_mail_transport_label()
{
    $transport = posla_mail_transport();
    if ($transport === 'php') {
        if (function_exists('mb_send_mail')) {
            return 'mb_send_mail';
        }
        return function_exists('mail') ? 'mail' : 'none';
    }
    return $transport;
}

function posla_send_mail($to, $subject, $body, array $options = [])
{
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'transport' => posla_mail_transport_label(), 'error' => 'INVALID_RECIPIENT'];
    }

    $subject = (string)$subject;
    $body = (string)$body;
    $fromName = isset($options['from_name']) ? (string)$options['from_name'] : posla_mail_default_from_name();
    $fromEmail = isset($options['from_email']) ? (string)$options['from_email'] : posla_mail_default_from_email();
    $replyTo = isset($options['reply_to']) ? trim((string)$options['reply_to']) : '';
    $transport = posla_mail_transport();

    if ($transport === 'sendgrid') {
        return posla_mail_sendgrid($to, $subject, $body, $fromName, $fromEmail, $replyTo);
    }

    if ($transport === 'log') {
        error_log('[POSLA_MAIL] log transport to=' . $to . ' subject=' . $subject);
        return ['success' => true, 'transport' => 'log', 'error' => null];
    }

    if ($transport === 'none' || $transport === 'disabled') {
        return ['success' => false, 'transport' => $transport, 'error' => 'MAIL_TRANSPORT_DISABLED'];
    }

    return posla_mail_php($to, $subject, $body, $fromName, $fromEmail, $replyTo);
}

function posla_mail_php($to, $subject, $body, $fromName, $fromEmail, $replyTo)
{
    if (function_exists('mb_language')) {
        mb_language('Japanese');
        mb_internal_encoding('UTF-8');
    }

    $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
    $headers = "From: " . $fromHeader . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    if ($replyTo !== '') {
        $headers .= "Reply-To: " . $replyTo . "\r\n";
    }

    if (function_exists('mb_send_mail')) {
        $ok = @mb_send_mail($to, $subject, $body, $headers);
        return ['success' => (bool)$ok, 'transport' => 'mb_send_mail', 'error' => $ok ? null : 'MAIL_SEND_FAILED'];
    }

    if (function_exists('mail')) {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = @mail($to, $encodedSubject, $body, $headers);
        return ['success' => (bool)$ok, 'transport' => 'mail', 'error' => $ok ? null : 'MAIL_SEND_FAILED'];
    }

    return ['success' => false, 'transport' => 'php', 'error' => 'PHP_MAIL_UNAVAILABLE'];
}

function posla_mail_sendgrid($to, $subject, $body, $fromName, $fromEmail, $replyTo)
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'transport' => 'sendgrid', 'error' => 'CURL_UNAVAILABLE'];
    }

    $apiKey = posla_mail_env('POSLA_SENDGRID_API_KEY');
    if ($apiKey === '') {
        return ['success' => false, 'transport' => 'sendgrid', 'error' => 'SENDGRID_API_KEY_MISSING'];
    }

    $payload = [
        'personalizations' => [[
            'to' => [['email' => $to]],
        ]],
        'from' => [
            'email' => $fromEmail,
            'name' => $fromName,
        ],
        'subject' => $subject,
        'content' => [[
            'type' => 'text/plain',
            'value' => $body,
        ]],
    ];
    if ($replyTo !== '') {
        $payload['reply_to'] = ['email' => $replyTo];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['success' => false, 'transport' => 'sendgrid', 'error' => 'JSON_ENCODE_FAILED'];
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => (int)posla_mail_env('POSLA_MAIL_TIMEOUT_SEC', '8'),
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $raw = @curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw !== false && $httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'transport' => 'sendgrid', 'error' => null, 'http_status' => $httpCode];
    }

    return [
        'success' => false,
        'transport' => 'sendgrid',
        'error' => $error !== '' ? $error : ('HTTP_' . $httpCode),
        'http_status' => $httpCode,
    ];
}
