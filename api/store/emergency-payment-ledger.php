<?php
/**
 * 緊急会計台帳 読み取り API (PWA Phase 4b-2 / 2026-04-20)
 *
 * GET /api/store/emergency-payment-ledger.php?store_id=X&from=YYYY-MM-DD&to=YYYY-MM-DD[&status=all|synced|conflict|pending_review|failed]
 *
 * 認証:
 *   - require_auth()
 *   - require_store_access($storeId)
 *   - role IN (manager, owner) のみ。staff / device は 403
 *
 * 出力:
 *   - summary: { count, totalAmount, needsReviewCount, pinUnverifiedCount }
 *   - records: 最大 300 件、server_received_at DESC
 *       - JSON カラム (order_ids_json / item_snapshot_json) は PHP 側で decode して配列で返す
 *
 * 目的:
 *   Phase 4a 以降、端末で記録された緊急会計 (emergency_payments) を
 *   管理者 (manager/owner) が確認できる読み取り専用台帳。
 *   承認 / 却下 / payments 転記 / 売上反映 は本 API では一切行わない。Phase 4c 以降で検討。
 *
 * 既存 GET /api/store/emergency-payments.php との関係:
 *   - 既存 GET は Phase 4a 時点のまま維持 (レスポンス形式変更なし)
 *   - 本 API は「店舗 × 期間 × status フィルタ + KPI サマリ」を返す管理画面向け
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET']);
$user = require_auth();
$pdo = get_db();

$storeId = isset($_GET['store_id']) ? (string)$_GET['store_id'] : '';
if (!$storeId) json_error('VALIDATION', 'store_id が必要です', 400);
require_store_access($storeId);

if (!in_array($user['role'], ['manager', 'owner'], true)) {
    json_error('FORBIDDEN', '緊急会計台帳は manager / owner のみ閲覧できます', 403);
}

// 期間 (省略時: 直近 7 日)
$fromStr = isset($_GET['from']) ? (string)$_GET['from'] : '';
$toStr   = isset($_GET['to'])   ? (string)$_GET['to']   : '';
$fromTs = $fromStr ? strtotime($fromStr) : (time() - 7 * 24 * 60 * 60);
$toTs   = $toStr   ? strtotime($toStr)   : time();
if (!$fromTs || !$toTs) json_error('VALIDATION', 'from / to の日付形式が不正です', 400);
// 期間は 92 日まで許容 (暴走ガード)
if ($toTs - $fromTs > 92 * 24 * 60 * 60) {
    json_error('VALIDATION', '期間は 92 日以内を指定してください', 400);
}
$fromSql = date('Y-m-d 00:00:00', $fromTs);
$toSql   = date('Y-m-d 23:59:59', $toTs);

// status フィルタ
$allowedStatus = ['synced', 'conflict', 'pending_review', 'failed'];
$statusIn = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$statusFilter = ($statusIn !== '' && in_array($statusIn, $allowedStatus, true)) ? $statusIn : null;

$tenantId = $user['tenant_id'];

// Phase 4c-2: migration 適用状態を事前に明示チェックする (resolution と transfer を個別判定)。
$hasResolutionColumnPreCheck = true;
try { $pdo->query('SELECT resolution_status FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasResolutionColumnPreCheck = false; }

$hasTransferColumnsPreCheck = true;
try { $pdo->query('SELECT transferred_by_user_id FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasTransferColumnsPreCheck = false; }

// Phase 4d-1: pin_entered_client (NULL 許容) カラムの有無を事前チェック
$hasPinEnteredClientPreCheck = true;
try { $pdo->query('SELECT pin_entered_client FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasPinEnteredClientPreCheck = false; }

// Phase 4d-2: external_method_type カラム (other_external の分類) の有無を事前チェック
$hasExternalMethodTypePreCheck = true;
try { $pdo->query('SELECT external_method_type FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasExternalMethodTypePreCheck = false; }

// Phase 4d-5a: payments.void_status カラムの有無を事前チェック
//   存在すれば records に紐づく payment の void 状態を LEFT JOIN で返す。
$hasPaymentVoidPreCheck = true;
try { $pdo->query('SELECT void_status FROM payments LIMIT 0'); }
catch (PDOException $e) { $hasPaymentVoidPreCheck = false; }

// Phase 4b レビュー指摘 #1 対応: summary は records とは別 SQL で全体集計する。
// Phase 4c-1 / 4c-2: resolution 系 / transfer 系をそれぞれ含む SQL を構築。
//                    migration 未適用環境 (42S22) には legacy SQL にフォールバック。

$summary = [
    'count' => 0, 'totalAmount' => 0, 'needsReviewCount' => 0, 'pinUnverifiedCount' => 0,
    'unresolvedCount' => 0, 'confirmedCount' => 0,
    'duplicateCount' => 0, 'rejectedCount' => 0, 'pendingResolutionCount' => 0,
    // Phase 4c-2: 転記関連 KPI
    'transferableCount' => 0, 'transferredCount' => 0,
    // Phase 4d-4a: 手入力計上可能 KPI (transferable とは別軸)
    'manualTransferableCount' => 0,
];
// 事前チェックの結果をそのまま採用 (summary SQL 失敗時はランタイム 42S22 fallback で false に落ちる)
$hasResolutionColumn = $hasResolutionColumnPreCheck;
$hasTransferColumns  = $hasTransferColumnsPreCheck;

$sumSql = 'SELECT
             COUNT(*) AS cnt,
             COALESCE(SUM(total_amount), 0) AS total_sum,
             SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS needs_review,
             SUM(CASE WHEN staff_pin_verified = 0 THEN 1 ELSE 0 END) AS pin_unverified,
             SUM(CASE WHEN resolution_status IS NULL OR resolution_status = ? THEN 1 ELSE 0 END) AS unresolved_c,
             SUM(CASE WHEN resolution_status = ? THEN 1 ELSE 0 END) AS confirmed_c,
             SUM(CASE WHEN resolution_status = ? THEN 1 ELSE 0 END) AS duplicate_c,
             SUM(CASE WHEN resolution_status = ? THEN 1 ELSE 0 END) AS rejected_c,
             SUM(CASE WHEN resolution_status = ? THEN 1 ELSE 0 END) AS pending_c,
             SUM(CASE WHEN synced_payment_id IS NOT NULL THEN 1 ELSE 0 END) AS transferred_c,
             SUM(CASE WHEN resolution_status = ?
                       AND synced_payment_id IS NULL
                       AND status IN (?, ?, ?)
                       AND (
                         payment_method <> ?
                         OR (payment_method = ? AND '
                           . ($hasExternalMethodTypePreCheck
                               ? 'external_method_type IN (?, ?, ?, ?, ?)'
                               : '0 = 1')
                         . ')
                       )
                       AND (order_ids_json IS NOT NULL AND JSON_LENGTH(order_ids_json) > 0)
                       AND (table_id IS NOT NULL AND table_id <> \'\')
                      THEN 1 ELSE 0 END) AS transferable_c,
             /* Phase 4d-4a: 手入力計上可能 (orderIds=0 / table_id 任意)。
                transferable_c とは排他条件なので KPI を分離して返す。 */
             SUM(CASE WHEN resolution_status = ?
                       AND synced_payment_id IS NULL
                       AND status IN (?, ?, ?)
                       AND total_amount > 0
                       AND (order_ids_json IS NULL OR JSON_LENGTH(order_ids_json) = 0)
                       AND (
                         payment_method <> ?
                         OR (payment_method = ? AND '
                           . ($hasExternalMethodTypePreCheck
                               ? 'external_method_type IN (?, ?, ?, ?, ?)'
                               : '0 = 1')
                         . ')
                       )
                      THEN 1 ELSE 0 END) AS manual_transferable_c
           FROM emergency_payments
          WHERE tenant_id = ? AND store_id = ?
            AND server_received_at >= ? AND server_received_at <= ?';
$sumParams = [
    'conflict', 'pending_review',
    'unresolved', 'confirmed', 'duplicate', 'rejected', 'pending',
    // transferable_c 用 (Phase 4d-3): resolution + status IN
    //   + (payment_method != other_external) OR (other_external AND 分類済み)
    //   + orderIds > 0 AND table_id あり
    'confirmed', 'synced', 'conflict', 'pending_review',
    'other_external',  // payment_method <> ?
    'other_external',  // payment_method = ?
];
if ($hasExternalMethodTypePreCheck) {
    $sumParams[] = 'voucher';
    $sumParams[] = 'bank_transfer';
    $sumParams[] = 'accounts_receivable';
    $sumParams[] = 'point';
    $sumParams[] = 'other';
}
// Phase 4d-4a: manual_transferable_c 用 (transferable_c と同じ allowlist だが order_ids_json 条件が逆)
$sumParams[] = 'confirmed';
$sumParams[] = 'synced';
$sumParams[] = 'conflict';
$sumParams[] = 'pending_review';
$sumParams[] = 'other_external';  // payment_method <> ?
$sumParams[] = 'other_external';  // payment_method = ?
if ($hasExternalMethodTypePreCheck) {
    $sumParams[] = 'voucher';
    $sumParams[] = 'bank_transfer';
    $sumParams[] = 'accounts_receivable';
    $sumParams[] = 'point';
    $sumParams[] = 'other';
}
$sumParams[] = $tenantId;
$sumParams[] = $storeId;
$sumParams[] = $fromSql;
$sumParams[] = $toSql;
if ($statusFilter !== null) {
    $sumSql .= ' AND status = ?';
    $sumParams[] = $statusFilter;
}

try {
    $sumStmt = $pdo->prepare($sumSql);
    $sumStmt->execute($sumParams);
    $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC);
    if ($sumRow) {
        $summary['count']                  = (int)$sumRow['cnt'];
        $summary['totalAmount']            = (int)$sumRow['total_sum'];
        $summary['needsReviewCount']       = (int)$sumRow['needs_review'];
        $summary['pinUnverifiedCount']     = (int)$sumRow['pin_unverified'];
        $summary['unresolvedCount']        = (int)$sumRow['unresolved_c'];
        $summary['confirmedCount']         = (int)$sumRow['confirmed_c'];
        $summary['duplicateCount']         = (int)$sumRow['duplicate_c'];
        $summary['rejectedCount']          = (int)$sumRow['rejected_c'];
        $summary['pendingResolutionCount'] = (int)$sumRow['pending_c'];
        $summary['transferredCount']       = (int)$sumRow['transferred_c'];
        $summary['transferableCount']      = (int)$sumRow['transferable_c'];
        // Phase 4d-4a: 手入力計上可能件数
        if (isset($sumRow['manual_transferable_c'])) {
            $summary['manualTransferableCount'] = (int)$sumRow['manual_transferable_c'];
        }
    }
} catch (PDOException $e) {
    if ($e->getCode() === '42S22') {
        // resolution 系カラム未適用 (migration-pwa4c 未適用) → legacy summary にフォールバック
        $hasResolutionColumn = false;
        $sumSqlLegacy = 'SELECT
                           COUNT(*) AS cnt,
                           COALESCE(SUM(total_amount), 0) AS total_sum,
                           SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS needs_review,
                           SUM(CASE WHEN staff_pin_verified = 0 THEN 1 ELSE 0 END) AS pin_unverified
                         FROM emergency_payments
                        WHERE tenant_id = ? AND store_id = ?
                          AND server_received_at >= ? AND server_received_at <= ?';
        $sumParamsLegacy = ['conflict', 'pending_review', $tenantId, $storeId, $fromSql, $toSql];
        if ($statusFilter !== null) {
            $sumSqlLegacy .= ' AND status = ?';
            $sumParamsLegacy[] = $statusFilter;
        }
        try {
            $legacyStmt = $pdo->prepare($sumSqlLegacy);
            $legacyStmt->execute($sumParamsLegacy);
            $legacyRow = $legacyStmt->fetch(PDO::FETCH_ASSOC);
            if ($legacyRow) {
                $summary['count']              = (int)$legacyRow['cnt'];
                $summary['totalAmount']        = (int)$legacyRow['total_sum'];
                $summary['needsReviewCount']   = (int)$legacyRow['needs_review'];
                $summary['pinUnverifiedCount'] = (int)$legacyRow['pin_unverified'];
            }
        } catch (PDOException $e2) {
            json_error('DB_ERROR', 'emergency_payments テーブル未作成の可能性 (migration-pwa4-emergency-payments.sql)', 500);
        }
    } else {
        json_error('DB_ERROR', 'emergency_payments テーブル未作成の可能性 (migration-pwa4-emergency-payments.sql)', 500);
    }
}

