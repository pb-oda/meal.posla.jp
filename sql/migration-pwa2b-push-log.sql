-- migration-pwa2b-push-log.sql
-- PWA Phase 2b: Web Push 送信ログ + レート制限用テーブル (2026-04-20)
--
-- 用途:
--   1. 送信監査 (いつ誰に何が送られたか、結果コードは何か)
--   2. レート制限 (直近 N 秒に同 user+type+tag で 2xx 済みなら重複 skip)
--   3. important_error の連続発火抑制 (直近 5 分に同 tenant+type で送信済みなら skip)
--   4. POSLA 管理画面 PWA/Push タブの 24 時間送信統計表示
--
-- PII 方針:
--   title / body は保存しない (個人を特定しうる店舗名・顧客コメント等が含まれる可能性)。
--   tag は "kitchen_call" "low_rating" 等の固定ラベルのみなので保存 OK。
--   type は 'call_staff' / 'call_alert' / 'low_rating' / 'important_error' / 'push_test'。
--
-- 保持期間:
--   90 日 (error_log / user_sessions と同じポリシー)。
--   月次 cron で `DELETE FROM push_send_log WHERE sent_at < DATE_SUB(NOW(), INTERVAL 90 DAY)` を実行することを推奨。
--
-- セキュリティ:
--   POSLA 管理者のみ閲覧可とする (api/posla/push-vapid.php 経由で統計のみ返し、明細は返さない)。
--   tenant_id / store_id / user_id は NULL を許容 (テナント横断テスト送信・存在しない store への送信試行など)。
--
-- MySQL 5.7 互換:
--   CHECK 制約は使わない。
--   utf8mb4 / InnoDB。

CREATE TABLE IF NOT EXISTS push_send_log (
  id              VARCHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id       VARCHAR(36)  DEFAULT NULL,
  store_id        VARCHAR(36)  DEFAULT NULL,
  subscription_id VARCHAR(36)  DEFAULT NULL COMMENT 'push_subscriptions.id (削除されたら NULL のまま残す)',
  user_id         VARCHAR(36)  DEFAULT NULL,
  type            VARCHAR(40)  NOT NULL COMMENT 'call_staff / call_alert / low_rating / important_error / push_test',
  tag             VARCHAR(100) DEFAULT NULL COMMENT '固定ラベル (kitchen_call / low_rating / call_staff_<code> 等)。PII は入れない',
  status_code     INT          DEFAULT NULL COMMENT 'HTTP 2xx=成功 / 410,404=gone / 429,5xx=一時失敗 / 0=送信前エラー',
  reason          VARCHAR(60)  DEFAULT NULL COMMENT 'sent / gone_disabled / transient_failure / encrypt_or_sign_failed 等',
  sent_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant_sent (tenant_id, sent_at),
  KEY idx_user_type_tag_sent (user_id, type, tag, sent_at),
  KEY idx_type_tenant_sent (type, tenant_id, sent_at),
  KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
