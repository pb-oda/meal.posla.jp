-- C-R1: 返金トラッキングカラムを payments テーブルに追加
-- 実行: mysql -u odah_eat-posla -p odah_eat-posla < migration-cr1-refund.sql

ALTER TABLE payments
  ADD COLUMN refund_status ENUM('none','partial','full') NOT NULL DEFAULT 'none' AFTER gateway_status,
  ADD COLUMN refund_amount INT NOT NULL DEFAULT 0 AFTER refund_status,
  ADD COLUMN refund_id VARCHAR(100) DEFAULT NULL AFTER refund_amount,
  ADD COLUMN refund_reason VARCHAR(200) DEFAULT NULL AFTER refund_id,
  ADD COLUMN refunded_at DATETIME DEFAULT NULL AFTER refund_reason,
  ADD COLUMN refunded_by VARCHAR(36) DEFAULT NULL AFTER refunded_at;

-- 検証
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'payments'
   AND COLUMN_NAME LIKE 'refund%'
 ORDER BY ORDINAL_POSITION;
