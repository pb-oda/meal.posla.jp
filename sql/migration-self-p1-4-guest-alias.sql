-- ============================================================
-- SELF-P1-4 自分の注文明示 UI — 任意ゲスト名を orders に持たせる
-- ============================================================
-- 目的:
--   customer 側で「自分が注文したもの」を分かりやすくするため、
--   orders 1 レコード = 1 人分の注文単位で任意のゲスト名を保存する。
--   payment / split / cashier 側では一切使わない。表示整理専用。
--
-- 設計原則:
--   - NULLABLE: 必須化しない。従来どおりゲスト名なしで注文可能
--   - VARCHAR(32): 短いニックネーム/ひらがな数文字想定
--   - memo カラムの直後に配置 (既存 customer-facing 任意入力と揃える)
--
-- 後方互換:
--   既存レコードには影響しない (NULL で初期化)。
--   INSERT / SELECT は API 側で column existence probe パターンを使うため、
--   migration 未適用でも従来どおり稼働する (graceful degradation)。
--
-- 2026-04-23 追加 (benchmark-feature-roadmap 2026-04 SELF-P1-4)
-- ============================================================

ALTER TABLE orders
  ADD COLUMN guest_alias VARCHAR(32) NULL AFTER memo;
