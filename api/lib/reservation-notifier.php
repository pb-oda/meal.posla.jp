<?php
/**
 * L-9 予約管理 — メール送信ライブラリ
 *
 * - 抽象化されたインターフェース。現在は PHP mb_send_mail() 実装、
 *   将来 SendGrid/SES 等に差し替え可能
 * - 送信ログは reservation_notifications_log に記録
 * - 多言語 (ja/en/zh-Hans/ko) テンプレート対応
 */

if (!function_exists('_l9_mail_send')) {
    /**
     * 実送信。返り値: ['success'=>bool, 'error'=>string|null]
     */
    function _l9_mail_send($to, $subject, $body, $fromName, $fromEmail, $replyTo = null) {
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return array('success' => false, 'error' => 'INVALID_RECIPIENT');
        }
        if (function_exists('mb_language')) {
            mb_language('Japanese');
            mb_internal_encoding('UTF-8');
        }
        $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
        $headers = "From: " . $fromHeader . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: " . $replyTo . "\r\n";
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = false;
        if (function_exists('mb_send_mail')) {
            $ok = @mb_send_mail($to, $subject, $body, $headers);
        } else {
            $ok = @mail($to, $encodedSubject, $body, $headers);
        }
        if (!$ok) {
            return array('success' => false, 'error' => 'MAIL_SEND_FAILED');
        }
        return array('success' => true, 'error' => null);
    }
}

if (!function_exists('_l9_log_notification')) {
    function _l9_log_notification($pdo, $reservationId, $storeId, $type, $recipient, $subject, $body, $status, $error = null) {
        try {
            $logId = bin2hex(random_bytes(18));
            $pdo->prepare(
                "INSERT INTO reservation_notifications_log
                 (id, reservation_id, store_id, notification_type, channel, recipient, subject, body_excerpt, status, error_message, sent_at, created_at)
                 VALUES (?, ?, ?, ?, 'email', ?, ?, ?, ?, ?, ?, NOW())"
            )->execute(array(
                $logId, $reservationId, $storeId, $type, $recipient,
                mb_substr($subject, 0, 250), mb_substr($body, 0, 1000),
                $status, $error,
                $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ));
        } catch (PDOException $e) {
            error_log('[L-9][notifier] log_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
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

if (!function_exists('send_reservation_notification')) {
    /**
     * 予約通知メール送信のメインエントリ
     *
     * @return array ['success'=>bool, 'error'=>string|null]
     */
    function send_reservation_notification($pdo, $reservation, $type, $extra = array()) {
        if (empty($reservation['customer_email'])) {
            return array('success' => false, 'error' => 'NO_EMAIL');
        }
        $store = _l9_resolve_store_info($pdo, $reservation['store_id']);
        if (!$store) return array('success' => false, 'error' => 'STORE_NOT_FOUND');

        $settings = get_reservation_settings($pdo, $reservation['store_id']);
        $lang = isset($reservation['language']) ? $reservation['language'] : 'ja';
        $extra['cancel_deadline_hours'] = $settings['cancel_deadline_hours'];
        $tpl = build_reservation_template($type, $reservation, $store, $lang, $extra);
        if (!$tpl) return array('success' => false, 'error' => 'UNKNOWN_TEMPLATE');

        $fromName = $store['name'];
        $fromEmail = 'noreply@eat.posla.jp';
        $replyTo = !empty($settings['notification_email']) ? $settings['notification_email'] : null;

        $r = _l9_mail_send($reservation['customer_email'], $tpl['subject'], $tpl['body'], $fromName, $fromEmail, $replyTo);
        _l9_log_notification(
            $pdo,
            $reservation['id'],
            $reservation['store_id'],
            $type,
            $reservation['customer_email'],
            $tpl['subject'],
            $tpl['body'],
            $r['success'] ? 'sent' : 'failed',
            $r['success'] ? null : $r['error']
        );
        return $r;
    }
}
