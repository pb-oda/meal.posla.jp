-- migration-pwa4c-emergency-payment-resolution.sql
-- PWA Phase 4c-1: 緊急会計への管理者解決フロー (2026-04-20)
--
-- 目的:
--   Phase 4b で「緊急会計台帳」を読み取り専用で実装した。Phase 4c-1 では
--   管理者 (manager / owner) が各緊急会計に対し「有効確認 / 重複扱い / 無効扱い / 保留」
--   の判断を DB に記録できるようにする。
--
-- 追加カラム (emergency_payments):
--   - resolution_status        VARCHAR(30) DEFAULT 'unresolved'
--       unresolved / confirmed / duplicate / rejected / pending
--   - resolution_note          VARCHAR(255) DEFAULT NULL
--   - resolved_by_user_id      VARCHAR(36)  DEFAULT NULL
--   - resolved_by_name         VARCHAR(100) DEFAULT NULL
--   - resolved_at              DATETIME     DEFAULT NULL
--
-- 追加インデックス:
--   - idx_emergency_resolution (tenant_id, store_id, resolution_status, resolved_at)
--   - idx_emergency_resolved_by (resolved_by_user_id)
--
-- 重要な設計方針:
--   - emergency_payments.status (Phase 4a 同期状態: synced / conflict / pending_review / failed) は維持。
--     resolution_status は「管理者判断」として別軸。
--   - 本 migration では payments / orders / order_items / emergency_payment_orders は触らない。
--   - synced_payment_id は Phase 4c-2 以降で payments.id を入れる予定 (今回未使用)。
--
-- MySQL 5.7 対応:
--   ADD COLUMN IF NOT EXISTS は非対応のため、既存 migration-c4-payments.sql と同じ
--   INFORMATION_SCHEMA + ストアドプロシージャで「既にあれば skip」する。
--   mysql クライアントの DELIMITER ディレクティブで構文を通す。

DELIMITER //
DROP PROCEDURE IF EXISTS _mc4c_add_resolution//
CREATE PROCEDURE _mc4c_add_resolution()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'resolution_status'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN resolution_status VARCHAR(30) NOT NULL DEFAULT 'unresolved'
                   COMMENT 'unresolved / confirmed / duplicate / rejected / pending'
                   AFTER synced_payment_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'resolution_note'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN resolution_note VARCHAR(255) DEFAULT NULL AFTER resolution_status;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'resolved_by_user_id'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN resolved_by_user_id VARCHAR(36) DEFAULT NULL AFTER resolution_note;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'resolved_by_name'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN resolved_by_name VARCHAR(100) DEFAULT NULL AFTER resolved_by_user_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND column_name = 'resolved_at'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN resolved_at DATETIME DEFAULT NULL AFTER resolved_by_name;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND index_name = 'idx_emergency_resolution'
  ) THEN
    ALTER TABLE emergency_payments
      ADD KEY idx_emergency_resolution (tenant_id, store_id, resolution_status, resolved_at);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'emergency_payments' AND index_name = 'idx_emergency_resolved_by'
  ) THEN
    ALTER TABLE emergency_payments
      ADD KEY idx_emergency_resolved_by (resolved_by_user_id);
  END IF;
END//
DELIMITER ;

CALL _mc4c_add_resolution();
DROP PROCEDURE IF EXISTS _mc4c_add_resolution;
