-- F-DA1: デバイス登録トークン（ワンタイム方式）
-- 実行: mysql -u odah_eat-posla -p odah_eat-posla < migration-fda1-device-tokens.sql

CREATE TABLE IF NOT EXISTS device_registration_tokens (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  token_hash VARCHAR(64) NOT NULL COMMENT 'SHA-256 of plain token',
  display_name VARCHAR(100) NOT NULL,
  visible_tools VARCHAR(100) NOT NULL DEFAULT 'kds',
  is_used TINYINT(1) NOT NULL DEFAULT 0,
  used_by_user_id VARCHAR(36) DEFAULT NULL,
  used_at DATETIME DEFAULT NULL,
  created_by VARCHAR(36) NOT NULL COMMENT 'manager/owner user_id',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uk_token_hash (token_hash),
  INDEX idx_tenant_store (tenant_id, store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 検証
SHOW CREATE TABLE device_registration_tokens\G
