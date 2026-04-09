-- ============================================================
-- 【P1-27 MIGRATION】Enterprise デモテナント「炭火焼鳥 とりまる」
-- ============================================================
-- 用途: 営業デモ専用。本番環境では実行しないこと。
-- 作成: 2026-04-08
-- 内容: テナント1 + 店舗5 + ユーザー16 + メニュー16 + テーブル50
-- 注文/明細データは scripts/p1-27-generate-torimaru-sample-orders.php で生成
-- パスワード: 全ユーザー Demo1234 (P1-5新ポリシー準拠、bcrypt cost=12)
-- 既存テナント (matsunoya/momonoya) には一切影響しない
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- 1. テナント
-- ============================================================
INSERT INTO tenants (id, slug, name, name_en, plan) VALUES
('t-torimaru-001', 'torimaru', '炭火焼鳥 とりまる', 'Sumibi Yakitori Torimaru', 'enterprise');

-- ============================================================
-- 2. 店舗 (5店舗: 渋谷/新宿/池袋/銀座/恵比寿)
-- ============================================================
INSERT INTO stores (id, tenant_id, slug, name, name_en) VALUES
('s-torimaru-shibuya',    't-torimaru-001', 'shibuya',    'とりまる 渋谷店',   'Torimaru Shibuya'),
('s-torimaru-shinjuku',   't-torimaru-001', 'shinjuku',   'とりまる 新宿店',   'Torimaru Shinjuku'),
('s-torimaru-ikebukuro',  't-torimaru-001', 'ikebukuro',  'とりまる 池袋店',   'Torimaru Ikebukuro'),
('s-torimaru-ginza',      't-torimaru-001', 'ginza',      'とりまる 銀座店',   'Torimaru Ginza'),
('s-torimaru-ebisu',      't-torimaru-001', 'ebisu',      'とりまる 恵比寿店', 'Torimaru Ebisu');

-- ============================================================
-- 3. 店舗設定
-- ============================================================
INSERT INTO store_settings (store_id, receipt_store_name) VALUES
('s-torimaru-shibuya',    'とりまる 渋谷店'),
('s-torimaru-shinjuku',   'とりまる 新宿店'),
('s-torimaru-ikebukuro',  'とりまる 池袋店'),
('s-torimaru-ginza',      'とりまる 銀座店'),
('s-torimaru-ebisu',      'とりまる 恵比寿店');

-- ============================================================
-- 4. ユーザー (16名: owner 1 + manager 5 + staff 10)
-- パスワード hash: password_hash('Demo1234', PASSWORD_DEFAULT) = $2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2
-- ユーザー名規約: torimaru-{store}-mgr / torimaru-{store}-staff{1|2}
-- ============================================================
INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role) VALUES
-- オーナー
('u-torimaru-owner', 't-torimaru-001', 'owner@torimaru.demo',
 'torimaru-owner',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 'とりまるオーナー', 'owner'),

-- マネージャー × 5
('u-torimaru-mgr-shibuya', 't-torimaru-001', 'mgr-shibuya@torimaru.demo',
 'torimaru-shibuya-mgr',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '渋谷店長', 'manager'),
('u-torimaru-mgr-shinjuku', 't-torimaru-001', 'mgr-shinjuku@torimaru.demo',
 'torimaru-shinjuku-mgr',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '新宿店長', 'manager'),
('u-torimaru-mgr-ikebukuro', 't-torimaru-001', 'mgr-ikebukuro@torimaru.demo',
 'torimaru-ikebukuro-mgr',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '池袋店長', 'manager'),
('u-torimaru-mgr-ginza', 't-torimaru-001', 'mgr-ginza@torimaru.demo',
 'torimaru-ginza-mgr',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '銀座店長', 'manager'),
('u-torimaru-mgr-ebisu', 't-torimaru-001', 'mgr-ebisu@torimaru.demo',
 'torimaru-ebisu-mgr',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '恵比寿店長', 'manager'),

-- スタッフ × 10 (各店2名)
('u-torimaru-staff-shibuya-1', 't-torimaru-001', 'staff-shibuya1@torimaru.demo',
 'torimaru-shibuya-staff1',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '渋谷スタッフA', 'staff'),
('u-torimaru-staff-shibuya-2', 't-torimaru-001', 'staff-shibuya2@torimaru.demo',
 'torimaru-shibuya-staff2',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '渋谷スタッフB', 'staff'),
('u-torimaru-staff-shinjuku-1', 't-torimaru-001', 'staff-shinjuku1@torimaru.demo',
 'torimaru-shinjuku-staff1',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '新宿スタッフA', 'staff'),
