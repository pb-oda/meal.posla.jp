-- ============================================================
-- P1-69: シフト希望提出依頼
-- - 店長/副店長から対象期間・期限・文面を指定して一斉依頼
-- - スタッフごとの既読を POSLA 内で管理
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shift_availability_announcements (
    id                 VARCHAR(36) NOT NULL,
    tenant_id          VARCHAR(36) NOT NULL,
    store_id           VARCHAR(36) NOT NULL,
    target_start_date  DATE NOT NULL,
    target_end_date    DATE NOT NULL,
    due_date           DATE NOT NULL,
    title              VARCHAR(120) NOT NULL,
    message            TEXT NULL,
    status             ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
    created_by         VARCHAR(36) NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_shift_avail_announce_store_due (tenant_id, store_id, status, due_date),
    KEY idx_shift_avail_announce_target (tenant_id, store_id, target_start_date, target_end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shift_availability_announcement_reads (
    id               VARCHAR(36) NOT NULL,
    tenant_id        VARCHAR(36) NOT NULL,
    store_id         VARCHAR(36) NOT NULL,
    announcement_id  VARCHAR(36) NOT NULL,
    user_id          VARCHAR(36) NOT NULL,
    read_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_shift_avail_announce_read_user (tenant_id, store_id, announcement_id, user_id),
    KEY idx_shift_avail_announce_read_user (tenant_id, store_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
