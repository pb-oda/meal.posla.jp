<?php
/**
 * 取引ジャーナル API
 *
 * GET /api/store/payments-journal.php?store_id=xxx[&from=YYYY-MM-DD&to=YYYY-MM-DD]
 * → 本日（または指定期間）の決済一覧をスタッフ名・テーブルコード付きで返却
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';

require_method(['GET']);
$user = require_auth();

$storeId = require_store_param();

$pdo = get_db();

// payments テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM payments LIMIT 0');
} catch (PDOException $e) {
    json_response(['payments' => [], 'summary' => ['count' => 0, 'total' => 0]]);
}

// 営業日範囲
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;

if ($from && $to) {
    $range = get_business_day_range($pdo, $storeId, $from, $to);
} else {
    $bd = get_business_day($pdo, $storeId);
    $range = ['start' => $bd['start'], 'end' => $bd['end']];
}

// C-R1: refund / gateway カラム存在チェック
$hasRefundCols = false;
try {
    $pdo->query('SELECT refund_status FROM payments LIMIT 0');
    $hasRefundCols = true;
} catch (PDOException $e) {}

$hasGatewayCols = false;
try {
    $pdo->query('SELECT gateway_name FROM payments LIMIT 0');
    $hasGatewayCols = true;
} catch (PDOException $e) {}

// P1-46: 通常レジの支払い詳細
$hasPaymentDetailCol = false;
try {
    $pdo->query('SELECT payment_method_detail FROM payments LIMIT 0');
    $hasPaymentDetailCol = true;
} catch (PDOException $e) {}

$hasPaymentExternalNoteCol = false;
try {
    $pdo->query('SELECT external_payment_note FROM payments LIMIT 0');
    $hasPaymentExternalNoteCol = true;
} catch (PDOException $e) {}

// Phase 4d-5a: void 系カラム検出
$hasVoidCols = false;
try {
    $pdo->query('SELECT void_status FROM payments LIMIT 0');
    $hasVoidCols = true;
} catch (PDOException $e) {}

$extraCols = '';
if ($hasGatewayCols) $extraCols .= ', p.gateway_name, p.external_payment_id, p.gateway_status';
if ($hasRefundCols) $extraCols .= ', p.refund_status, p.refund_amount, p.refunded_at';
if ($hasPaymentDetailCol) $extraCols .= ', p.payment_method_detail';
if ($hasPaymentExternalNoteCol) $extraCols .= ', p.external_payment_note';
// Phase 4d-5a: void_status 系フィールドを動的追加 (voided 行も一覧に表示し、UI で「取消済み」バッジを出す)
if ($hasVoidCols)   $extraCols .= ', p.void_status, p.voided_at, p.void_reason';

$stmt = $pdo->prepare(
    'SELECT p.id, p.table_id, p.total_amount, p.payment_method,
            p.received_amount, p.change_amount, p.is_partial,
            p.paid_at, p.user_id' . $extraCols . ',
            u.display_name AS staff_name,
            t.table_code AS table_code
     FROM payments p
     LEFT JOIN users u ON u.id = p.user_id
     LEFT JOIN tables t ON t.id = p.table_id
     WHERE p.store_id = ? AND p.paid_at >= ? AND p.paid_at < ?
     ORDER BY p.paid_at DESC'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
$payments = $stmt->fetchAll();

// サマリー計算
$count = count($payments);
$total = 0;
foreach ($payments as $p) {
    $total += (int)$p['total_amount'];
}

json_response([
    'payments' => $payments,
    'summary'  => [
        'count' => $count,
        'total' => $total,
    ],
]);
