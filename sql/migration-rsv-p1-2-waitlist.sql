-- ============================================================
-- RSV-P1-2 waitlist / walk-in 統合
-- ============================================================
-- 目的:
--   reservations.status に 'waitlisted' を追加し、
--   受付待ち客 (walk-in で席が空くのを待つ / phone 予約で到着済み席待ち)
--   を予約台帳の別パネルで扱えるようにする。
--
-- 適用条件:
--   既存 status ENUM: 'pending','confirmed','seated','no_show','cancelled','completed'
--   新 ENUM:          上記 + 'waitlisted'
--
-- 後方互換:
--   既存レコードには影響しない (DEFAULT 'confirmed' 維持)。
--   'waitlisted' 値は新規追加のみ、既存クエリは無変更。
--
-- 2026-04-23 追加 (BENCH-P1-2b / benchmark-feature-roadmap 2026-04 RSV-P1-2)
-- ============================================================

ALTER TABLE reservations
  MODIFY COLUMN status
    ENUM('pending','confirmed','seated','no_show','cancelled','completed','waitlisted')
    NOT NULL DEFAULT 'confirmed';
