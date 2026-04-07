-- ============================================================
-- Seed B: コーステンプレート & 食べ放題プラン デモデータ
-- ============================================================
-- 前提: seed.sql 適用済み（テナント・店舗・カテゴリ・メニューテンプレート）
-- 前提: migration-b-phase-b.sql, migration-b2-plan-menu-items.sql 適用済み
-- 冪等: INSERT IGNORE のため再実行可能
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- 1. コーステンプレート（渋谷店）
-- ============================================================
INSERT IGNORE INTO course_templates (id, store_id, name, name_en, price, description, phase_count, sort_order, is_active) VALUES
('crs-lunch-001',  's-shibuya-001', '松の屋ランチコース',   'Matsunoya Lunch Course',  2500, '前菜・メイン・デザートの3品コース。お昼のお得なコースです。', 3, 1, 1),
('crs-dinner-001', 's-shibuya-001', '松の屋ディナーコース', 'Matsunoya Dinner Course', 4500, '前菜・焼き物・メイン・デザートの本格4品コース。', 4, 2, 1),
('crs-kids-001',   's-shibuya-001', 'お子様コース',         'Kids Course',             1500, 'お子様向けのメイン＋デザートコース。', 2, 3, 1);

-- ============================================================
-- 2. コースフェーズ
-- ============================================================

-- ----- ランチコース（3フェーズ）-----
INSERT IGNORE INTO course_phases (id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order) VALUES
('cph-lunch-1', 'crs-lunch-001', 1, '前菜',     'Appetizer', '[{"id":"mt-011","name":"サラダ","qty":1},{"id":"mt-012","name":"冷奴","qty":1}]', NULL, 1),
('cph-lunch-2', 'crs-lunch-001', 2, 'メイン',   'Main',      '[{"id":"mt-001","name":"焼き魚定食","qty":1}]', 10, 2),
('cph-lunch-3', 'crs-lunch-001', 3, 'デザート', 'Dessert',   '[{"id":"mt-013","name":"緑茶","qty":1}]', 15, 3);

-- ----- ディナーコース（4フェーズ）-----
INSERT IGNORE INTO course_phases (id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order) VALUES
('cph-dinner-1', 'crs-dinner-001', 1, '前菜',   'Appetizer', '[{"id":"mt-011","name":"サラダ","qty":1},{"id":"mt-012","name":"冷奴","qty":1},{"id":"mt-010","name":"味噌汁","qty":1}]', NULL, 1),
('cph-dinner-2', 'crs-dinner-001', 2, '焼き物', 'Grilled',   '[{"id":"mt-001","name":"焼き魚定食","qty":1}]', 10, 2),
('cph-dinner-3', 'crs-dinner-001', 3, 'メイン', 'Main',      '[{"id":"mt-002","name":"生姜焼き定食","qty":1}]', 15, 3),
('cph-dinner-4', 'crs-dinner-001', 4, 'デザート', 'Dessert', '[{"id":"mt-013","name":"緑茶","qty":1}]', 10, 4);

-- ----- お子様コース（2フェーズ）-----
INSERT IGNORE INTO course_phases (id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order) VALUES
('cph-kids-1', 'crs-kids-001', 1, 'メイン',   'Main',    '[{"id":"mt-004","name":"ハンバーグ定食","qty":1}]', NULL, 1),
('cph-kids-2', 'crs-kids-001', 2, 'デザート', 'Dessert', '[{"id":"mt-014","name":"コーラ","qty":1}]', 10, 2);

-- ============================================================
-- 3. コーステンプレート（新宿店）
-- ============================================================
INSERT IGNORE INTO course_templates (id, store_id, name, name_en, price, description, phase_count, sort_order, is_active) VALUES
('crs-lunch-002',  's-shinjuku-001', '新宿ランチコース',     'Shinjuku Lunch Course',   2200, '丼ものメインのお手軽ランチコース。', 2, 1, 1),
('crs-dinner-002', 's-shinjuku-001', '新宿スペシャルコース', 'Shinjuku Special Course', 5000, '新宿店限定の特別5品コース。', 5, 2, 1);

