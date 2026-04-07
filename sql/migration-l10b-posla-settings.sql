-- L-10b: POSLA共通設定テーブル
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-l10b-posla-settings.sql

CREATE TABLE IF NOT EXISTS posla_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初期行（値は空。POSLA管理画面から設定する）
INSERT INTO posla_settings (setting_key, setting_value) VALUES
('gemini_api_key', NULL),
('google_places_api_key', NULL)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
