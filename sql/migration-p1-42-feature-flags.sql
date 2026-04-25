-- P1-42: Feature flag control plane
-- Purpose:
--   Keep contract features (tenants.hq_menu_broadcast) separate from
--   operational rollout flags. Overrides are resolved by scope:
--   tenant > cell > global > default.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS posla_feature_flags (
  feature_key VARCHAR(80) NOT NULL,
  label VARCHAR(120) NOT NULL,
  description TEXT DEFAULT NULL,
  default_enabled TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (feature_key),
  KEY idx_posla_feature_flags_active (is_active, feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posla_feature_flag_overrides (
  id VARCHAR(36) NOT NULL,
  feature_key VARCHAR(80) NOT NULL,
  scope_type ENUM('global','cell','tenant') NOT NULL,
  scope_id VARCHAR(100) NOT NULL,
  enabled TINYINT(1) NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  created_by_admin_id VARCHAR(36) DEFAULT NULL,
  updated_by_admin_id VARCHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_posla_feature_flag_scope (feature_key, scope_type, scope_id),
  KEY idx_posla_feature_flag_overrides_scope (scope_type, scope_id),
  KEY idx_posla_feature_flag_overrides_updated (updated_at),
  CONSTRAINT fk_posla_feature_flag_overrides_feature
    FOREIGN KEY (feature_key) REFERENCES posla_feature_flags (feature_key)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO posla_feature_flags
  (feature_key, label, description, default_enabled, is_active)
VALUES
  (
    'support_console_v2',
    'Support Console v2',
    'POSLA管理画面サポート導線の段階展開用。既存サポート導線の契約判定とは分離する。',
    0,
    1
  ),
  (
    'codex_ops_write',
    'codex-ops write actions',
    'codex-ops-platform からの write/deploy 系アクションを許可する前段の運用ゲート。',
    0,
    1
  ),
  (
    'tenant_preview_release',
    'Tenant preview release',
    '特定 tenant/cell だけに先行検証導線を出すためのプレビューリリースゲート。',
    0,
    1
  )
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description),
  default_enabled = VALUES(default_enabled),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;
