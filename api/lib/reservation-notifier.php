<?php
/**
 * L-9 予約管理 — メール送信ライブラリ
 *
 * - 実送信は api/lib/mail.php に集約
 * - 送信ログは reservation_notifications_log に記録
 * - 多言語 (ja/en/zh-Hans/ko) テンプレート対応
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/mail.php';
// L-17 Phase 2B-1: confirm 時 LINE 並行送信用 (optional include)
// ファイルが無い環境でも落ちないよう存在チェック付き
if (file_exists(__DIR__ . '/line-link.php')) {
    require_once __DIR__ . '/line-link.php';
}
if (file_exists(__DIR__ . '/line-messaging.php')) {
    require_once __DIR__ . '/line-messaging.php';
}

if (!function_exists('_l9_mail_send')) {
    /**
     * 実送信。返り値: ['success'=>bool, 'error'=>string|null]
     */
    function _l9_mail_send($to, $subject, $body, $fromName, $fromEmail, $replyTo = null) {
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return array('success' => false, 'error' => 'INVALID_RECIPIENT');
        }
        $result = posla_send_mail($to, $subject, $body, array(
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'reply_to' => $replyTo,
        ));
        return array(
            'success' => !empty($result['success']),
            'error' => !empty($result['success']) ? null : ($result['error'] ?? 'MAIL_SEND_FAILED'),
        );
    }
}

