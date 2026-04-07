-- migration-f2-order-items.sql
-- 品目単位ステータス管理テーブル（C-4: KDS品目単位ステータス管理）
-- 依存: orders テーブルが存在すること

CREATE TABLE order_items (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  order_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  menu_item_id VARCHAR(36) DEFAULT NULL COMMENT 'menu_itemsのID',
  name VARCHAR(100) NOT NULL COMMENT '品目名（注文時点のスナップショット）',
  price INT NOT NULL DEFAULT 0 COMMENT '単価（注文時点）',
  qty INT NOT NULL DEFAULT 1,
  options JSON DEFAULT NULL COMMENT 'オプション情報JSON',
  status ENUM('pending','preparing','ready','served','cancelled') NOT NULL DEFAULT 'pending',
  prepared_at DATETIME DEFAULT NULL,
  ready_at DATETIME DEFAULT NULL,
  served_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_oi_order (order_id),
  INDEX idx_oi_store_status (store_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
