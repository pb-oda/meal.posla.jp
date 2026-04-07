<?php
/**
 * POSLA管理者 テナントCRUD API
 *
 * GET   /api/posla/tenants.php          — 全テナント一覧
 * GET   /api/posla/tenants.php?id=xxx   — テナント詳細
 * PATCH /api/posla/tenants.php          — テナント更新
 * POST  /api/posla/tenants.php          — テナント新規作成
 */

require_once __DIR__ . '/auth-helper.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH', 'POST']);
$pdo = get_db();

// ============================================================
// GET — 一覧 / 詳細
// ============================================================
if ($method === 'GET') {
    $id = $_GET['id'] ?? '';

    if ($id !== '') {
        // 1件詳細
        $stmt = $pdo->prepare(
            'SELECT t.id, t.slug, t.name, t.name_en, t.plan, t.is_active,
                    t.subscription_status, t.current_period_end,
                    t.stripe_connect_account_id, t.connect_onboarding_complete,
                    t.created_at, t.updated_at,
                    (SELECT COUNT(*) FROM stores s WHERE s.tenant_id = t.id) AS store_count,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count
             FROM tenants t
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            json_error('NOT_FOUND', 'テナントが見つかりません', 404);
        }

        json_response(['tenant' => $tenant]);
    }

    // 全件一覧
    $stmt = $pdo->prepare(
        'SELECT t.id, t.slug, t.name, t.name_en, t.plan, t.is_active,
                t.subscription_status, t.current_period_end,
                t.stripe_connect_account_id, t.connect_onboarding_complete,
                t.created_at, t.updated_at,
                (SELECT COUNT(*) FROM stores s WHERE s.tenant_id = t.id) AS store_count,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count
         FROM tenants t
         ORDER BY t.created_at DESC'
    );
    $stmt->execute();
    $tenants = $stmt->fetchAll();

    json_response(['tenants' => $tenants]);
}

// ============================================================
// PATCH — 更新
// ============================================================
if ($method === 'PATCH') {
    $input = get_json_body();
    $id = $input['id'] ?? '';

    if (empty($id)) {
        json_error('MISSING_ID', 'テナントIDは必須です', 400);
    }

    // 対象テナント存在確認
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        json_error('NOT_FOUND', 'テナントが見つかりません', 404);
    }

    $sets = [];
    $params = [];

    // plan
    if (isset($input['plan'])) {
        $plan = $input['plan'];
        if (!in_array($plan, ['standard', 'pro', 'enterprise'], true)) {
            json_error('INVALID_PLAN', 'プランが不正です', 400);
        }
        $sets[] = 'plan = ?';
        $params[] = $plan;
    }

    // is_active
    if (isset($input['is_active'])) {
        $sets[] = 'is_active = ?';
        $params[] = $input['is_active'] ? 1 : 0;
    }

    // name
    if (isset($input['name']) && trim($input['name']) !== '') {
        $sets[] = 'name = ?';
        $params[] = trim($input['name']);
    }

    if (empty($sets)) {
        json_error('NO_CHANGES', '更新項目がありません', 400);
    }

    $params[] = $id;
    $sql = 'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);

    json_response(['message' => 'テナントを更新しました']);
}

// ============================================================
// POST — 新規作成
// ============================================================
if ($method === 'POST') {
    $input = get_json_body();
    $slug = trim($input['slug'] ?? '');
    $name = trim($input['name'] ?? '');
    $plan = $input['plan'] ?? 'standard';

    if (empty($slug)) {
        json_error('MISSING_SLUG', 'slugは必須です', 400);
    }
    if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
        json_error('INVALID_SLUG', 'slugは半角英小文字・数字・ハイフンのみ使用できます', 400);
    }
    if (empty($name)) {
        json_error('MISSING_NAME', 'テナント名は必須です', 400);
    }
    if (!in_array($plan, ['standard', 'pro', 'enterprise'], true)) {
        json_error('INVALID_PLAN', 'プランが不正です', 400);
    }

    // slug重複チェック
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        json_error('DUPLICATE_SLUG', 'このslugは既に使用されています', 409);
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO tenants (id, slug, name, plan) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$id, $slug, $name, $plan]);

    json_response([
        'tenant' => [
            'id'   => $id,
            'slug' => $slug,
            'name' => $name,
            'plan' => $plan,
        ]
    ], 201);
}
