-- P1-2: Square 完全削除
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-p1-remove-square.sql
--
-- 前提: 本番DB上で payment_gateway='square' のテナントが 0 件であることを事前確認済み (2026-04-07)
--       schema.sql / migration-c3-payment-gateway.sql / migration-l1-takeout.sql は変更しない
--
-- 影響範囲:
--   tenants.square_access_token (C-3 で追加) → DROP
--   tenants.square_location_id  (L-1 で追加) → DROP
--   tenants.payment_gateway ENUM('none','square','stripe') → ENUM('none','stripe')
--   payments.gateway_name='square' の過去履歴は保持（DELETE しない）

-- 念のため 'square' 残存があれば 'none' に変換（0 件想定）
UPDATE tenants SET payment_gateway = 'none' WHERE payment_gateway = 'square';

-- カラム削除
ALTER TABLE tenants DROP COLUMN square_access_token;
ALTER TABLE tenants DROP COLUMN square_location_id;

-- ENUM 縮小
ALTER TABLE tenants
  MODIFY COLUMN payment_gateway ENUM('none','stripe') NOT NULL DEFAULT 'none' COMMENT '使用する決済ゲートウェイ（P1-2 で square 削除）';
