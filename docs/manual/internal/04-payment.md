---
title: 決済アーキテクチャ（Pattern A / Pattern B）
chapter: 04
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [payment, 運営, POSLA]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 4. 決済アーキテクチャ（Pattern A / Pattern B）

POSLAのテナント決済は2つのパターンで動作します。テナントごとに選択可能で、バックエンドが自動で経路を切り替えます。

::: tip 現行正本: コンテナログで追う
決済系の一次切り分けは `docker compose logs php` と `docker compose exec -T db mysql ...` が正本です。過去の SSH / 共有サーバ前提のコマンド例は履歴資料として扱ってください。
:::

::: tip この章を読む順番（運営者向け）
1. **4.1 2つのパターン** で全体像を把握
2. **4.2 / 4.3 / 4.4** で技術的な分岐ロジック
3. **4.6 オンボーディング** で Pattern B の実装
4. **4.8 process-payment / 4.9 refund-payment** でリクエスト/レスポンス仕様
5. 障害時は **4.11 トラブルシューティング** と **4.12 FAQ**
:::

## 4.1 2つのパターン

| 項目 | パターンA：自前Stripeキー | パターンB：Stripe Connect |
|------|--------------------------|----------------------------|
| 使用するカラム | `tenants.stripe_secret_key` | `tenants.stripe_connect_account_id` + `connect_onboarding_complete=1` |
| Stripe呼び出し | テナント固有のSecret Keyで直接 | POSLAプラットフォームキー + `Stripe-Account` ヘッダ |
| `application_fee` | なし（POSLA手数料ゼロ） | 1.0%（`ceil(amount * 1.0 / 100)`、最低¥1） |
| 入金先 | テナント自身のStripe残高 | テナント自身のConnect残高 |
| 使える機能 | レジカードリーダー、セルフレジ、テイクアウト | レジカードリーダー、セルフレジ、テイクアウト |
| 事前準備時間 | テナント側で 10 分（既に Stripe アカウントあり前提） | テナント側で 30 分（Express オンボーディング） |
| 審査 | 不要（既存アカウントを再利用） | 1〜3 営業日 |

### 4.1.1 優先順位

両方設定されている場合、**パターンB（Connect）が優先**されます。判定は `get_stripe_auth_for_tenant()` が行い、`/api/connect/status.php` のレスポンスに以下のフィールドが返ります。

```json
{
  "connected": true,
  "charges_enabled": true,
  "onboarding_complete": true,
  "terminal_pattern": "B",
  "tenant_stripe_configured": false
}
```

- `terminal_pattern`: `'A'` / `'B'` / `null`
- `tenant_stripe_configured`: `stripe_secret_key` が設定されているか

レジアプリ（`cashier-app.js`）はこのフィールドを見てStripe Terminal SDKの初期化経路を決定します。

### 4.1.2 Square の完全削除（P1-2）

2026年4月の P1-2 で `square_access_token`, `square_location_id` カラムを DROP し、`payment_gateway` ENUM から `'square'` を除外しました。マイグレーション: `sql/migration-p1-remove-square.sql`。過去の `payments.gateway_name='square'` データは履歴として残っている（0件確認済み）。**今後 Square に関する追加実装は禁止**。

---

## 4.2 `get_stripe_auth_for_tenant()` ヘルパ

`api/lib/payment-gateway.php` に追加されたヘルパ関数。テナントIDから現在のStripe認証モードを判定します。

| 戻り値 | 条件 | `mode` | `secret` | `stripe_account` | `fee_percent` |
|--------|------|--------|----------|------------------|---------------|
| Connect | `connect_onboarding_complete=1` かつ `charges_enabled=1` | `'connect'` | POSLAプラットフォームキー | テナントのConnect Account ID | 1.0 |
| Direct | `stripe_secret_key` あり、Connectは未完了 | `'direct'` | テナントのSecret Key | `null` | 0 |
| None | どちらも未設定 | `'none'` | — | — | — |

呼び出し元:
- `api/store/terminal-intent.php`（レジPayment Intent生成）
- `api/connect/terminal-token.php`（Stripe Terminal Connection Token発行）
- `api/store/refund-payment.php`（返金時の Stripe API 経路選択）

### 4.2.1 判定フロー（ASCII）

```
[get_stripe_auth_for_tenant($tenantId)]
        │
        ▼
   tenants 行取得
        │
        ▼
   ┌──────────────────────────────────┐
   │ connect_onboarding_complete = 1  │
   │ AND charges_enabled = 1?         │
   └──────────────────────────────────┘
        │
   YES  │  NO
        ▼  │
   [Connect] ▼
   mode='connect'  ┌──────────────────────────────────┐
                   │ stripe_secret_key IS NOT NULL ?  │
                   └──────────────────────────────────┘
                        │
                   YES  │  NO
                        ▼  │
                   [Direct] ▼
                   mode='direct'  [None]
                                  mode='none'
```

---

## 4.3 Stripe Terminal（カードリーダー）の分岐

### 4.3.1 PaymentIntent 生成（terminal-intent.php）

`POST /api/store/terminal-intent.php`（manager 以上）

