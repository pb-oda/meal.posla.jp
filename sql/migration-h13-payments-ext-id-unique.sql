-- ============================================================================
-- H-13: payments.external_payment_id に UNIQUE 制約を追加
-- ============================================================================
--
-- 目的:
--   Stripe PaymentIntent ID (stripe_connect / stripe_connect_terminal 等) が
--   同じ値で 2 つの payments 行に記録される race 条件を DB レベルで塞ぐ。
--   app 層 (checkout-confirm.php:179-190 / takeout-payment.php:xxx) では
--   既に app 層で early idempotent return するが、TX 外で TOCTOU 窓がある。
--   UNIQUE KEY で DB レベルの二重挿入を完全阻止する。
--
-- 現物確認 (2026-04-22):
--   SELECT COUNT(*) FROM payments WHERE external_payment_id IS NOT NULL;        -- 17
--   SELECT COUNT(DISTINCT external_payment_id) FROM payments
--     WHERE external_payment_id IS NOT NULL;                                    -- 17
--   → duplicate 0 件確認済、UNIQUE 追加可能
--
-- MySQL UNIQUE KEY の NULL 挙動:
--   MySQL (InnoDB) は UNIQUE KEY のカラムが NULL の場合、複数 NULL を許容する
--   (NULL != NULL 評価)。したがって cash / 手動 card / qr など external_payment_id
--   が NULL の 100 件は影響を受けない。重複チェックは NOT NULL 値の間でのみ行う。
--
-- rollback:
--   ALTER TABLE payments DROP INDEX uq_external_payment_id;
--
-- 2026-04-22 (H-13)
-- ============================================================================

-- 既存 UNIQUE 制約があれば先に削除 (再適用安全化)
-- information_schema で制約の存在確認してから ALTER
SET @has_uniq := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'payments'
       AND INDEX_NAME = 'uq_external_payment_id'
       AND NON_UNIQUE = 0
);
SET @sql := IF(@has_uniq > 0,
    'ALTER TABLE payments DROP INDEX uq_external_payment_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- UNIQUE 追加
ALTER TABLE payments
    ADD UNIQUE KEY uq_external_payment_id (external_payment_id);

-- ============================================================================
-- 検証 SQL (migration 適用後に実行推奨):
--
-- 1. UNIQUE index が追加されたか確認
-- SHOW INDEX FROM payments WHERE Key_name = 'uq_external_payment_id';
--
-- 2. 既存 17 行で duplicate error が発生していないことを確認
-- SELECT external_payment_id, COUNT(*) FROM payments
--   WHERE external_payment_id IS NOT NULL
--   GROUP BY external_payment_id HAVING COUNT(*) > 1;
--   -- 期待: 0 rows (duplicate なし)
--
-- 3. 同じ external_payment_id で 2 回 INSERT を試行して UNIQUE violation を確認
-- INSERT INTO payments (id, store_id, external_payment_id, order_ids, total_amount)
--   VALUES (UUID(), 'test-store', 'pi_TEST_UNIQ', '[]', 100);
-- INSERT INTO payments (id, store_id, external_payment_id, order_ids, total_amount)
--   VALUES (UUID(), 'test-store', 'pi_TEST_UNIQ', '[]', 100);
--   -- 期待: ERROR 1062 (23000): Duplicate entry 'pi_TEST_UNIQ' for key 'uq_external_payment_id'
-- DELETE FROM payments WHERE external_payment_id = 'pi_TEST_UNIQ';
--
-- 4. NULL は複数許容されることを確認
-- INSERT INTO payments (id, store_id, external_payment_id, order_ids, total_amount)
--   VALUES (UUID(), 'test-store', NULL, '[]', 100);
-- -- 既存 100 件 NULL と合わせて 101 件、constraint violation なし
-- -- (MySQL UNIQUE は複数 NULL を許容)
-- DELETE FROM payments WHERE store_id = 'test-store' AND external_payment_id IS NULL;
-- ============================================================================
