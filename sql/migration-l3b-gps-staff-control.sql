-- L-3b: GPS出退勤制御 + スタッフツール表示制御
-- shift_settings に5カラム追加

ALTER TABLE shift_settings
  ADD COLUMN store_lat DECIMAL(10,7) NULL COMMENT '店舗緯度' AFTER auto_clock_out_hours,
  ADD COLUMN store_lng DECIMAL(10,7) NULL COMMENT '店舗経度' AFTER store_lat,
  ADD COLUMN gps_radius_meters INT NOT NULL DEFAULT 200 COMMENT 'GPS許容半径（m）' AFTER store_lng,
  ADD COLUMN gps_required TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GPS出退勤を必須にする' AFTER gps_radius_meters,
  ADD COLUMN staff_visible_tools VARCHAR(100) NULL DEFAULT NULL COMMENT 'CSV: handy,kds,register (NULL=全表示)' AFTER gps_required;
