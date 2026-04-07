-- migration-e2-override-image.sql
-- store_menu_overrides に image_url カラムを追加
-- NULL = テンプレートの画像を継承

ALTER TABLE store_menu_overrides
  ADD COLUMN image_url VARCHAR(500) DEFAULT NULL
  COMMENT '店舗独自の画像（NULLならテンプレート画像を継承）'
  AFTER is_sold_out;
