<?php
/**
 * L-9 予約管理 — 店舗側 CRUD API
 *
 * GET ?store_id=xxx&date=YYYY-MM-DD            … 1日のガント描画用
 * GET ?store_id=xxx&from=YYYY-MM-DD&to=...     … 範囲取得 (週次/月次)
 * GET ?store_id=xxx&id=xxx                     … 単一予約詳細
 * POST   { store_id, ... }                     … 新規作成 (電話受付/walk-in/サイト)
 * PATCH  { id, store_id, ... }                 … 予約変更
 * DELETE ?id=xxx&store_id=xxx                  … キャンセル
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/reservation-availability.php';
require_once __DIR__ . '/../lib/reservation-notifier.php';
require_once __DIR__ . '/../lib/reservation-deposit.php';
require_once __DIR__ . '/../lib/reservation-history.php';
require_once __DIR__ . '/../lib/reservation-waitlist.php';
require_once __DIR__ . '/../config/app.php';

$method = require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('staff');
$pdo = get_db();

// ---------- ヘルパ ----------
function _l9_uuid() {
    return bin2hex(random_bytes(18));
}
function _l9_validate_party_size($v, $min = 1, $max = 50) {
    $n = (int)$v;
    if ($n < $min || $n > $max) json_error('INVALID_PARTY_SIZE', '人数が不正です', 400);
    return $n;
}
function _l9_validate_datetime($s) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', (string)$s)) {
        json_error('INVALID_DATETIME', '日時形式が不正です (YYYY-MM-DD HH:MM)', 400);
    }
    $ts = strtotime($s);
    if (!$ts) json_error('INVALID_DATETIME', '日時を解釈できません', 400);
    return date('Y-m-d H:i:s', $ts);
}
function _l9_load_reservation($pdo, $resId, $storeId) {
    // RSV-P1-1: 予約詳細で顧客要約カードを出すため reservation_customers を LEFT JOIN。
    // customer_id が null の予約は全カラム null になるだけで既存動作を壊さない (additive)
    // hotfix: 同一 store / 同一 tenant の顧客だけマッチするよう JOIN 条件を seal
    //   (reservation_customers schema: tenant_id / store_id 両 NOT NULL)
    $stmt = $pdo->prepare(
        'SELECT r.*,
                rc.is_vip           AS c_is_vip,
                rc.is_blacklisted   AS c_is_blacklisted,
                rc.blacklist_reason AS c_blacklist_reason,
                rc.allergies        AS c_allergies,
                rc.preferences      AS c_preferences,
                rc.internal_memo    AS c_internal_memo,
                rc.tags             AS c_tags,
                rc.visit_count      AS c_visit_count,
                rc.no_show_count    AS c_no_show_count,
                rc.cancel_count     AS c_cancel_count,
                rc.total_spend      AS c_total_spend,
                rc.last_visit_at    AS c_last_visit_at
         FROM reservations r
         LEFT JOIN reservation_customers rc
                ON rc.id = r.customer_id
               AND rc.store_id = r.store_id
               AND rc.tenant_id = r.tenant_id
         WHERE r.id = ? AND r.store_id = ?'
    );
    $stmt->execute([$resId, $storeId]);
    $r = $stmt->fetch();
    if (!$r) json_error('RESERVATION_NOT_FOUND', '予約が見つかりません', 404);
    return $r;
}
function _l9_assert_table_ids_capacity($pdo, $storeId, $tableIds, $partySize) {
    if (!is_array($tableIds) || empty($tableIds)) return;
    $place = implode(',', array_fill(0, count($tableIds), '?'));
    $params = array_merge($tableIds, [$storeId]);
    $stmt = $pdo->prepare("SELECT id, capacity FROM tables WHERE id IN ($place) AND store_id = ? AND is_active = 1");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (count($rows) !== count($tableIds)) json_error('INVALID_TABLE_IDS', '指定テーブルが店舗に属していません', 400);
    $cap = 0;
    foreach ($rows as $r) $cap += (int)$r['capacity'];
    if ($cap < $partySize) json_error('TABLE_CAPACITY_SHORT', '指定テーブルの収容人数が不足です', 400);
}
function _l9_decode_table_ids($value) {
    if (!$value) return [];
    if (is_array($value)) return $value;
    $arr = json_decode($value, true);
    return is_array($arr) ? $arr : [];
}
function _l9_intervals_overlap($aStart, $aEnd, $bStart, $bEnd) {
    return $aStart < $bEnd && $aEnd > $bStart;
}
function _l9_assert_reservation_conflict_free($pdo, $storeId, $reservedAt, $durationMin, $partySize, $tableIds, $excludeReservationId = null) {
    $tableIds = is_array($tableIds) ? array_map('strval', array_values($tableIds)) : [];
    $settings = get_reservation_settings($pdo, $storeId);
    $bufferBefore = (int)($settings['buffer_before_min'] ?? 0);
    $bufferAfter = (int)($settings['buffer_after_min'] ?? 0);
    $baseStartTs = strtotime($reservedAt);
    if (!$baseStartTs) json_error('INVALID_DATETIME', '日時を解釈できません', 400);
    $startTs = $baseStartTs - ($bufferBefore * 60);
    $endTs = $baseStartTs + max(15, (int)$durationMin) * 60 + ($bufferAfter * 60);
    $rangeFrom = date('Y-m-d H:i:s', $startTs - 3600);
    $rangeTo = date('Y-m-d H:i:s', $endTs + 3600);

    $sql = "SELECT id, customer_name, party_size, reserved_at, duration_min, assigned_table_ids, status
            FROM reservations
            WHERE store_id = ?
              AND status NOT IN ('cancelled','no_show','completed','waitlisted')
              AND reserved_at < ?
              AND DATE_ADD(reserved_at, INTERVAL duration_min MINUTE) > ?";
    $params = [$storeId, $rangeTo, $rangeFrom];
    if ($excludeReservationId) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeReservationId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $occupiedTableIds = [];
    $unassignedParty = 0;
    foreach ($stmt->fetchAll() as $row) {
        $rowBaseStart = strtotime($row['reserved_at']);
        $rowStart = $rowBaseStart - ($bufferBefore * 60);
        $rowEnd = $rowBaseStart + max(15, (int)$row['duration_min']) * 60 + ($bufferAfter * 60);
        if (!_l9_intervals_overlap($startTs, $endTs, $rowStart, $rowEnd)) continue;
        $rowTableIds = array_map('strval', _l9_decode_table_ids($row['assigned_table_ids']));
        if ($tableIds && $rowTableIds) {
            foreach ($tableIds as $tid) {
                if (in_array($tid, $rowTableIds, true)) {
                    json_error('RESERVATION_CONFLICT', '同じ時間帯に同じテーブルの予約があります: ' . $row['customer_name'], 409);
                }
            }
        }
        if ($rowTableIds) {
            foreach ($rowTableIds as $tid) $occupiedTableIds[$tid] = true;
        } else {
            $unassignedParty += (int)$row['party_size'];
        }
    }

    $sessionSql = "SELECT table_id, guest_count, started_at, expires_at
                   FROM table_sessions
                   WHERE store_id = ? AND status NOT IN ('paid','closed')";
    $sessionStmt = $pdo->prepare($sessionSql);
    $sessionStmt->execute([$storeId]);
    foreach ($sessionStmt->fetchAll() as $sess) {
        $sessionUntil = !empty($sess['expires_at']) ? strtotime($sess['expires_at']) : (time() + max(15, (int)$durationMin) * 60);
        if ($sessionUntil && $startTs >= $sessionUntil) continue;
        $sessionTableId = (string)$sess['table_id'];
        if ($tableIds && in_array($sessionTableId, $tableIds, true)) {
            json_error('TABLE_OCCUPIED', '指定テーブルは着席中です', 409);
        }
        $occupiedTableIds[$sessionTableId] = true;
    }

    if ($tableIds) return;

    $tStmt = $pdo->prepare('SELECT id, capacity FROM tables WHERE store_id = ? AND is_active = 1');
    $tStmt->execute([$storeId]);
    $freeCapacity = 0;
    foreach ($tStmt->fetchAll() as $tbl) {
        if (!isset($occupiedTableIds[(string)$tbl['id']])) $freeCapacity += (int)$tbl['capacity'];
    }
    $freeCapacity -= $unassignedParty;
    if ($freeCapacity < (int)$partySize) {
        json_error('SLOT_UNAVAILABLE', 'この時間帯は空席が不足しています', 409);
    }
}
function _l9_upsert_customer($pdo, $tenantId, $storeId, $name, $phone, $email) {
    if (empty($phone)) {
        // 電話なしは新規作成のみ (識別不能なので統合しない)
        $cid = _l9_uuid();
        $pdo->prepare(
            'INSERT INTO reservation_customers (id, tenant_id, store_id, customer_name, customer_email, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$cid, $tenantId, $storeId, $name, $email]);
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
    $cid = _l9_uuid();
    $pdo->prepare(
        'INSERT INTO reservation_customers (id, tenant_id, store_id, customer_name, customer_phone, customer_email, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([$cid, $tenantId, $storeId, $name, $phone, $email]);
    return $cid;
}
function _l9_arrival_followup_labels() {
    return [
        'none' => '未対応',
        'contacted' => '連絡済み',
        'arriving' => '到着予定',
        'waiting_reply' => '折り返し待ち',
        'no_show_confirmed' => 'no-show確定',
    ];
}
function _l9_arrival_followup_label($status) {
    $labels = _l9_arrival_followup_labels();
    return array_key_exists($status, $labels) ? $labels[$status] : $labels['none'];
}
function _l9_waitlist_call_labels() {
    return [
        'not_called' => '未呼出',
        'called' => '呼出済み',
        'recalled' => '再呼出済み',
        'absent' => '不在',
        'seated' => '着席',
    ];
}
function _l9_waitlist_call_label($status) {
    $labels = _l9_waitlist_call_labels();
    return array_key_exists($status, $labels) ? $labels[$status] : $labels['not_called'];
}
function _l9_reservation_ops_risk($r) {
    $levelRank = ['normal' => 0, 'notice' => 1, 'warning' => 2, 'danger' => 3];
    $risk = [
        'level' => 'normal',
        'label' => '通常',
        'reasons' => [],
        'minutes_late' => 0,
    ];
    $setLevel = function ($level, $label) use (&$risk, $levelRank) {
        if (($levelRank[$level] ?? 0) >= ($levelRank[$risk['level']] ?? 0)) {
            $risk['level'] = $level;
            $risk['label'] = $label;
        }
    };
    $addReason = function ($reason) use (&$risk) {
        if ($reason !== '' && !in_array($reason, $risk['reasons'], true)) {
            $risk['reasons'][] = $reason;
        }
    };

    $status = isset($r['status']) ? (string)$r['status'] : '';
    if (in_array($status, ['cancelled', 'no_show', 'completed', 'seated', 'waitlisted'], true)) {
        return $risk;
    }
    $followStatus = !empty($r['arrival_followup_status']) ? (string)$r['arrival_followup_status'] : 'none';
    $followed = in_array($followStatus, ['contacted', 'arriving', 'waiting_reply'], true);
    if ($followStatus === 'no_show_confirmed') {
        $setLevel('danger', 'no-show確定');
        $addReason('遅刻対応: no-show確定');
    } elseif ($followed) {
        $setLevel('notice', _l9_arrival_followup_label($followStatus));
        $addReason('遅刻対応: ' . _l9_arrival_followup_label($followStatus));
    }

    $reservedTs = !empty($r['reserved_at']) ? strtotime($r['reserved_at']) : false;
    if ($reservedTs && !$followed && $followStatus !== 'no_show_confirmed') {
        $lateMin = (int)floor((time() - $reservedTs) / 60);
        if ($lateMin >= 15) {
            $risk['minutes_late'] = $lateMin;
            $setLevel('danger', 'no-show候補');
            $addReason('予約時刻から15分以上経過');
        } elseif ($lateMin >= 5) {
            $risk['minutes_late'] = $lateMin;
            $setLevel('warning', '遅刻確認');
            $addReason('予約時刻から5分以上経過');
        }
    }

    $noShowCount = array_key_exists('c_no_show_count', $r) ? (int)$r['c_no_show_count'] : 0;
    $cancelCount = array_key_exists('c_cancel_count', $r) ? (int)$r['c_cancel_count'] : 0;
    if ($noShowCount >= 2) {
        $setLevel('danger', 'no-show注意');
        $addReason('過去no-show ' . $noShowCount . '回');
    } elseif ($noShowCount >= 1) {
        $setLevel('warning', 'no-show注意');
        $addReason('過去no-showあり');
    }
    if ($cancelCount >= 3) {
        $setLevel('warning', 'キャンセル多め');
        $addReason('過去キャンセル ' . $cancelCount . '回');
    }
    if (array_key_exists('c_is_blacklisted', $r) && (int)$r['c_is_blacklisted'] === 1) {
        $setLevel('danger', '来店注意');
        $addReason('ブラックリスト指定');
    }

    return $risk;
}
function _l9_reservation_reminder_status($r) {
    $hasEmail = !empty($r['customer_email']);
    $reservedTs = !empty($r['reserved_at']) ? strtotime($r['reserved_at']) : false;
    $minutesUntil = $reservedTs ? (int)floor(($reservedTs - time()) / 60) : null;
    $reservationStatus = isset($r['status']) ? (string)$r['status'] : '';
    $status = [
        'has_email' => $hasEmail ? 1 : 0,
        'level' => $hasEmail ? 'normal' : 'disabled',
        'label' => $hasEmail ? '未送信' : 'メールなし',
        'next_due' => null,
        'minutes_until' => $minutesUntil,
    ];
    if (!in_array($reservationStatus, ['confirmed', 'pending'], true)) {
        $status['level'] = 'disabled';
        $status['label'] = '対象外';
        return $status;
    }
    if (!$hasEmail) return $status;

    if (!empty($r['reminder_2h_sent_at'])) {
        $status['level'] = 'sent';
        $status['label'] = '2h済';
        return $status;
    }
    if (!empty($r['reminder_24h_sent_at'])) {
        $status['level'] = 'sent';
        $status['label'] = '24h済';
    }

    if ($minutesUntil !== null && $minutesUntil >= 0 && $minutesUntil <= 135 && empty($r['reminder_2h_sent_at'])) {
        $status['level'] = 'due';
        $status['label'] = '2h前未送信';
        $status['next_due'] = 'reminder_2h';
    } elseif ($minutesUntil !== null && $minutesUntil >= 0 && $minutesUntil <= 1500 && empty($r['reminder_24h_sent_at'])) {
        $status['level'] = 'due';
        $status['label'] = '24h前未送信';
        $status['next_due'] = 'reminder_24h';
    }
    return $status;
}
function _l9_reservation_reminder_delivery($pdo, $r) {
    if (!$pdo || empty($r['id']) || empty($r['store_id'])) return [];
    try {
        $stmt = $pdo->prepare(
            "SELECT notification_type, channel, status, error_message, sent_at, created_at
             FROM reservation_notifications_log
             WHERE reservation_id = ? AND store_id = ?
               AND notification_type IN ('reminder_24h','reminder_2h')
             ORDER BY created_at DESC
             LIMIT 8"
        );
        $stmt->execute([$r['id'], $r['store_id']]);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('[L-9][reservations] reminder_delivery: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        return [];
    }
}
function _l9_reservation_risk_actions($r, $risk) {
    $actions = [];
    $status = isset($r['status']) ? (string)$r['status'] : '';
    if (!in_array($status, ['confirmed', 'pending'], true)) return $actions;
    if (array_key_exists('c_is_blacklisted', $r) && (int)$r['c_is_blacklisted'] === 1) {
        $actions[] = ['key' => 'manager_confirm', 'level' => 'danger', 'label' => '店長確認必須', 'detail' => 'ブラックリスト指定があります'];
    }
    $noShowCount = array_key_exists('c_no_show_count', $r) ? (int)$r['c_no_show_count'] : 0;
    if ($noShowCount >= 1) {
        $actions[] = ['key' => 'phone_confirm', 'level' => $noShowCount >= 2 ? 'danger' : 'warning', 'label' => '電話確認推奨', 'detail' => '過去no-show履歴があります'];
    }
    if (!empty($risk['minutes_late']) && (int)$risk['minutes_late'] >= 5) {
        $actions[] = ['key' => 'late_followup', 'level' => (int)$risk['minutes_late'] >= 15 ? 'danger' : 'warning', 'label' => '遅刻対応を記録', 'detail' => '連絡済み/到着予定/no-show確定を選択してください'];
    }
    if ((int)($r['party_size'] ?? 0) >= 8) {
        $actions[] = ['key' => 'large_party_confirm', 'level' => 'warning', 'label' => '大人数確認', 'detail' => '人数・席・コースの事前確認を推奨'];
    }
    if ((int)($r['deposit_required'] ?? 0) !== 1 && ($noShowCount >= 2 || (int)($r['party_size'] ?? 0) >= 8)) {
        $actions[] = ['key' => 'consider_deposit', 'level' => 'notice', 'label' => '予約金検討', 'detail' => '高リスクまたは大人数のため予約金運用を検討'];
    }
    return $actions;
}
function _l9_serialize_reservation($r, $pdo = null, $includeDetails = false) {
    // RSV-P1-1: 顧客要約フィールドを additive に追加 (LEFT JOIN の結果、customer_id 未設定なら全て null)
    $arrivalFollowupStatus = array_key_exists('arrival_followup_status', $r) && $r['arrival_followup_status'] ? $r['arrival_followup_status'] : 'none';
    $waitlistCallStatus = array_key_exists('waitlist_call_status', $r) && $r['waitlist_call_status'] ? $r['waitlist_call_status'] : 'not_called';
    $opsRisk = _l9_reservation_ops_risk($r);
    $data = [
        'id' => $r['id'],
        'tenant_id' => $r['tenant_id'],
        'store_id' => $r['store_id'],
        'customer_name' => $r['customer_name'],
        'customer_phone' => $r['customer_phone'],
        'customer_email' => $r['customer_email'],
        'party_size' => (int)$r['party_size'],
        'reserved_at' => $r['reserved_at'],
        'duration_min' => (int)$r['duration_min'],
        'status' => $r['status'],
        'source' => $r['source'],
        'assigned_table_ids' => $r['assigned_table_ids'] ? json_decode($r['assigned_table_ids'], true) : [],
        'course_id' => $r['course_id'],
        'course_name' => $r['course_name'],
        'memo' => $r['memo'],
        'tags' => $r['tags'],
        'language' => $r['language'],
        'table_session_id' => $r['table_session_id'],
        'customer_id' => $r['customer_id'],
        'deposit_required' => (int)$r['deposit_required'],
        'deposit_amount' => (int)$r['deposit_amount'],
        'deposit_status' => $r['deposit_status'],
        'cancel_policy_hours' => $r['cancel_policy_hours'] !== null ? (int)$r['cancel_policy_hours'] : null,
        'cancel_reason' => $r['cancel_reason'],
        'arrival_followup_status' => $arrivalFollowupStatus,
        'arrival_followup_label' => _l9_arrival_followup_label($arrivalFollowupStatus),
        'arrival_followup_note' => array_key_exists('arrival_followup_note', $r) ? $r['arrival_followup_note'] : null,
        'arrival_followup_at' => array_key_exists('arrival_followup_at', $r) ? $r['arrival_followup_at'] : null,
        'arrival_followup_user_id' => array_key_exists('arrival_followup_user_id', $r) ? $r['arrival_followup_user_id'] : null,
        'waitlist_call_status' => $waitlistCallStatus,
        'waitlist_call_label' => _l9_waitlist_call_label($waitlistCallStatus),
        'waitlist_call_count' => array_key_exists('waitlist_call_count', $r) && $r['waitlist_call_count'] !== null ? (int)$r['waitlist_call_count'] : 0,
        'waitlist_called_at' => array_key_exists('waitlist_called_at', $r) ? $r['waitlist_called_at'] : null,
        'waitlist_call_user_id' => array_key_exists('waitlist_call_user_id', $r) ? $r['waitlist_call_user_id'] : null,
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
        'confirmed_at' => $r['confirmed_at'],
        'seated_at' => $r['seated_at'],
        'cancelled_at' => $r['cancelled_at'],
        'reminder_24h_sent_at' => $r['reminder_24h_sent_at'],
        'reminder_2h_sent_at' => $r['reminder_2h_sent_at'],
        // RSV-P1-1: 顧客要約 (reservation_customers から LEFT JOIN、未 JOIN 時は全て null)
        'customer_is_vip'           => array_key_exists('c_is_vip', $r) && $r['c_is_vip'] !== null ? (int)$r['c_is_vip'] : null,
        'customer_is_blacklisted'   => array_key_exists('c_is_blacklisted', $r) && $r['c_is_blacklisted'] !== null ? (int)$r['c_is_blacklisted'] : null,
        'customer_blacklist_reason' => array_key_exists('c_blacklist_reason', $r) ? $r['c_blacklist_reason'] : null,
        'customer_allergies'        => array_key_exists('c_allergies', $r) ? $r['c_allergies'] : null,
        'customer_preferences'      => array_key_exists('c_preferences', $r) ? $r['c_preferences'] : null,
        'customer_internal_memo'    => array_key_exists('c_internal_memo', $r) ? $r['c_internal_memo'] : null,
        'customer_tags'             => array_key_exists('c_tags', $r) ? $r['c_tags'] : null,
        'customer_visit_count'      => array_key_exists('c_visit_count', $r) && $r['c_visit_count'] !== null ? (int)$r['c_visit_count'] : null,
        'customer_no_show_count'    => array_key_exists('c_no_show_count', $r) && $r['c_no_show_count'] !== null ? (int)$r['c_no_show_count'] : null,
        'customer_cancel_count'     => array_key_exists('c_cancel_count', $r) && $r['c_cancel_count'] !== null ? (int)$r['c_cancel_count'] : null,
        'customer_total_spend'      => array_key_exists('c_total_spend', $r) && $r['c_total_spend'] !== null ? (int)$r['c_total_spend'] : null,
        'customer_last_visit_at'    => array_key_exists('c_last_visit_at', $r) ? $r['c_last_visit_at'] : null,
        'ops_risk'                  => $opsRisk,
        'risk_actions'              => _l9_reservation_risk_actions($r, $opsRisk),
        'reminder_status'           => _l9_reservation_reminder_status($r),
    ];
    if ($includeDetails && $pdo) {
        $data['change_history'] = reservation_history_fetch($pdo, $r['id'], $r['store_id']);
        $data['reminder_delivery'] = _l9_reservation_reminder_delivery($pdo, $r);
    }
    return $data;
}

// ---------- GET ----------
if ($method === 'GET') {
    $storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
    if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);
    require_store_access($storeId);

    if (isset($_GET['action']) && $_GET['action'] === 'availability') {
        $date = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
        $partySize = isset($_GET['party_size']) ? max(1, (int)$_GET['party_size']) : 2;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_error('INVALID_DATE', '日付形式が不正です', 400);
        }

        $settings = get_reservation_settings($pdo, $storeId);
        if ($partySize < (int)$settings['min_party_size']) json_error('INVALID_PARTY_SIZE', '人数が下限未満です', 400);
        if ($partySize > (int)$settings['max_party_size']) json_error('INVALID_PARTY_SIZE', '人数が上限超過です', 400);

        purge_expired_holds($pdo, $storeId);
        $slots = compute_slot_availability($pdo, $storeId, $date, $partySize);
        json_response([
            'date' => $date,
            'party_size' => $partySize,
            'slots' => $slots,
            'settings' => [
                'open_time' => substr($settings['open_time'], 0, 5),
                'close_time' => substr($settings['close_time'], 0, 5),
                'slot_interval_min' => (int)$settings['slot_interval_min'],
                'default_duration_min' => (int)$settings['default_duration_min'],
                'min_party_size' => (int)$settings['min_party_size'],
                'max_party_size' => (int)$settings['max_party_size'],
            ],
        ]);
    }

    // 単一取得
    if (!empty($_GET['id'])) {
        $r = _l9_load_reservation($pdo, $_GET['id'], $storeId);
        json_response(['reservation' => _l9_serialize_reservation($r, $pdo, true)]);
    }

    $from = isset($_GET['from']) ? $_GET['from'] : (isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'));
    $to = isset($_GET['to']) ? $_GET['to'] : $from;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        json_error('INVALID_DATE', '日付形式が不正です', 400);
    }
    $fromDt = $from . ' 00:00:00';
    $toDt = date('Y-m-d 23:59:59', strtotime($to));

    // RSV-P1-1: 顧客要約フィールドを additive に取得 (LEFT JOIN、customer_id 未設定は null)
    // hotfix: JOIN 条件に store_id / tenant_id を追加してマルチテナント境界を seal
    $stmt = $pdo->prepare(
        "SELECT r.*,
                rc.is_vip           AS c_is_vip,
                rc.is_blacklisted   AS c_is_blacklisted,
                rc.blacklist_reason AS c_blacklist_reason,
                rc.allergies        AS c_allergies,
                rc.preferences      AS c_preferences,
                rc.internal_memo    AS c_internal_memo,
                rc.tags             AS c_tags,
                rc.visit_count      AS c_visit_count,
                rc.no_show_count    AS c_no_show_count,
                rc.cancel_count     AS c_cancel_count,
                rc.total_spend      AS c_total_spend,
                rc.last_visit_at    AS c_last_visit_at
         FROM reservations r
         LEFT JOIN reservation_customers rc
                ON rc.id = r.customer_id
               AND rc.store_id = r.store_id
               AND rc.tenant_id = r.tenant_id
         WHERE r.store_id = ?
           AND r.reserved_at >= ? AND r.reserved_at <= ?
         ORDER BY r.reserved_at ASC"
    );
    $stmt->execute([$storeId, $fromDt, $toDt]);
    $list = [];
    foreach ($stmt->fetchAll() as $r) $list[] = _l9_serialize_reservation($r);

    // テーブル一覧 (ガント縦軸)
    $tStmt = $pdo->prepare('SELECT id, table_code AS label, capacity, floor AS area FROM tables WHERE store_id = ? AND is_active = 1 ORDER BY floor, table_code');
    $tStmt->execute([$storeId]);
    $tables = $tStmt->fetchAll();

    // 設定
    $settings = get_reservation_settings($pdo, $storeId);

    // サマリー (RSV-P1-2: waitlisted 集計を追加 / RSV-P1-1: 注目客集計を追加)
    $summary = [
        'total' => count($list), 'confirmed' => 0, 'seated' => 0, 'no_show' => 0,
        'cancelled_today' => 0, 'walk_in' => 0, 'waitlisted' => 0, 'guests' => 0,
        // RSV-P1-1: 今日の注目客 (attention = VIP or blacklist or allergies or internal_memo)
        'attention_total' => 0, 'vip' => 0, 'blacklist' => 0, 'allergy' => 0, 'memo' => 0,
        'arrival_risk' => 0, 'late_risk' => 0, 'reminder_due' => 0,
        'waitlist_called' => 0, 'waitlist_absent' => 0,
    ];
    foreach ($list as $r) {
        if ($r['status'] === 'confirmed' || $r['status'] === 'pending') $summary['confirmed']++;
        if ($r['status'] === 'seated') $summary['seated']++;
        if ($r['status'] === 'no_show') $summary['no_show']++;
        if ($r['status'] === 'waitlisted') $summary['waitlisted']++;
        if ($r['source'] === 'walk_in') $summary['walk_in']++;
        if ($r['status'] !== 'cancelled' && $r['status'] !== 'no_show') $summary['guests'] += $r['party_size'];
        // RSV-P1-1: 注目客フラグ集計 (cancel/no_show は除外)
        if ($r['status'] !== 'cancelled' && $r['status'] !== 'no_show') {
            $isVip       = (int)($r['customer_is_vip'] ?? 0) === 1;
            $isBlack     = (int)($r['customer_is_blacklisted'] ?? 0) === 1;
            $hasAllergy  = !empty($r['customer_allergies']);
            $hasMemo     = !empty($r['customer_internal_memo']);
            if ($isVip) $summary['vip']++;
            if ($isBlack) $summary['blacklist']++;
            if ($hasAllergy) $summary['allergy']++;
            if ($hasMemo) $summary['memo']++;
            if ($isVip || $isBlack || $hasAllergy || $hasMemo) $summary['attention_total']++;
        }
        if (isset($r['ops_risk']) && in_array($r['ops_risk']['level'], ['warning', 'danger'], true)) {
            $summary['arrival_risk']++;
            if (!empty($r['ops_risk']['minutes_late'])) $summary['late_risk']++;
        }
        if ($r['status'] === 'waitlisted') {
            $waitCallStatus = isset($r['waitlist_call_status']) ? (string)$r['waitlist_call_status'] : 'not_called';
            if (in_array($waitCallStatus, ['called', 'recalled'], true)) {
                $summary['waitlist_called']++;
            }
            if ($waitCallStatus === 'absent') {
                $summary['waitlist_absent']++;
            }
        }
        if (isset($r['reminder_status']) && $r['reminder_status']['level'] === 'due') {
            $summary['reminder_due']++;
        }
    }

    json_response([
        'reservations' => $list,
        'waitlist_candidates' => reservation_waitlist_fetch_for_date($pdo, $storeId, $from),
        'tables' => $tables,
        'settings' => [
            'open_time' => substr($settings['open_time'], 0, 5),
            'close_time' => substr($settings['close_time'], 0, 5),
            'slot_interval_min' => (int)$settings['slot_interval_min'],
            'default_duration_min' => (int)$settings['default_duration_min'],
            'buffer_before_min' => (int)$settings['buffer_before_min'],
            'buffer_after_min' => (int)$settings['buffer_after_min'],
        ],
        'summary' => $summary,
        'range' => ['from' => $from, 'to' => $to],
    ]);
}

// ---------- POST (新規作成) ----------
if ($method === 'POST') {
    $body = get_json_body();
    $storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
    if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);
    require_store_access($storeId);

    $sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores s WHERE id = ?');
    $sStmt->execute([$storeId]);
    $store = $sStmt->fetch();
    if (!$store) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);

    $name = trim((string)($body['customer_name'] ?? ''));
    if ($name === '') json_error('MISSING_NAME', 'お客様名が必要です', 400);
    $phone = trim((string)($body['customer_phone'] ?? ''));
    $email = trim((string)($body['customer_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('INVALID_EMAIL', 'メールアドレスが不正です', 400);

    $partySize = _l9_validate_party_size($body['party_size'] ?? 0);
    $reservedAt = _l9_validate_datetime($body['reserved_at'] ?? '');
    $settings = get_reservation_settings($pdo, $storeId);
    $duration = isset($body['duration_min']) ? max(15, (int)$body['duration_min']) : (int)$settings['default_duration_min'];

    $source = isset($body['source']) ? $body['source'] : 'phone';
    if (!in_array($source, ['web','phone','walk_in','google','external','ai_chat'], true)) $source = 'phone';

    $tableIds = isset($body['assigned_table_ids']) && is_array($body['assigned_table_ids']) ? array_values($body['assigned_table_ids']) : [];
    if ($tableIds) _l9_assert_table_ids_capacity($pdo, $storeId, $tableIds, $partySize);

    $courseId = isset($body['course_id']) ? trim((string)$body['course_id']) : null;
    $courseName = isset($body['course_name']) ? trim((string)$body['course_name']) : null;
    if ($courseId && !$courseName) {
        $cStmt = $pdo->prepare('SELECT name FROM reservation_courses WHERE id = ? AND store_id = ?');
        $cStmt->execute([$courseId, $storeId]);
        $cRow = $cStmt->fetch();
        if ($cRow) $courseName = $cRow['name'];
    }

    $memo = isset($body['memo']) ? (string)$body['memo'] : null;
    $tags = isset($body['tags']) ? (string)$body['tags'] : null;
    $language = isset($body['language']) ? (string)$body['language'] : 'ja';
    // RSV-P1-2: 'waitlisted' を追加 (受付待ち客用)
    $status = isset($body['status']) && in_array($body['status'], ['pending','confirmed','seated','no_show','cancelled','completed','waitlisted'], true) ? $body['status'] : 'confirmed';
    if (!in_array($status, ['cancelled','no_show','completed','waitlisted'], true)) {
        _l9_assert_reservation_conflict_free($pdo, $storeId, $reservedAt, $duration, $partySize, $tableIds);
    }

    // 顧客台帳更新
    $customerId = _l9_upsert_customer($pdo, $store['tenant_id'], $storeId, $name, $phone, $email);

    // ブラックリストチェック
    if ($phone) {
        $blStmt = $pdo->prepare('SELECT is_blacklisted, blacklist_reason FROM reservation_customers WHERE store_id = ? AND customer_phone = ?');
        $blStmt->execute([$storeId, $phone]);
        $bl = $blStmt->fetch();
        if ($bl && (int)$bl['is_blacklisted'] === 1) {
            json_error('BLACKLISTED_CUSTOMER', 'この顧客は予約不可です: ' . ($bl['blacklist_reason'] ?: ''), 403);
        }
    }

    // デポジット判定 (店舗作成時はオプションで強制 OFF にできる)
    $skipDeposit = !empty($body['skip_deposit']);
    $depositRequired = 0;
    $depositAmount = 0;
    $depositStatus = 'not_required';
    if (!$skipDeposit && reservation_deposit_is_available($pdo, $storeId, $store['tenant_id'], $settings)) {
        $amt = reservation_deposit_amount($settings, $partySize);
        if ($amt > 0) {
            $depositRequired = 1;
            $depositAmount = $amt;
            $depositStatus = 'pending';
        }
    }

    $resId = _l9_uuid();
    $editToken = bin2hex(random_bytes(24));

    $pdo->prepare(
        "INSERT INTO reservations
         (id, tenant_id, store_id, customer_name, customer_phone, customer_email, party_size, reserved_at, duration_min, status, source, assigned_table_ids, course_id, course_name, memo, tags, language, customer_id, deposit_required, deposit_amount, deposit_status, cancel_policy_hours, edit_token, created_by_user_id, created_at, updated_at, confirmed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)"
    )->execute([
        $resId, $store['tenant_id'], $storeId, $name, $phone, $email, $partySize, $reservedAt, $duration, $status, $source,
        $tableIds ? json_encode($tableIds, JSON_UNESCAPED_UNICODE) : null,
        $courseId, $courseName, $memo, $tags, $language, $customerId,
        $depositRequired, $depositAmount, $depositStatus,
        (int)$settings['cancel_deadline_hours'], $editToken, $user['user_id'],
        $status === 'confirmed' ? date('Y-m-d H:i:s') : null,
    ]);
    reservation_waitlist_mark_booked($pdo, $storeId, $resId, $reservedAt, $partySize, $phone, $email);

    $r = _l9_load_reservation($pdo, $resId, $storeId);
    reservation_history_record($pdo, $r, 'staff', $user['user_id'], $user['username'] ?? $user['displayName'] ?? '', 'created', 'status', null, $r['status']);

    // 通知 (メールがあれば確認メール送信)
    if ($r['customer_email']) {
        $editUrl = app_url('/customer/reserve-detail.html') . '?id=' . urlencode($resId) . '&t=' . urlencode($editToken);
        send_reservation_notification($pdo, $r, 'confirm', ['edit_url' => $editUrl]);
    }

    json_response(['reservation' => _l9_serialize_reservation($r)]);
}

// ---------- PATCH (変更) ----------
if ($method === 'PATCH') {
    $body = get_json_body();
    $resId = isset($body['id']) ? trim($body['id']) : '';
    $storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
    if (!$resId || !$storeId) json_error('MISSING_PARAM', 'id と store_id が必要です', 400);
    require_store_access($storeId);

    $r = _l9_load_reservation($pdo, $resId, $storeId);

    $sets = [];
    $params = [];

    if (isset($body['customer_name'])) {
        $name = trim((string)$body['customer_name']);
        if ($name === '') json_error('MISSING_NAME', 'お客様名が空です', 400);
        $sets[] = 'customer_name = ?'; $params[] = $name;
    }
    if (isset($body['customer_phone'])) { $sets[] = 'customer_phone = ?'; $params[] = trim((string)$body['customer_phone']); }
    if (isset($body['customer_email'])) {
        $email = trim((string)$body['customer_email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('INVALID_EMAIL', 'メールアドレスが不正です', 400);
        $sets[] = 'customer_email = ?'; $params[] = $email;
    }
    if (isset($body['party_size'])) {
        $ps = _l9_validate_party_size($body['party_size']);
        $sets[] = 'party_size = ?'; $params[] = $ps;
    }
    if (isset($body['reserved_at'])) {
        $sets[] = 'reserved_at = ?'; $params[] = _l9_validate_datetime($body['reserved_at']);
    }
    if (isset($body['duration_min'])) {
        $sets[] = 'duration_min = ?'; $params[] = max(15, (int)$body['duration_min']);
    }
    if (isset($body['memo'])) { $sets[] = 'memo = ?'; $params[] = (string)$body['memo']; }
    if (isset($body['tags'])) { $sets[] = 'tags = ?'; $params[] = (string)$body['tags']; }
    if (isset($body['course_id'])) { $sets[] = 'course_id = ?'; $params[] = $body['course_id'] !== '' ? $body['course_id'] : null; }
    if (isset($body['course_name'])) { $sets[] = 'course_name = ?'; $params[] = $body['course_name'] !== '' ? $body['course_name'] : null; }
    if (array_key_exists('arrival_followup_status', $body)) {
        $arrivalStatus = trim((string)$body['arrival_followup_status']);
        if ($arrivalStatus === '') $arrivalStatus = 'none';
        $arrivalLabels = _l9_arrival_followup_labels();
        if (!array_key_exists($arrivalStatus, $arrivalLabels)) {
            json_error('INVALID_ARRIVAL_FOLLOWUP_STATUS', '遅刻対応ステータスが不正です', 400);
        }
        if ($arrivalStatus === 'no_show_confirmed') {
            json_error('USE_NO_SHOW_ENDPOINT', 'no-show確定は no-show 処理を使ってください', 400);
        }
        $sets[] = 'arrival_followup_status = ?'; $params[] = $arrivalStatus;
        if ($arrivalStatus === 'none') {
            $sets[] = 'arrival_followup_at = NULL';
            $sets[] = 'arrival_followup_user_id = NULL';
            if (!array_key_exists('arrival_followup_note', $body)) {
                $sets[] = 'arrival_followup_note = NULL';
            }
        } else {
            $sets[] = 'arrival_followup_at = NOW()';
            $sets[] = 'arrival_followup_user_id = ?'; $params[] = $user['user_id'];
        }
    }
    if (array_key_exists('arrival_followup_note', $body)) {
        $arrivalNote = trim((string)$body['arrival_followup_note']);
        $sets[] = 'arrival_followup_note = ?'; $params[] = $arrivalNote !== '' ? $arrivalNote : null;
    }
    if (array_key_exists('waitlist_call_status', $body)) {
        $waitCallStatus = trim((string)$body['waitlist_call_status']);
        if ($waitCallStatus === '') $waitCallStatus = 'not_called';
        $waitCallLabels = _l9_waitlist_call_labels();
        if (!array_key_exists($waitCallStatus, $waitCallLabels)) {
            json_error('INVALID_WAITLIST_CALL_STATUS', '待ち客呼び出しステータスが不正です', 400);
        }
        if ($r['status'] !== 'waitlisted' && $waitCallStatus !== 'seated') {
            json_error('NOT_WAITLISTED', '受付待ちの予約だけ呼び出し状態を更新できます', 400);
        }
        $sets[] = 'waitlist_call_status = ?'; $params[] = $waitCallStatus;
        if ($waitCallStatus === 'not_called') {
            $sets[] = 'waitlist_call_count = 0';
            $sets[] = 'waitlist_called_at = NULL';
            $sets[] = 'waitlist_call_user_id = NULL';
        } elseif ($waitCallStatus === 'called' || $waitCallStatus === 'recalled') {
            $sets[] = 'waitlist_call_count = waitlist_call_count + 1';
            $sets[] = 'waitlist_called_at = NOW()';
            $sets[] = 'waitlist_call_user_id = ?'; $params[] = $user['user_id'];
        } else {
            $sets[] = 'waitlist_called_at = NOW()';
            $sets[] = 'waitlist_call_user_id = ?'; $params[] = $user['user_id'];
        }
    }
    if (isset($body['assigned_table_ids']) && is_array($body['assigned_table_ids'])) {
        $tids = array_values($body['assigned_table_ids']);
        $checkPS = isset($body['party_size']) ? (int)$body['party_size'] : (int)$r['party_size'];
        if ($tids) _l9_assert_table_ids_capacity($pdo, $storeId, $tids, $checkPS);
        $sets[] = 'assigned_table_ids = ?'; $params[] = $tids ? json_encode($tids, JSON_UNESCAPED_UNICODE) : null;
    }
    // RSV-P1-2: 'waitlisted' を追加 (受付待ち客用)。waitlisted → seated 変換時は seated_at も埋める
    if (isset($body['status']) && in_array($body['status'], ['pending','confirmed','seated','no_show','cancelled','completed','waitlisted'], true)) {
        $sets[] = 'status = ?'; $params[] = $body['status'];
        if ($body['status'] === 'cancelled') { $sets[] = 'cancelled_at = NOW()'; }
        if ($body['status'] === 'completed') { /* completed_at もあれば追加 */ }
        if ($body['status'] === 'seated') { $sets[] = 'seated_at = COALESCE(seated_at, NOW())'; }
        if ($body['status'] === 'waitlisted' && !array_key_exists('waitlist_call_status', $body)) {
            $sets[] = "waitlist_call_status = 'not_called'";
            $sets[] = 'waitlist_call_count = 0';
            $sets[] = 'waitlist_called_at = NULL';
            $sets[] = 'waitlist_call_user_id = NULL';
        }
        if ($body['status'] === 'seated' && $r['status'] === 'waitlisted' && !array_key_exists('waitlist_call_status', $body)) {
            $sets[] = "waitlist_call_status = 'seated'";
        }
    }

    $nextStatus = isset($body['status']) && in_array($body['status'], ['pending','confirmed','seated','no_show','cancelled','completed','waitlisted'], true) ? $body['status'] : $r['status'];
    if (!in_array($nextStatus, ['cancelled','no_show','completed','waitlisted'], true)) {
        $nextReservedAt = isset($body['reserved_at']) ? _l9_validate_datetime($body['reserved_at']) : $r['reserved_at'];
        $nextDuration = isset($body['duration_min']) ? max(15, (int)$body['duration_min']) : (int)$r['duration_min'];
        $nextParty = isset($body['party_size']) ? (int)$body['party_size'] : (int)$r['party_size'];
        $nextTableIds = (isset($body['assigned_table_ids']) && is_array($body['assigned_table_ids'])) ? array_values($body['assigned_table_ids']) : _l9_decode_table_ids($r['assigned_table_ids']);
        _l9_assert_reservation_conflict_free($pdo, $storeId, $nextReservedAt, $nextDuration, $nextParty, $nextTableIds, $resId);
    }

    if (empty($sets)) json_error('NO_FIELDS', '変更する項目がありません', 400);

    $sets[] = 'updated_at = NOW()';
    $params[] = $resId;
    $params[] = $storeId;
    $sql = 'UPDATE reservations SET ' . implode(', ', $sets) . ' WHERE id = ? AND store_id = ?';
    $pdo->prepare($sql)->execute($params);

    $r2 = _l9_load_reservation($pdo, $resId, $storeId);
    reservation_history_record_fields(
        $pdo,
        $r,
        $r2,
        ['customer_name','customer_phone','customer_email','party_size','reserved_at','duration_min','memo','tags','course_id','course_name','assigned_table_ids','status','arrival_followup_status','waitlist_call_status'],
        'staff',
        $user['user_id'],
        $user['username'] ?? $user['displayName'] ?? '',
        'updated'
    );
    json_response(['reservation' => _l9_serialize_reservation($r2)]);
}

