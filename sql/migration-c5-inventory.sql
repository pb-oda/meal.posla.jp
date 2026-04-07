-- migration-c5-inventory.sql
-- Sprint C-2: レシピ連動の自動在庫管理
-- 原材料マスタ + レシピ構成テーブル

-- ── 原材料マスタ ──
CREATE TABLE IF NOT EXISTS ingredients (
    id              VARCHAR(36) PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL              COMMENT '原材料名',
    unit            VARCHAR(20) NOT NULL DEFAULT '個'   COMMENT '単位（g, ml, 個, 枚, etc.）',
    stock_quantity  DECIMAL(10,2) NOT NULL DEFAULT 0   COMMENT '現在庫数（マイナス許容）',
    cost_price      DECIMAL(10,2) NOT NULL DEFAULT 0   COMMENT '原価（単位あたり）',
    low_stock_threshold DECIMAL(10,2) DEFAULT NULL     COMMENT '在庫少アラート閾値',
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_ing_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── レシピ構成（メニュー品目 ↔ 原材料の紐付け） ──
CREATE TABLE IF NOT EXISTS recipes (
    id                  VARCHAR(36) PRIMARY KEY,
    menu_template_id    VARCHAR(36) NOT NULL            COMMENT '対象メニューテンプレートID',
    ingredient_id       VARCHAR(36) NOT NULL            COMMENT '原材料ID',
    quantity            DECIMAL(10,2) NOT NULL DEFAULT 1 COMMENT '1品あたり消費量',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_recipe_menu (menu_template_id),
    INDEX idx_recipe_ingredient (ingredient_id),
    UNIQUE KEY uq_recipe_menu_ing (menu_template_id, ingredient_id),
    FOREIGN KEY (menu_template_id) REFERENCES menu_templates(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 既存DB向けパッチ（low_stock_threshold 追加） ──
DELIMITER //
DROP PROCEDURE IF EXISTS _mc5_patch//
CREATE PROCEDURE _mc5_patch()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ingredients' AND column_name = 'low_stock_threshold'
  ) THEN
    ALTER TABLE ingredients ADD COLUMN low_stock_threshold DECIMAL(10,2) DEFAULT NULL COMMENT '在庫少アラート閾値' AFTER cost_price;
  END IF;
END//
DELIMITER ;

CALL _mc5_patch();
DROP PROCEDURE IF EXISTS _mc5_patch;