- **Connect**: `create_stripe_connect_terminal_intent()` で PaymentIntent 作成（`application_fee_amount` を付与、`Stripe-Account` ヘッダ付き）
- **Direct**: Pattern A 用の直接 PaymentIntent 作成（手数料なし）
- **None**: 400 エラー `NOT_CONNECTED`

リクエスト例:

```json
{
  "store_id": "s-xxx",
  "amount": 3000,
  "order_ids": ["o-xxx-1"]
}
```

レスポンス例:

```json
{
  "ok": true,
  "data": {
    "client_secret": "pi_xxx_secret_yyy",
    "payment_intent_id": "pi_xxx",
    "terminal_pattern": "B"
  }
}
```

### 4.3.3 セルフ決済とレジ決済のロジック差分

POSLAの決済ロジックは、利用シーンに応じて以下の2つのエンドポイントで制御されます。

| 項目 | セルフ決済 (`get-bill.php`) | レジ決済 (`process-payment.php`) |
|---|---|---|
| **集計単位** | **テーブルセッション全体** | **品目単位（Partial 対応）** |
| **個別会計** | 未対応（常に全額集約） | **対応**（`selected_items` 配列が渡された場合に自動判定） |
| **在庫消費** | 決済完了時に一括消費 | 決済した品目のみを消費 |
| **状態遷移** | 全注文が `paid` へ遷移 | 選択した品目のみ `paid` へ遷移 |

#### セルフ決済のフロー
1. `GET /api/customer/get-bill.php` で未払い注文を全件取得。
2. 合計金額で Stripe PaymentIntent を作成。
3. 決済完了通知（Webhook または同期レスポンス）を受け取り、`api/customer/checkout-confirm.php` で一括 `paid` 処理。

#### レジ決済のフロー
1. レジ画面で全選択（通常）または一部選択（個別）を行う。
2. `POST /api/store/process-payment.php` にデータを送信：
    - **全額会計**: `table_id` または `order_ids` を送信。
    - **個別会計**: `selected_items`（画面表示用の品目情報オブジェクト）および **`selected_item_ids`**（`order_items.id` の配列）を送信。
3. サーバー側は `selected_items` の有無によって **Partial（個別）判定** を行います。
4. `selected_item_ids` を canonical source として価格を再計算し、対象品目のみを `paid` 化・在庫消費します。

---

## 4.4 返金処理仕様

トークン有効期限: **5 分**。Stripe Terminal SDK が定期的に再取得する。

### 4.3.3 クライアント側の分岐（cashier-app.js）

```js
// /public/kds/js/cashier-app.js (ES5 IIFE)
if (!json.data.terminal_pattern) {
  return; // 未接続なら Terminal SDK 初期化をスキップ
}
_initStripeTerminal();
```

---

## 4.4 Stripe Connect の解除（P1-1c）

テナントがパターンBからパターンAに切り替えたい場合に使う機能。

**エンドポイント:** `POST /api/connect/disconnect.php`（owner ロール必須）

**処理:**
```sql
UPDATE tenants SET
  stripe_connect_account_id = NULL,
  connect_onboarding_complete = 0,
  charges_enabled = 0,
  payouts_enabled = 0
WHERE id = ?
```

- Stripe APIは呼ばない（Stripe ダッシュボード側のアカウントはそのまま残る）
- `audit_log` に記録
- 統一レスポンス形式で `{ ok: true, data: { disconnected: true } }` を返す

**UI:** owner-dashboard の「決済設定」タブ > Stripe Connect 有効ブロック > 「Stripe Connectを解除」ボタン。`renderConnectStatus()` の `if (data.connected && data.charges_enabled)` ブロックの中にのみ描画されるため、未接続状態では表示されない。

::: warning 解除後の挙動
解除後、Pattern A への切替まで「使用する決済サービス」を `none` から `stripe` に変更し `stripe_secret_key` を保存する必要がある。それまではレジカード決済が `NOT_CONNECTED` 400 で動作不能になる。
:::

---

## 4.5 手数料計算（パターンB）

```
手数料 = ceil(決済金額 × connect_application_fee_percent / 100)
最低手数料 = ¥1
```

**設定:** `posla_settings.connect_application_fee_percent`（デフォルト `1.0`）

POSLA管理画面の「API設定」タブで変更可能。変更は即座に反映される（テナント側の subscription 再作成不要）。

**計算例:**

| 決済金額 | 手数料率 | 手数料 | テナント受取額（Stripe手数料別） |
|---------|---------|--------|----------------------------------|
| ¥1,000 | 1.0% | ¥10 | ¥990 |
| ¥3,000 | 1.0% | ¥30 | ¥2,970 |
| ¥10,000 | 1.0% | ¥100 | ¥9,900 |
| ¥50 | 1.0% | ¥1（最低額） | ¥49 |

### 4.5.1 Stripe Dashboard での確認方法

1. Stripe Dashboard（プラットフォーム親アカウント）にログイン
2. 左メニュー > **「Connect」** > **「アカウント」**
3. テナントの Connect アカウントをクリック
4. 「決済」タブで決済一覧を表示
5. 各決済の右側に「アプリケーション手数料: ¥XX」が表示される
6. POSLA プラットフォームの収益として「**コレクション**」セクションに集計される

---

## 4.6 テナントのConnect接続（パターンB）

### 4.6.1 オンボーディングフロー（クリック単位）

