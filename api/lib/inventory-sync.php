<?php
/**
 * 在庫→品切れ自動連動ヘルパー（S-5）
 *
 * レシピ連動在庫が閾値以下になったメニューを自動で品切れに設定する。
 * 棚卸し等で在庫が回復した場合は品切れを自動解除する（autoClear=true時）。
 *
 * 依存: ingredients, recipes, store_menu_overrides テーブル
 */

/**
 * 在庫状況に基づいて品切れフラグを自動同期
 *
 * @param PDO         $pdo
 * @param string      $tenantId   テナントID
 * @param string|null $storeId    店舗ID（null=テナント全店舗）
 * @param bool        $autoClear  在庫回復時に品切れ解除するか
 */
function sync_sold_out_from_inventory(PDO $pdo, string $tenantId, ?string $storeId = null, bool $autoClear = false): void
{
    try {
        // レシピ紐付きメニューで、在庫切れ原材料を持つものを取得
        $stmt = $pdo->prepare(
            'SELECT DISTINCT r.menu_template_id
             FROM recipes r
             JOIN ingredients i ON i.id = r.ingredient_id
             WHERE i.tenant_id = ?
               AND i.is_active = 1
               AND i.stock_quantity <= COALESCE(i.low_stock_threshold, 0)'
        );
        $stmt->execute([$tenantId]);
        $depletedMenuIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 対象店舗取得
        if ($storeId) {
            $storeIds = [$storeId];
        } else {
            $stmt = $pdo->prepare('SELECT id FROM stores WHERE tenant_id = ? AND is_active = 1');
            $stmt->execute([$tenantId]);
            $storeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (empty($storeIds)) return;

        // 品切れ設定: 在庫切れメニュー → is_sold_out = 1
        if (!empty($depletedMenuIds)) {
            $checkStmt = $pdo->prepare(
                'SELECT id FROM store_menu_overrides WHERE store_id = ? AND template_id = ?'
            );
            $updateStmt = $pdo->prepare(
                'UPDATE store_menu_overrides SET is_sold_out = 1 WHERE id = ?'
            );
            $insertStmt = $pdo->prepare(
                'INSERT INTO store_menu_overrides (id, store_id, template_id, is_sold_out) VALUES (?, ?, ?, 1)'
            );

            foreach ($storeIds as $sid) {
                foreach ($depletedMenuIds as $menuId) {
                    $checkStmt->execute([$sid, $menuId]);
                    $existing = $checkStmt->fetch();
                    if ($existing) {
                        $updateStmt->execute([$existing['id']]);
                    } else {
                        $insertStmt->execute([generate_uuid(), $sid, $menuId]);
                    }
                }
            }
        }

        // 品切れ解除: 全原材料が在庫ありのメニュー → is_sold_out = 0（棚卸し時のみ）
        if ($autoClear) {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT r.menu_template_id
                 FROM recipes r
                 JOIN ingredients i ON i.id = r.ingredient_id
                 WHERE i.tenant_id = ?
                   AND i.is_active = 1
                   AND r.menu_template_id NOT IN (
                       SELECT DISTINCT r2.menu_template_id
                       FROM recipes r2
                       JOIN ingredients i2 ON i2.id = r2.ingredient_id
                       WHERE i2.tenant_id = ?
                         AND i2.is_active = 1
                         AND i2.stock_quantity <= COALESCE(i2.low_stock_threshold, 0)
                   )'
            );
            $stmt->execute([$tenantId, $tenantId]);
            $inStockMenuIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($inStockMenuIds)) {
                $clearStmt = $pdo->prepare(
                    'UPDATE store_menu_overrides SET is_sold_out = 0
                     WHERE store_id = ? AND template_id = ? AND is_sold_out = 1'
                );
                foreach ($storeIds as $sid) {
                    foreach ($inStockMenuIds as $menuId) {
                        $clearStmt->execute([$sid, $menuId]);
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // recipes/ingredients/store_menu_overrides テーブル未作成時はスキップ
        if (strpos($e->getMessage(), 'recipes') === false
            && strpos($e->getMessage(), 'ingredients') === false
            && strpos($e->getMessage(), 'store_menu_overrides') === false) {
            throw $e;
        }
    }
}