-- ----- 新宿ランチコース（2フェーズ）-----
INSERT IGNORE INTO course_phases (id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order) VALUES
('cph-slunch-1', 'crs-lunch-002', 1, 'メイン',   'Main',    '[{"id":"mt-005","name":"牛丼","qty":1},{"id":"mt-010","name":"味噌汁","qty":1}]', NULL, 1),
('cph-slunch-2', 'crs-lunch-002', 2, 'デザート', 'Dessert', '[{"id":"mt-013","name":"緑茶","qty":1}]', 10, 2);

-- ----- 新宿スペシャルコース（5フェーズ）-----
INSERT IGNORE INTO course_phases (id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order) VALUES
('cph-sspec-1', 'crs-dinner-002', 1, '先付',   'Amuse',     '[{"id":"mt-012","name":"冷奴","qty":1}]', NULL, 1),
('cph-sspec-2', 'crs-dinner-002', 2, '前菜',   'Appetizer', '[{"id":"mt-011","name":"サラダ","qty":1},{"id":"mt-010","name":"味噌汁","qty":1}]', 5, 2),
('cph-sspec-3', 'crs-dinner-002', 3, '焼き物', 'Grilled',   '[{"id":"mt-001","name":"焼き魚定食","qty":1}]', 10, 3),
('cph-sspec-4', 'crs-dinner-002', 4, 'メイン', 'Main',      '[{"id":"mt-003","name":"チキン南蛮定食","qty":1}]', 12, 4),
('cph-sspec-5', 'crs-dinner-002', 5, 'デザート', 'Dessert', '[{"id":"mt-013","name":"緑茶","qty":1}]', 10, 5);

-- ============================================================
-- 4. 食べ放題プラン（渋谷店）
-- ============================================================
INSERT IGNORE INTO time_limit_plans (id, store_id, name, name_en, duration_min, last_order_min, price, description, sort_order, is_active) VALUES
('plan-std-001',  's-shibuya-001', '90分食べ放題スタンダード', '90min All-You-Can-Eat Standard', 90, 15, 2980, '定食・丼もの・サイドメニューが食べ放題！ドリンクは別注文。', 1, 1),
('plan-prm-001',  's-shibuya-001', '120分食べ放題プレミアム',  '120min All-You-Can-Eat Premium', 120, 20, 3980, '全メニュー＋ドリンク飲み放題付き！', 2, 1),
('plan-lunch-001','s-shibuya-001', '60分ランチ食べ放題',       '60min Lunch Buffet',             60, 10, 1980, 'ランチタイム限定のお得な食べ放題。定食メニューが対象。', 3, 1);

-- ============================================================
-- 5. 食べ放題プラン（新宿店）
-- ============================================================
INSERT IGNORE INTO time_limit_plans (id, store_id, name, name_en, duration_min, last_order_min, price, description, sort_order, is_active) VALUES
('plan-std-002',  's-shinjuku-001', '90分食べ放題',           '90min All-You-Can-Eat',          90, 15, 2780, '人気メニューが90分間食べ放題。', 1, 1),
('plan-prm-002',  's-shinjuku-001', '120分プレミアム食べ放題', '120min Premium All-You-Can-Eat', 120, 20, 3780, '全メニュー＋ドリンク付きのプレミアムプラン。', 2, 1);

-- ============================================================
-- 6. プラン別メニュー品目（渋谷店 スタンダード）
-- ============================================================
INSERT IGNORE INTO plan_menu_items (id, plan_id, category_id, name, name_en, description, sort_order, is_active) VALUES
-- 定食
('pmi-std-001', 'plan-std-001', 'c-teishoku-001', '焼き魚定食',     'Grilled Fish Set',    NULL, 1, 1),
('pmi-std-002', 'plan-std-001', 'c-teishoku-001', '生姜焼き定食',   'Ginger Pork Set',     NULL, 2, 1),
('pmi-std-003', 'plan-std-001', 'c-teishoku-001', 'チキン南蛮定食', 'Chicken Nanban Set',  NULL, 3, 1),
('pmi-std-004', 'plan-std-001', 'c-teishoku-001', 'ハンバーグ定食', 'Hamburg Steak Set',   NULL, 4, 1),
-- 丼もの
('pmi-std-005', 'plan-std-001', 'c-donburi-001',  '牛丼',   'Beef Bowl',         NULL, 5, 1),
('pmi-std-006', 'plan-std-001', 'c-donburi-001',  '親子丼', 'Chicken & Egg Bowl', NULL, 6, 1),
('pmi-std-007', 'plan-std-001', 'c-donburi-001',  'カツ丼', 'Katsu Bowl',        NULL, 7, 1),
-- サイドメニュー
('pmi-std-008', 'plan-std-001', 'c-side-001',     '味噌汁', 'Miso Soup',   NULL, 8, 1),
('pmi-std-009', 'plan-std-001', 'c-side-001',     'サラダ', 'Salad',       NULL, 9, 1),
('pmi-std-010', 'plan-std-001', 'c-side-001',     '冷奴',   'Cold Tofu',   NULL, 10, 1);