1. テナントのオーナーがオーナーダッシュボードの **「決済設定」** タブを開く
2. **「Stripe Connectに登録して決済を開始する」** ボタンをクリック
3. POSLA がStripe Express Account を作成（`POST /api/connect/onboard.php`）：
   - アカウントタイプ：**Express**
   - 国：JP（日本）
   - 有効化する機能：`card_payments`, `transfers`
4. Stripe のオンボーディングページにリダイレクト
5. テナントが Stripe 上で以下を入力（10〜20 分）：
   - ビジネス情報（屋号・住所・電話・業種）
   - 銀行口座情報
   - 本人確認書類（運転免許証 or マイナンバーカード）
6. オンボーディング完了後、POSLA にリダイレクト（`api/connect/callback.php`）
7. `connect_onboarding_complete=1` に更新

#### サーバー側の API フロー（ASCII）

```
[owner-dashboard]                  [POSLA]                       [Stripe]
       │                              │                              │
       │  POST /connect/onboard.php   │                              │
       │ ─────────────────────────▶  │                              │
       │                              │  POST /v1/accounts (Express) │
       │                              │ ───────────────────────────▶│
       │                              │ ◀────────────────────────── │
       │                              │  acct_xxx                    │
       │                              │  POST /v1/account_links      │
       │                              │ ───────────────────────────▶│
       │                              │ ◀────────────────────────── │
       │  { url: "https://connect..." } │  onboarding URL              │
       │ ◀───────────────────────── │                              │
       │  redirect                                                   │
       │ ──────────────────────────────────────────────────────────▶│
       │                                            (20分入力)       │
       │ ◀──────────────────────────────────────────────────────── │
       │  redirect to /connect/callback.php?account=acct_xxx         │
       │ ─────────────────────────▶  │                              │
       │                              │  GET /v1/accounts/acct_xxx   │
       │                              │ ───────────────────────────▶│
       │                              │ ◀────────────────────────── │
       │                              │  charges_enabled=true        │
       │                              │  UPDATE tenants ...          │
       │ ◀───────────────────────── │                              │
       │  決済設定タブに戻る          │                              │
```

### 4.6.2 接続状態の取得

POSLA は `/api/connect/status.php` で Stripe のアカウント情報を定期取得：

| フィールド | 説明 |
|-----------|------|
| `charges_enabled` | 決済の受付が有効か |
| `payouts_enabled` | 入金（振込）が有効か |
| `details_submitted` | 必要情報の入力が完了しているか |

3つすべてが true になっていれば Pattern B として完全に稼働可能。

---

## 4.7 DBフィールド一覧

### 4.7.1 Connect関連（パターンB）

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `stripe_connect_account_id` | VARCHAR(100) | StripeのConnectアカウントID（`acct_xxx`） |
| `connect_onboarding_complete` | TINYINT(1) | オンボーディング完了フラグ（0/1） |
| `charges_enabled` | TINYINT(1) | 決済受付可能か |
| `payouts_enabled` | TINYINT(1) | 入金有効か |

### 4.7.2 自前キー関連（パターンA）

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `payment_gateway` | ENUM('none','stripe') | 使用する決済ゲートウェイ |
| `stripe_secret_key` | VARCHAR(200) | テナント自前のStripe Secret Key |

### 4.7.3 payments テーブル（決済履歴）

| フィールド | 型 | 説明 |
|---|---|---|
| `id` | VARCHAR(36) | UUID |
| `store_id` | VARCHAR(36) | テナント境界の主キー |
| `total_amount` | INT | 円 |
| `payment_method` | ENUM('cash','card','qr') | UI 上の表示分類 |
| `gateway_name` | VARCHAR(50) | `stripe` / `stripe_connect_terminal` / `null`（cash） |
| `external_payment_id` | VARCHAR(100) | Stripe `pi_xxx` |
| `gateway_status` | VARCHAR(50) | `succeeded` / `requires_capture` 等 |
| `refund_status` | ENUM('none','partial','full') | C-R1 で追加 |
| `refund_amount` | INT | 円 |
| `refund_id` | VARCHAR(100) | Stripe `re_xxx` |
| `refund_reason` | VARCHAR(200) | テナント入力理由 |
| `refunded_at` | DATETIME | |
| `refunded_by` | VARCHAR(36) | PIN 認証された担当者 user_id（CP1b） |
| `created_at` | DATETIME | |

### 4.7.4 Square関連（削除済み）

2026年4月のP1-2で `square_access_token`, `square_location_id` カラムを DROP し、`payment_gateway` ENUM から `'square'` を除外した。マイグレーション: `sql/migration-p1-remove-square.sql`。

---

## 4.8 process-payment.php — 統合決済エンドポイント

### 4.8.1 概要

会計処理の中核 API。すべての支払方法（cash / card / qr / terminal）を 1 つのエンドポイントで扱います。

`POST /api/store/process-payment.php`（manager 以上）

### 4.8.2 リクエスト例

```json
{
  "store_id": "s-shibuya-001",
  "payment_method": "cash",
  "order_ids": ["o-xxx-1", "o-xxx-2"],
  "received_amount": 5000,
  "selected_items": null,
  "total_override": null
}
```