// ---------- DELETE (キャンセル) ----------
if ($method === 'DELETE') {
    $resId = isset($_GET['id']) ? trim($_GET['id']) : '';
    $storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
    if (!$resId || !$storeId) json_error('MISSING_PARAM', 'id と store_id が必要です', 400);
    require_store_access($storeId);

    $r = _l9_load_reservation($pdo, $resId, $storeId);
    if (in_array($r['status'], ['cancelled','no_show','completed'], true)) {
        json_error('ALREADY_FINAL', 'この予約は既に確定状態です: ' . $r['status'], 400);
    }
    $reason = isset($_GET['reason']) ? trim($_GET['reason']) : null;
    $pdo->prepare("UPDATE reservations SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ?, updated_at = NOW() WHERE id = ? AND store_id = ?")
        ->execute([$reason, $resId, $storeId]);
    $rCancelled = _l9_load_reservation($pdo, $resId, $storeId);
    reservation_history_record($pdo, $rCancelled, 'staff', $user['user_id'], $user['username'] ?? $user['displayName'] ?? '', 'cancelled', 'status', $r['status'], 'cancelled');
    if ($reason !== null) {
        reservation_history_record($pdo, $rCancelled, 'staff', $user['user_id'], $user['username'] ?? $user['displayName'] ?? '', 'cancelled', 'cancel_reason', $r['cancel_reason'], $reason);
    }
    $waitlistNotify = reservation_waitlist_notify_open_slot($pdo, $storeId, $r['reserved_at'], (int)$r['party_size'], 'reservation_cancelled');

    // デポジット release
    if ($r['deposit_payment_intent_id']) {
        $sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores WHERE id = ?');
        $sStmt->execute([$storeId]);
        $store = $sStmt->fetch();
        $rel = reservation_deposit_release($pdo, $r, $store);
        if ($rel['success']) {
            $pdo->prepare("UPDATE reservations SET deposit_status = 'released' WHERE id = ?")->execute([$resId]);
        }
    }

    if ($r['customer_email']) {
        $r2 = _l9_load_reservation($pdo, $resId, $storeId);
        send_reservation_notification($pdo, $r2, 'cancel');
    }
    if ($r['customer_phone']) {
        $pdo->prepare('UPDATE reservation_customers SET cancel_count = cancel_count + 1 WHERE store_id = ? AND customer_phone = ?')
            ->execute([$storeId, $r['customer_phone']]);
    }
    json_response(['ok' => true, 'waitlist_notify' => $waitlistNotify]);
}
