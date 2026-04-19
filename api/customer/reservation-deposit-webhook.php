<?php
/**
 * L-9 予約管理 — Stripe Webhook (デポジット決済結果)
 *
 * Stripe からの POST を受け取り、checkout.session.completed イベントで
 * 該当予約の deposit_status を 'authorized' に更新し、status を 'confirmed' に昇格する
 *
 * Stripe ダッシュボードで以下のイベントを購読しておく:
 *   - checkout.session.completed
 *   - checkout.session.expired
 *   - payment_intent.canceled
 *   - payment_intent.payment_failed
 *
 * 認証: Stripe-Signature ヘッダ (webhook_secret は posla_settings から)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/reservation-notifier.php';

// Webhook 専用エンドポイント。応答は最小化 (Stripe は 2xx を期待)
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo '{"ok":false}'; exit;
}

$payload = file_get_contents('php://input');
$sig = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

$pdo = get_db();

// webhook secret 取得 (posla_settings)
$secret = null;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM posla_settings WHERE setting_key = 'stripe_webhook_secret_reservation'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) $secret = $row['setting_value'];
} catch (PDOException $e) {
    error_log('[L-9][webhook] secret_load_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

// S2 P1 #10: secret 未設定時は fail-close (偽 webhook で予約状態を改ざんされるのを防ぐ)
if (!$secret) {
    error_log('[L-9][webhook] FAIL_CLOSE: stripe_webhook_secret_reservation not configured — request rejected', 3, '/home/odah/log/php_errors.log');
    http_response_code(503); echo '{"ok":false,"error":"webhook_secret_not_configured"}'; exit;
}
if (!_l9_verify_stripe_sig($payload, $sig, $secret)) {
    http_response_code(401); echo '{"ok":false,"error":"bad_sig"}'; exit;
}

$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) {
    http_response_code(400); echo '{"ok":false}'; exit;
}

$type = $event['type'];
$obj = isset($event['data']['object']) ? $event['data']['object'] : [];
$reservationId = isset($obj['metadata']['reservation_id']) ? $obj['metadata']['reservation_id'] : null;
if (!$reservationId && isset($obj['payment_intent'])) {
    // PI 単独イベント時は metadata から取得
    if (isset($obj['metadata']['reservation_id'])) {
        $reservationId = $obj['metadata']['reservation_id'];
    }
}
if (!$reservationId) {
    // session_id でも逆引き
    $sessId = isset($obj['id']) ? $obj['id'] : null;
    if ($sessId) {
        $stmt = $pdo->prepare('SELECT id FROM reservations WHERE deposit_session_id = ? OR deposit_payment_intent_id = ?');
        $stmt->execute([$sessId, $sessId]);
        $row = $stmt->fetch();
        if ($row) $reservationId = $row['id'];
    }
}
if (!$reservationId) {
    error_log('[L-9][webhook] no_reservation_match type=' . $type, 3, '/home/odah/log/php_errors.log');
    http_response_code(200); echo '{"ok":true,"note":"no_match"}'; exit;
}

$rStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
$rStmt->execute([$reservationId]);
$r = $rStmt->fetch();
if (!$r) { http_response_code(200); echo '{"ok":true,"note":"not_found"}'; exit; }

// ── P0 #5: metadata 照合 (別予約 session の流用攻撃を阻止) ──
// checkout.session.completed の場合は obj['amount_total'] / obj['currency'] / obj['metadata'] が、
// payment_intent.* の場合は obj['amount'] / obj['currency'] / obj['metadata'] がある
$mdObj = isset($obj['metadata']) && is_array($obj['metadata']) ? $obj['metadata'] : array();
$mdResId = isset($mdObj['reservation_id']) ? (string)$mdObj['reservation_id'] : '';
$mdStoreId = isset($mdObj['store_id']) ? (string)$mdObj['store_id'] : '';
$mdTenantId = isset($mdObj['tenant_id']) ? (string)$mdObj['tenant_id'] : '';
$mdExpAmt = isset($mdObj['expected_amount']) ? (int)$mdObj['expected_amount'] : -1;
$mdExpCur = isset($mdObj['expected_currency']) ? strtolower((string)$mdObj['expected_currency']) : '';
$mdPurpose = isset($mdObj['purpose']) ? (string)$mdObj['purpose'] : '';

// reservation の store/tenant を取得
$storeStmt = $pdo->prepare('SELECT id, tenant_id FROM stores WHERE id = ?');
$storeStmt->execute([$r['store_id']]);
$storeRow = $storeStmt->fetch();
$expectedTenant = $storeRow ? (string)$storeRow['tenant_id'] : '';
$expectedStore = (string)$r['store_id'];

// 金額/通貨 (Session/PI のどちらか持っている方)
$objAmount = null;
if (isset($obj['amount_total'])) $objAmount = (int)$obj['amount_total'];
elseif (isset($obj['amount'])) $objAmount = (int)$obj['amount'];
$objCur = isset($obj['currency']) ? strtolower((string)$obj['currency']) : '';

// metadata がある webhook イベントでは厳格に照合 (deposit-related のみ。決済成立イベントだけ照合)
$mismatchEvents = array('checkout.session.completed', 'payment_intent.succeeded');
if (in_array($type, $mismatchEvents, true)) {
    if ($mdResId === '' || $mdStoreId === '' || $mdTenantId === '' || $mdExpAmt < 0 || $mdExpCur === '') {
        error_log('[P0#5][reserve-deposit-webhook] STRIPE_MISMATCH metadata_missing type=' . $type . ' res=' . $reservationId, 3, '/home/odah/log/php_errors.log');
        http_response_code(403); echo '{"ok":false,"error":"STRIPE_MISMATCH","note":"metadata_missing"}'; exit;
    }
    if ($mdResId !== (string)$reservationId || $mdStoreId !== $expectedStore || $mdTenantId !== $expectedTenant) {
        error_log('[P0#5][reserve-deposit-webhook] STRIPE_MISMATCH context md_res=' . $mdResId . ' md_store=' . $mdStoreId . ' md_tenant=' . $mdTenantId . ' / db_res=' . $reservationId . ' db_store=' . $expectedStore . ' db_tenant=' . $expectedTenant, 3, '/home/odah/log/php_errors.log');
        http_response_code(403); echo '{"ok":false,"error":"STRIPE_MISMATCH","note":"context"}'; exit;
    }
    if ($mdPurpose !== 'reservation_deposit') {
        error_log('[P0#5][reserve-deposit-webhook] STRIPE_MISMATCH purpose=' . $mdPurpose, 3, '/home/odah/log/php_errors.log');
        http_response_code(403); echo '{"ok":false,"error":"STRIPE_MISMATCH","note":"purpose"}'; exit;
    }
    if ($mdExpCur !== 'jpy' || $objCur !== 'jpy') {
        error_log('[P0#5][reserve-deposit-webhook] STRIPE_MISMATCH currency md=' . $mdExpCur . ' stripe=' . $objCur, 3, '/home/odah/log/php_errors.log');
        http_response_code(403); echo '{"ok":false,"error":"STRIPE_MISMATCH","note":"currency"}'; exit;
    }
    if ($objAmount === null || $mdExpAmt !== $objAmount || $mdExpAmt !== (int)$r['deposit_amount']) {
        error_log('[P0#5][reserve-deposit-webhook] STRIPE_MISMATCH amount db=' . (int)$r['deposit_amount'] . ' md=' . $mdExpAmt . ' stripe=' . (int)$objAmount, 3, '/home/odah/log/php_errors.log');
        http_response_code(403); echo '{"ok":false,"error":"STRIPE_MISMATCH","note":"amount"}'; exit;
    }
}

if ($type === 'checkout.session.completed') {
    $piId = isset($obj['payment_intent']) ? $obj['payment_intent'] : null;
    $pdo->prepare("UPDATE reservations SET deposit_status = 'authorized', deposit_payment_intent_id = ?, status = CASE WHEN status = 'pending' THEN 'confirmed' ELSE status END, confirmed_at = COALESCE(confirmed_at, NOW()), updated_at = NOW() WHERE id = ?")
        ->execute([$piId, $reservationId]);
    $r2 = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $r2->execute([$reservationId]);
    $rr = $r2->fetch();
    if ($rr['customer_email']) {
        $editUrl = 'https://eat.posla.jp/public/customer/reserve-detail.html?id=' . urlencode($reservationId) . '&t=' . urlencode($rr['edit_token']);
        send_reservation_notification($pdo, $rr, 'deposit_captured');
        send_reservation_notification($pdo, $rr, 'confirm', ['edit_url' => $editUrl]);
    }
} elseif ($type === 'checkout.session.expired' || $type === 'payment_intent.payment_failed') {
    $pdo->prepare("UPDATE reservations SET deposit_status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$reservationId]);
} elseif ($type === 'payment_intent.canceled') {
    $pdo->prepare("UPDATE reservations SET deposit_status = 'released', updated_at = NOW() WHERE id = ?")->execute([$reservationId]);
} elseif ($type === 'payment_intent.succeeded') {
    $pdo->prepare("UPDATE reservations SET deposit_status = 'captured', updated_at = NOW() WHERE id = ?")->execute([$reservationId]);
}

http_response_code(200);
echo '{"ok":true}';

function _l9_verify_stripe_sig($payload, $header, $secret) {
    if (!$header) return false;
    // S3 #16: t=, v1= は複数現れうるため、v1 を配列で集める
    $timestamp = null;
    $sigs = array();
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') {
            $timestamp = $kv[1];
        } elseif ($kv[0] === 'v1') {
            $sigs[] = $kv[1];
        }
    }
    if (!$timestamp || empty($sigs)) return false;
    // S3 #16: Stripe 推奨の 5 分タイムスタンプ許容 (replay 攻撃対策)
    if (abs(time() - (int)$timestamp) > 300) {
        error_log('[S3#16][l9-webhook] timestamp_out_of_tolerance t=' . $timestamp . ' now=' . time(), 3, '/home/odah/log/php_errors.log');
        return false;
    }
    $signed = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
