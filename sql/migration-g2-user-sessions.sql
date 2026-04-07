-- migration-g2-user-sessions.sql
-- S-3: マルチデバイス同時ログイン制御
-- セッション管理テーブル

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    session_id VARCHAR(128) NOT NULL COMMENT 'PHPセッションID',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    device_label VARCHAR(100) DEFAULT NULL COMMENT '簡易デバイス名: Chrome/Windows等',
    login_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_active_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_user (user_id),
    INDEX idx_session (session_id),
    INDEX idx_active (user_id, is_active),
    UNIQUE KEY uk_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
