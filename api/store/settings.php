<?php
/**
 * 店舗設定 API
 *
 * GET   /api/store/settings.php?store_id=xxx
 * PATCH /api/store/settings.php
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';
require_once __DIR__ . '/../lib/posla-settings.php';

require_method(['GET', 'PATCH']);
// GETはstaff以上（KDS音声コマンドでAPIキー取得に必要）、PATCHはmanager以上
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $user = require_role('manager');
} else {
    $user = require_auth();
}
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $stmt = $pdo->prepare('SELECT * FROM store_settings WHERE store_id = ?');
    $stmt->execute([$storeId]);
    $settings = $stmt->fetch();

    if (!$settings) {
        $pdo->prepare('INSERT INTO store_settings (store_id) VALUES (?)')->execute([$storeId]);
        $stmt->execute([$storeId]);
        $settings = $stmt->fetch();
    }

    // P1b-2: include_ai_key=1 の旧呼び出しブランチを廃止（codex #1）
    // フロントエンド (voice-commander/shift-calendar/sold-out) は P1-6b/c で
    // ai-generate.php プロキシ経由に完全移行済み。本ブランチは APIキーを返す可能性が
    // あったため攻撃面を残していた。posla-admin 側は posla_settings テーブル経由の
    // 別経路なので影響なし。

    // POSLA共通でAIキーが設定済みかフラグを付与
    $aiConfigured = get_posla_setting($pdo, 'gemini_api_key') !== null;
    $settings['ai_api_key_set'] = $aiConfigured;
    $settings['ai_configured'] = $aiConfigured;

    // 通常レスポンスではAPIキー本体を含めない
    unset($settings['ai_api_key']);

    json_response(['settings' => $settings]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    try {
        $data = get_json_body();
        $storeId = $data['store_id'] ?? null;
        if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
        require_store_access($storeId);

        $allowed = [
            'day_cutoff_time', 'default_open_amount', 'overshort_threshold',
            'payment_methods_enabled', 'receipt_store_name', 'receipt_address',
            'receipt_phone', 'tax_rate', 'receipt_footer',
            'max_items_per_order', 'max_amount_per_order',
            'welcome_message', 'welcome_message_en',
            'last_order_time', 'google_place_id',
            'takeout_enabled', 'takeout_min_prep_minutes', 'takeout_available_from',
            'takeout_available_to', 'takeout_slot_capacity', 'takeout_online_payment',
            'brand_color', 'brand_logo_url', 'brand_display_name',
            'self_checkout_enabled' // P1-10b: セルフレジ有効フラグ
        ];

        $fields = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = ?";
                $params[] = $data[$col];
            }
        }

        // ブランドカラーバリデーション
        if (isset($data['brand_color']) && $data['brand_color'] !== '' && $data['brand_color'] !== null) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $data['brand_color'])) {
                json_error('INVALID_COLOR', 'カラーコードは #RRGGBB 形式で入力してください', 400);
            }
        }

        if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

        // 監査ログ用: 変更前の値を取得
        $oldStmt = $pdo->prepare('SELECT * FROM store_settings WHERE store_id = ?');
        $oldStmt->execute([$storeId]);
        $oldSettings = $oldStmt->fetch();

        $params[] = $storeId;
        $pdo->prepare('UPDATE store_settings SET ' . implode(', ', $fields) . ' WHERE store_id = ?')->execute($params);

        // 監査ログ
        $auditOld = [];
        $auditNew = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $auditOld[$col] = $oldSettings[$col] ?? null;
                $auditNew[$col] = $data[$col];
            }
        }
        write_audit_log($pdo, $user, $storeId, 'settings_update', 'store_settings', $storeId, $auditOld, $auditNew, null);

        json_response(['ok' => true]);
    } catch (Exception $e) {
        // P1b-5: 例外メッセージ (DB カラム名等) を攻撃者に露出させない。詳細は error_log に。
        error_log('[settings.php PATCH] ' . $e->getMessage());
        json_error('SERVER_ERROR', '設定の更新に失敗しました', 500);
    }
}
