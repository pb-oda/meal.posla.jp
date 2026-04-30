SET NAMES utf8mb4;

-- P1-62: 受付待ち客の呼び出し状態・呼び出し回数を予約レコードに保持する。
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'waitlist_call_status'
);
SET @stmt := IF(@col_exists = 0,
  "ALTER TABLE reservations ADD COLUMN waitlist_call_status ENUM('not_called','called','recalled','absent','seated') NOT NULL DEFAULT 'not_called' AFTER arrival_followup_user_id",
  "SELECT 'waitlist_call_status already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'waitlist_call_count'
);
SET @stmt := IF(@col_exists = 0,
  "ALTER TABLE reservations ADD COLUMN waitlist_call_count INT NOT NULL DEFAULT 0 AFTER waitlist_call_status",
  "SELECT 'waitlist_call_count already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'waitlist_called_at'
);
SET @stmt := IF(@col_exists = 0,
  "ALTER TABLE reservations ADD COLUMN waitlist_called_at DATETIME DEFAULT NULL AFTER waitlist_call_count",
  "SELECT 'waitlist_called_at already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'waitlist_call_user_id'
);
SET @stmt := IF(@col_exists = 0,
  "ALTER TABLE reservations ADD COLUMN waitlist_call_user_id VARCHAR(36) DEFAULT NULL AFTER waitlist_called_at",
  "SELECT 'waitlist_call_user_id already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND INDEX_NAME = 'idx_waitlist_call'
);
SET @stmt := IF(@idx_exists = 0,
  "ALTER TABLE reservations ADD INDEX idx_waitlist_call (store_id, status, waitlist_call_status, created_at)",
  "SELECT 'idx_waitlist_call already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
