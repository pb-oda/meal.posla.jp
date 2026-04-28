-- migration-p1-47-register-close-reconciliation.sql
-- P1-47: レジ締め照合情報の構造化 (2026-04-29)
--
-- 目的:
--   レジ締め時に、実現金額だけでなく、POSLA上の予想現金額・差額・
--   現金/カード/QR電子の売上記録を保存する。
--   既存 cash_log の open / cash_in / cash_out / cash_sale には影響しない。
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、INFORMATION_SCHEMA + PROCEDURE 方式で冪等。

DELIMITER //
DROP PROCEDURE IF EXISTS _mp147_add_register_close_reconciliation//
CREATE PROCEDURE _mp147_add_register_close_reconciliation()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'expected_amount'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN expected_amount INT DEFAULT NULL
                   COMMENT 'レジ締め時のPOSLA予想現金額'
                   AFTER amount;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'difference_amount'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN difference_amount INT DEFAULT NULL
                   COMMENT 'レジ締め時の実現金 - 予想現金'
                   AFTER expected_amount;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'cash_sales_amount'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN cash_sales_amount INT DEFAULT NULL
                   COMMENT 'レジ締め時点の現金売上記録'
                   AFTER difference_amount;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'card_sales_amount'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN card_sales_amount INT DEFAULT NULL
                   COMMENT 'レジ締め時点のカード売上記録'
                   AFTER cash_sales_amount;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'qr_sales_amount'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN qr_sales_amount INT DEFAULT NULL
                   COMMENT 'レジ締め時点のQR/電子マネー売上記録'
                   AFTER card_sales_amount;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'reconciliation_note'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN reconciliation_note VARCHAR(255) DEFAULT NULL
                   COMMENT 'レジ締め差額理由・照合メモ'
                   AFTER note;
  END IF;
END//
DELIMITER ;

CALL _mp147_add_register_close_reconciliation();
DROP PROCEDURE IF EXISTS _mp147_add_register_close_reconciliation;

SELECT
  SUM(CASE WHEN column_name = 'expected_amount' THEN 1 ELSE 0 END) AS expected_col,
  SUM(CASE WHEN column_name = 'difference_amount' THEN 1 ELSE 0 END) AS difference_col,
  SUM(CASE WHEN column_name = 'cash_sales_amount' THEN 1 ELSE 0 END) AS cash_sales_col,
  SUM(CASE WHEN column_name = 'card_sales_amount' THEN 1 ELSE 0 END) AS card_sales_col,
  SUM(CASE WHEN column_name = 'qr_sales_amount' THEN 1 ELSE 0 END) AS qr_sales_col,
  SUM(CASE WHEN column_name = 'reconciliation_note' THEN 1 ELSE 0 END) AS note_col
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'cash_log'
  AND column_name IN (
    'expected_amount',
    'difference_amount',
    'cash_sales_amount',
    'card_sales_amount',
    'qr_sales_amount',
    'reconciliation_note'
  );
