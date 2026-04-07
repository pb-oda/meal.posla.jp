# L-3 シフト管理 — 検証プロンプト（Claude Code 向け）

> 目的: L-3 Phase 1 の実装を検証し、既存機能への影響がないことを確認する。
> 問題があれば修正し、問題がなければデプロイファイルリストを出力する。

---

## 前提

L-3 シフト管理・勤怠連携の Phase 1 が実装済み。
設計ドキュメント: `docs/L-3-shift-design.md`
コーダープロンプト: `docs/L-3-coder-prompt.md`

以下の検証を **順番に** 実施してください。各検証項目について「OK」または「問題あり（詳細）」を報告すること。

---

## 検証 1: 新規ファイルの構文チェック

### 1-1. PHP 構文チェック

以下の全ファイルに `php -l` を実行:

```
api/store/shift/settings.php
api/store/shift/templates.php
api/store/shift/assignments.php
api/store/shift/availabilities.php
api/store/shift/attendance.php
api/store/shift/summary.php
api/cron/auto-clock-out.php
```

**期待結果:** 全ファイルで `No syntax errors detected`

**問題があった場合:** 構文エラーを修正してから次に進む。

### 1-2. JS 構文チェック

以下の全ファイルに `node --check` を実行:

```
public/js/shift-manager.js
public/js/shift-calendar.js
public/js/attendance-clock.js
```

**期待結果:** エラーなし

**問題があった場合:** 構文エラーを修正してから次に進む。

### 1-3. SQL 構文確認

`sql/migration-l3-shift-management.sql` を読み、以下を確認:
- 全テーブルに `tenant_id` カラムがあること
- `shift_assignments`, `shift_availabilities`, `attendance_logs` に `user_id` カラムがあること（`staff_id` ではない）
- 全テーブルの PRIMARY KEY が `id VARCHAR(36)` であること（shift_settings は例外: PRIMARY KEY が `store_id`）
- plan_features への INSERT が `pro` と `enterprise` の2行あること
- MySQL 5.7 互換であること（JSON型を使っていないか、generated columns を使っていないか等）

**期待結果:** 全項目OK

---

## 検証 2: 既存コードの CLAUDE.md 準拠チェック

### 2-1. ES5 互換チェック

以下の3ファイルに `const`, `let`, `=>`, `async`, `await` が含まれていないことを確認:

```
public/js/shift-manager.js
public/js/shift-calendar.js
public/js/attendance-clock.js
```

確認コマンド例:
```bash
grep -n 'const \|let \|=>\|async \|await ' public/js/shift-manager.js public/js/shift-calendar.js public/js/attendance-clock.js
```

**期待結果:** ヒットなし

**問題があった場合:** `var` + コールバックパターンに書き換える。

### 2-2. プリペアドステートメントチェック

全 PHP ファイルで、SQL文字列に変数を直接埋め込んでいる箇所がないことを確認:

```bash
grep -n '\$_GET\|\$_POST\|\$body\[' api/store/shift/*.php | grep -v 'prepare\|execute\|json_error\|require_'
```

全クエリが `$pdo->prepare()` + `$stmt->execute([...])` パターンであること。

**期待結果:** 直接埋め込みなし

**問題があった場合:** プリペアドステートメントに書き換える。

### 2-3. マルチテナント境界チェック

全 PHP ファイルの SELECT / UPDATE / DELETE 文に `tenant_id = ?` が含まれていることを確認。

```bash
grep -n 'SELECT\|UPDATE\|DELETE\|INSERT' api/store/shift/*.php
```

各 SQL 文を確認し、`tenant_id` の絞り込みがないクエリがあれば報告。

**期待結果:** 全クエリに tenant_id 絞り込みあり（INSERT は除く。INSERT は tenant_id カラムに値を入れていればOK）

### 2-4. XSS対策チェック

JS ファイルで `innerHTML` に値を挿入している箇所が全て `esc()` (= `Utils.escapeHtml()`) を経由していることを確認。

```bash
grep -n 'innerHTML' public/js/shift-manager.js public/js/shift-calendar.js public/js/attendance-clock.js
```

innerHTML に代入している文字列内のユーザー入力値（名前・メモ等）が `esc()` で囲まれていること。

**期待結果:** 全箇所エスケープ済み

### 2-5. レスポンス形式チェック