// レコード取得 (tenant_id / store_id 二重境界)
// Phase 4c-1 / 4c-2: resolution_* / transferred_* カラムをそれぞれ動的に SELECT に含める。
// 事前チェック ($hasResolutionColumnPreCheck / $hasTransferColumnsPreCheck) でカラム有無を判定、
// 42S22 のランタイム fallback に依存しない (records SELECT は 1 回で済ませる)。
$sqlSelectHead = 'SELECT id, local_emergency_payment_id, table_id, table_code,
               order_ids_json, item_snapshot_json,
               subtotal_amount, tax_amount, total_amount,
               payment_method, received_amount, change_amount,
               external_terminal_name, external_slip_no, external_approval_no,
               status, conflict_reason, synced_payment_id,
               staff_name, staff_pin_verified, note,
               client_created_at, server_received_at';
$sqlResolutionColumns = ',
               resolution_status, resolution_note,
               resolved_by_user_id, resolved_by_name, resolved_at';
$sqlTransferColumns = ',
               transferred_by_user_id, transferred_by_name, transferred_at, transfer_note';
// Phase 4d-1: pin_entered_client を含めるかどうかを分岐
$sqlPinColumn = ',
               pin_entered_client';
// Phase 4d-2: external_method_type を含めるかどうかを分岐
$sqlExtMethodColumn = ',
               external_method_type';

