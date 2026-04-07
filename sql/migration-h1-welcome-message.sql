-- migration-h1-welcome-message.sql
-- ウェルカムメッセージ用カラムを store_settings に追加

ALTER TABLE store_settings
  ADD COLUMN welcome_message VARCHAR(500) DEFAULT NULL,
  ADD COLUMN welcome_message_en VARCHAR(500) DEFAULT NULL;
