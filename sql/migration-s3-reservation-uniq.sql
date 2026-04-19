-- S3 #7: 二重予約 race-condition 対策
-- 同店舗・同時刻・同電話番号の完全重複申込を DB レベルで弾く
--
-- 注意:
--   - assigned_table_ids が JSON のため、テーブル粒度の UNIQUE は不可。
--     アプリ層で BEGIN + SELECT FOR UPDATE + 空き再計算で並列予約を排除し、
--     この複合 UNIQUE は「同一の客が二重 POST した場合」の最終防衛線として機能する。
--   - customer_phone は NULL 許可なので、NULL の場合は MySQL の UNIQUE 仕様により
--     重複が許される (NULL 同士は等しくないとみなされる)。電話なし予約は
--     アプリ層 (compute_slot_availability + FOR UPDATE) のみで担保する。
--
-- 実行:
--   mysql -u odah_eat-posla -p odah_eat-posla < migration-s3-reservation-uniq.sql

ALTER TABLE reservations
  ADD UNIQUE KEY uniq_store_time_phone (store_id, reserved_at, customer_phone);

-- 検証
SHOW INDEX FROM reservations WHERE Key_name = 'uniq_store_time_phone';