-- ============================================================
-- 7. プラン別メニュー品目（渋谷店 プレミアム = スタンダード + カレー + ドリンク）
-- ============================================================
INSERT IGNORE INTO plan_menu_items (id, plan_id, category_id, name, name_en, description, sort_order, is_active) VALUES
-- 定食
('pmi-prm-001', 'plan-prm-001', 'c-teishoku-001', '焼き魚定食',     'Grilled Fish Set',    NULL, 1, 1),
('pmi-prm-002', 'plan-prm-001', 'c-teishoku-001', '生姜焼き定食',   'Ginger Pork Set',     NULL, 2, 1),
('pmi-prm-003', 'plan-prm-001', 'c-teishoku-001', 'チキン南蛮定食', 'Chicken Nanban Set',  NULL, 3, 1),
('pmi-prm-004', 'plan-prm-001', 'c-teishoku-001', 'ハンバーグ定食', 'Hamburg Steak Set',   NULL, 4, 1),
-- 丼もの
('pmi-prm-005', 'plan-prm-001', 'c-donburi-001',  '牛丼',   'Beef Bowl',          NULL, 5, 1),
('pmi-prm-006', 'plan-prm-001', 'c-donburi-001',  '親子丼', 'Chicken & Egg Bowl', NULL, 6, 1),
('pmi-prm-007', 'plan-prm-001', 'c-donburi-001',  'カツ丼', 'Katsu Bowl',         NULL, 7, 1),
-- カレー
('pmi-prm-008', 'plan-prm-001', 'c-curry-001',    'ビーフカレー', 'Beef Curry',  NULL, 8, 1),
('pmi-prm-009', 'plan-prm-001', 'c-curry-001',    'カツカレー',   'Katsu Curry', NULL, 9, 1),
-- サイドメニュー
('pmi-prm-010', 'plan-prm-001', 'c-side-001',     '味噌汁', 'Miso Soup', NULL, 10, 1),
('pmi-prm-011', 'plan-prm-001', 'c-side-001',     'サラダ', 'Salad',     NULL, 11, 1),
('pmi-prm-012', 'plan-prm-001', 'c-side-001',     '冷奴',   'Cold Tofu', NULL, 12, 1),
-- ドリンク（プレミアム限定）
('pmi-prm-013', 'plan-prm-001', 'c-drink-001',    '緑茶',   'Green Tea', NULL, 13, 1),
('pmi-prm-014', 'plan-prm-001', 'c-drink-001',    'コーラ', 'Cola',      NULL, 14, 1),
('pmi-prm-015', 'plan-prm-001', 'c-drink-001',    'ビール', 'Beer',      NULL, 15, 1);

-- ============================================================
-- 8. プラン別メニュー品目（渋谷店 ランチ食べ放題 = 定食のみ）
-- ============================================================
INSERT IGNORE INTO plan_menu_items (id, plan_id, category_id, name, name_en, description, sort_order, is_active) VALUES
('pmi-lun-001', 'plan-lunch-001', 'c-teishoku-001', '焼き魚定食',     'Grilled Fish Set',   NULL, 1, 1),
('pmi-lun-002', 'plan-lunch-001', 'c-teishoku-001', '生姜焼き定食',   'Ginger Pork Set',    NULL, 2, 1),
('pmi-lun-003', 'plan-lunch-001', 'c-teishoku-001', 'チキン南蛮定食', 'Chicken Nanban Set', NULL, 3, 1),
('pmi-lun-004', 'plan-lunch-001', 'c-teishoku-001', 'ハンバーグ定食', 'Hamburg Steak Set',  NULL, 4, 1),
('pmi-lun-005', 'plan-lunch-001', 'c-side-001',     '味噌汁',         'Miso Soup',          NULL, 5, 1),
('pmi-lun-006', 'plan-lunch-001', 'c-side-001',     'サラダ',         'Salad',              NULL, 6, 1);

