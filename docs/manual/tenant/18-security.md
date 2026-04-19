---
feature_id: SECURITY
title: セキュリティとアカウント管理
chapter: 18
plan: [all]
role: [owner, manager, staff]
audience: 全員
keywords: [セキュリティ, パスワード, 監査ログ, セッション管理, S1-S6, CP1, CB1, PIN, レートリミット, エラー監視, Slack]
related: [02-login, 16-settings, 25-table-auth]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 18. セキュリティとアカウント管理

POSLAのセキュリティ機能とアカウント管理のベストプラクティスについて説明します。

## 18.0 はじめにお読みください

このページは「POSLA のセキュリティ機能をテナント側で正しく運用するための、すべての設定と仕組み」を、初心者向けに体系的にまとめた完全ガイドです。

::: tip この章を読む順番（おすすめ）
1. **18.0.1 セキュリティ全体像** で POSLA がどう守られているかを把握
2. **18.6 アカウント管理のベストプラクティス** で日常運用ルールを徹底
3. PIN 関連 → **18.Z 担当スタッフ PIN（CP1）+ レジ金庫 PIN（CB1d）**
4. 監視 → **18.7 監査ログ** + **18.8 エラー監視（CB1）**
5. 不正・障害発生時 → **18.Y トラブルシューティング** と **18.X FAQ 30 件**
:::

### 18.0.1 セキュリティ全体像（CP1 + CB1 統合図）

```
┌─ 認証層 ──────────────────────────────────────┐
│ ・ログイン       (api/auth/login.php)          │
│   ユーザー名+パスワード、bcrypt、セッション固定対策 │
│ ・パスワードポリシー (api/lib/password-policy.php)│
│   8文字+英字+数字、ハッシュ password_hash()     │
│ ・セッション管理 (api/lib/auth.php)            │
│   8h Cookie / 30 分アイドル / リモートログアウト │
└────────────────────────────────────────────────┘
                    ↓
┌─ 認可層 ──────────────────────────────────────┐
│ ・ロール階層     owner > manager > staff > device│
│ ・店舗境界       require_store_access()        │
│ ・テナント境界   全 SQL に tenant_id / store_id │
└────────────────────────────────────────────────┘
                    ↓
┌─ 操作実行層（PIN 必須化、CP1 + CB1d） ─────────┐
│ ・POSレジ会計    process-payment.php (CP1)     │
│ ・返金           refund-payment.php  (CP1b)    │
│ ・レジ開け/閉め  cash-log.php?type=open/close  │
│ ・現金入金/出金  cash-log.php?type=cash_in/out │
│   → 出勤中スタッフのみ PIN 受付（退勤後は無効）│
└────────────────────────────────────────────────┘
                    ↓
┌─ 監視層 ──────────────────────────────────────┐
│ ・監査ログ       audit_log（誰が・何を・いつ） │
│ ・エラーログ     error_log（CB1、API 失敗全件）│
│ ・5 分 cron      monitor-health.php            │
│   error_log 集計 → monitor_events 昇格         │
│   → Slack 通知 + メール（連続 3 件で）          │
└────────────────────────────────────────────────┘
```

### 18.0.2 「いつ何が記録されるか」サマリー

| 操作 | 記録先 | 記録項目 | 認証方式 |
|---|---|---|---|
| ログイン成功 | `audit_log`(`login`) + `user_sessions` | user_id / IP / device_label | ID + パスワード |
| ログイン失敗 | `error_log`(`INVALID_CREDENTIALS`) | IP / 入力 username | — |
| パスワード変更 | `audit_log`(`password_change`) | 自他フラグ | 現パスワード or manager+ |
| POSレジ会計 | `audit_log`(`payment_complete`) + `payments` | 金額 / 担当 / 支払方法 | **PIN（CP1）** |
| 返金 | `audit_log`(`payment_refund`) + `payments.refunded_by` | 金額 / 理由 / 元 payment | **PIN（CP1b）** |
| レジ開け / 閉め | `audit_log`(`register_open`/`close`) + `cash_log` | 初期金額 / 差額 | **PIN（CB1d）** |
| 現金入金 / 出金 | `audit_log`(`cash_in`/`cash_out`) + `cash_log` | 金額 / メモ | **PIN（CB1d）** |
| メニュー編集 | `audit_log`(`menu_update`) | old_value / new_value | パスワード |
| API エラー全般 | `error_log` | code / errorNo / HTTP / IP | — |

---

## 18.1 認証とセッション管理

### ログイン認証

- **ユーザー名 + パスワード**で認証（メールアドレス不要、`api/auth/login.php`）
- ログイン成功時に `session_regenerate_id(true)` でセッションIDを再生成（セッション固定攻撃対策）
- ログイン成功時に `audit_log` に `event='login'` として記録
- `user_sessions` にセッション情報を記録（`tenant_id` / `user_id` / `session_id` / `device_label` / `ip_address` / `login_at`）
- `ip_address` は `REMOTE_ADDR` のみ。`X-Forwarded-For` はスプーフ可能のため信頼しない

::: warning ログイン失敗のアカウントロック機能は **未実装** (2026-04-18 時点)
`login.php` には試行回数カウントや一時ロックの処理はありません。連続失敗しても即再試行できます。ブルートフォース対策としては、同じテーブル QR のPIN検証（`check_rate_limit('pin-verify:' . $tableId, 5, 600)`）など、**認証以外の箇所では** レートリミットが入っています。将来的に login にもレートリミットを入れる予定です。
:::

### セッションの有効期限

| 設定 | 値 | 実装 |
|------|-----|------|
| セッション Cookie TTL | **8時間** | `api/lib/auth.php` L24 `'lifetime' => 28800` |
| 無操作タイムアウト警告 | **25分** | `public/js/session-monitor.js` L13 `WARNING_MS` |
| 自動ログアウト | **30分** | サーバー側 `/api/auth/check.php` が `SESSION_TIMEOUT` 返却 |

**タイムアウトの流れ：**
1. 25分間操作がないと警告モーダルが表示されます：「セッションタイムアウト」「5分以内に操作がないとログアウトされます」
2. 「操作を続ける」ボタンを押すと `/api/auth/check.php` が呼ばれ、`last_active_at` がリセットされます
3. 5分以内に操作がない場合、サーバーが `SESSION_TIMEOUT` / `SESSION_REVOKED` を返し、JS が `/admin/index.html` にリダイレクトします
4. 以下のイベントでタイマーは自動リセットされます: `click` / `keydown` / `scroll` / `touchstart`（`session-monitor.js` L130）

### マルチデバイスログイン

- 同じアカウントで複数端末から同時ログイン可能
- ログイン時に同じユーザーの `user_sessions.is_active=1` を count し、2 件以上なら `login.php` のレスポンスに `warning: '他の端末でもログインしています'` と `active_sessions: <数>` を返す

