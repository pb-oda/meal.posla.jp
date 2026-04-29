-- migration-p1-51-register-pre-close-resolution.sql
-- P1-51: 仮締めの調査・解決履歴 (2026-04-29)
--
-- 目的:
--   仮締め差額を管理画面から調査済み/解決済みにできるよう、
--   対応者・対応日時・解決メモを保存する。
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、INFORMATION_SCHEMA + PROCEDURE 方式で冪等。

DELIMITER //
DROP PROCEDURE IF EXISTS _mp151_add_pre_close_resolution//
CREATE PROCEDURE _mp151_add_pre_close_resolution()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'register_pre_close_logs'
       AND column_name  = 'resolved_by'
  ) THEN
    ALTER TABLE register_pre_close_logs
      ADD COLUMN resolved_by VARCHAR(36) DEFAULT NULL
                 COMMENT '仮締め差額を解決済みにしたユーザーID'
                 AFTER status;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'register_pre_close_logs'
       AND column_name  = 'resolved_at'
  ) THEN
    ALTER TABLE register_pre_close_logs
      ADD COLUMN resolved_at DATETIME DEFAULT NULL
                 COMMENT '仮締め差額の解決日時'
                 AFTER resolved_by;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'register_pre_close_logs'
       AND column_name  = 'resolution_note'
  ) THEN
    ALTER TABLE register_pre_close_logs
      ADD COLUMN resolution_note VARCHAR(255) DEFAULT NULL
                 COMMENT '仮締め差額の調査・解決メモ'
                 AFTER resolved_at;
  END IF;
END//
DELIMITER ;

CALL _mp151_add_pre_close_resolution();
DROP PROCEDURE IF EXISTS _mp151_add_pre_close_resolution;

SELECT
  SUM(CASE WHEN column_name = 'resolved_by' THEN 1 ELSE 0 END) AS resolved_by_col,
  SUM(CASE WHEN column_name = 'resolved_at' THEN 1 ELSE 0 END) AS resolved_at_col,
  SUM(CASE WHEN column_name = 'resolution_note' THEN 1 ELSE 0 END) AS resolution_note_col
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'register_pre_close_logs'
  AND column_name IN ('resolved_by', 'resolved_at', 'resolution_note');
