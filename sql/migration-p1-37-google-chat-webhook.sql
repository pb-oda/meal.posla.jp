-- P1-37: 内部監視アラートの通知先を Google Chat webhook へ移行
-- 既存 slack_webhook_url は rollback / legacy fallback 用に温存

SET NAMES utf8mb4;

INSERT INTO posla_settings (setting_key, setting_value)
VALUES
  ('google_chat_webhook_url', '')
ON DUPLICATE KEY UPDATE updated_at = NOW();
