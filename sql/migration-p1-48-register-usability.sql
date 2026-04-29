-- migration-p1-48-register-usability.sql
-- P1-48: レジ利便性向上 (2026-04-29)
--
-- 目的:
--   通常レジで店舗既存の外部決済を記録する際に、控え番号・端末番号などの
--   任意メモを保存できるようにする。
--   また、レジ締め時に翌日共有事項・未処理事項などの引き継ぎメモを構造化して残す。
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、INFORMATION_SCHEMA + PROCEDURE 方式で冪等。

DELIMITER //
DROP PROCEDURE IF EXISTS _mp148_add_register_usability//
CREATE PROCEDURE _mp148_add_register_usability()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND column_name  = 'external_payment_note'
  ) THEN
    ALTER TABLE payments
      ADD COLUMN external_payment_note VARCHAR(120) DEFAULT NULL
                   COMMENT '外部カード端末/QRアプリ等の控え番号・端末番号・任意メモ'
                   AFTER payment_method_detail;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'orders'
       AND column_name  = 'external_payment_note'
  ) THEN
    ALTER TABLE orders
      ADD COLUMN external_payment_note VARCHAR(120) DEFAULT NULL
                   COMMENT '全額会計時の外部決済控えメモ'
                   AFTER payment_method_detail;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'cash_log'
       AND column_name  = 'handover_note'
  ) THEN
    ALTER TABLE cash_log
      ADD COLUMN handover_note VARCHAR(255) DEFAULT NULL
                   COMMENT '閉店時の翌日共有・未処理事項メモ'
                   AFTER reconciliation_note;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND index_name   = 'idx_pay_external_note_check'
  ) THEN
    ALTER TABLE payments
      ADD KEY idx_pay_external_note_check (store_id, payment_method, paid_at);
  END IF;
END//
DELIMITER ;

CALL _mp148_add_register_usability();
DROP PROCEDURE IF EXISTS _mp148_add_register_usability;

SELECT
  SUM(CASE WHEN table_name = 'payments' AND column_name = 'external_payment_note' THEN 1 ELSE 0 END) AS payments_external_note_col,
  SUM(CASE WHEN table_name = 'orders'   AND column_name = 'external_payment_note' THEN 1 ELSE 0 END) AS orders_external_note_col,
  SUM(CASE WHEN table_name = 'cash_log' AND column_name = 'handover_note' THEN 1 ELSE 0 END) AS cash_log_handover_col
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
    (table_name = 'payments' AND column_name = 'external_payment_note')
    OR (table_name = 'orders' AND column_name = 'external_payment_note')
    OR (table_name = 'cash_log' AND column_name = 'handover_note')
  );
