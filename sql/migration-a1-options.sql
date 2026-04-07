-- ============================================================
-- Migration A-1: オプション/トッピング機能
-- Phase A Sprint 1 — 新規5テーブル
-- ============================================================
-- 実行順序: schema.sql → seed.sql → (本ファイル) → seed-a1-options.sql
-- 冪等: CREATE TABLE IF NOT EXISTS のため再実行可能
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- A-1. option_groups — オプショングループ（テナント単位）
-- 例: 「ごはんサイズ」「トッピング」「抜き」
-- ============================================================
CREATE TABLE IF NOT EXISTS option_groups (
    id              VARCHAR(36) PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    name_en         VARCHAR(100) DEFAULT NULL,
    selection_type  ENUM('single','multi') NOT NULL DEFAULT 'single'
                    COMMENT 'single=ラジオボタン（1つ選択）, multi=チェックボックス（複数選択）',
    min_select      INT NOT NULL DEFAULT 0          COMMENT '最低選択数（0=任意）',
    max_select      INT NOT NULL DEFAULT 1          COMMENT '最大選択数',
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_og_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- A-2. option_choices — オプション選択肢
-- 例: 「大盛り +100円」「ネギ抜き ±0円」
-- ============================================================
CREATE TABLE IF NOT EXISTS option_choices (
    id              VARCHAR(36) PRIMARY KEY,
    group_id        VARCHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    name_en         VARCHAR(100) DEFAULT NULL,
    price_diff      INT NOT NULL DEFAULT 0          COMMENT '価格差分（円）。マイナスも可',
    is_default      TINYINT(1) NOT NULL DEFAULT 0   COMMENT 'デフォルト選択',
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_oc_group (group_id),
    FOREIGN KEY (group_id) REFERENCES option_groups(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- A-3. menu_template_options — テンプレート×オプショングループ M:N
-- 「焼き魚定食」に「ごはんサイズ」と「トッピング」を紐付け
-- ============================================================
CREATE TABLE IF NOT EXISTS menu_template_options (
    template_id     VARCHAR(36) NOT NULL,
    group_id        VARCHAR(36) NOT NULL,
    is_required     TINYINT(1) NOT NULL DEFAULT 0   COMMENT '必須オプションか',
    sort_order      INT NOT NULL DEFAULT 0,

    PRIMARY KEY (template_id, group_id),
    INDEX idx_mto_group (group_id),
    FOREIGN KEY (template_id) REFERENCES menu_templates(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (group_id) REFERENCES option_groups(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- A-4. store_option_overrides — 店舗別オプション上書き
-- 渋谷店だけ「大盛り +150円」に変更、新宿店では「激辛」を非表示、等
-- ============================================================
CREATE TABLE IF NOT EXISTS store_option_overrides (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    choice_id       VARCHAR(36) NOT NULL,
    price_diff      INT DEFAULT NULL                COMMENT 'NULL=本部価格を継承',
    is_available    TINYINT(1) NOT NULL DEFAULT 1   COMMENT '0=この店舗では非表示',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_soo_store_choice (store_id, choice_id),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (choice_id) REFERENCES option_choices(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- A-5. local_item_options — 店舗限定メニュー×オプショングループ M:N
-- ============================================================
CREATE TABLE IF NOT EXISTS local_item_options (
    local_item_id   VARCHAR(36) NOT NULL,
    group_id        VARCHAR(36) NOT NULL,
    is_required     TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      INT NOT NULL DEFAULT 0,

    PRIMARY KEY (local_item_id, group_id),
    INDEX idx_lio_group (group_id),
    FOREIGN KEY (local_item_id) REFERENCES store_local_items(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (group_id) REFERENCES option_groups(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
