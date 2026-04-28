-- migration-p1-46-register-payment-detail.sql
-- P1-46: 通常レジの支払い詳細記録 (2026-04-29)
--
-- 目的:
--   通常レジは外部決済端末 / QR / 電子マネーで支払い完了後、POSLA に会計事実を記録する。
--   既存の payment_method ENUM('cash','card','qr') は売上集計互換のため変更せず、
--   ブランド / 種別の詳細だけを payment_method_detail に保存する。
--
-- 例:
--   card + card_credit
--   card + card_debit
--   qr   + qr_paypay
--   qr   + emoney_transport_ic
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、INFORMATION_SCHEMA + PROCEDURE 方式で冪等。

DELIMITER //
DROP PROCEDURE IF EXISTS _mp146_add_register_payment_detail//
CREATE PROCEDURE _mp146_add_register_payment_detail()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND column_name  = 'payment_method_detail'
  ) THEN
    ALTER TABLE payments
      ADD COLUMN payment_method_detail VARCHAR(40) DEFAULT NULL
                   COMMENT '通常レジの支払い詳細。card_credit / qr_paypay / emoney_transport_ic 等'
                   AFTER payment_method;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'orders'
       AND column_name  = 'payment_method_detail'
  ) THEN
    ALTER TABLE orders
      ADD COLUMN payment_method_detail VARCHAR(40) DEFAULT NULL
                   COMMENT '通常レジの支払い詳細。payment_method は互換用大分類'
                   AFTER payment_method;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND index_name   = 'idx_pay_method_detail'
  ) THEN
    ALTER TABLE payments
      ADD KEY idx_pay_method_detail (store_id, payment_method_detail, paid_at);
  END IF;
END//
DELIMITER ;

CALL _mp146_add_register_payment_detail();
DROP PROCEDURE IF EXISTS _mp146_add_register_payment_detail;

SELECT
  SUM(CASE WHEN table_name = 'payments' AND column_name = 'payment_method_detail' THEN 1 ELSE 0 END) AS payments_detail_col,
  SUM(CASE WHEN table_name = 'orders'   AND column_name = 'payment_method_detail' THEN 1 ELSE 0 END) AS orders_detail_col
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('payments', 'orders')
  AND column_name = 'payment_method_detail';
