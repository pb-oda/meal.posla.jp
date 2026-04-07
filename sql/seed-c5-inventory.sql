-- ============================================================
-- Seed C5: 原材料マスタ & レシピ構成 デモデータ
-- ============================================================
-- 前提: seed.sql 適用済み（テナント・メニューテンプレート）
-- 前提: migration-c5-inventory.sql 適用済み
-- 冪等: INSERT IGNORE のため再実行可能
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- 1. 原材料マスタ（テナント: 松の屋）
-- ============================================================

-- ----- 肉・魚 -----
INSERT IGNORE INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order) VALUES
('ing-001', 't-matsunoya-001', '魚切り身（鮭）',   'g',  5000,  3.00,  500, 1),
('ing-002', 't-matsunoya-001', '豚バラ肉',         'g',  8000,  2.50, 1000, 2),
('ing-003', 't-matsunoya-001', '鶏もも肉',         'g', 10000,  1.80, 1000, 3),
('ing-004', 't-matsunoya-001', '合挽き肉',         'g',  5000,  2.20,  500, 4),
('ing-005', 't-matsunoya-001', '牛バラ肉',         'g',  6000,  4.00,  800, 5),
('ing-006', 't-matsunoya-001', '豚ロース',         'g',  6000,  2.80,  800, 6);

-- ----- 野菜 -----
INSERT IGNORE INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order) VALUES
('ing-010', 't-matsunoya-001', '玉ねぎ',     'g', 15000, 0.30, 2000, 10),
('ing-011', 't-matsunoya-001', 'キャベツ',   'g', 10000, 0.20, 1500, 11),
('ing-012', 't-matsunoya-001', 'レタス',     'g',  3000, 0.50,  500, 12),
('ing-013', 't-matsunoya-001', 'トマト',     '個',  50,  80.00,   10, 13),
('ing-014', 't-matsunoya-001', 'きゅうり',   '本',  40,  50.00,    8, 14),
('ing-015', 't-matsunoya-001', 'にんじん',   'g',  5000, 0.25,  800, 15),
('ing-016', 't-matsunoya-001', 'じゃがいも', 'g',  5000, 0.30,  800, 16),
('ing-017', 't-matsunoya-001', '大根',       'g',  4000, 0.15,  500, 17),
('ing-018', 't-matsunoya-001', '生姜',       'g',  1000, 1.00,  200, 18),
('ing-019', 't-matsunoya-001', 'ネギ',       'g',  3000, 0.80,  500, 19);

-- ----- 穀物・粉類 -----
INSERT IGNORE INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order) VALUES
('ing-020', 't-matsunoya-001', '白米',   'g', 50000, 0.40, 5000, 20),
('ing-021', 't-matsunoya-001', 'パン粉', 'g',  3000, 0.50,  500, 21),
('ing-022', 't-matsunoya-001', '小麦粉', 'g',  3000, 0.30,  500, 22);

-- ----- 大豆製品・卵 -----
INSERT IGNORE INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order) VALUES
('ing-025', 't-matsunoya-001', '豆腐',   '丁', 60, 40.00, 10, 25),
('ing-026', 't-matsunoya-001', '味噌',   'g',  5000, 1.20, 500, 26),
('ing-027', 't-matsunoya-001', '卵',     '個', 200,  20.00, 30, 27);

-- ----- 調味料 -----
INSERT IGNORE INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order) VALUES
('ing-030', 't-matsunoya-001', '醤油',         'ml', 10000, 0.20, 1000, 30),
('ing-031', 't-matsunoya-001', 'みりん',       'ml',  5000, 0.30,  500, 31),
('ing-032', 't-matsunoya-001', 'だし汁',       'ml', 20000, 0.10, 2000, 32),
('ing-033', 't-matsunoya-001', 'カレールー',   'g',   3000, 2.00,  500, 33),
('ing-034', 't-matsunoya-001', 'タルタルソース','g',   2000, 1.50,  300, 34),
('ing-035', 't-matsunoya-001', 'ドレッシング', 'ml',  3000, 0.80,  500, 35),
('ing-036', 't-matsunoya-001', 'サラダ油',     'ml', 10000, 0.15, 1500, 36);

