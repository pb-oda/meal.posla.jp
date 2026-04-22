-- L-17: tenant 単位 LINE連携設定基盤
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-l17-line-settings.sql

CREATE TABLE IF NOT EXISTS tenant_line_settings (
  tenant_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  channel_access_token text COLLATE utf8mb4_unicode_ci COMMENT 'LINE Messaging API Channel Access Token',
  channel_secret varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'LINE Channel Secret',
  liff_id varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'LINE Front-end Framework ID',
  is_enabled tinyint(1) NOT NULL DEFAULT '0' COMMENT 'LINE連携有効フラグ',
  notify_reservation_created tinyint(1) NOT NULL DEFAULT '0' COMMENT '予約受付完了通知',
  notify_reservation_reminder_day tinyint(1) NOT NULL DEFAULT '0' COMMENT '前日リマインド通知',
  notify_takeout_ready tinyint(1) NOT NULL DEFAULT '0' COMMENT 'テイクアウト準備完了通知',
  last_webhook_at datetime DEFAULT NULL COMMENT '最終 webhook 受信日時',
  last_webhook_event_type varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '最終 webhook の先頭イベント種別',
  last_webhook_event_count int NOT NULL DEFAULT '0' COMMENT '最終 webhook のイベント件数',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id),
  CONSTRAINT tenant_line_settings_ibfk_1 FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
