-- L-17 Phase 2A-1: 顧客LINEひも付け基盤
--
-- POSLA 側の reservation_customers と、tenant 保有 LINE公式アカウントの友達
-- (LINE user) を安全にひも付ける中間テーブル。
--
-- 本マイグレーションは link テーブルだけを追加し、reservation_customers /
-- reservations / 通知ロジックには一切手を入れない。未適用時でも既存動作は
-- 100% 不変 (helper 側で table_exists チェックして controlled response)。
--
-- 実行方法:
--   mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla \
--     < sql/migration-l17-2a-customer-line-links.sql

CREATE TABLE IF NOT EXISTS reservation_customer_line_links (
  id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  tenant_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  store_id varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '連携発生時のコンテキスト店舗 (reservation_customers.store_id とは別に記録)',
  reservation_customer_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  line_user_id varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'LINE User ID (U + 32hex)',
  display_name varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  picture_url varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  link_status enum('linked','unlinked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'linked',
  linked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unlinked_at datetime DEFAULT NULL,
  last_interaction_at datetime DEFAULT NULL COMMENT 'LINE 側での最終 interaction (message イベント等)',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tenant_line_user (tenant_id, line_user_id),
  KEY idx_tenant_customer (tenant_id, reservation_customer_id),
  KEY idx_tenant_store (tenant_id, store_id),
  KEY idx_tenant_status (tenant_id, link_status),
  CONSTRAINT rcll_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT rcll_store_fk FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT rcll_customer_fk FOREIGN KEY (reservation_customer_id) REFERENCES reservation_customers (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
