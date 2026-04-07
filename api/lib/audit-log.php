<?php
/**
 * 監査ログ共通ライブラリ（S-2 Phase 1）
 *
 * 全操作の追跡を記録する。書き込み失敗時は業務処理を阻害しない。
 *
 * 操作種別（action）一覧:
 *   menu_update    — メニュー価格・表示変更
 *   menu_soldout   — 品切れ変更
 *   menu_create    — 限定メニュー作成
 *   menu_delete    — 限定メニュー削除
 *   staff_create   — スタッフ作成
 *   staff_update   — スタッフ情報変更
 *   staff_delete   — スタッフ削除
 *   order_cancel   — 注文キャンセル
 *   order_status   — 注文ステータス変更
 *   item_status    — 品目ステータス変更
 *   settings_update — 店舗設定変更
 *   login          — ログイン
 *   logout         — ログアウト
 *
 * 対象種別（entity_type）一覧:
 *   menu_item, user, order, order_item, settings, ingredient, store
 */

/**
 * 監査ログを書き込む
 *
 * @param PDO         $pdo        DB接続
 * @param array       $user       ユーザー情報（user_id, tenant_id, username, role）
 * @param string|null $storeId    店舗ID（テナントレベル操作時はNULL）
 * @param string      $action     操作種別
 * @param string      $entityType 対象種別
 * @param string|null $entityId   対象レコードID
 * @param mixed       $oldValue   変更前の値（配列→JSON化、NULL許容）
 * @param mixed       $newValue   変更後の値（同上）
 * @param string|null $reason     操作理由
 * @return void
 */
function write_audit_log(
    PDO $pdo,
    array $user,
    ?string $storeId,
    string $action,
    string $entityType,
    ?string $entityId = null,
    $oldValue = null,
    $newValue = null,
    ?string $reason = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
                (tenant_id, store_id, user_id, username, role,
                 action, entity_type, entity_id,
                 old_value, new_value, reason,
                 ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );

        $stmt->execute([
            $user['tenant_id'] ?? '',
            $storeId,
            $user['user_id'] ?? '',
            $user['username'] ?? $user['displayName'] ?? null,
            $user['role'] ?? null,
            $action,
            $entityType,
            $entityId,
            $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Exception $e) {
        // 監査ログの書き込み失敗は業務処理を阻害しない
        error_log('audit_log write failed: ' . $e->getMessage());
    }
}
