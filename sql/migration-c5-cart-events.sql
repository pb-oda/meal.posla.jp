-- migration-c5-cart-events.sql
-- カート操作ログ（迷った品目分析用）

CREATE TABLE IF NOT EXISTS cart_events (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    store_id      VARCHAR(36) NOT NULL,
    table_id      VARCHAR(36) DEFAULT NULL,
    session_token VARCHAR(64) DEFAULT NULL,
    item_id       VARCHAR(36) NOT NULL,
    item_name     VARCHAR(100) NOT NULL,
    item_price    INT NOT NULL DEFAULT 0,
    action        ENUM('add','remove') NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ce_store_date (store_id, created_at),
    INDEX idx_ce_item (item_id, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
