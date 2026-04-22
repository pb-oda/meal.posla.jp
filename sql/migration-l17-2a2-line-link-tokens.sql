-- L-17 Phase 2A-2: LINE ひも付け用 one-time token
--
-- owner が発行し、顧客が店舗 LINE OA に「LINK:XXXXXX」形式のメッセージで
-- 送信することで reservation_customers と LINE user を安全にひも付ける。
-- official Account Linking (LINE Login チャネル必須) が現スコープでは
-- 重いため、Messaging API 単独で閉じる fallback フローを採用。
--
-- - token は 6 桁 A-Z0-9 (O/0/I/1 等紛らわしい文字を除外して生成)
-- - 未使用 (used_at IS NULL) かつ未失効 (expires_at > NOW()) のみ有効
-- - 単回使用: consume 時に used_at / used_by_line_user_id をセット
-- - 30 分 TTL をアプリ側で付与 (DB カラムには既定値を置かない)
-- - tenant 境界: UNIQUE KEY (tenant_id, token) でテナント毎衝突回避
-- - 未適用時は helper 側で controlled に false 返却 → 既存動作 100% 不変
--
-- 実行方法:
--   mysql -h ... -u ... -p ... < sql/migration-l17-2a2-line-link-tokens.sql

CREATE TABLE IF NOT EXISTS reservation_customer_line_link_tokens (
  id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  tenant_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  store_id varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '発行コンテキスト店舗 (customer は単一店舗所属だが UI 上の出所を記録)',
  reservation_customer_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  token varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'URL-safe short code (default: 6 文字 A-Z0-9、紛らわしい文字は除外)',
  issued_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at datetime NOT NULL COMMENT 'アプリ側で 30 分デフォルト付与',
  used_at datetime DEFAULT NULL,
  used_by_line_user_id varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  revoked_at datetime DEFAULT NULL COMMENT 'owner による明示的失効',
  created_by_user_id varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tenant_token (tenant_id, token),
  KEY idx_tenant_customer (tenant_id, reservation_customer_id),
  KEY idx_tenant_expires (tenant_id, expires_at),
  KEY idx_tenant_used (tenant_id, used_at),
  CONSTRAINT rclt_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT rclt_store_fk FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT rclt_customer_fk FOREIGN KEY (reservation_customer_id) REFERENCES reservation_customers (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
