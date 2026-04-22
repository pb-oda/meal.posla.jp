-- migration-pwa2-push-subscriptions.sql
-- PWA Phase 2a: Web Push 購読情報を保存するテーブル (2026-04-20)
--
-- 用途:
--   業務端末 (KDS / レジ / ハンディ / 管理画面 / オーナー画面) で通知許可をしたスタッフの
--   PushSubscription (endpoint / p256dh / auth) を保存し、サーバーから push_send_to_*() で
--   送信する際の宛先テーブルとして使う。
--
-- マルチテナント境界:
--   tenant_id / store_id / user_id を必須カラムとして持つ。送信時は WHERE 句で必ず絞り込む。
--
-- enabled / revoked_at による soft delete:
--   ブラウザ側で unsubscribe された / 410 Gone が返った endpoint は enabled=0 + revoked_at に
--   日時を入れる。物理削除はしない (送信履歴・統計のため)。
--
-- MySQL 5.7 互換:
--   CHECK 制約は使わない (PHP 側で scope の許可リスト検証)。
--   utf8mb4 / InnoDB / ON UPDATE CURRENT_TIMESTAMP を使用。
--
-- セキュリティ:
--   endpoint / p256dh / auth は個人端末を識別する情報なので、
--   GET API では絶対に他人に返さない。本人 (user_id 一致) または manager 以上のみ閲覧可とする。

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id              VARCHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id       VARCHAR(36)  NOT NULL,
  store_id        VARCHAR(36)  DEFAULT NULL COMMENT '店舗紐付け (店舗単位通知用)。owner で全店共通の場合は NULL 可',
  user_id         VARCHAR(36)  NOT NULL,
  role            VARCHAR(20)  NOT NULL COMMENT 'owner / manager / staff / device',
  scope           VARCHAR(20)  NOT NULL COMMENT 'admin / owner / kds / cashier / handy / pos-register',
  endpoint        TEXT         NOT NULL COMMENT 'PushSubscription.endpoint (URL長制限考慮で TEXT)',
  endpoint_hash   CHAR(64)     NOT NULL COMMENT 'SHA-256(endpoint)。一意制約とログ用ハッシュとして使う',
  p256dh          VARCHAR(255) NOT NULL COMMENT 'Public key (base64url)',
  auth_key        VARCHAR(64)  NOT NULL COMMENT 'Auth secret (base64url)',
  user_agent      VARCHAR(255) DEFAULT NULL,
  device_label    VARCHAR(100) DEFAULT NULL COMMENT 'スタッフが付ける任意のラベル (例: 厨房 KDS タブレット)',
  enabled         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_seen_at    DATETIME     DEFAULT NULL COMMENT 'subscribe API が最後に呼ばれた日時 (生存確認)',
  revoked_at      DATETIME     DEFAULT NULL COMMENT 'enabled=0 にしたタイミング',
  UNIQUE KEY uk_endpoint_hash (endpoint_hash),
  KEY idx_store_enabled_scope (store_id, enabled, scope),
  KEY idx_user_enabled (user_id, enabled),
  KEY idx_tenant_role_enabled (tenant_id, role, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
