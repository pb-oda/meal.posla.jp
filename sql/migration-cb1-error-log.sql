-- migration-cb1-error-log.sql
-- Phase B (CB1): API エラー記録テーブル
--
-- 用途:
--   - json_error() が呼ばれるたびに記録
--   - 監視 cron がここから集計 → monitor_events に昇格
--   - 管理画面ダッシュボードでエラーランキング表示
--   - AI 運用エージェントが頻度分析に利用
--
-- audit_log とは責務分離: audit_log = ユーザー操作 / error_log = API 失敗

CREATE TABLE IF NOT EXISTS error_log (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  error_no        VARCHAR(8)   NULL,             -- 例: E3024 (未登録 code は NULL)
  code            VARCHAR(64)  NOT NULL,         -- 例: PIN_INVALID
  message         TEXT         NULL,
  http_status     SMALLINT     NOT NULL,
  tenant_id       VARCHAR(36)  NULL,
  store_id        VARCHAR(36)  NULL,
  user_id         VARCHAR(36)  NULL,
  username        VARCHAR(50)  NULL,
  role            VARCHAR(20)  NULL,
  request_method  VARCHAR(8)   NULL,
  request_path    VARCHAR(255) NULL,
  ip_address      VARCHAR(45)  NULL,
  user_agent      TEXT         NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_error_no (error_no),
  KEY idx_code (code),
  KEY idx_tenant_store_created (tenant_id, store_id, created_at),
  KEY idx_status_created (http_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
