-- migration-c4-payments.sql
-- Sprint C-1: 高性能POSレジ — payments テーブル
-- 決済履歴（税率別内訳・支払方法・預かり金/お釣り・個別会計対応）

CREATE TABLE IF NOT EXISTS payments (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    table_id        VARCHAR(36) DEFAULT NULL,
    session_id      VARCHAR(36) DEFAULT NULL,
    order_ids       JSON NOT NULL                   COMMENT '対象注文IDリスト',
    paid_items      JSON DEFAULT NULL               COMMENT '個別会計時の明細 [{name,qty,price,taxRate}]',
    subtotal_10     INT NOT NULL DEFAULT 0          COMMENT '10%税率 税抜小計',
    tax_10          INT NOT NULL DEFAULT 0          COMMENT '10%税額',
    subtotal_8      INT NOT NULL DEFAULT 0          COMMENT '8%税率 税抜小計',
    tax_8           INT NOT NULL DEFAULT 0          COMMENT '8%税額',
    total_amount    INT NOT NULL                    COMMENT '合計（税込）',
    payment_method  ENUM('cash','card','qr') NOT NULL DEFAULT 'cash',
    received_amount INT DEFAULT NULL                COMMENT '預かり金（現金時）',
    change_amount   INT DEFAULT NULL                COMMENT 'お釣り',
    is_partial      TINYINT(1) NOT NULL DEFAULT 0   COMMENT '個別会計フラグ',
    user_id         VARCHAR(36) DEFAULT NULL        COMMENT '操作スタッフ',
    note            VARCHAR(200) DEFAULT NULL,
    paid_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_pay_store_date (store_id, paid_at),
    INDEX idx_pay_table (table_id),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧マイグレーションで作成済の場合: 不足カラムを追加
-- paid_items / is_partial が無ければ追加するプロシージャ
DELIMITER //
DROP PROCEDURE IF EXISTS _mc4_patch//
CREATE PROCEDURE _mc4_patch()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'paid_items'
  ) THEN
    ALTER TABLE payments ADD COLUMN paid_items JSON DEFAULT NULL COMMENT '個別会計時の明細' AFTER order_ids;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'is_partial'
  ) THEN
    ALTER TABLE payments ADD COLUMN is_partial TINYINT(1) NOT NULL DEFAULT 0 COMMENT '個別会計フラグ' AFTER change_amount;
  END IF;
END//
DELIMITER ;

CALL _mc4_patch();
DROP PROCEDURE IF EXISTS _mc4_patch;