-- ----- その他食材 -----
INSERT IGNORE INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order) VALUES
('ing-040', 't-matsunoya-001', 'わかめ',     'g',  1000, 2.00, 200, 40),
('ing-041', 't-matsunoya-001', 'かつお節',   'g',   500, 5.00, 100, 41),
('ing-042', 't-matsunoya-001', 'レモン',     '個',   30, 60.00,   5, 42),
('ing-043', 't-matsunoya-001', '漬物',       'g',  3000, 0.80, 500, 43),
('ing-044', 't-matsunoya-001', '紅生姜',     'g',  1000, 1.50, 200, 44);

-- ----- 飲料原料 -----
INSERT IGNORE INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order) VALUES
('ing-050', 't-matsunoya-001', '緑茶葉',       'g',  1000, 3.00, 200, 50),
('ing-051', 't-matsunoya-001', 'コーラ（PET）', '本',  48, 80.00,  10, 51),
('ing-052', 't-matsunoya-001', 'ビール（缶）',  '本',  72, 150.00, 12, 52);

-- ============================================================
-- 2. レシピ構成（メニュー品目 × 原材料 × 1品あたり使用量）
-- ============================================================

-- ----- mt-001 焼き魚定食 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-001-001', 'mt-001', 'ing-001', 120.00),  -- 魚切り身 120g
('rcp-001-020', 'mt-001', 'ing-020', 250.00),  -- 白米 250g
('rcp-001-026', 'mt-001', 'ing-026',  15.00),  -- 味噌 15g（味噌汁分）
('rcp-001-025', 'mt-001', 'ing-025',   0.25),  -- 豆腐 0.25丁（味噌汁分）
('rcp-001-019', 'mt-001', 'ing-019',   5.00),  -- ネギ 5g
('rcp-001-032', 'mt-001', 'ing-032', 180.00),  -- だし汁 180ml
('rcp-001-017', 'mt-001', 'ing-017',  30.00),  -- 大根おろし 30g
('rcp-001-042', 'mt-001', 'ing-042',   0.25),  -- レモン 1/4個
('rcp-001-043', 'mt-001', 'ing-043',  20.00);  -- 漬物 20g

-- ----- mt-002 生姜焼き定食 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-002-002', 'mt-002', 'ing-002', 150.00),  -- 豚バラ 150g
('rcp-002-018', 'mt-002', 'ing-018',  10.00),  -- 生姜 10g
('rcp-002-010', 'mt-002', 'ing-010',  80.00),  -- 玉ねぎ 80g
('rcp-002-030', 'mt-002', 'ing-030',  20.00),  -- 醤油 20ml
('rcp-002-031', 'mt-002', 'ing-031',  15.00),  -- みりん 15ml
('rcp-002-036', 'mt-002', 'ing-036',  10.00),  -- サラダ油 10ml
('rcp-002-011', 'mt-002', 'ing-011',  50.00),  -- キャベツ 50g（付け合わせ）
('rcp-002-020', 'mt-002', 'ing-020', 250.00),  -- 白米 250g
('rcp-002-026', 'mt-002', 'ing-026',  15.00),  -- 味噌 15g
('rcp-002-025', 'mt-002', 'ing-025',   0.25),  -- 豆腐 0.25丁
('rcp-002-019', 'mt-002', 'ing-019',   5.00),  -- ネギ 5g
('rcp-002-032', 'mt-002', 'ing-032', 180.00),  -- だし汁 180ml
('rcp-002-043', 'mt-002', 'ing-043',  20.00);  -- 漬物 20g

-- ----- mt-003 チキン南蛮定食 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-003-003', 'mt-003', 'ing-003', 180.00),  -- 鶏もも肉 180g
('rcp-003-027', 'mt-003', 'ing-027',   1.00),  -- 卵 1個（衣用）
('rcp-003-022', 'mt-003', 'ing-022',  30.00),  -- 小麦粉 30g
('rcp-003-034', 'mt-003', 'ing-034',  40.00),  -- タルタルソース 40g
('rcp-003-030', 'mt-003', 'ing-030',  15.00),  -- 醤油 15ml（南蛮酢）
('rcp-003-036', 'mt-003', 'ing-036',  80.00),  -- サラダ油 80ml（揚げ油）
('rcp-003-011', 'mt-003', 'ing-011',  50.00),  -- キャベツ 50g
('rcp-003-020', 'mt-003', 'ing-020', 250.00),  -- 白米 250g
('rcp-003-026', 'mt-003', 'ing-026',  15.00),  -- 味噌 15g
('rcp-003-025', 'mt-003', 'ing-025',   0.25),  -- 豆腐 0.25丁
('rcp-003-019', 'mt-003', 'ing-019',   5.00),  -- ネギ 5g
('rcp-003-032', 'mt-003', 'ing-032', 180.00),  -- だし汁 180ml
('rcp-003-043', 'mt-003', 'ing-043',  20.00);  -- 漬物 20g

