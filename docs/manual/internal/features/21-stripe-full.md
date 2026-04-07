# 21. Stripe決済の全設定（完全ガイド）

POSLAはStripeを2つの目的で利用しています。

1. **Stripe Billing** — POSLA自体の月額利用料の課金（テナントがPOSLAに支払う）
2. **Stripe Connect** — テナントがお客さんから決済を受け取る（カード/オンライン決済）

本章ではテナント側でやること・POSLA運営側でやることを、どちらも完全な手順で記載します。

---

## 21.1 全体像

### 登場人物

| 役割 | 説明 |
|------|------|
| POSLA運営（プラスビリーフ） | Stripeプラットフォーム事業者。Stripe Billing・Stripe Connectの親アカウントを持つ |
| テナント（飲食店） | POSLAに月額料金を支払い、Stripe Connect経由でお客さんから決済を受け取る |
| お客さん | テナントの飲食店で注文し、カード決済する |

### お金の流れ

```
【Stripe Billing】
テナント → 月額プラン料金 → Stripe → POSLA運営

【Stripe Connect】
お客さん → カード決済 → Stripe → テナント売上 − POSLA手数料(1.0%) − Stripe決済手数料
```

---

## 21.2 Stripe Billing（POSLA月額料金）

### 21.2.1 テナント側の申込み手順

1. POSLAのランディングページまたはオーナーダッシュボードの「契約・プラン」タブを開きます
2. プランを選択します（standard / pro / enterprise）
3. 「サブスクリプション開始」ボタンをクリックします
4. Stripe Checkoutの決済ページにリダイレクトされます
5. クレジットカード情報を入力します
6. 決済完了後、自動的にPOSLAに戻り、プランが有効化されます

### 21.2.2 プラン変更・解約

**アップグレード（standard → pro 等）：**
1. 「契約・プラン」タブで上位プランを選択
2. 差額が日割り計算されて即座に課金されます
3. 新プランの機能が即時有効化されます

**ダウングレード：**
1. 「契約・プラン」タブで下位プランを選択
2. 現在の課金期間の終了時にダウングレードが反映されます（次回請求から新料金）
3. 上位プラン専用のタブは期間終了後に非表示になります

**解約：**
1. 「契約・プラン」タブの「サブスクリプションを解約」をクリック
2. 現在の課金期間の終了時に解約されます
3. 解約後はPOSLAへのログインはできますが、機能制限モードになります

### 21.2.3 支払い状況の確認

「契約・プラン」タブで以下が確認できます。

- 現在のプラン
- 次回請求日
- 前回の支払い状況（成功/失敗）
- 請求書のダウンロード（Stripe Customer Portal）

### 21.2.4 支払い失敗時の動作

| 状態 | 説明 |
|------|------|
| 1回目の失敗 | 警告メール送信。機能は引き続き利用可能 |
| 3日後に再試行 | Stripeが自動で再課金 |
| 最終失敗（7日後） | `subscription_status`が`past_due`に変わり、警告バナー表示 |
| 長期未払い | `canceled`になり、機能制限モード |

### 21.2.5 POSLA運営側の設定手順

POSLA運営（プラスビリーフ）が初期設定時に行う作業です。

