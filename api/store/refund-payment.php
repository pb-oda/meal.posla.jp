<?php
/**
 * C-R1: 返金 API
 *
 * POST /api/store/refund-payment.php
 * Body: { payment_id, store_id, reason? }
 *
 * 全額返金のみ対応（部分返金は将来拡張）
 * manager 以上のみ実行可能
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/payment-gateway.php';
require_once __DIR__ . '/../lib/stripe-connect.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);
$user = require_role('manager');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) json_error('VALIDATION', 'リクエストボディが不正です', 400);

$paymentId = $data['payment_id'] ?? '';
$storeId   = $data['store_id'] ?? '';
$reason    = $data['reason'] ?? 'requested_by_customer';
$staffPin  = isset($data['staff_pin']) ? trim((string)$data['staff_pin']) : '';

if (!$paymentId || !$storeId) {
    json_error('VALIDATION', 'payment_id と store_id は必須です', 400);
}

// 店舗アクセス権チェック
require_store_access($storeId);

$pdo = get_db();

// CP1b: 返金担当スタッフ PIN を必須化（出勤中スタッフのみ）
$cashierUserId   = $user['user_id'];
$cashierUserName = null;
if ($staffPin === '') {
    json_error('PIN_REQUIRED', '返金には担当スタッフ PIN の入力が必要です', 400);
}
if (!preg_match('/^\d{4,8}$/', $staffPin)) {
    json_error('INVALID_PIN', '担当 PIN は 4〜8 桁の数字で入力してください', 400);
}
try {
    $today = date('Y-m-d');
    $pinStmt = $pdo->prepare(
        "SELECT u.id, u.display_name, u.cashier_pin_hash
         FROM users u
         INNER JOIN attendance_logs ar
           ON ar.user_id = u.id AND ar.store_id = ?
              AND DATE(ar.clock_in) = ? AND ar.clock_out IS NULL
         WHERE u.tenant_id = ? AND u.is_active = 1
               AND u.role IN ('staff', 'manager', 'owner')
               AND u.cashier_pin_hash IS NOT NULL"
    );
    $pinStmt->execute([$storeId, $today, $user['tenant_id']]);
    $candidates = $pinStmt->fetchAll();
} catch (PDOException $e) {
    json_error('MIGRATION', 'PIN 機能のマイグレーションが未適用です (migration-cp1-cashier-pin.sql / migration-l3-shift-management.sql)', 500);
}
$pinVerified = false;
foreach ($candidates as $cand) {
    if ($cand['cashier_pin_hash'] && password_verify($staffPin, $cand['cashier_pin_hash'])) {
        $cashierUserId   = $cand['id'];
        $cashierUserName = $cand['display_name'];
        $pinVerified     = true;
        break;
    }
}
if (!$pinVerified) {
    @write_audit_log($pdo, $user, $storeId, 'cashier_pin_failed', 'payment', $paymentId, null, ['pin_length' => strlen($staffPin), 'context' => 'refund'], null);
    json_error('PIN_INVALID', 'PIN が一致しないか、対象スタッフが出勤中ではありません', 401);
}

// refund カラム存在チェック（graceful degradation）
$hasRefundCols = false;
try {
    $pdo->query('SELECT refund_status FROM payments LIMIT 0');
    $hasRefundCols = true;
} catch (PDOException $e) {
    json_error('MIGRATION_REQUIRED', '返金機能のマイグレーションが未適用です', 500);
}

// ── S3 #10: 返金 race-condition 対策 ──
// (1) BEGIN → SELECT FOR UPDATE で payment 行をロック
// (2) 状態を厳密に再確認 (TOCTOU 対策)
// (3) refund_status='none' のみ条件で 'pending' に UPDATE (affected_rows=1 でなければ ALREADY_REFUNDED)
// (4) COMMIT してから Stripe Refund API を呼ぶ (外部 API はトランザクション外)
// (5) 成功後に再 BEGIN → refund_status='full' で確定 UPDATE
// 'pending' 状態は migration-s3-refund-pending.sql で payments.refund_status ENUM に追加

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'SELECT id, total_amount, payment_method, gateway_name, external_payment_id,
                gateway_status, refund_status, refund_amount, store_id
         FROM payments
         WHERE id = ? AND store_id = ?
         FOR UPDATE'
    );
    $stmt->execute([$paymentId, $storeId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        $pdo->rollBack();
        json_error('NOT_FOUND', '指定された決済が見つかりません', 404);
    }

    // 現金は返金不可（手渡しで対応）
    if ($payment['payment_method'] === 'cash') {
        $pdo->rollBack();
        json_error('CASH_REFUND', '現金決済はシステム返金できません。手動で返金してください。', 400);
    }

    // gateway_name チェック
    if (!$payment['gateway_name'] || !$payment['external_payment_id']) {
        $pdo->rollBack();
        json_error('NO_GATEWAY', 'この決済は外部決済ゲートウェイを使用していません', 400);
    }

    // 返金済みチェック (再確認)
    if ($payment['refund_status'] !== 'none') {
        $pdo->rollBack();
        json_error('ALREADY_REFUNDED', 'この決済は既に返金処理中または返金済みです', 400);
    }

    // gateway_status チェック
    if ($payment['gateway_status'] !== 'succeeded') {
        $pdo->rollBack();
        json_error('INVALID_STATUS', '決済が完了していないため返金できません', 400);
    }

    // 'pending' に先取り UPDATE (affected_rows=1 でなければ並列処理が先行)
    $claimStmt = $pdo->prepare(
        "UPDATE payments
         SET refund_status = 'pending', refunded_by = ?, refunded_at = NOW()
         WHERE id = ? AND store_id = ? AND refund_status = 'none'"
    );
    $claimStmt->execute([$cashierUserId, $paymentId, $storeId]);
    if ($claimStmt->rowCount() !== 1) {
        $pdo->rollBack();
        json_error('ALREADY_REFUNDED', 'この決済は既に返金処理中または返金済みです', 400);
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[S3#10][refund-payment] claim_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    // 'pending' enum 値が DB に未追加の場合 (migration 未適用)
    if (strpos($e->getMessage(), 'Data truncated') !== false || strpos($e->getMessage(), 'Incorrect') !== false) {
        json_error('MIGRATION_REQUIRED', "返金用マイグレーション (migration-s3-refund-pending.sql) が未適用です", 500);
    }
    json_error('DB_ERROR', '返金処理の前段で失敗しました', 500);
}

$tenantId = $user['tenant_id'];
$totalAmount = (int)$payment['total_amount'];

// Stripe API 呼び出し（Connect vs Direct を判定）
$refundResult = null;

if ($payment['gateway_name'] === 'stripe_connect_terminal') {
    // Pattern B: Connect
    $connectInfo = get_tenant_connect_info($pdo, $tenantId);
    if (!$connectInfo || empty($connectInfo['stripe_connect_account_id'])) {
        json_error('CONNECT_NOT_CONFIGURED', 'Stripe Connect が設定されていません', 500);
    }
    // POSLA プラットフォームキーを posla_settings から取得
    $platformKey = null;
    try {
        $sk = $pdo->prepare("SELECT setting_value FROM posla_settings WHERE setting_key = 'stripe_secret_key'");
        $sk->execute();
        $row = $sk->fetch();
        $platformKey = $row ? $row['setting_value'] : null;
    } catch (PDOException $e) {}

    if (!$platformKey) {
        json_error('PLATFORM_KEY_MISSING', 'プラットフォームの Stripe キーが設定されていません', 500);
    }

    $refundResult = create_stripe_connect_refund(
        $platformKey,
        $connectInfo['stripe_connect_account_id'],
        $payment['external_payment_id'],
        $totalAmount,
        $reason,
        true // プラットフォーム手数料も返金
    );
} else {
    // Pattern A: Direct
    $gwConfig = get_payment_gateway_config($pdo, $tenantId);
    if (!$gwConfig || $gwConfig['gateway'] !== 'stripe' || !$gwConfig['token']) {
        json_error('GATEWAY_NOT_CONFIGURED', 'Stripe が設定されていません', 500);
    }

    $refundResult = create_stripe_refund(
        $gwConfig['token'],
        $payment['external_payment_id'],
        $totalAmount,
        $reason
    );
}

if (!$refundResult['success']) {
    // S3 #10: Stripe Refund 失敗時は 'pending' を 'none' に戻す (リトライ可能化)
    try {
        $pdo->prepare(
            "UPDATE payments
             SET refund_status = 'none', refunded_by = NULL, refunded_at = NULL
             WHERE id = ? AND store_id = ? AND refund_status = 'pending'"
        )->execute([$paymentId, $storeId]);
    } catch (PDOException $e) {
        error_log('[S3#10][refund-payment] revert_pending_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }
    json_error('REFUND_FAILED', '返金に失敗しました: ' . ($refundResult['error'] ?? '不明なエラー'), 502);
}

// DB 確定更新 — 'pending' のみ 'full' に確定 (store_id でテナント境界二重チェック)
$pdo->beginTransaction();
try {
    $finalStmt = $pdo->prepare(
        "UPDATE payments
         SET refund_status = 'full', refund_amount = ?, refund_id = ?,
             refund_reason = ?, refunded_at = NOW(), refunded_by = ?
         WHERE id = ? AND store_id = ? AND refund_status = 'pending'"
    );
    $finalStmt->execute([
        $totalAmount,
        $refundResult['refund_id'],
        $reason,
        $cashierUserId,
        $paymentId,
        $storeId
    ]);
    if ($finalStmt->rowCount() !== 1) {
        // 想定外: 'pending' でなくなっている (並列で revert 等)
        // Stripe 上は返金済みなので、refund_id だけは記録する
        error_log('[S3#10][refund-payment] finalize_rowcount_zero payment=' . $paymentId . ' refund=' . $refundResult['refund_id'], 3, '/home/odah/log/php_errors.log');
        $pdo->prepare(
            "UPDATE payments
             SET refund_status = 'full', refund_amount = ?, refund_id = ?, refund_reason = ?, refunded_at = NOW(), refunded_by = ?
             WHERE id = ? AND store_id = ?"
        )->execute([$totalAmount, $refundResult['refund_id'], $reason, $cashierUserId, $paymentId, $storeId]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Stripe 側は返金済みだが DB 反映に失敗した状態
    error_log('[S3#10][refund-payment] finalize_failed payment=' . $paymentId . ' refund=' . $refundResult['refund_id'] . ' err=' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    json_error('DB_ERROR', '返金は完了しましたが DB 反映に失敗しました。サポートへご連絡ください。 refund_id=' . $refundResult['refund_id'], 500);
}

// 監査ログ（CP1b: write_audit_log() 経由で正しいスキーマで書き込む）
@write_audit_log($pdo, $user, $storeId, 'payment_refund', 'payment', $paymentId, null, [
    'cashier_user_id'   => $cashierUserId,
    'cashier_user_name' => $cashierUserName,
    'pin_verified'      => 1,
    'amount'            => $totalAmount,
    'reason'            => $reason,
    'refund_id'         => $refundResult['refund_id'],
    'gateway'           => $payment['gateway_name'],
], $reason);

json_response([
    'refunded'   => true,
    'refund_id'  => $refundResult['refund_id'],
    'amount'     => $totalAmount,
    'payment_id' => $paymentId,
]);
