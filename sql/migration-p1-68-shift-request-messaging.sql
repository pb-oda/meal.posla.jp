-- P1-68: Shift request messaging, unread notifications, and candidate consent.
-- Adds request-thread communication without editing schema.sql.

SET NAMES utf8mb4;

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

CALL posla_add_column_if_missing('shift_swap_requests', 'candidate_acceptance_status',
  'ALTER TABLE shift_swap_requests ADD COLUMN candidate_acceptance_status ENUM(''not_required'',''pending'',''accepted'',''declined'') NOT NULL DEFAULT ''not_required'' AFTER candidate_user_id');
CALL posla_add_column_if_missing('shift_swap_requests', 'candidate_responded_at',
  'ALTER TABLE shift_swap_requests ADD COLUMN candidate_responded_at DATETIME DEFAULT NULL AFTER candidate_acceptance_status');
CALL posla_add_column_if_missing('shift_swap_requests', 'candidate_response_note',
  'ALTER TABLE shift_swap_requests ADD COLUMN candidate_response_note TEXT NULL AFTER candidate_responded_at');

CALL posla_add_index_if_missing('shift_swap_requests', 'idx_shift_swap_candidate',
  'CREATE INDEX idx_shift_swap_candidate ON shift_swap_requests (tenant_id, store_id, candidate_user_id, candidate_acceptance_status, status)');

CREATE TABLE IF NOT EXISTS shift_request_messages (
  id VARCHAR(36) NOT NULL,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  request_id VARCHAR(36) NOT NULL,
  sender_user_id VARCHAR(36) NOT NULL,
  message_type ENUM('message','system','reason') NOT NULL DEFAULT 'message',
  message_body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_srm_request_created (tenant_id, store_id, request_id, created_at),
  KEY idx_srm_sender (tenant_id, sender_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shift_request_notifications (
  id VARCHAR(36) NOT NULL,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  request_id VARCHAR(36) NOT NULL,
  user_id VARCHAR(36) NOT NULL,
  notification_type ENUM('message','status','candidate') NOT NULL DEFAULT 'message',
  title VARCHAR(120) NOT NULL,
  body VARCHAR(500) DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_srn_user_unread (tenant_id, store_id, user_id, is_read, created_at),
  KEY idx_srn_request_user (tenant_id, store_id, request_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE shift_swap_requests
SET candidate_acceptance_status = CASE
  WHEN candidate_user_id IS NULL OR candidate_user_id = '' THEN 'not_required'
  ELSE 'accepted'
END
WHERE candidate_acceptance_status = 'not_required';

DROP PROCEDURE IF EXISTS posla_add_column_if_missing;
DROP PROCEDURE IF EXISTS posla_add_index_if_missing;
