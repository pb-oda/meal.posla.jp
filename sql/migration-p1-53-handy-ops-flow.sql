-- P1-53: ハンディ運用フロー基盤
-- - 会計後の卓を即空席にせず、清掃待ち(cleaning)として運用ボードに残す
-- - KDS/客席呼び出しに対応中(in_progress)と担当者記録を追加する

ALTER TABLE table_sessions
  MODIFY COLUMN status ENUM('seated','eating','bill_requested','paid','cleaning','closed')
    NOT NULL DEFAULT 'seated'
    COMMENT 'seated=着席, eating=食事中, bill_requested=会計待ち, paid=会計済み, cleaning=清掃待ち, closed=退席済み';

ALTER TABLE call_alerts
  MODIFY COLUMN status ENUM('pending','in_progress','acknowledged')
    DEFAULT 'pending';

ALTER TABLE call_alerts
  ADD COLUMN acknowledged_by_user_id VARCHAR(36) DEFAULT NULL AFTER acknowledged_at,
  ADD COLUMN acknowledged_by_name VARCHAR(100) DEFAULT NULL AFTER acknowledged_by_user_id,
  ADD COLUMN in_progress_at DATETIME DEFAULT NULL AFTER acknowledged_by_name,
  ADD COLUMN in_progress_by_user_id VARCHAR(36) DEFAULT NULL AFTER in_progress_at,
  ADD COLUMN in_progress_by_name VARCHAR(100) DEFAULT NULL AFTER in_progress_by_user_id,
  ADD INDEX idx_call_alerts_progress (store_id, status, created_at);
