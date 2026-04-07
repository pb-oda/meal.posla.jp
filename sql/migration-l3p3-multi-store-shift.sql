-- ============================================================
-- L-3 Phase 3: マルチ店舗シフト管理
-- shift_help_requests テーブル（ヘルプ要請）
-- shift_help_assignments テーブル（派遣スタッフ中間テーブル）
-- shift_assignments.help_request_id カラム追加
-- plan_features: shift_help_request (enterprise)
-- ============================================================

-- ヘルプ要請テーブル
CREATE TABLE IF NOT EXISTS shift_help_requests (
    id                  VARCHAR(36) NOT NULL,
    tenant_id           VARCHAR(36) NOT NULL,
    from_store_id       VARCHAR(36) NOT NULL COMMENT '要請元（人手不足の店舗）',
    to_store_id         VARCHAR(36) NOT NULL COMMENT '要請先（スタッフを派遣する店舗）',
    requested_date      DATE NOT NULL COMMENT 'ヘルプ希望日',
    start_time          TIME NOT NULL,
    end_time            TIME NOT NULL,
    requested_staff_count INT NOT NULL DEFAULT 1,
    role_hint           VARCHAR(20) NULL COMMENT 'kitchen / hall / NULL',
    status              ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requesting_user_id  VARCHAR(36) NOT NULL COMMENT '依頼者（from_storeのマネージャー）',
    responding_user_id  VARCHAR(36) NULL COMMENT '承認/却下者（to_storeのマネージャー）',
    note                TEXT NULL COMMENT '依頼メモ',
    response_note       TEXT NULL COMMENT '回答メモ',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_shr_tenant (tenant_id),
    INDEX idx_shr_from (tenant_id, from_store_id, status),
    INDEX idx_shr_to (tenant_id, to_store_id, status),
    INDEX idx_shr_date (tenant_id, requested_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 派遣スタッフ中間テーブル
CREATE TABLE IF NOT EXISTS shift_help_assignments (
    id                  VARCHAR(36) NOT NULL,
    help_request_id     VARCHAR(36) NOT NULL,
    user_id             VARCHAR(36) NOT NULL COMMENT '派遣されるスタッフ',
    shift_assignment_id VARCHAR(36) NULL COMMENT '自動作成されたshift_assignmentsのID',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_sha_request (help_request_id),
    INDEX idx_sha_user (user_id),
    FOREIGN KEY (help_request_id) REFERENCES shift_help_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- shift_assignments にヘルプ要請ID追加（NULLable）
ALTER TABLE shift_assignments
    ADD COLUMN help_request_id VARCHAR(36) NULL DEFAULT NULL
    COMMENT 'ヘルプ要請経由で作成された場合の要請ID';

ALTER TABLE shift_assignments
    ADD INDEX idx_sa_help (help_request_id);

-- enterprise プランにヘルプ要請機能を追加
INSERT IGNORE INTO plan_features (plan, feature_key, enabled) VALUES
('enterprise', 'shift_help_request', 1);
