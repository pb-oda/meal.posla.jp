-- P1-67: Staff home attendance correction requests
-- Adds staff-submitted attendance correction workflow without editing schema.sql.

CREATE TABLE IF NOT EXISTS attendance_correction_requests (
  id VARCHAR(36) NOT NULL,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  user_id VARCHAR(36) NOT NULL,
  attendance_log_id VARCHAR(36) NULL,
  target_date DATE NOT NULL,
  request_type ENUM('clock_in','clock_out','break','note','other') NOT NULL DEFAULT 'other',
  requested_clock_in DATETIME NULL,
  requested_clock_out DATETIME NULL,
  requested_break_minutes INT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  response_note TEXT NULL,
  responded_by VARCHAR(36) NULL,
  responded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_acr_tenant_store_status (tenant_id, store_id, status, created_at),
  KEY idx_acr_user_date (tenant_id, store_id, user_id, target_date),
  KEY idx_acr_attendance_log (attendance_log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
