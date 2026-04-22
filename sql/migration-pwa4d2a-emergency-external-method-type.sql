-- migration-pwa4d2a-emergency-external-method-type.sql
-- PWA Phase 4d-2a: 緊急会計台帳の外部分類カラム (2026-04-20)
--
-- 目的:
--   payment_method='other_external' (商品券 / 事前振込 / 売掛 / ポイント充当 / その他) の詳細を
--   機械可読に保存する。Phase 4d-2 では「記録・台帳表示」のみで、売上転記はしない (4d-3 以降)。
--
-- 許可値 (API 側 allowlist と一致):
--   voucher                商品券
--   bank_transfer          事前振込
--   accounts_receivable    売掛
--   point                  ポイント充当
--   other                  その他
--
-- 設計方針:
--   - DEFAULT NULL を選ぶ。payment_method が cash / external_card / external_qr の行、および
--     未分類の旧 other_external 行は NULL のまま残す。
--   - NULL = 未分類 / 対象外 を明示し、管理者が 4d-3 以降で手動分類できる余地を残す。
--   - backfill なし。旧 other_external レコードは画面上「その他外部（未分類）」表示。
--
-- 追加カラム:
--   - external_method_type VARCHAR(30) DEFAULT NULL
--
-- MySQL 5.7:
--   ADD COLUMN IF NOT EXISTS 非対応のため、INFORMATION_SCHEMA + PROCEDURE 方式で冪等にする。

DELIMITER //
DROP PROCEDURE IF EXISTS _mpwa4d2a_add_emergency_external_method_type//
CREATE PROCEDURE _mpwa4d2a_add_emergency_external_method_type()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'emergency_payments'
       AND column_name  = 'external_method_type'
  ) THEN
    ALTER TABLE emergency_payments
      ADD COLUMN external_method_type VARCHAR(30) DEFAULT NULL
                   COMMENT 'other_external 時のみ設定。voucher / bank_transfer / accounts_receivable / point / other。NULL=未分類または対象外'
                   AFTER payment_method;
  END IF;
END//
DELIMITER ;

CALL _mpwa4d2a_add_emergency_external_method_type();
DROP PROCEDURE IF EXISTS _mpwa4d2a_add_emergency_external_method_type;

-- 検証用 SELECT (目視用、エラーではない)
SELECT
  COUNT(*)                                                                  AS total_rows,
  SUM(CASE WHEN payment_method='other_external'                 THEN 1 ELSE 0 END) AS other_external_rows,
  SUM(CASE WHEN payment_method='other_external' AND external_method_type IS NULL THEN 1 ELSE 0 END) AS other_external_unclassified
  FROM emergency_payments;
