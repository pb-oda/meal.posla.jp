-- ============================================================
-- INV-P1-5 自動在庫消費 — 冪等性マーカー + 消費履歴ログ
-- ============================================================
-- 目的:
--   注文が会計完了 (orders.status='paid') した際のレシピ連動在庫引き落としに
--   明示的な冪等性マーカーと audit log を追加する。
--   既存の recipe 引き落としロジック自体 (process-payment.php / checkout-confirm.php)
--   は動いているが、以下の 2 点が欠落していた:
--     1) orders 単位の「消費反映済み」フラグ (二重消費防止の明示的ガード)
--     2) 消費履歴ログ (variance / 原価分析 / 監査の前提基盤)
--
-- 設計:
--   - orders.stock_consumed_at DATETIME NULL
--       UPDATE ... WHERE stock_consumed_at IS NULL で atomic claim
--       rowCount=1 の orders だけを消費対象にすれば二重実行を避けられる
--       既存レコードは NULL で初期化 (過去の会計分は消費記録なしとして無視)
--
--   - inventory_consumption_log
--       消費 1 回 = 1 行 ((order_id, ingredient_id) 単位)
--       tenant_id / store_id を必ず保持 (マルチテナント境界)
--       INDEX: order_id / ingredient_id / (store_id, consumed_at)
--
-- 後方互換:
--   - 既存 INSERT / UPDATE は API 側で column existence probe で分岐するため
--     migration 未適用時は従来挙動で稼働する (graceful degradation)
--   - 既存レポート / 決済 / 会計フローは無影響
--   - void / refund による在庫復元は本 migration の対象外 (INV-P1-2 / P1-7 へ)
--
-- 2026-04-23 追加 (benchmark-feature-roadmap 2026-04 INV-P1-5)
-- ============================================================

-- ── 1. orders に冪等性マーカー追加 ──
ALTER TABLE orders
  ADD COLUMN stock_consumed_at DATETIME NULL AFTER paid_at;

-- ── 2. 消費履歴ログ (variance / 監査の前提基盤) ──
CREATE TABLE IF NOT EXISTS inventory_consumption_log (
  id             VARCHAR(36) PRIMARY KEY,
  tenant_id      VARCHAR(36) NOT NULL,
  store_id       VARCHAR(36) NOT NULL,
  order_id       VARCHAR(36) NOT NULL,
  ingredient_id  VARCHAR(36) NOT NULL,
  quantity       DECIMAL(10,2) NOT NULL         COMMENT '差し引いた数量 (recipes.quantity × 注文数量)',
  consumed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_invlog_order (order_id),
  INDEX idx_invlog_ingredient (ingredient_id),
  INDEX idx_invlog_store_consumed (store_id, consumed_at),
  INDEX idx_invlog_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
