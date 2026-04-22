-- migration-pwa4-emergency-payments.sql
-- PWA Phase 4: 緊急レジモード - 端末内緊急会計の POSLA 同期台帳 (2026-04-20)
--
-- 目的:
--   通信障害・API 障害・stale 表示中にも、レジの「会計事実」を失わずに端末内 (IndexedDB) に
--   記録し、復帰後に POSLA へ同期するための台帳テーブル。
--
-- 重要:
--   - 本テーブルは「緊急モード専用台帳」。通常会計は既存 `payments` テーブルへ進む。
--   - 通常売上レポートへの自動変換は Phase 4b に分離。本テーブルは「状態管理 + 管理者確認」まで。
--   - カード番号・有効期限・CVV に相当するカラムは作らない (PCI-DSS 範囲に入らない設計)。
--     外部端末の控え番号・承認番号のみ任意で保存。
--
-- マルチテナント境界:
--   - tenant_id / store_id 両方を持たせ、API 側で必ず user['tenant_id'] と突き合わせる。
--   - 既存 payments は store_id だけだが、本フェーズから tenant_id を明示する方針。
--
-- Idempotency:
--   - local_emergency_payment_id は端末で一意 (`storeId + deviceId + timestamp + random`)。
--   - UNIQUE (store_id, local_emergency_payment_id) により同一 localId を何度 POST しても
--     1 件のみ作成される。
--
-- ステータス遷移:
--   端末側 IndexedDB:  pending_sync → syncing → synced / conflict / pending_review / failed
--   サーバー側本テーブル: server_received_at 時点の最終 status を保存
--
-- MySQL 5.7:
--   - JSON 型は既存 payments と揃える。
--   - ON UPDATE CURRENT_TIMESTAMP で updated_at 自動更新。

CREATE TABLE IF NOT EXISTS emergency_payments (
  id                          VARCHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id                   VARCHAR(36)  NOT NULL,
  store_id                    VARCHAR(36)  NOT NULL,
  local_emergency_payment_id  VARCHAR(80)  NOT NULL COMMENT '端末側 idempotency key',
  device_id                   VARCHAR(80)  DEFAULT NULL,
  staff_user_id               VARCHAR(36)  DEFAULT NULL,
  staff_name                  VARCHAR(100) DEFAULT NULL,
  staff_pin_verified          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'API 側で PIN 検証通ったら 1',
  table_id                    VARCHAR(36)  DEFAULT NULL,
  table_code                  VARCHAR(40)  DEFAULT NULL,
  order_ids_json              JSON         DEFAULT NULL COMMENT '対象注文 ID 配列',
  item_snapshot_json          JSON         DEFAULT NULL COMMENT '明細 snapshot [{orderId,itemId,name,qty,price,taxRate,status}]',
  subtotal_amount             INT          NOT NULL DEFAULT 0,
  tax_amount                  INT          NOT NULL DEFAULT 0,
  total_amount                INT          NOT NULL,
  payment_method              VARCHAR(50)  NOT NULL COMMENT 'cash / external_card / external_qr / other_external',
  received_amount             INT          DEFAULT NULL COMMENT '現金時の預かり金',
  change_amount               INT          DEFAULT NULL COMMENT '現金時のお釣り',
  external_terminal_name      VARCHAR(100) DEFAULT NULL COMMENT '外部決済端末の名称 (任意)',
  external_slip_no            VARCHAR(100) DEFAULT NULL COMMENT '外部端末の控え番号 (任意)',
  external_approval_no        VARCHAR(100) DEFAULT NULL COMMENT '外部端末の承認番号 (任意)',
  note                        VARCHAR(255) DEFAULT NULL,
  status                      VARCHAR(30)  NOT NULL DEFAULT 'synced'
                                           COMMENT 'synced / conflict / pending_review / failed',
  conflict_reason             VARCHAR(255) DEFAULT NULL,
  synced_payment_id           VARCHAR(64)  DEFAULT NULL COMMENT 'Phase 4b で payments.id と連携したら記録',
  app_version                 VARCHAR(20)  DEFAULT NULL,
  client_created_at           DATETIME     DEFAULT NULL COMMENT '端末側で会計記録した時刻',
  server_received_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_store_local (store_id, local_emergency_payment_id),
  KEY idx_store_status_created (store_id, status, created_at),
  KEY idx_tenant_status        (tenant_id, status),
  KEY idx_table                (table_id),
  KEY idx_server_received      (server_received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
