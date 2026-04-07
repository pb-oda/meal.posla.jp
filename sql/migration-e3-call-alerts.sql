-- migration-e3-call-alerts.sql
-- テーブル呼び出しアラート機能

CREATE TABLE IF NOT EXISTS call_alerts (
  id VARCHAR(36) PRIMARY KEY,
  store_id VARCHAR(50) NOT NULL,
  table_id VARCHAR(50) NOT NULL,
  table_code VARCHAR(20) NOT NULL,
  reason VARCHAR(100) DEFAULT 'スタッフ呼び出し',
  status ENUM('pending','acknowledged') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at DATETIME NULL,
  INDEX idx_store_status (store_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
