-- ============================================================
-- Seed: 桃の屋���ナント（マルチテナント検証用）
-- ============================================================
-- パスワード: 全ユー���ー 'password'
-- 松の屋とは完全に独立したデータ
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- テナント
-- ============================================================
INSERT INTO tenants (id, slug, name, name_en) VALUES
('t-momonoya-001', 'momonoya', '桃の屋', 'Momonoya');

-- ============================================================
-- 店舗（3店舗: 池袋・横浜・吉祥寺）
-- ============================================================
INSERT INTO stores (id, tenant_id, slug, name, name_en) VALUES
('s-ikebukuro-001', 't-momonoya-001', 'ikebukuro', '桃の屋 池袋店', 'Momonoya Ikebukuro'),
('s-yokohama-001',  't-momonoya-001', 'yokohama',  '桃の屋 横浜店', 'Momonoya Yokohama'),
('s-kichijoji-001', 't-momonoya-001', 'kichijoji', '桃の屋 吉祥寺店', 'Momonoya Kichijoji');

-- ============================================================
-- 店舗設定
-- ============================================================
INSERT INTO store_settings (store_id, receipt_store_name) VALUES
('s-ikebukuro-001', '桃の屋 池袋店'),
('s-yokohama-001',  '桃の屋 横浜店'),
('s-kichijoji-001', '桃の屋 吉祥寺店');

