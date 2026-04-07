-- ============================================================
-- Seed Data — デモテナント + 2店舗 + ユーザー + メニュー
-- ============================================================
-- パスワード: 全ユーザー 'password123'
-- password_hash('password123', PASSWORD_DEFAULT) の結果を使用

SET NAMES utf8mb4;

-- ============================================================
-- テナント
-- ============================================================
INSERT INTO tenants (id, slug, name, name_en) VALUES
('t-matsunoya-001', 'matsunoya', '松の屋', 'Matsunoya');

-- ============================================================
-- 店舗
-- ============================================================
INSERT INTO stores (id, tenant_id, slug, name, name_en) VALUES
('s-shibuya-001', 't-matsunoya-001', 'shibuya', '松の屋 渋谷店', 'Matsunoya Shibuya'),
('s-shinjuku-001', 't-matsunoya-001', 'shinjuku', '松の屋 新宿店', 'Matsunoya Shinjuku');

-- ============================================================
-- 店舗設定
-- ============================================================
INSERT INTO store_settings (store_id, receipt_store_name) VALUES
('s-shibuya-001', '松の屋 渋谷店'),
('s-shinjuku-001', '松の屋 新宿店');

-- ============================================================
-- ユーザー
-- ※ 実環境ではPHPで生成したハッシュに差し替えてください
-- ============================================================
INSERT INTO users (id, tenant_id, email, password_hash, display_name, role) VALUES
('u-owner-001', 't-matsunoya-001', 'owner@matsunoya.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'オーナー太郎', 'owner'),
('u-manager-001', 't-matsunoya-001', 'manager-shibuya@matsunoya.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 '渋谷マネージャー', 'manager'),
('u-manager-002', 't-matsunoya-001', 'manager-shinjuku@matsunoya.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 '新宿マネージャー', 'manager'),
('u-staff-001', 't-matsunoya-001', 'staff-shibuya@matsunoya.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 '渋谷スタッフ', 'staff');

-- ============================================================
-- ユーザー×店舗（owner以外に必要）
-- ============================================================
INSERT INTO user_stores (user_id, store_id) VALUES
('u-manager-001', 's-shibuya-001'),
('u-manager-002', 's-shinjuku-001'),
('u-staff-001', 's-shibuya-001');

-- ============================================================
-- カテゴリ（テナント単位）
-- ============================================================
INSERT INTO categories (id, tenant_id, name, name_en, sort_order) VALUES
('c-teishoku-001', 't-matsunoya-001', '定食', 'Set Meals', 1),
('c-donburi-001',  't-matsunoya-001', '丼もの', 'Rice Bowls', 2),
('c-curry-001',    't-matsunoya-001', 'カレー', 'Curry', 3),
('c-side-001',     't-matsunoya-001', 'サイドメニュー', 'Side Menu', 4),
('c-drink-001',    't-matsunoya-001', 'ドリンク', 'Drinks', 5);

-- ============================================================
-- 本部メニューテンプレート
-- ============================================================
INSERT INTO menu_templates (id, tenant_id, category_id, name, name_en, base_price, description, description_en, sort_order) VALUES
('mt-001', 't-matsunoya-001', 'c-teishoku-001', '焼き魚定食', 'Grilled Fish Set', 950, '本日の焼き魚、ご飯、味噌汁、小鉢付き', 'Grilled fish of the day with rice, miso soup and side dish', 1),
('mt-002', 't-matsunoya-001', 'c-teishoku-001', '生姜焼き定食', 'Ginger Pork Set', 900, '豚の生姜焼き、ご飯、味噌汁、サラダ付き', 'Ginger pork with rice, miso soup and salad', 2),
('mt-003', 't-matsunoya-001', 'c-teishoku-001', 'チキン南蛮定食', 'Chicken Nanban Set', 950, 'タルタルソースのチキン南蛮、ご飯、味噌汁付き', 'Chicken nanban with tartar sauce, rice and miso soup', 3),
('mt-004', 't-matsunoya-001', 'c-teishoku-001', 'ハンバーグ定食', 'Hamburg Steak Set', 1000, '手ごねハンバーグ、ご飯、味噌汁、サラダ付き', 'Handmade hamburg steak with rice, miso soup and salad', 4),
('mt-005', 't-matsunoya-001', 'c-donburi-001', '牛丼', 'Gyudon', 680, '特製タレの牛丼', 'Beef bowl with special sauce', 1),
('mt-006', 't-matsunoya-001', 'c-donburi-001', '親子丼', 'Oyakodon', 750, 'ふわとろ卵の親子丼', 'Chicken and egg rice bowl', 2),
('mt-007', 't-matsunoya-001', 'c-donburi-001', 'カツ丼', 'Katsudon', 880, 'サクサクのカツ丼', 'Pork cutlet rice bowl', 3),
('mt-008', 't-matsunoya-001', 'c-curry-001', 'ビーフカレー', 'Beef Curry', 800, 'じっくり煮込んだビーフカレー', 'Slow-cooked beef curry', 1),
('mt-009', 't-matsunoya-001', 'c-curry-001', 'カツカレー', 'Katsu Curry', 950, 'サクサクカツのせカレー', 'Curry with crispy pork cutlet', 2),
('mt-010', 't-matsunoya-001', 'c-side-001', '味噌汁', 'Miso Soup', 200, '', '', 1),
('mt-011', 't-matsunoya-001', 'c-side-001', 'サラダ', 'Salad', 300, 'グリーンサラダ', 'Green salad', 2),
('mt-012', 't-matsunoya-001', 'c-side-001', '冷奴', 'Hiyayakko', 250, '', 'Cold tofu', 3),
('mt-013', 't-matsunoya-001', 'c-drink-001', '緑茶', 'Green Tea', 200, '', '', 1),
('mt-014', 't-matsunoya-001', 'c-drink-001', 'コーラ', 'Cola', 250, '', '', 2),
('mt-015', 't-matsunoya-001', 'c-drink-001', 'ビール', 'Beer', 500, '生ビール中ジョッキ', 'Draft beer (medium)', 3);

-- ============================================================
-- 店舗オーバーライド（渋谷店: 都心価格）
-- ============================================================
INSERT INTO store_menu_overrides (id, store_id, template_id, price, is_hidden, is_sold_out) VALUES
('smo-001', 's-shibuya-001', 'mt-005', 730, 0, 0),
('smo-002', 's-shibuya-001', 'mt-008', 850, 0, 0);

-- ============================================================
-- 店舗限定メニュー（渋谷店限定）
-- ============================================================
INSERT INTO store_local_items (id, store_id, category_id, name, name_en, price, description, description_en, sort_order) VALUES
('sli-001', 's-shibuya-001', 'c-teishoku-001', '渋谷スペシャル定食', 'Shibuya Special Set', 1200, '渋谷店限定の豪華定食', 'Shibuya-exclusive deluxe set meal', 10),
('sli-002', 's-shibuya-001', 'c-drink-001', '抹茶ラテ', 'Matcha Latte', 400, '渋谷店限定', 'Shibuya exclusive', 10);

-- ============================================================
-- テーブル
-- ============================================================
INSERT INTO tables (id, store_id, table_code, capacity) VALUES
('tbl-shib-01', 's-shibuya-001', 'T01', 4),
('tbl-shib-02', 's-shibuya-001', 'T02', 4),
('tbl-shib-03', 's-shibuya-001', 'T03', 2),
('tbl-shib-04', 's-shibuya-001', 'T04', 6),
('tbl-shin-01', 's-shinjuku-001', 'T01', 4),
('tbl-shin-02', 's-shinjuku-001', 'T02', 4),
('tbl-shin-03', 's-shinjuku-001', 'T03', 2);
