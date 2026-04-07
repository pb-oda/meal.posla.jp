-- migration-i6-table-merge-split.sql
-- O-2: テーブル合流・分割会計（Merge / Split Payment）
--
-- payments テーブルに合流情報カラム追加
-- order_items に支払追跡カラム追加

-- payments テーブルに合流情報カラム追加
ALTER TABLE payments ADD COLUMN merged_table_ids JSON DEFAULT NULL AFTER table_id;
ALTER TABLE payments ADD COLUMN merged_session_ids JSON DEFAULT NULL AFTER merged_table_ids;

-- order_items に支払追跡カラム追加
ALTER TABLE order_items ADD COLUMN payment_id VARCHAR(36) DEFAULT NULL AFTER order_id;
ALTER TABLE order_items ADD INDEX idx_order_items_payment_id (payment_id);
