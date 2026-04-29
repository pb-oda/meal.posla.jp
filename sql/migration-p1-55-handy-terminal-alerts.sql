-- P1-55: ハンディ端末設定と通知再通知
-- - 端末ごとの表示モード・通知音・再通知間隔を保存する
-- - 通常運用の手順を増やさず、壁掛け/レジ横端末の設定を維持する

CREATE TABLE IF NOT EXISTS handy_terminals (
  id VARCHAR(36) NOT NULL,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  device_uid VARCHAR(80) NOT NULL,
  device_label VARCHAR(80) DEFAULT NULL,
  terminal_mode ENUM('handy','wall','register') NOT NULL DEFAULT 'handy',
  sound_enabled TINYINT(1) NOT NULL DEFAULT 0,
  realert_enabled TINYINT(1) NOT NULL DEFAULT 1,
  realert_interval_sec INT NOT NULL DEFAULT 180,
  last_seen_at DATETIME DEFAULT NULL,
  created_by_user_id VARCHAR(36) DEFAULT NULL,
  updated_by_user_id VARCHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_handy_terminals_store_device (store_id, device_uid),
  KEY idx_handy_terminals_tenant_store (tenant_id, store_id),
  KEY idx_handy_terminals_last_seen (store_id, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