| パラメータ | 必須 | 説明 |
|---|---|---|
| `store_id` | ✅ | 店舗 ID |
| `payment_method` | ✅ | `cash` / `card` / `qr` / `terminal` |
| `order_ids` | ✅* | 会計対象の注文 ID 配列（合流会計時は複数） |
| `table_id` | ✅* | 単一テーブル時は table_id でも可 |
| `received_amount` | — | 現金時の受取金額（おつり計算用） |
| `selected_items` | — | 個別会計時の**画面表示用の品目情報オブジェクト**配列 `[{name, qty, price, taxRate?}]`。この配列が渡されると `$isPartial = true` と判定される（サーバー側の canonical 判定フラグ） |
| `selected_item_ids` | — | 個別会計時の `order_items.id` 配列（**canonical ID source**）。合計金額・在庫消費はこの ID 群を基準に再計算される |
| `total_override` | — | 個別会計・割引時の合計上書き（円） |
| `discount_amount` | — | 割引額（円） |
| `discount_percent` | — | 割引率（%） |
| `terminal_payment_id` | — | Stripe Terminal の `pi_xxx` |

### 4.8.3 内部処理

1. order_ids の全注文を `WHERE id IN (...) AND store_id = ?` で取得（テナント境界チェック）
2. 合計金額計算（`total_override` 優先、なければ items から再計算）
3. 割引適用
4. payment_method 別処理:
   - `cash`: 受取金額・おつり記録、`cash_log` に `cash_sale` レコード追加
   - `card`/`qr`: ゲートウェイ経由で決済確認
   - `terminal`: Stripe Terminal の決済結果を確認、`terminal_payment_id` を保存
5. `orders.status = 'paid'`, `paid_at = NOW()`
6. `payments` テーブルに 1 レコード INSERT
7. テーブルの `session_token` を新規発行（4 時間 TTL）
8. レシート生成（自動印刷設定があればプリンタへ送信）
9. `audit_log` に決済イベント記録

### 4.8.4 レスポンス

```json
{
  "ok": true,
  "data": {
    "payment_id": "p-xxx",
    "total": 4800,
    "received": 5000,
    "change": 200,
    "receipt_url": "/api/customer/receipt-view.php?order_id=..."
  }
}
```

### 4.8.5 エラーコード

| コード | HTTP | 説明 |
|---|---|---|
| `MISSING_FIELDS` | 400 | store_id / payment_method 不足 |
| `INVALID_AMOUNT` | 400 | 金額が不正（負数等） |
| `ORDER_NOT_FOUND` | 404 | 指定 order_id が存在しない or 別店舗 |
| `ALREADY_PAID` | 409 | 既に paid ステータス（S3 #3 で排他制御強化） |
| `CONFLICT` | 409 | レースコンディション検出（FOR UPDATE で行ロック → affected rows = 0、S3 #3 で追加） |
| `AMOUNT_MISMATCH` | 409 | クライアント表示額とサーバー再計算額が不一致（S3 #4 で追加） |
| `STRIPE_MISMATCH` | 409 | Stripe Terminal の retrieve 結果と DB 上の payment_intent が不一致（S3 #4 で追加） |
| `PAYMENT_FAILED` | 402 | カード/Terminal 決済失敗 |
| `STRIPE_NOT_CONFIGURED` | 503 | Stripe 設定なし |

### 4.8.6 Stripe Terminal の retrieve 検証フロー（S3 #4、2026-04-19 追加）

`payment_method='terminal'` で `terminal_payment_id` を受け取った時、サーバー側で以下を必ず実行:

1. `BEGIN`
2. `SELECT ... FROM orders WHERE id IN (...) AND store_id=? FOR UPDATE` で行ロック
3. すでに `status='paid'` の注文があれば `ALREADY_PAID` (409) → `ROLLBACK`
4. items から金額再計算 → `total_override` と一致しなければ `AMOUNT_MISMATCH` (409) → `ROLLBACK`
5. `\Stripe\PaymentIntent::retrieve($terminal_payment_id)` で Stripe API 呼び出し
6. retrieve 結果の `status === 'succeeded'` かつ `amount === 計算した合計` を確認
   - いずれか不一致 → `STRIPE_MISMATCH` (409) → `ROLLBACK`
7. `UPDATE orders SET status='paid' WHERE id IN (...) AND status='pending'` → affected rows が期待数と異なれば `CONFLICT` (409) → `ROLLBACK`
8. `payments` INSERT → `COMMIT`

**目的**: クライアント JS が悪意 / バグで偽の `terminal_payment_id` を送ってきた場合（あるいは Stripe Terminal の表示と実際の決済が食い違う異常）に、POSLA 側が誤って `paid` に遷移しないよう保護する。

**運用復旧**:
- `STRIPE_MISMATCH` が発生したら Stripe Dashboard の Payments で `payment_intent` を直接確認 → 実際に succeeded していれば手動で `orders.status='paid'` 更新 + `payments` INSERT が必要（運営対応案件）

---

## 4.9 refund-payment.php — 返金処理（CR1）

### 4.9.1 概要

`POST /api/store/refund-payment.php`（manager 以上、2026-04-18 追加）

会計済みの支払いに対して**全額返金**を実行。Stripe カード決済は Stripe Refund API で自動返金、現金は `CASH_REFUND` エラーで拒否（手渡し対応）。

