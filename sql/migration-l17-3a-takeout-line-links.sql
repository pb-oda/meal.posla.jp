-- L-17 Phase 3A: テイクアウト注文 LINE ひも付け基盤
--
-- 予約顧客向けの reservation_customer_line_links と責務を分離して、takeout
-- 注文単位で LINE user を明示的に紐付ける。orders テーブルに customer_id が
-- 無いため別テーブルで管理し、orders 既存意味を壊さない。
--
-- 電話番号だけの暗黙マッチは使わず、顧客自身が店舗 LINE OA に送信する
-- "LINK:XXXXXX" トークンを経由する明示リンクに限定する (Phase 3A-1)。
--
-- tenant/store/order の 3 境界を各テーブル必須カラムとして持ち、FK + UNIQUE
-- で逸脱を防ぐ。
--
-- 実行方法:
--   mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -p odah_eat-posla \
--     < sql/migration-l17-3a-takeout-line-links.sql

-- ========== 1. takeout_order_line_links ==========
CREATE TABLE IF NOT EXISTS takeout_order_line_links (
  id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  tenant_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  store_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  order_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  line_user_id varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'LINE User ID (U + 32hex)',
  display_name varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  picture_url varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  link_status enum('linked','unlinked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'linked',
  linked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unlinked_at datetime DEFAULT NULL,
  last_interaction_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tenant_order (tenant_id, order_id),
  KEY idx_tenant_line_user (tenant_id, line_user_id),
  KEY idx_tenant_store (tenant_id, store_id),
  KEY idx_tenant_status (tenant_id, link_status),
  CONSTRAINT tol_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT tol_store_fk FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT tol_order_fk FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== 2. takeout_order_line_link_tokens ==========
CREATE TABLE IF NOT EXISTS takeout_order_line_link_tokens (
  id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  tenant_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  store_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  order_id varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  token varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'URL-safe short code (default: 6 文字 A-Z0-9、紛らわしい文字は除外)',
  issued_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at datetime NOT NULL COMMENT 'アプリ側で 30 分デフォルト付与',
  used_at datetime DEFAULT NULL,
  used_by_line_user_id varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  issued_by_phone varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '発行時の顧客電話番号 (監査用、orders.customer_phone と照合)',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tenant_token (tenant_id, token),
  KEY idx_tenant_order (tenant_id, order_id),
  KEY idx_tenant_expires (tenant_id, expires_at),
  KEY idx_tenant_used (tenant_id, used_at),
  CONSTRAINT tolt_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT tolt_store_fk FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT tolt_order_fk FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
