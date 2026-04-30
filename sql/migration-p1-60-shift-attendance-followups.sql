SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shift_attendance_followups (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    shift_assignment_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    followup_date DATE NOT NULL,
    status ENUM('contacted','late_notice','absent') NOT NULL,
    note TEXT NULL,
    created_by VARCHAR(36) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shift_attendance_followup_assignment (tenant_id, store_id, shift_assignment_id),
    KEY idx_shift_attendance_followups_store_date (tenant_id, store_id, followup_date),
    KEY idx_shift_attendance_followups_user_date (tenant_id, user_id, followup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
