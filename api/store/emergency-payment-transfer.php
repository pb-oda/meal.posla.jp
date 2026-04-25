<?php
/**
 * 緊急会計 → 通常 payments 転記 API (PWA Phase 4c-2 / 2026-04-20)
 *
 * POST /api/store/emergency-payment-transfer.php
 *
 * body: {
 *   store_id: "<uuid>",
 *   emergency_payment_id: "<uuid>",
 *   note: "任意"
 * }
 *
 * 機能:
 *   resolution_status='confirmed' の緊急会計を 1 件、通常 payments テーブルに INSERT する。
 *   同じ orderIds を持つ orders を 'paid' に更新する。
 *   emergency_payments.synced_payment_id に payment.id を記録して idempotent を担保する。
 *
 * 認証:
 *   require_auth() + require_store_access($storeId) + role IN ('manager','owner')
 *   staff / device は 403 FORBIDDEN
 *
 * Idempotency:
 *   synced_payment_id が既にあれば UPDATE / INSERT を一切せず idempotent:true で 200 を返す。
 *
 * 絶対にやらない:
 *   - process-payment.php の呼び出し (副作用を避けるため独自 INSERT)
 *   - payments テーブルのスキーマ変更
 *   - order_items の更新 (全額会計相当、process-payment.php の !isPartial 経路と同じ)
 *   - emergency_payment_orders.status の変更 ('active' のまま維持)
 *   - duplicate / rejected / pending / unresolved の転記
 *   - manual_entry (orderIds 空) の転記
 *   - 未分類 other_external の転記 (Phase 4d-3 から external_method_type 設定済みは転記可。未分類は 409)
 *   - 合流会計 (table_id 空) の転記 (Phase 4d-4 以降で対応)
 *   - 外部端末決済の gateway_name / external_payment_id / gateway_status 書き込み
 *     (refund-payment.php の gateway_status='succeeded' 前提と衝突するため note に退避)
 *
 * 通常会計と揃えている副作用 (Fix 1-4 / 2026-04-20 厳格化):
 *   - 単一テーブルの tables.session_token 再生成 + table_sessions close (同一TX)
 *   - cash_log(type='cash_sale') INSERT (現金時のみ、created_at=$paidAt で営業日保全)
 *   - payments.user_id = 実レジ担当者 (staff_pin_verified=1 のとき staff_user_id)
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['POST']);

// H-04: CSRF 対策 — 緊急会計→payments 転記経路に Origin / Referer 検証を追加（opt-in）
verify_origin();

$user = require_auth();

if (!in_array($user['role'], ['manager', 'owner'], true)) {
    json_error('FORBIDDEN', '緊急会計の転記は manager / owner のみ可能です', 403);
}

$body = get_json_body();
$storeId            = isset($body['store_id']) ? (string)$body['store_id'] : '';
$emergencyPaymentId = isset($body['emergency_payment_id']) ? (string)$body['emergency_payment_id'] : '';
$noteIn             = isset($body['note']) ? (string)$body['note'] : '';

if (!$storeId)            json_error('VALIDATION', 'store_id が必要です', 400);
if (!$emergencyPaymentId) json_error('VALIDATION', 'emergency_payment_id が必要です', 400);

$transferNoteInput = trim($noteIn);
if ($transferNoteInput !== '') {
    $transferNoteInput = mb_substr($transferNoteInput, 0, 200);
}

require_store_access($storeId);
$tenantId = $user['tenant_id'];

$pdo = get_db();

// 転記実行者 display_name 取得 (取れなければ username)
$transferByUserId = isset($user['user_id']) ? (string)$user['user_id'] : null;
$transferByName   = isset($user['username']) ? (string)$user['username'] : '';
try {
    if ($transferByUserId) {
        $uStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
        $uStmt->execute([$transferByUserId, $tenantId]);
        $uRow = $uStmt->fetch();
        if ($uRow && !empty($uRow['display_name'])) {
            $transferByName = (string)$uRow['display_name'];
        }
    }
} catch (PDOException $e) {
    // users 読み取り失敗 → username フォールバック維持
}
$transferByName = mb_substr($transferByName, 0, 100);

// ---- payments テーブルの動的カラム検出 (process-payment.php と同方針) ----
$hasMergedCols  = false;
try { $pdo->query('SELECT merged_table_ids FROM payments LIMIT 0'); $hasMergedCols = true; }  catch (PDOException $e) {}

// Phase 4d-3: external_method_type カラム (emergency_payments / payments 両方) の事前チェック
$hasEmergencyExtCol = true;
try { $pdo->query('SELECT external_method_type FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasEmergencyExtCol = false; }
$hasPaymentsExtCol = true;
try { $pdo->query('SELECT external_method_type FROM payments LIMIT 0'); }
catch (PDOException $e) { $hasPaymentsExtCol = false; }

// ---- transferred_* カラム (migration-pwa4c2) の有無チェック ----
$hasTransferCols = true;
try { $pdo->query('SELECT transferred_by_user_id FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasTransferCols = false; }

// ========================= トランザクション ========================
$inTx = false;
try {
    $pdo->beginTransaction();
    $inTx = true;

    // 1. emergency_payments 対象行を FOR UPDATE
    $selCols = 'id, tenant_id, store_id, local_emergency_payment_id,
                table_id, table_code, order_ids_json, item_snapshot_json,
                subtotal_amount, tax_amount, total_amount,
                payment_method, received_amount, change_amount,
                external_terminal_name, external_slip_no, external_approval_no, note,
                status, resolution_status, synced_payment_id,
                staff_name, staff_user_id, staff_pin_verified,
                client_created_at, server_received_at';
    // Phase 4d-3: external_method_type を動的に含める (migration 未適用環境では除外)
    if ($hasEmergencyExtCol) {
        $selCols .= ', external_method_type';
    }
    $selSql = 'SELECT ' . $selCols . ' FROM emergency_payments
                WHERE id = ? AND tenant_id = ? AND store_id = ?
                LIMIT 1 FOR UPDATE';
    $selStmt = $pdo->prepare($selSql);
    $selStmt->execute([$emergencyPaymentId, $tenantId, $storeId]);
    $row = $selStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack(); $inTx = false;
        json_error('NOT_FOUND', '指定された緊急会計が見つかりません', 404);
    }

    // 2. Idempotency: synced_payment_id があれば UPDATE せずそのまま返す
    if (!empty($row['synced_payment_id'])) {
        $pdo->commit(); $inTx = false;
        json_response([
            'emergencyPaymentId' => $row['id'],
            'paymentId'          => $row['synced_payment_id'],
            'transferred'        => false,
            'idempotent'         => true,
        ]);
    }

    // 3. 転記前提条件チェック
    $resolutionStatus = $row['resolution_status'] === null ? 'unresolved' : $row['resolution_status'];
    if ($resolutionStatus !== 'confirmed') {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', '転記には resolution_status=confirmed が必要です (現在: ' . $resolutionStatus . ')', 409);
    }
    if ($row['status'] === 'failed') {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', '同期失敗 (status=failed) の記録は転記できません', 409);
    }
    $totalAmount = (int)$row['total_amount'];
    if ($totalAmount <= 0) {
        $pdo->rollBack(); $inTx = false;
        json_error('VALIDATION', 'total_amount が 0 以下の記録は転記できません', 400);
    }

    $paymentMethodEmerg = (string)$row['payment_method'];
    // 許可 4 種
    if (!in_array($paymentMethodEmerg, ['cash', 'external_card', 'external_qr', 'other_external'], true)) {
        $pdo->rollBack(); $inTx = false;
        json_error('VALIDATION', '不正な payment_method です: ' . mb_substr($paymentMethodEmerg, 0, 40), 400);
    }

    // Phase 4d-3: other_external は「分類済み」に限り転記可能。未分類は 409 のまま。
    //   カラム有無はトランザクション外で事前チェック済み ($hasEmergencyExtCol / $hasPaymentsExtCol)。
    $allowedExternalMethodTypes = ['voucher', 'bank_transfer', 'accounts_receivable', 'point', 'other'];
    $emergencyExtType = (array_key_exists('external_method_type', $row) && $row['external_method_type'] !== null)
                         ? (string)$row['external_method_type'] : null;
    if ($paymentMethodEmerg === 'other_external') {
        if (!$hasEmergencyExtCol || !$hasPaymentsExtCol) {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'other_external の転記には migration-pwa4d2a / pwa4d2b が必要です (external_method_type カラム未適用)',
                409
            );
        }
        if ($emergencyExtType === null || !in_array($emergencyExtType, $allowedExternalMethodTypes, true)) {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'unclassified_other_external: 外部分類 (voucher / bank_transfer / accounts_receivable / point / other) を先に記録してください',
                409
            );
        }
    }

    // 4. orderIds 抽出
    $orderIds = [];
    if (!empty($row['order_ids_json'])) {
        $tmp = json_decode($row['order_ids_json'], true);
        if (is_array($tmp)) {
            foreach ($tmp as $oid) {
                if (is_string($oid) && strlen($oid) > 0 && strlen($oid) <= 64) $orderIds[] = $oid;
            }
        }
    }
    if (count($orderIds) === 0) {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', '手入力モード (orderIds 空) は Phase 4c-2 では転記不可です (Phase 4d で設計予定)', 409);
    }

    // 4b. 合流会計ガード: table_id が空 = 合流 or 特殊運用。Phase 4c-2 では未対応
    $tableIdVal = !empty($row['table_id']) ? (string)$row['table_id'] : null;
    if ($tableIdVal === null) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'merged_transfer_not_supported: table_id が空の緊急会計の転記は Phase 4d で対応予定です',
            409
        );
    }

    // 5. orders を FOR UPDATE で固定 + 存在・状態・table_id 整合性チェック
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $ordStmt = $pdo->prepare(
        'SELECT id, status, total_amount, order_type, items, table_id
           FROM orders
          WHERE id IN (' . $placeholders . ') AND store_id = ?
          FOR UPDATE'
    );
    $ordStmt->execute(array_merge($orderIds, [$storeId]));
    $ordRows = $ordStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($ordRows) !== count($orderIds)) {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', 'order_not_found: 対象注文の一部が存在しない、または他店舗のものです', 409);
    }
    foreach ($ordRows as $o) {
        if (in_array($o['status'], ['paid', 'cancelled'], true)) {
            $pdo->rollBack(); $inTx = false;
            json_error('CONFLICT', 'order_already_finalized: order_id=' . mb_substr($o['id'], 0, 32) . ' status=' . $o['status'], 409);
        }
        // Fix 6: orderIds が緊急会計 table_id に属しているか (単一テーブル転記の最低限の整合性)
        //  合流会計は 4b で既に拒否済みなので、全 orders が同一 tableIdVal を持つべき。
        //  orders.table_id が NULL (テイクアウト等) でも NOT NULL の tableIdVal と一致しない → mismatch で拒否。
        $ordTableId = isset($o['table_id']) ? (string)$o['table_id'] : '';
        if ($ordTableId !== $tableIdVal) {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'order_table_mismatch: order_id=' . mb_substr($o['id'], 0, 32) . ' の table_id が緊急会計の table_id と一致しません',
                409
            );
        }
    }

    // 5b. Fix 7: 同卓に orderIds 外の未会計注文が残っていないか
    //     緊急レジは stale/offline 中に使うので、端末スナップショット後に卓へ追加注文が入っている可能性がある。
    //     その状態で転記すると、卓セッションを閉じる (13b) タイミングで 「端末が知らない未会計注文」を残したまま
    //     trans_token 再生成 + table_sessions close が走り、注文が宙に浮く。
    //     同一 store_id + table_id + status NOT IN (paid,cancelled) かつ id NOT IN (orderIds) を FOR UPDATE で検索し、
    //     1 件でも見つかれば 409 で拒否する (管理者が手動で突合・追加会計する判断を促す)。
    $excludedPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
    $missingSameTableStmt = $pdo->prepare(
        'SELECT id FROM orders
          WHERE store_id = ? AND table_id = ?
            AND status NOT IN ("paid", "cancelled")
            AND id NOT IN (' . $excludedPlaceholders . ')
          FOR UPDATE'
    );
    $missingSameTableStmt->execute(array_merge([$storeId, $tableIdVal], $orderIds));
    $missingSameTable = $missingSameTableStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (count($missingSameTable) > 0) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'table_has_unincluded_unpaid_orders: 同じテーブルに緊急レジ記録に含まれていない未会計注文があります (件数=' . count($missingSameTable) . ')。管理者が手動で突合してください',
            409
        );
    }

    // 6. 既存 payments に同一 order_id が含まれていないか
    foreach ($orderIds as $oid) {
        $dupStmt = $pdo->prepare(
            "SELECT id FROM payments
              WHERE store_id = ? AND JSON_CONTAINS(order_ids, JSON_QUOTE(?))
              LIMIT 1"
        );
        $dupStmt->execute([$storeId, $oid]);
        if ($dupStmt->fetch()) {
            $pdo->rollBack(); $inTx = false;
            json_error('CONFLICT', 'order_already_in_payments: order_id=' . mb_substr($oid, 0, 32) . ' は既に通常 payments に記録されています', 409);
        }
    }

    // 6b. Fix 5: 個別会計状態の緊急レジ転記を拒否
    //     cashier-app.js:2651- は _checkedItems にチェックが入った品目だけを itemSnapshot に入れる。
    //     ここでその itemSnapshot に含まれない 未会計・未取消 の order_items があると、
    //     「一部品目の金額で orders 全体が paid」になる会計事故が起きる。
    //     orders に紐づく order_items を SELECT し、(status!=cancelled AND payment_id IS NULL) を
    //     全て itemSnapshot.itemId でカバーしているか検証する。
    //     itemSnapshot.json を先に必要なので parse しておく (7. で使う itemSnapshot と同じ変数)。
    $itemSnapshotForCheck = [];
    if (!empty($row['item_snapshot_json'])) {
        $tmpSnap = json_decode($row['item_snapshot_json'], true);
        if (is_array($tmpSnap)) $itemSnapshotForCheck = $tmpSnap;
    }
    $snapItemIdSet = [];
    foreach ($itemSnapshotForCheck as $it) {
        if (is_array($it) && !empty($it['itemId'])) {
            $snapItemIdSet[(string)$it['itemId']] = true;
        }
    }
    $oiSelSql = 'SELECT id, order_id FROM order_items
                  WHERE order_id IN (' . $placeholders . ')
                    AND store_id = ?
                    AND status != "cancelled"
                    AND payment_id IS NULL';
    $oiStmt = $pdo->prepare($oiSelSql);
    $oiStmt->execute(array_merge($orderIds, [$storeId]));
    $activeOi = $oiStmt->fetchAll(PDO::FETCH_ASSOC);
    $missingOi = [];
    foreach ($activeOi as $ai) {
        $oiId = (string)$ai['id'];
        if (!isset($snapItemIdSet[$oiId])) {
            $missingOi[] = $oiId;
        }
    }
    if (count($missingOi) > 0) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'partial_emergency_transfer_not_supported: itemSnapshot に含まれない未会計 order_items があります (件数=' . count($missingOi) . ')。緊急レジ記録は全品目選択状態で作成してください',
            409
        );
    }

    // 7. tax split: itemSnapshot.taxRate から再集計 (fallback で 10% 寄せ)
    $subtotal10 = 0; $tax10 = 0;
    $subtotal8  = 0; $tax8  = 0;
    $approximated = false;

    $itemSnapshot = [];
    if (!empty($row['item_snapshot_json'])) {
        $tmp = json_decode($row['item_snapshot_json'], true);
        if (is_array($tmp)) $itemSnapshot = $tmp;
    }

    if (count($itemSnapshot) > 0) {
        $sumTaxIncl = 0;
        $missingTaxRate = false;
        $bufSub10 = 0; $bufTax10 = 0;
        $bufSub8  = 0; $bufTax8  = 0;
        foreach ($itemSnapshot as $it) {
            if (!is_array($it)) continue;
            $qty   = isset($it['qty']) ? (int)$it['qty'] : 0;
            $price = isset($it['price']) ? (int)$it['price'] : 0;
            $lineTotal = $qty * $price;
            $sumTaxIncl += $lineTotal;
            $taxRate = null;
            if (isset($it['taxRate']) && is_numeric($it['taxRate'])) {
                $taxRate = (int)$it['taxRate'];
            }
            if ($taxRate !== 10 && $taxRate !== 8) {
                $missingTaxRate = true;
                // 未指定は 10% に寄せる (集計結果に反映する前に fallback 判定)
                $taxRate = 10;
            }
            if ($taxRate === 8) {
                $sub = (int)floor($lineTotal / 1.08);
                $bufSub8 += $sub;
                $bufTax8 += $lineTotal - $sub;
            } else {
                $sub = (int)floor($lineTotal / 1.10);
                $bufSub10 += $sub;
                $bufTax10 += $lineTotal - $sub;
            }
        }

        // itemSnapshot 合計と emergency.total_amount が一致しない、
        // または taxRate 欠落があれば approximated 扱い
        if ($missingTaxRate || $sumTaxIncl !== $totalAmount) {
            $approximated = true;
            // emergency の subtotal / tax を 10% に寄せる (fallback)
            $subtotal10 = (int)$row['subtotal_amount'];
            $tax10      = (int)$row['tax_amount'];
            // subtotal+tax と total_amount が大きくズレる場合は total_amount に合わせる
            if (($subtotal10 + $tax10) !== $totalAmount) {
                $subtotal10 = (int)floor($totalAmount / 1.10);
                $tax10      = $totalAmount - $subtotal10;
            }
        } else {
            $subtotal10 = $bufSub10;
            $tax10      = $bufTax10;
            $subtotal8  = $bufSub8;
            $tax8       = $bufTax8;
        }
    } else {
        // itemSnapshot 空 → emergency.subtotal / tax を 10% 側に全部寄せる
        $approximated = true;
        $subtotal10 = (int)$row['subtotal_amount'];
        $tax10      = (int)$row['tax_amount'];
        if (($subtotal10 + $tax10) !== $totalAmount) {
            $subtotal10 = (int)floor($totalAmount / 1.10);
            $tax10      = $totalAmount - $subtotal10;
        }
    }

    // 8. payment_method mapping (Fix 4 / Phase 4d-3)
    //  外部端末 (external_card/external_qr/other_external) は payments.gateway_* を使わない。
    //  refund-payment.php が gateway_status='succeeded' を前提にしており意味が衝突するため、
    //  端末名/伝票番号/承認番号は payments.note に退避する。
    //  emergency_payments 側にはそのまま保持される (監査トレース)。
    // Phase 4d-3: other_external は既存 ENUM('cash','card','qr') を壊さないため 'qr' に寄せる。
    //   分類は payments.external_method_type に保存され、UI/レポート側で区別表示する。
    //   残リスク: 既存 paymentBreakdown (orders.payment_method 集計) では qr に混ざる。
    //   externalMethodBreakdown (Phase 4d-3 追加) で分離表示して補完する。
    $methodForPayments = 'cash';
    $externalMethodTypeForPayments = null;
    if ($paymentMethodEmerg === 'cash') {
        $methodForPayments = 'cash';
    } elseif ($paymentMethodEmerg === 'external_card') {
        $methodForPayments = 'card';
    } elseif ($paymentMethodEmerg === 'external_qr') {
        $methodForPayments = 'qr';
    } elseif ($paymentMethodEmerg === 'other_external') {
        $methodForPayments = 'qr';
        $externalMethodTypeForPayments = $emergencyExtType;  // 既に allowlist 検証済み
    }

    // 9. paid_at: client_created_at 優先、無ければ server_received_at。NOT NULL 強制
    $paidAt = null;
    if (!empty($row['client_created_at'])) {
        $paidAt = (string)$row['client_created_at'];
    } elseif (!empty($row['server_received_at'])) {
        $paidAt = (string)$row['server_received_at'];
    } else {
        // どちらもなければ現在時刻 (フォールバック)
        $paidAt = date('Y-m-d H:i:s');
    }

    // 9b. payments.user_id に入れる実レジ担当者を決定 (Fix 3)
    //  - staff_pin_verified=1 かつ staff_user_id が同 tenant の users に存在 → staff_user_id
    //  - そうでなければ 転記実行者 ($transferByUserId) に fallback
    //  - 転記者自体の記録は transferred_by_user_id / transferred_by_name に残る
    $cashierUserIdForPayments = $transferByUserId;
    $staffUserIdCand    = !empty($row['staff_user_id']) ? (string)$row['staff_user_id'] : '';
    $staffPinVerified   = (int)(isset($row['staff_pin_verified']) ? $row['staff_pin_verified'] : 0);
    if ($staffUserIdCand !== '' && $staffPinVerified === 1) {
        try {
            $chkStmt = $pdo->prepare(
                'SELECT id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $chkStmt->execute([$staffUserIdCand, $tenantId]);
            if ($chkStmt->fetch()) {
                $cashierUserIdForPayments = $staffUserIdCand;
            }
        } catch (PDOException $e) {
            // 照合失敗時は fallback (転記実行者) のまま
        }
    }

    // 10. note (payments.note は VARCHAR(200))
    //  Fix 4: 外部端末決済は gateway_* カラムに入れないので、端末名/伝票/承認を note に追記。
    $mergedNoteParts = [];
    if ($transferNoteInput !== '') $mergedNoteParts[] = $transferNoteInput;
    $mergedNoteParts[] = 'emergency_transfer src=' . mb_substr($row['local_emergency_payment_id'], 0, 40);
    if ($paymentMethodEmerg === 'external_card' || $paymentMethodEmerg === 'external_qr' || $paymentMethodEmerg === 'other_external') {
        if (!empty($row['external_terminal_name'])) {
            $mergedNoteParts[] = 'term=' . mb_substr((string)$row['external_terminal_name'], 0, 24);
        }
        if (!empty($row['external_slip_no'])) {
            $mergedNoteParts[] = 'slip=' . mb_substr((string)$row['external_slip_no'], 0, 24);
        }
        if (!empty($row['external_approval_no'])) {
            $mergedNoteParts[] = 'appr=' . mb_substr((string)$row['external_approval_no'], 0, 20);
        }
        // Phase 4d-3: other_external は note にも分類 slug を残す (payments.external_method_type と冗長だが human-readable)
        if ($paymentMethodEmerg === 'other_external' && $externalMethodTypeForPayments) {
            $mergedNoteParts[] = 'ext_type=' . $externalMethodTypeForPayments;
        }
    }
    if ($approximated) $mergedNoteParts[] = 'tax split approximated';
    $paymentsNote = mb_substr(implode(' / ', $mergedNoteParts), 0, 200);

    // transfer_note (emergency_payments 側、VARCHAR(255))
    $transferNoteStored = ($transferNoteInput !== '') ? mb_substr($transferNoteInput, 0, 255) : null;
    if ($approximated) {
        $suffix = ' [tax split approximated]';
        $transferNoteStored = $transferNoteStored === null
            ? mb_substr(ltrim($suffix), 0, 255)
            : mb_substr($transferNoteStored . $suffix, 0, 255);
    }

    // 11. payments INSERT
    $paymentId = generate_uuid();
    $receivedAmt     = ($row['received_amount'] !== null && $row['received_amount'] !== '') ? (int)$row['received_amount'] : null;
    $changeAmt       = ($row['change_amount']   !== null && $row['change_amount']   !== '') ? (int)$row['change_amount']   : null;
    $orderIdsJson    = json_encode(array_values($orderIds), JSON_UNESCAPED_UNICODE);
    $paidItemsJson   = count($itemSnapshot) > 0 ? json_encode($itemSnapshot, JSON_UNESCAPED_UNICODE) : null;

    // Phase 4d-3: payments.external_method_type を動的注入 (migration-pwa4d2b 済みなら)。
    //   payment_method の直後 = received_amount の直前に列を入れる。
    $extMethodCol   = $hasPaymentsExtCol ? ', external_method_type' : '';
    $extMethodPh    = $hasPaymentsExtCol ? ', ?' : '';
    $extMethodParam = $hasPaymentsExtCol ? [$externalMethodTypeForPayments] : [];

    if ($hasMergedCols) {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, merged_table_ids, merged_session_ids, session_id,
                                   order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8,
                                   total_amount, payment_method' . $extMethodCol . ', received_amount, change_amount,
                                   is_partial, user_id, note, paid_at, created_at)
             VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?' . $extMethodPh . ', ?, ?, 0, ?, ?, ?, NOW())'
        )->execute(array_merge(
            [$paymentId, $storeId, $tableIdVal,
             $orderIdsJson, $paidItemsJson,
             $subtotal10, $tax10, $subtotal8, $tax8,
             $totalAmount, $methodForPayments],
            $extMethodParam,
            [$receivedAmt, $changeAmt,
             $cashierUserIdForPayments, $paymentsNote, $paidAt]
        ));
    } else {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, session_id,
                                   order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8,
                                   total_amount, payment_method' . $extMethodCol . ', received_amount, change_amount,
                                   is_partial, user_id, note, paid_at, created_at)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?' . $extMethodPh . ', ?, ?, 0, ?, ?, ?, NOW())'
        )->execute(array_merge(
            [$paymentId, $storeId, $tableIdVal,
             $orderIdsJson, $paidItemsJson,
             $subtotal10, $tax10, $subtotal8, $tax8,
             $totalAmount, $methodForPayments],
            $extMethodParam,
            [$receivedAmt, $changeAmt,
             $cashierUserIdForPayments, $paymentsNote, $paidAt]
        ));
    }

    // 12. (Fix 4) 外部端末決済の伝票/承認は gateway_* ではなく note に退避済み。
    //     gateway_name / external_payment_id / gateway_status は一切 UPDATE しない。
    //     emergency_payments 側には external_terminal_name/external_slip_no/external_approval_no が残る。

    // 12b. cash_log に cash_sale を INSERT (Fix 2)
    //      process-payment.php:587 と同じ位置付け。register-report.php が
    //      cash_log を根拠に「現金売上 / 期待残高」を計算するため、緊急会計分も必ず記録する。
    //      created_at は転記時刻ではなく $paidAt (実会計時刻) を使い、営業日をズラさない。
    if ($methodForPayments === 'cash' && $totalAmount > 0) {
        $cashLogId = generate_uuid();
        $pdo->prepare(
            'INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $cashLogId, $storeId, $cashierUserIdForPayments, 'cash_sale', $totalAmount,
            '緊急会計転記', $paidAt
        ]);
    }

    // 13. orders を 'paid' に更新 (rowCount 検証で二重売上ガード)
    $updOrdersSql = 'UPDATE orders
                        SET status = ?, payment_method = ?, received_amount = ?, change_amount = ?,
                            paid_at = ?, updated_at = NOW()
                      WHERE id IN (' . $placeholders . ') AND store_id = ?
                        AND status NOT IN ("paid", "cancelled")';
    $updOrdersStmt = $pdo->prepare($updOrdersSql);
    $updOrdersStmt->execute(array_merge(
        ['paid', $methodForPayments, $receivedAmt, $changeAmt, $paidAt],
        $orderIds,
        [$storeId]
    ));
    if ($updOrdersStmt->rowCount() !== count($orderIds)) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'order_update_rowcount_mismatch: 期待=' . count($orderIds) . ' 実際=' . $updOrdersStmt->rowCount() . ' (並行して別処理が paid 化した可能性)',
            409
        );
    }

    // 13b. 単一テーブルのセッション終了: tables.session_token 再生成 + table_sessions close
    //      process-payment.php:537-551 と同じ流儀。合流は Fix 1 で既に 409 拒否済みなので
    //      ここでは tableIdVal 必ず有効。
    $newSessionToken = bin2hex(random_bytes(16));
    $pdo->prepare(
        'UPDATE tables
            SET session_token = ?,
                session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR)
          WHERE id = ? AND store_id = ?'
    )->execute([$newSessionToken, $tableIdVal, $storeId]);
    try {
        $pdo->prepare(
            'UPDATE table_sessions
                SET status = "paid", closed_at = NOW()
              WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
        )->execute([$storeId, $tableIdVal]);
    } catch (PDOException $e) {
        // table_sessions 未作成環境はスキップ (process-payment.php と同じ)
        error_log('[pwa4c2] table_sessions close skip: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }

    // 14. emergency_payments を UPDATE (synced_payment_id + transferred_*)
    if ($hasTransferCols) {
        $pdo->prepare(
            'UPDATE emergency_payments
                SET synced_payment_id = ?,
                    transferred_by_user_id = ?,
                    transferred_by_name = ?,
                    transferred_at = NOW(),
                    transfer_note = ?,
                    updated_at = NOW()
              WHERE id = ? AND tenant_id = ? AND store_id = ?'
        )->execute([
            $paymentId, $transferByUserId, $transferByName, $transferNoteStored,
            $emergencyPaymentId, $tenantId, $storeId,
        ]);
    } else {
        // migration-pwa4c2 未適用の保険 (実運用では発生しない想定)
        $pdo->prepare(
            'UPDATE emergency_payments
                SET synced_payment_id = ?, updated_at = NOW()
              WHERE id = ? AND tenant_id = ? AND store_id = ?'
        )->execute([$paymentId, $emergencyPaymentId, $tenantId, $storeId]);
    }

    $pdo->commit();
    $inTx = false;

    json_response([
        'emergencyPaymentId' => $emergencyPaymentId,
        'paymentId'          => $paymentId,
        'transferred'        => true,
        'idempotent'         => false,
        'approximated'       => $approximated,
    ]);
} catch (PDOException $e) {
    if ($inTx) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        $inTx = false;
    }
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/emergency-payment-transfer.php] db_error: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '緊急会計の転記に失敗しました', 500);
}