$selectExtras = '';
if ($hasResolutionColumnPreCheck)    $selectExtras .= $sqlResolutionColumns;
if ($hasTransferColumnsPreCheck)     $selectExtras .= $sqlTransferColumns;
if ($hasPinEnteredClientPreCheck)    $selectExtras .= $sqlPinColumn;
if ($hasExternalMethodTypePreCheck)  $selectExtras .= $sqlExtMethodColumn;

$sqlRest = ' FROM emergency_payments
         WHERE tenant_id = ? AND store_id = ?
           AND server_received_at >= ? AND server_received_at <= ?';
$params = [$tenantId, $storeId, $fromSql, $toSql];
if ($statusFilter !== null) {
    $sqlRest .= ' AND status = ?';
    $params[] = $statusFilter;
}
$sqlRest .= ' ORDER BY server_received_at DESC LIMIT 300';

$rows = [];
try {
    $stmt = $pdo->prepare($sqlSelectHead . $selectExtras . $sqlRest);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // 事前チェックを通ったにもかかわらず 42S22 が出る稀ケースは最低限の legacy に倒す
    if ($e->getCode() === '42S22') {
        $hasResolutionColumn = false;
        $hasTransferColumns  = false;
        try {
            $stmt = $pdo->prepare($sqlSelectHead . $sqlRest);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            json_error('DB_ERROR', 'emergency_payments テーブル未作成の可能性 (migration-pwa4-emergency-payments.sql)', 500);
        }
    } else {
        json_error('DB_ERROR', 'emergency_payments テーブル未作成の可能性 (migration-pwa4-emergency-payments.sql)', 500);
    }
}

