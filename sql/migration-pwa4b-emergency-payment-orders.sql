-- migration-pwa4b-emergency-payment-orders.sql
-- PWA Phase 4b-1: 緊急会計 × 注文ID の正規化子テーブル (2026-04-20)
--
-- 目的:
--   Phase 4a の emergency_payments.order_ids_json (JSON 型) は「誰がどの注文を緊急会計したか」を
--   記録しているが、JSON カラムには UNIQUE INDEX を効かせられず、大量データ時の
--   JSON_CONTAINS 検索も INDEX が効かない。
--   Phase 4a のトランザクション + FOR UPDATE で race は実用上防げるが、
--   DB レベルの絶対保証 (UNIQUE 制約による DUP_ENTRY) を追加して二重登録を
--   物理的に封じるため、order_id ごとの子テーブルを新設する。
--
-- MySQL 5.7 での UNIQUE 制約設計:
--   - MySQL 5.7 では partial unique index (WHERE status='active' のような部分インデックス) が使えない
--   - そのため、status を UNIQUE KEY に含める形で回避する: UNIQUE (store_id, order_id, status)
--   - 当面は status='active' のみ登録する運用。将来 (Phase 4c 以降) で「無効化」「解決済み」などの
--     ステータスを導入する余地を残す
--   - 同じ (store_id, order_id) で status='active' の行が既に存在すると INSERT が 1062 を返すので、
--     API 側でキャッチして emergency_payments の親レコードを status='conflict' に遷移させる
--
-- マルチテナント境界:
--   tenant_id / store_id を明示し、全クエリで WHERE tenant_id = ? AND store_id = ? を徹底する。
--
-- Phase 4a の order_ids_json との併存:
--   本子テーブルは「二重防止の補助」であり、JSON カラムは互換のため当面維持する。
--   Phase 4c 以降で JSON 併存の廃止を検討する。
--
-- FOREIGN KEY:
--   emergency_payments へは FK を張らない (既存 payments / orders もそろえて FK なしで運用している)。
--   参照整合性は API 側のトランザクションと UNIQUE 制約で担保する。

CREATE TABLE IF NOT EXISTS emergency_payment_orders (
  id                          VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id                   VARCHAR(36) NOT NULL,
  store_id                    VARCHAR(36) NOT NULL,
  emergency_payment_id        VARCHAR(36) NOT NULL COMMENT '親 emergency_payments.id',
  local_emergency_payment_id  VARCHAR(80) NOT NULL COMMENT '端末側 idempotency key (親と同値)',
  order_id                    VARCHAR(36) NOT NULL,
  status                      VARCHAR(30) NOT NULL DEFAULT 'active'
                                COMMENT '当面 active のみ。将来 invalidated / resolved などを追加可能',
  created_at                  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_store_order_active (store_id, order_id, status),
  KEY idx_emergency_payment       (emergency_payment_id),
  KEY idx_tenant_store_created    (tenant_id, store_id, created_at),
  KEY idx_local                   (store_id, local_emergency_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
