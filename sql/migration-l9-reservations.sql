-- L-9 予約管理 (Reservations) — 完全版マイグレーション
-- 2026-04-18 / トレタ + TableCheck 超え仕様
-- 注意: 全テーブル utf8mb4_unicode_ci で統一 (P1-33 教訓)

SET NAMES utf8mb4;

-- ============================================================
-- 1. reservations: 予約本体
-- ============================================================
CREATE TABLE IF NOT EXISTS reservations (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_phone VARCHAR(40) DEFAULT NULL,
  customer_email VARCHAR(190) DEFAULT NULL,
  party_size INT NOT NULL DEFAULT 1,
  reserved_at DATETIME NOT NULL,
  duration_min INT NOT NULL DEFAULT 90,
  status ENUM('pending','confirmed','seated','no_show','cancelled','completed') NOT NULL DEFAULT 'confirmed',
  source ENUM('web','phone','walk_in','google','external','ai_chat') NOT NULL DEFAULT 'web',
  assigned_table_ids JSON DEFAULT NULL,
  course_id VARCHAR(36) DEFAULT NULL,
  course_name VARCHAR(120) DEFAULT NULL,
  memo TEXT DEFAULT NULL,
  tags VARCHAR(255) DEFAULT NULL,
  language VARCHAR(10) DEFAULT 'ja',
  table_session_id VARCHAR(36) DEFAULT NULL,
  customer_id VARCHAR(36) DEFAULT NULL,
  deposit_required TINYINT(1) NOT NULL DEFAULT 0,
  deposit_amount INT NOT NULL DEFAULT 0,
  deposit_status ENUM('not_required','pending','authorized','captured','released','refunded','failed') NOT NULL DEFAULT 'not_required',
  deposit_payment_intent_id VARCHAR(120) DEFAULT NULL,
  deposit_session_id VARCHAR(120) DEFAULT NULL,
  cancel_policy_hours INT DEFAULT NULL,
  cancel_reason VARCHAR(255) DEFAULT NULL,
  reminder_24h_sent_at DATETIME DEFAULT NULL,
  reminder_2h_sent_at DATETIME DEFAULT NULL,
  edit_token VARCHAR(64) DEFAULT NULL,
  created_by_user_id VARCHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  confirmed_at DATETIME DEFAULT NULL,
  seated_at DATETIME DEFAULT NULL,
  cancelled_at DATETIME DEFAULT NULL,
  INDEX idx_store_reserved_at (store_id, reserved_at),
  INDEX idx_tenant_reserved_at (tenant_id, reserved_at),
  INDEX idx_status (store_id, status, reserved_at),
  INDEX idx_customer (customer_id),
  INDEX idx_phone (customer_phone),
  INDEX idx_edit_token (edit_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. reservation_settings: 店舗別設定 (1店舗=1行)
-- ============================================================
CREATE TABLE IF NOT EXISTS reservation_settings (
  store_id VARCHAR(36) NOT NULL PRIMARY KEY,
  online_enabled TINYINT(1) NOT NULL DEFAULT 0,
  lead_time_hours INT NOT NULL DEFAULT 2,
  max_advance_days INT NOT NULL DEFAULT 60,
  default_duration_min INT NOT NULL DEFAULT 90,
  slot_interval_min INT NOT NULL DEFAULT 30,
  max_party_size INT NOT NULL DEFAULT 10,
  min_party_size INT NOT NULL DEFAULT 1,
  open_time TIME DEFAULT '11:00:00',
  close_time TIME DEFAULT '22:00:00',
  last_order_offset_min INT NOT NULL DEFAULT 60,
  weekly_closed_days VARCHAR(20) DEFAULT NULL,
  require_phone TINYINT(1) NOT NULL DEFAULT 1,
  require_email TINYINT(1) NOT NULL DEFAULT 0,
  buffer_before_min INT NOT NULL DEFAULT 0,
  buffer_after_min INT NOT NULL DEFAULT 10,
  notes_to_customer TEXT DEFAULT NULL,
  cancel_deadline_hours INT NOT NULL DEFAULT 3,
  deposit_enabled TINYINT(1) NOT NULL DEFAULT 0,
  deposit_per_person INT NOT NULL DEFAULT 0,
  deposit_min_party_size INT NOT NULL DEFAULT 4,
  reminder_24h_enabled TINYINT(1) NOT NULL DEFAULT 1,
  reminder_2h_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ai_chat_enabled TINYINT(1) NOT NULL DEFAULT 1,
  notification_email VARCHAR(190) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. reservation_holds: 一時在庫確保 (5分TTL、二重予約防止)
-- ============================================================
CREATE TABLE IF NOT EXISTS reservation_holds (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  store_id VARCHAR(36) NOT NULL,
  reserved_at DATETIME NOT NULL,
  party_size INT NOT NULL,
  duration_min INT NOT NULL DEFAULT 90,
  expires_at DATETIME NOT NULL,
  client_fingerprint VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_expires (store_id, expires_at),
  INDEX idx_store_reserved (store_id, reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. reservation_courses: コース予約マスタ
-- ============================================================
CREATE TABLE IF NOT EXISTS reservation_courses (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  store_id VARCHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  name_en VARCHAR(120) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  description_en TEXT DEFAULT NULL,
  price INT NOT NULL DEFAULT 0,
  duration_min INT NOT NULL DEFAULT 90,
  min_party_size INT NOT NULL DEFAULT 1,
  max_party_size INT NOT NULL DEFAULT 10,
  image_url VARCHAR(500) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_active (store_id, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. reservation_customers: 顧客台帳 (リピーター履歴・好み・タグ・ブラックリスト)
-- ============================================================
CREATE TABLE IF NOT EXISTS reservation_customers (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_phone VARCHAR(40) DEFAULT NULL,
  customer_email VARCHAR(190) DEFAULT NULL,
  visit_count INT NOT NULL DEFAULT 0,
  no_show_count INT NOT NULL DEFAULT 0,
  cancel_count INT NOT NULL DEFAULT 0,
  total_spend INT NOT NULL DEFAULT 0,
  last_visit_at DATETIME DEFAULT NULL,
  preferences TEXT DEFAULT NULL,
  allergies TEXT DEFAULT NULL,
  tags VARCHAR(255) DEFAULT NULL,
  is_vip TINYINT(1) NOT NULL DEFAULT 0,
  is_blacklisted TINYINT(1) NOT NULL DEFAULT 0,
  blacklist_reason VARCHAR(255) DEFAULT NULL,
  internal_memo TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_store_phone (store_id, customer_phone),
  INDEX idx_tenant (tenant_id),
  INDEX idx_store_vip (store_id, is_vip),
  INDEX idx_store_blacklist (store_id, is_blacklisted),
  INDEX idx_email (customer_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. reservation_notifications_log: 通知送信ログ
-- ============================================================
CREATE TABLE IF NOT EXISTS reservation_notifications_log (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  reservation_id VARCHAR(36) NOT NULL,
  store_id VARCHAR(36) NOT NULL,
  notification_type ENUM('confirm','reminder_24h','reminder_2h','cancel','no_show','deposit_required','deposit_captured','deposit_refunded') NOT NULL,
  channel ENUM('email','sms','line') NOT NULL DEFAULT 'email',
  recipient VARCHAR(190) NOT NULL,
  subject VARCHAR(255) DEFAULT NULL,
  body_excerpt TEXT DEFAULT NULL,
  status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  error_message VARCHAR(500) DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reservation (reservation_id),
  INDEX idx_store_type (store_id, notification_type, sent_at),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 既存テーブルへの追加 (table_sessions に reservation_id 紐付けカラム追加)
-- ============================================================
ALTER TABLE table_sessions
  ADD COLUMN reservation_id VARCHAR(36) DEFAULT NULL AFTER table_id;
ALTER TABLE table_sessions
  ADD INDEX idx_reservation (reservation_id);
