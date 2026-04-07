-- migration-g1-audit-log.sql
-- S-2 Phase 1: 監査ログ基盤テーブル
-- 全操作の追跡を可能にする監査ログテーブル

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) DEFAULT NULL,
    user_id VARCHAR(36) NOT NULL,
    username VARCHAR(50) DEFAULT NULL,
    role ENUM('owner','manager','staff') DEFAULT NULL,
    action VARCHAR(50) NOT NULL COMMENT '操作種別: menu_update, staff_create, order_cancel 等',
    entity_type VARCHAR(50) NOT NULL COMMENT '対象種別: menu_item, user, order, settings 等',
    entity_id VARCHAR(36) DEFAULT NULL COMMENT '対象レコードのID',
    old_value JSON DEFAULT NULL COMMENT '変更前の値',
    new_value JSON DEFAULT NULL COMMENT '変更後の値',
    reason TEXT DEFAULT NULL COMMENT '操作理由（キャンセル理由等）',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_store (tenant_id, store_id),
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
