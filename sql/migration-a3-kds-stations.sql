-- ============================================================
-- Migration A-3: KDSステーション & ルーティング
-- Phase A Sprint 3
-- ============================================================
-- 冪等: CREATE TABLE IF NOT EXISTS
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- A-3-1. kds_stations — KDSステーション定義（店舗単位）
-- 例: 「厨房」「ドリンク」「デザート」
-- ============================================================
CREATE TABLE IF NOT EXISTS kds_stations (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    name_en         VARCHAR(100) DEFAULT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_ks_store (store_id),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- A-3-2. kds_routing_rules — ステーション×カテゴリ ルーティング
-- 「厨房ステーション」に「定食」「丼もの」「カレー」を表示
-- ============================================================
CREATE TABLE IF NOT EXISTS kds_routing_rules (
    station_id      VARCHAR(36) NOT NULL,
    category_id     VARCHAR(36) NOT NULL,

    PRIMARY KEY (station_id, category_id),
    FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
