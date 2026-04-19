-- S3 #10: 返金 race-condition 対策
-- payments.refund_status ENUM に 'pending' を追加
--
-- 用途:
--   refund-payment.php が以下の流れで二重返金を防止する:
--     (1) BEGIN + SELECT ... FOR UPDATE で payment 行をロック
--     (2) refund_status = 'none' のみ条件で 'pending' に UPDATE (affected_rows=1 で成功)
--     (3) COMMIT してから Stripe Refund API を呼ぶ (外部 API はトランザクション外)
--     (4) 成功時 'pending' → 'full' で確定 / 失敗時 'pending' → 'none' で revert
--
-- 既存の 'none' / 'partial' / 'full' レコードは影響を受けない (ENUM 拡張のみ)。
-- UNIQUE 制約・カラムサイズ変更なし。
--
-- 実行:
--   mysql -u odah_eat-posla -p odah_eat-posla < migration-s3-refund-pending.sql

ALTER TABLE payments
  MODIFY COLUMN refund_status
    ENUM('none','pending','partial','full')
    NOT NULL DEFAULT 'none';

-- 検証
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'payments'
   AND COLUMN_NAME = 'refund_status';
