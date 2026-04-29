-- migration-p1-52-register-close-reminder.sql
-- P1-52: レジ締め忘れ通知設定 (2026-04-29)
--
-- 目的:
--   営業終了後にレジ締めが未完了の場合、管理画面/レジ画面で警告できるよう
--   店舗ごとの締め予定時刻と猶予分を保存する。
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、INFORMATION_SCHEMA + PROCEDURE 方式で冪等。

DELIMITER //
DROP PROCEDURE IF EXISTS _mp152_add_register_close_reminder//
CREATE PROCEDURE _mp152_add_register_close_reminder()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'store_settings'
       AND column_name  = 'register_close_alert_enabled'
  ) THEN
    ALTER TABLE store_settings
      ADD COLUMN register_close_alert_enabled TINYINT(1) NOT NULL DEFAULT 1
                 COMMENT 'レジ締め忘れ警告を有効にする'
                 AFTER overshort_threshold;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'store_settings'
       AND column_name  = 'register_close_time'
  ) THEN
    ALTER TABLE store_settings
      ADD COLUMN register_close_time TIME DEFAULT NULL
                 COMMENT 'レジ締め予定時刻。NULLならラストオーダー+60分を暫定利用'
                 AFTER register_close_alert_enabled;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'store_settings'
       AND column_name  = 'register_close_grace_min'
  ) THEN
    ALTER TABLE store_settings
      ADD COLUMN register_close_grace_min INT NOT NULL DEFAULT 30
                 COMMENT 'レジ締め予定時刻から警告までの猶予分'
                 AFTER register_close_time;
  END IF;
END//
DELIMITER ;

CALL _mp152_add_register_close_reminder();
DROP PROCEDURE IF EXISTS _mp152_add_register_close_reminder;

SELECT
  SUM(CASE WHEN column_name = 'register_close_alert_enabled' THEN 1 ELSE 0 END) AS close_alert_enabled_col,
  SUM(CASE WHEN column_name = 'register_close_time' THEN 1 ELSE 0 END) AS close_time_col,
  SUM(CASE WHEN column_name = 'register_close_grace_min' THEN 1 ELSE 0 END) AS close_grace_col
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'store_settings'
  AND column_name IN (
    'register_close_alert_enabled',
    'register_close_time',
    'register_close_grace_min'
  );
