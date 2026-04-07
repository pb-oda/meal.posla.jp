<?php
/**
 * テナント情報 API（オーナー専用）
 *
 * GET  /api/owner/tenants.php       — テナント情報取得
 * PATCH /api/owner/tenants.php      — テナント情報更新（nullで削除可能）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'PATCH']);
$user = require_role('owner');
$pdo = get_db();

/**
 * APIキーをマスクする（先頭4文字 + ●●●●●●●●）
 */
function mask_api_key($key) {
    if (!$key || mb_strlen($key) < 4) return null;
    return substr($key, 0, 4) . str_repeat('●', min(8, strlen($key) - 4));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 決済カラム存在チェック（マイグレーション未適用対応）
    $hasPaymentGw = false;
    try {
        $pdo->query('SELECT stripe_secret_key FROM tenants LIMIT 0');
        $hasPaymentGw = true;
    } catch (PDOException $e) {}

    $selectCols = 'id, slug, name, name_en, ai_api_key, google_places_api_key, created_at';
    if ($hasPaymentGw) {
        $selectCols .= ', stripe_secret_key, payment_gateway';
    }

    $stmt = $pdo->prepare('SELECT ' . $selectCols . ' FROM tenants WHERE id = ?');
    $stmt->execute([$user['tenant_id']]);
    $tenant = $stmt->fetch();
    if (!$tenant) json_error('NOT_FOUND', 'テナントが見つかりません', 404);

    // マスク付きキーと設定済みフラグを返す
    $tenant['ai_api_key_set'] = !empty($tenant['ai_api_key']);
    $tenant['ai_api_key_masked'] = mask_api_key($tenant['ai_api_key']);
    $tenant['google_places_api_key_set'] = !empty($tenant['google_places_api_key']);
    $tenant['google_places_api_key_masked'] = mask_api_key($tenant['google_places_api_key']);

    // 決済キー
    if ($hasPaymentGw) {
        $tenant['stripe_secret_key_set'] = !empty($tenant['stripe_secret_key']);
        $tenant['stripe_secret_key_masked'] = mask_api_key($tenant['stripe_secret_key']);
        unset($tenant['stripe_secret_key']);
    } else {
        $tenant['payment_gateway'] = 'none';
        $tenant['stripe_secret_key_set'] = false;
        $tenant['stripe_secret_key_masked'] = null;
    }

    // フルキーは返さない
    unset($tenant['ai_api_key']);
    unset($tenant['google_places_api_key']);

    json_response(['tenant' => $tenant]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = get_json_body();
    $fields = [];
    $params = [];

    if (isset($data['name'])) {
        $fields[] = 'name = ?';
        $params[] = trim($data['name']);
    }
    if (isset($data['name_en'])) {
        $fields[] = 'name_en = ?';
        $params[] = trim($data['name_en']);
    }

    // APIキー: 値があれば更新、nullなら削除
    if (array_key_exists('ai_api_key', $data)) {
        $fields[] = 'ai_api_key = ?';
        $val = $data['ai_api_key'];
        $params[] = ($val === null || $val === '') ? null : trim($val);
    }
    if (array_key_exists('google_places_api_key', $data)) {
        $fields[] = 'google_places_api_key = ?';
        $val = $data['google_places_api_key'];
        $params[] = ($val === null || $val === '') ? null : trim($val);
    }

    // 決済ゲートウェイ設定（カラム存在チェック付き、P1-2 で Square 削除済み）
    $hasPaymentGw = false;
    try {
        $pdo->query('SELECT stripe_secret_key FROM tenants LIMIT 0');
        $hasPaymentGw = true;
    } catch (PDOException $e) {}

    if ($hasPaymentGw) {
        if (array_key_exists('stripe_secret_key', $data)) {
            $fields[] = 'stripe_secret_key = ?';
            $val = $data['stripe_secret_key'];
            $params[] = ($val === null || $val === '') ? null : trim($val);
        }
        if (array_key_exists('payment_gateway', $data)) {
            $val = $data['payment_gateway'];
            if (in_array($val, ['none', 'stripe'], true)) {
                $fields[] = 'payment_gateway = ?';
                $params[] = $val;
            }
        }
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

    $params[] = $user['tenant_id'];
    $pdo->prepare('UPDATE tenants SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}
