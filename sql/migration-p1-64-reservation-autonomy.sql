SET NAMES utf8mb4;

-- P1-64: 予約の自動再送・キャンセル待ちロック・高リスク予約金・Web枠ルールを追加する。

DROP PROCEDURE IF EXISTS posla_add_column_if_missing;
DROP PROCEDURE IF EXISTS posla_add_index_if_missing;

DELIMITER //
CREATE PROCEDURE posla_add_column_if_missing(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_ddl TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = p_table
       AND COLUMN_NAME = p_column
  ) THEN
    SET @posla_sql = p_ddl;
    PREPARE posla_stmt FROM @posla_sql;
    EXECUTE posla_stmt;
    DEALLOCATE PREPARE posla_stmt;
  END IF;
END//
CREATE PROCEDURE posla_add_index_if_missing(
  IN p_table VARCHAR(64),
  IN p_index VARCHAR(64),
  IN p_ddl TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = p_table
       AND INDEX_NAME = p_index
  ) THEN
    SET @posla_idx_sql = p_ddl;
    PREPARE posla_idx_stmt FROM @posla_idx_sql;
    EXECUTE posla_idx_stmt;
    DEALLOCATE PREPARE posla_idx_stmt;
  END IF;
END//
DELIMITER ;

CALL posla_add_column_if_missing('reservation_notifications_log', 'retry_count',
  'ALTER TABLE reservation_notifications_log ADD COLUMN retry_count INT NOT NULL DEFAULT 0 AFTER status');
CALL posla_add_column_if_missing('reservation_notifications_log', 'next_retry_at',
  'ALTER TABLE reservation_notifications_log ADD COLUMN next_retry_at DATETIME DEFAULT NULL AFTER retry_count');
CALL posla_add_column_if_missing('reservation_notifications_log', 'manager_attention',
  'ALTER TABLE reservation_notifications_log ADD COLUMN manager_attention TINYINT(1) NOT NULL DEFAULT 0 AFTER next_retry_at');
CALL posla_add_column_if_missing('reservation_notifications_log', 'resolved_at',
  'ALTER TABLE reservation_notifications_log ADD COLUMN resolved_at DATETIME DEFAULT NULL AFTER manager_attention');

CALL posla_add_column_if_missing('reservation_waitlist_candidates', 'hold_id',
  'ALTER TABLE reservation_waitlist_candidates ADD COLUMN hold_id VARCHAR(36) DEFAULT NULL AFTER booked_reservation_id');
CALL posla_add_column_if_missing('reservation_waitlist_candidates', 'hold_expires_at',
  'ALTER TABLE reservation_waitlist_candidates ADD COLUMN hold_expires_at DATETIME DEFAULT NULL AFTER hold_id');

CALL posla_add_column_if_missing('reservation_settings', 'reminder_retry_minutes',
  'ALTER TABLE reservation_settings ADD COLUMN reminder_retry_minutes INT NOT NULL DEFAULT 15 AFTER reminder_2h_enabled');
CALL posla_add_column_if_missing('reservation_settings', 'reminder_retry_max',
  'ALTER TABLE reservation_settings ADD COLUMN reminder_retry_max INT NOT NULL DEFAULT 3 AFTER reminder_retry_minutes');
CALL posla_add_column_if_missing('reservation_settings', 'waitlist_lock_minutes',
  'ALTER TABLE reservation_settings ADD COLUMN waitlist_lock_minutes INT NOT NULL DEFAULT 15 AFTER reminder_retry_max');
CALL posla_add_column_if_missing('reservation_settings', 'high_risk_deposit_enabled',
  'ALTER TABLE reservation_settings ADD COLUMN high_risk_deposit_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER deposit_min_party_size');
CALL posla_add_column_if_missing('reservation_settings', 'high_risk_deposit_min_no_show_count',
  'ALTER TABLE reservation_settings ADD COLUMN high_risk_deposit_min_no_show_count INT NOT NULL DEFAULT 2 AFTER high_risk_deposit_enabled');
CALL posla_add_column_if_missing('reservation_settings', 'high_risk_deposit_large_party_size',
  'ALTER TABLE reservation_settings ADD COLUMN high_risk_deposit_large_party_size INT NOT NULL DEFAULT 8 AFTER high_risk_deposit_min_no_show_count');
CALL posla_add_column_if_missing('reservation_settings', 'web_phone_only_min_party_size',
  'ALTER TABLE reservation_settings ADD COLUMN web_phone_only_min_party_size INT NOT NULL DEFAULT 0 AFTER max_party_size');
CALL posla_add_column_if_missing('reservation_settings', 'web_peak_start_time',
  'ALTER TABLE reservation_settings ADD COLUMN web_peak_start_time TIME DEFAULT NULL AFTER web_phone_only_min_party_size');
CALL posla_add_column_if_missing('reservation_settings', 'web_peak_end_time',
  'ALTER TABLE reservation_settings ADD COLUMN web_peak_end_time TIME DEFAULT NULL AFTER web_peak_start_time');
CALL posla_add_column_if_missing('reservation_settings', 'web_peak_max_groups',
  'ALTER TABLE reservation_settings ADD COLUMN web_peak_max_groups INT NOT NULL DEFAULT 0 AFTER web_peak_end_time');
CALL posla_add_column_if_missing('reservation_settings', 'web_peak_max_covers',
  'ALTER TABLE reservation_settings ADD COLUMN web_peak_max_covers INT NOT NULL DEFAULT 0 AFTER web_peak_max_groups');
CALL posla_add_column_if_missing('reservation_settings', 'web_table_area_filter',
  'ALTER TABLE reservation_settings ADD COLUMN web_table_area_filter VARCHAR(30) DEFAULT NULL AFTER web_peak_max_covers');
CALL posla_add_column_if_missing('reservation_settings', 'sms_enabled',
  'ALTER TABLE reservation_settings ADD COLUMN sms_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER notification_email');
CALL posla_add_column_if_missing('reservation_settings', 'sms_webhook_url',
  'ALTER TABLE reservation_settings ADD COLUMN sms_webhook_url VARCHAR(500) DEFAULT NULL AFTER sms_enabled');

CALL posla_add_index_if_missing('reservation_notifications_log', 'idx_retry_due',
  'CREATE INDEX idx_retry_due ON reservation_notifications_log (status, next_retry_at, manager_attention)');
CALL posla_add_index_if_missing('reservation_waitlist_candidates', 'idx_waitlist_hold',
  'CREATE INDEX idx_waitlist_hold ON reservation_waitlist_candidates (hold_id, hold_expires_at)');

DROP PROCEDURE IF EXISTS posla_add_column_if_missing;
DROP PROCEDURE IF EXISTS posla_add_index_if_missing;
