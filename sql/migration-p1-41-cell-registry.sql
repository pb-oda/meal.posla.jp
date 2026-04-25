-- P1-41: Cell registry / deployment history
-- Purpose:
--   Track POSLA cell deployment targets separately from tenant business data.
--   MVP still runs 1 tenant / 1 cell, but this table lets operations and
--   codex-ops-platform identify app/DB/storage/deploy-version per cell.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS posla_cell_registry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cell_id VARCHAR(100) NOT NULL,
  tenant_id VARCHAR(36) DEFAULT NULL,
  tenant_slug VARCHAR(50) DEFAULT NULL,
  tenant_name VARCHAR(200) DEFAULT NULL,
  environment VARCHAR(50) NOT NULL DEFAULT 'production',
  status ENUM('planned','provisioning','active','maintenance','retired','failed') NOT NULL DEFAULT 'planned',
  app_base_url VARCHAR(255) DEFAULT NULL,
  health_url VARCHAR(255) DEFAULT NULL,
  db_host VARCHAR(255) DEFAULT NULL,
  db_name VARCHAR(100) DEFAULT NULL,
  db_user VARCHAR(100) DEFAULT NULL,
  uploads_path VARCHAR(255) DEFAULT NULL,
  php_image VARCHAR(255) DEFAULT NULL,
  deploy_version VARCHAR(100) DEFAULT NULL,
  cron_enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_ping_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_posla_cell_registry_cell_id (cell_id),
  KEY idx_posla_cell_registry_status (status, environment),
  KEY idx_posla_cell_registry_tenant (tenant_id),
  KEY idx_posla_cell_registry_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posla_cell_deployments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cell_id VARCHAR(100) NOT NULL,
  deploy_version VARCHAR(100) NOT NULL,
  php_image VARCHAR(255) DEFAULT NULL,
  status ENUM('planned','deployed','failed','rolled_back') NOT NULL DEFAULT 'planned',
  deployed_by VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_posla_cell_deployments_cell (cell_id, created_at),
  KEY idx_posla_cell_deployments_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
