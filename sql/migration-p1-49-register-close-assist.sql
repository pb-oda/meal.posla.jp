-- migration-p1-49-register-close-assist.sql
-- P1-49: レジ締め補助情報の保存 (2026-04-29)
--
-- 目的:
--   レジ締め時の現金枚数カウント、外部決済端末の日計照合、
--   締め前確認チェックを cash_log.close 行に保存する。
--   既存の amount / expected_amount / difference_amount は変更しない。
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、INFORMATION_SCHEMA + PROCEDURE 方式で冪等。

DELIMITER //
DROP PROCEDURE IF EXISTS _mp149_add_register_close_assist//
CREATE PROCEDURE _mp149_add_register_close_assist()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'cash_denomination_json'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN cash_denomination_json TEXT DEFAULT NULL
                   COMMENT 'レジ締め時の金種別枚数カウント(JSON文字列)'
                   AFTER handover_note;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'external_reconciliation_json'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN external_reconciliation_json TEXT DEFAULT NULL
                   COMMENT 'カード/QR等の外部決済端末日計照合(JSON文字列)'
                   AFTER cash_denomination_json;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'close_check_json'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN close_check_json TEXT DEFAULT NULL
                   COMMENT 'レジ締め前確認チェック(JSON文字列)'
                   AFTER external_reconciliation_json;
  END IF;
END//
DELIMITER ;

CALL _mp149_add_register_close_assist();
DROP PROCEDURE IF EXISTS _mp149_add_register_close_assist;

SELECT
  SUM(CASE WHEN column_name = 'cash_denomination_json' THEN 1 ELSE 0 END) AS cash_denomination_col,
  SUM(CASE WHEN column_name = 'external_reconciliation_json' THEN 1 ELSE 0 END) AS external_reconciliation_col,
  SUM(CASE WHEN column_name = 'close_check_json' THEN 1 ELSE 0 END) AS close_check_col
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'cash_log'
  AND column_name IN (
    'cash_denomination_json',
    'external_reconciliation_json',
    'close_check_json'
  );