::: warning 部分返金は未対応
2026-04-19 時点では全額返金のみサポート。部分返金は将来拡張予定。
:::

### 4.9.2 リクエスト

```json
{
  "store_id": "s-xxx",
  "payment_id": "p-xxx",
  "reason": "料理間違い",
  "staff_pin": "1234"
}
```

| パラメータ | 必須 | 説明 |
|---|---|---|
| `store_id` | ✅ | テナント境界チェック用 |
| `payment_id` | ✅ | 返金対象の payments.id |
| `staff_pin` | ✅ | **CP1b で必須化**。返金担当スタッフの 4-8 桁 PIN |
| `reason` | — | 任意。デフォルト `'requested_by_customer'` |

### 4.9.3 内部処理

1. `require_role('manager')` でロールチェック
2. `require_store_access($storeId)` でテナント境界チェック
3. **CP1b PIN 検証**:
   - `attendance_logs` で当該店舗に**今日出勤中**（clock_in が今日かつ clock_out が NULL）のスタッフを抽出
   - 各スタッフの `cashier_pin_hash` に対して `password_verify($staffPin, $hash)` を実行
   - 一致するスタッフが見つからなければ `PIN_INVALID` (401) で拒否
   - 失敗時は `audit_log` に `cashier_pin_failed` イベント記録
4. payments 取得: `WHERE id = ? AND store_id = ?`（テナント境界二重チェック）
5. 返金可否チェック:
   - `payment_method='cash'` → `CASH_REFUND` (400) `現金決済はシステム返金できません`
   - `gateway_name`/`external_payment_id` 欠落 → `NO_GATEWAY` (400)
   - `refund_status != 'none'` → `ALREADY_REFUNDED` (400)
   - `gateway_status != 'succeeded'` → `INVALID_STATUS` (400)
6. **Stripe API 呼び出し（Pattern 別分岐）**:
   - `gateway_name='stripe_connect_terminal'` (Pattern B): `create_stripe_connect_refund()` でプラットフォームキー + `Stripe-Account` ヘッダ付きで Refund 作成（`refund_application_fee=true` でアプリ手数料も返金）
   - それ以外 (Pattern A): `create_stripe_refund()` でテナントの Secret Key で直接 Refund 作成
7. `payments` 更新: `refund_status='full'`, `refund_amount`, `refund_id`, `refund_reason`, `refunded_at=NOW()`, `refunded_by=$cashierUserId`
8. `audit_log` に `payment_refund` イベント記録（PIN 認証済みフラグ・金額・理由・refund_id）

### 4.9.4 レスポンス

```json
{
  "ok": true,
  "data": {
    "refunded": true,
    "refund_id": "re_xxx",
    "amount": 1500,
    "payment_id": "p-xxx"
  }
}
```

### 4.9.5 エラーコード一覧

| コード | HTTP | 原因 |
|---|---|---|
| `VALIDATION` | 400 | payment_id / store_id 欠落 |
| `PIN_REQUIRED` | 400 | staff_pin 空 |
| `INVALID_PIN` | 400 | PIN が 4-8 桁の数字でない |
| `MIGRATION` | 500 | CP1b マイグレーション未適用 |
| `PIN_INVALID` | 401 | PIN 不一致 or 出勤中スタッフでない |
| `MIGRATION_REQUIRED` | 500 | C-R1 マイグレーション未適用 |
| `NOT_FOUND` | 404 | payment_id が存在しない or 別店舗 |
| `CASH_REFUND` | 400 | 現金決済はシステム返金不可 |
| `NO_GATEWAY` | 400 | gateway_name / external_payment_id が空 |
| `ALREADY_REFUNDED` | 400 | refund_status が `none` 以外 |
| `INVALID_STATUS` | 400 | gateway_status が `succeeded` 以外 |
| `CONNECT_NOT_CONFIGURED` | 500 | Pattern B なのに connect_account_id 欠落 |
| `PLATFORM_KEY_MISSING` | 500 | posla_settings.stripe_secret_key 欠落 |
| `GATEWAY_NOT_CONFIGURED` | 500 | Pattern A なのに stripe_secret_key 欠落 |
| `REFUND_FAILED` | 502 | Stripe API エラー |

### 4.9.6 二重返金防止

- 同じ payment_intent に対して 2 回目の返金リクエストは Stripe 側で**冪等性**により拒否
- DB 側でも `refund_status != 'none'` チェックで弾く
- UI 上も `_refunding` フラグで返金ボタンが disabled になる（cashier-app.js）

### 4.9.7 cash の例外（手動返金フロー）

現金決済への返金は POSLA 上では実行できません。スタッフが手渡しで対応する運用フローになります。

```
[お客さん]                    [スタッフ]                    [POSLA]
   │                              │                              │
   │  「返金してほしい」          │                              │
   │ ─────────────────────────▶  │                              │
   │                              │  refund-payment.php POST     │
   │                              │ ─────────────────────────▶  │
   │                              │                              │
   │                              │  CASH_REFUND 400             │
   │                              │ ◀─────────────────────────  │
   │                              │                              │
   │                              │  レジ画面に                  │
   │                              │  「現金は手動で返金」        │
   │                              │  と表示                      │
   │                              │                              │
   │  ¥XXXX 手渡し                │                              │
   │ ◀───────────────────────── │                              │
   │                              │  cash_log にメモ手入力       │
   │                              │ ─────────────────────────▶  │
```

