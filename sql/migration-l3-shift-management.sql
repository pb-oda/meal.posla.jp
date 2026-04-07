-- migration-l3-shift-management.sql
-- L-3 シフト管理・勤怠連携
-- 新規テーブル5つ + plan_features追加

-- 1. shift_templates（シフトテンプレート）
-- 繰り返し使う週次パターン（「平日ランチ」「週末ディナー」等）
CREATE TABLE shift_templates (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=日 1=月 ... 6=土',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    required_staff INT NOT NULL DEFAULT 1,
    role_hint VARCHAR(20) NULL COMMENT 'kitchen / hall / NULL',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_st_tenant_store (tenant_id, store_id),
    INDEX idx_st_day (tenant_id, store_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. shift_assignments（シフト割当）
-- 確定したシフト。誰がいつ働くか
CREATE TABLE shift_assignments (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    shift_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_minutes INT NOT NULL DEFAULT 0,
    role_type VARCHAR(20) NULL COMMENT 'kitchen / hall / NULL',
    status ENUM('draft','published','confirmed') NOT NULL DEFAULT 'draft',
    note TEXT NULL,
    created_by VARCHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_sa_tenant_store_date (tenant_id, store_id, shift_date),
    INDEX idx_sa_user_date (tenant_id, user_id, shift_date),
    INDEX idx_sa_status (tenant_id, store_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. shift_availabilities（希望シフト）
-- スタッフが提出する勤務可能時間
CREATE TABLE shift_availabilities (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    target_date DATE NOT NULL,
    availability ENUM('available','preferred','unavailable') NOT NULL,
    preferred_start TIME NULL,
    preferred_end TIME NULL,
    note TEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_avail_unique (tenant_id, store_id, user_id, target_date),
    INDEX idx_avail_store_date (tenant_id, store_id, target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. attendance_logs（勤怠打刻）
-- 出退勤の記録
CREATE TABLE attendance_logs (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    shift_assignment_id VARCHAR(36) NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    break_minutes INT NOT NULL DEFAULT 0,
    status ENUM('working','completed','absent','late') NOT NULL DEFAULT 'working',
    clock_in_method ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    clock_out_method ENUM('manual','auto','timeout') NOT NULL DEFAULT 'manual',
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_att_tenant_store_date (tenant_id, store_id, clock_in),
    INDEX idx_att_user (tenant_id, user_id, clock_in),
    INDEX idx_att_status (tenant_id, store_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. shift_settings（シフト設定）
-- 店舗ごとのシフト運用パラメータ
CREATE TABLE shift_settings (
    store_id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    submission_deadline_day TINYINT NOT NULL DEFAULT 5 COMMENT '毎月N日までに希望提出',
    default_break_minutes INT NOT NULL DEFAULT 60,
    overtime_threshold_minutes INT NOT NULL DEFAULT 480 COMMENT '8h=480分',
    early_clock_in_minutes INT NOT NULL DEFAULT 15 COMMENT '早出打刻許容（分）',
    auto_clock_out_hours INT NOT NULL DEFAULT 12 COMMENT '自動退勤（打刻忘れ対策）',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (store_id),
    INDEX idx_ss_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- plan_features 追加（proプラン以上で利用可能）
INSERT INTO plan_features (plan, feature_key, enabled) VALUES
('pro', 'shift_management', 1),
('enterprise', 'shift_management', 1);