-- ----- mt-004 ハンバーグ定食 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-004-004', 'mt-004', 'ing-004', 200.00),  -- 合挽き肉 200g
('rcp-004-010', 'mt-004', 'ing-010',  60.00),  -- 玉ねぎ 60g（みじん切り）
('rcp-004-021', 'mt-004', 'ing-021',  15.00),  -- パン粉 15g
('rcp-004-027', 'mt-004', 'ing-027',   1.00),  -- 卵 1個
('rcp-004-036', 'mt-004', 'ing-036',  10.00),  -- サラダ油 10ml
('rcp-004-030', 'mt-004', 'ing-030',  15.00),  -- 醤油 15ml（ソース用）
('rcp-004-011', 'mt-004', 'ing-011',  50.00),  -- キャベツ 50g
('rcp-004-020', 'mt-004', 'ing-020', 250.00),  -- 白米 250g
('rcp-004-026', 'mt-004', 'ing-026',  15.00),  -- 味噌 15g
('rcp-004-025', 'mt-004', 'ing-025',   0.25),  -- 豆腐 0.25丁
('rcp-004-019', 'mt-004', 'ing-019',   5.00),  -- ネギ 5g
('rcp-004-032', 'mt-004', 'ing-032', 180.00),  -- だし汁 180ml
('rcp-004-043', 'mt-004', 'ing-043',  20.00);  -- 漬物 20g

-- ----- mt-005 牛丼 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-005-005', 'mt-005', 'ing-005', 120.00),  -- 牛バラ肉 120g
('rcp-005-010', 'mt-005', 'ing-010', 100.00),  -- 玉ねぎ 100g
('rcp-005-020', 'mt-005', 'ing-020', 280.00),  -- 白米 280g
('rcp-005-030', 'mt-005', 'ing-030',  25.00),  -- 醤油 25ml
('rcp-005-031', 'mt-005', 'ing-031',  20.00),  -- みりん 20ml
('rcp-005-018', 'mt-005', 'ing-018',   5.00),  -- 生姜 5g
('rcp-005-032', 'mt-005', 'ing-032',  50.00),  -- だし汁 50ml
('rcp-005-044', 'mt-005', 'ing-044',  10.00);  -- 紅生姜 10g

-- ----- mt-006 親子丼 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-006-003', 'mt-006', 'ing-003', 120.00),  -- 鶏もも肉 120g
('rcp-006-027', 'mt-006', 'ing-027',   2.00),  -- 卵 2個
('rcp-006-010', 'mt-006', 'ing-010',  80.00),  -- 玉ねぎ 80g
('rcp-006-020', 'mt-006', 'ing-020', 280.00),  -- 白米 280g
('rcp-006-030', 'mt-006', 'ing-030',  20.00),  -- 醤油 20ml
('rcp-006-031', 'mt-006', 'ing-031',  15.00),  -- みりん 15ml
('rcp-006-032', 'mt-006', 'ing-032',  80.00),  -- だし汁 80ml
('rcp-006-019', 'mt-006', 'ing-019',   5.00);  -- ネギ 5g（仕上げ）

-- ----- mt-007 カツ丼 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-007-006', 'mt-007', 'ing-006', 150.00),  -- 豚ロース 150g
('rcp-007-027', 'mt-007', 'ing-027',   2.00),  -- 卵 2個（衣1 + とじ1）
('rcp-007-010', 'mt-007', 'ing-010',  60.00),  -- 玉ねぎ 60g
('rcp-007-021', 'mt-007', 'ing-021',  30.00),  -- パン粉 30g
('rcp-007-022', 'mt-007', 'ing-022',  20.00),  -- 小麦粉 20g
('rcp-007-036', 'mt-007', 'ing-036',  80.00),  -- サラダ油 80ml（揚げ油）
('rcp-007-020', 'mt-007', 'ing-020', 280.00),  -- 白米 280g
('rcp-007-030', 'mt-007', 'ing-030',  25.00),  -- 醤油 25ml
('rcp-007-031', 'mt-007', 'ing-031',  20.00),  -- みりん 20ml
('rcp-007-032', 'mt-007', 'ing-032',  60.00);  -- だし汁 60ml