将来は `cash_refund` 専用エンドポイントを追加し `cash_log` に -金額 を記録する予定（未実装）。

---

## 4.10 監視・運用クエリ

### 4.10.1 テナント別 Pattern A/B 一覧

```sql
SELECT
  id, slug,
  CASE
    WHEN connect_onboarding_complete=1 AND charges_enabled=1 THEN 'B (Connect)'
    WHEN stripe_secret_key IS NOT NULL THEN 'A (Direct)'
    ELSE 'None'
  END AS pattern,
  payment_gateway,
  stripe_connect_account_id IS NOT NULL AS has_connect_acct,
  stripe_secret_key IS NOT NULL AS has_secret_key
FROM tenants
WHERE is_active = 1
ORDER BY pattern, slug;
```

### 4.10.2 直近 7 日の決済件数（Pattern 別）

```sql
SELECT
  CASE
    WHEN gateway_name='stripe_connect_terminal' THEN 'B (Connect Terminal)'
    WHEN gateway_name='stripe' THEN 'A (Direct)'
    WHEN payment_method='cash' THEN 'cash'
    WHEN payment_method='qr' THEN 'qr'
    ELSE gateway_name
  END AS pattern,
  COUNT(*) AS cnt,
  SUM(total_amount) AS total_yen
FROM payments
WHERE created_at > NOW() - INTERVAL 7 DAY
GROUP BY pattern
ORDER BY cnt DESC;
```

### 4.10.3 直近 30 日の返金一覧

```sql
SELECT
  p.id, p.store_id, p.total_amount, p.refund_amount,
  p.refund_reason, p.refunded_at, p.refunded_by,
  u.display_name AS refunded_by_name
FROM payments p
LEFT JOIN users u ON u.id = p.refunded_by
WHERE p.refund_status != 'none'
  AND p.refunded_at > NOW() - INTERVAL 30 DAY
ORDER BY p.refunded_at DESC;
```

### 4.10.4 Connect 接続テナントの稼働状況

```sql
SELECT id, slug,
       connect_onboarding_complete, charges_enabled, payouts_enabled,
       stripe_connect_account_id
FROM tenants
WHERE stripe_connect_account_id IS NOT NULL
ORDER BY (charges_enabled + payouts_enabled) DESC;
```

---

## 4.11 トラブルシューティング

### 4.11.1 症状別フローチャート

```
[症状]
├─ レジで「カードリーダーに接続できません」
│   ├─ terminal_pattern=null → 4.11.2
│   ├─ Stripe Terminal SDK 初期化失敗 → 4.11.3
│   └─ S700 Wi-Fi 切断 → 4.11.4
├─ 決済 succeeded だが POSLA に記録なし
│   └─ Webhook 失敗 → 4.11.5
├─ 返金エラー
│   ├─ CASH_REFUND → 4.11.6 (現金は手渡し対応)
│   ├─ PIN_INVALID → 4.11.7
│   ├─ ALREADY_REFUNDED → 4.11.8
│   └─ REFUND_FAILED → 4.11.9
├─ Pattern A から Pattern B に切り替えたい → 4.11.10
└─ Pattern B から Pattern A に切り替えたい → 4.11.11 (4.4 参照)
```

### 4.11.2 terminal_pattern が null

**原因**: `payment_gateway` が `none` または Connect 未完了かつ Secret Key 未設定。

**確認 SQL**:
```sql
SELECT id, payment_gateway, stripe_secret_key IS NOT NULL AS has_key,
       stripe_connect_account_id, connect_onboarding_complete, charges_enabled
FROM tenants WHERE id = 't-xxx';
```

**対処**: テナント owner に決済設定タブで Pattern A の Secret Key 設定 or Pattern B の Connect オンボーディング完了を依頼。

### 4.11.3 Stripe Terminal SDK 初期化失敗

**確認**:
- ブラウザ開発者ツール > Console で `StripeTerminal` の例外を確認
- ネットワークタブで `/api/connect/terminal-token.php` のレスポンスを確認

**よくある原因**:
- Connection Token の有効期限切れ（5 分）→ SDK が自動再取得するはずだが、失敗時はリロード
- Stripe 側の Terminal 機能が未有効 → Stripe Dashboard > Terminal で有効化

### 4.11.4 S700 Wi-Fi 切断

- 5GHz 帯のみのネットワークは非対応 → 2.4GHz 帯に接続
- ゲストネットワークだと Stripe サーバ通信遮断 → 通常 SSID 使用
- S700 本体の電源ボタン 10 秒長押しで強制再起動

### 4.11.5 Webhook 失敗で payments 行なし

**確認**:
1. Stripe Dashboard > Webhooks > 該当エンドポイントの「最近の試行」
2. POSLA 側 error_log

```bash
ssh odah@odah.sakura.ne.jp 'tail -200 ~/www/eat-posla/_logs/php_errors.log | grep -i payment'
```

**対処**:
- Stripe Dashboard で webhook を「再試行」
- 手動で `payments` 行を INSERT する場合は SQL での慎重なオペレーションが必要（運営判断）

