SET NAMES utf8mb4;

-- P1-61: 予約の遅刻・no-show 対応状態を受付ボードで追跡する。
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'arrival_followup_status'
);
SET @stmt := IF(@col_exists = 0,
  "ALTER TABLE reservations ADD COLUMN arrival_followup_status ENUM('none','contacted','arriving','waiting_reply','no_show_confirmed') NOT NULL DEFAULT 'none' AFTER cancel_reason",
  "SELECT 'arrival_followup_status already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'arrival_followup_note'
);
SET @stmt := IF(@col_exists = 0,
  "ALTER TABLE reservations ADD COLUMN arrival_followup_note VARCHAR(255) DEFAULT NULL AFTER arrival_followup_status",
  "SELECT 'arrival_followup_note already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'arrival_followup_at'
);
SET @stmt := IF(@col_exists = 0,
  "ALTER TABLE reservations ADD COLUMN arrival_followup_at DATETIME DEFAULT NULL AFTER arrival_followup_note",
  "SELECT 'arrival_followup_at already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'arrival_followup_user_id'
);
SET @stmt := IF(@col_exists = 0,
  "ALTER TABLE reservations ADD COLUMN arrival_followup_user_id VARCHAR(36) DEFAULT NULL AFTER arrival_followup_at",
  "SELECT 'arrival_followup_user_id already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND INDEX_NAME = 'idx_arrival_followup'
);
SET @stmt := IF(@idx_exists = 0,
  "ALTER TABLE reservations ADD INDEX idx_arrival_followup (store_id, arrival_followup_status, reserved_at)",
  "SELECT 'idx_arrival_followup already exists, skip' AS status"
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