::: warning 警告の画面表示は未実装（2026-04-18 時点）
`login.php` は `warning` / `active_sessions` を返しますが、`public/admin/index.html` のログイン成功処理はこの警告を **画面に表示しません**（成功時は即リダイレクトのみ）。サーバー側で記録されているので `user_sessions` テーブルを直接クエリすれば監査可能です。警告モーダル化の UI タスクは未着手。
:::

### リモートログアウト（API のみ実装、UI は未着手）

`api/store/active-sessions.php` は manager 以上から呼び出せる API として実装されています。

- `GET /api/store/active-sessions.php?store_id=xxx` — アクティブセッション一覧
- `DELETE /api/store/active-sessions.php` — 対象セッションを `is_active=0` に更新

返却フィールド: `id`, `user_id`, `username`, `display_name`, `role`, `session_id`, `device_label`, `ip_address`, `login_at`, `last_active_at`。

::: warning UI は未実装
管理ダッシュボード内にこれを使う画面（セッション一覧タブ・リモートログアウトボタン）は **ありません**（2026-04-18 時点）。POSLA 運営が `curl` / DB 直参照で対応するオペレーションです。将来的に管理画面に組み込み予定。
:::

---

## 18.2 複数店舗管理とロール権限

POSLAは複数店舗を1つの企業アカウントで管理できます。店舗ごとにデータは分離されており、各ユーザーは所属する店舗のデータだけにアクセスできます。

### ロールベースアクセス制御

| ロール | アクセス範囲 |
|--------|------------|
| オーナー | 企業アカウント内の全店舗・全機能 |
| マネージャー | 所属店舗の管理機能 |
| スタッフ | 所属店舗の限定機能（シフト・勤怠・POS操作） |

### 店舗切替

オーナーはオーナーダッシュボードの店舗選択ドロップダウンから、管理する店舗を切り替えられます。レポート・シフト・AIアシスタント等のタブは選択中の店舗のデータを表示します。

### 店舗間のデータ分離

- マネージャー・スタッフは自分が所属する店舗のデータのみ閲覧・編集できます
- 他店舗の売上・注文・スタッフ情報にはアクセスできません
- オーナーのみが全店舗を横断してデータを確認できます

---

## 18.3 SQLインジェクション対策

全てのSQLクエリはプリペアドステートメント（バインドパラメータ）を使用しています。例外はありません。

---

## 18.4 XSS対策

HTMLへの動的挿入は全て`Utils.escapeHtml()`関数を通してエスケープされます。

---

## 18.5 冪等性キー

セルフオーダーの注文送信には冪等性キー（Idempotency Key）が使用されます。ネットワーク障害でリトライが発生しても、同じ注文が重複して作成されることはありません。

---

## 18.6 アカウント管理のベストプラクティス

### 1人1アカウント（必須）

POSLAを利用する全スタッフに個別のユーザーアカウントを作成してください。「kitchen」「hall」などの共有アカウントは禁止です。

**理由：**
- 監査ログが「誰が操作したか」を追跡します
- 勤怠打刻がスタッフ個人に紐づきます
- スタッフ評価レポートが個人単位で集計されます
- シフト管理が個人単位で行われます

### パスワード管理

- 初期パスワードはオーナーまたはマネージャーが設定し、口頭でスタッフに伝えます
- スタッフは初回ログイン後に**画面右上の「パスワード変更」ボタン**から自分でパスワードを変更できます
- 推測されやすいパスワード（1234、password等）は避けてください

### パスワードポリシー

POSLAでは以下のパスワード要件をシステム側で強制しています。要件を満たさないパスワードは登録・変更できません。

| 要件 | 内容 |
|------|------|
| 最低長 | **8文字以上** |
| 英字 | **A-Z または a-z を1文字以上**含む |
| 数字 | **0-9 を1文字以上**含む |

例：`tanaka123` ✅ / `Yamada2026` ✅ / `shibuya2024` ✅
NG例：`abcdefgh`（数字なし）、`12345678`（英字なし）、`abc123`（8文字未満）

::: tip 適用範囲
パスワードポリシーは以下の操作で全て適用されます。
- 新規ユーザー作成時（オーナー / マネージャーが他のスタッフを作成）
- 既存ユーザーのパスワード更新（オーナー / マネージャーがリセット）
- 自分のパスワード変更（画面右上の「パスワード変更」ボタン）
- POSLA管理者の自己パスワード変更
:::

### 自分のパスワード変更によるセッション保護

「パスワード変更」ボタンから自分でパスワードを変更すると、セキュリティのため**他の端末でログイン中の同じアカウントのセッションが自動的に無効化**されます。

- いま操作している端末はそのまま継続して使えます
- 他の端末（共有タブレット等）は次回操作時にログイン画面にリダイレクトされます
- パスワード漏洩の疑いがある場合は、まず自分でパスワードを変更することで他端末を遮断できます

### 退職者のアカウント処理

スタッフが退職した場合：
1. アカウントを**無効化**します（削除ではなく無効化を推奨）
2. アクティブセッションがあれば**リモートログアウト**します
3. 無効化したアカウントではログインできなくなります
4. 過去の監査ログ・勤怠記録は保持されます

### 共有端末の管理

KDSやPOSレジなど共有タブレットを使用する場合：
- 退勤時には必ずログアウトしてください
- ログアウトしないと、次のスタッフの操作が前のスタッフのアカウントで記録されます
- 出退勤ボタンを押してからログアウトする習慣をつけてください

---

## 18.6-b 担当スタッフ PIN ポリシー（CP1 / CB1d 詳細）

POSレジ・現金管理関連の重要操作には全て **担当スタッフ PIN（4〜8 桁数字）** が必要です。device アカウントでログイン中の端末でも、操作の都度 PIN で「誰が実行したか」を特定します。

### 18.6-b.1 PIN が必須化されている操作（CB1d 完全網羅）

| 操作 | エンドポイント | 監査アクション | エラーコード（PIN 不一致時） |
|---|---|---|---|
| **POSレジ会計** | `POST /api/store/process-payment.php` | `payment_complete` | `PIN_INVALID` (401) |
| **返金** | `POST /api/store/refund-payment.php` | `payment_refund` | `PIN_INVALID` (401) |
| **レジ開け** | `POST /api/kds/cash-log.php` (`type=open`) | `register_open` | `PIN_INVALID` (401) |
| **レジ閉め** | `POST /api/kds/cash-log.php` (`type=close`) | `register_close` | `PIN_INVALID` (401) |
| **現金入金** | `POST /api/kds/cash-log.php` (`type=cash_in`) | `cash_in` | `PIN_INVALID` (401) |
| **現金出金** | `POST /api/kds/cash-log.php` (`type=cash_out`) | `cash_out` | `PIN_INVALID` (401) |
| **現金売上** | `POST /api/kds/cash-log.php` (`type=cash_sale`) | （`payment_complete` で記録） | PIN 検証なし（process-payment 経由） |

