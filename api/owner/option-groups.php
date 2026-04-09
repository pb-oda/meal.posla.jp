<?php
/**
 * オプショングループ管理 API（オーナー専用、テナント単位）
 *
 * GET    /api/owner/option-groups.php             — 一覧（選択肢含む）
 * POST   /api/owner/option-groups.php             — グループ新規作成
 * PATCH  /api/owner/option-groups.php?id=xxx      — グループ更新
 * DELETE /api/owner/option-groups.php?id=xxx      — グループ削除
 *
 * --- 選択肢 ---
 * POST   /api/owner/option-groups.php?action=add-choice         — 選択肢追加
 * PATCH  /api/owner/option-groups.php?action=update-choice&id=x — 選択肢更新
 * DELETE /api/owner/option-groups.php?action=delete-choice&id=x — 選択肢削除
 *
 * --- メニュー紐付け ---
 * GET    /api/owner/option-groups.php?action=template-links&template_id=xxx  — テンプレート紐付け取得
 * POST   /api/owner/option-groups.php?action=sync-template-links             — テンプレート紐付け同期
 * GET    /api/owner/option-groups.php?action=local-links&local_item_id=xxx   — 限定メニュー紐付け取得
 * POST   /api/owner/option-groups.php?action=sync-local-links                — 限定メニュー紐付け同期
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];

$action = $_GET['action'] ?? null;

// ===== 選択肢操作 =====
if ($action === 'add-choice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $groupId = trim($data['group_id'] ?? '');
    $name = trim($data['name'] ?? '');
    if (!$groupId || !$name) json_error('MISSING_FIELDS', 'グループIDと名前は必須です', 400);

    // テナント所属チェック
    $stmt = $pdo->prepare('SELECT id FROM option_groups WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$groupId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'グループが見つかりません', 404);

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM option_choices WHERE group_id = ?');
    $stmt->execute([$groupId]);
    $nextOrder = (int)$stmt->fetchColumn();

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO option_choices (id, group_id, name, name_en, price_diff, is_default, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id, $groupId, $name,
        trim($data['name_en'] ?? ''),
        (int)($data['price_diff'] ?? 0),
        !empty($data['is_default']) ? 1 : 0,
        $nextOrder
    ]);

    json_response(['id' => $id], 201);
}

if ($action === 'update-choice' && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    // テナント所属チェック
    $stmt = $pdo->prepare(
        'SELECT c.id FROM option_choices c
         JOIN option_groups g ON g.id = c.group_id
         WHERE c.id = ? AND g.tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', '選択肢が見つかりません', 404);

    $data = get_json_body();
    $fields = [];
    $params = [];

    foreach (['name' => 's', 'name_en' => 's', 'price_diff' => 'i', 'is_default' => 'b', 'sort_order' => 'i'] as $col => $type) {
        if (isset($data[$col])) {
            $fields[] = "$col = ?";
            if ($type === 'i') $params[] = (int)$data[$col];
            elseif ($type === 'b') $params[] = !empty($data[$col]) ? 1 : 0;
            else $params[] = trim($data[$col]);
        }
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

    $params[] = $id;
    $pdo->prepare('UPDATE option_choices SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

if ($action === 'delete-choice' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare(
        'SELECT c.id FROM option_choices c
         JOIN option_groups g ON g.id = c.group_id
         WHERE c.id = ? AND g.tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', '選択肢が見つかりません', 404);

    $pdo->prepare('DELETE FROM option_choices WHERE id = ?')->execute([$id]);

    json_response(['ok' => true]);
}

// ===== メニュー紐付け操作 =====

// --- GET template-links ---
if ($action === 'template-links' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $templateId = $_GET['template_id'] ?? null;
    if (!$templateId) json_error('MISSING_ID', 'template_idが必要です', 400);

    // テナント所属チェック
    $stmt = $pdo->prepare('SELECT id FROM menu_templates WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$templateId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューテンプレートが見つかりません', 404);

    try {
        $stmt = $pdo->prepare(
            'SELECT group_id, is_required, sort_order
             FROM menu_template_options WHERE template_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$templateId]);
        $links = $stmt->fetchAll();
    } catch (PDOException $e) {
        $links = [];
    }

    json_response(['links' => $links]);
}

// --- POST sync-template-links ---
if ($action === 'sync-template-links' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $templateId = trim($data['template_id'] ?? '');
    $groups = $data['groups'] ?? [];
    if (!$templateId) json_error('MISSING_FIELDS', 'template_idは必須です', 400);

    // テナント所属チェック
    $stmt = $pdo->prepare('SELECT id FROM menu_templates WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$templateId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューテンプレートが見つかりません', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM menu_template_options WHERE template_id = ?')->execute([$templateId]);

        $ins = $pdo->prepare(
            'INSERT INTO menu_template_options (template_id, group_id, is_required, sort_order) VALUES (?, ?, ?, ?)'
        );
        $order = 0;
        foreach ($groups as $g) {
            $gid = trim($g['group_id'] ?? '');
            if (!$gid) continue;
            // グループがテナントに属するか検証
            $chk = $pdo->prepare('SELECT id FROM option_groups WHERE id = ? AND tenant_id = ?');
            $chk->execute([$gid, $tenantId]);
            if (!$chk->fetch()) continue;
            $ins->execute([$templateId, $gid, !empty($g['is_required']) ? 1 : 0, $order++]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        json_error('SYNC_FAILED', '紐付けの保存に失敗しました', 500);
    }

    json_response(['ok' => true]);
}

// --- GET local-links ---
if ($action === 'local-links' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $localItemId = $_GET['local_item_id'] ?? null;
    if (!$localItemId) json_error('MISSING_ID', 'local_item_idが必要です', 400);

    // 店舗→テナント所属チェック
    $stmt = $pdo->prepare(
        'SELECT li.id FROM store_local_items li
         JOIN stores s ON s.id = li.store_id
         WHERE li.id = ? AND s.tenant_id = ?'
    );
    $stmt->execute([$localItemId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', '限定メニューが見つかりません', 404);

    try {
        $stmt = $pdo->prepare(
            'SELECT group_id, is_required, sort_order
             FROM local_item_options WHERE local_item_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$localItemId]);
        $links = $stmt->fetchAll();
    } catch (PDOException $e) {
        $links = [];
    }

    json_response(['links' => $links]);
}

// --- POST sync-local-links ---
if ($action === 'sync-local-links' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $localItemId = trim($data['local_item_id'] ?? '');
    $groups = $data['groups'] ?? [];
    if (!$localItemId) json_error('MISSING_FIELDS', 'local_item_idは必須です', 400);

    // 店舗→テナント所属チェック
    $stmt = $pdo->prepare(
        'SELECT li.id FROM store_local_items li
         JOIN stores s ON s.id = li.store_id
         WHERE li.id = ? AND s.tenant_id = ?'
    );
    $stmt->execute([$localItemId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', '限定メニューが見つかりません', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM local_item_options WHERE local_item_id = ?')->execute([$localItemId]);

        $ins = $pdo->prepare(
            'INSERT INTO local_item_options (local_item_id, group_id, is_required, sort_order) VALUES (?, ?, ?, ?)'
        );
        $order = 0;
        foreach ($groups as $g) {
            $gid = trim($g['group_id'] ?? '');
            if (!$gid) continue;
            $chk = $pdo->prepare('SELECT id FROM option_groups WHERE id = ? AND tenant_id = ?');
            $chk->execute([$gid, $tenantId]);
            if (!$chk->fetch()) continue;
            $ins->execute([$localItemId, $gid, !empty($g['is_required']) ? 1 : 0, $order++]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        json_error('SYNC_FAILED', '紐付けの保存に失敗しました', 500);
    }

    json_response(['ok' => true]);
}

// ===== グループ操作 =====

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, name_en, selection_type, min_select, max_select, sort_order, is_active
             FROM option_groups WHERE tenant_id = ? ORDER BY sort_order, name'
        );
        $stmt->execute([$tenantId]);
        $groups = $stmt->fetchAll();
    } catch (PDOException $e) {
        json_error('TABLE_NOT_FOUND', 'オプション機能のデータベースが未設定です。migration-a1-options.sql を実行してください。', 500);
    }

    // 各グループの選択肢を取得
    try {
        $choiceStmt = $pdo->prepare(
            'SELECT c.id, c.group_id, c.name, c.name_en, c.price_diff, c.is_default, c.sort_order
             FROM option_choices c
             JOIN option_groups g ON g.id = c.group_id
             WHERE g.tenant_id = ?
             ORDER BY c.sort_order, c.name'
        );
        $choiceStmt->execute([$tenantId]);
        $allChoices = $choiceStmt->fetchAll();
    } catch (PDOException $e) {
        $allChoices = [];
    }

    // グループIDでマッピング
    $choiceMap = [];
    foreach ($allChoices as $c) {
        $choiceMap[$c['group_id']][] = $c;
    }

    foreach ($groups as &$g) {
        $g['choices'] = $choiceMap[$g['id']] ?? [];
    }

    // メニューテンプレートとの紐付け数
    try {
        $linkStmt = $pdo->prepare(
            'SELECT mto.group_id, COUNT(*) AS cnt
             FROM menu_template_options mto
             JOIN option_groups g ON g.id = mto.group_id
             WHERE g.tenant_id = ?
             GROUP BY mto.group_id'
        );
        $linkStmt->execute([$tenantId]);
        $linkCounts = [];
        foreach ($linkStmt->fetchAll() as $row) {
            $linkCounts[$row['group_id']] = (int)$row['cnt'];
        }
        foreach ($groups as &$g) {
            $g['linkedMenuCount'] = $linkCounts[$g['id']] ?? 0;
        }
    } catch (PDOException $e) {
        // テーブル未作成時
        error_log('[P1-12][api/owner/option-groups.php:306] count_linked_menus: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }

    json_response(['groups' => $groups]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    if (!$name) json_error('MISSING_FIELDS', 'グループ名は必須です', 400);

    $selType = ($data['selection_type'] ?? 'single') === 'multi' ? 'multi' : 'single';

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM option_groups WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $nextOrder = (int)$stmt->fetchColumn();

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO option_groups (id, tenant_id, name, name_en, selection_type, min_select, max_select, sort_order, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        $id, $tenantId, $name,
        trim($data['name_en'] ?? ''),
        $selType,
        (int)($data['min_select'] ?? ($selType === 'single' ? 1 : 0)),
        (int)($data['max_select'] ?? 1),
        $nextOrder
    ]);

    json_response(['id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM option_groups WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'グループが見つかりません', 404);

    $data = get_json_body();
    $fields = [];
    $params = [];

    foreach (['name' => 's', 'name_en' => 's', 'selection_type' => 's', 'min_select' => 'i', 'max_select' => 'i', 'sort_order' => 'i', 'is_active' => 'b'] as $col => $type) {
        if (isset($data[$col])) {
            $fields[] = "$col = ?";
            if ($type === 'i') $params[] = (int)$data[$col];
            elseif ($type === 'b') $params[] = !empty($data[$col]) ? 1 : 0;
            else $params[] = trim($data[$col]);
        }
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

    $params[] = $id;
    $pdo->prepare('UPDATE option_groups SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM option_groups WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'グループが見つかりません', 404);

    $pdo->beginTransaction();
    try {
        // 選択肢のオーバーライドも削除
        $pdo->prepare(
            'DELETE soo FROM store_option_overrides soo
             JOIN option_choices c ON c.id = soo.choice_id
             WHERE c.group_id = ?'
        )->execute([$id]);

        // 選択肢削除
        $pdo->prepare('DELETE FROM option_choices WHERE group_id = ?')->execute([$id]);

        // テンプレート紐付け削除
        $pdo->prepare('DELETE FROM menu_template_options WHERE group_id = ?')->execute([$id]);

        // ローカルメニュー紐付け削除
        $pdo->prepare('DELETE FROM local_item_options WHERE group_id = ?')->execute([$id]);

        // グループ削除
        $pdo->prepare('DELETE FROM option_groups WHERE id = ?')->execute([$id]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        json_error('DELETE_FAILED', '削除に失敗しました', 500);
    }

    json_response(['ok' => true]);
}
