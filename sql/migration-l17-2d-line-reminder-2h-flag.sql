-- L-17 Phase 2D: 2時間前リマインド用 LINE 通知フラグを tenant_line_settings に追加
--
-- 既存 notify_reservation_reminder_day (前日) と分離し、24h と 2h を独立 ON/OFF
-- できるようにする。運用上、前日は送るが 2h 直前は送らない / その逆、等の
-- 設定パターンを許容する。既存行には DEFAULT 0 が自動適用されるため、
-- Phase 2D 適用だけでは既存挙動は一切変わらない (default OFF)。
--
-- 実行方法:
--   mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla \
--     < sql/migration-l17-2d-line-reminder-2h-flag.sql
--
-- 冪等性: MySQL 5.7/8.0 は ADD COLUMN IF NOT EXISTS を native サポートしない
-- ため、INFORMATION_SCHEMA.COLUMNS で column 存在をプローブし、未存在の時
-- だけ ALTER を実行する prepared statement で実現する。

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tenant_line_settings'
      AND COLUMN_NAME = 'notify_reservation_reminder_2h'
);

SET @stmt := IF(@col_exists = 0,
    'ALTER TABLE tenant_line_settings
        ADD COLUMN notify_reservation_reminder_2h tinyint(1) NOT NULL DEFAULT 0
          COMMENT ''2時間前リマインド通知 (L-17 Phase 2D)''
          AFTER notify_reservation_reminder_day',
    'SELECT ''notify_reservation_reminder_2h already exists, skip'' AS status'
);

PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
