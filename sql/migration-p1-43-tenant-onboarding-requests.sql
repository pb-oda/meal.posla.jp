-- P1-43: Tenant onboarding requests for 1 tenant / 1 cell operations
-- Purpose:
--   Record every customer creation path before cell provisioning so op.posla.jp
--   can detect pending customers and guide manual cell creation.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS posla_tenant_onboarding_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(36) NOT NULL,
  request_source ENUM('lp_signup','posla_admin','manual') NOT NULL,
  status ENUM('received','payment_pending','ready_for_cell','cell_provisioning','active','failed','canceled') NOT NULL DEFAULT 'received',
  tenant_id VARCHAR(36) DEFAULT NULL,
  tenant_slug VARCHAR(50) NOT NULL,
  tenant_name VARCHAR(200) NOT NULL,
  store_id VARCHAR(36) DEFAULT NULL,
  store_slug VARCHAR(50) DEFAULT NULL,
  store_name VARCHAR(200) DEFAULT NULL,
  owner_user_id VARCHAR(36) DEFAULT NULL,
  owner_username VARCHAR(50) DEFAULT NULL,
  owner_email VARCHAR(190) DEFAULT NULL,
  owner_display_name VARCHAR(100) DEFAULT NULL,
  requested_store_count INT UNSIGNED NOT NULL DEFAULT 1,
  hq_menu_broadcast TINYINT(1) NOT NULL DEFAULT 0,
  cell_id VARCHAR(100) DEFAULT NULL,
  signup_token_sha256 CHAR(64) DEFAULT NULL,
  stripe_customer_id VARCHAR(100) DEFAULT NULL,
  stripe_subscription_id VARCHAR(100) DEFAULT NULL,
  payload_json TEXT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payment_confirmed_at DATETIME DEFAULT NULL,
  provisioned_at DATETIME DEFAULT NULL,
  activated_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_posla_onboarding_request_id (request_id),
  UNIQUE KEY uniq_posla_onboarding_tenant_id (tenant_id),
  KEY idx_posla_onboarding_status (status, updated_at),
  KEY idx_posla_onboarding_source (request_source, requested_at),
  KEY idx_posla_onboarding_slug (tenant_slug),
  KEY idx_posla_onboarding_cell (cell_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
