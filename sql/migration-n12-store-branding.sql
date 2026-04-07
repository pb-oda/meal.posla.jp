-- N-12: 店舗別セルフメニュー外観カスタマイズ
-- store_settings にブランディング用カラムを追加

ALTER TABLE store_settings
  ADD COLUMN brand_color VARCHAR(7) DEFAULT NULL AFTER google_place_id,
  ADD COLUMN brand_logo_url VARCHAR(500) DEFAULT NULL AFTER brand_color,
  ADD COLUMN brand_display_name VARCHAR(100) DEFAULT NULL AFTER brand_logo_url;
