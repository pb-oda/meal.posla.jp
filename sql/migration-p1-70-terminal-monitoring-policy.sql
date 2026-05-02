SET NAMES utf8mb4;

-- P1-70: 端末heartbeat監視ポリシー
-- - 未使用端末、監視除外端末、営業時間外の端末を強い異常にしないための任意設定。
-- - 既存の端末は引き続き監視対象(active / monitoring_enabled=1)として扱う。

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

CALL posla_add_column_if_missing('handy_terminals', 'monitoring_enabled',
  'ALTER TABLE handy_terminals ADD COLUMN monitoring_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER terminal_mode');
CALL posla_add_column_if_missing('handy_terminals', 'operational_status',
  'ALTER TABLE handy_terminals ADD COLUMN operational_status ENUM(''active'',''standby'',''unused'',''retired'',''disabled'') NOT NULL DEFAULT ''active'' AFTER monitoring_enabled');
CALL posla_add_column_if_missing('handy_terminals', 'monitor_business_hours_only',
  'ALTER TABLE handy_terminals ADD COLUMN monitor_business_hours_only TINYINT(1) NOT NULL DEFAULT 1 AFTER operational_status');

CALL posla_add_index_if_missing('handy_terminals', 'idx_handy_terminals_monitoring',
  'CREATE INDEX idx_handy_terminals_monitoring ON handy_terminals (tenant_id, store_id, monitoring_enabled, operational_status, last_seen_at)');

DROP PROCEDURE IF EXISTS posla_add_column_if_missing;
DROP PROCEDURE IF EXISTS posla_add_index_if_missing;
