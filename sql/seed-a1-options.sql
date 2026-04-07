-- ============================================================
-- Seed A-1: オプション/トッピング テストデータ
-- ============================================================
-- 前提: migration-a1-options.sql 実行済み
-- 前提: seed.sql 実行済み（テナント・メニューテンプレートが存在）
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- オプショングループ（テナント単位）
-- ============================================================
INSERT INTO option_groups (id, tenant_id, name, name_en, selection_type, min_select, max_select, sort_order) VALUES
('og-rice-001',       't-matsunoya-001', 'ごはんサイズ',   'Rice Size',    'single', 1, 1, 1),
('og-topping-001',    't-matsunoya-001', 'トッピング',     'Toppings',     'multi',  0, 3, 2),
('og-spice-001',      't-matsunoya-001', '辛さ',           'Spice Level',  'single', 0, 1, 3),
('og-drink-size-001', 't-matsunoya-001', 'ドリンクサイズ', 'Drink Size',   'single', 1, 1, 4),
('og-remove-001',     't-matsunoya-001', '抜き',           'Removals',     'multi',  0, 3, 5);

-- ============================================================
-- オプション選択肢
-- ============================================================

-- ごはんサイズ
INSERT INTO option_choices (id, group_id, name, name_en, price_diff, is_default, sort_order) VALUES
('oc-rice-normal', 'og-rice-001', '普通',   'Regular', 0,    1, 1),
('oc-rice-large',  'og-rice-001', '大盛り', 'Large',   100,  0, 2),
('oc-rice-small',  'og-rice-001', '小盛り', 'Small',   -50,  0, 3);

-- トッピング
INSERT INTO option_choices (id, group_id, name, name_en, price_diff, is_default, sort_order) VALUES
('oc-top-egg',    'og-topping-001', '温泉卵',   'Soft-boiled Egg',    80,  0, 1),
('oc-top-cheese', 'og-topping-001', 'チーズ',   'Cheese',             120, 0, 2),
('oc-top-negi',   'og-topping-001', 'ネギ増し', 'Extra Green Onion',  50,  0, 3);

-- 辛さ
INSERT INTO option_choices (id, group_id, name, name_en, price_diff, is_default, sort_order) VALUES
('oc-spice-normal', 'og-spice-001', '普通', 'Regular',    0,  1, 1),
('oc-spice-medium', 'og-spice-001', '中辛', 'Medium',     0,  0, 2),
('oc-spice-hot',    'og-spice-001', '大辛', 'Hot',        0,  0, 3),
('oc-spice-extra',  'og-spice-001', '激辛', 'Extra Hot',  50, 0, 4);

-- ドリンクサイズ
INSERT INTO option_choices (id, group_id, name, name_en, price_diff, is_default, sort_order) VALUES
('oc-drink-s', 'og-drink-size-001', 'S', 'Small',  -50, 0, 1),
('oc-drink-m', 'og-drink-size-001', 'M', 'Medium',  0,  1, 2),
('oc-drink-l', 'og-drink-size-001', 'L', 'Large',   100, 0, 3);

-- 抜き
INSERT INTO option_choices (id, group_id, name, name_en, price_diff, is_default, sort_order) VALUES
('oc-rm-negi',   'og-remove-001', 'ネギ抜き',       'No Green Onion', 0, 0, 1),
('oc-rm-onion',  'og-remove-001', '玉ねぎ抜き',     'No Onion',       0, 0, 2),
('oc-rm-garlic', 'og-remove-001', 'にんにく抜き',   'No Garlic',      0, 0, 3);

-- ============================================================
-- テンプレート × オプショングループ 紐付け
-- ============================================================

-- 定食(mt-001〜004): ごはんサイズ（必須）
INSERT INTO menu_template_options (template_id, group_id, is_required, sort_order) VALUES
('mt-001', 'og-rice-001', 1, 1),
('mt-002', 'og-rice-001', 1, 1),
('mt-003', 'og-rice-001', 1, 1),
('mt-004', 'og-rice-001', 1, 1);

-- 丼もの(mt-005〜007): ごはんサイズ（必須）+ トッピング + 抜き
INSERT INTO menu_template_options (template_id, group_id, is_required, sort_order) VALUES
('mt-005', 'og-rice-001',    1, 1),
('mt-005', 'og-topping-001', 0, 2),
('mt-005', 'og-remove-001',  0, 3),
('mt-006', 'og-rice-001',    1, 1),
('mt-006', 'og-topping-001', 0, 2),
('mt-007', 'og-rice-001',    1, 1),
('mt-007', 'og-topping-001', 0, 2);

-- カレー(mt-008〜009): ごはんサイズ（必須）+ 辛さ + トッピング
INSERT INTO menu_template_options (template_id, group_id, is_required, sort_order) VALUES
('mt-008', 'og-rice-001',    1, 1),
('mt-008', 'og-spice-001',   0, 2),
('mt-008', 'og-topping-001', 0, 3),
('mt-009', 'og-rice-001',    1, 1),
('mt-009', 'og-spice-001',   0, 2),
('mt-009', 'og-topping-001', 0, 3);

-- ドリンク(mt-013 緑茶, mt-014 コーラ): ドリンクサイズ
-- ※ mt-015 ビールはサイズ固定のため紐付けなし
INSERT INTO menu_template_options (template_id, group_id, is_required, sort_order) VALUES
('mt-013', 'og-drink-size-001', 0, 1),
('mt-014', 'og-drink-size-001', 0, 1);

-- ============================================================
-- 店舗限定メニュー × オプショングループ 紐付け
-- ============================================================

-- 渋谷スペシャル定食(sli-001): ごはんサイズ（必須）
INSERT INTO local_item_options (local_item_id, group_id, is_required, sort_order) VALUES
('sli-001', 'og-rice-001', 1, 1);

-- 抹茶ラテ(sli-002): ドリンクサイズ
INSERT INTO local_item_options (local_item_id, group_id, is_required, sort_order) VALUES
('sli-002', 'og-drink-size-001', 0, 1);

-- ============================================================
-- 店舗オーバーライド（店舗別の価格変更・非表示）
-- ============================================================

-- 渋谷店: 大盛り → +150円（本部の+100円より高い都心価格）
INSERT INTO store_option_overrides (id, store_id, choice_id, price_diff, is_available) VALUES
('soo-opt-001', 's-shibuya-001', 'oc-rice-large', 150, 1);

-- 新宿店: 激辛 → 非表示（店舗の判断で提供停止）
INSERT INTO store_option_overrides (id, store_id, choice_id, price_diff, is_available) VALUES
('soo-opt-002', 's-shinjuku-001', 'oc-spice-extra', NULL, 0);
