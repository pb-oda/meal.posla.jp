SET NAMES utf8mb4;

-- P1-63: 予約変更履歴・キャンセル待ち候補を追加する。

CREATE TABLE IF NOT EXISTS reservation_change_logs (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  reservation_id VARCHAR(36) NOT NULL,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  actor_type ENUM('staff','customer','system') NOT NULL DEFAULT 'staff',
  actor_user_id VARCHAR(36) DEFAULT NULL,
  actor_name VARCHAR(120) DEFAULT NULL,
  action VARCHAR(40) NOT NULL,
  field_name VARCHAR(80) NOT NULL,
  old_value TEXT DEFAULT NULL,
  new_value TEXT DEFAULT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reservation_changed (reservation_id, changed_at),
  INDEX idx_store_changed (store_id, changed_at),
  INDEX idx_actor (actor_user_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservation_waitlist_candidates (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  desired_at DATETIME NOT NULL,
  party_size INT NOT NULL DEFAULT 1,
  customer_name VARCHAR(120) NOT NULL,
  customer_phone VARCHAR(40) DEFAULT NULL,
  customer_email VARCHAR(190) DEFAULT NULL,
  memo TEXT DEFAULT NULL,
  language VARCHAR(10) DEFAULT 'ja',
  source ENUM('web','phone','walk_in','ai_chat') NOT NULL DEFAULT 'web',
  status ENUM('waiting','notified','booked','cancelled','expired') NOT NULL DEFAULT 'waiting',
  notification_count INT NOT NULL DEFAULT 0,
  notified_at DATETIME DEFAULT NULL,
  last_notification_error VARCHAR(255) DEFAULT NULL,
  booked_reservation_id VARCHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_desired (store_id, desired_at, status),
  INDEX idx_store_contact (store_id, customer_phone, customer_email),
  INDEX idx_tenant_status (tenant_id, status, desired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