// Phase 4d-5a: records に紐づく payments.void_status を bulk SELECT で取得 (N+1 回避)
//   synced_payment_id がある行だけを対象に、1 回の IN クエリで payments をまとめて引く。
//   store_id 境界を二重チェック (emergency.store_id と payments.store_id が同一でなければ除外)。
//   migration-pwa4d5a 未適用時はスキップ (paymentVoidStatus=null フィールドを返す)。
$paymentVoidMap = [];
if ($hasPaymentVoidPreCheck) {
    $paymentIdsForVoid = [];
    foreach ($rows as $r) {
        if (!empty($r['synced_payment_id'])) {
            $paymentIdsForVoid[] = (string)$r['synced_payment_id'];
        }
    }
    if (count($paymentIdsForVoid) > 0) {
        try {
            $placeholders = implode(',', array_fill(0, count($paymentIdsForVoid), '?'));
            $pvStmt = $pdo->prepare(
                'SELECT id, void_status, voided_at, void_reason
                   FROM payments
                  WHERE id IN (' . $placeholders . ') AND store_id = ?'
            );
            $pvStmt->execute(array_merge($paymentIdsForVoid, [$storeId]));
            foreach ($pvStmt->fetchAll(PDO::FETCH_ASSOC) as $pvRow) {
                $paymentVoidMap[(string)$pvRow['id']] = $pvRow;
            }
        } catch (PDOException $e) {
            // 稀なランタイム 42S22 等の場合は silent 空 map
            $paymentVoidMap = [];
        }
    }
}

