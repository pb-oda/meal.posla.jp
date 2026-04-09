# P1-13 EXPLAIN 結果分析

**実行日**: 2026-04-08
**対象**: 本番DB (`mysql80.odah.sakura.ne.jp` / `odah_eat-posla`)
**方法**: `EXPLAIN SELECT ...` を 12 クエリで実行（READ ONLY、データ変更なし）

---

## 本番テーブル行数（参考）

| テーブル | 行数 | データMB | インデックスMB |
|---------|------|---------|---------------|
| **order_items** | **32,404** | 12.05 | 14.58 |
| **orders** | **7,396** | 9.05 | 9.52 |
| ui_translations | 465 | 0.06 | 0.02 |
| audit_log | 373 | 0.19 | 0.11 |
| menu_translations | 241 | 0.06 | 0.05 |
| call_alerts | 129 | 0.05 | 0.02 |
| shift_assignments | 120 | 0.05 | 0.06 |
| user_sessions | 98 | 0.06 | 0.06 |
| satisfaction_ratings | 33 | 0.02 | 0.05 |
| 他全テーブル | < 100 | — | — |

**結論**: 現状で性能影響を実感するレベルなのは `orders` と `order_items` のみ。他は全て小規模で full scan でも 1ms 未満。

ただし将来的なグロース（マルチテナント想定で月数千件→数十万件）を見据えてインデックス整備を検討する。

---

## EXPLAIN 結果サマリー

| # | クエリ | テーブル | 採用 key | type | rows | 警告 |
|---|--------|---------|---------|------|------|------|
| Q1 | orders 店舗+ステータス+日付 | orders | idx_order_store | ref | 1 | composite 不採用 |
| Q2 | orders 店舗+卓+セッション+ステータス | orders | idx_order_store | ref | 1 | composite 不採用 |
| Q3 | users tenant+role+active | users | idx_user_tenant | ref | 15 | filtered=3.33% |
| Q4 | shift_assignments tenant+store+日付範囲 | shift_assignments | idx_sa_status | ref | 1 | 期待: idx_sa_tenant_store_date |
| Q5 | cart_events 店舗+日付（P1-33 新規） | cart_events | idx_ce_store_date | range | 1 | ✅ 想定通り |
| Q6 | call_alerts 店舗+ステータス | call_alerts | idx_store_status | ref | 1 | ✅ 想定通り |
| Q7 | satisfaction_ratings JOIN orders（B-09 修正後） | sr→o | idx_store_created→PRIMARY | range/eq_ref | 1 | **Using temporary** |
| Q8 | attendance_logs tenant+store+ステータス | attendance_logs | idx_att_tenant_store_date | ref | 1 | ✅ |
| Q9 | stores tenant+active | stores | idx_store_tenant_slug | ref | 2 | filtered=10% |
| Q10 | shift_help_requests tenant+to_store+status | shift_help_requests | idx_shr_tenant | ref | 1 | 期待: idx_shr_to |
| Q11 | orders status のみ | orders | idx_order_status | ref | 1 | ✅ |
| Q12 | order_items JOIN orders | o→oi | idx_order_store→idx_oi_order | ref/ref | 1+3 | ✅ |

### 警告対象（要監視）

#### W1: Q1/Q2 — orders で composite 不採用

**現状**: `idx_order_store_created (store_id, created_at)` があるのに optimizer は単純 `idx_order_store` を選択。

**理由**: 本番データが小さく（7396行）、店舗別に絞ると 1 行になるため optimizer が composite を選ぶインセンティブがない。

**将来リスク**: 1 店舗あたり orders が 10 万件規模になると `idx_order_store` だけでは status フィルタリングコストが線形に増加。

**推奨**: `(store_id, status, created_at)` を追加すると、ステータス + 日付範囲クエリが covering index で完結する。
**ただし** `idx_order_store_created` と機能が重複するため要検討。

#### W2: Q3 — users で filtered=3.33%

**現状**: `idx_user_tenant` だけで 15 行返却 → role と is_active で 3.33% に絞る → 結果 0-1 行。

**将来リスク**: 数百ユーザー規模になると顕在化。

**推奨**: `(tenant_id, role, is_active)` の composite。テーブルが小さいので優先度低。

#### W3: Q4 — shift_assignments で idx_sa_status を選択

**現状**: 期待は `idx_sa_tenant_store_date` だが実測は `idx_sa_status (tenant_id, store_id, status)`。

**理由**: 現状 shift_assignments=120 行と少なく、status フィルタの方が選択性が高い。

**将来**: 月100件規模になると optimizer は date 範囲インデックスを選ぶはず。**現状は問題なし**。

#### W4: Q7 — satisfaction_ratings JOIN で「Using temporary」

**現状**: `EXPLAIN` に `Using temporary` が出ている。GROUP BY o.staff_id のため一時表が必要。

**理由**: GROUP BY 列（o.staff_id）が JOIN 後に決まるため、ソートのため temp table 必須。

**改善案**: 機能影響なし（rows=1, データ少）。改善するなら satisfaction_ratings 側に staff_id を非正規化するしかないが過剰最適化。**触らない**。

#### W5: Q10 — shift_help_requests で idx_shr_to を不採用

**現状**: `idx_shr_to (tenant_id, to_store_id, status)` があるのに `idx_shr_tenant (tenant_id)` を選択。

**理由**: 現状 0 行 or 1 行のため optimizer が短い key を優先。

**将来**: ヘルプ要請が増えれば composite 採用。**現状は問題なし**。

---

## 結論

| 区分 | 件数 | 内容 |
|------|------|------|
| ✅ 問題なし | 8 件 | 既存インデックスで適切に処理 |
| ⚠️ 将来要監視 | 4 件 | 現状は OK だがデータグロースで composite が必要 |
| ❌ 即対応必要 | 0 件 | なし |

**現時点で追加インデックスを作成する必然性なし**。recommended_indexes.sql は将来用の参考実装としてコメントアウト状態で残す。

---

## 補足: full scan が起きないテーブル

スキャン対象 12 クエリすべてで `type` が `ref/range/eq_ref` のいずれか。`ALL`（full scan）はゼロ。

これは
1. 全クエリで `tenant_id` / `store_id` 絞り込みが入っている（マルチテナント原則の徹底）
2. 主要テーブルすべてに最低 1 つの composite index がある

ことの結果。設計時点での index 整備が功を奏している。

---

## 次の調査提案（オプション、別タスク）

1. **2 ヶ月後リグレッション**: order_items が 10 万件超えたら Q12 を再 EXPLAIN。
2. **`SHOW INDEX FROM orders` cardinality 監視**: `SELECT TABLE_NAME, INDEX_NAME, CARDINALITY FROM information_schema.STATISTICS WHERE TABLE_NAME = 'orders'` を定期取得。
3. **slow query log の有効化**: my.cnf で `slow_query_log = 1`, `long_query_time = 1` を設定すれば実運用での遅クエリが見える化される。さくらのレンタルでは権限的に難しい可能性あり要確認。
