-- P1-40: Cell migration ledger
-- Purpose:
--   Track schema migration state per cell DB so migrations can be applied,
--   verified, and rolled back cell-by-cell instead of all tenants at once.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_key VARCHAR(255) NOT NULL,
  checksum_sha256 CHAR(64) DEFAULT NULL,
  status ENUM('applied','failed','rolled_back') NOT NULL DEFAULT 'applied',
  cell_id VARCHAR(100) DEFAULT NULL,
  deploy_version VARCHAR(100) DEFAULT NULL,
  applied_by VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_schema_migration_key (migration_key),
  KEY idx_schema_migrations_cell (cell_id, applied_at),
  KEY idx_schema_migrations_status (status, applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
