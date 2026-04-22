-- migration-pwa4d5a-payment-void.sql
-- PWA Phase 4d-5a: payments の論理取消 (void) カラム追加 (2026-04-21)
--
-- 目的:
--   4d-4a で作成された「手入力計上 payments」(emergency-payment-manual-transfer.php 由来)
--   を論理取消できるようにする。通常会計 payments と注文紐付き emergency transfer 分は
--   API 側でロックして対象外に固定する (4d-5b/c で対応)。
--
-- 追加カラム:
--   - void_status  VARCHAR(20) NOT NULL DEFAULT 'active'
--                  許可値運用: active / voided
--                  ENUM ではなく VARCHAR を採用 (4d-5b/c で状態追加がある想定のため)
--   - voided_at    DATETIME DEFAULT NULL
--   - voided_by    VARCHAR(36) DEFAULT NULL
--   - void_reason  VARCHAR(255) DEFAULT NULL
--
-- 追加 INDEX:
--   - idx_pay_void_status (store_id, void_status, paid_at)
--     → レポート系で「active のみ」を期間フィルタで高速に引けるよう複合
--
-- 重要:
--   - DEFAULT 'active' なので既存 payments は全て active で埋まる (backfill 不要)
--   - refund_status は独立 (gateway 返金用)。void とは責務分離
--   - API 側では「order_ids=[] かつ note LIKE '%emergency_manual_transfer%'」で対象を
--     手入力計上分のみに絞る (4d-5a スコープ)
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、既存 migration と同じ
--   INFORMATION_SCHEMA + PROCEDURE で冪等 (2 回実行しても安全)。

DELIMITER //
DROP PROCEDURE IF EXISTS _mpwa4d5a_add_payment_void//
CREATE PROCEDURE _mpwa4d5a_add_payment_void()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND column_name  = 'void_status'
  ) THEN
    ALTER TABLE payments
      ADD COLUMN void_status VARCHAR(20) NOT NULL DEFAULT 'active'
                   COMMENT 'active / voided (Phase 4d-5a: manual-transfer 分のみ voided 可)'
                   AFTER refunded_by;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND column_name  = 'voided_at'
  ) THEN
    ALTER TABLE payments
      ADD COLUMN voided_at DATETIME DEFAULT NULL AFTER void_status;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND column_name  = 'voided_by'
  ) THEN
    ALTER TABLE payments
      ADD COLUMN voided_by VARCHAR(36) DEFAULT NULL AFTER voided_at;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND column_name  = 'void_reason'
  ) THEN
    ALTER TABLE payments
      ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL AFTER voided_by;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'payments'
       AND index_name   = 'idx_pay_void_status'
  ) THEN
    ALTER TABLE payments
      ADD KEY idx_pay_void_status (store_id, void_status, paid_at);
  END IF;
END//
DELIMITER ;

CALL _mpwa4d5a_add_payment_void();
DROP PROCEDURE IF EXISTS _mpwa4d5a_add_payment_void;

-- 検証用 SELECT (目視用、エラーではない)
SELECT
  COUNT(*) AS total_rows,
  SUM(CASE WHEN void_status = 'active' THEN 1 ELSE 0 END) AS active_rows,
  SUM(CASE WHEN void_status = 'voided' THEN 1 ELSE 0 END) AS voided_rows
  FROM payments;
