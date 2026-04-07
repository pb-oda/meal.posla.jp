<?php
/**
 * order_items テーブルへの書き込みヘルパー
 *
 * orders.items JSON と order_items テーブルの二重書き込みを行う。
 * order_items テーブルが存在しない場合はサイレントにスキップする（移行期間対応）。
 */

/**
 * 注文の品目を order_items テーブルに書き込む
 *
 * @param PDO    $pdo
 * @param string $orderId  orders.id
 * @param string $storeId  store_id
 * @param array  $items    品目配列 [{id, name, price, qty, options?}]
 */
function insert_order_items(PDO $pdo, string $orderId, string $storeId, array $items): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO order_items (id, order_id, store_id, menu_item_id, name, price, qty, options, allergen_selections, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", NOW(), NOW())'
        );
        foreach ($items as $item) {
            $itemId = bin2hex(random_bytes(16));
            $menuItemId = $item['id'] ?? null;
            $name = $item['name'] ?? '';
            $price = (int)($item['price'] ?? 0);
            $qty = (int)($item['qty'] ?? 1);
            $options = !empty($item['options']) ? json_encode($item['options'], JSON_UNESCAPED_UNICODE) : null;
            $allergens = !empty($item['allergen_selections']) ? json_encode($item['allergen_selections'], JSON_UNESCAPED_UNICODE) : null;
            $stmt->execute([$itemId, $orderId, $storeId, $menuItemId, $name, $price, $qty, $options, $allergens]);
        }
    } catch (PDOException $e) {
        // order_items テーブルが存在しない場合はスキップ（移行期間中）
        if (strpos($e->getMessage(), 'order_items') !== false) {
            return;
        }
        throw $e;
    }
}
