# P1-12 空 catch ブロック棚卸しレポート

**スキャン日**: 2026-04-08
**対象**: `api/**/*.php`
**目的**: B-PROC-01a で発覚した silent failure 系統バグ（B-09: staff-report.php avgRating 集計 try/catch サイレント失敗）の網羅調査

---

## サマリー

| 指標 | 値 |
|------|---|
| 検出ファイル数 | 42 |
| 空 catch 総数 | 101 |
| Pattern A（中身ゼロ `catch (X $e) {}`） | 34 |
| Pattern D（コメントのみ） | 67 |

### リスク分布

| Risk | 件数 | 説明 |
|------|------|------|
| **High** | 50 | SELECT/JOIN でデータ取得 → 失敗時は空配列/null フォールバック。collation/権限/論理エラーがサイレントに握り潰される（B-09 と同型） |
| **Medium** | 17 | INSERT/UPDATE 書き込み失敗を握り潰す。データ消失検知不可 |
| **Low** | 34 | `SELECT 1 FROM xxx LIMIT 0` 単独のスキーマスニッフ。マイグレーション差分検知用、設計意図的 |

### 操作タイプ別

| 操作 | 件数 |
|------|------|
| select_fetch（単表 SELECT） | 34 |
| schema_sniff（LIMIT 0 単独） | 23 |
| join_select（JOIN 含む SELECT） | 16 |
| update | 14 |
| unknown（コメントのみ判定不能） | 11 |
| execute_unknown | 2 |
| insert | 1 |

---

## B-09 検証

**`api/store/staff-report.php:87`** (Pattern D, comment-only)
```php
} catch (PDOException $e) {
    // satisfaction_ratings テーブルが存在しない場合
}
```

✅ **CSV に捕捉済み** (`b09_related=1`、`risk=High`、`operation=join_select`)。

直前の try ブロック (line 70-86):
```sql
SELECT o.staff_id, AVG(sr.rating), COUNT(sr.id)
FROM satisfaction_ratings sr
JOIN orders o ON o.id = sr.order_id
WHERE sr.store_id = ? AND o.staff_id IS NOT NULL ...
GROUP BY o.staff_id
```

このクエリは P1-33 で照合順序を修正するまで「Illegal mix of collations」で常時失敗 → `$ratingByStaff = []` のまま `avgRating = null` 返却。コメントは「テーブル未作成時」と書かれていたが、実態は collation バグだった。

**教訓**: コメント文字列を信用せず、catch のスコープに来る例外は「想定通り」「想定外」を区別しない。

---

## High リスク（50件）— 優先確認対象

すべて「SELECT 系で例外を捨てている」パターン。コメント上は `テーブル未作成時はスキップ` だが、collation・権限・論理エラーも同じ catch で握り潰される。

### 上位ホットスポット（同一ファイル内に複数）

| ファイル | 件数 | 内訳 |
|---------|------|------|
| `api/customer/takeout-orders.php` | 6 件 | 設定/スロット/在庫/決済情報の取得が全て silent fail |
| `api/store/demand-forecast-data.php` | 4 件 | ドリンク除外名・在庫・レシピ・店舗名の全てが silent fail |
| `api/store/tables-status.php` | 4 件 | テーブルメモ・LO・進捗計算が silent fail |
| `api/customer/menu.php` | 3 件 | daily_recommendations / 価格制限 / 売上集計 |
| `api/customer/orders.php` | 3 件 | table_sessions チェック × 3 |
| `api/customer/table-session.php` | 3 件 | セッション存在チェック / 各カラム取得 |
| `api/kds/orders.php` | 3 件 | KDS ルーティング / アラート / 評価集計 |

### High カテゴリ全リスト（ファイル名昇順）

