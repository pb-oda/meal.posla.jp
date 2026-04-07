<?php
/**
 * POSLA共通設定 API
 *
 * GET   /api/posla/settings.php  — 現在の設定を取得（キーはマスク）
 * PATCH /api/posla/settings.php  — 設定を更新
 */

require_once __DIR__ . '/auth-helper.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH']);
$pdo = get_db();

/**
 * APIキーをマスクする（先頭4文字 + ●×8）
 */
function mask_api_key($key) {
    if (!$key || mb_strlen($key) < 4) return null;
    return substr($key, 0, 4) . str_repeat('●', min(8, strlen($key) - 4));
}

// ============================================================
// GET — 設定取得
// ============================================================
if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM posla_settings');
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $settings = [];
    foreach ($rows as $row) {
        $key = $row['setting_key'];
        $val = $row['setting_value'];
        $settings[$key . '_set'] = !empty($val);
        $settings[$key . '_masked'] = mask_api_key($val);
        $settings[$key . '_value'] = $val;
    }

    json_response(['settings' => $settings]);
}

// ============================================================
// PATCH — 設定更新
// ============================================================
if ($method === 'PATCH') {
    $input = get_json_body();
    $allowedKeys = ['gemini_api_key', 'google_places_api_key', 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret', 'stripe_price_standard', 'stripe_price_pro', 'stripe_price_enterprise', 'connect_application_fee_percent', 'smaregi_client_id', 'smaregi_client_secret'];
    $updated = 0;

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $input)) continue;

        $val = $input[$key];
        if ($val === null || $val === '') {
            $stmt = $pdo->prepare(
                'UPDATE posla_settings SET setting_value = NULL WHERE setting_key = ?'
            );
            $stmt->execute([$key]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE posla_settings SET setting_value = ? WHERE setting_key = ?'
            );
            $stmt->execute([$val, $key]);
        }
        $updated++;
    }

    if ($updated === 0) {
        json_error('NO_CHANGES', '更新項目がありません', 400);
    }

    json_response(['message' => '設定を更新しました']);
}