('u-torimaru-staff-shinjuku-2', 't-torimaru-001', 'staff-shinjuku2@torimaru.demo',
 'torimaru-shinjuku-staff2',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '新宿スタッフB', 'staff'),
('u-torimaru-staff-ikebukuro-1', 't-torimaru-001', 'staff-ikebukuro1@torimaru.demo',
 'torimaru-ikebukuro-staff1',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '池袋スタッフA', 'staff'),
('u-torimaru-staff-ikebukuro-2', 't-torimaru-001', 'staff-ikebukuro2@torimaru.demo',
 'torimaru-ikebukuro-staff2',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '池袋スタッフB', 'staff'),
('u-torimaru-staff-ginza-1', 't-torimaru-001', 'staff-ginza1@torimaru.demo',
 'torimaru-ginza-staff1',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '銀座スタッフA', 'staff'),
('u-torimaru-staff-ginza-2', 't-torimaru-001', 'staff-ginza2@torimaru.demo',
 'torimaru-ginza-staff2',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '銀座スタッフB', 'staff'),
('u-torimaru-staff-ebisu-1', 't-torimaru-001', 'staff-ebisu1@torimaru.demo',
 'torimaru-ebisu-staff1',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '恵比寿スタッフA', 'staff'),
('u-torimaru-staff-ebisu-2', 't-torimaru-001', 'staff-ebisu2@torimaru.demo',
 'torimaru-ebisu-staff2',
 '$2y$12$kWdzBIK1l.GI78Ol4A.Q1.iRDw55pqbx/d9ys/jHSf8VyIecs2HJ2',
 '恵比寿スタッフB', 'staff');

-- ============================================================
-- 5. ユーザー × 店舗 (manager/staff の所属)
-- owner は user_stores 不要 (テナント全店舗にアクセス可能)
-- ============================================================
INSERT INTO user_stores (user_id, store_id) VALUES
-- マネージャー
('u-torimaru-mgr-shibuya',    's-torimaru-shibuya'),
('u-torimaru-mgr-shinjuku',   's-torimaru-shinjuku'),
('u-torimaru-mgr-ikebukuro',  's-torimaru-ikebukuro'),
('u-torimaru-mgr-ginza',      's-torimaru-ginza'),
('u-torimaru-mgr-ebisu',      's-torimaru-ebisu'),
-- スタッフ
('u-torimaru-staff-shibuya-1',    's-torimaru-shibuya'),
('u-torimaru-staff-shibuya-2',    's-torimaru-shibuya'),
('u-torimaru-staff-shinjuku-1',   's-torimaru-shinjuku'),
('u-torimaru-staff-shinjuku-2',   's-torimaru-shinjuku'),
('u-torimaru-staff-ikebukuro-1',  's-torimaru-ikebukuro'),
('u-torimaru-staff-ikebukuro-2',  's-torimaru-ikebukuro'),
('u-torimaru-staff-ginza-1',      's-torimaru-ginza'),
('u-torimaru-staff-ginza-2',      's-torimaru-ginza'),
('u-torimaru-staff-ebisu-1',      's-torimaru-ebisu'),
('u-torimaru-staff-ebisu-2',      's-torimaru-ebisu');

-- ============================================================
-- 6. カテゴリ (3カテゴリ: 焼鳥串/サイドメニュー/ドリンク)
-- ============================================================
INSERT INTO categories (id, tenant_id, name, name_en, sort_order) VALUES
('c-torimaru-yakitori',  't-torimaru-001', '焼鳥串',     'Yakitori', 1),
('c-torimaru-side',      't-torimaru-001', 'サイドメニュー', 'Side',  2),
('c-torimaru-drink',     't-torimaru-001', 'ドリンク',   'Drinks',   3);

