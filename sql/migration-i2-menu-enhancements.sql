-- migration-i2: メニュー表示強化（N-1, N-6, N-7）
-- 実行タイミング: デプロイ前に1回実行

-- N-7: menu_templates にカロリー・アレルギー情報を追加
ALTER TABLE menu_templates ADD COLUMN calories INT DEFAULT NULL AFTER image_url;
ALTER TABLE menu_templates ADD COLUMN allergens JSON DEFAULT NULL AFTER calories;

-- N-7: store_local_items にも同じカラムを追加
ALTER TABLE store_local_items ADD COLUMN calories INT DEFAULT NULL AFTER image_url;
ALTER TABLE store_local_items ADD COLUMN allergens JSON DEFAULT NULL AFTER calories;

-- N-1: 今日のおすすめテーブル
CREATE TABLE IF NOT EXISTS daily_recommendations (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  store_id VARCHAR(36) NOT NULL,
  menu_item_id VARCHAR(36) NOT NULL,
  source ENUM('template','local') NOT NULL DEFAULT 'template',
  badge_type ENUM('recommend','popular','new','limited','today_only') NOT NULL DEFAULT 'recommend',
  comment VARCHAR(200) DEFAULT NULL,
  display_date DATE NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_by VARCHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, display_date),
  INDEX idx_menu_item (menu_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
