-- ============================================================
-- Migration i5: ラストオーダー管理 + KDS→ハンディ通知
-- O-3: 店舗全体のラストオーダー管理
-- O-5: KDS ready 通知
-- ============================================================

-- --- O-3: store_settings にラストオーダー関連カラム追加 ---

ALTER TABLE store_settings
  ADD COLUMN last_order_time TIME DEFAULT NULL
  COMMENT '定時ラストオーダー時刻（例: 21:30:00）。NULLなら無効';

ALTER TABLE store_settings
  ADD COLUMN last_order_active TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '手動ラストオーダーフラグ（1=発動中）';

ALTER TABLE store_settings
  ADD COLUMN last_order_activated_at DATETIME DEFAULT NULL
  COMMENT '手動ラストオーダー発動時刻';

-- --- O-5: call_alerts にアラート種別カラム追加 ---

ALTER TABLE call_alerts
  ADD COLUMN type ENUM('staff_call','product_ready') NOT NULL DEFAULT 'staff_call'
  COMMENT 'アラート種別' AFTER reason;

ALTER TABLE call_alerts
  ADD COLUMN order_item_id VARCHAR(36) DEFAULT NULL
  COMMENT '関連品目ID（product_ready時）' AFTER type;

ALTER TABLE call_alerts
  ADD COLUMN item_name VARCHAR(100) DEFAULT NULL
  COMMENT '品目名（表示用）' AFTER order_item_id;