```
api/auth/login.php:95               [select_fetch]  user_sessions テーブル未作成時はスキップ
api/connect/status.php:31           [select_fetch]  カラム未存在時はスキップ
api/customer/checkout-confirm.php:191 [select_fetch] table_sessions 未存在時はスキップ
api/customer/menu.php:169           [select_fetch]  テーブル未作成時は空配列のまま
api/customer/menu.php:182           [select_fetch]  カラム未存在時はスキップ
api/customer/menu.php:202           [select_fetch]  order_items テーブル未作成時は空配列のまま
api/customer/order-status.php:132   [select_fetch]  satisfaction_ratings テーブル未作成
api/customer/orders.php:77          [select_fetch]  table_sessions 未作成時はスキップ
api/customer/orders.php:119         [select_fetch]  table_sessions 未作成時はスキップ
api/customer/orders.php:137         [select_fetch]  カラム未存在時はスキップ
api/customer/satisfaction-rating.php:65 [select_fetch] テーブル未作成 → INSERT で失敗する（下記 catch で処理）
api/customer/table-session.php:107  [join_select]   table_sessions テーブルが未作成の場合はスキップ
api/customer/table-session.php:178  [select_fetch]  カラム未存在時はスキップ
api/customer/table-session.php:197  [select_fetch]  スキップ
api/customer/takeout-orders.php:56  [select_fetch]  マイグレーション未適用時はデフォルト値のまま
api/customer/takeout-orders.php:72  [join_select]   (コメントなし)
api/customer/takeout-orders.php:115 [select_fetch]  (コメントなし)
api/customer/takeout-orders.php:190 [select_fetch]  (コメントなし)
api/customer/takeout-orders.php:245 [select_fetch]  (コメントなし)
api/customer/takeout-orders.php:294 [select_fetch]  (コメントなし)
api/customer/ui-translations.php:33 [select_fetch]  テーブル未作成時は空オブジェクト
api/kds/call-alerts.php:104         [select_fetch]  type カラムが無い場合は従来通り
api/kds/close-table.php:74          [join_select]   table_sessions/time_limit_plans 未作成時はスキップ
api/kds/orders.php:52               [join_select]   kds_stations / kds_routing_rules テーブル未作成時はスキップ
api/kds/orders.php:229              [select_fetch]  テーブル未作成時はスキップ
api/kds/orders.php:274              [select_fetch]  satisfaction_ratings テーブル未作成
api/kds/update-item-status.php:90   [join_select]   call_alerts に type カラムがない場合は無視
api/owner/analytics-abc.php:116     [join_select]   recipes/ingredients 未作成時はスキップ（原価=0で計算）
api/owner/cross-store-report.php:212 [select_fetch] cart_events テーブル未作成 — スキップ ★P1-33で作成済
api/owner/option-groups.php:306     [join_select]   テーブル未作成時
api/public/store-status.php:60      [join_select]   table_sessions テーブル未作成 → 0
api/store/course-csv.php:54         [select_fetch]  course_phases 未作成時はスキップ
api/store/course-csv.php:140        [join_select]   course_phases 未作成時はスキップ
api/store/demand-forecast-data.php:39  [join_select]   (コメントなし)
api/store/demand-forecast-data.php:142 [select_fetch]  (コメントなし)
api/store/demand-forecast-data.php:167 [join_select]   (コメントなし)
api/store/demand-forecast-data.php:186 [select_fetch]  (コメントなし)
api/store/handy-order.php:153       [join_select]   テーブル未作成時はスキップ
api/store/handy-order.php:168       [select_fetch]  テーブル未作成時はスキップ
api/store/menu-version.php:36       [select_fetch]  テーブル未存在時はスキップ
api/store/menu-version.php:49       [select_fetch]  テーブル未存在時はスキップ
api/store/process-payment.php:118   [join_select]   table_sessions/time_limit_plans 未作成時はスキップ
api/store/shift/ai-suggest-data.php:213 [select_fetch] (コメントなし、店舗名取得)
api/store/staff-report.php:87       [join_select]   ★B-09 satisfaction_ratings JOIN（P1-33で照合順序修正済）
api/store/tables-status.php:63      [join_select]   table_sessions 未作成時はスキップ
api/store/tables-status.php:84      [select_fetch]  skip
api/store/tables-status.php:113     [join_select]   order_items テーブル未作成時はスキップ
api/store/tables-status.php:168     [select_fetch]  カラム未存在時はデフォルト値
api/store/translate-menu.php:122    [select_fetch]  テーブル未作成の場合は全て翻訳
api/store/turnover-report.php:38    [select_fetch]  カラムが存在しない場合はデフォルト値のまま
```

