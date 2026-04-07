SET NAMES utf8mb4;

-- ============================================================
-- L-5: 領収書/インボイス
-- ============================================================

-- 1. store_settings にインボイス制度用カラムを追加
ALTER TABLE store_settings
  ADD COLUMN registration_number VARCHAR(20) DEFAULT NULL COMMENT '適格請求書発行事業者登録番号（T+13桁）' AFTER brand_display_name,
  ADD COLUMN business_name VARCHAR(100) DEFAULT NULL COMMENT '事業者正式名称' AFTER registration_number;

-- 2. 領収書発行記録テーブル
CREATE TABLE IF NOT EXISTS receipts (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  store_id        VARCHAR(36) NOT NULL,
  payment_id      VARCHAR(36) NOT NULL COMMENT 'payments テーブルの ID',
  receipt_number  VARCHAR(20) NOT NULL COMMENT '領収書番号（連番: R-YYYYMMDD-NNNN）',
  receipt_type    ENUM('receipt', 'invoice') NOT NULL DEFAULT 'receipt' COMMENT '領収書 or 適格簡易請求書',
  addressee       VARCHAR(100) DEFAULT NULL COMMENT '宛名（会社名・個人名）',
  subtotal_10     INT NOT NULL DEFAULT 0,
  tax_10          INT NOT NULL DEFAULT 0,
  subtotal_8      INT NOT NULL DEFAULT 0,
  tax_8           INT NOT NULL DEFAULT 0,
  total_amount    INT NOT NULL DEFAULT 0,
  pdf_path        VARCHAR(255) DEFAULT NULL COMMENT '生成済みPDFパス',
  issued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_store_date (store_id, issued_at),
  INDEX idx_payment (payment_id),
  UNIQUE KEY uq_receipt_number (store_id, receipt_number),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
