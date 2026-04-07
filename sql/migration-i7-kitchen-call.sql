-- migration-i7-kitchen-call.sql
-- キッチン→スタッフ呼び出し通知用に call_alerts.type ENUM を拡張
-- 既存データ(staff_call, product_ready)に影響なし

ALTER TABLE call_alerts MODIFY COLUMN type ENUM('staff_call','product_ready','kitchen_call') NOT NULL DEFAULT 'staff_call';