-- ============================================================
-- 9. プラン別メニュー品目（新宿店 90分食べ放題）
-- ============================================================
INSERT IGNORE INTO plan_menu_items (id, plan_id, category_id, name, name_en, description, sort_order, is_active) VALUES
('pmi-s90-001', 'plan-std-002', 'c-teishoku-001', '焼き魚定食',     'Grilled Fish Set',    NULL, 1, 1),
('pmi-s90-002', 'plan-std-002', 'c-teishoku-001', '生姜焼き定食',   'Ginger Pork Set',     NULL, 2, 1),
('pmi-s90-003', 'plan-std-002', 'c-teishoku-001', 'チキン南蛮定食', 'Chicken Nanban Set',  NULL, 3, 1),
('pmi-s90-004', 'plan-std-002', 'c-donburi-001',  '牛丼',           'Beef Bowl',           NULL, 4, 1),
('pmi-s90-005', 'plan-std-002', 'c-donburi-001',  '親子丼',         'Chicken & Egg Bowl',  NULL, 5, 1),
('pmi-s90-006', 'plan-std-002', 'c-side-001',     '味噌汁',         'Miso Soup',           NULL, 6, 1),
('pmi-s90-007', 'plan-std-002', 'c-side-001',     'サラダ',         'Salad',               NULL, 7, 1);

-- ============================================================
-- 10. プラン別メニュー品目（新宿店 プレミアム = 全メニュー + ドリンク）
-- ============================================================
INSERT IGNORE INTO plan_menu_items (id, plan_id, category_id, name, name_en, description, sort_order, is_active) VALUES
('pmi-sprm-001', 'plan-prm-002', 'c-teishoku-001', '焼き魚定食',     'Grilled Fish Set',    NULL, 1, 1),
('pmi-sprm-002', 'plan-prm-002', 'c-teishoku-001', '生姜焼き定食',   'Ginger Pork Set',     NULL, 2, 1),
('pmi-sprm-003', 'plan-prm-002', 'c-teishoku-001', 'チキン南蛮定食', 'Chicken Nanban Set',  NULL, 3, 1),
('pmi-sprm-004', 'plan-prm-002', 'c-teishoku-001', 'ハンバーグ定食', 'Hamburg Steak Set',   NULL, 4, 1),
('pmi-sprm-005', 'plan-prm-002', 'c-donburi-001',  '牛丼',           'Beef Bowl',           NULL, 5, 1),
('pmi-sprm-006', 'plan-prm-002', 'c-donburi-001',  '親子丼',         'Chicken & Egg Bowl',  NULL, 6, 1),
('pmi-sprm-007', 'plan-prm-002', 'c-donburi-001',  'カツ丼',         'Katsu Bowl',          NULL, 7, 1),
('pmi-sprm-008', 'plan-prm-002', 'c-curry-001',    'ビーフカレー',   'Beef Curry',          NULL, 8, 1),
('pmi-sprm-009', 'plan-prm-002', 'c-curry-001',    'カツカレー',     'Katsu Curry',         NULL, 9, 1),
('pmi-sprm-010', 'plan-prm-002', 'c-side-001',     '味噌汁',         'Miso Soup',           NULL, 10, 1),
('pmi-sprm-011', 'plan-prm-002', 'c-side-001',     'サラダ',         'Salad',               NULL, 11, 1),
('pmi-sprm-012', 'plan-prm-002', 'c-side-001',     '冷奴',           'Cold Tofu',           NULL, 12, 1),
('pmi-sprm-013', 'plan-prm-002', 'c-drink-001',    '緑茶',           'Green Tea',           NULL, 13, 1),
('pmi-sprm-014', 'plan-prm-002', 'c-drink-001',    'コーラ',         'Cola',                NULL, 14, 1),
('pmi-sprm-015', 'plan-prm-002', 'c-drink-001',    'ビール',         'Beer',                NULL, 15, 1);
