# 4. 決済アーキテクチャ（Pattern A / Pattern B）

POSLAのテナント決済は2つのパターンで動作します。テナントごとに選択可能で、バックエンドが自動で経路を切り替えます。

## 4.1 2つのパターン

| 項目 | パターンA：自前Stripeキー | パターンB：Stripe Connect |
|------|--------------------------|----------------------------|
| 使用するカラム | `tenants.stripe_secret_key` | `tenants.stripe_connect_account_id` + `connect_onboarding_complete=1` |
| Stripe呼び出し | テナント固有のSecret Keyで直接 | POSLAプラットフォームキー + `Stripe-Account` ヘッダ |
| application_fee | なし（POSLA手数料ゼロ） | 1.0%（`ceil(amount * 1.0 / 100)`、最低¥1） |
| 入金先 | テナント自身のStripe残高 | テナント自身のConnect残高 |
| 使える機能 | レジカードリーダー、セルフレジ、テイクアウト | レジカードリーダー、セルフレジ、テイクアウト |

### 優先順位

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

---

## 4.3 Stripe Terminal（カードリーダー）の分岐

`api/store/terminal-intent.php`:

- **Connect**: `create_stripe_connect_terminal_intent()` でPayment Intent作成（`application_fee_amount` を付与）
- **Direct**: Pattern A用の直接Payment Intent作成（手数料なし）
- **None**: 400エラー `NOT_CONNECTED`

`api/connect/terminal-token.php`:

- **Connect**: `Stripe-Account` ヘッダ付きで Connection Token 発行
- **Direct**: テナントのSecret Keyで Connection Token 発行（ヘッダなし）
- **None**: 400エラー

クライアント側（`cashier-app.js`）:
```js
if (!json.data.terminal_pattern) return;  // 未接続なら Terminal SDK 初期化をスキップ
_initStripeTerminal();
```

---

## 4.4 Stripe Connect の解除（P1-1c）

テナントがパターンBからパターンAに切り替えたい場合に使う機能。

**エンドポイント：** `POST /api/connect/disconnect.php`（owner ロール必須）

**処理：**
```sql
UPDATE tenants SET
  stripe_connect_account_id = NULL,
  connect_onboarding_complete = 0,
  charges_enabled = 0,
  payouts_enabled = 0
WHERE id = ?
```

- Stripe APIは呼ばない（Stripeダッシュボード側のアカウントはそのまま残る）
- `audit_log` に記録
- 統一レスポンス形式で `{ ok: true, data: { disconnected: true } }` を返す

**UI:** owner-dashboard の「決済設定」タブ > Stripe Connect有効ブロック > 「Stripe Connectを解除」ボタン。`renderConnectStatus()` の `if (data.connected && data.charges_enabled)` ブロックの中にのみ描画されるため、未接続状態では表示されない。

---

## 4.5 手数料計算（パターンB）

```
手数料 = ceil(決済金額 × connect_application_fee_percent / 100)
最低手数料 = ¥1
```

**設定：** `posla_settings.connect_application_fee_percent`（デフォルト `1.0`）

POSLA管理画面の「API設定」タブで変更可能。変更は即座に反映される。

**計算例：**

| 決済金額 | 手数料率 | 手数料 | テナント受取額（Stripe手数料別） |
|---------|---------|--------|----------------------------------|
| ¥1,000 | 1.0% | ¥10 | ¥990 |
| ¥3,000 | 1.0% | ¥30 | ¥2,970 |
| ¥10,000 | 1.0% | ¥100 | ¥9,900 |
| ¥50 | 1.0% | ¥1（最低額） | ¥49 |

---

## 4.6 テナントのConnect接続（パターンB）

### オンボーディングフロー

1. テナントのオーナーがオーナーダッシュボードの「決済設定」タブを開く
2. 「Stripe Connectに登録して決済を開始する」ボタンをクリック
3. POSLAがStripe Express Accountを作成
   - アカウントタイプ：Express
   - 国：JP（日本）
   - 有効化する機能：`card_payments`, `transfers`
4. Stripeのオンボーディングページにリダイレクト
5. テナントがStripe上で以下を入力
   - ビジネス情報
   - 銀行口座情報
   - 本人確認書類
6. オンボーディング完了後、POSLAにリダイレクト
7. `connect_onboarding_complete=1` に更新

### 接続状態の取得

POSLAは `/api/connect/status.php` でStripeのアカウント情報を定期取得：

| フィールド | 説明 |
|-----------|------|
| `charges_enabled` | 決済の受付が有効か |
| `payouts_enabled` | 入金（振込）が有効か |
| `details_submitted` | 必要情報の入力が完了しているか |

---

## 4.7 DBフィールド一覧

### Connect関連（パターンB）

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `stripe_connect_account_id` | VARCHAR(100) | StripeのConnectアカウントID（`acct_xxx`） |
| `connect_onboarding_complete` | TINYINT(1) | オンボーディング完了フラグ（0/1） |
| `charges_enabled` | TINYINT(1) | 決済受付可能か |
| `payouts_enabled` | TINYINT(1) | 入金有効か |

### 自前キー関連（パターンA）

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `payment_gateway` | ENUM('none','stripe') | 使用する決済ゲートウェイ |
| `stripe_secret_key` | VARCHAR(200) | テナント自前のStripe Secret Key |

### Square関連（削除済み）

2026年4月のP1-2で `square_access_token`, `square_location_id` カラムを DROP し、`payment_gateway` ENUMから `'square'` を除外した（マイグレーション: `sql/migration-p1-remove-square.sql`）。過去の `payments.gateway_name='square'` データは履歴として残っている（0件確認済み）。
