-- P1-13 推奨インデックス（参考、コメントアウト状態）
--
-- スキャン日: 2026-04-08
-- 対象DB: odah_eat-posla
--
-- 重要:
--   - 本ファイルは「将来データが増えた場合の参考実装」です
--   - **現時点では実行不要**（EXPLAIN で full scan ゼロ、Using temporary 1件のみ、全て小規模）
--   - 実行する場合は別途 P1-XX タスクとして承認・段階適用すること
--   - 既存インデックスを削除する DDL は含まれません
--
-- 適用優先順位:
--   Tier 1 (orders/order_items が10万行を超えたら):
--     - orders(store_id, status, created_at)
--     - orders(store_id, table_id, session_token, status)
--   Tier 2 (users/satisfaction_ratings が1000行を超えたら):
--     - users(tenant_id, role, is_active)
--   Tier 3 (将来検討):
--     - stores(tenant_id, is_active)

-- ============================================================================
-- Tier 1: orders / order_items (現状 7396 / 32404 行)
-- ============================================================================

-- W1-1: 店舗+ステータス+日付範囲（売上集計、staff-report、turnover-report で頻出）
-- 既存: idx_order_store, idx_order_status, idx_order_store_created
-- 新規候補: 上記2つの中間カバー
-- ALTER TABLE orders ADD INDEX idx_order_store_status_created (store_id, status, created_at);
-- → 確認: idx_order_store_created を残すかは検討（カバーする列が異なる）

-- W1-2: 店舗+卓+セッション+ステータス（顧客注文フロー）
-- 既存: idx_order_store, idx_order_table
-- 新規候補:
-- ALTER TABLE orders ADD INDEX idx_order_store_table_session (store_id, table_id, session_token);
-- → 影響: 顧客注文 GET の lookup が高速化

-- ============================================================================
-- Tier 2: users / satisfaction_ratings (現状 35 / 33 行)
-- ============================================================================

-- W2: tenant+role+active で manager 一覧取得
-- 既存: idx_user_tenant
-- 新規候補:
-- ALTER TABLE users ADD INDEX idx_user_tenant_role_active (tenant_id, role, is_active);
-- → 効果: ユーザー数が数百を超えてから

-- W4: satisfaction_ratings GROUP BY 用カバー（B-09 修正後の avgRating 集計を高速化）
-- 既存: idx_store_created (store_id, created_at), idx_order (order_id)
-- 現状: Using temporary が出ている
-- 新規候補:
-- ALTER TABLE satisfaction_ratings ADD INDEX idx_sr_store_order (store_id, order_id, created_at);
-- → 効果: temp table を回避できる可能性。ただしテーブル小さいため不要

-- ============================================================================
-- Tier 3: 将来検討（現状はテーブルが小さく不要）
-- ============================================================================

-- stores by tenant + active
-- 既存: idx_store_tenant, idx_store_tenant_slug
-- 新規候補:
-- ALTER TABLE stores ADD INDEX idx_store_tenant_active (tenant_id, is_active);

-- shift_help_requests: 既存の idx_shr_to が optimizer に選ばれない件は
-- 将来データ増で自動解決見込み。インデックス追加不要。

-- ============================================================================
-- メモ: B-PROC-01a + P1-33 関連
-- ============================================================================

-- cart_events (P1-33 で新規作成済み):
--   既存: idx_ce_store_date (store_id, created_at), idx_ce_item (item_id, action)
--   EXPLAIN 結果: range スキャン採用、追加不要 ✅

-- call_alerts:
--   既存: idx_store_status (store_id, status)
--   EXPLAIN 結果: ref スキャン採用、追加不要 ✅

-- daily_recommendations:
--   既存: idx_store_date (store_id, display_date), idx_menu_item (menu_item_id)
--   現状の使い方では追加不要 ✅

-- ============================================================================
-- Rollback (将来 ALTER 実行時)
-- ============================================================================

-- ALTER TABLE orders DROP INDEX idx_order_store_status_created;
-- ALTER TABLE orders DROP INDEX idx_order_store_table_session;
-- ALTER TABLE users DROP INDEX idx_user_tenant_role_active;
-- ALTER TABLE satisfaction_ratings DROP INDEX idx_sr_store_order;
-- ALTER TABLE stores DROP INDEX idx_store_tenant_active;
