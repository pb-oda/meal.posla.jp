-- migration-pwa4c2-emergency-payment-transfer.sql
-- PWA Phase 4c-2: emergency_payments → payments 転記の監査カラム (2026-04-20)
--
-- 目的:
--   Phase 4c-1 で manager / owner が resolution_status='confirmed' を記録できるようになった。
--   Phase 4c-2 では confirmed の緊急会計を明示操作で通常 payments に 1 件ずつ転記する。
--   その監査ログとして「誰が / 何時 / どんなメモで」転記したかを残すカラムを追加する。
--
-- 追加カラム (emergency_payments):
--   - transferred_by_user_id   VARCHAR(36)  DEFAULT NULL
--   - transferred_by_name      VARCHAR(100) DEFAULT NULL
--   - transferred_at           DATETIME     DEFAULT NULL
--   - transfer_note            VARCHAR(255) DEFAULT NULL
--
-- 追加インデックス:
--   - idx_emergency_synced_payment (tenant_id, store_id, synced_payment_id)
--     → 「転記済みを除外」「payment_id から逆引き」で使う
--
-- 重要:
--   - synced_payment_id カラムは Phase 4a migration で既に存在。追加しない。
--   - status / resolution_status は本 migration では触らない。
--   - payments / orders / order_items / schema.sql は一切触らない。
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、既存 Phase 4c-1 migration と同じ
--   INFORMATION_SCHEMA + PROCEDURE 方式で再実行安全にする。

DELIMITER //
DROP PROCEDURE IF EXISTS _mc4c2_add_transfer//
CREATE PROCEDURE _mc4c2_add_transfer()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'transferred_by_user_id'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN transferred_by_user_id VARCHAR(36) DEFAULT NULL
                   COMMENT 'Phase 4c-2: payments 転記を実行した user_id'
                   AFTER synced_payment_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'transferred_by_name'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN transferred_by_name VARCHAR(100) DEFAULT NULL AFTER transferred_by_user_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'transferred_at'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN transferred_at DATETIME DEFAULT NULL AFTER transferred_by_name;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'transfer_note'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN transfer_note VARCHAR(255) DEFAULT NULL AFTER transferred_at;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND index_name = 'idx_emergency_synced_payment'
  ) THEN
    ALTER TABLE emergency_payments
      ADD KEY idx_emergency_synced_payment (tenant_id, store_id, synced_payment_id);
  END IF;
END//
DELIMITER ;

CALL _mc4c2_add_transfer();
DROP PROCEDURE IF EXISTS _mc4c2_add_transfer;
