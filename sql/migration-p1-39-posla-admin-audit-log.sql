-- ============================================================================
-- P1-39: POSLA管理者向け監査ログテーブル
-- ============================================================================
--
-- 目的:
--   POSLA管理画面で行った共通設定変更の差分と更新者を append-only で残す。
--   tenant 側 audit_log とは責務分離し、POSLA 運営操作専用の履歴台帳とする。
--
-- 用途:
--   - API設定（POSLA共通）の変更履歴表示
--   - 本番前後の設定差分追跡
--   - 「誰が / いつ / どの設定を / どう変えたか」の監査
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS posla_admin_audit_log (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  batch_id VARCHAR(36) DEFAULT NULL COMMENT '同一保存操作を束ねるID',
  admin_id VARCHAR(36) NOT NULL,
  admin_email VARCHAR(255) DEFAULT NULL,
  admin_display_name VARCHAR(100) DEFAULT NULL,
  action VARCHAR(50) NOT NULL COMMENT 'settings_create / settings_update / settings_clear',
  entity_type VARCHAR(50) NOT NULL COMMENT '現状は posla_setting 固定',
  entity_id VARCHAR(100) DEFAULT NULL COMMENT 'setting_key',
  old_value JSON DEFAULT NULL,
  new_value JSON DEFAULT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at),
  INDEX idx_admin (admin_id, created_at),
  INDEX idx_entity (entity_type, entity_id, created_at),
  INDEX idx_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS posla_admin_audit_log_no_update;
DROP TRIGGER IF EXISTS posla_admin_audit_log_no_delete;

DELIMITER $$

CREATE TRIGGER posla_admin_audit_log_no_update
BEFORE UPDATE ON posla_admin_audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'posla_admin_audit_log is append-only: UPDATE denied (P1-39)';
END$$

CREATE TRIGGER posla_admin_audit_log_no_delete
BEFORE DELETE ON posla_admin_audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'posla_admin_audit_log is append-only: DELETE denied (P1-39)';
END$$

DELIMITER ;
