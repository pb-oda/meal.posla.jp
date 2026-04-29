-- ============================================================
-- P1-56: シフト現場運用強化
-- - 店舗別の持ち場マスタ
-- - シフト交代/欠勤連絡
-- - 公開前チェック用の目標人件費率
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shift_work_positions (
    id          VARCHAR(36) NOT NULL,
    tenant_id   VARCHAR(36) NOT NULL,
    store_id    VARCHAR(36) NOT NULL,
    code        VARCHAR(20) NOT NULL,
    label       VARCHAR(50) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 100,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_shift_work_positions_code (tenant_id, store_id, code),
    KEY idx_shift_work_positions_store (tenant_id, store_id, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO shift_work_positions
    (id, tenant_id, store_id, code, label, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'hall', 'ホール', 1, 10
FROM stores
WHERE is_active = 1;

INSERT IGNORE INTO shift_work_positions
    (id, tenant_id, store_id, code, label, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'kitchen', 'キッチン', 1, 20
FROM stores
WHERE is_active = 1;

CREATE TABLE IF NOT EXISTS shift_swap_requests (
    id                  VARCHAR(36) NOT NULL,
    tenant_id           VARCHAR(36) NOT NULL,
    store_id            VARCHAR(36) NOT NULL,
    shift_assignment_id VARCHAR(36) NOT NULL,
    request_type        ENUM('swap','absence') NOT NULL DEFAULT 'swap',
    requester_user_id   VARCHAR(36) NOT NULL,
    candidate_user_id   VARCHAR(36) NULL,
    replacement_user_id VARCHAR(36) NULL,
    status              ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reason              TEXT NULL,
    response_note       TEXT NULL,
    responded_by        VARCHAR(36) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_shift_swap_store_status (tenant_id, store_id, status, created_at),
    KEY idx_shift_swap_assignment (tenant_id, shift_assignment_id),
    KEY idx_shift_swap_requester (tenant_id, requester_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE shift_settings
    ADD COLUMN target_labor_cost_ratio DECIMAL(5,2) NOT NULL DEFAULT 30.00
    COMMENT '公開前チェックの目標人件費率（%）';
