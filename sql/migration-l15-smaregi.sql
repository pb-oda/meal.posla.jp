-- L-15: スマレジ連携 DB基盤
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-l15-smaregi.sql

-- 1. tenants テーブルにスマレジ認証カラム追加
ALTER TABLE tenants
  ADD COLUMN smaregi_contract_id VARCHAR(50) DEFAULT NULL
    COMMENT 'スマレジ契約ID' AFTER connect_onboarding_complete,
  ADD COLUMN smaregi_access_token TEXT DEFAULT NULL
    COMMENT 'スマレジ アクセストークン' AFTER smaregi_contract_id,
  ADD COLUMN smaregi_refresh_token TEXT DEFAULT NULL
    COMMENT 'スマレジ リフレッシュトークン' AFTER smaregi_access_token,
  ADD COLUMN smaregi_token_expires_at DATETIME DEFAULT NULL
    COMMENT 'アクセストークン有効期限' AFTER smaregi_refresh_token,
  ADD COLUMN smaregi_connected_at DATETIME DEFAULT NULL
    COMMENT 'スマレジ連携日時' AFTER smaregi_token_expires_at;

-- 2. 店舗マッピングテーブル（新規）
CREATE TABLE IF NOT EXISTS smaregi_store_mapping (
    id              VARCHAR(36) PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    store_id        VARCHAR(36) NOT NULL
      COMMENT 'POSLA店舗ID',
    smaregi_store_id VARCHAR(20) NOT NULL
      COMMENT 'スマレジ店舗ID',
    sync_enabled    TINYINT(1) NOT NULL DEFAULT 1
      COMMENT '注文送信有効フラグ',
    last_menu_sync  DATETIME DEFAULT NULL
      COMMENT '最終メニュー同期日時',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_smaregi_map_store (store_id),
    UNIQUE INDEX idx_smaregi_map_ext (tenant_id, smaregi_store_id),
    INDEX idx_smaregi_map_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. メニューマッピングテーブル（新規）
CREATE TABLE IF NOT EXISTS smaregi_product_mapping (
    id                  VARCHAR(36) PRIMARY KEY,
    tenant_id           VARCHAR(36) NOT NULL,
    menu_template_id    VARCHAR(36) NOT NULL
      COMMENT 'POSLAメニューテンプレートID',
    smaregi_product_id  VARCHAR(20) NOT NULL
      COMMENT 'スマレジ商品ID',
    smaregi_store_id    VARCHAR(20) NOT NULL
      COMMENT 'スマレジ店舗ID',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_smaregi_prod_menu (menu_template_id),
    UNIQUE INDEX idx_smaregi_prod_ext
      (tenant_id, smaregi_product_id, smaregi_store_id),
    INDEX idx_smaregi_prod_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE,
    FOREIGN KEY (menu_template_id) REFERENCES menu_templates(id)
      ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. orders テーブルにスマレジ取引ID追加
ALTER TABLE orders
  ADD COLUMN smaregi_transaction_id VARCHAR(50) DEFAULT NULL
    COMMENT 'スマレジ仮販売の取引ID' AFTER session_token;

-- 5. posla_settings にスマレジ Client ID / Secret 追加
INSERT INTO posla_settings (setting_key, setting_value) VALUES
  ('smaregi_client_id', '142a88853213ca386b90d56c2d385ac3'),
  ('smaregi_client_secret', NULL)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
