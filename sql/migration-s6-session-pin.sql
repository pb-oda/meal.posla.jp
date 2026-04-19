-- S6: セッションPIN（4桁暗証番号）
-- 着席時にスタッフ側で4桁PINを自動生成し、顧客がQRスキャン後にPIN入力を求める
-- これにより、QRコードのURLを知っているだけでは注文できなくなる

ALTER TABLE table_sessions ADD COLUMN session_pin CHAR(4) DEFAULT NULL AFTER memo;

-- 検証: カラム追加確認
-- SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'table_sessions' AND COLUMN_NAME = 'session_pin';

-- Rollback:
-- ALTER TABLE table_sessions DROP COLUMN session_pin;
