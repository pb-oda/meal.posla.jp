<?php
/**
 * POSLA mail transport helper.
 *
 * POSLA uses the server-side PHP mail-compatible transport configured for the
 * runtime. UI-managed settings only control the sender and support addresses.
 */

function posla_mail_env($key, $default = '')
{
    $value = getenv($key);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function posla_mail_setting($key)
{
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        if (function_exists('get_db')) {
            try {
                $pdo = get_db();
                $stmt = $pdo->query(
                    "SELECT setting_key, setting_value
                     FROM posla_settings
                     WHERE setting_key IN ('mail_from_email','mail_from_name','mail_support_email')"
                );
                foreach ($stmt->fetchAll() as $row) {
                    $cache[$row['setting_key']] = trim((string)$row['setting_value']);
                }
            } catch (Throwable $e) {
                $cache = array();
            }
        }
    }

    return isset($cache[$key]) && $cache[$key] !== '' ? $cache[$key] : '';
}

function posla_mail_default_from_email()
{
    $setting = posla_mail_setting('mail_from_email');
    if ($setting !== '' && filter_var($setting, FILTER_VALIDATE_EMAIL)) {
        return $setting;
    }
    if (defined('APP_FROM_EMAIL')) {
        return APP_FROM_EMAIL;
    }
    return posla_mail_env('POSLA_FROM_EMAIL', 'noreply@meal.posla.jp');
}

function posla_mail_default_from_name()
{
    $setting = posla_mail_setting('mail_from_name');
    if ($setting !== '') {
        return $setting;
    }
    return posla_mail_env('POSLA_MAIL_FROM_NAME', 'POSLA');
}

function posla_mail_default_support_email()
{
    $setting = posla_mail_setting('mail_support_email');
    if ($setting !== '' && filter_var($setting, FILTER_VALIDATE_EMAIL)) {
        return $setting;
    }
    if (defined('APP_SUPPORT_EMAIL')) {
        return APP_SUPPORT_EMAIL;
    }
    return posla_mail_env('POSLA_SUPPORT_EMAIL', 'info@meal.posla.jp');
}

function posla_mail_transport()
{
    $transport = strtolower(posla_mail_env('POSLA_MAIL_TRANSPORT', 'auto'));
    if ($transport === 'auto' || $transport === 'mail' || $transport === 'mb_send_mail') {
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