全 PHP ファイルが `api/lib/response.php` の `json_response()` / `json_error()` を使用していること。
`echo json_encode()` を直接呼んでいる箇所がないことを確認。

```bash
grep -n 'echo json_encode' api/store/shift/*.php
```

**期待結果:** ヒットなし（auto-clock-out.php は CLI 専用なので echo は許容）

---

## 検証 3: 既存ファイルへの影響確認

### 3-1. dashboard.html の既存タブ破壊チェック

`public/admin/dashboard.html` を読み、以下を確認:

1. 既存の全グループタブボタンが残っていること:
   - menu, plan, table, inventory, report, config, takeout, ai

2. 既存の全サブタブボタンが残っていること:
   - hq-categories, option-groups, store-menu, local-menu
   - time-plans, courses
   - tables, floor-map
   - inventory
   - sales, register, orders, turnover, staff-report, basket
   - settings, kds-stations, staff-mgmt, audit-log, receipt-settings
   - takeout-orders
   - ai-assistant

3. 新規追加されたシフトタブの `data-group="shift"` が既存のグループ名と衝突していないこと

4. 既存の script タグが全て残っていること（特に以下は削除禁止）:
   - `offline-detector.js`
   - `session-monitor.js`
   - 全ての既存 js/* ファイル

5. `activateTab()` 関数の switch 文で、既存の全 case が残っていること

**確認方法:**
```bash
# 既存タブが残っているか
grep -c 'data-group="menu"' public/admin/dashboard.html    # 期待: 1以上
grep -c 'data-group="report"' public/admin/dashboard.html  # 期待: 1以上
grep -c 'data-tab="sales"' public/admin/dashboard.html     # 期待: 1以上
grep -c 'data-tab="floor-map"' public/admin/dashboard.html # 期待: 1以上

# 既存scriptタグが残っているか
grep -c 'offline-detector.js' public/admin/dashboard.html  # 期待: 1
grep -c 'session-monitor.js' public/admin/dashboard.html   # 期待: 1
grep -c 'admin-api.js' public/admin/dashboard.html         # 期待: 1
grep -c 'ai-assistant.js' public/admin/dashboard.html      # 期待: 1

# activateTab の既存 case が残っているか
grep 'case .hq-categories' public/admin/dashboard.html     # 期待: 1
grep 'case .ai-assistant' public/admin/dashboard.html      # 期待: 1
```

**期待結果:** 全て1以上

**問題があった場合:** 既存のタブ・スクリプトが削除されていれば復元する。これは致命的なバグ。

### 3-2. owner-dashboard.html の既存タブ破壊チェック

`public/admin/owner-dashboard.html` を読み、以下を確認:

1. 既存タブが全て残っていること:
   - cross-store, abc-analytics, stores, users, ai-assistant, subscription, payment, external-pos

2. 新規追加の `shift-overview` タブが既存のタブの間（paymentの後、external-posの前）に配置されていること

3. 既存の script タグが全て残っていること

**確認方法:**
```bash
grep -c 'data-tab="cross-store"' public/admin/owner-dashboard.html   # 期待: 1
grep -c 'data-tab="subscription"' public/admin/owner-dashboard.html  # 期待: 1
grep -c 'data-tab="external-pos"' public/admin/owner-dashboard.html  # 期待: 1
grep -c 'owner-app.js' public/admin/owner-dashboard.html             # 期待: 1
```

### 3-3. owner-app.js の既存機能破壊チェック

`public/admin/js/owner-app.js` を読み、以下を確認:

1. `activateTab()` の既存 case が全て残っていること:
   - cross-store, abc-analytics, stores, users, ai-assistant, subscription, payment, external-pos

2. 新規追加の `shift-overview` case が既存 case の間に正しく挿入されていること

3. 既存の `initApp()`, `loadSubscriptionStatus()`, `loadApiKeyManager()`, `loadSmaregiStatus()` 関数が変更されていないこと

**確認方法:**
```bash
grep "case '" public/admin/js/owner-app.js
```

**期待結果:** 既存8 case + 新規1 case = 9 case が存在

### 3-4. admin.css の既存スタイル破壊チェック

`public/admin/css/admin.css` の変更が**末尾への追記のみ**であることを確認。

```bash
# 最後の既存スタイル（L-3追加前）
grep -n 'bar-row__fill--warning' public/admin/css/admin.css
# L-3 追加セクションの開始
grep -n 'L-3.*シフト管理' public/admin/css/admin.css
```

**期待結果:** `bar-row__fill--warning` の後に `L-3` セクションが来ている。既存のスタイルルールの間に挿入されていないこと。

---

## 検証 4: API 認証・認可チェック

### 4-1. 認証チェック

全 API ファイルの先頭で以下が呼ばれていることを確認:

```php
start_auth_session();
handle_preflight();
$method = require_method([...]);
$user = require_auth();
```

### 4-2. プランチェック

全 API ファイルで `check_plan_feature($pdo, $tenantId, 'shift_management')` が呼ばれていることを確認。

### 4-3. ロール制限チェック

以下のロール制限が正しいことを確認:

| エンドポイント | GET | POST | PATCH | DELETE |
|---------------|-----|------|-------|--------|
| settings.php | manager+ | - | manager+ | - |
| templates.php | manager+ | manager+ | manager+ | manager+ |
| assignments.php | staff+（staffは自分のみ）| manager+ | manager+ | manager+ |
| availabilities.php | staff+（staffは自分のみ）| staff+ | staff+（staffは自分のみ）| - |
| attendance.php | staff+（staffは自分のみ）| staff+（打刻）| manager+（修正）| - |
| summary.php | manager+ | - | - | - |

### 4-4. store_id アクセス制御

全 API ファイルで `require_store_access($storeId)` が呼ばれていることを確認。

---

## 検証 5: データベース整合性

### 5-1. 既存テーブルへの影響

`sql/migration-l3-shift-management.sql` に既存テーブルへの `ALTER TABLE` がないことを確認。
全て `CREATE TABLE` と `INSERT INTO plan_features` のみであること。

### 5-2. テーブル名重複チェック

新規テーブル名が既存の schema.sql および全 migration ファイルのテーブル名と重複していないことを確認:

```bash
grep 'CREATE TABLE' sql/schema.sql sql/migration-*.sql | grep -i 'shift_\|attendance_'
```

**期待結果:** `migration-l3-shift-management.sql` のみがヒット

---

## 検証 6: IIFE パターン検証

### 6-1. JS モジュールの IIFE 構造

3つのJSファイルが全て `(function() { ... })();` パターンで囲まれていること。
グローバルに公開されるオブジェクトが `window.ShiftManager`, `window.ShiftCalendar`, `window.AttendanceClock` のみであること。

```bash
grep 'window\.' public/js/shift-manager.js public/js/shift-calendar.js public/js/attendance-clock.js
```

**期待結果:** 各ファイルで `window.XXX = XXX;` が1行のみ

---

## 検証完了後の対応

### 全項目 OK の場合

以下のフォーマットでデプロイファイルリストを出力:

```
=== L-3 シフト管理 Phase 1 デプロイファイル ===

【新規ファイル】
sql/migration-l3-shift-management.sql
api/store/shift/settings.php
api/store/shift/templates.php
api/store/shift/assignments.php
api/store/shift/availabilities.php
api/store/shift/attendance.php
api/store/shift/summary.php
api/cron/auto-clock-out.php
public/js/shift-manager.js
public/js/shift-calendar.js
public/js/attendance-clock.js

【変更ファイル】
public/admin/dashboard.html
public/admin/owner-dashboard.html
public/admin/js/owner-app.js
public/admin/css/admin.css

【マイグレーション手順】
1. sql/migration-l3-shift-management.sql を本番DBに適用
2. 上記ファイルを全てデプロイ
3. cron設定: */5 * * * * php /path/to/api/cron/auto-clock-out.php >> /var/log/posla-auto-clockout.log 2>&1

【デプロイ後の動作確認】
1. manager でログイン → シフト管理タブが表示されること
2. テンプレート作成・一覧・編集・削除
3. テンプレート適用でシフト一括生成 → 週間カレンダーに表示
4. シフト公開 → staff ログインでマイシフトに表示
5. 出勤打刻 → 退勤打刻 → 勤怠一覧に記録
6. owner ログイン → シフト概況タブで店舗別サマリー表示
7. 既存タブ（メニュー管理・レポート・設定等）が正常に動作すること
```

### 問題があった場合

1. 問題の詳細と影響範囲を報告
2. 修正を実施
3. 修正後に該当する検証項目を再実行
4. 全項目 OK になるまで繰り返す
5. 最終的にデプロイファイルリストを出力