-- ============================================================
-- ユーザー
-- ============================================================
INSERT INTO users (id, tenant_id, email, password_hash, display_name, role) VALUES
('u-momo-owner-001', 't-momonoya-001', 'owner@momonoya.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 '桃山オーナー', 'owner'),
('u-momo-mgr-001', 't-momonoya-001', 'manager-ikebukuro@momonoya.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 '池袋マネージャー', 'manager'),
('u-momo-mgr-002', 't-momonoya-001', 'manager-yokohama@momonoya.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 '横浜マネージャー', 'manager'),
('u-momo-staff-001', 't-momonoya-001', 'staff-ikebukuro@momonoya.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 '池袋スタッフ', 'staff');

-- ============================================================
-- ユーザー×店舗
-- ============================================================
INSERT INTO user_stores (user_id, store_id) VALUES
('u-momo-mgr-001',   's-ikebukuro-001'),
('u-momo-mgr-002',   's-yokohama-001'),
('u-momo-staff-001', 's-ikebukuro-001');

-- ============================================================
-- カテゴリ（桃の屋独自）
-- ============================================================
INSERT INTO categories (id, tenant_id, name, name_en, sort_order) VALUES
('c-momo-pasta-001',   't-momonoya-001', 'パスタ',       'Pasta',     1),
('c-momo-pizza-001',   't-momonoya-001', 'ピザ',         'Pizza',     2),
('c-momo-salad-001',   't-momonoya-001', 'サラダ',       'Salad',     3),
('c-momo-dessert-001', 't-momonoya-001', 'デザート',     'Dessert',   4),
('c-momo-drink-001',   't-momonoya-001', 'ドリンク',     'Drinks',    5);

-- ============================================================
-- 本部メニューテンプレート
-- ============================================================
INSERT INTO menu_templates (id, tenant_id, category_id, name, name_en, base_price, description, description_en, sort_order) VALUES
-- パスタ
('mt-momo-001', 't-momonoya-001', 'c-momo-pasta-001', 'ナポリタン', 'Napolitan', 880, '昔ながらの喫茶店風ナポリタン', 'Classic Japanese-style Napolitan', 1),
('mt-momo-002', 't-momonoya-001', 'c-momo-pasta-001', 'カルボナーラ', 'Carbonara', 980, '濃厚クリームのカルボナーラ', 'Rich cream carbonara', 2),
('mt-momo-003', 't-momonoya-001', 'c-momo-pasta-001', 'ペペロンチーノ', 'Peperoncino', 850, 'にんにく香るシンプルパスタ', 'Simple garlic pasta', 3),
('mt-momo-004', 't-momonoya-001', 'c-momo-pasta-001', 'ミートソース', 'Meat Sauce', 920, '自家製ミートソース', 'Homemade meat sauce pasta', 4),
-- ピザ
('mt-momo-005', 't-momonoya-001', 'c-momo-pizza-001', 'マルゲリータ', 'Margherita', 1100, 'モッツァレラとバジルのシンプルピザ', 'Simple pizza with mozzarella and basil', 1),
('mt-momo-006', 't-momonoya-001', 'c-momo-pizza-001', 'クアトロフォルマッジ', 'Quattro Formaggi', 1300, '4種のチーズピザ', 'Four cheese pizza', 2),
('mt-momo-007', 't-momonoya-001', 'c-momo-pizza-001', 'ビスマルク', 'Bismarck', 1200, '半熟卵のせピザ', 'Pizza with soft-boiled egg', 3),
-- サラダ
('mt-momo-008', 't-momonoya-001', 'c-momo-salad-001', 'シーザーサラダ', 'Caesar Salad', 650, '自家製ドレッシングのシーザーサラダ', 'Caesar salad with homemade dressing', 1),
('mt-momo-009', 't-momonoya-001', 'c-momo-salad-001', 'カプレーゼ', 'Caprese', 700, 'トマトとモッツァレラのカプレーゼ', 'Tomato and mozzarella caprese', 2),
-- デザート
('mt-momo-010', 't-momonoya-001', 'c-momo-dessert-001', 'ティラミス', 'Tiramisu', 550, '本格イタリアンティラミス', 'Authentic Italian tiramisu', 1),
('mt-momo-011', 't-momonoya-001', 'c-momo-dessert-001', 'パンナコッタ', 'Panna Cotta', 480, 'なめらかパンナコッタ', 'Smooth panna cotta', 2),
('mt-momo-012', 't-momonoya-001', 'c-momo-dessert-001', 'ジェラート', 'Gelato', 400, '本日のジェラート2種盛り', 'Two scoops of today''s gelato', 3),
-- ドリンク
('mt-momo-013', 't-momonoya-001', 'c-momo-drink-001', 'エスプレッソ', 'Espresso', 300, '', '', 1),
('mt-momo-014', 't-momonoya-001', 'c-momo-drink-001', 'カプチーノ', 'Cappuccino', 450, '', '', 2),
('mt-momo-015', 't-momonoya-001', 'c-momo-drink-001', 'レモネード', 'Lemonade', 400, '自家製レモネード', 'Homemade lemonade', 3),
('mt-momo-016', 't-momonoya-001', 'c-momo-drink-001', 'ワイン（赤）', 'Red Wine', 600, 'グラスワイン', 'Glass of red wine', 4),
('mt-momo-017', 't-momonoya-001', 'c-momo-drink-001', 'ワイン（白）', 'White Wine', 600, 'グラスワイン', 'Glass of white wine', 5);

-- ============================================================
-- 店舗オーバーライド（池袋店: 都心価格アップ）
-- ============================================================
INSERT INTO store_menu_overrides (id, store_id, template_id, price, is_hidden, is_sold_out) VALUES
('smo-momo-001', 's-ikebukuro-001', 'mt-momo-001', 930, 0, 0),
('smo-momo-002', 's-ikebukuro-001', 'mt-momo-005', 1200, 0, 0);

-- ============================================================
-- 店舗限定メニュー
-- ============================================================
-- 池袋店限定
INSERT INTO store_local_items (id, store_id, category_id, name, name_en, price, description, description_en, sort_order) VALUES
('sli-momo-001', 's-ikebukuro-001', 'c-momo-pasta-001', '池袋スペシャルパスタ', 'Ikebukuro Special Pasta', 1280, '池袋店限定の贅沢パスタ', 'Ikebukuro-exclusive luxury pasta', 10);

-- 横浜店限定
INSERT INTO store_local_items (id, store_id, category_id, name, name_en, price, description, description_en, sort_order) VALUES
('sli-momo-002', 's-yokohama-001', 'c-momo-salad-001', '横浜ベイサラダ', 'Yokohama Bay Salad', 780, '横浜店限定の海鮮サラダ', 'Yokohama-exclusive seafood salad', 10);

-- ============================================================
-- テーブル
-- ============================================================
INSERT INTO tables (id, store_id, table_code, capacity) VALUES
-- 池袋店（6卓）
('tbl-ike-01', 's-ikebukuro-001', 'T01', 2),
('tbl-ike-02', 's-ikebukuro-001', 'T02', 4),
('tbl-ike-03', 's-ikebukuro-001', 'T03', 4),
('tbl-ike-04', 's-ikebukuro-001', 'T04', 4),
('tbl-ike-05', 's-ikebukuro-001', 'T05', 6),
('tbl-ike-06', 's-ikebukuro-001', 'T06', 6),
-- 横浜店（5卓）
('tbl-yok-01', 's-yokohama-001', 'T01', 2),
('tbl-yok-02', 's-yokohama-001', 'T02', 4),
('tbl-yok-03', 's-yokohama-001', 'T03', 4),
('tbl-yok-04', 's-yokohama-001', 'T04', 6),
('tbl-yok-05', 's-yokohama-001', 'T05', 8),
-- 吉祥寺店（4卓）
('tbl-kic-01', 's-kichijoji-001', 'T01', 2),
('tbl-kic-02', 's-kichijoji-001', 'T02', 4),
('tbl-kic-03', 's-kichijoji-001', 'T03', 4),
('tbl-kic-04', 's-kichijoji-001', 'T04', 6);
