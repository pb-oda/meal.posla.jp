-- migration-f1-token-expiry.sql
-- セッショントークンに有効期限カラムを追加（QRコード注文テロ対策）

ALTER TABLE tables ADD COLUMN session_token_expires_at DATETIME DEFAULT NULL
  COMMENT 'セッショントークンの有効期限' AFTER session_token;
