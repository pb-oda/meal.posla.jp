-- S3 #20: schema.sql のカラムコメント文字化け修正
--
-- 背景:
--   schema.sql 内で日本語コメントが Windows-1252 → UTF-8 二重エンコードで
--   壊れて投入されていたカラムを正しい UTF-8 へ戻す。
--
-- 対象:
--   1) user_stores.hourly_rate     COMMENT '時給（円、L-3 シフト管理用）'
--   2) store_settings.default_hourly_rate
--                                   COMMENT '店舗デフォルト時給（円）。NULL=未設定'
--
-- 注意:
--   CLAUDE.md ルールにより schema.sql / 既存マイグレーションは編集禁止。
--   本マイグレーションは新規ファイルとして作成し、本番 DB に適用する。
--   コメントのみの修正であり、データ・スキーマ構造には影響しない。

SET NAMES utf8mb4;

-- (1) user_stores.hourly_rate のコメント正規化
ALTER TABLE `user_stores`
  MODIFY COLUMN `hourly_rate` int DEFAULT NULL
  COMMENT '時給（円、L-3 シフト管理用）';

-- (2) store_settings.default_hourly_rate のコメント正規化
ALTER TABLE `store_settings`
  MODIFY COLUMN `default_hourly_rate` int DEFAULT NULL
  COMMENT '店舗デフォルト時給（円）。NULL=未設定';

-- 検証クエリ:
-- SELECT COLUMN_NAME, COLUMN_COMMENT
--   FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND ((TABLE_NAME = 'user_stores'    AND COLUMN_NAME = 'hourly_rate')
--      OR (TABLE_NAME = 'store_settings' AND COLUMN_NAME = 'default_hourly_rate'));
--
-- Rollback (不要だが記録):
-- ALTER TABLE `user_stores`
--   MODIFY COLUMN `hourly_rate` int DEFAULT NULL COMMENT '';
-- ALTER TABLE `store_settings`
--   MODIFY COLUMN `default_hourly_rate` int DEFAULT NULL COMMENT '';
