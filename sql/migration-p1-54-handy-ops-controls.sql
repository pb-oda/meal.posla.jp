-- P1-54: ハンディ運用ボード操作強化
-- - 会計待ちなど、状態変更後の放置時間を運用ボードで判定できるようにする

ALTER TABLE table_sessions
  ADD COLUMN status_changed_at DATETIME DEFAULT NULL AFTER status,
  ADD INDEX idx_ts_status_changed (store_id, status, status_changed_at);