---

## Medium リスク（17件）— 書き込み失敗を握り潰し

INSERT/UPDATE の失敗を `catch (X) {}` で隠している。データ消失が検知不能。

```
api/auth/change-password.php:68     [update]  user_sessions
api/auth/logout.php:34              [update]  user_sessions
api/customer/checkout-confirm.php:261 [update] table_sessions
api/customer/checkout-confirm.php:307 [update] recipes/ingredients
api/customer/takeout-orders.php:373 [update]  orders memo (stripe_session)
api/customer/takeout-orders.php:381 [update]  C-3 stripe-connect フォールスルー
api/customer/takeout-orders.php:401 [update]  orders memo (stripe_session)
api/customer/takeout-payment.php:142 [insert] payments記録失敗（注文ステータスは更新済）
api/kds/call-alerts.php:152         [execute_unknown] order_items 未作成時
api/kds/close-table.php:118         [update]  table_sessions
api/kds/orders.php:308              [execute_unknown] order_items 未作成時
api/owner/cross-store-report.php:71 [update]  daily_recommendations
api/store/process-payment.php:345   [update]  table_sessions (merge)
api/store/process-payment.php:388   [update]  table_sessions (split)
api/store/translate-menu.php:227    [update]  menu_translations 重複
... (他 2 件は CSV 参照)
```

---

## Low リスク（34件）— スキーマスニッフ

`SELECT 1 FROM xxx LIMIT 0` または `SELECT col FROM xxx LIMIT 0` 単独。マイグレーション差分検知用で設計意図的。`$hasFoo = true` フラグ用途。

例:
```php
$hasMergedCols = false;
try {
    $pdo->query('SELECT merged_table_ids FROM payments LIMIT 0');
    $hasMergedCols = true;
} catch (PDOException $e) {}
```

これは触らなくてよい（マイグレーション順序非依存にするための意図的なフォールバック）。

---

## 提案: 改修方針（実装は別タスク）

### Tier 1: B-09 と同型のリスク撲滅（High カテゴリ 50件）

**選択肢A: 全箇所に `error_log()` 追加**（最小変更）
```php
} catch (PDOException $e) {
    error_log('[silent_fail] ' . __FILE__ . ':' . __LINE__ . ' ' . $e->getMessage());
    // 既存コメント
}
```
- 利点: 動作変更なし。本番ログから silent fail 発生を可視化できる
- 欠点: ログを能動的に見ないと気づかない

**選択肢B: 「テーブル不在」と「それ以外」を区別**
```php
} catch (PDOException $e) {
    // SQLSTATE 42S02 = base table or view not found
    if ($e->getCode() !== '42S02') {
        error_log('[unexpected_db_error] ' . __FILE__ . ':' . __LINE__ . ' ' . $e->getMessage());
        throw $e; // または json_error
    }
    // テーブル未作成時はスキップ
}
```
- 利点: 想定内エラー（migration 未適用）と想定外エラー（collation/権限/論理）を分離
- 欠点: 50箇所書き換え、後方互換のリスクあり

**選択肢C: ヘルパー関数化**
```php
// api/lib/db.php に追加
function safe_query_or_default($pdo, $callback, $default = null) {
    try {
        return $callback($pdo);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') {
            error_log('[unexpected] ' . $e->getMessage());
        }
        return $default;
    }
}
```
- 利点: 一元管理。新規箇所はヘルパー使用を強制可能
- 欠点: 既存 50 箇所の書き換え範囲が大きい

### Tier 2: Medium カテゴリ 17件

INSERT/UPDATE の silent fail は `error_log()` を必ず追加。データ整合性監視のために production ログ必須。

### Tier 3: Low カテゴリ 34件

触らない。設計意図的なスキーマスニッフ。

---

## 既存動作への影響

- 本タスクは **スキャンのみ**。コード変更なし。
- 改修方針は別タスクで承認後に実施。

---

## 出力ファイル

- `_scan_20260408_p112/empty_catches.csv` （101 行 + ヘッダ）
- `_scan_20260408_p112/empty_catches_report.md` （本ファイル）
