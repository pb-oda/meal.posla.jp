-- P1-6c: tenants.ai_api_key カラム DROP
--
-- 背景: P1-6 で全 AI 機能（AIウェイター/AIキッチン/メニュー翻訳/ai-generate プロキシ/
-- 翻訳/voice-commander/shift-calendar/sold-out）が posla_settings.gemini_api_key に
-- 統一済み。旧 tenants.ai_api_key カラムへの参照は P1-6c で全て撤去された。
--
-- 注意: tenants.google_places_api_key は api/store/places-proxy.php が現役で
-- SELECT しているため、別タスク（P1-22）で posla_settings 化後に DROP 予定。
-- 本マイグレーションでは ai_api_key のみ DROP する。

ALTER TABLE tenants DROP COLUMN ai_api_key;
