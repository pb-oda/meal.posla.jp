SET NAMES utf8mb4;

-- P1-65: テイクアウト実運用強化
-- - 受取時間SLA警告
-- - 梱包チェックリスト
-- - 準備完了通知の送信結果
-- - 受取遅れ・キャンセル・返金の運用ステータス
-- - 時間帯別 / 品数別の受付上限制御

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

CALL posla_add_column_if_missing('store_settings', 'takeout_slot_item_capacity',
  'ALTER TABLE store_settings ADD COLUMN takeout_slot_item_capacity INT NOT NULL DEFAULT 0 AFTER takeout_slot_capacity');
CALL posla_add_column_if_missing('store_settings', 'takeout_peak_start_time',
  'ALTER TABLE store_settings ADD COLUMN takeout_peak_start_time TIME DEFAULT NULL AFTER takeout_slot_item_capacity');
CALL posla_add_column_if_missing('store_settings', 'takeout_peak_end_time',
  'ALTER TABLE store_settings ADD COLUMN takeout_peak_end_time TIME DEFAULT NULL AFTER takeout_peak_start_time');
CALL posla_add_column_if_missing('store_settings', 'takeout_peak_slot_capacity',
  'ALTER TABLE store_settings ADD COLUMN takeout_peak_slot_capacity INT NOT NULL DEFAULT 0 AFTER takeout_peak_end_time');
CALL posla_add_column_if_missing('store_settings', 'takeout_peak_slot_item_capacity',
  'ALTER TABLE store_settings ADD COLUMN takeout_peak_slot_item_capacity INT NOT NULL DEFAULT 0 AFTER takeout_peak_slot_capacity');
CALL posla_add_column_if_missing('store_settings', 'takeout_acceptance_delay_minutes',
  'ALTER TABLE store_settings ADD COLUMN takeout_acceptance_delay_minutes INT NOT NULL DEFAULT 0 AFTER takeout_peak_slot_item_capacity');
CALL posla_add_column_if_missing('store_settings', 'takeout_sla_warning_minutes',
  'ALTER TABLE store_settings ADD COLUMN takeout_sla_warning_minutes INT NOT NULL DEFAULT 10 AFTER takeout_acceptance_delay_minutes');

CALL posla_add_column_if_missing('orders', 'takeout_pack_checklist',
  'ALTER TABLE orders ADD COLUMN takeout_pack_checklist JSON DEFAULT NULL AFTER memo');
CALL posla_add_column_if_missing('orders', 'takeout_pack_checked_at',
  'ALTER TABLE orders ADD COLUMN takeout_pack_checked_at DATETIME DEFAULT NULL AFTER takeout_pack_checklist');
CALL posla_add_column_if_missing('orders', 'takeout_pack_checked_by_user_id',
  'ALTER TABLE orders ADD COLUMN takeout_pack_checked_by_user_id VARCHAR(36) DEFAULT NULL AFTER takeout_pack_checked_at');
CALL posla_add_column_if_missing('orders', 'takeout_ready_notified_at',
  'ALTER TABLE orders ADD COLUMN takeout_ready_notified_at DATETIME DEFAULT NULL AFTER takeout_pack_checked_by_user_id');
CALL posla_add_column_if_missing('orders', 'takeout_ready_notification_status',
  'ALTER TABLE orders ADD COLUMN takeout_ready_notification_status ENUM(''not_requested'',''sent'',''failed'',''skipped'') NOT NULL DEFAULT ''not_requested'' AFTER takeout_ready_notified_at');
CALL posla_add_column_if_missing('orders', 'takeout_ready_notification_error',
  'ALTER TABLE orders ADD COLUMN takeout_ready_notification_error VARCHAR(255) DEFAULT NULL AFTER takeout_ready_notification_status');
CALL posla_add_column_if_missing('orders', 'takeout_ops_status',
  'ALTER TABLE orders ADD COLUMN takeout_ops_status ENUM(''normal'',''late_risk'',''late'',''customer_delayed'',''cancel_requested'',''cancelled'',''refund_pending'',''refunded'') NOT NULL DEFAULT ''normal'' AFTER takeout_ready_notification_error');
CALL posla_add_column_if_missing('orders', 'takeout_ops_note',
  'ALTER TABLE orders ADD COLUMN takeout_ops_note VARCHAR(255) DEFAULT NULL AFTER takeout_ops_status');
CALL posla_add_column_if_missing('orders', 'takeout_ops_updated_at',
  'ALTER TABLE orders ADD COLUMN takeout_ops_updated_at DATETIME DEFAULT NULL AFTER takeout_ops_note');
CALL posla_add_column_if_missing('orders', 'takeout_ops_updated_by_user_id',
  'ALTER TABLE orders ADD COLUMN takeout_ops_updated_by_user_id VARCHAR(36) DEFAULT NULL AFTER takeout_ops_updated_at');

CALL posla_add_index_if_missing('orders', 'idx_takeout_ops_status',
  'CREATE INDEX idx_takeout_ops_status ON orders (store_id, order_type, takeout_ops_status, pickup_at)');
CALL posla_add_index_if_missing('orders', 'idx_takeout_pack',
  'CREATE INDEX idx_takeout_pack ON orders (store_id, order_type, takeout_pack_checked_at)');

DROP PROCEDURE IF EXISTS posla_add_column_if_missing;
DROP PROCEDURE IF EXISTS posla_add_index_if_missing;
