-- ============================================================
-- P1-57: シフト人間中心オペレーション強化
-- - 空きシフト募集
-- - スキル/資格タグ
-- - 開店/閉店/営業中の作業担当
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shift_open_shifts (
    id                    VARCHAR(36) NOT NULL,
    tenant_id             VARCHAR(36) NOT NULL,
    store_id              VARCHAR(36) NOT NULL,
    shift_date            DATE NOT NULL,
    start_time            TIME NOT NULL,
    end_time              TIME NOT NULL,
    break_minutes         INT NOT NULL DEFAULT 0,
    role_type             VARCHAR(20) NULL,
    required_skill_code   VARCHAR(20) NULL,
    status                ENUM('open','filled','cancelled') NOT NULL DEFAULT 'open',
    note                  TEXT NULL,
    created_by            VARCHAR(36) NOT NULL,
    approved_by           VARCHAR(36) NULL,
    created_assignment_id VARCHAR(36) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_shift_open_store_status (tenant_id, store_id, status, shift_date),
    KEY idx_shift_open_date (tenant_id, store_id, shift_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shift_open_shift_applications (
    id             VARCHAR(36) NOT NULL,
    tenant_id      VARCHAR(36) NOT NULL,
    store_id       VARCHAR(36) NOT NULL,
    open_shift_id  VARCHAR(36) NOT NULL,
    user_id        VARCHAR(36) NOT NULL,
    status         ENUM('applied','approved','rejected','cancelled') NOT NULL DEFAULT 'applied',
    note           TEXT NULL,
    response_note  TEXT NULL,
    responded_by   VARCHAR(36) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_shift_open_application (tenant_id, open_shift_id, user_id),
    KEY idx_shift_open_app_store (tenant_id, store_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shift_skill_tags (
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
    UNIQUE KEY uq_shift_skill_code (tenant_id, store_id, code),
    KEY idx_shift_skill_store (tenant_id, store_id, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO shift_skill_tags
    (id, tenant_id, store_id, code, label, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'cashier_close', 'レジ締め可', 1, 10
FROM stores
WHERE is_active = 1;

INSERT IGNORE INTO shift_skill_tags
    (id, tenant_id, store_id, code, label, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'trainer', '新人指導可', 1, 20
FROM stores
WHERE is_active = 1;

INSERT IGNORE INTO shift_skill_tags
    (id, tenant_id, store_id, code, label, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'key_holder', '鍵管理可', 1, 30
FROM stores
WHERE is_active = 1;

CREATE TABLE IF NOT EXISTS shift_staff_skill_tags (
    id          VARCHAR(36) NOT NULL,
    tenant_id   VARCHAR(36) NOT NULL,
    store_id    VARCHAR(36) NOT NULL,
    user_id     VARCHAR(36) NOT NULL,
    skill_code  VARCHAR(20) NOT NULL,
    level       TINYINT NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_shift_staff_skill (tenant_id, store_id, user_id, skill_code),
    KEY idx_shift_staff_skill_store (tenant_id, store_id, skill_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shift_position_required_skills (
    id              VARCHAR(36) NOT NULL,
    tenant_id       VARCHAR(36) NOT NULL,
    store_id        VARCHAR(36) NOT NULL,
    role_type       VARCHAR(20) NOT NULL,
    skill_code      VARCHAR(20) NOT NULL,
    required_count  INT NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_shift_position_skill (tenant_id, store_id, role_type, skill_code),
    KEY idx_shift_position_skill_store (tenant_id, store_id, role_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shift_task_templates (
    id                 VARCHAR(36) NOT NULL,
    tenant_id          VARCHAR(36) NOT NULL,
    store_id           VARCHAR(36) NOT NULL,
    code               VARCHAR(30) NOT NULL,
    label              VARCHAR(80) NOT NULL,
    day_part           ENUM('opening','midday','closing','custom') NOT NULL DEFAULT 'custom',
    default_role_type  VARCHAR(20) NULL,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    sort_order         INT NOT NULL DEFAULT 100,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_shift_task_template_code (tenant_id, store_id, code),
    KEY idx_shift_task_template_store (tenant_id, store_id, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO shift_task_templates
    (id, tenant_id, store_id, code, label, day_part, default_role_type, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'open_register', 'レジ開け', 'opening', 'hall', 1, 10
FROM stores
WHERE is_active = 1;

INSERT IGNORE INTO shift_task_templates
    (id, tenant_id, store_id, code, label, day_part, default_role_type, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'open_prep', '開店前チェック', 'opening', NULL, 1, 20
FROM stores
WHERE is_active = 1;

INSERT IGNORE INTO shift_task_templates
    (id, tenant_id, store_id, code, label, day_part, default_role_type, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'mid_shift_clean', '中間清掃', 'midday', 'hall', 1, 30
FROM stores
WHERE is_active = 1;

INSERT IGNORE INTO shift_task_templates
    (id, tenant_id, store_id, code, label, day_part, default_role_type, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'close_register', 'レジ締め', 'closing', 'hall', 1, 40
FROM stores
WHERE is_active = 1;

INSERT IGNORE INTO shift_task_templates
    (id, tenant_id, store_id, code, label, day_part, default_role_type, is_active, sort_order)
SELECT UUID(), tenant_id, id, 'close_clean', '閉店清掃', 'closing', NULL, 1, 50
FROM stores
WHERE is_active = 1;

CREATE TABLE IF NOT EXISTS shift_task_assignments (
    id                    VARCHAR(36) NOT NULL,
    tenant_id             VARCHAR(36) NOT NULL,
    store_id              VARCHAR(36) NOT NULL,
    task_date             DATE NOT NULL,
    task_template_id      VARCHAR(36) NULL,
    task_label            VARCHAR(80) NOT NULL,
    user_id               VARCHAR(36) NOT NULL,
    shift_assignment_id   VARCHAR(36) NULL,
    status                ENUM('pending','done') NOT NULL DEFAULT 'pending',
    note                  TEXT NULL,
    completed_by          VARCHAR(36) NULL,
    completed_at          DATETIME NULL,
    created_by            VARCHAR(36) NOT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_shift_task_assign_date (tenant_id, store_id, task_date, status),
    KEY idx_shift_task_assign_user (tenant_id, store_id, user_id, task_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
