-- migration-i3-satisfaction-ratings.sql
-- N-4: 満足度評価テーブル
-- 品目提供後にお客様が5段階評価を送信。低評価はKDS/ハンディにアラート表示。

CREATE TABLE IF NOT EXISTS satisfaction_ratings (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  store_id VARCHAR(36) NOT NULL,
  order_id VARCHAR(36) NOT NULL,
  order_item_id VARCHAR(36) DEFAULT NULL,
  menu_item_id VARCHAR(36) DEFAULT NULL,
  item_name VARCHAR(200) DEFAULT NULL,
  rating TINYINT NOT NULL COMMENT '1-5 (1=最低 5=最高)',
  session_token VARCHAR(64) DEFAULT NULL,
  table_id VARCHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_created (store_id, created_at),
  INDEX idx_order (order_id),
  INDEX idx_menu_item (menu_item_id, store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