if (!function_exists('_l9_log_notification')) {
    /**
     * @param string $channel 'email' | 'sms' | 'line' (L-17 Phase 2B-1: channel 引数化)
     */
    function _l9_log_notification($pdo, $reservationId, $storeId, $type, $recipient, $subject, $body, $status, $error = null, $channel = 'email', $retryMinutes = 15, $retryCount = 0, $managerAttention = 0) {
        // enum 値のみ許可。未知値は 'email' にフォールバック
        if (!in_array($channel, array('email', 'sms', 'line'), true)) {
            $channel = 'email';
        }
        try {
            $logId = bin2hex(random_bytes(18));
            $retryMinutes = max(1, min(1440, (int)$retryMinutes));
            $pdo->prepare(
                "INSERT INTO reservation_notifications_log
                 (id, reservation_id, store_id, notification_type, channel, recipient, subject, body_excerpt, status, retry_count, next_retry_at, manager_attention, error_message, sent_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($status === 'failed' ? 'DATE_ADD(NOW(), INTERVAL ' . $retryMinutes . ' MINUTE)' : 'NULL') . ", ?, ?, ?, NOW())"
            )->execute(array(
                $logId, $reservationId, $storeId, $type, $channel, $recipient,
                mb_substr((string)$subject, 0, 250), mb_substr((string)$body, 0, 1000),
                $status, max(0, (int)$retryCount), $managerAttention ? 1 : 0, $error,
                $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ));
            return $logId;
        } catch (PDOException $e) {
            error_log('[L-9][notifier] log_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
            return null;
        }
    }
}

if (!function_exists('_l9_resolve_store_info')) {
    function _l9_resolve_store_info($pdo, $storeId) {
        $stmt = $pdo->prepare('SELECT s.id, s.name, s.tenant_id, ss.receipt_phone AS phone, ss.receipt_address AS address FROM stores s LEFT JOIN store_settings ss ON ss.store_id = s.id WHERE s.id = ?');
        $stmt->execute(array($storeId));
        $store = $stmt->fetch();
        if (!$store) return null;
        return $store;
    }
}

if (!function_exists('_l9_format_datetime_for_lang')) {
    function _l9_format_datetime_for_lang($iso, $lang) {
        $ts = strtotime($iso);
        if ($lang === 'en') return date('M j, Y (D) H:i', $ts);
        if ($lang === 'zh-Hans') return date('Y年n月j日 H:i', $ts);
        if ($lang === 'ko') return date('Y년 n월 j일 H:i', $ts);
        $weekdayJa = array('日','月','火','水','木','金','土');
        return date('Y年n月j日', $ts) . '(' . $weekdayJa[(int)date('w', $ts)] . ') ' . date('H:i', $ts);
    }
}

if (!function_exists('build_reservation_template')) {
    /**
     * 予約通知テンプレートを生成 (type ごとに件名と本文を返す)
     */
    function build_reservation_template($type, $reservation, $store, $lang = 'ja', $extra = array()) {
        $lang = $lang ?: 'ja';
        $when = _l9_format_datetime_for_lang($reservation['reserved_at'], $lang);
        $name = $reservation['customer_name'];
        $party = (int)$reservation['party_size'];
        $storeName = $store['name'];
        $reservationId = $reservation['id'];
        $editUrl = isset($extra['edit_url']) ? $extra['edit_url'] : '';
        $depositUrl = isset($extra['deposit_url']) ? $extra['deposit_url'] : '';
        $cancelDeadlineHours = isset($extra['cancel_deadline_hours']) ? (int)$extra['cancel_deadline_hours'] : 3;

        $T = array(
            'ja' => array(
                'confirm_subject' => '【ご予約確定】' . $storeName,
                'confirm_body' => "{$name} 様\n\nこの度はご予約ありがとうございます。\n以下の内容で承りました。\n\n■店舗\n{$storeName}\n\n■ご予約日時\n{$when}\n\n■人数\n{$party}名様\n\n■予約番号\n{$reservationId}\n\n■ご予約の確認・変更・キャンセル\n{$editUrl}\n\n※キャンセルは{$cancelDeadlineHours}時間前まで無料です。\n\n当日のご来店をスタッフ一同お待ちしております。\n\n--\n{$storeName}",
                'reminder_24h_subject' => '【明日のご予約】' . $storeName,
                'reminder_24h_body' => "{$name} 様\n\n明日のご予約のリマインドをお送りします。\n\n■店舗\n{$storeName}\n\n■ご予約日時\n{$when}\n\n■人数\n{$party}名様\n\n■予約番号\n{$reservationId}\n\n■変更・キャンセル\n{$editUrl}\n\nお気をつけてお越しください。",
                'reminder_2h_subject' => '【まもなくお越しください】' . $storeName,
                'reminder_2h_body' => "{$name} 様\n\n本日のご予約までもうすぐです。\n\n■予約日時\n{$when}\n■人数\n{$party}名様\n■店舗\n{$storeName}\n\nスタッフ一同お待ちしております。",
                'cancel_subject' => '【キャンセル受付】' . $storeName,
                'cancel_body' => "{$name} 様\n\n以下のご予約をキャンセル承りました。\n\n■予約日時\n{$when}\n■人数\n{$party}名様\n■店舗\n{$storeName}\n■予約番号\n{$reservationId}\n\nまたのご利用をお待ちしております。",
                'no_show_subject' => '【ご来店確認のお願い】' . $storeName,
                'no_show_body' => "{$name} 様\n\n本日{$when}にご予約いただきましたが、お見えになっていないようです。\nお手数ですが店舗までご連絡ください。",
                'deposit_required_subject' => '【予約金のお支払いのお願い】' . $storeName,
                'deposit_required_body' => "{$name} 様\n\nご予約には予約金のお支払いが必要です。下記URLよりお支払いください。\n\n■決済URL\n{$depositUrl}\n\n■予約日時\n{$when}\n■人数\n{$party}名様\n\n決済が完了するまでご予約は仮予約状態となります。",
                'deposit_captured_subject' => '【予約金のお支払い完了】' . $storeName,
                'deposit_captured_body' => "{$name} 様\n\n予約金のお支払いを確認しました。\n当日のご来店をお待ちしております。",
                'deposit_refunded_subject' => '【予約金返金のお知らせ】' . $storeName,
                'deposit_refunded_body' => "{$name} 様\n\n予約金の返金処理を行いました。",
            ),
            'en' => array(
                'confirm_subject' => '[Reservation Confirmed] ' . $storeName,
                'confirm_body' => "Dear {$name},\n\nThank you for your reservation.\n\nStore: {$storeName}\nDate/Time: {$when}\nParty: {$party}\nReservation ID: {$reservationId}\n\nManage your reservation: {$editUrl}\n\nFree cancellation up to {$cancelDeadlineHours} hours before.\n\n--\n{$storeName}",
                'reminder_24h_subject' => '[Reminder: Tomorrow] ' . $storeName,
                'reminder_24h_body' => "Dear {$name},\n\nThis is a reminder of your reservation tomorrow.\n\nDate/Time: {$when}\nParty: {$party}\nStore: {$storeName}\n\nManage: {$editUrl}",
                'reminder_2h_subject' => '[Reminder: Soon] ' . $storeName,
                'reminder_2h_body' => "Dear {$name},\n\nYour reservation is coming up.\nDate/Time: {$when}\nParty: {$party}",
                'cancel_subject' => '[Reservation Cancelled] ' . $storeName,
                'cancel_body' => "Dear {$name},\n\nYour reservation has been cancelled.\nDate/Time: {$when}\nParty: {$party}\nReservation ID: {$reservationId}",
                'no_show_subject' => '[Did you forget?] ' . $storeName,
                'no_show_body' => "Dear {$name},\n\nWe did not see you at {$when}. Please contact us if needed.",
                'deposit_required_subject' => '[Deposit Required] ' . $storeName,
                'deposit_required_body' => "Dear {$name},\n\nA deposit is required to confirm your reservation.\nPay here: {$depositUrl}",
                'deposit_captured_subject' => '[Deposit Received] ' . $storeName,
                'deposit_captured_body' => "Dear {$name},\n\nWe received your deposit. See you soon!",
                'deposit_refunded_subject' => '[Deposit Refunded] ' . $storeName,
                'deposit_refunded_body' => "Dear {$name},\n\nYour deposit has been refunded.",
            ),
            'zh-Hans' => array(
                'confirm_subject' => '【预订确认】' . $storeName,
                'confirm_body' => "{$name} 您好\n\n感谢您的预订。\n\n店铺: {$storeName}\n时间: {$when}\n人数: {$party}人\n预订编号: {$reservationId}\n\n变更/取消: {$editUrl}",
                'reminder_24h_subject' => '【明日预订提醒】' . $storeName,
                'reminder_24h_body' => "{$name} 您好\n\n这是您明日预订的提醒。\n时间: {$when}\n人数: {$party}人",
                'reminder_2h_subject' => '【即将光临】' . $storeName,
                'reminder_2h_body' => "{$name} 您好\n\n您的预订即将开始。\n时间: {$when}",
                'cancel_subject' => '【取消已受理】' . $storeName,
                'cancel_body' => "{$name} 您好\n\n您的预订已取消。\n时间: {$when}",
                'no_show_subject' => '【请确认】' . $storeName,
                'no_show_body' => "{$name} 您好\n\n您预订的时间已过,请联系店铺。",
                'deposit_required_subject' => '【需支付订金】' . $storeName,
                'deposit_required_body' => "{$name} 您好\n\n请支付订金: {$depositUrl}",
                'deposit_captured_subject' => '【订金已确认】' . $storeName,
                'deposit_captured_body' => "{$name} 您好\n\n订金已确认。",
                'deposit_refunded_subject' => '【订金已退款】' . $storeName,
                'deposit_refunded_body' => "{$name} 您好\n\n订金已退款。",
            ),
            'ko' => array(
                'confirm_subject' => '[예약 확정] ' . $storeName,
                'confirm_body' => "{$name} 님\n\n예약해 주셔서 감사합니다.\n\n매장: {$storeName}\n일시: {$when}\n인원: {$party}명\n예약번호: {$reservationId}\n\n변경/취소: {$editUrl}",
                'reminder_24h_subject' => '[내일 예약 알림] ' . $storeName,
                'reminder_24h_body' => "{$name} 님\n\n내일 예약 알림입니다.\n일시: {$when}\n인원: {$party}명",
                'reminder_2h_subject' => '[곧 시작] ' . $storeName,
                'reminder_2h_body' => "{$name} 님\n\n예약 시간이 다가오고 있습니다.\n일시: {$when}",
                'cancel_subject' => '[취소 접수] ' . $storeName,
                'cancel_body' => "{$name} 님\n\n예약이 취소되었습니다.\n일시: {$when}",
                'no_show_subject' => '[확인 부탁드립니다] ' . $storeName,
                'no_show_body' => "{$name} 님\n\n예약 시간이 지났습니다. 매장에 연락 주세요.",
                'deposit_required_subject' => '[예약금 결제 요청] ' . $storeName,
                'deposit_required_body' => "{$name} 님\n\n예약금 결제: {$depositUrl}",
                'deposit_captured_subject' => '[예약금 확인] ' . $storeName,
                'deposit_captured_body' => "{$name} 님\n\n예약금이 확인되었습니다.",
                'deposit_refunded_subject' => '[예약금 환불] ' . $storeName,
                'deposit_refunded_body' => "{$name} 님\n\n예약금이 환불되었습니다.",
            ),
        );
        $bag = isset($T[$lang]) ? $T[$lang] : $T['ja'];

        $map = array(
            'confirm' => array($bag['confirm_subject'], $bag['confirm_body']),
            'reminder_24h' => array($bag['reminder_24h_subject'], $bag['reminder_24h_body']),
            'reminder_2h' => array($bag['reminder_2h_subject'], $bag['reminder_2h_body']),
            'cancel' => array($bag['cancel_subject'], $bag['cancel_body']),
            'no_show' => array($bag['no_show_subject'], $bag['no_show_body']),
            'deposit_required' => array($bag['deposit_required_subject'], $bag['deposit_required_body']),
            'deposit_captured' => array($bag['deposit_captured_subject'], $bag['deposit_captured_body']),
            'deposit_refunded' => array($bag['deposit_refunded_subject'], $bag['deposit_refunded_body']),
        );

        if (!isset($map[$type])) return null;
        return array('subject' => $map[$type][0], 'body' => $map[$type][1]);
    }
}

if (!function_exists('_l9_send_reservation_line')) {
    /**
     * L-17 Phase 2B-1 / 2C: 予約通知の LINE 並行送信を試みる内部ヘルパ。
     *
     * 対応 type:
     *   - confirm      (Phase 2B-1, flag: notify_reservation_created)
     *   - reminder_24h (Phase 2C,   flag: notify_reservation_reminder_day)
     *
     * 以下の全てを満たす場合のみ push を実行する:
     *   1. type が対応範囲 (confirm / reminder_24h) にある
     *   2. tenant_line_settings が適用済で is_enabled=1
     *   3. tenant_line_settings.channel_access_token が空でない
     *   4. type 対応 notify_* flag が 1
     *   5. reservation.customer_id がセットされている
     *   6. reservation_customer_line_links に link_status='linked' な行がある
     *
     * 送信結果に関わらず reservation_notifications_log に channel='line' で
     * 1 行残す。送信失敗で exception は投げない (caller は email 結果を優先)。
     *
     * @return array ['attempted'=>bool, 'success'=>bool, 'error'=>?string]
     */
    function _l9_send_reservation_line($pdo, $reservation, $type, $store, $tpl) {
        // type → tenant_line_settings の flag カラム名 マップ
        // (Phase 2C: reminder_24h、Phase 2D: reminder_2h を追加。
        //  cancel / no_show / deposit_* / takeout_ready は未サポート)
        $typeFlagMap = array(
            'confirm'      => 'notify_reservation_created',
            'reminder_24h' => 'notify_reservation_reminder_day',
            'reminder_2h'  => 'notify_reservation_reminder_2h',
        );
        if (!isset($typeFlagMap[$type])) {
            return array('attempted' => false, 'success' => false, 'error' => 'LINE_TYPE_NOT_SUPPORTED');
        }
        $flagColumn = $typeFlagMap[$type];

        if (!function_exists('line_link_get_by_customer') || !function_exists('line_push_message')) {
            // ヘルパ未同梱 (phase 2A-1 未適用等) の環境では何もせず quiet skip
            return array('attempted' => false, 'success' => false, 'error' => 'LINE_HELPERS_MISSING');
        }
        if (empty($reservation['customer_id'])) {
            return array('attempted' => false, 'success' => false, 'error' => 'NO_CUSTOMER_ID');
        }
        $tenantId = isset($store['tenant_id']) ? $store['tenant_id'] : null;
        if (!$tenantId) {
            return array('attempted' => false, 'success' => false, 'error' => 'NO_TENANT_ID');
        }

        // tenant_line_settings を参照 (テーブル未作成なら silent skip)
        // SELECT * でカラム未追加の Phase 2D 未適用環境でも fallback が効く
        // (notify_reservation_reminder_2h が $row にない場合 isset で 0 扱い)
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM tenant_line_settings WHERE tenant_id = ?'
            );
            $stmt->execute(array($tenantId));
            $settings = $stmt->fetch();
        } catch (PDOException $e) {
            return array('attempted' => false, 'success' => false, 'error' => 'LINE_SETTINGS_TABLE_MISSING');
        }
        if (!$settings) {
            return array('attempted' => false, 'success' => false, 'error' => 'NO_LINE_SETTINGS');
        }
        if ((int)$settings['is_enabled'] !== 1) {
            return array('attempted' => false, 'success' => false, 'error' => 'LINE_NOT_ENABLED');
        }
        if ((int)($settings[$flagColumn] ?? 0) !== 1) {
            return array('attempted' => false, 'success' => false, 'error' => 'NOTIFY_FLAG_OFF');
        }
        if (empty($settings['channel_access_token'])) {
            return array('attempted' => false, 'success' => false, 'error' => 'NO_ACCESS_TOKEN');
        }

        // linked customer -> line_user_id 解決 (未連携なら skip)
        $link = line_link_get_by_customer($pdo, $tenantId, $reservation['customer_id']);
        if (!$link || empty($link['line_user_id'])) {
            return array('attempted' => false, 'success' => false, 'error' => 'NOT_LINKED');
        }

        // LINE は subject 概念がないので「件名 + 本文」を 1 text message に結合。
        // 本文はメールと同じ template をそのまま流用 (多言語対応は email と共通)。
        $text = $tpl['subject'] . "\n\n" . $tpl['body'];
        $messages = array(line_text_message($text));

        $r = line_push_message($settings['channel_access_token'], $link['line_user_id'], $messages);

        _l9_log_notification(
            $pdo,
            $reservation['id'],
            $reservation['store_id'],
            $type,
            'line:' . substr($link['line_user_id'], 0, 8) . '…',
            $tpl['subject'],
            $text,
            $r['success'] ? 'sent' : 'failed',
            $r['success'] ? null : (isset($r['error']) ? (string)$r['error'] : 'LINE_SEND_FAILED'),
            'line',
            15
        );

        return array(
            'attempted' => true,
            'success'   => (bool)$r['success'],
            'error'     => $r['success'] ? null : (isset($r['error']) ? $r['error'] : 'LINE_SEND_FAILED'),
        );
    }
}

