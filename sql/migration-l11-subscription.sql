-- L-11: サブスクリプション課金基盤
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-l11-subscription.sql

-- 1. tenants テーブルにサブスク関連カラム追加
ALTER TABLE tenants
  ADD COLUMN stripe_customer_id VARCHAR(50) DEFAULT NULL COMMENT 'Stripe Customer ID (cus_xxx)',
  ADD COLUMN stripe_subscription_id VARCHAR(50) DEFAULT NULL COMMENT 'Stripe Subscription ID (sub_xxx)',
  ADD COLUMN subscription_status ENUM('none','active','past_due','canceled','trialing') NOT NULL DEFAULT 'none' COMMENT 'サブスク状態',
  ADD COLUMN current_period_end DATETIME DEFAULT NULL COMMENT '現在の課金期間終了日';

-- 2. posla_settings にStripe Billing関連キーを追加
INSERT INTO posla_settings (setting_key, setting_value) VALUES
  ('stripe_secret_key', NULL),
  ('stripe_publishable_key', NULL),
  ('stripe_webhook_secret', NULL),
  ('stripe_price_standard', NULL),
  ('stripe_price_pro', NULL),
  ('stripe_price_enterprise', NULL)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- 3. サブスクリプションイベントログ
CREATE TABLE IF NOT EXISTS subscription_events (
  id VARCHAR(36) NOT NULL,
  tenant_id VARCHAR(36) NOT NULL,
  event_type VARCHAR(50) NOT NULL COMMENT 'Stripeイベントタイプ',
  stripe_event_id VARCHAR(100) DEFAULT NULL COMMENT 'Stripe Event ID (evt_xxx)',
  data TEXT DEFAULT NULL COMMENT 'イベントデータJSON',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tenant_id (tenant_id),
  KEY idx_stripe_event_id (stripe_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
