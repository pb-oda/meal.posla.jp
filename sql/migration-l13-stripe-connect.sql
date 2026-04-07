-- L-13: Stripe Connect（決済代行）
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-l13-stripe-connect.sql

-- 1. tenants テーブルにConnect関連カラム追加
ALTER TABLE tenants
  ADD COLUMN stripe_connect_account_id VARCHAR(100) DEFAULT NULL COMMENT 'Stripe Connect Express Account ID (acct_...)',
  ADD COLUMN connect_onboarding_complete TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Connectオンボーディング完了フラグ';

-- 2. posla_settings にデフォルト手数料率を追加
INSERT IGNORE INTO posla_settings (setting_key, setting_value)
VALUES ('connect_application_fee_percent', '1.0');
