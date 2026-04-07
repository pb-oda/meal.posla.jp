-- L-7: Googleレビュー連携用 Place ID カラム追加
-- 実行方法: mysql -u odah_eat-posla -p odah_eat-posla < sql/migration-l7-google-place-id.sql

ALTER TABLE store_settings
  ADD COLUMN google_place_id VARCHAR(100) DEFAULT NULL COMMENT 'Google Place ID（レビュー誘導用）' AFTER receipt_footer;
