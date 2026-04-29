<?php
/**
 * レジ本締め後の金銭操作ロック判定
 *
 * 直近のレジ開閉操作が close の場合、その営業日内の会計・取消・返金・入出金は
 * 理由入力を必須にする。DB 書き込みは呼び出し側で成功後に監査ログへ残す。
 */

require_once __DIR__ . '/business-day.php';

function register_close_lock_trim($value, int $maxLen = 255): string
{
    $text = trim((string)$value);
    if ($text === '') return '';
    if (function_exists('mb_substr')) return mb_substr($text, 0, $maxLen);
    return substr($text, 0, $maxLen);
}

function get_register_close_lock_state(PDO $pdo, string $storeId, ?array $businessDay = null): array
{
    if ($businessDay === null) {
        $businessDay = get_business_day($pdo, $storeId);
    }

    $result = [
        'locked' => false,
        'businessDay' => $businessDay['date'] ?? null,
        'closedAt' => null,
        'closeLogId' => null,
        'closedBy' => null,
        'closeNote' => null,
    ];

    try {
        $stmt = $pdo->prepare(
            'SELECT cl.id, cl.type, cl.created_at, cl.note, u.display_name AS user_name
               FROM cash_log cl
               LEFT JOIN users u ON u.id = cl.user_id
              WHERE cl.store_id = ?
                AND cl.type IN ("open", "close")
                AND cl.created_at >= ? AND cl.created_at < ?
              ORDER BY cl.created_at DESC, cl.id DESC
              LIMIT 1'
        );
        $stmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);
        $row = $stmt->fetch();
        if ($row && $row['type'] === 'close') {
            $result['locked'] = true;
            $result['closedAt'] = $row['created_at'];
            $result['closeLogId'] = $row['id'];
            $result['closedBy'] = $row['user_name'] ?? null;
            $result['closeNote'] = $row['note'] ?? null;
        }
    } catch (PDOException $e) {
        $result['error'] = 'cash_log_unavailable';
    }

    return $result;
}

function require_register_close_override(PDO $pdo, string $storeId, array $payload, string $operationLabel, ?array $businessDay = null): array
{
    $state = get_register_close_lock_state($pdo, $storeId, $businessDay);
    if (empty($state['locked'])) {
        return [
            'locked' => false,
            'reason' => null,
            'state' => $state,
        ];
    }

    $reason = register_close_lock_trim($payload['post_close_reason'] ?? '');
    if ($reason === '') {
        $message = 'レジ本締め後の操作です。' . $operationLabel . 'を行う場合は理由を入力してください。';
        if (!empty($state['closedAt'])) {
            $message .= ' 最終締め: ' . $state['closedAt'];
        }
        json_error('REGISTER_CLOSED', $message, 409);
    }

    return [
        'locked' => true,
        'reason' => $reason,
        'state' => $state,
    ];
}

function register_close_override_audit_payload(array $lock): array
{
    $state = $lock['state'] ?? [];
    return [
        'post_close_override' => !empty($lock['locked']) ? 1 : 0,
        'post_close_reason' => $lock['reason'] ?? null,
        'closed_at' => $state['closedAt'] ?? null,
        'close_log_id' => $state['closeLogId'] ?? null,
        'closed_by' => $state['closedBy'] ?? null,
        'business_day' => $state['businessDay'] ?? null,
    ];
}
