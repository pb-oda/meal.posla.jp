-- L-1: テイクアウト事前注文
-- 実行方法: mysql -u odah_eat-posla -p odah_eat-posla < sql/migration-l1-takeout.sql

-- store_settings: テイクアウト設定カラム追加
ALTER TABLE store_settings
  ADD COLUMN takeout_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'テイクアウト受付ON/OFF',
  ADD COLUMN takeout_min_prep_minutes INT NOT NULL DEFAULT 30 COMMENT '最短準備時間（分）',
  ADD COLUMN takeout_available_from TIME DEFAULT '10:00:00' COMMENT 'テイクアウト受付開始時刻',
  ADD COLUMN takeout_available_to TIME DEFAULT '20:00:00' COMMENT 'テイクアウト受付終了時刻',
  ADD COLUMN takeout_slot_capacity INT NOT NULL DEFAULT 5 COMMENT '15分枠あたり最大受付数',
  ADD COLUMN takeout_online_payment TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'オンライン決済の有効/無効';

-- tenants: Square Location ID（オンライン決済に必要）
ALTER TABLE tenants
  ADD COLUMN square_location_id VARCHAR(50) DEFAULT NULL COMMENT 'Square Location ID';

-- orders.status に pending_payment を追加（オンライン決済待ち状態）
ALTER TABLE orders
  MODIFY COLUMN status ENUM('pending','pending_payment','preparing','ready','served','paid','cancelled') NOT NULL DEFAULT 'pending';