-- ============================================================
-- 7. 本部メニューテンプレート (HQメニュー一括配信のデモ用、16品目)
-- 焼鳥串 8品 + サイド 4品 + ドリンク 4品
-- 価格帯に幅を持たせ、ABC分析・銀座 high_price_bias の効果を可視化
-- ============================================================
INSERT INTO menu_templates (id, tenant_id, category_id, name, name_en, base_price, description, description_en, sort_order) VALUES
-- 焼鳥串 (8品: 150円〜480円)
('mt-torimaru-001', 't-torimaru-001', 'c-torimaru-yakitori', '皮',           'Kawa Skewer',      150, 'パリッと焼いた鶏皮',                       'Crispy chicken skin skewer',                  1),
('mt-torimaru-002', 't-torimaru-001', 'c-torimaru-yakitori', 'ねぎま',       'Negima Skewer',    180, '鶏もも肉と長ねぎの定番串',                 'Chicken thigh and leek skewer',               2),
('mt-torimaru-003', 't-torimaru-001', 'c-torimaru-yakitori', 'もも串',       'Momo Skewer',      180, '備長炭で香ばしく焼き上げた鶏もも肉',       'Chicken thigh skewer grilled over binchotan', 3),
('mt-torimaru-004', 't-torimaru-001', 'c-torimaru-yakitori', 'ハツ',         'Heart Skewer',     200, '新鮮な鶏ハツ、コリッとした食感',           'Fresh chicken heart skewer',                  4),
('mt-torimaru-005', 't-torimaru-001', 'c-torimaru-yakitori', '砂肝',         'Gizzard Skewer',   200, '歯ごたえのある鶏砂肝',                     'Chewy chicken gizzard skewer',                5),
('mt-torimaru-006', 't-torimaru-001', 'c-torimaru-yakitori', 'レバー',       'Liver Skewer',     220, '新鮮な鶏レバー、とろける食感',             'Fresh chicken liver skewer',                  6),
('mt-torimaru-007', 't-torimaru-001', 'c-torimaru-yakitori', 'つくね',       'Tsukune',          280, '自家製鶏つくね、卵黄添え',                 'Homemade chicken meatball with egg yolk',     7),
('mt-torimaru-008', 't-torimaru-001', 'c-torimaru-yakitori', '比内地鶏もも', 'Hinai Premium',    480, '秋田比内地鶏のプレミアムもも串',           'Premium Akita Hinai chicken thigh skewer',    8),

-- サイドメニュー (4品: 480円〜1280円)
('mt-torimaru-009', 't-torimaru-001', 'c-torimaru-side', '枝豆',             'Edamame',          480, '塩茹で枝豆',                               'Salted edamame',                              1),
('mt-torimaru-010', 't-torimaru-001', 'c-torimaru-side', '出汁巻き玉子',     'Dashi Tamagoyaki', 580, '出汁の効いた厚焼き玉子',                   'Rolled omelet with dashi',                    2),
('mt-torimaru-011', 't-torimaru-001', 'c-torimaru-side', '鶏唐揚げ',         'Karaage',          780, 'ジューシーな鶏唐揚げ5個',                  'Juicy fried chicken (5 pcs)',                 3),
('mt-torimaru-012', 't-torimaru-001', 'c-torimaru-side', '鶏刺し盛り合わせ', 'Chicken Sashimi', 1280, '新鮮な鶏刺し3種盛り合わせ',                'Fresh chicken sashimi platter (3 kinds)',     4),

-- ドリンク (4品: 380円〜880円)
('mt-torimaru-013', 't-torimaru-001', 'c-torimaru-drink', 'ウーロン茶',       'Oolong Tea',       380, 'ホット/アイス選択可',                      'Hot or iced',                                 1),
('mt-torimaru-014', 't-torimaru-001', 'c-torimaru-drink', 'ハイボール',       'Highball',         480, '角ハイボール',                             'Suntory Kaku highball',                       2),
('mt-torimaru-015', 't-torimaru-001', 'c-torimaru-drink', '生ビール',         'Draft Beer',       580, 'キンキンに冷えた生ビール',                 'Ice-cold draft beer',                         3),
('mt-torimaru-016', 't-torimaru-001', 'c-torimaru-drink', '純米酒',           'Junmai Sake',      880, '厳選蔵元の純米酒（一合）',                 'Premium junmai sake (180ml)',                 4);

