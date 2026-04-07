-- migration-d2-places-api-key.sql
-- Google Places API キーをテナントテーブルに追加（owner一元管理）

ALTER TABLE `tenants`
  ADD COLUMN `google_places_api_key` VARCHAR(200) DEFAULT NULL;