### 4.11.6 CASH_REFUND エラー

**症状**: 返金 API 呼び出しで `CASH_REFUND` (400) `現金決済はシステム返金できません。手動で返金してください。`

**原因**: `payments.payment_method='cash'`。仕様通りの動作。

**対処**:
- スタッフがレジから現金を手渡し
- POSLA 上で記録は残らない（cash_refund 専用エンドポイントは未実装）
- 4.9.7 のフロー参照

### 4.11.7 PIN_INVALID

**原因**:
- 入力 PIN が間違っている
- 該当スタッフが今日出勤打刻していない
- `users.cashier_pin_hash` が NULL（PIN 未設定）

**確認 SQL**:
```sql
-- 今日出勤中で PIN 設定済みのスタッフ
SELECT u.id, u.display_name, u.cashier_pin_hash IS NOT NULL AS has_pin
FROM users u
INNER JOIN attendance_logs ar ON ar.user_id = u.id AND ar.store_id = 's-xxx'
WHERE u.tenant_id = 't-xxx'
  AND DATE(ar.clock_in) = CURDATE() AND ar.clock_out IS NULL;
```

**対処**:
- スタッフに自分の PIN を再確認させる
- マネージャーが dashboard.html > スタッフ管理 で PIN を再設定

### 4.11.8 ALREADY_REFUNDED

**原因**: `refund_status != 'none'`。同じ payment への 2 回目の返金リクエスト。

**対処**: payments テーブルで `refund_status` / `refund_amount` を確認し、テナントに「既に返金済み」と案内。

### 4.11.9 REFUND_FAILED (502)

**原因**: Stripe API エラー（charge が既に refunded、ネットワーク障害、charge_id 不正等）。

**確認**: `error_log` で Stripe レスポンスメッセージを確認。

**対処**: Stripe Dashboard で該当 PaymentIntent / Charge を直接確認。手動 refund が必要な場合は Stripe Dashboard から実行し、POSLA DB を SQL で同期。

### 4.11.10 Pattern A から Pattern B に切り替え

1. テナントで現在の Stripe Secret Key を控える（Pattern A 解除後に念のため戻せるように）
2. 決済設定タブで「使用する決済サービス」を `none` に変更（Secret Key を NULL に）
3. 「Stripe Connectに登録して決済を開始する」ボタンから Pattern B のオンボーディングへ
4. Connect 完了後、自動で Pattern B が優先

### 4.11.11 Pattern B から Pattern A に切り替え

→ 4.4 を参照。`/api/connect/disconnect.php` で Connect 切断 → Pattern A の Secret Key 設定。

### 4.11.12 ログ確認用コマンド集

```bash
# 直近の決済関連エラー
ssh odah@odah.sakura.ne.jp 'tail -200 ~/www/eat-posla/_logs/php_errors.log | grep -iE "(payment|refund|stripe)"'

# 直近の return-payment 呼び出し（audit_log）
mysql -e "
SELECT created_at, user_id, action, target_id, JSON_EXTRACT(detail_json, '$.refund_id')
FROM audit_log
WHERE action='payment_refund'
ORDER BY created_at DESC LIMIT 20;
"

# Pattern B テナントの Connect 状態スナップショット
mysql -e "
SELECT slug, connect_onboarding_complete, charges_enabled, payouts_enabled
FROM tenants
WHERE stripe_connect_account_id IS NOT NULL
ORDER BY slug;
"
```

---

## 4.12 FAQ（POSLA 運営者向け 27 件）

### A. アーキテクチャ全般

**Q1. Pattern A と B の振り分けは誰が決めますか？**
A. テナントの owner が「決済設定」タブで自分で決める。両方設定の場合は Pattern B が自動優先。

**Q2. Square はもう使えないのですか？**
A. 完全削除済み（P1-2、2026-04）。`payments.gateway_name='square'` の過去レコードのみ履歴として残置（0件確認済み）。今後 Square の追加実装は禁止。

**Q3. テナントが「Stripe 以外の決済を使いたい」と言ってきました**
A. 対応不可。POSLA は Stripe 専用設計。PayPay 等の QR 決済は外部アプリで処理し、`payment_method='qr'` で記録のみ残すフロー。

**Q4. テナントの決済が突然失敗する**
A. 4.11.2 の確認 SQL を実行。Pattern B なら `charges_enabled=1` を確認、Pattern A なら Secret Key の有効性を Stripe Dashboard で確認。

**Q5. Stripe Connect の手数料率を変えたい**
A. POSLA 管理画面 > API設定 > `connect_application_fee_percent` を変更。即座に反映。

### B. Pattern A（自前 Stripe キー）

**Q6. テナントが Stripe Secret Key を渡してきません**
A. `sk_live_xxx` は機密情報。SSH/メール/チャットで送らせない。Slack の Private Channel か Stripe Dashboard 経由で本人がコピペするのが安全。

**Q7. Pattern A のテナントに POSLA 手数料は課金できますか？**
A. できない。Pattern A は POSLA 経由しないので `application_fee` を付けられない。POSLA の収益は Stripe Billing（月額料金）のみ。

**Q8. Secret Key がローテーションされた**
A. テナントが「決済設定」タブで新しい Key を上書き保存。即座に反映。