// JSON カラム decode + キャメルケース変換 (records 生成のみ。summary は上で別 SQL 済み)
$records = [];
foreach ($rows as $r) {
    $orderIds = [];
    $itemSnapshot = [];
    if (!empty($r['order_ids_json'])) {
        $d = json_decode($r['order_ids_json'], true);
        if (is_array($d)) $orderIds = $d;
    }
    if (!empty($r['item_snapshot_json'])) {
        $d = json_decode($r['item_snapshot_json'], true);
        if (is_array($d)) $itemSnapshot = $d;
    }

    $records[] = [
        'id'                        => $r['id'],
        'localEmergencyPaymentId'   => $r['local_emergency_payment_id'],
        'tableId'                   => $r['table_id'],
        'tableCode'                 => $r['table_code'],
        'orderIds'                  => $orderIds,
        'itemSnapshot'              => $itemSnapshot,
        'subtotal'                  => (int)$r['subtotal_amount'],
        'tax'                       => (int)$r['tax_amount'],
        'totalAmount'               => (int)$r['total_amount'],
        'paymentMethod'             => $r['payment_method'],
        'receivedAmount'            => $r['received_amount'] !== null ? (int)$r['received_amount'] : null,
        'changeAmount'              => $r['change_amount']   !== null ? (int)$r['change_amount']   : null,
        'externalTerminalName'      => $r['external_terminal_name'],
        'externalSlipNo'            => $r['external_slip_no'],
        'externalApprovalNo'        => $r['external_approval_no'],
        'status'                    => $r['status'],
        'conflictReason'            => $r['conflict_reason'],
        'syncedPaymentId'           => $r['synced_payment_id'],
        'staffName'                 => $r['staff_name'],
        'staffPinVerified'          => (int)$r['staff_pin_verified'] === 1,
        // Phase 4d-1: pin_entered_client は null / 0 / 1 の 3 値。null=旧データ/不明。
        //   migration 未適用や legacy 経路ではキー自体が無いので isset → null fallback。
        'pinEnteredClient'          => (array_key_exists('pin_entered_client', $r) && $r['pin_entered_client'] !== null)
                                         ? ((int)$r['pin_entered_client'] === 1) : null,
        // Phase 4d-2: external_method_type (voucher / bank_transfer / accounts_receivable / point / other)。
        //   null = 未分類 or 対象外 (cash / external_card / external_qr)。migration 未適用時もキー無しで null fallback。
        'externalMethodType'        => (array_key_exists('external_method_type', $r) && $r['external_method_type'] !== null)
                                         ? (string)$r['external_method_type'] : null,
        // Phase 4d-5a: 紐づく payments の void 状態 (bulk SELECT 結果からルックアップ)
        //   synced_payment_id がない or payments 行がない or migration 未適用 → 全て null
        'paymentVoidStatus'         => (!empty($r['synced_payment_id']) && isset($paymentVoidMap[$r['synced_payment_id']]))
                                         ? (string)$paymentVoidMap[$r['synced_payment_id']]['void_status'] : null,
        'paymentVoidedAt'           => (!empty($r['synced_payment_id']) && isset($paymentVoidMap[$r['synced_payment_id']]))
                                         ? $paymentVoidMap[$r['synced_payment_id']]['voided_at'] : null,
        'paymentVoidReason'         => (!empty($r['synced_payment_id']) && isset($paymentVoidMap[$r['synced_payment_id']]))
                                         ? $paymentVoidMap[$r['synced_payment_id']]['void_reason'] : null,
        'note'                      => $r['note'],
        'clientCreatedAt'           => $r['client_created_at'],
        'serverReceivedAt'          => $r['server_received_at'],
        // Phase 4c-1: resolution 系 5 フィールド。$hasResolutionColumn=false の legacy 経路では
        //             各カラムは $r に存在しないため、isset チェックで null にフォールバックする。
        'resolutionStatus'          => (isset($r['resolution_status']) && $r['resolution_status'] !== null)
                                         ? $r['resolution_status'] : ($hasResolutionColumn ? 'unresolved' : null),
        'resolutionNote'            => isset($r['resolution_note']) ? $r['resolution_note'] : null,
        'resolvedByUserId'          => isset($r['resolved_by_user_id']) ? $r['resolved_by_user_id'] : null,
        'resolvedByName'            => isset($r['resolved_by_name']) ? $r['resolved_by_name'] : null,
        'resolvedAt'                => isset($r['resolved_at']) ? $r['resolved_at'] : null,
        // Phase 4c-2: transferred 系 4 フィールド。同様に isset チェックで null fallback。
        'transferredByUserId'       => isset($r['transferred_by_user_id']) ? $r['transferred_by_user_id'] : null,
        'transferredByName'         => isset($r['transferred_by_name']) ? $r['transferred_by_name'] : null,
        'transferredAt'             => isset($r['transferred_at']) ? $r['transferred_at'] : null,
        'transferNote'              => isset($r['transfer_note']) ? $r['transfer_note'] : null,
    ];
}

