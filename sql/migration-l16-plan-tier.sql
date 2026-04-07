-- L-16: プラン基盤（料金プランに基づく機能制限の基盤）
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-l16-plan-tier.sql

-- 1. plan ENUM を4段階に変更（既存: free/standard/premium → 新: lite/standard/pro/enterprise）
--    手順: ENUM拡張 → データ移行 → ENUM縮小（一時カラム不要）
ALTER TABLE tenants MODIFY COLUMN plan ENUM('free','standard','premium','lite','pro','enterprise') NOT NULL DEFAULT 'standard';
UPDATE tenants SET plan = 'lite' WHERE plan = 'free';
UPDATE tenants SET plan = 'pro' WHERE plan = 'premium';
ALTER TABLE tenants MODIFY COLUMN plan ENUM('lite','standard','pro','enterprise') NOT NULL DEFAULT 'standard';

-- 2. plan_features マスタテーブル
CREATE TABLE IF NOT EXISTS plan_features (
    plan ENUM('lite','standard','pro','enterprise') NOT NULL,
    feature_key VARCHAR(50) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (plan, feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 機能フラグ投入（docs/plan-features.md に基づく）
INSERT INTO plan_features (plan, feature_key, enabled) VALUES
-- ========== lite ==========
('lite', 'self_order', 0),
('lite', 'handy_pos', 0),
('lite', 'floor_map', 0),
('lite', 'table_merge_split', 0),
('lite', 'inventory', 0),
('lite', 'ai_waiter', 0),
('lite', 'ai_voice', 0),
('lite', 'ai_forecast', 0),
('lite', 'takeout', 0),
('lite', 'payment_gateway', 0),
('lite', 'advanced_reports', 0),
('lite', 'basket_analysis', 0),
('lite', 'audit_log', 0),
('lite', 'multi_session_control', 0),
('lite', 'offline_detection', 0),
('lite', 'satisfaction_rating', 0),
('lite', 'multilingual', 0),
('lite', 'hq_menu_broadcast', 0),
-- ========== standard ==========
('standard', 'self_order', 1),
('standard', 'handy_pos', 1),
('standard', 'floor_map', 1),
('standard', 'table_merge_split', 1),
('standard', 'inventory', 1),
('standard', 'ai_waiter', 1),
('standard', 'ai_voice', 0),
('standard', 'ai_forecast', 0),
('standard', 'takeout', 0),
('standard', 'payment_gateway', 0),
('standard', 'advanced_reports', 1),
('standard', 'basket_analysis', 0),
('standard', 'audit_log', 1),
('standard', 'multi_session_control', 1),
('standard', 'offline_detection', 1),
('standard', 'satisfaction_rating', 1),
('standard', 'multilingual', 0),
('standard', 'hq_menu_broadcast', 0),
-- ========== pro ==========
('pro', 'self_order', 1),
('pro', 'handy_pos', 1),
('pro', 'floor_map', 1),
('pro', 'table_merge_split', 1),
('pro', 'inventory', 1),
('pro', 'ai_waiter', 1),
('pro', 'ai_voice', 1),
('pro', 'ai_forecast', 1),
('pro', 'takeout', 1),
('pro', 'payment_gateway', 1),
('pro', 'advanced_reports', 1),
('pro', 'basket_analysis', 1),
('pro', 'audit_log', 1),
('pro', 'multi_session_control', 1),
('pro', 'offline_detection', 1),
('pro', 'satisfaction_rating', 1),
('pro', 'multilingual', 1),
('pro', 'hq_menu_broadcast', 0),
-- ========== enterprise ==========
('enterprise', 'self_order', 1),
('enterprise', 'handy_pos', 1),
('enterprise', 'floor_map', 1),
('enterprise', 'table_merge_split', 1),
('enterprise', 'inventory', 1),
('enterprise', 'ai_waiter', 1),
('enterprise', 'ai_voice', 1),
('enterprise', 'ai_forecast', 1),
('enterprise', 'takeout', 1),
('enterprise', 'payment_gateway', 1),
('enterprise', 'advanced_reports', 1),
('enterprise', 'basket_analysis', 1),
('enterprise', 'audit_log', 1),
('enterprise', 'multi_session_control', 1),
('enterprise', 'offline_detection', 1),
('enterprise', 'satisfaction_rating', 1),
('enterprise', 'multilingual', 1),
('enterprise', 'hq_menu_broadcast', 1)
ON DUPLICATE KEY UPDATE enabled = VALUES(enabled);