**Q9. Pattern A で `charges_enabled=false` を検出するには？**
A. 直接の DB カラムなし（`charges_enabled` は Connect 用カラム）。Stripe API `GET /v1/account` を呼んで判定する必要があるが、Pattern A では POSLA から自動チェックしていない。決済失敗で間接的に検知。

### C. Pattern B（Stripe Connect）

**Q10. Connect オンボーディングが途中で離脱された**
A. `connect_onboarding_complete=0` のままになる。テナントが「決済設定」タブで「オンボーディングを続ける」をクリック → 中断箇所から再開。

**Q11. 審査落ちしたテナントへの対応**
A. Stripe Dashboard > Connect > 該当アカウントで「不足情報」を確認 → テナントに必要書類を案内。POSLA 側ではコード対応不要。

**Q12. Connect Account を完全削除したい**
A. POSLA 側は `/api/connect/disconnect.php` でひも付け解除のみ。Stripe 側のアカウント完全削除は Stripe Dashboard から（or Stripe API `POST /v1/accounts/acct_xxx/reject`）。

**Q13. Connect の application_fee は誰が受け取りますか？**
A. POSLA 運営（プラットフォーム親アカウント）。Stripe Dashboard > Connect > コレクション で集計可。

**Q14. Connect 経由でテナント自身がカード決済を打つのは禁止？**
A. 禁止ではないが、`charges_enabled=true` が前提。テナントは自分の Connect Dashboard でも決済を打てる（POSLA 経由しないので手数料はかからないが、POSLA 側に記録も残らない）。

### D. 返金（refund-payment.php）

**Q15. 部分返金はできないのですか？**
A. 2026-04-19 時点では全額返金のみ。部分返金は将来拡張予定。

**Q16. 返金時に PIN を求めるのは必須ですか？**
A. はい、CP1b で必須化（2026-04-18）。テナント側で PIN 確認をスキップしたい要望が来ても、不正対策のため変更しない。

**Q17. 返金を取り消したい（誤って返金してしまった）**
A. Stripe 側で refund を取り消すことはできない。同じ金額を再度カード決済する必要がある。POSLA UI からは未対応 → Stripe Dashboard から手動で新規 PaymentIntent 作成。

**Q18. 現金返金を POSLA 上に記録したい**
A. 現状未対応。スタッフが `cash_log` テーブルに手動でレコード追加するか、メモアプリで管理。将来 `cash_refund` 専用エンドポイント実装予定。

**Q19. 返金実行者の追跡はどこで見られますか？**
A. `payments.refunded_by`（PIN 認証された user_id）と `audit_log.action='payment_refund'`。4.10.3 の SQL 参照。

**Q20. 返金の application_fee はどうなりますか？**
A. Pattern B では `refund_application_fee=true` でアプリ手数料も同時返金。POSLA の収益から差し引かれる。

### E. Stripe Terminal / S700

**Q21. S700 はいくらですか？**
A. ¥52,480（税込、Stripe 価格）。POSLA 運営経由で発注（在庫管理上の都合）。

**Q22. S700 を複数台繋げますか？**
A. 可能。レジ用 PC/タブレット 1 台に対して 1 台の S700 をペアリング。複数のレジがあればそれぞれに別の S700。

**Q23. Connection Token の有効期限は？**
A. **5 分**。Stripe Terminal SDK が定期的に再取得するため通常意識不要。

**Q24. Pattern A と B で S700 の使い方は変わりますか？**
A. テナント側の操作は同じ。サーバー側のみ `terminal-intent.php` / `terminal-token.php` で経路分岐。

### F. 監視・運用

**Q25. 月次で運営が確認すべきクエリは？**
A. 4.10 の 4 クエリ（Pattern 一覧 / 決済件数 / 返金一覧 / Connect 稼働状況）。月次レポートに含めることを推奨。

**Q26. 大きな金額の返金（10万円超など）はアラート通知できますか？**
A. 現状未実装。希望があれば cron で `payments.refund_amount > 100000` を検出し Slack 通知する仕組みを追加可能。

**Q27. テスト環境で Stripe Terminal を動かしたい**
A. Stripe Dashboard > Terminal でテストモードに切替 → simulator reader を使う。S700 実機は本番モード専用。

---

## X.X 更新履歴
- **2026-04-19 (S3 セキュリティ修正)**: 4.8.5 にエラーコード `CONFLICT` / `AMOUNT_MISMATCH` / `STRIPE_MISMATCH` を追加。4.8.6 を新設し Stripe Terminal の retrieve 検証フロー（BEGIN + FOR UPDATE + retrieve + status/amount 確認）を文書化。handy-order.php もサーバー側で `order-validator.php` 流用の価格再計算を実施。
- **2026-04-19**: フル粒度展開（4.6 オンボーディング ASCII、4.10 監視 SQL、4.11 トラブルシューティング、4.12 FAQ 27件追加）。4.9.5 エラーコード一覧、4.9.7 cash 例外フロー、CP1b PIN 必須化反映
- **2026-04-19**: 4.8 process-payment.php / 4.9 refund-payment.php 詳細仕様追加
- **2026-04-18**: frontmatter 追加、AIヘルプデスク knowledge base として整備
