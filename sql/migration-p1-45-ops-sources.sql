-- P1-45: POSLA ops source registry
-- Purpose:
--   Separate POSLA control/source endpoints from customer cell registry.
--   posla_cell_registry is for customer cells. posla_ops_sources is the
--   read-only entrypoint used by codex-ops-platform to discover cells.
-- MySQL 5.7 compatible.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS posla_ops_sources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id VARCHAR(100) NOT NULL,
  label VARCHAR(200) NOT NULL,
  environment VARCHAR(50) NOT NULL DEFAULT 'production',
  status ENUM('active','maintenance','inactive','failed') NOT NULL DEFAULT 'active',
  base_url VARCHAR(255) DEFAULT NULL,
  ping_url VARCHAR(255) DEFAULT NULL,
  snapshot_url VARCHAR(255) DEFAULT NULL,
  auth_type ENUM('ops_read_secret','cron_secret','none') NOT NULL DEFAULT 'ops_read_secret',
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_posla_ops_sources_source_id (source_id),
  KEY idx_posla_ops_sources_status (status, environment),
  KEY idx_posla_ops_sources_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO posla_ops_sources
  (source_id, label, environment, status, base_url, ping_url, snapshot_url, auth_type, notes)
VALUES
  (
    'posla-control-local',
    'POSLA control local',
    'pseudo-prod',
    'active',
    'http://127.0.0.1:8081',
    'http://127.0.0.1:8081/api/monitor/ping.php',
    'http://127.0.0.1:8081/api/monitor/cell-snapshot.php',
    'cron_secret',
    'Local pseudo-production control/source endpoint for codex-ops-platform. Former pseudo-prod-local registry role.'
  )
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  environment = VALUES(environment),
  status = VALUES(status),
  base_url = VALUES(base_url),
  ping_url = VALUES(ping_url),
  snapshot_url = VALUES(snapshot_url),
  auth_type = VALUES(auth_type),
  notes = VALUES(notes),
  updated_at = CURRENT_TIMESTAMP;

DELETE FROM posla_cell_registry
WHERE cell_id = 'pseudo-prod-local'
  AND tenant_id IS NULL
  AND tenant_slug IS NULL;
