-- L-8: セルフレジ
-- 実行方法: mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla < sql/migration-l8-self-checkout.sql

ALTER TABLE store_settings
  ADD COLUMN self_checkout_enabled TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'セルフレジ有効フラグ（L-8）';