### 18.6-b.2 PIN 仕様

| 項目 | 値 |
|---|---|
| 桁数 | **4〜8 桁の数字のみ**（`/^\d{4,8}$/`） |
| ハッシュ | **bcrypt**（`password_hash($pin, PASSWORD_BCRYPT)`） |
| 保存先 | `users.cashier_pin_hash` + `users.cashier_pin_updated_at` |
| 弱 PIN 拒否 | `1234` / `12345` / `123456` / `1234567` / `12345678` / `0000` / `00000` / `000000` / `00000000` / ゾロ目（`1111`/`2222` 等） |
| 対象ロール | `staff`, `manager`, `owner`（**device は不可**） |
| 出勤判定 | `attendance_logs.clock_in IS NOT NULL AND clock_out IS NULL`（当日分のみ） |

### 18.6-b.3 PIN 検証フロー（重要）

PIN 入力時、サーバは以下の SQL で「**現在出勤中で PIN を設定しているスタッフ**」を全件取得し、`password_verify()` で順に照合します。

```sql
SELECT u.id, u.display_name, u.cashier_pin_hash
FROM users u
INNER JOIN attendance_logs ar
  ON ar.user_id = u.id AND ar.store_id = ?
     AND DATE(ar.clock_in) = ? AND ar.clock_out IS NULL
WHERE u.tenant_id = ? AND u.is_active = 1
      AND u.role IN ('staff', 'manager', 'owner')
      AND u.cashier_pin_hash IS NOT NULL
```

::: warning 退勤打刻 = PIN 自動無効化
退勤打刻したスタッフは `clock_out IS NOT NULL` になるため、上記 JOIN から外れて PIN 照合の対象外になります。これが CP1 の核心設計で、**退職者の PIN 悪用を物理的に防ぎます**。
:::

### 18.6-b.4 PIN 関連の監査ログイベント

| イベント | 記録タイミング | 記録内容 |
|---|---|---|
| `cashier_pin_set` | PIN 新規設定・変更時 | `target_username` / `is_own` |
| `cashier_pin_used` | PIN 一致した会計成功時 | `cashier_user_id` / `total` / `payment_method` |
| `cashier_pin_failed` | PIN 不一致時 | `pin_length` / `context`（`refund` / `cash_log:open` 等） |

::: tip ブルートフォース調査
`pin_length` のみ記録（PIN 値自体は記録しない）。連続失敗を `audit_log` で集計すれば「特定店舗で短時間に PIN 試行が多発」のような攻撃パターンを検知できます。
:::

---

## 18.7 監査ログに記録される操作（2026-04-19 拡充）

POSLA では「誰が・いつ・何を・どの店舗で」やったかを `audit_log` テーブルに記録しています。閲覧は管理ダッシュボード「店舗設定」→「監査ログ」サブタブから（manager 以上）。

### 18.7.1 会計・レジ関連の操作（CP1 + CB1d、2026-04-19 拡張）

| アクション | 記録内容 |
|---|---|
| `payment_complete` | 会計完了（金額・支払方法・テーブル・担当スタッフ user_id） |
| `payment_refund` | **返金実行**（金額・理由・元 payment_id・実行者） |
| `cashier_pin_used` | PIN による担当スタッフ特定が成功（出勤中スタッフを照合） |
| `cashier_pin_failed` | PIN 不一致（ブルートフォース調査用、入力 PIN は記録しない） |
| `cashier_pin_set` | PIN 設定・変更 |
| `register_open` | レジ開け（初期金額・`pin_verified=1`・`cashier_user_id`・`cashier_user_name`） |
| `register_close` | レジ締め（実際残高・予想残高・差額・`pin_verified=1`・`cashier_user_id`・`cashier_user_name`） |
| `cash_in` | 現金入金（金額・メモ・`pin_verified=1`・`cashier_user_id`・`cashier_user_name`） |
| `cash_out` | 現金出金（金額・メモ・`pin_verified=1`・`cashier_user_id`・`cashier_user_name`） |

::: warning レジ金庫操作にも担当スタッフ PIN が必須（CB1d、2026-04-19 追加）
**レジ開け / レジ閉め / 入金 / 出金** の 4 操作にも担当スタッフ PIN 入力が必須になりました。会計・返金と同じく出勤中スタッフのみ照合対象で、退勤打刻したスタッフの PIN は無効化されます。

