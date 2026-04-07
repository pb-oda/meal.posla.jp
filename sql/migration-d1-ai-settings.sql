-- migration-d1-ai-settings.sql
-- AI設定: テナントレベルに Gemini APIキーカラムを追加
-- 依存: schema.sql（tenants）

-- テナントにAIキーカラムを追加
ALTER TABLE tenants ADD COLUMN ai_api_key VARCHAR(200) DEFAULT NULL
  COMMENT 'Gemini APIキー' AFTER is_active;

-- store_settings側にカラムが存在する場合は既存データを移行してから削除
-- （新規構築の場合はstore_settings側にカラムが無いのでスキップされる）
-- 手動で必要に応じて実行:
-- UPDATE tenants t
--   JOIN stores s ON s.tenant_id = t.id
--   JOIN store_settings ss ON ss.store_id = s.id
--   SET t.ai_api_key = ss.ai_api_key
--   WHERE ss.ai_api_key IS NOT NULL AND t.ai_api_key IS NULL;
-- ALTER TABLE store_settings DROP COLUMN ai_api_key;