if (!function_exists('_l9_send_reservation_sms')) {
    /**
     * 店舗設定の sms_webhook_url に JSON POST する汎用 SMS 連携。
     * Twilio 等の実プロバイダはこの Webhook 側で吸収する。
     */
    function _l9_send_reservation_sms($pdo, $reservation, $type, $store, $tpl, $settings) {
        if ((int)($settings['sms_enabled'] ?? 0) !== 1) {
            return array('attempted' => false, 'success' => false, 'error' => 'SMS_NOT_ENABLED');
        }
        if (empty($settings['sms_webhook_url'])) {
            return array('attempted' => false, 'success' => false, 'error' => 'NO_SMS_WEBHOOK');
        }
        if (empty($reservation['customer_phone'])) {
            return array('attempted' => false, 'success' => false, 'error' => 'NO_PHONE');
        }
        $url = (string)$settings['sms_webhook_url'];
        if (!preg_match('/^https:\/\//', $url)) {
            return array('attempted' => false, 'success' => false, 'error' => 'SMS_WEBHOOK_REQUIRES_HTTPS');
        }
        $message = $tpl['subject'] . "\n" . mb_substr($tpl['body'], 0, 420);
        $payload = array(
            'type' => $type,
            'store_id' => $reservation['store_id'],
            'reservation_id' => $reservation['id'],
            'phone' => $reservation['customer_phone'],
            'message' => $message,
        );
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 10,
        ));
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $success = (!$err && $code >= 200 && $code < 300);
        $error = $success ? null : ($err ?: ('HTTP_' . $code . ':' . mb_substr((string)$raw, 0, 160)));
        $logId = _l9_log_notification(
            $pdo,
            $reservation['id'],
            $reservation['store_id'],
            $type,
            'sms:' . $reservation['customer_phone'],
            $tpl['subject'],
            $message,
            $success ? 'sent' : 'failed',
            $error,
            'sms',
            (int)($settings['reminder_retry_minutes'] ?? 15)
        );
        return array('attempted' => true, 'success' => $success, 'error' => $error, 'log_id' => $logId);
    }
}