- マネージャーも例外なし（バイパス禁止）
- 出金は店舗の現金が外に出る最も危険な操作。誰が・いつ・何のために動かしたかを必ず追跡できる状態に
- `cash_log.user_id` には PIN 認証されたスタッフ user_id が記録されます（device 端末のユーザーではない）
- 「現金が動くすべての操作は、PIN 認証された担当者と紐づく」が CB1d の設計原則
- 詳細は [8 章 8.6 レジ開閉操作](./08-cashier.md#86-レジ開閉操作) / [8.10-b.7](./08-cashier.md#810-b7-pin-が要求される操作2026-04-19-cb1d-で完全網羅) 参照
:::

::: warning 返金には担当スタッフ PIN が必須（2026-04-19 追加）
返金処理（POSレジ → 取引履歴 → 【返金】ボタン）も会計と同様に **担当スタッフ PIN 入力が必須** になりました。誰が返金を実行したかを `payments.refund_user_id` および `audit_log` の `payment_refund` レコードに記録します。

device アカウントでログイン中でも、PIN 入力により実際に返金を承認した「人間スタッフ」の特定が可能です。退勤打刻したスタッフの PIN は無効化されます（[8 章 8.10-b](./08-cashier.md#810-b-担当スタッフ-pin-認証-cp12026-04-19-追加) 参照）。
:::

### 18.7.2 監査ログ画面の表示改善（2026-04-19）

監査ログの「詳細」欄は色付きバッジ + 日本語ラベルで表示されるようになりました（`public/admin/js/audit-log-viewer.js`）。

- 注文ステータス: `pending` → 受付 / `preparing` → 調理中 / `ready` → 提供待ち / `served` → 提供済 / `paid` → 会計済
- 金額: `¥1,630` 形式（カンマ区切り + 円記号）
- boolean 値: ✓ / × アイコンで視覚化
- 操作種別プルダウンに会計関連（`payment_complete` / `payment_refund` / `register_open` 等）が追加

### 18.7.3 監査ログ全アクション一覧（2026-04-19 時点）

`audit_log.action` カラムに記録される全操作種別。閲覧は管理ダッシュボード「店舗設定 → 監査ログ」サブタブから（manager 以上）。

| カテゴリ | アクション | トリガー API | 記録される情報 |
|---|---|---|---|
| **認証** | `login` | `api/auth/login.php` | user_id / IP / device_label |
| | `logout` | `api/auth/logout.php` | user_id |
| | `auto_logout` | `_check_session_validity()` | アイドル秒数 |
| **メニュー** | `menu_create` | `api/store/local-items.php` | 新規限定品の name / price |
| | `menu_update` | `api/store/menu-overrides.php` | old_value / new_value |
| | `menu_soldout` | `api/store/menu-overrides.php` | is_sold_out フラグ |
| | `menu_delete` | `api/store/local-items.php` (DELETE) | 削除した品目 |
| **スタッフ** | `staff_create` | `api/store/staff-management.php` (POST) | username / role |
| | `staff_update` | `api/store/staff-management.php` (PATCH) | old_value / new_value |
| | `staff_delete` | `api/store/staff-management.php` (DELETE) | 削除 user |
| **注文** | `order_cancel` | `api/store/orders.php` | 理由 / 金額 |
| | `order_status` | `api/store/orders.php` (PATCH) | old_status / new_status |
| | `item_status` | `api/kds/order-items.php` | item_id / 新ステータス |
| **会計（CP1）** | `payment_complete` | `process-payment.php` | 金額 / 担当 / 支払方法 / `pin_verified=1` |
| | `payment_refund` | `refund-payment.php` | 金額 / 理由 / 元 payment_id / `pin_verified=1` |
| | `cashier_pin_used` | （`payment_complete` 内に併記） | cashier_user_id / total |
| | `cashier_pin_failed` | PIN 不一致時 | pin_length / context |
| | `cashier_pin_set` | `set-cashier-pin.php` | target_username / is_own |
| **現金（CB1d）** | `register_open` | `cash-log.php` (`type=open`) | 初期金額 / `pin_verified=1` |
| | `register_close` | `cash-log.php` (`type=close`) | 実残高 / 予想残高 / 差額 / `pin_verified=1` |
| | `cash_in` | `cash-log.php` (`type=cash_in`) | 金額 / メモ / `pin_verified=1` |
| | `cash_out` | `cash-log.php` (`type=cash_out`) | 金額 / メモ / `pin_verified=1` |
| **設定** | `settings_update` | `api/store/settings.php` | old_value / new_value |
| **デバイス** | `device:register` | `api/auth/device-register.php` | token / 端末 user_id |
| **テーブル** | `table_create` / `table_update` | `api/store/tables.php` | テーブル番号 / 容量 |
| **テナント** | `tenant_signup` | `api/signup/register.php` | プラン / 店舗数 |

::: tip 詳細
`audit_log.entity_type` は `menu_item`, `user`, `order`, `order_item`, `payment`, `cash_log`, `settings`, `ingredient`, `store` 等のいずれか。`old_value` / `new_value` は JSON で保存され、画面側で diff 表示されます。
:::

### 18.7.4 監査ログの閲覧手順

1. **dashboard.html** にログイン（manager 以上）
2. 上部タブ **「店舗設定」** を開く
3. サブタブ **「監査ログ」** を選択
4. フィルタ:
   - **操作種別**（プルダウン）: `payment_complete` / `payment_refund` / `register_open` 等
   - **期間**: 過去 24 時間 / 7 日 / 30 日
   - **担当ユーザー**: 任意のユーザー名で絞り込み
5. テーブルに「日時 / ユーザー / アクション / 対象 / 詳細（diff）」が表示される
6. 詳細列の **「+」** で展開すると JSON の old_value / new_value が見られる

```
┌── 監査ログ ────────────────────────────────────────────┐
│ [操作種別 ▼] [期間 ▼] [ユーザー名]   [絞り込み]       │
│ ─────────────────────────────────────────────────────  │
│ 04/19 14:32 │ tanaka  │ payment_complete │ ¥1,630 cash│
│ 04/19 14:30 │ tanaka  │ register_open    │ ¥10,000    │
│ 04/19 13:55 │ suzuki  │ menu_soldout     │ from:0→1   │
│ 04/19 13:20 │ owner   │ staff_create     │ +yamada    │
└────────────────────────────────────────────────────────┘
```

---

## 18.8 エラー監視（CB1、2026-04-19 新設）

API エラー（`response.php` の `json_error()` で返される全エラー）は **`error_log` テーブル** に自動記録されます。これを集計してダッシュボードに表示するのが「エラー監視」サブタブです。

### 18.8.1 設置場所

- 管理ダッシュボード → **「店舗設定」グループ** → **「エラー監視」** サブタブ
- 集計 API: `GET /api/store/error-stats.php?store_id=xxx&hours=24`
- 必要ロール: **manager 以上**（owner はテナント全体、manager は自店舗のみ）

### 18.8.2 表示内容

| 表示要素 | 内容 |
|---|---|
| **総エラー件数** | 直近 N 時間の合計 |
| **Top 10 エラー** | `errorNo` / `code` / `http_status` / 件数 / 最終発生時刻 |
| **HTTP ステータス別集計** | 400 / 401 / 403 / 404 / 500 / 502 等の内訳 |
| **カテゴリ別集計** | E1xxx（バリデーション）/ E2xxx（リソース）/ E3xxx（認証認可）/ E4xxx（業務ロジック）/ E5xxx（インフラ）等 |
| **未マイグレーション警告** | `error_log` テーブル未作成時に `unmigrated: true` を返す |

### 18.8.3 画面操作手順

1. **dashboard.html** にログイン（manager 以上）
2. 上部タブ **「店舗設定」** を開く
3. サブタブ **「エラー監視」** を選択
4. デフォルトで **直近 24 時間** のサマリー表示
5. プルダウンで **「直近 1 時間」 / 「直近 7 日」 / 「直近 1 週間（最大 168 時間）」** に切替可
6. Top 10 のいずれかをクリックすると、その errorNo の時系列ドリルダウンが見られる

```
┌── エラー監視 ─────────────────────────────────────────┐
│ 期間: [直近 24 時間 ▼]            総件数: 42         │
│ ────────────────────────────────────────────────────  │
│ # │ E番号 │ コード               │ HTTP │ 件数 │ 最新│
│ 1 │ E3024 │ PIN_INVALID          │ 401  │  18  │ 14:30│
│ 2 │ E1001 │ MISSING_FIELDS       │ 400  │  12  │ 14:22│
│ 3 │ E5001 │ DB_ERROR             │ 500  │   3  │ 13:50│
│ 4 │ E3001 │ INVALID_CREDENTIALS  │ 401  │   2  │ 12:10│
│ ...                                                   │
└───────────────────────────────────────────────────────┘
```

### 18.8.4 自動アラート（cron + Slack）

5 分ごとに走る cron（`api/cron/monitor-health.php`）が `error_log` を集計し、**閾値超過時に `monitor_events` テーブルに昇格 + Slack 通知**を発火します。

#### アラート閾値（CB1 集計ロジック）

| 条件 | 重要度 | 通知 |
|---|---|---|
| 同一 errorNo が直近 5 分で **20 件以上** | warn | Slack 通知（黄色） |
| **5xx 系エラー**（DB / Gateway 等）が **3 件以上** | error | Slack 通知（赤） |
| **認証系エラー**（HTTP 401/403）が **30 件以上** | warn | Slack 通知（ブルートフォース疑い） |
| `monitor_events.severity in ('error','critical')` が **直近 15 分で 3 件以上** | critical | **メール通知**（Hiro 個人 ops_notify_email） |
| 重複通知防止 | — | 同一 errorNo は **直近 30 分以内** に既通知なら抑止 |

#### Slack 通知の設定方法（POSLA 運営）

1. POSLA 管理画面（`/posla-admin/`）にログイン
2. **「API 設定」** タブを開く
3. **「Slack Webhook URL」** 欄に `https://hooks.slack.com/services/...` を入力
4. **「ops_notify_email」** 欄に運営担当のメールアドレスを入力
5. **「保存」** を押す
6. テスト: `php api/cron/monitor-health.php` を CLI 実行 → Slack に投稿されれば成功

::: tip テナント側で見る場合
テナント側では Slack 設定そのものは触れません。「同じ症状が続く」場合はエラー監視タブで該当 E 番号を確認 → 改善されない場合は POSLA 運営に連絡してください。
:::

### 18.8.5 error_log テーブル構造（POSLA 運営向け）

```sql
CREATE TABLE error_log (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  error_no        VARCHAR(8)   NULL,             -- E1234 形式（未登録 code は NULL）
  code            VARCHAR(64)  NOT NULL,         -- 例: PIN_INVALID
  message         TEXT         NULL,
  http_status     SMALLINT     NOT NULL,
  tenant_id       VARCHAR(36)  NULL,
  store_id        VARCHAR(36)  NULL,
  user_id         VARCHAR(36)  NULL,
  username        VARCHAR(50)  NULL,
  role            VARCHAR(20)  NULL,
  request_method  VARCHAR(8)   NULL,
  request_path    VARCHAR(255) NULL,
  ip_address      VARCHAR(45)  NULL,
  user_agent      TEXT         NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_error_no (error_no),
  KEY idx_code (code),
  KEY idx_tenant_store_created (tenant_id, store_id, created_at),
  KEY idx_status_created (http_status, created_at)
);
```

::: tip 責務分離
- `audit_log` = ユーザー**操作**の追跡（誰が何をしたか）
- `error_log` = API **失敗**の記録（何が動かなかったか）
- 両方を組み合わせると「失敗したユーザー操作の経緯」を完全に追跡可能
:::

### 18.8.6 詳細なエラー番号一覧

エラーコード（E1234 形式）の完全な対応表は **章 99「エラーカタログ」**（自動生成、`scripts/generate_error_catalog.py` で再生成）を参照してください。

---

## 18.X よくある質問 30 件

### A. パスワード・認証（Q1〜Q5）

**Q1. パスワード要件は？**
A. **8 文字以上 + 英字 1 文字以上 + 数字 1 文字以上**（P1-5、`api/lib/password-policy.php`）。OK 例: `tanaka123` / `Yamada2026`。NG 例: `abcdefgh`（数字なし） / `12345678`（英字なし） / `abc123`（短い）。

**Q2. ログイン失敗でアカウントロックされますか？**
A. **2026-04-19 時点でロックされません**。`api/auth/login.php` には失敗回数カウントやロック処理が未実装です。ブルートフォース対策はテーブル PIN 検証（5 回/10 分）など認証以外には入っています。将来 login にもレートリミット追加予定。

**Q3. 多要素認証（MFA / 2FA）はありますか？**
A. 現状未対応。Phase 2 で SMS / TOTP の検討予定。当面は **強パスワード + 1 人 1 アカウント + PIN（CP1）+ device ロール分離** で運用してください。

**Q4. パスワードを SMS で復旧できますか？**
A. できません。メール / SMS リセットは未実装（飲食店スタッフがメールアドレス・電話番号を持たないケース配慮）。owner / manager に直接依頼。

**Q5. パスワードはどこに保存されていますか？**
A. `users.password_hash` カラムに **bcrypt（`PASSWORD_DEFAULT`）でハッシュ化**して保存。平文は一切残らず、漏洩しても復元困難。

### B. PIN（CP1 + CB1d）（Q6〜Q12）

**Q6. PIN を設定しないと何ができなくなる？**
A. **会計・返金・レジ開け/閉め・現金入金/出金 の全 6 操作が実行不可**（CB1d 以降）。事前に該当スタッフ全員に PIN を設定してください。

**Q7. PIN は何桁ですか？**
A. **4〜8 桁の数字のみ**。`1234` / `0000` / ゾロ目（`1111` 等）は弱 PIN として拒否されます。

**Q8. 退勤後の PIN はどうなる？**
A. **自動無効化**されます。PIN 検証 SQL は `attendance_logs.clock_out IS NULL`（出勤中）のスタッフのみを対象とするため、退勤打刻後は PIN が一致しません。

**Q9. 退勤打刻を忘れたまま帰宅したら？**
A. 翌日になると `business_day` で別日扱いになり、当日のクエリには含まれません。ただし「正しい打刻運用」がセキュリティの前提なので、運営ルールとして退勤打刻徹底を周知してください。

**Q10. PIN を入力しても「PIN が一致しないか、対象スタッフが出勤中ではありません」と出る**
A. 以下のいずれか: ①PIN 入力ミス → 再入力 ②退勤打刻済み → 出勤打刻し直す ③別店舗からログインしている → 自店舗で出勤打刻 ④マイグレーション未適用 → POSLA 運営に連絡。

**Q11. device アカウントに PIN を設定できますか？**
A. **できません**（`set-cashier-pin.php` で `VALIDATION` エラー）。device 端末でも会計時は **人間スタッフの PIN** を入力する設計です。

**Q12. PIN はどう監査されますか？**
A. `cashier_pin_used`（成功）/ `cashier_pin_failed`（失敗、`pin_length` のみ記録）/ `cashier_pin_set`（設定変更）の 3 種類で監査ログに記録されます。

### C. セッション・マルチデバイス（Q13〜Q18）

**Q13. セッションタイムアウトの仕様は？**
A. Cookie TTL **8 時間** / 無操作 **30 分で自動ログアウト** / 25 分時点で警告モーダル表示。

**Q14. 同じアカウントで 2 端末からログインできますか？**
A. できます。サーバ側で `user_sessions` に各セッションを記録し、login.php レスポンスに `warning: '他の端末でもログインしています'` を返します（UI 表示は未実装、サーバ監査可能）。

**Q15. 不審なセッションを強制ログアウトしたい**
A. `DELETE /api/store/active-sessions.php` で可能（manager 以上）。**UI は未実装**のため、運営に依頼してください。

**Q16. パスワードを変更したら他端末はどうなる？**
A. **自動的にログアウトされます**。漏洩疑い時に即座に他端末を遮断できます。

**Q17. KDS / レジ / ハンディは 8 時間で切れますか？**
A. 通常運用で API を頻繁に呼ぶため、`last_active_at` が常時更新され事実上切れません。再起動した翌朝のみ再ログインが必要です。

**Q18. ログイン履歴を誰が見られますか？**
A. **manager 以上**が `audit_log`（`action='login'`）から閲覧可能。staff は閲覧不可。

### D. 監査ログ・エラー監視（Q19〜Q24）

**Q19. 監査ログはどこから見られますか？**
A. **dashboard.html → 店舗設定 → 監査ログ** サブタブ（manager 以上）。フィルタで操作種別・期間・ユーザーで絞り込み可能。

**Q20. エラー監視はどこから見られますか？**
A. **dashboard.html → 店舗設定 → エラー監視** サブタブ（manager 以上）。直近 24 時間 / 7 日 / 168 時間で集計表示。

**Q21. 同じエラーが多発したら通知が来ますか？**
A. POSLA 運営側に Slack 通知が飛びます（5 分 cron）。テナントには直接通知しませんが、エラー監視タブに残ります。

**Q22. 監査ログは何ヶ月保持されますか？**
A. 現状は無期限保持（明示的な削除ジョブなし）。テーブル肥大化が問題化したら paryt 化 or アーカイブを検討。

**Q23. error_log と audit_log の違いは？**
A. `audit_log` = ユーザー**操作** の記録、`error_log` = API **失敗** の記録。両方併用で「失敗したユーザー操作の経緯」を完全追跡可能。

**Q24. 監査ログの「詳細」が JSON 文字列で読みにくい**
A. 2026-04-19 から `audit-log-viewer.js` が色付きバッジ + 日本語ラベルで表示するよう改善されました（金額カンマ区切り / boolean ✓× / ステータス日本語化）。

### E. 退職者・アカウント運用（Q25〜Q28）

**Q25. 退職者のアカウントはどうする？**
A. **削除ではなく `is_active=0` で無効化**。削除すると `audit_log.username` の表示が崩れます。リモートログアウトも実行（manager+）。

**Q26. 共有アカウント（kitchen / hall）を作って良い？**
A. **禁止**。1 人 1 アカウントが必須。監査ログ・勤怠・スタッフ評価・シフト管理が全て個人前提。

**Q27. 個人スマホで POSLA を使ってよい？**
A. **非推奨**。POSLA は店舗備品の PC / タブレットでの運用前提。個人スマホで触ると勤怠 GPS や監査ログが個人端末に紐付き混乱します。

**Q28. オーナーが 1 人しかいない場合、退職時にどうする？**
A. 退職前に **必ず後任を owner に昇格**してください。owner ゼロになると管理操作不可・パスワードリセット不可になります。緊急時は POSLA 運営が DB 直接対応可能。

### F. レートリミット・XSS・SQL インジェクション（Q29〜Q30）

**Q29. ブルートフォース攻撃を受けたら？**
A. テーブル QR の PIN 検証など個別経路にはレートリミットあり（[18.Z.1](#18-z-1-全レートリミット一覧-s-1)）。ログイン API 自体には未実装なので、`error_log` で `INVALID_CREDENTIALS` の急増 + 同一 IP を確認 → POSLA 運営に連絡 → サーバ側でファイアウォールブロック。

**Q30. メニュー名にスクリプトタグを入れたら XSS 攻撃になりますか？**
A. **なりません**。全 HTML 挿入は `Utils.escapeHtml()` を通すよう統一されており、`<script>` は `&lt;script&gt;` にエスケープされます。SQL も全てプリペアドステートメント。

---

## 18.Y トラブルシューティング表

### 18.Y.1 認証・ログイン関連

| 症状 | 想定原因 | 対処 |
|---|---|---|
| ログインできない | パスワード忘れ / Caps Lock / アカウント無効化 | パスワードリセット依頼（[2 章](./02-login.md) 参照） |
| 「TENANT_DISABLED」 | テナント解約 / 未払い | POSLA 運営に連絡。Stripe Billing 状態を確認 |
| ログイン直後にログアウトされる | Cookie ブロック / プライベートブラウジング | Cookie 許可、通常モードで再試行 |
| 不審なログイン履歴を発見 | 他人によるログイン疑い | パスワード即変更 → 他端末自動ログアウト → 運営に IP を相談 |

### 18.Y.2 セッション・タイムアウト

| 症状 | 想定原因 | 対処 |
|---|---|---|
| 突然ログアウト | 30 分無操作 / リモートログアウト / パスワード変更 | 再ログイン |
| 警告モーダルが出ない | 古い JS キャッシュ | Ctrl+F5 でスーパーリロード |
| 不審なセッションを遮断したい | — | 運営に `active-sessions.php DELETE` を依頼（UI 未実装） |

### 18.Y.3 PIN（CP1 / CB1d）

| 症状 | 想定原因 | 対処 |
|---|---|---|
| 「PIN が一致しないか出勤中ではありません」 | 退勤打刻済 / PIN ミス | 出勤打刻 → PIN 再入力 |
| 「device ロールには PIN を設定できません」 | device に PIN を試みた | 該当する人間スタッフに設定 |
| マイグレーション未適用エラー | 新規環境で SQL 未実行 | POSLA 運営に `migration-cp1-cashier-pin.sql` 適用依頼 |
| PIN 漏洩疑い | スタッフ間で共有 / 付箋に貼った | 即変更 + 該当スタッフに教育 |

### 18.Y.4 監査ログ・エラー監視

| 症状 | 想定原因 | 対処 |
|---|---|---|
| 監査ログタブが見えない | staff ロール | manager / owner に閲覧依頼 |
| エラー監視に「未マイグレーション」表示 | `error_log` テーブル未作成 | POSLA 運営に `migration-cb1-error-log.sql` 適用依頼 |
| 同じエラーが連続発生 | フロント実装バグ or 攻撃 | エラー監視で件数 + IP 確認、運営に連絡 |
| Slack 通知が来ない | `slack_webhook_url` 未設定 | POSLA 管理画面で設定（運営作業） |

### 18.Y.5 不正・障害

| 症状 | 対処 |
|---|---|
| 不審なログイン履歴 | `audit_log`（`action='login'`）を POSLA 運営に確認依頼 |
| 不審なセッション遮断 | `api/store/active-sessions.php` を運営経由で実行（UI 未実装） |
| PIN ブルートフォース疑い | `audit_log`（`cashier_pin_failed`）を集計、運営に連絡 |
| データ漏洩疑い | 即時 POSLA 運営に連絡、当該テナントの全パスワードリセット |

## 18.Z 技術補足

実装済セキュリティ: S1 レートリミット, S2 会計後クールダウン, S3 テイクアウト決済限定, S4 Stripe payment_method_types, S5 未提供アラート, S6 PIN方式 (旧テーブル PIN), L-9 テーブル開放認証 (現行), **CP1 担当スタッフ PIN（POSレジ会計時、2026-04-19）**, **CB1d レジ金庫 PIN（レジ開け/閉め/入金/出金、2026-04-19）**

### CP1: 担当スタッフ PIN 認証

POSレジ会計時に **「誰が会計を実行したか」を PIN で特定**する仕組み（業界標準: スマレジ・エアレジ等と同等）。device アカウントでログイン中でも、人間スタッフを `payments.user_id` に記録できる。

**設計の核心 — 出勤中スタッフのみ PIN 受付**:
- 退勤打刻したスタッフの PIN は自動無効化
- 退職者の PIN 悪用を物理的に防止
- 詳細: [2 章 2.6 担当スタッフ PIN](./02-login.md#26-担当スタッフ-pin-cp1)

実装:
- DB: `users.cashier_pin_hash` (bcrypt) + `cashier_pin_updated_at`
- API: `POST /api/store/set-cashier-pin.php`（PIN 設定）, `process-payment.php` の `staff_pin` パラメータ
- 監査: `cashier_pin_used` / `cashier_pin_failed` / `cashier_pin_set` 3 種

弱 PIN 拒否ロジック:
- ゾロ目（`1111`, `0000` 等）→ `WEAK_PIN` エラー
- ブラックリスト（`1234`, `12345`, `0000` 等）→ `WEAK_PIN` エラー
- 4〜8 桁の数字以外 → `INVALID_PIN` エラー

### 18.Z.1 全レートリミット一覧（S-1）

実装: `api/lib/rate-limiter.php` の `check_rate_limit($key, $limit, $window)`（ファイルベース、`flock(LOCK_EX)` で同時書き込み防御、`REMOTE_ADDR` のみ信頼、`X-Forwarded-For` は無視）

| エンドポイント | key | 上限 | 時間窓 | 実装ファイル |
|---|---|---|---|---|
| セルフオーダー注文送信 | `customer-order` | 20 | 600秒 | `api/customer/orders.php` |
| テーブルセッション取得 | `table-session` | 30 | 600秒 | `api/customer/table-session.php` L18 |
| PIN 検証（旧方式） | `pin-verify:{tableId}` | 5 | 600秒 | `api/customer/table-session.php` L154 |
| AI ウェイター | `ai-waiter` | 20 | 3600秒 | `api/customer/ai-waiter.php` L21 |
| AI 予約解析 | `reserve-ai:{IP}` | 5 | 60秒 | `api/customer/reservation-ai-parse.php` L18 |
| デバイス登録 | `device_register:{IP}` | 3 | 3600秒 | `api/auth/device-register.php` |
| サインアップ | `signup:{IP}` | 5 | 3600秒 | `api/signup/register.php` |

超過時は HTTP 429 エラー「リクエスト回数の上限に達しました」を返却。

### 18.Z.2 不正抑止の仕組み（CP1 + CB1d 設計まとめ）

POSLA は「事後追跡で犯人を特定する」のではなく「**事前に不正を物理的に困難にする**」設計を採用しています。

| 攻撃シナリオ | 防御メカニズム |
|---|---|
| 退職スタッフが古い PIN を悪用してレジを動かす | 退勤打刻 = PIN 自動無効化（`attendance_logs.clock_out IS NULL` 縛り） |
| device 端末で勝手に会計をごまかす | 全 6 操作で人間スタッフ PIN 必須（device 自身の認証では不可） |
| 共有アカウントで誰が悪用したか分からない | 1 人 1 アカウント原則 + 監査ログ + PIN で個人特定 |
| ブラウザに保存したパスワードで他人が侵入 | パスワード変更で全他端末を即無効化（リモートログアウト） |
| ブルートフォースで PIN 試行 | 失敗を `cashier_pin_failed` で全件記録 + cron で 30 件/5 分超過時 Slack 通知 |
| 監査ログ自体を改ざん | `write_audit_log()` は INSERT のみ、UPDATE/DELETE する API なし |
| API キー漏洩 | フロント露出禁止 + サーバプロキシ経由（`api/store/ai-generate.php`） |

### 18.Z.3 関連 SQL マイグレーション

::: tip 通常テナントは読まなくて OK
ここから先は POSLA 運営向けの技術詳細です。
:::

| ファイル | 用途 |
|---|---|
| `sql/migration-cp1-cashier-pin.sql` | `users.cashier_pin_hash` + `cashier_pin_updated_at` 追加 |
| `sql/migration-cb1-error-log.sql` | `error_log` テーブル新設（CB1） |
| `sql/migration-s6-session-pin.sql` | お客さん用テーブル PIN（S6 旧方式） |
| `sql/migration-fda1-device-tokens.sql` | デバイス自動ログイン用ワンタイムトークン |
| `sql/migration-fqr1-sub-sessions.sql` | 相席用サブセッション QR |

### 18.Z.4 主要セキュリティ関連 API

| エンドポイント | メソッド | 認証 | 用途 |
|---|---|---|---|
| `/api/auth/login.php` | POST | 公開 | ID + パスワード認証 |
| `/api/auth/logout.php` | DELETE | 認証必須 | セッション破棄 |
| `/api/auth/check.php` | GET | 認証必須 | セッション延命 + アイドル判定 |
| `/api/auth/change-password.php` | POST | 認証必須 | 自己パスワード変更（他端末は SESSION_REVOKED） |
| `/api/auth/device-register.php` | POST | ワンタイムトークン | デバイス自動ログイン |
| `/api/store/set-cashier-pin.php` | POST | 自分 / manager+ | 担当スタッフ PIN 設定 |
| `/api/store/active-sessions.php` | GET / DELETE | manager+ | アクティブセッション一覧 / リモートログアウト |
| `/api/store/error-stats.php` | GET | manager+ | エラー統計（CB1） |
| `/api/cron/monitor-health.php` | CLI / X-POSLA-CRON-SECRET | 運営 | 5 分 cron 監視 + Slack 通知 |

---

## 18.W S3 セキュリティ修正一式（2026-04-19）

2026-04-19 に POSLA 全体に対して 20 件の S3 セキュリティ修正を適用しました。テナント運用に影響する主要トピックを以下にまとめます。詳細は [99-error-catalog](./99-error-catalog.md) と [19-troubleshoot](./19-troubleshoot.md) を参照。

### 18.W.1 CORS Origin ホワイトリスト化（修正 #19, Q3=B）

- `api/lib/response.php` の `send_api_headers()` が許可する Origin を以下 2 つに限定:
  - `https://eat.posla.jp` （本番テナント）
  - `https://meal.posla.jp` （本番複製予定）
- それ以外の Origin（カスタムドメイン・ローカル開発 URL 等）からの API 呼び出しはブラウザ側で CORS エラーになります
- **影響**: 独自ドメインで POSLA を運用する予定がある場合は POSLA 運営に申請してください（ホワイトリストに追加します）

### 18.W.2 DB 認証情報の環境変数化（修正 #9, Q2=A）

- `api/config/database.php` が `getenv('POSLA_DB_HOST')` / `POSLA_DB_NAME` / `POSLA_DB_USER` / `POSLA_DB_PASS` を優先参照、未設定時は従来のハードコード値にフォールバック
- 環境変数は `api/.htaccess` の `SetEnv` ディレクティブで渡します（Apache）
- **影響**: テナント側に直接の影響はありません。POSLA 運営側で本番 DB 認証情報の管理方法が変わります（ソースコードリポジトリに認証情報を含めなくなる）

### 18.W.3 レースコンディション対策（修正 #3, #7, #8, #10）

複数スタッフが同じ注文・テーブル・予約に対して同時操作した時のデータ破損を防ぐため、以下の API に `BEGIN + SELECT ... FOR UPDATE + affected rows 検証` を導入しました。

| API | 対策内容 | エラー時 |
|---|---|---|
| `process-payment.php` / `checkout-confirm.php` | 重複会計 / 二重課金防止 | `CONFLICT` (409) → 画面リロードで最新状態を確認 |
| `reservation-create.php` | 同一時刻の二重予約 | `CONFLICT` (409) + 複合 UNIQUE (新マイグレーション) |
| `takeout-orders.php` | 容量超過の駆け込み注文 | `CAPACITY_EXCEEDED` (409) |
| `refund-payment.php` | 二重返金 | `pending` 中間状態で排他制御 |

::: tip CONFLICT が出たら
画面をリロードして最新状態を確認してから操作し直してください。多くの場合、別のスタッフが同じ操作を一足先に完了しています。
:::

### 18.W.4 サーバー側価格再計算（修正 #4）

- handy（ハンディ）からの注文送信時も、サーバーが `order-validator.php` で **メニューマスタから価格を再計算**します
- クライアント側で改ざんした価格・オプションは無視され、`AMOUNT_MISMATCH` (409) で拒否されます
- **影響**: 正常運用では発生しません。ハンディ端末の表示価格が一瞬古いまま注文した場合に発生する可能性あり → 端末再読込で解消

### 18.W.5 在庫減算バグ修正（修正 #5, #6）

- 部分会計（伝票分割）時に在庫が二重減算されるバグを修正
- 修正前: `order_items.id` を使って減算 → 同じ menu の複数行を別在庫として処理
- 修正後: `menu_item_id` を使って正しく合算
- 在庫操作 SQL に `tenant_id` 境界を追加（修正 #6）

### 18.W.6 Stripe 連携の堅牢化（修正 #1, #2, #16）

- `signup/activate.php` が Stripe Subscription の状態を必ず確認 → 未払い・キャンセル状態のテナントは `PAYMENT_NOT_CONFIRMED` (402) で拒否
- Stripe Webhook の secret 未設定時は **fail-close** (503)（旧仕様: 検証スキップして続行）
- Stripe Webhook 署名のタイムスタンプ許容を 5 分に設定（リプレイ攻撃対策）

### 18.W.7 例外露出の固定文言化（修正 #11）

- `signup/register.php` / `checkout-confirm.php` / `handy-order.php` の例外メッセージを固定文言に統一
- DB エラーの内部詳細（テーブル名・カラム名）が `error.detail` に漏洩しないよう修正

### 18.W.8 ユーザー名のグローバル一意化（修正 #14, Q1=B）

- `users.username` は **POSLA 全体（テナント横断）でグローバル一意**になりました
- 詳細は [02-login § 2.1](./02-login.md) を参照

### 18.W.9 ES5 互換性の徹底（修正 #17）

- `String.prototype.padStart` は古い iOS Safari で動作しない → `('0' + s).slice(-2)` 形式に置換（6 ファイル 9 箇所）
- 影響: なし（動作互換のまま）

---

## 関連章

- [02-login](./02-login.md) — ログインとアカウント、PIN 設定 UI
- [08-cashier](./08-cashier.md) — POSレジ会計時の PIN 入力フロー
- [16-settings](./16-settings.md) — スタッフ管理 UI
- [21-stripe-full](./21-stripe-full.md) — Stripe 決済キー管理
- [25-table-auth](./25-table-auth.md) — お客さん用テーブル QR / PIN 認証
- [99-error-catalog](./99-error-catalog.md) — エラー番号一覧（自動生成）

---

## 更新履歴
- **2026-04-19 (S3 セキュリティ修正一式)**: 18.W を新設。CORS Origin ホワイトリスト化（`eat.posla.jp` / `meal.posla.jp`）、DB 認証情報の環境変数化、レースコンディション対策（process-payment / reservation-create / takeout-orders / refund-payment）、サーバー側価格再計算（handy）、在庫二重減算バグ修正、Stripe 連携堅牢化（activate.php Subscription 検証 / webhook fail-close / 5分タイムスタンプ許容）、username グローバル一意化、ES5 互換性徹底。新マイグレーション: `migration-s3-refund-pending.sql` 適用済 / `migration-s3-reservation-uniq.sql` 保留。
- **2026-04-19 (Phase B Batch-2)**: フル粒度化。18.0「はじめに」追加（CP1 + CB1 全体像 + 「いつ何が記録されるか」サマリー）。18.6-b「担当スタッフ PIN ポリシー」を新設（CB1d 完全網羅、PIN 検証 SQL 全文、監査イベント 3 種）。18.7.3 監査ログ全アクション一覧（カテゴリ別 25 種）+ 18.7.4 閲覧手順 + ASCII モック追加。18.8 エラー監視を大幅拡張（画面操作手順 + ASCII モック + アラート閾値表 + Slack/メール通知設定 + error_log スキーマ）。18.X FAQ を 30 件に拡充（A〜F カテゴリ別）。18.Y トラブルシューティングを 5 区分に分割。18.Z.2 不正抑止の仕組み + 18.Z.3 マイグレーション一覧 + 18.Z.4 主要 API 一覧を新設。
- **2026-04-19 (CB1d)**: レジ金庫操作 4 種（レジ開け/閉め/入金/出金）にも担当スタッフ PIN 必須を追記（18.7.1 / 18.Z）。`audit_log` の `register_*` / `cash_in` / `cash_out` に `pin_verified=1` / `cashier_user_id` / `cashier_user_name` が含まれる旨を明記。
- **2026-04-19 (CP1 + CB1)**: 18.7「監査ログに記録される操作」を新設（会計・返金・レジ開閉・現金入出金・PIN 設定/使用/失敗）。返金にも担当スタッフ PIN 必須を追記。18.8「エラー監視」を新設（エラーカタログ + monitor-health cron + Slack 通知）。
- **2026-04-18 (S1-S6)**: フル粒度化（レートリミット一覧 + テーブル PIN ブルートフォース防御 + hash_equals タイミング攻撃対策）