**ステップ1：Stripe Dashboardで商品を作成**
1. [Stripe Dashboard](https://dashboard.stripe.com/) にログイン
2. 「商品」→「商品を追加」
3. 3つの商品を作成：
   - POSLA Standard — 月額 ¥10,000
   - POSLA Pro — 月額 ¥20,000〜30,000
   - POSLA Enterprise — 月額 ¥50,000
4. 各商品の価格IDを控える（`price_xxxx` 形式）

**ステップ2：APIキーを取得**
1. Stripe Dashboard > 開発者 > APIキー
2. シークレットキー（`sk_live_xxx`）を取得
3. 公開可能キー（`pk_live_xxx`）を取得

**ステップ3：Webhookエンドポイントを登録**
1. Stripe Dashboard > 開発者 > Webhooks > 「エンドポイントを追加」
2. URL: `https://eat.posla.jp/api/subscription/webhook.php`
3. 監視イベント：
   - `checkout.session.completed`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
4. Webhook署名シークレット（`whsec_xxx`）を取得

**ステップ4：POSLA管理画面に入力**

POSLA管理画面（`/public/posla-admin/dashboard.html`）の「API設定」タブで以下を入力します。

| キー名 | 値 |
|--------|-----|
| stripe_secret_key | `sk_live_xxx` |
| stripe_publishable_key | `pk_live_xxx` |
| stripe_webhook_secret | `whsec_xxx` |
| stripe_price_standard | Standard商品の価格ID |
| stripe_price_pro | Pro商品の価格ID |
| stripe_price_enterprise | Enterprise商品の価格ID |

**ステップ5：動作確認**
1. テストテナントでサブスクリプション開始フローを実行
2. Stripe DashboardのWebhookログで全イベントが正常に受信されているか確認
3. POSLA側の`subscription_events`テーブルにイベントが記録されているか確認

---

## 21.3 Stripe Connect（テナントがお客さんから決済を受け取る）

### 21.3.1 テナント側の接続手順

1. オーナーダッシュボードにログイン
2. 「決済設定」タブを開く
3. 「Stripe接続を開始」ボタンをクリック
4. POSLAがStripe Express Accountを作成
5. Stripeのオンボーディングページにリダイレクト
6. 以下の情報を入力：
   - 事業者情報（法人名・代表者名・住所・電話番号）
   - 銀行口座情報（入金先）
   - 本人確認書類（運転免許証・マイナンバーカード等）
   - 事業内容（飲食店）
7. Stripeが審査（通常1〜3営業日）
8. 審査完了後、POSLAの「決済設定」タブで接続状態が「有効」に変わります

### 21.3.2 接続状態の確認

「決済設定」タブで以下が確認できます。

| 項目 | 意味 |
|------|------|
| charges_enabled | お客さんからの決済受付が可能か（true/false） |
| payouts_enabled | 入金（銀行振込）が有効か |
| details_submitted | 必要情報の入力が完了しているか |

すべてがtrueになっている必要があります。いずれかがfalseの場合、Stripeダッシュボードで追加情報の入力が必要です。

### 21.3.3 決済方法の設定

テナントは店舗設定で利用する決済方法を選択できます。

| 決済方法 | 説明 | 必要な接続 |
|---------|------|-----------|
| 現金 | テンキーで入力 | 不要 |
| クレジット（対面） | Stripe Reader S700 | Stripe Connect |
| QR決済（PayPay等） | 外部QR決済アプリで処理 | 不要 |
| セルフレジ（お客さんスマホ） | Stripe オンライン決済 | Stripe Connect |

### 21.3.4 カードリーダー（Stripe Reader S700）

対面でクレジットカード決済を受ける場合、Stripe Reader S700が必要です。

| 項目 | 値 |
|------|-----|
| 価格 | ¥52,480 |
| 接続方式 | Wi-Fi |
| 対応プラットフォーム | Web（Stripe Terminal JS SDK） |
| 発注方法 | POSLA運営経由でStripeに発注 |

**セットアップ手順：**
1. 端末を電源に接続し、Wi-Fiに接続
2. レジアプリ（`/public/kds/register.html`）で「カードリーダー接続」ボタンをクリック
3. 接続トークンが自動発行され、レジアプリがリーダーと通信を開始
4. リーダーが「Ready」と表示されたら決済可能

**決済フロー：**
1. レジでメニューを選択し、合計金額を確定
2. 「クレジット（対面）」を選択
3. カードリーダーに金額が表示される
4. お客さんがカードをタッチ/挿入
5. 決済結果がレジに返される
6. 成功時：レシートを印刷して完了

### 21.3.5 セルフレジ（お客さんのスマホで決済）

セルフオーダー画面からお客さんがカード決済する場合です。

1. お客さんがカートから「チェックアウト」へ
2. 決済方法選択画面で「クレジットカード」を選択
3. Stripe Elementsの決済フォームが表示される
4. カード情報を入力して「支払う」
5. 決済完了後、注文が自動送信される

### 21.3.6 手数料の仕組み

```
お客さんの支払額 = 注文金額
  ↓
Stripe が決済を処理
  ↓
テナント受取額 = 注文金額 − POSLA手数料 − Stripe決済手数料
```

| 手数料 | 率 | 取得者 |
|--------|-----|-------|
| Stripe決済手数料 | 3.6% | Stripe |
| POSLA手数料 | 1.0% | POSLA運営 |

**計算例（¥3,000の決済）：**

| 項目 | 金額 |
|------|------|
| 決済金額 | ¥3,000 |
| Stripe決済手数料（3.6%） | ¥108 |
| POSLA手数料（1.0%） | ¥30 |
| テナント受取額 | ¥2,862 |

POSLA手数料は `ceil(決済金額 × 1.0 / 100)` で計算され、最低¥1です。

### 21.3.7 入金サイクル

- 決済から約7日後にテナント指定の銀行口座に自動入金されます
- 入金サイクルはStripe Dashboardで確認・変更できます
- 週次入金、月次入金などの設定が可能

### 21.3.8 POSLA運営側の設定手順

**ステップ1：Stripe Connectプラットフォーム登録**
1. Stripe Dashboardで「Connect」を有効化
2. プラットフォーム情報を入力（POSLAのビジネス情報）
3. 対応国：日本（JP）
4. アカウントタイプ：Express

**ステップ2：Connect手数料の設定**

POSLA管理画面の「API設定」タブで以下を設定します。

| キー名 | デフォルト | 説明 |
|--------|----------|------|
| connect_application_fee_percent | 1.0 | POSLA手数料率（%） |

**ステップ3：Stripe Terminal（カードリーダー）用の設定**

- Stripeダッシュボードで Terminal 機能を有効化
- POSLA側は接続トークン発行エンドポイント（`POST /api/connect/terminal-token.php`）で対応

**ステップ4：テナントの接続状態を定期確認**

- `stripe_connect_account_id` が設定されているテナントに対して、Stripe APIで最新の `charges_enabled`/`payouts_enabled`/`details_submitted` を取得
- POSLA管理画面の「テナント管理」タブで接続状態を一覧表示

---

## 21.4 データベース構造

### テナントテーブルのStripe関連フィールド

| フィールド | 型 | 説明 |
|-----------|-----|------|
| stripe_customer_id | VARCHAR(50) | StripeカスタマーID（`cus_xxx`） |
| stripe_subscription_id | VARCHAR(50) | サブスクリプションID（`sub_xxx`） |
| subscription_status | ENUM | none/trialing/active/past_due/canceled |
| current_period_end | DATETIME | 現在の課金期間終了日 |
| stripe_connect_account_id | VARCHAR(100) | Connect アカウントID（`acct_xxx`） |
| connect_onboarding_complete | TINYINT(1) | オンボーディング完了フラグ |

### subscription_events テーブル

全Webhookイベントの受信ログです。

| フィールド | 説明 |
|-----------|------|
| event_id | UUID |
| tenant_id | 対象テナント |
| event_type | Webhookイベントタイプ |
| stripe_event_id | StripeのイベントID |
| data | イベントデータ（JSON） |
| created_at | 受信日時 |

---

## 21.5 トラブルシューティング

### Stripe Billing関連

| 問題 | 原因 | 対処 |
|------|------|------|
| サブスクリプション開始後にプランが反映されない | Webhookが届いていない | Stripe Dashboardでイベントログを確認。再送信 |
| 請求書が発行されない | メールアドレス未登録 | テナント設定でメールアドレスを登録 |
| カード決済が失敗する | カード与信問題 | Stripe Customer Portalで別カードに変更 |

### Stripe Connect関連

| 問題 | 原因 | 対処 |
|------|------|------|
| 接続状態が「無効」のまま | オンボーディング未完了 | Stripeから届いたメールの指示に従って情報を追加入力 |
| `charges_enabled`がfalse | 審査中または書類不備 | Stripe Dashboardでメッセージを確認 |
| カードリーダー接続できない | Wi-Fi切断・ファームウェア古い | リーダー再起動・ファームウェア更新 |
| 決済完了するが注文が記録されない | Webhook失敗 | `subscription_events`テーブルと`audit_log`を確認 |