-- ----- mt-008 ビーフカレー -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-008-005', 'mt-008', 'ing-005', 100.00),  -- 牛バラ肉 100g
('rcp-008-010', 'mt-008', 'ing-010', 100.00),  -- 玉ねぎ 100g
('rcp-008-015', 'mt-008', 'ing-015',  50.00),  -- にんじん 50g
('rcp-008-016', 'mt-008', 'ing-016',  80.00),  -- じゃがいも 80g
('rcp-008-033', 'mt-008', 'ing-033',  40.00),  -- カレールー 40g
('rcp-008-036', 'mt-008', 'ing-036',  10.00),  -- サラダ油 10ml
('rcp-008-020', 'mt-008', 'ing-020', 280.00);  -- 白米 280g

-- ----- mt-009 カツカレー -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-009-006', 'mt-009', 'ing-006', 150.00),  -- 豚ロース 150g
('rcp-009-021', 'mt-009', 'ing-021',  30.00),  -- パン粉 30g
('rcp-009-022', 'mt-009', 'ing-022',  20.00),  -- 小麦粉 20g
('rcp-009-027', 'mt-009', 'ing-027',   1.00),  -- 卵 1個（衣用）
('rcp-009-036', 'mt-009', 'ing-036',  80.00),  -- サラダ油 80ml（揚げ油）
('rcp-009-010', 'mt-009', 'ing-010', 100.00),  -- 玉ねぎ 100g
('rcp-009-015', 'mt-009', 'ing-015',  50.00),  -- にんじん 50g
('rcp-009-016', 'mt-009', 'ing-016',  80.00),  -- じゃがいも 80g
('rcp-009-033', 'mt-009', 'ing-033',  40.00),  -- カレールー 40g
('rcp-009-020', 'mt-009', 'ing-020', 280.00);  -- 白米 280g

-- ----- mt-010 味噌汁 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-010-026', 'mt-010', 'ing-026',  15.00),  -- 味噌 15g
('rcp-010-025', 'mt-010', 'ing-025',   0.25),  -- 豆腐 0.25丁
('rcp-010-019', 'mt-010', 'ing-019',   5.00),  -- ネギ 5g
('rcp-010-040', 'mt-010', 'ing-040',   3.00),  -- わかめ 3g
('rcp-010-032', 'mt-010', 'ing-032', 180.00);  -- だし汁 180ml

-- ----- mt-011 サラダ -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-011-012', 'mt-011', 'ing-012',  60.00),  -- レタス 60g
('rcp-011-013', 'mt-011', 'ing-013',   0.50),  -- トマト 0.5個
('rcp-011-014', 'mt-011', 'ing-014',   0.50),  -- きゅうり 0.5本
('rcp-011-035', 'mt-011', 'ing-035',  20.00);  -- ドレッシング 20ml

-- ----- mt-012 冷奴 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-012-025', 'mt-012', 'ing-025',   0.50),  -- 豆腐 0.5丁
('rcp-012-019', 'mt-012', 'ing-019',   5.00),  -- ネギ 5g
('rcp-012-018', 'mt-012', 'ing-018',   3.00),  -- 生姜 3g
('rcp-012-030', 'mt-012', 'ing-030',  10.00),  -- 醤油 10ml
('rcp-012-041', 'mt-012', 'ing-041',   3.00);  -- かつお節 3g

-- ----- mt-013 緑茶 -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-013-050', 'mt-013', 'ing-050',   3.00);  -- 緑茶葉 3g

-- ----- mt-014 コーラ -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-014-051', 'mt-014', 'ing-051',   1.00);  -- コーラ 1本

-- ----- mt-015 ビール -----
INSERT IGNORE INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES
('rcp-015-052', 'mt-015', 'ing-052',   1.00);  -- ビール 1本
