-- migration-pwa4d2b-payments-external-method-type.sql
-- PWA Phase 4d-2b: 通常 payments テーブルの外部分類カラム (2026-04-20)
--
-- 目的:
--   将来 Phase 4d-3 以降で emergency_payments → payments に other_external を転記する際、
--   「商品券 / 事前振込 / 売掛 / ポイント充当 / その他」の分類情報をロストさせないための列を事前に追加する。
--
-- 重要:
--   Phase 4d-2 ではこのカラムに値は入らない。transfer.php は other_external を 409 で拒否するまま。
--   payments 側の cash / card / qr 行は NULL のまま残る (通常会計は外部分類を持たない)。
--
-- 追加カラム:
--   - external_method_type VARCHAR(30) DEFAULT NULL
--
-- 既存 payments への影響:
--   - DEFAULT NULL なので既存行は影響を受けない。
--   - ENUM('cash','card','qr') の payment_method は触らない (enum 拡張によるロック / 依存 API への影響を回避)。
--
-- MySQL 5.7:
--   INFORMATION_SCHEMA + PROCEDURE で冪等。

DELIMITER //
DROP PROCEDURE IF EXISTS _mpwa4d2b_add_payments_external_method_type//
CREATE PROCEDURE _mpwa4d2b_add_payments_external_method_type()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND column_name  = 'external_method_type'
  ) THEN
    ALTER TABLE payments
      ADD COLUMN external_method_type VARCHAR(30) DEFAULT NULL
                   COMMENT 'other_external 等の外部分類。通常 cash/card/qr では NULL。voucher / bank_transfer / accounts_receivable / point / other'
                   AFTER payment_method;
  END IF;
END//
DELIMITER ;

CALL _mpwa4d2b_add_payments_external_method_type();
DROP PROCEDURE IF EXISTS _mpwa4d2b_add_payments_external_method_type;

-- 検証用 SELECT
SELECT
  COUNT(*)                                            AS total_rows,
  SUM(CASE WHEN external_method_type IS NULL  THEN 1 ELSE 0 END) AS null_rows,
  SUM(CASE WHEN external_method_type IS NOT NULL THEN 1 ELSE 0 END) AS set_rows
  FROM payments;