-- ============================================================
-- 8. テーブル (各店10卓 × 5店 = 50卓)
-- ============================================================
INSERT INTO tables (id, store_id, table_code, capacity) VALUES
-- 渋谷店
('tbl-torimaru-shibuya-01', 's-torimaru-shibuya', 'T01', 2),
('tbl-torimaru-shibuya-02', 's-torimaru-shibuya', 'T02', 2),
('tbl-torimaru-shibuya-03', 's-torimaru-shibuya', 'T03', 4),
('tbl-torimaru-shibuya-04', 's-torimaru-shibuya', 'T04', 4),
('tbl-torimaru-shibuya-05', 's-torimaru-shibuya', 'T05', 4),
('tbl-torimaru-shibuya-06', 's-torimaru-shibuya', 'T06', 4),
('tbl-torimaru-shibuya-07', 's-torimaru-shibuya', 'T07', 6),
('tbl-torimaru-shibuya-08', 's-torimaru-shibuya', 'T08', 6),
('tbl-torimaru-shibuya-09', 's-torimaru-shibuya', 'T09', 8),
('tbl-torimaru-shibuya-10', 's-torimaru-shibuya', 'T10', 8),
-- 新宿店
('tbl-torimaru-shinjuku-01', 's-torimaru-shinjuku', 'T01', 2),
('tbl-torimaru-shinjuku-02', 's-torimaru-shinjuku', 'T02', 2),
('tbl-torimaru-shinjuku-03', 's-torimaru-shinjuku', 'T03', 4),
('tbl-torimaru-shinjuku-04', 's-torimaru-shinjuku', 'T04', 4),
('tbl-torimaru-shinjuku-05', 's-torimaru-shinjuku', 'T05', 4),
('tbl-torimaru-shinjuku-06', 's-torimaru-shinjuku', 'T06', 4),
('tbl-torimaru-shinjuku-07', 's-torimaru-shinjuku', 'T07', 6),
('tbl-torimaru-shinjuku-08', 's-torimaru-shinjuku', 'T08', 6),
('tbl-torimaru-shinjuku-09', 's-torimaru-shinjuku', 'T09', 8),
('tbl-torimaru-shinjuku-10', 's-torimaru-shinjuku', 'T10', 8),
-- 池袋店
('tbl-torimaru-ikebukuro-01', 's-torimaru-ikebukuro', 'T01', 2),
('tbl-torimaru-ikebukuro-02', 's-torimaru-ikebukuro', 'T02', 2),
('tbl-torimaru-ikebukuro-03', 's-torimaru-ikebukuro', 'T03', 4),
('tbl-torimaru-ikebukuro-04', 's-torimaru-ikebukuro', 'T04', 4),
('tbl-torimaru-ikebukuro-05', 's-torimaru-ikebukuro', 'T05', 4),
('tbl-torimaru-ikebukuro-06', 's-torimaru-ikebukuro', 'T06', 4),
('tbl-torimaru-ikebukuro-07', 's-torimaru-ikebukuro', 'T07', 6),
('tbl-torimaru-ikebukuro-08', 's-torimaru-ikebukuro', 'T08', 6),
('tbl-torimaru-ikebukuro-09', 's-torimaru-ikebukuro', 'T09', 8),
('tbl-torimaru-ikebukuro-10', 's-torimaru-ikebukuro', 'T10', 8),
-- 銀座店
('tbl-torimaru-ginza-01', 's-torimaru-ginza', 'T01', 2),
('tbl-torimaru-ginza-02', 's-torimaru-ginza', 'T02', 2),
('tbl-torimaru-ginza-03', 's-torimaru-ginza', 'T03', 4),
('tbl-torimaru-ginza-04', 's-torimaru-ginza', 'T04', 4),
('tbl-torimaru-ginza-05', 's-torimaru-ginza', 'T05', 4),
('tbl-torimaru-ginza-06', 's-torimaru-ginza', 'T06', 4),
('tbl-torimaru-ginza-07', 's-torimaru-ginza', 'T07', 6),
('tbl-torimaru-ginza-08', 's-torimaru-ginza', 'T08', 6),
('tbl-torimaru-ginza-09', 's-torimaru-ginza', 'T09', 8),
('tbl-torimaru-ginza-10', 's-torimaru-ginza', 'T10', 8),
-- 恵比寿店
('tbl-torimaru-ebisu-01', 's-torimaru-ebisu', 'T01', 2),
('tbl-torimaru-ebisu-02', 's-torimaru-ebisu', 'T02', 2),
('tbl-torimaru-ebisu-03', 's-torimaru-ebisu', 'T03', 4),
('tbl-torimaru-ebisu-04', 's-torimaru-ebisu', 'T04', 4),
('tbl-torimaru-ebisu-05', 's-torimaru-ebisu', 'T05', 4),
('tbl-torimaru-ebisu-06', 's-torimaru-ebisu', 'T06', 4),
('tbl-torimaru-ebisu-07', 's-torimaru-ebisu', 'T07', 6),
('tbl-torimaru-ebisu-08', 's-torimaru-ebisu', 'T08', 6),
('tbl-torimaru-ebisu-09', 's-torimaru-ebisu', 'T09', 8),
('tbl-torimaru-ebisu-10', 's-torimaru-ebisu', 'T10', 8);

-- ============================================================
-- 注: plan_features は L-16 マイグレーションで投入済みのため
--     追加 INSERT 不要 (tenants.plan='enterprise' で自動的に enterprise 機能が有効化される)
-- 注: 注文/明細データは下記コマンドで生成すること
--     php scripts/p1-27-generate-torimaru-sample-orders.php
-- ============================================================
