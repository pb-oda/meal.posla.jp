SET NAMES utf8mb4;

-- P1-66: テイクアウト顧客導線強化
-- - 顧客の「着きました」連絡を注文単位で保持する。
-- - 既存 orders.status / takeout_ops_status ENUM は変更せず、店側ボードで独立表示する。

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

CALL posla_add_column_if_missing('orders', 'takeout_arrived_at',
  'ALTER TABLE orders ADD COLUMN takeout_arrived_at DATETIME DEFAULT NULL AFTER takeout_ops_updated_by_user_id');
CALL posla_add_column_if_missing('orders', 'takeout_arrival_type',
  'ALTER TABLE orders ADD COLUMN takeout_arrival_type ENUM(''counter'',''curbside'') DEFAULT NULL AFTER takeout_arrived_at');
CALL posla_add_column_if_missing('orders', 'takeout_arrival_note',
  'ALTER TABLE orders ADD COLUMN takeout_arrival_note VARCHAR(255) DEFAULT NULL AFTER takeout_arrival_type');

CALL posla_add_index_if_missing('orders', 'idx_takeout_arrival',
  'CREATE INDEX idx_takeout_arrival ON orders (store_id, order_type, takeout_arrived_at, status)');

DROP PROCEDURE IF EXISTS posla_add_column_if_missing;
DROP PROCEDURE IF EXISTS posla_add_index_if_missing;
