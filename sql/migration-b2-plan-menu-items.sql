-- ============================================================
-- Migration B2: plan_menu_items — 食べ放題プラン専用メニュー
-- ============================================================

CREATE TABLE plan_menu_items (
    id              VARCHAR(36) PRIMARY KEY,
    plan_id         VARCHAR(36) NOT NULL        COMMENT 'time_limit_plansのID',
    category_id     VARCHAR(36) DEFAULT NULL     COMMENT 'categoriesのID（カテゴリ分け用）',
    name            VARCHAR(100) NOT NULL        COMMENT '品目名',
    name_en         VARCHAR(100) DEFAULT ''      COMMENT '品目名（英語）',
    description     TEXT DEFAULT NULL,
    image_url       VARCHAR(255) DEFAULT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_pmi_plan (plan_id),
    INDEX idx_pmi_category (category_id),
    FOREIGN KEY (plan_id) REFERENCES time_limit_plans(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
