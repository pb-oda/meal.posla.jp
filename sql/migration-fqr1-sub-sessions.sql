-- F-QR1: 相席用サブセッション（個別会計用）
-- 実行: mysql -u odah_eat-posla -p odah_eat-posla < migration-fqr1-sub-sessions.sql

CREATE TABLE IF NOT EXISTS table_sub_sessions (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  table_session_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  table_id VARCHAR(36) NOT NULL,
  sub_token VARCHAR(64) NOT NULL,
  label VARCHAR(50) DEFAULT NULL COMMENT 'ゲスト表示名 (A様, B様 等)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME DEFAULT NULL,
  INDEX idx_sub_session_token (sub_token),
  INDEX idx_sub_session_parent (table_session_id),
  INDEX idx_sub_session_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- orders テーブルにサブセッションID カラム追加
ALTER TABLE orders ADD COLUMN sub_session_id VARCHAR(36) DEFAULT NULL AFTER session_token;

-- 検証
SHOW CREATE TABLE table_sub_sessions\G
SELECT COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'sub_session_id';
