-- ============================================================
-- Multi-Tenant Self-Order & KDS System — Database Schema
-- Phase 1: MySQL 5.7+ (Shared Hosting)
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================
-- 1. tenants — 企業（テナント）
-- ============================================================
CREATE TABLE tenants (
    id              VARCHAR(36) PRIMARY KEY,
    slug            VARCHAR(50) NOT NULL UNIQUE      COMMENT 'URL識別子 (例: matsunoya)',
    name            VARCHAR(200) NOT NULL             COMMENT '企業表示名',
    name_en         VARCHAR(200) DEFAULT NULL,
    plan            ENUM('free','standard','premium') NOT NULL DEFAULT 'standard',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    ai_api_key      VARCHAR(200) DEFAULT NULL        COMMENT 'Gemini APIキー',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. stores — 店舗
-- ============================================================
CREATE TABLE stores (
    id              VARCHAR(36) PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    slug            VARCHAR(50) NOT NULL              COMMENT 'テナント内URL識別子 (例: shibuya)',
    name            VARCHAR(200) NOT NULL,
    name_en         VARCHAR(200) DEFAULT NULL,
    timezone        VARCHAR(50) NOT NULL DEFAULT 'Asia/Tokyo',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_store_tenant_slug (tenant_id, slug),
    INDEX idx_store_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. users — 認証ユーザー
-- ============================================================
CREATE TABLE users (
    id              VARCHAR(36) PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    email           VARCHAR(254) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL             COMMENT 'password_hash() output',
    display_name    VARCHAR(100) NOT NULL DEFAULT '',
    role            ENUM('owner','manager','staff') NOT NULL DEFAULT 'staff',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_user_email (email),
    INDEX idx_user_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. user_stores — ユーザー×店舗 M:N
-- ============================================================
CREATE TABLE user_stores (
    user_id         VARCHAR(36) NOT NULL,
    store_id        VARCHAR(36) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, store_id),
    INDEX idx_us_store (store_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. store_settings — 店舗別設定
-- ============================================================
CREATE TABLE store_settings (
    store_id                VARCHAR(36) PRIMARY KEY,
    max_items_per_order     INT NOT NULL DEFAULT 10,
    max_amount_per_order    INT NOT NULL DEFAULT 30000,
    max_quantity_per_item   INT NOT NULL DEFAULT 5,
    max_toppings_per_item   INT NOT NULL DEFAULT 5,
    rate_limit_orders       INT NOT NULL DEFAULT 3,
    rate_limit_window_min   INT NOT NULL DEFAULT 5,
    day_cutoff_time         TIME NOT NULL DEFAULT '05:00:00',
    default_open_amount     INT NOT NULL DEFAULT 30000,
    overshort_threshold     INT NOT NULL DEFAULT 1000,
    payment_methods_enabled VARCHAR(100) NOT NULL DEFAULT 'cash,card,qr',
    receipt_store_name      VARCHAR(100) DEFAULT NULL,
    receipt_address         VARCHAR(200) DEFAULT NULL,
    receipt_phone           VARCHAR(20)  DEFAULT NULL,
    tax_rate                DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    receipt_footer          VARCHAR(200) DEFAULT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. categories — カテゴリ（テナント単位で共有）
-- ============================================================
CREATE TABLE categories (
    id              VARCHAR(36) PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    name_en         VARCHAR(100) DEFAULT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_cat_tenant (tenant_id),
    INDEX idx_cat_sort (tenant_id, sort_order),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. menu_templates — 本部メニューテンプレート（テナント単位）
-- ============================================================
CREATE TABLE menu_templates (
    id              VARCHAR(36) PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    category_id     VARCHAR(36) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    name_en         VARCHAR(200) DEFAULT NULL,
    base_price      INT NOT NULL                     COMMENT '本部基準価格（税込円）',
    description     TEXT,
    description_en  TEXT,
    image_url       VARCHAR(500) DEFAULT NULL,
    is_sold_out     TINYINT(1) NOT NULL DEFAULT 0    COMMENT '本部レベルの品切れ',
    is_active       TINYINT(1) NOT NULL DEFAULT 1    COMMENT '本部が全店舗で無効化可能',
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_mt_tenant (tenant_id),
    INDEX idx_mt_category (category_id),
    INDEX idx_mt_sort (sort_order),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. store_menu_overrides — 店舗オーバーライド（差分のみ保存）
-- ============================================================
CREATE TABLE store_menu_overrides (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    template_id     VARCHAR(36) NOT NULL,
    price           INT DEFAULT NULL                 COMMENT 'NULL=本部価格を継承',
    is_hidden       TINYINT(1) NOT NULL DEFAULT 0    COMMENT 'この店舗では非表示',
    is_sold_out     TINYINT(1) NOT NULL DEFAULT 0    COMMENT '店舗レベルの品切れ',
    sort_order      INT DEFAULT NULL                 COMMENT 'NULL=テンプレートの順序を継承',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_smo_store_template (store_id, template_id),
    INDEX idx_smo_template (template_id),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (template_id) REFERENCES menu_templates(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. store_local_items — 店舗限定メニュー
-- ============================================================
CREATE TABLE store_local_items (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    category_id     VARCHAR(36) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    name_en         VARCHAR(200) DEFAULT NULL,
    price           INT NOT NULL,
    description     TEXT,
    description_en  TEXT,
    image_url       VARCHAR(500) DEFAULT NULL,
    is_sold_out     TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_sli_store (store_id),
    INDEX idx_sli_category (category_id),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. tables — テーブル（店舗別）
-- ============================================================
CREATE TABLE tables (
    id              VARCHAR(36) PRIMARY KEY           COMMENT 'UUID（グローバル一意）',
    store_id        VARCHAR(36) NOT NULL,
    table_code      VARCHAR(20) NOT NULL              COMMENT '表示コード: T01, T02 等（店舗内UNIQUE）',
    capacity        INT NOT NULL DEFAULT 4            COMMENT '座席数',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    session_token   VARCHAR(64) DEFAULT NULL          COMMENT 'テーブル使用サイクル識別トークン',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_table_store_code (store_id, table_code),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. orders — 注文
-- ============================================================
CREATE TABLE orders (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    table_id        VARCHAR(36) NOT NULL,
    items           JSON NOT NULL,
    total_amount    INT NOT NULL,
    status          ENUM('pending','preparing','ready','served','paid','cancelled') NOT NULL DEFAULT 'pending',
    payment_method  ENUM('cash','card','qr') DEFAULT NULL,
    received_amount INT DEFAULT NULL,
    change_amount   INT DEFAULT NULL,
    idempotency_key VARCHAR(64) DEFAULT NULL,
    session_token   VARCHAR(64) DEFAULT NULL          COMMENT '注文時のテーブルセッション',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    prepared_at     DATETIME DEFAULT NULL,
    ready_at        DATETIME DEFAULT NULL,
    served_at       DATETIME DEFAULT NULL,
    paid_at         DATETIME DEFAULT NULL,

    UNIQUE INDEX idx_idempotency (idempotency_key),
    INDEX idx_order_store (store_id),
    INDEX idx_order_table (table_id),
    INDEX idx_order_status (status),
    INDEX idx_order_store_created (store_id, created_at),
    INDEX idx_order_updated (updated_at),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE CASCADE,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. cash_log — 入出金管理（店舗別）
-- ============================================================
CREATE TABLE cash_log (
    id          VARCHAR(36) PRIMARY KEY,
    store_id    VARCHAR(36) NOT NULL,
    user_id     VARCHAR(36) DEFAULT NULL              COMMENT '操作者',
    type        ENUM('open','close','cash_sale','cash_in','cash_out') NOT NULL,
    amount      INT NOT NULL,
    note        VARCHAR(200) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_cl_store_date (store_id, created_at),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. order_rate_log — レートリミット
-- ============================================================
CREATE TABLE order_rate_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    table_id        VARCHAR(36) NOT NULL,
    session_id      VARCHAR(128) NOT NULL,
    ordered_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_rate_store_table (store_id, table_id, session_id, ordered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
