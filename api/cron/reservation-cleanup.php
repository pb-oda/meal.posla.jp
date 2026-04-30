<?php
/**
 * L-9 予約管理 — クリーンアップ (cron)
 *
 * 推奨実行頻度: 1 時間ごと
 *
 * - 期限切れ holds 削除
 * - 過去予約で confirmed のまま放置されているものを auto-no-show (deposit capture/release は実施しない)
 *   注意: deposit があるものは触らない (人手で no-show 操作してもらう)
 * - 30 日以上前の cancelled/no_show の log は今後 (未実装)
 */

if (php_sapi_name() !== 'cli') {
    // 環境変数 POSLA_CRON_SECRET が未設定なら HTTP 経路は無効（CLI 専用化）
    // 既定値 'change-me' へのフォールバックは脆弱なため廃止
    $expected = getenv('POSLA_CRON_SECRET') ?: '';
    if (!$expected || ($_SERVER['HTTP_X_POSLA_CRON_SECRET'] ?? '') !== $expected) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
}

require_once __DIR__ . '/../config/app.php'; // REL-HOTFIX-20260423-SERVER-READY: POSLA_PHP_ERROR_LOG 定数を確実にロード
require_once __DIR__ . '/../lib/db.php';
$pdo = get_db();

$result = ['holds_purged' => 0, 'waitlist_holds_expired' => 0, 'auto_no_show' => 0];

try {
    $wStmt = $pdo->prepare(
        'UPDATE reservation_waitlist_candidates
            SET hold_id = NULL, hold_expires_at = NULL, updated_at = NOW()
          WHERE hold_expires_at IS NOT NULL AND hold_expires_at <= NOW() AND status = "notified"'
    );
    $wStmt->execute();
    $result['waitlist_holds_expired'] = $wStmt->rowCount();
    $stmt = $pdo->prepare('DELETE FROM reservation_holds WHERE expires_at <= NOW()');
    $stmt->execute();
    $result['holds_purged'] = $stmt->rowCount();
} catch (PDOException $e) {
    error_log('[L-9][cleanup] purge_holds: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

try {
    // 6h 以上前の confirmed (deposit なし) を auto no_show
    $stmt = $pdo->prepare(
        "UPDATE reservations
         SET status = 'no_show', updated_at = NOW()
         WHERE status IN ('confirmed','pending')
           AND deposit_required = 0
           AND reserved_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)"
    );
    $stmt->execute();
    $result['auto_no_show'] = $stmt->rowCount();
} catch (PDOException $e) {
    error_log('[L-9][cleanup] auto_no_show: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

if (php_sapi_name() === 'cli') {
    echo "[L-9] cleanup: " . json_encode($result) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'result' => $result]);
}