if (!function_exists('send_reservation_notification')) {
    /**
     * 予約通知のメインエントリ (メール + L-17 Phase 2B-1 で confirm だけ LINE 並行)
     *
     * email 送信結果が基本の返り値 (caller との互換性)。LINE 送信は confirm 時
     * にのみ並行で試み、結果は reservation_notifications_log に channel='line'
     * で残す。LINE 送信失敗でこの関数の success/error は変わらない。
     *
     * @return array ['success'=>bool, 'error'=>string|null]
     */
    function send_reservation_notification($pdo, $reservation, $type, $extra = array()) {
        $store = _l9_resolve_store_info($pdo, $reservation['store_id']);
        if (!$store) return array('success' => false, 'error' => 'STORE_NOT_FOUND');

        $settings = get_reservation_settings($pdo, $reservation['store_id']);
        $lang = isset($reservation['language']) ? $reservation['language'] : 'ja';
        $extra['cancel_deadline_hours'] = $settings['cancel_deadline_hours'];
        $tpl = build_reservation_template($type, $reservation, $store, $lang, $extra);
        if (!$tpl) return array('success' => false, 'error' => 'UNKNOWN_TEMPLATE');

        $channels = array();
        $lineResult = array('attempted' => false, 'success' => false, 'error' => null);
        $smsResult = array('attempted' => false, 'success' => false, 'error' => null);
        try {
            $lineResult = _l9_send_reservation_line($pdo, $reservation, $type, $store, $tpl);
        } catch (Exception $e) {
            error_log('[L-17 line_send] ' . $type . ': ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        }
        if (!empty($lineResult['attempted'])) $channels['line'] = $lineResult;

        // メール送信 (従来挙動)
        $emailResult = array('success' => false, 'error' => 'NO_EMAIL', 'log_id' => null);
        if (empty($reservation['customer_email'])) {
            $channels['email'] = $emailResult;
        } else {
            $fromName = $store['name'];
            $fromEmail = posla_mail_default_from_email();
            $replyTo = !empty($settings['notification_email']) ? $settings['notification_email'] : null;

            $r = _l9_mail_send($reservation['customer_email'], $tpl['subject'], $tpl['body'], $fromName, $fromEmail, $replyTo);
            $emailLogId = _l9_log_notification(
                $pdo,
                $reservation['id'],
                $reservation['store_id'],
                $type,
                $reservation['customer_email'],
                $tpl['subject'],
                $tpl['body'],
                $r['success'] ? 'sent' : 'failed',
                $r['success'] ? null : $r['error'],
                'email',
                (int)($settings['reminder_retry_minutes'] ?? 15)
            );
            $emailResult = array('success' => !empty($r['success']), 'error' => $r['success'] ? null : $r['error'], 'log_id' => $emailLogId);
            $channels['email'] = $emailResult;
        }

        try {
            $smsResult = _l9_send_reservation_sms($pdo, $reservation, $type, $store, $tpl, $settings);
        } catch (Exception $e) {
            $smsResult = array('attempted' => true, 'success' => false, 'error' => $e->getMessage());
        }
        if (!empty($smsResult['attempted'])) $channels['sms'] = $smsResult;

        $success = !empty($emailResult['success']) || !empty($lineResult['success']) || !empty($smsResult['success']);
        $err = $success ? null : (!empty($emailResult['error']) ? $emailResult['error'] : (!empty($lineResult['error']) ? $lineResult['error'] : (!empty($smsResult['error']) ? $smsResult['error'] : 'SEND_FAILED')));
        return array(
            'success' => $success,
            'error' => $err,
            'email_log_id' => $emailResult['log_id'],
            'channels' => $channels,
        );
    }
}
