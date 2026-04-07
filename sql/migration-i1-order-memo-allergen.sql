-- migration-i1-order-memo-allergen.sql
-- 注文メモ + アレルギー情報カラム追加

-- orders テーブルにメモカラム追加
ALTER TABLE orders ADD COLUMN memo VARCHAR(200) NULL AFTER session_token;

-- order_items テーブルにアレルギー選択カラム追加
ALTER TABLE order_items ADD COLUMN allergen_selections JSON NULL AFTER options;
