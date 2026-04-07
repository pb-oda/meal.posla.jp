-- migration-a4-removed-items.sql
-- 検討後キャンセル品目トラッキング用カラム追加
-- 顧客がカートから削除した品目のJSON記録

ALTER TABLE orders
  ADD COLUMN removed_items JSON DEFAULT NULL AFTER items;
