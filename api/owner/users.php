<?php
/**
 * ユーザー管理 API（オーナー・マネージャー共用）
 *
 * GET    /api/owner/users.php            — 一覧
 * POST   /api/owner/users.php            — 新規作成
 * PATCH  /api/owner/users.php?id=xxx     — 更新
 * DELETE /api/owner/users.php?id=xxx     — 削除
 *
 * owner: テナント全ユーザーのCRUD
 * manager: 自店舗staffのみCRUD（ロール変更不可）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/password-policy.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];
$isOwner = ($user['role'] === 'owner');

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($isOwner) {
        // owner: テナント全ユーザー
        $stmt = $pdo->prepare(
            'SELECT u.id, u.username, u.email, u.display_name, u.role, u.is_active, u.created_at,
                    GROUP_CONCAT(us.store_id ORDER BY us.store_id) AS store_ids,
                    GROUP_CONCAT(s.name ORDER BY us.store_id) AS store_names,
                    GROUP_CONCAT(IFNULL(us.visible_tools, \'\') ORDER BY us.store_id SEPARATOR \'|\') AS visible_tools_pipe,
                    GROUP_CONCAT(IFNULL(us.hourly_rate, \'\') ORDER BY us.store_id SEPARATOR \'|\') AS hourly_rate_pipe
             FROM users u
             LEFT JOIN user_stores us ON us.user_id = u.id
             LEFT JOIN stores s ON s.id = us.store_id
             WHERE u.tenant_id = ?
             GROUP BY u.id
             ORDER BY u.role, u.display_name'
        );
        $stmt->execute([$tenantId]);
    } else {
        // manager: 自分自身 + 自分の担当店舗に紐づくstaffのみ
        $myStoreIds = $user['store_ids'];
        if (empty($myStoreIds)) {
            json_response(['users' => []]);
        }
        $placeholders = implode(',', array_fill(0, count($myStoreIds), '?'));
        $params = array_merge([$tenantId, $user['user_id'], $tenantId], $myStoreIds);
        $stmt = $pdo->prepare(
            'SELECT u.id, u.username, u.email, u.display_name, u.role, u.is_active, u.created_at,
                    GROUP_CONCAT(us.store_id ORDER BY us.store_id) AS store_ids,
                    GROUP_CONCAT(s.name ORDER BY us.store_id) AS store_names,
                    GROUP_CONCAT(IFNULL(us.visible_tools, \'\') ORDER BY us.store_id SEPARATOR \'|\') AS visible_tools_pipe,
                    GROUP_CONCAT(IFNULL(us.hourly_rate, \'\') ORDER BY us.store_id SEPARATOR \'|\') AS hourly_rate_pipe
             FROM users u
             LEFT JOIN user_stores us ON us.user_id = u.id
             LEFT JOIN stores s ON s.id = us.store_id
             WHERE (u.tenant_id = ? AND u.id = ?)
                OR (u.tenant_id = ? AND u.role = \'staff\' AND us.store_id IN (' . $placeholders . '))
             GROUP BY u.id
             ORDER BY u.role, u.display_name'
        );
        $stmt->execute($params);
    }
    $users = $stmt->fetchAll();

    // store_ids / store_names / visible_tools_by_store を配列・マップに変換
    foreach ($users as &$u) {
        $ids = $u['store_ids'] ? explode(',', $u['store_ids']) : [];
        $vtParts = $u['visible_tools_pipe'] ? explode('|', $u['visible_tools_pipe']) : [];
        $hrParts = $u['hourly_rate_pipe'] ? explode('|', $u['hourly_rate_pipe']) : [];
        $u['store_ids'] = $ids;
        $u['store_names'] = $u['store_names'] ? explode(',', $u['store_names']) : [];
        // visible_tools_by_store: { store_id: "handy,kds" or null }
        $vtMap = [];
        // L-3 Phase 2: hourly_rate_by_store: { store_id: int or null }
        $hrMap = [];
        for ($i = 0; $i < count($ids); $i++) {
            $vtMap[$ids[$i]] = (isset($vtParts[$i]) && $vtParts[$i] !== '') ? $vtParts[$i] : null;
            $hrMap[$ids[$i]] = (isset($hrParts[$i]) && $hrParts[$i] !== '') ? (int)$hrParts[$i] : null;
        }
        $u['visible_tools_by_store'] = $vtMap;
        $u['hourly_rate_by_store'] = $hrMap;
        unset($u['visible_tools_pipe'], $u['hourly_rate_pipe']);
    }
    unset($u);

    json_response(['users' => $users]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $username = trim($data['username'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $displayName = trim($data['display_name'] ?? '');
    $role = $data['role'] ?? 'staff';
    $storeIds = $data['store_ids'] ?? [];

    // username必須バリデーション
    if (!$username || !$password) json_error('MISSING_FIELDS', 'ユーザー名とパスワードは必須です', 400);
    if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) json_error('INVALID_USERNAME', 'ユーザー名は半角英数字・ハイフン・アンダースコア（3〜50文字）で入力してください', 400);
    validate_password_strength($password);
    if (!in_array($role, ['owner', 'manager', 'staff'])) json_error('INVALID_ROLE', '無効なロールです', 400);

    // email任意バリデーション
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('INVALID_EMAIL', 'メールアドレスの形式が正しくありません', 400);

    // manager権限制限: staffのみ作成可能
    if (!$isOwner && $role !== 'staff') {
        json_error('FORBIDDEN', 'マネージャーはスタッフのみ作成できます', 403);
    }

    // managerが作成するスタッフは、managerの担当店舗に限定
    if (!$isOwner) {
        foreach ($storeIds as $sid) {
            if (!in_array($sid, $user['store_ids'])) {
                json_error('FORBIDDEN', 'この店舗へのアクセス権がありません', 403);
            }
        }
    }

    // username重複チェック
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetchColumn() > 0) json_error('DUPLICATE_USERNAME', 'このユーザー名は既に使用されています', 409);

    // email重複チェック（空でなければ）
    if ($email) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) json_error('DUPLICATE_EMAIL', 'このメールアドレスは既に登録されています', 409);
    }

    $id = generate_uuid();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, tenant_id, username, email, password_hash, display_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $tenantId, $username, $email ?: null, $hash, $displayName, $role]);

        // 店舗割当（ownerはuser_storesに入れない — 全店舗暗黙アクセス）
        // L-3b2: visible_tools_by_store 対応, L-3 Phase 2: hourly_rate_by_store 対応
        $vtByStore = isset($data['visible_tools_by_store']) ? $data['visible_tools_by_store'] : [];
        $hrByStore = isset($data['hourly_rate_by_store']) ? $data['hourly_rate_by_store'] : [];
        if ($role !== 'owner' && !empty($storeIds)) {
            $ins = $pdo->prepare('INSERT INTO user_stores (user_id, store_id, visible_tools, hourly_rate) VALUES (?, ?, ?, ?)');
            foreach ($storeIds as $sid) {
                // テナント内店舗か確認
                $chk = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND tenant_id = ?');
                $chk->execute([$sid, $tenantId]);
                if ($chk->fetch()) {
                    $vt = isset($vtByStore[$sid]) ? $vtByStore[$sid] : null;
                    $hr = isset($hrByStore[$sid]) && $hrByStore[$sid] !== null ? (int)$hrByStore[$sid] : null;
                    $ins->execute([$id, $sid, $vt, $hr]);
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('CREATE_FAILED', 'ユーザー作成に失敗しました', 500);
    }

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    $target = $stmt->fetch();
    if (!$target) json_error('NOT_FOUND', 'ユーザーが見つかりません', 404);

    // manager権限制限: staffのみ編集可能
    if (!$isOwner) {
        if ($target['role'] !== 'staff') json_error('FORBIDDEN', '権限が不足しています', 403);
    }

    $data = get_json_body();

    // managerはロール変更不可
    if (!$isOwner && isset($data['role']) && $data['role'] !== 'staff') {
        json_error('FORBIDDEN', 'ロールの変更はオーナーのみ可能です', 403);
    }

    $fields = [];
    $params = [];

    // username更新
    if (isset($data['username'])) {
        $newUsername = trim($data['username']);
        if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $newUsername)) json_error('INVALID_USERNAME', 'ユーザー名は半角英数字・ハイフン・アンダースコア（3〜50文字）で入力してください', 400);
        // 重複チェック（自分以外）
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id != ?');
        $stmt->execute([$newUsername, $id]);
        if ($stmt->fetchColumn() > 0) json_error('DUPLICATE_USERNAME', 'このユーザー名は既に使用されています', 409);
        $fields[] = 'username = ?';
        $params[] = $newUsername;
    }

    if (isset($data['display_name'])) {
        $fields[] = 'display_name = ?';
        $params[] = trim($data['display_name']);
    }
    if (isset($data['role']) && in_array($data['role'], ['owner', 'manager', 'staff'])) {
        $fields[] = 'role = ?';
        $params[] = $data['role'];
    }
    if (isset($data['is_active'])) {
        $fields[] = 'is_active = ?';
        $params[] = $data['is_active'] ? 1 : 0;
    }
    if (!empty($data['password'])) {
        validate_password_strength($data['password']);
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
    }

    $pdo->beginTransaction();
    try {
        if (!empty($fields)) {
            $params[] = $id;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }

        // 店舗割当更新
        if (isset($data['store_ids'])) {
            // managerは自分の担当店舗以外への割当不可
            if (!$isOwner) {
                foreach ($data['store_ids'] as $sid) {
                    if (!in_array($sid, $user['store_ids'])) {
                        json_error('FORBIDDEN', 'この店舗へのアクセス権がありません', 403);
                    }
                }
            }

            $newRole = $data['role'] ?? $target['role'];
            // L-3b2: visible_tools_by_store 対応, L-3 Phase 2: hourly_rate_by_store 対応
            $vtByStore = isset($data['visible_tools_by_store']) ? $data['visible_tools_by_store'] : [];
            $hrByStore = isset($data['hourly_rate_by_store']) ? $data['hourly_rate_by_store'] : [];
            $pdo->prepare('DELETE FROM user_stores WHERE user_id = ?')->execute([$id]);
            if ($newRole !== 'owner' && !empty($data['store_ids'])) {
                $ins = $pdo->prepare('INSERT INTO user_stores (user_id, store_id, visible_tools, hourly_rate) VALUES (?, ?, ?, ?)');
                foreach ($data['store_ids'] as $sid) {
                    $chk = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND tenant_id = ?');
                    $chk->execute([$sid, $tenantId]);
                    if ($chk->fetch()) {
                        $vt = isset($vtByStore[$sid]) ? $vtByStore[$sid] : null;
                        $hr = isset($hrByStore[$sid]) && $hrByStore[$sid] !== null ? (int)$hrByStore[$sid] : null;
                        $ins->execute([$id, $sid, $vt, $hr]);
                    }
                }
            }
        } elseif (isset($data['visible_tools_by_store']) || isset($data['hourly_rate_by_store'])) {
            // L-3b2: 店舗割当変更なし、visible_tools/hourly_rateのみ更新
            if (isset($data['visible_tools_by_store'])) {
                $vtByStore = $data['visible_tools_by_store'];
                $upd = $pdo->prepare('UPDATE user_stores SET visible_tools = ? WHERE user_id = ? AND store_id = ?');
                foreach ($vtByStore as $sid => $vt) {
                    $upd->execute([$vt, $id, $sid]);
                }
            }
            if (isset($data['hourly_rate_by_store'])) {
                $hrByStore = $data['hourly_rate_by_store'];
                $upd = $pdo->prepare('UPDATE user_stores SET hourly_rate = ? WHERE user_id = ? AND store_id = ?');
                foreach ($hrByStore as $sid => $hr) {
                    $hrVal = ($hr !== null && $hr !== '') ? (int)$hr : null;
                    $upd->execute([$hrVal, $id, $sid]);
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('UPDATE_FAILED', '更新に失敗しました', 500);
    }

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);
    if ($id === $user['user_id']) json_error('CANNOT_DELETE_SELF', '自分自身は削除できません', 400);

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    $target = $stmt->fetch();
    if (!$target) json_error('NOT_FOUND', 'ユーザーが見つかりません', 404);

    // manager権限制限: staffのみ削除可能
    if (!$isOwner) {
        if ($target['role'] !== 'staff') json_error('FORBIDDEN', '権限が不足しています', 403);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_stores WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('DELETE_FAILED', '削除に失敗しました', 500);
    }

    json_response(['ok' => true]);
}