// Phase 4b レビュー指摘 #1 対応:
//   summary は上で別 SQL により全体集計済み。records は LIMIT 300 で切られる可能性あり。
//   displayedCount で「今画面に返している件数」、summary.count で「期間内の全件数」を区別できるようにする。
json_response([
    'summary' => $summary,
    'records' => $records,
    'displayedCount' => count($records),
    'limit' => 300,
    'hasResolutionColumn' => $hasResolutionColumn,  // Phase 4c-1: false なら UI 側で解決操作ボタンを非表示にする
    'hasTransferColumns'  => $hasTransferColumns,    // Phase 4c-2: false なら UI 側で売上転記ボタンを非表示にする
    'hasPinEnteredClientColumn' => $hasPinEnteredClientPreCheck,  // Phase 4d-1: false なら UI 側で PIN 4 状態表示を従来の 2 値に倒す
    'hasExternalMethodTypeColumn' => $hasExternalMethodTypePreCheck,  // Phase 4d-2: false なら UI 側で other_external を「その他外部」一律表示に倒す
    'hasPaymentVoidColumn'        => $hasPaymentVoidPreCheck,          // Phase 4d-5a: false なら UI 側で「取消」ボタンを出さない
    'filter'  => [
        'storeId' => $storeId,
        'from'    => $fromSql,
        'to'      => $toSql,
        'status'  => $statusFilter !== null ? $statusFilter : 'all',
    ],
]);
