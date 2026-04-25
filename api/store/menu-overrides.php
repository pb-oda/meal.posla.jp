<?php
/**
 * 店舗メニューオーバーライド API
 *
 * GET   /api/store/menu-overrides.php?store_id=xxx     — テンプレート+オーバーライド一覧
 * PATCH /api/store/menu-overrides.php                  — オーバーライド更新（UPSERT）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['GET', 'PATCH']);
$user = require_role('manager');
$pdo = get_db();

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    // store -> tenant_id
    $stmt = $pdo->prepare('SELECT tenant_id FROM stores WHERE id = ?');
    $stmt->execute([$storeId]);
    $tenantId = $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT mt.id AS template_id, mt.name, mt.name_en, mt.base_price, mt.is_sold_out AS template_sold_out,
                mt.image_url, mt.category_id, c.name AS category_name,
                smo.id AS override_id, smo.price, smo.is_hidden, smo.is_sold_out AS override_sold_out,
                smo.image_url AS override_image_url
         FROM menu_templates mt
         JOIN categories c ON c.id = mt.category_id
         LEFT JOIN store_menu_overrides smo ON smo.template_id = mt.id AND smo.store_id = ?
         WHERE mt.tenant_id = ?
         ORDER BY c.sort_order, mt.sort_order, mt.name'
    );
    $stmt->execute([$storeId, $tenantId]);

    json_response(['items' => $stmt->fetchAll()]);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $templateId = $data['template_id'] ?? null;

    if (!$storeId || !$templateId) json_error('MISSING_FIELDS', 'store_idとtemplate_idは必須です', 400);
    require_store_access($storeId);

    // テンプレートの存在確認
    $stmt = $pdo->prepare(
        'SELECT mt.id FROM menu_templates mt
         JOIN stores s ON s.tenant_id = mt.tenant_id
         WHERE mt.id = ? AND s.id = ?'
    );
    $stmt->execute([$templateId, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューが見つかりません', 404);

    // 既存オーバーライド確認（監査ログ用に変更前の値も取得）
    $stmt = $pdo->prepare('SELECT id, price, is_hidden, is_sold_out, image_url FROM store_menu_overrides WHERE store_id = ? AND template_id = ?');
    $stmt->execute([$storeId, $templateId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // UPDATE
        $fields = [];
        $params = [];
        if (array_key_exists('price', $data)) {
            $fields[] = 'price = ?';
            $params[] = $data['price'] !== null ? (int)$data['price'] : null;
        }
        if (isset($data['is_hidden'])) {
            $fields[] = 'is_hidden = ?';
            $params[] = $data['is_hidden'] ? 1 : 0;
        }
        if (isset($data['is_sold_out'])) {
            $fields[] = 'is_sold_out = ?';
            $params[] = $data['is_sold_out'] ? 1 : 0;
        }
        if (array_key_exists('image_url', $data)) {
            $fields[] = 'image_url = ?';
            $params[] = $data['image_url'] !== null ? trim($data['image_url']) : null;
        }
        if (!empty($fields)) {
            $params[] = $existing['id'];
            $pdo->prepare('UPDATE store_menu_overrides SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
    } else {
        // INSERT
        $id = generate_uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO store_menu_overrides (id, store_id, template_id, price, is_hidden, is_sold_out, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $storeId, $templateId,
            array_key_exists('price', $data) && $data['price'] !== null ? (int)$data['price'] : null,
            !empty($data['is_hidden']) ? 1 : 0,
            !empty($data['is_sold_out']) ? 1 : 0,
            isset($data['image_url']) ? trim($data['image_url']) : null
        ]);
    }

    // N-7: calories/allergens は menu_templates を直接更新
    // P1-29: enterprise (hq_menu_broadcast=true) では本部マスタを店舗側から書き換え不可
    $tenantStmt = $pdo->prepare('SELECT tenant_id FROM stores WHERE id = ?');
    $tenantStmt->execute([$storeId]);
    $tenantIdForCheck = $tenantStmt->fetchColumn();
    $isEnterpriseHq = $tenantIdForCheck && check_plan_feature($pdo, $tenantIdForCheck, 'hq_menu_broadcast');

    if (!$isEnterpriseHq && (array_key_exists('calories', $data) || array_key_exists('allergens', $data))) {
        try {
            $tplFields = [];
            $tplParams = [];
            if (array_key_exists('calories', $data)) {
                $tplFields[] = 'calories = ?';
                $tplParams[] = $data['calories'] !== null && $data['calories'] !== '' ? (int)$data['calories'] : null;
            }
            if (array_key_exists('allergens', $data)) {
                $tplFields[] = 'allergens = ?';
                $tplParams[] = is_array($data['allergens']) ? json_encode($data['allergens'], JSON_UNESCAPED_UNICODE) : null;
            }
            if (!empty($tplFields)) {
                $tplFields[] = 'updated_at = NOW()';
                $tplParams[] = $templateId;
                $pdo->prepare('UPDATE menu_templates SET ' . implode(', ', $tplFields) . ' WHERE id = ?')->execute($tplParams);
            }
        } catch (PDOException $e) {
            // カラム未作成時はスキップ
            error_log('[P1-12][api/store/menu-overrides.php:130] non_hq_master_update: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        }
    }

    // 監査ログ
    $auditAction = isset($data['is_sold_out']) ? 'menu_soldout' : 'menu_update';
    $auditNew = array_intersect_key($data, array_flip(['price', 'is_hidden', 'is_sold_out', 'image_url']));
    $auditOld = $existing ? ['price' => $existing['price'], 'is_hidden' => $existing['is_hidden'], 'is_sold_out' => $existing['is_sold_out']] : null;
    write_audit_log($pdo, $user, $storeId, $auditAction, 'menu_item', $templateId, $auditOld, $auditNew, null);

    json_response(['ok' => true]);
}
