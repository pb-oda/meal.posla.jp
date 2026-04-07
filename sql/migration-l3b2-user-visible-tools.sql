-- L-3b2: ユーザー単位ツール表示制御
-- user_stores に visible_tools カラム追加
-- NULL = 店舗設定 (shift_settings.staff_visible_tools) に従う
-- CSV例: "handy,kds,register"

ALTER TABLE user_stores
  ADD COLUMN visible_tools VARCHAR(100) NULL DEFAULT NULL
  COMMENT 'CSV: handy,kds,register (NULL=店舗設定に従う)';
