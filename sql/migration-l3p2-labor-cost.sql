-- ============================================
-- L-3 Phase 2: 人件費シミュレーション用カラム追加
-- 実行日: 2026-04-06
-- ============================================

-- 店舗デフォルト時給
ALTER TABLE shift_settings
  ADD COLUMN default_hourly_rate INT NULL DEFAULT NULL
  COMMENT '店舗デフォルト時給（円）。NULL=未設定';

-- スタッフ個別時給
ALTER TABLE user_stores
  ADD COLUMN hourly_rate INT NULL DEFAULT NULL
  COMMENT 'スタッフ個別時給（円）。NULL=店舗デフォルトを使用';
