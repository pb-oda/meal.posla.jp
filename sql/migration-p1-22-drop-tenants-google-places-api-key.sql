-- P1-22: tenants.google_places_api_key カラム DROP
--
-- 背景: P1-6c で AI設定UI を撤去した際に同時に消えた google_places_api_key 入力欄の代替として、
--       POSLA管理画面（posla_settings 経由）に完全移行。
--       api/store/places-proxy.php も P1-22 で posla_settings 参照に変更済み。
-- 確認: 全アプリケーションコードから tenants.google_places_api_key への参照が消えていること。

ALTER TABLE tenants DROP COLUMN google_places_api_key;
