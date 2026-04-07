-- ============================================================
-- Seed A-3: KDSステーション & ルーティング テストデータ
-- ============================================================
-- 前提: migration-a3-kds-stations.sql 実行済み
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- 松の屋 渋谷店: 2ステーション
-- ============================================================
INSERT INTO kds_stations (id, store_id, name, name_en, sort_order) VALUES
('ks-shib-kitchen', 's-shibuya-001', '厨房', 'Kitchen', 1),
('ks-shib-drink',   's-shibuya-001', 'ドリンク', 'Drinks', 2);

-- 厨房 → 定食・丼もの・カレー・サイドメニュー
INSERT INTO kds_routing_rules (station_id, category_id) VALUES
('ks-shib-kitchen', 'c-teishoku-001'),
('ks-shib-kitchen', 'c-donburi-001'),
('ks-shib-kitchen', 'c-curry-001'),
('ks-shib-kitchen', 'c-side-001');

-- ドリンク → ドリンク
INSERT INTO kds_routing_rules (station_id, category_id) VALUES
('ks-shib-drink', 'c-drink-001');

-- ============================================================
-- 松の屋 新宿店: 1ステーション（全品目）
-- ============================================================
INSERT INTO kds_stations (id, store_id, name, name_en, sort_order) VALUES
('ks-shin-all', 's-shinjuku-001', '全品目', 'All Items', 1);

-- 全カテゴリを割当
INSERT INTO kds_routing_rules (station_id, category_id) VALUES
('ks-shin-all', 'c-teishoku-001'),
('ks-shin-all', 'c-donburi-001'),
('ks-shin-all', 'c-curry-001'),
('ks-shin-all', 'c-side-001'),
('ks-shin-all', 'c-drink-001');

-- ============================================================
-- 桃の屋 池袋店: 3ステーション
-- ============================================================
INSERT INTO kds_stations (id, store_id, name, name_en, sort_order) VALUES
('ks-ike-kitchen', 's-ikebukuro-001', '厨房',     'Kitchen',  1),
('ks-ike-pizza',   's-ikebukuro-001', 'ピザ窯',   'Pizza',    2),
('ks-ike-bar',     's-ikebukuro-001', 'バー',     'Bar',      3);

-- 厨房 → パスタ・サラダ
INSERT INTO kds_routing_rules (station_id, category_id) VALUES
('ks-ike-kitchen', 'c-momo-pasta-001'),
('ks-ike-kitchen', 'c-momo-salad-001');

-- ピザ窯 → ピザ
INSERT INTO kds_routing_rules (station_id, category_id) VALUES
('ks-ike-pizza', 'c-momo-pizza-001');

-- バー → デザート・ドリンク
INSERT INTO kds_routing_rules (station_id, category_id) VALUES
('ks-ike-bar', 'c-momo-dessert-001'),
('ks-ike-bar', 'c-momo-drink-001');
