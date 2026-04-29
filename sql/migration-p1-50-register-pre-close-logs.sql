-- migration-p1-50-register-pre-close-logs.sql
-- P1-50: 仮締めログ (2026-04-29)
--
-- 目的:
--   レジを閉めずに、現金差額・外部決済日計・締め前チェック・メモを保存する。
--   cash_log.type は enum のため変更せず、仮締め専用テーブルで安全に追加する。

CREATE TABLE IF NOT EXISTS register_pre_close_logs (
  id VARCHAR(36) NOT NULL,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  user_id VARCHAR(36) DEFAULT NULL,
  business_day DATE NOT NULL,
  actual_cash_amount INT DEFAULT NULL,
  expected_cash_amount INT DEFAULT NULL,
  difference_amount INT DEFAULT NULL,
  cash_sales_amount INT DEFAULT NULL,
  card_sales_amount INT DEFAULT NULL,
  qr_sales_amount INT DEFAULT NULL,
  reconciliation_note VARCHAR(255) DEFAULT NULL,
  handover_note VARCHAR(255) DEFAULT NULL,
  cash_denomination_json TEXT DEFAULT NULL,
  external_reconciliation_json TEXT DEFAULT NULL,
  close_check_json TEXT DEFAULT NULL,
  close_assist_json TEXT DEFAULT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rpcl_store_day (store_id, business_day, created_at),
  KEY idx_rpcl_tenant_day (tenant_id, business_day),
  CONSTRAINT fk_rpcl_store FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE CASCADE,
  CONSTRAINT fk_rpcl_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT
  COUNT(*) AS register_pre_close_logs_table
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'register_pre_close_logs';
