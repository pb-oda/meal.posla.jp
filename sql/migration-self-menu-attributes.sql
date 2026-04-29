-- ============================================================
-- SELF-MENU-4: セルフメニュー検索/絞り込み用の構造化属性
-- ============================================================
-- 対象:
--   - menu_templates
--   - store_local_items
--
-- 目的:
--   顧客セルフメニューの「辛い」「ベジ」「子ども向け」「早く出る」
--   「品目別提供目安」を、品名キーワードだけに依存せず店舗側で明示設定できるようにする。
--
-- MySQL 5.7 互換のため、INFORMATION_SCHEMA + PROCEDURE で冪等化。
-- ============================================================

SET NAMES utf8mb4;

DELIMITER //

DROP PROCEDURE IF EXISTS _self_menu_attr_patch//
CREATE PROCEDURE _self_menu_attr_patch()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'menu_templates'
       AND COLUMN_NAME = 'spice_level'
  ) THEN
    ALTER TABLE menu_templates
      ADD COLUMN spice_level TINYINT NOT NULL DEFAULT 0 COMMENT '辛さレベル: 0=なし,1=控えめ,2=辛い,3=激辛' AFTER allergens;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'menu_templates'
       AND COLUMN_NAME = 'is_vegetarian'
  ) THEN
    ALTER TABLE menu_templates
      ADD COLUMN is_vegetarian TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ベジ対応フラグ' AFTER spice_level;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'menu_templates'
       AND COLUMN_NAME = 'is_kids_friendly'
  ) THEN
    ALTER TABLE menu_templates
      ADD COLUMN is_kids_friendly TINYINT(1) NOT NULL DEFAULT 0 COMMENT '子ども向けフラグ' AFTER is_vegetarian;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'menu_templates'
       AND COLUMN_NAME = 'is_quick_serve'
  ) THEN
    ALTER TABLE menu_templates
      ADD COLUMN is_quick_serve TINYINT(1) NOT NULL DEFAULT 0 COMMENT '早出し対象フラグ' AFTER is_kids_friendly;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'menu_templates'
       AND COLUMN_NAME = 'prep_time_min'
  ) THEN
    ALTER TABLE menu_templates
      ADD COLUMN prep_time_min INT DEFAULT NULL COMMENT '店舗が明示する提供目安分' AFTER is_quick_serve;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'store_local_items'
       AND COLUMN_NAME = 'spice_level'
  ) THEN
    ALTER TABLE store_local_items
      ADD COLUMN spice_level TINYINT NOT NULL DEFAULT 0 COMMENT '辛さレベル: 0=なし,1=控えめ,2=辛い,3=激辛' AFTER allergens;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'store_local_items'
       AND COLUMN_NAME = 'is_vegetarian'
  ) THEN
    ALTER TABLE store_local_items
      ADD COLUMN is_vegetarian TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ベジ対応フラグ' AFTER spice_level;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'store_local_items'
       AND COLUMN_NAME = 'is_kids_friendly'
  ) THEN
    ALTER TABLE store_local_items
      ADD COLUMN is_kids_friendly TINYINT(1) NOT NULL DEFAULT 0 COMMENT '子ども向けフラグ' AFTER is_vegetarian;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'store_local_items'
       AND COLUMN_NAME = 'is_quick_serve'
  ) THEN
    ALTER TABLE store_local_items
      ADD COLUMN is_quick_serve TINYINT(1) NOT NULL DEFAULT 0 COMMENT '早出し対象フラグ' AFTER is_kids_friendly;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'store_local_items'
       AND COLUMN_NAME = 'prep_time_min'
  ) THEN
    ALTER TABLE store_local_items
      ADD COLUMN prep_time_min INT DEFAULT NULL COMMENT '店舗が明示する提供目安分' AFTER is_quick_serve;
  END IF;
END//

DELIMITER ;

CALL _self_menu_attr_patch();
DROP PROCEDURE IF EXISTS _self_menu_attr_patch;
