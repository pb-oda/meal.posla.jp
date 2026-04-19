-- I-1 運用支援エージェント Phase 1
-- 2026-04-18 / 異常検知 + Slack 通知 + 外部 uptime 対応

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS monitor_events (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  event_type ENUM('php_error','stripe_webhook_fail','api_health','custom') NOT NULL,
  severity ENUM('info','warn','error','critical') NOT NULL DEFAULT 'info',
  source VARCHAR(100) DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  detail TEXT DEFAULT NULL,
  tenant_id VARCHAR(36) DEFAULT NULL,
  store_id VARCHAR(36) DEFAULT NULL,
  notified_slack TINYINT(1) NOT NULL DEFAULT 0,
  notified_email TINYINT(1) NOT NULL DEFAULT 0,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  resolved_at DATETIME DEFAULT NULL,
  resolved_by VARCHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_type_severity (event_type, severity, created_at),
  INDEX idx_created (created_at),
  INDEX idx_resolved (resolved, created_at),
  INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- posla_settings に Slack/運営メール設定を追加 (INSERT ON DUPLICATE で冪等)
INSERT INTO posla_settings (setting_key, setting_value)
VALUES
  ('slack_webhook_url', ''),
  ('ops_notify_email', 'info@posla.jp'),
  ('monitor_cron_secret', CONCAT('cs_', SUBSTRING(MD5(RAND()), 1, 24))),
  ('monitor_last_heartbeat', '')
ON DUPLICATE KEY UPDATE updated_at = NOW();
