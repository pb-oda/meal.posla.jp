-- C-3: 決済ゲートウェイ連携用カラム追加
-- 実行方法: mysql -u odah_eat-posla -p odah_eat-posla < sql/migration-c3-payment-gateway.sql

-- テナントに決済APIキーを追加（Gemini/Placesと同じパターン）
ALTER TABLE tenants
  ADD COLUMN square_access_token VARCHAR(200) DEFAULT NULL COMMENT 'Square Access Token' AFTER google_places_api_key,
  ADD COLUMN stripe_secret_key VARCHAR(200) DEFAULT NULL COMMENT 'Stripe Secret Key' AFTER square_access_token,
  ADD COLUMN payment_gateway ENUM('none','square','stripe') NOT NULL DEFAULT 'none' COMMENT '有効な決済ゲートウェイ' AFTER stripe_secret_key;

-- paymentsテーブルに外部決済情報カラムを追加
ALTER TABLE payments
  ADD COLUMN gateway_name VARCHAR(20) DEFAULT NULL COMMENT 'square|stripe|null(現金)' AFTER payment_method,
  ADD COLUMN external_payment_id VARCHAR(100) DEFAULT NULL COMMENT '外部決済ID（Square payment_id / Stripe PaymentIntent id）' AFTER gateway_name,
  ADD COLUMN gateway_status VARCHAR(30) DEFAULT NULL COMMENT '外部決済ステータス（COMPLETED/succeeded等）' AFTER external_payment_id;
