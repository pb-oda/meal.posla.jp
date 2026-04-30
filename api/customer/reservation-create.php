<?php
/**
 * L-9 予約管理 (客側) — 予約作成 + ホールド消費
 *
 * POST /api/customer/reservation-create.php
 *   body: { store_id, customer_name, customer_phone, customer_email?, party_size, reserved_at, memo?, language?, course_id?, hold_id? }
 *
 * フロー:
 *   1. レートリミット (IP per minute)
 *   2. 入力検証
 *   3. 空席ダブルチェック (compute_slot_availability)
 *   4. 顧客台帳 upsert + ブラックリストチェック
 *   5. テーブル自動割当 (suggested_tables)
 *   6. デポジット必要なら status='pending' で予約作成し、checkout_url を返す
 *      → 客は決済完了後 deposit-webhook で 'confirmed' に昇格
 *      → 不要なら status='confirmed' で完了
 *   7. 確認メール送信
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/reservation-availability.php';
require_once __DIR__ . '/../lib/reservation-deposit.php';
require_once __DIR__ . '/../lib/reservation-history.php';
require_once __DIR__ . '/../lib/reservation-waitlist.php';
require_once __DIR__ . '/../lib/reservation-risk.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/reservation-notifier.php';

require_method(['POST']);

// レートリミット (IP/minute = 5回)
check_rate_limit('reserve-create:' . _l9_get_client_ip(), 5, 60);

$pdo = get_db();
$body = get_json_body();

$storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);

$sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores WHERE id = ? AND is_active = 1');
$sStmt->execute([$storeId]);
$store = $sStmt->fetch();
if (!$store) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);

$settings = get_reservation_settings($pdo, $storeId);
if ((int)$settings['online_enabled'] !== 1) json_error('RESERVATION_DISABLED', 'オンライン予約は受け付けていません', 403);

// 入力検証
$name = trim((string)($body['customer_name'] ?? ''));
if ($name === '' || mb_strlen($name) > 120) json_error('INVALID_NAME', 'お名前を入力してください', 400);
$phone = trim((string)($body['customer_phone'] ?? ''));
$email = trim((string)($body['customer_email'] ?? ''));
if ((int)$settings['require_phone'] === 1 && $phone === '') json_error('PHONE_REQUIRED', '電話番号を入力してください', 400);
if ((int)$settings['require_email'] === 1 && $email === '') json_error('EMAIL_REQUIRED', 'メールアドレスを入力してください', 400);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('INVALID_EMAIL', 'メールアドレスが不正です', 400);
if ($phone !== '' && (mb_strlen($phone) > 40 || !preg_match('/^[0-9+\-\s()]+$/', $phone))) json_error('INVALID_PHONE', '電話番号が不正です', 400);

$partySize = (int)($body['party_size'] ?? 0);
if ($partySize < (int)$settings['min_party_size'] || $partySize > (int)$settings['max_party_size']) {
    json_error('INVALID_PARTY_SIZE', '人数が範囲外です', 400);
}

$reservedAtRaw = (string)($body['reserved_at'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $reservedAtRaw)) json_error('INVALID_DATETIME', '日時形式が不正です', 400);
$reservedTs = strtotime($reservedAtRaw);
if (!$reservedTs) json_error('INVALID_DATETIME', '日時を解釈できません', 400);
$reservedAt = date('Y-m-d H:i:s', $reservedTs);

// リードタイム
if ($reservedTs < time() + (int)$settings['lead_time_hours'] * 3600) {
    json_error('LEAD_TIME_VIOLATION', '予約締切時刻を過ぎています', 400);
}

// 受付期間
$maxAdvanceTs = time() + (int)$settings['max_advance_days'] * 86400;
if ($reservedTs > $maxAdvanceTs) json_error('TOO_FAR', '受付期間を超えています', 400);

$source = isset($body['source']) && in_array($body['source'], ['web','ai_chat'], true) ? $body['source'] : 'web';
$waitlistId = isset($body['waitlist_id']) ? trim((string)$body['waitlist_id']) : '';
$waitlistFingerprint = null;
if ($waitlistId !== '') {
    $wStmt = $pdo->prepare('SELECT id FROM reservation_waitlist_candidates WHERE id = ? AND store_id = ? AND status IN ("waiting","notified") LIMIT 1');
    $wStmt->execute([$waitlistId, $storeId]);
    if ($wStmt->fetch()) {
        $waitlistFingerprint = 'waitlist:' . $waitlistId;
    }
}

// ブラックリスト確認
if ($phone) {
    $blStmt = $pdo->prepare('SELECT is_blacklisted FROM reservation_customers WHERE store_id = ? AND customer_phone = ?');
    $blStmt->execute([$storeId, $phone]);
    $bl = $blStmt->fetch();
    if ($bl && (int)$bl['is_blacklisted'] === 1) {
        json_error('CANNOT_RESERVE', 'この番号からは予約できません。お電話で店舗へお問い合わせください', 403);
    }
}

// 空席ダブルチェック (事前判定)
purge_expired_holds($pdo, $storeId);
$date = date('Y-m-d', $reservedTs);
$timeStr = date('H:i', $reservedTs);
$slots = compute_slot_availability($pdo, $storeId, $date, $partySize, null, $waitlistFingerprint, $source);
$matched = null;
foreach ($slots as $s) { if ($s['time'] === $timeStr) { $matched = $s; break; } }
if (!$matched || !$matched['available']) {
    $reason = $matched && isset($matched['reason']) ? $matched['reason'] : 'full';
    if ($reason === 'phone_only') {
        json_error('PHONE_ONLY_SLOT', 'この人数はオンライン予約では受け付けていません。店舗へお電話ください', 409);
    }
    if ($matched && !empty($body['join_waitlist']) && !in_array($reason, ['lead_time','phone_only'], true)) {
        $waitlistId = reservation_waitlist_create($pdo, $store, [
            'desired_at' => $reservedAt,
            'party_size' => $partySize,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_email' => $email,
            'memo' => isset($body['memo']) ? (string)$body['memo'] : null,
            'language' => isset($body['language']) ? (string)$body['language'] : 'ja',
            'source' => isset($body['source']) && $body['source'] === 'ai_chat' ? 'ai_chat' : 'web',
        ]);
        json_response([
            'waitlist_id' => $waitlistId,
            'status' => 'waitlist_registered',
            'message' => 'キャンセル待ちに登録しました。空席が出た場合にご連絡します。',
        ]);
    }
    json_error('SLOT_UNAVAILABLE', 'お選びの時間は満席です。別の時間をお試しください', 409);
}
$tableIds = isset($matched['suggested_tables']) ? $matched['suggested_tables'] : [];
// S3 #7: 並列予約のレース検出は INSERT トランザクション内で行う (下記)

// 顧客 upsert (ロジックは store/reservations.php と同じだが客側用に inline)
function _l9_cust_upsert_public($pdo, $tenantId, $storeId, $name, $phone, $email) {
    if (empty($phone)) {
        $cid = bin2hex(random_bytes(18));
        $pdo->prepare('INSERT INTO reservation_customers (id, tenant_id, store_id, customer_name, customer_email, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([$cid, $tenantId, $storeId, $name, $email]);
        return $cid;
    }
    $stmt = $pdo->prepare('SELECT id FROM reservation_customers WHERE store_id = ? AND customer_phone = ?');
    $stmt->execute([$storeId, $phone]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->prepare('UPDATE reservation_customers SET customer_name = ?, customer_email = COALESCE(NULLIF(?, ""), customer_email), updated_at = NOW() WHERE id = ?')
            ->execute([$name, $email, $row['id']]);
        return $row['id'];
    }
    $cid = bin2hex(random_bytes(18));
    $pdo->prepare('INSERT INTO reservation_customers (id, tenant_id, store_id, customer_name, customer_phone, customer_email, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$cid, $tenantId, $storeId, $name, $phone, $email]);
    return $cid;
}
function _l9_get_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

$customerId = _l9_cust_upsert_public($pdo, $store['tenant_id'], $storeId, $name, $phone, $email);
$custRiskStmt = $pdo->prepare('SELECT no_show_count, cancel_count, is_blacklisted FROM reservation_customers WHERE id = ? AND store_id = ?');
$custRiskStmt->execute([$customerId, $storeId]);
$customerRisk = $custRiskStmt->fetch() ?: ['no_show_count' => 0, 'cancel_count' => 0, 'is_blacklisted' => 0];
$nearBlacklist = reservation_customer_has_near_blacklist($pdo, $storeId, $phone, $email, $name, $customerId);

// デポジット判定
$depositRequired = 0;
$depositAmount = 0;
$depositStatus = 'not_required';
$highRiskDeposit = ((int)($settings['high_risk_deposit_enabled'] ?? 0) === 1)
    && (
        (int)$customerRisk['no_show_count'] >= max(1, (int)($settings['high_risk_deposit_min_no_show_count'] ?? 2))
        || (int)$partySize >= max(1, (int)($settings['high_risk_deposit_large_party_size'] ?? 8))
        || $nearBlacklist
    );
if (reservation_deposit_is_available($pdo, $storeId, $store['tenant_id'], $settings)) {
    $amt = reservation_deposit_amount($settings, $partySize);
    if ($amt <= 0 && $highRiskDeposit) {
        $amt = (int)$settings['deposit_per_person'] * (int)$partySize;
    }
    if ($amt > 0) {
        $depositRequired = 1;
        $depositAmount = $amt;
        $depositStatus = 'pending';
    }
}

$courseId = isset($body['course_id']) ? trim((string)$body['course_id']) : null;
$courseName = null;
if ($courseId) {
    $cStmt = $pdo->prepare('SELECT name, duration_min, max_party_size, min_party_size FROM reservation_courses WHERE id = ? AND store_id = ? AND is_active = 1');
    $cStmt->execute([$courseId, $storeId]);
    $cr = $cStmt->fetch();
    if (!$cr) json_error('COURSE_NOT_FOUND', 'コースが見つかりません', 404);
    $courseName = $cr['name'];
    if ($partySize < (int)$cr['min_party_size'] || $partySize > (int)$cr['max_party_size']) {
        json_error('COURSE_PARTY_MISMATCH', 'このコースの対象人数外です', 400);
    }
}

$resId = bin2hex(random_bytes(18));
$editToken = bin2hex(random_bytes(24));
$status = $depositRequired === 1 ? 'pending' : 'confirmed';
$language = isset($body['language']) ? (string)$body['language'] : 'ja';
$memo = isset($body['memo']) ? (string)$body['memo'] : null;

// ── S3 #7: 二重予約防止トランザクション ──
// BEGIN → 同店舗の同時間帯予約を FOR UPDATE → 空き再計算 → INSERT を atomic に行う。
// (assigned_table_ids は JSON のため per-table UNIQUE は不可。複合 UNIQUE は
//  migration-s3-reservation-uniq.sql で (store_id, reserved_at, customer_phone) を追加し
//  完全な同一申込の二重 INSERT を DB レベルでも防止する。)
$pdo->beginTransaction();
try {
    // 同日帯の既存予約 (取消・no_show 以外) を FOR UPDATE で行ロック
    $rangeFrom = date('Y-m-d 00:00:00', strtotime($date . ' -1 day'));
    $rangeTo   = date('Y-m-d 23:59:59', strtotime($date . ' +1 day'));
    $lockStmt = $pdo->prepare(
        "SELECT id FROM reservations
         WHERE store_id = ?
           AND reserved_at BETWEEN ? AND ?
           AND status NOT IN ('cancelled','no_show')
         FOR UPDATE"
    );
    $lockStmt->execute([$storeId, $rangeFrom, $rangeTo]);
    $lockStmt->fetchAll(); // ロック取得が目的

    // 空き再計算 (FOR UPDATE 後に実行することで TOCTOU を排除)
    $slots2 = compute_slot_availability($pdo, $storeId, $date, $partySize, null, $waitlistFingerprint, $source);
    $matched2 = null;
    foreach ($slots2 as $s2) { if ($s2['time'] === $timeStr) { $matched2 = $s2; break; } }
    if (!$matched2 || !$matched2['available']) {
        $pdo->rollBack();
        $reason2 = $matched2 && isset($matched2['reason']) ? $matched2['reason'] : 'full';
        if ($reason2 === 'phone_only') {
            json_error('PHONE_ONLY_SLOT', 'この人数はオンライン予約では受け付けていません。店舗へお電話ください', 409);
        }
        if ($matched2 && !empty($body['join_waitlist']) && !in_array($reason2, ['lead_time','phone_only'], true)) {
            $waitlistId = reservation_waitlist_create($pdo, $store, [
                'desired_at' => $reservedAt,
                'party_size' => $partySize,
                'customer_name' => $name,
                'customer_phone' => $phone,
                'customer_email' => $email,
                'memo' => isset($body['memo']) ? (string)$body['memo'] : null,
                'language' => isset($body['language']) ? (string)$body['language'] : 'ja',
                'source' => $source,
            ]);
            json_response([
                'waitlist_id' => $waitlistId,
                'status' => 'waitlist_registered',
                'message' => 'キャンセル待ちに登録しました。空席が出た場合にご連絡します。',
            ]);
        }
        json_error('SLOT_FULL', 'お選びの時間は満席になりました。別の時間をお試しください', 409);
    }
    // 再算出された割当を採用 (古い $tableIds を上書き)
    $tableIds = isset($matched2['suggested_tables']) ? $matched2['suggested_tables'] : [];

    $pdo->prepare(
        "INSERT INTO reservations
         (id, tenant_id, store_id, customer_name, customer_phone, customer_email, party_size, reserved_at, duration_min, status, source, assigned_table_ids, course_id, course_name, memo, language, customer_id, deposit_required, deposit_amount, deposit_status, cancel_policy_hours, edit_token, created_at, updated_at, confirmed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)"
    )->execute([
        $resId, $store['tenant_id'], $storeId, $name, $phone, $email, $partySize, $reservedAt,
        (int)$settings['default_duration_min'], $status, $source,
        $tableIds ? json_encode($tableIds, JSON_UNESCAPED_UNICODE) : null,
        $courseId, $courseName, $memo, $language, $customerId,
        $depositRequired, $depositAmount, $depositStatus, (int)$settings['cancel_deadline_hours'], $editToken,
        $status === 'confirmed' ? date('Y-m-d H:i:s') : null,
    ]);

    // hold 削除 (使われたものを掃除)
    if (!empty($body['hold_id'])) {
        try { $pdo->prepare('DELETE FROM reservation_holds WHERE id = ? AND store_id = ?')->execute([$body['hold_id'], $storeId]); } catch (PDOException $e) {}
    }
    reservation_waitlist_mark_booked($pdo, $storeId, $resId, $reservedAt, $partySize, $phone, $email, $waitlistId ?: null);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // S3 #7: 複合 UNIQUE 制約 (store_id, reserved_at, customer_phone) 衝突は SLOT_FULL で返す
    if ($e->getCode() === '23000') {
        error_log('[S3#7][reservation-create] duplicate_unique store=' . $storeId . ' phone=' . $phone . ' at=' . $reservedAt, 3, POSLA_PHP_ERROR_LOG);
        json_error('SLOT_FULL', 'お選びの時間は同一の予約が既に登録されています', 409);
    }
    error_log('[S3#7][reservation-create] tx_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '予約の登録に失敗しました', 500);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[S3#7][reservation-create] tx_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '予約の登録に失敗しました', 500);
}

$rStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
$rStmt->execute([$resId]);
$r = $rStmt->fetch();
if ($r) {
    reservation_history_record($pdo, $r, 'customer', null, $name, 'created', 'status', null, $r['status']);
}

$response = [
    'reservation_id' => $resId,
    'edit_token' => $editToken,
    'status' => $status,
    'deposit_required' => $depositRequired,
    'deposit_amount' => $depositAmount,
    'high_risk_deposit_required' => ($depositRequired === 1 && $highRiskDeposit) ? 1 : 0,
    'edit_url' => app_url('/customer/reserve-detail.html') . '?id=' . urlencode($resId) . '&t=' . urlencode($editToken),
];

// デポジット必要なら Checkout URL を生成
if ($depositRequired === 1) {
    $base = app_url('/customer/reserve-detail.html');
    $successUrl = $base . '?id=' . urlencode($resId) . '&t=' . urlencode($editToken) . '&deposit=success';
    $cancelUrl = $base . '?id=' . urlencode($resId) . '&t=' . urlencode($editToken) . '&deposit=cancel';
    $checkout = reservation_deposit_create_checkout($pdo, $r, $store, $depositAmount, $successUrl, $cancelUrl);
    if ($checkout['success']) {
        $pdo->prepare("UPDATE reservations SET deposit_session_id = ? WHERE id = ?")->execute([$checkout['session_id'], $resId]);
        $response['deposit_checkout_url'] = $checkout['checkout_url'];
        $response['deposit_session_id'] = $checkout['session_id'];
        // デポジット用通知
        send_reservation_notification($pdo, $r, 'deposit_required', ['deposit_url' => $checkout['checkout_url']]);
    } else {
        $pdo->prepare("UPDATE reservations SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = 'deposit_checkout_failed' WHERE id = ?")->execute([$resId]);
        json_error('DEPOSIT_CHECKOUT_FAILED', '予約金決済の準備に失敗しました: ' . ($checkout['error'] ?: 'unknown'), 503);
    }
} else {
    // 確定通知 (メール / LINE / SMS)
    send_reservation_notification($pdo, $r, 'confirm', ['edit_url' => $response['edit_url']]);
}

json_response($response);
