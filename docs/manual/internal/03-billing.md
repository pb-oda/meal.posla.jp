# 3. 課金・サブスクリプション

POSLAはStripe Billingを使用したSaaS型のサブスクリプション課金モデルを採用しています。

## 3.1 プラン構成

| プラン | 月額目安 | 対象 |
|--------|---------|------|
| standard | ¥10,000 | 店内飲食の基本運営 |
| pro | ¥20,000〜30,000 | 全チャネル＋経営管理 |
| enterprise | ¥50,000 | 多店舗統括 |

### プラン別機能一覧

**standard（店内飲食の基本運営）：**
- セルフオーダー＋AIウェイター
- ハンディPOS＋フロアマップ＋テーブル合流分割
- KDS＋AI音声コマンド
- 基本レポート（売上日報）
- オフライン検知＋マルチセッション制御

**pro（全チャネル＋経営管理）：**
- standardの全機能に加えて：
- 在庫管理＋AI需要予測
- 高度レポート（回転率・客層・スタッフ評価・併売分析）＋監査ログ＋満足度評価
- テイクアウト＋決済ゲートウェイ（Stripe）
- 多言語対応
- シフト管理

**enterprise（多店舗統括）：**
- proの全機能に加えて：
- HQメニュー一括配信
- クロス店舗分析（ABC分析等）
- 統合シフトビュー
- 店舗間ヘルプ要請

---

## 3.2 Stripe Billing設定

### 事前準備

POSLA管理画面の「API設定」タブで以下を設定します。

| キー | 値 | 取得元 |
|------|-----|--------|
| stripe_secret_key | Stripeシークレットキー | Stripe Dashboard > Developers > API Keys |
| stripe_publishable_key | Stripe公開キー | 同上 |
| stripe_webhook_secret | Webhookシークレット | Stripe Dashboard > Developers > Webhooks |
| stripe_price_standard | Standardプランの価格ID | Stripe Dashboard > Products |
| stripe_price_pro | Proプランの価格ID | 同上 |
| stripe_price_enterprise | Enterpriseプランの価格ID | 同上 |

### Stripe上での商品作成手順

1. Stripe Dashboardで商品を作成（例：「POSLA Standard」）
2. 月額の価格を設定（例：¥10,000/月）
3. 作成された価格ID（`price_xxx`）をPOSLA管理画面に入力

### Webhookの設定

1. Stripe Dashboardの「Developers」→「Webhooks」で新規エンドポイントを追加
2. URL：`https://あなたのドメイン/api/subscription/webhook.php`
3. 以下のイベントを選択：
   - `checkout.session.completed`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
4. Webhookシークレット（`whsec_xxx`）をPOSLA管理画面に入力

---

## 3.3 サブスクリプションのライフサイクル

### 新規契約

1. テナントのオーナーがプラン選択画面にアクセスします
2. Stripe Checkoutセッションが作成されます
3. オーナーがStripeの決済フォームでクレジットカード情報を入力します
4. 決済完了後、`checkout.session.completed` Webhookが発火します
5. POSLAがテナントのサブスクリプション情報を更新します

### ステータスの遷移

| ステータス | 意味 |
|-----------|------|
| none | サブスクリプション未設定 |
| trialing | 試用期間中 |
| active | 有効（支払い済み） |
| past_due | 支払い延滞 |
| canceled | キャンセル済み |

### Webhookイベントの処理

| イベント | POSLAの処理 |
|---------|------------|
| checkout.session.completed | サブスクリプション作成、プラン設定 |
| customer.subscription.updated | プラン変更の反映（アップグレード/ダウングレード） |
| customer.subscription.deleted | サブスクリプションのキャンセル処理 |
| invoice.paid | 支払い成功のログ記録 |
| invoice.payment_failed | 支払い失敗のログ記録 |

### Webhookの検証

- HMAC-SHA256署名によるリクエスト検証
- タイムスタンプチェック（5分以内のリクエストのみ有効）
- 検証失敗は400エラーを返却

### イベントログ

全Webhookイベントは`subscription_events`テーブルに記録されます。

| フィールド | 説明 |
|-----------|------|
| event_id | UUID |
| tenant_id | 対象テナント |
| event_type | Webhookイベントタイプ |
| stripe_event_id | StripeのイベントID |
| data | イベントデータ（JSON） |
| created_at | 受信日時 |

---

## 3.4 プラン変更

### アップグレード

テナントがより上位のプランに変更する場合：
1. Stripe上でサブスクリプションの価格IDを変更
2. `customer.subscription.updated` Webhookが発火
3. POSLAがプランを更新し、`plan_features`に基づいて新機能が有効化

### ダウングレード

テナントがより下位のプランに変更する場合：
1. Stripe上でサブスクリプションの価格IDを変更
2. Webhookでプランが更新
3. 上位プラン専用の機能のタブが管理ダッシュボードで非表示になる

---

## 3.5 テナントデータベースのサブスクリプション関連フィールド

| フィールド | 型 | 説明 |
|-----------|-----|------|
| stripe_customer_id | VARCHAR(50) | StripeのカスタマーID（cus_xxx） |
| stripe_subscription_id | VARCHAR(50) | StripeのサブスクリプションID（sub_xxx） |
| subscription_status | ENUM | none / active / past_due / canceled / trialing |
| current_period_end | DATETIME | 現在の課金期間の終了日 |
