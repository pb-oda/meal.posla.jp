# 21. Stripe決済の全設定（完全ガイド）

POSLAはStripeを2つの目的で利用しています。

1. **Stripe Billing** — POSLA自体の月額利用料の課金（テナントがPOSLAに支払う）
2. **テナント決済** — テナントがお客さんから決済を受け取る（カード/オンライン決済）。さらに2方式あります：
   - **パターンA：自前Stripeキー** — テナントが自分のStripeアカウントを持っている場合
   - **パターンB：POSLA経由Connect** — テナントが自分のStripeアカウントを持っていない場合

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

【テナント決済：パターンA（自前Stripeキー）】
お客さん → カード決済 → Stripe → テナント売上 − Stripe決済手数料（POSLA手数料なし）

【テナント決済：パターンB（POSLA経由Connect）】
お客さん → カード決済 → Stripe → テナント売上 − POSLA手数料(1.0%) − Stripe決済手数料
```

### パターンAとパターンBの比較

| 項目 | パターンA：自前Stripeキー | パターンB：POSLA経由Connect |
|------|--------------------------|------------------------------|
| テナント側の事前準備 | Stripeアカウント開設・審査済 | 不要（POSLAが代行） |
| Secret Key管理 | テナントが `sk_live_xxx` をPOSLAに登録 | 登録不要（Connect Account ID のみ） |
| Stripe決済手数料 | Stripe標準（通常3.6%） | Stripe標準（通常3.6%） |
| POSLA手数料 | なし | 1.0%（`application_fee`） |
| 入金先 | テナント自身のStripe残高 | テナント自身のStripe Connect残高 |
| レジカードリーダー | ✅ 利用可能（Stripe Reader S700） | ✅ 利用可能（Stripe Reader S700） |
| セルフレジ | ✅ 利用可能 | ✅ 利用可能 |
| テイクアウト決済 | ✅ 利用可能 | ✅ 利用可能 |
| 切り替え | B解除後にA設定可能 | Aを削除後にConnect接続可能 |

どちらを選んでも、POSLAの決済関連機能はすべて同じように使えます。パターンAとパターンBの両方が設定されている場合は、**パターンB（Connect）が優先**されます。

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

## 21.3 パターンA：自前Stripeキー

テナントが自分のStripeアカウントを既に持っている場合に選択します。POSLA手数料はかかりません。

### 21.3.1 事前準備

1. Stripeアカウント開設（[https://dashboard.stripe.com/](https://dashboard.stripe.com/)）
2. 本人確認・事業者審査の完了
3. `charges_enabled` になっていること（Stripe Dashboard > 設定 > アカウント で確認）
4. Stripe Dashboard > 開発者 > APIキー で Secret Key（`sk_live_xxx`）を取得

### 21.3.2 POSLAへの登録手順

1. オーナーダッシュボードの「決済設定」タブを開く
2. 「使用する決済サービス」で「Stripe」を選択
3. 「Stripe Secret Key」欄に `sk_live_xxx` を貼り付けて「保存」
4. 保存後は `sk_live_●●●●1234` のようにマスクされ、キーそのものはPOSLA上で再表示されない
5. この時点でパターンAが有効になり、レジ・セルフレジ・テイクアウトすべての決済が使えるようになる

### 21.3.3 動作確認

- レジ会計画面でカードリーダー決済を試す（下記21.5を参照）
- 少額テスト決済後、Stripe Dashboard > 決済 で該当の決済が記録されていることを確認
- テナント自身のStripe残高に入金されること（`application_fee` は引かれない）

### 21.3.4 キーの更新・削除

- **更新**: 同じ「決済設定」タブで新しいキーを入力して「保存」
- **削除**: 「削除」ボタンで `stripe_secret_key = NULL` になる。削除後はパターンAが無効化

### 21.3.5 トラブルシューティング

| 問題 | 原因 | 対処 |
|------|------|------|
| 「保存」後もカードリーダーが使えない | Secret Keyが無効 | Stripe Dashboardでキー再発行 |
| Permission エラー | Terminal機能が未有効 | Stripe Dashboard > Terminal を有効化 |
| 決済失敗 | `charges_enabled=false` | 本人確認・事業者審査を完了 |

---

## 21.4 パターンB：POSLA経由Connect（Stripe Connect）

### 21.4.1 テナント側の接続手順

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

### 21.4.2 接続状態の確認

「決済設定」タブで以下が確認できます。

| 項目 | 意味 |
|------|------|
| charges_enabled | お客さんからの決済受付が可能か（true/false） |
| payouts_enabled | 入金（銀行振込）が有効か |
| details_submitted | 必要情報の入力が完了しているか |

すべてがtrueになっている必要があります。いずれかがfalseの場合、Stripeダッシュボードで追加情報の入力が必要です。

### 21.4.3 決済方法の設定

テナントは店舗設定で利用する決済方法を選択できます。

| 決済方法 | 説明 | 必要な接続 |
|---------|------|-----------|
| 現金 | テンキーで入力 | 不要 |
| クレジット（対面） | Stripe Reader S700 | パターンA または パターンB |
| QR決済（PayPay等） | 外部QR決済アプリで処理 | 不要 |
| セルフレジ（お客さんスマホ） | Stripe オンライン決済 | パターンA または パターンB |

### 21.4.4 カードリーダー（Stripe Reader S700）

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

::: tip パターンA / パターンB 両対応
Stripe Reader S700 はパターンA（自前キー）・パターンB（Connect）のどちらでも利用できます。POSLAのバックエンドが `terminal_pattern` を自動判定して適切なStripeアカウントでPayment Intentを作成します。
:::

### 21.4.5 セルフレジ（お客さんのスマホで決済）

セルフオーダー画面からお客さんが直接決済する場合です。

1. お客さんが注文送信後、画面下部の「お会計」ボタンをタップ
2. 未払い注文一覧と税率別内訳がモーダルで表示される
3. 決済手段ロゴ列（VISA / Mastercard / JCB / AMEX / Apple Pay / Google Pay）と「お支払いに進む」ボタンが表示される
4. 「お支払いに進む」をタップ → Stripe Checkout（Stripeが提供する安全な決済ページ）に遷移
5. クレジットカードを入力するか、Apple Pay / Google Pay / Link で1タップ決済
6. 決済完了後、自動的にセルフオーダー画面に戻り、「お支払い完了」が表示される
7. 「領収書を表示」ボタンで30分限定のお会計明細HTMLを表示（スクリーンショット保存可）

::: tip Apple Pay / Google Pay の有効化
Apple Pay / Google Pay / Link はコード上で個別に有効化する必要はなく、**Stripe Dashboard の「Settings → Payment methods」で有効化** すれば Stripe Checkout 上に自動表示されます。POSLA側のコード変更は不要です。

ドメイン認証も Stripe Checkout 経由なら Stripe 側で自動処理されるため、テナント側で `apple-developer-merchantid-domain-association` ファイルを配置する必要はありません。
:::

### 21.4.6 手数料の仕組み（パターンBのみ）

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

※パターンA（自前Stripeキー）の場合、POSLA手数料はかかりません。Stripe決済手数料のみです。

**計算例（¥3,000の決済）：**

| 項目 | 金額 |
|------|------|
| 決済金額 | ¥3,000 |
| Stripe決済手数料（3.6%） | ¥108 |
| POSLA手数料（1.0%） | ¥30 |
| テナント受取額 | ¥2,862 |

POSLA手数料は `ceil(決済金額 × 1.0 / 100)` で計算され、最低¥1です。

### 21.4.7 入金サイクル

- 決済から約7日後にテナント指定の銀行口座に自動入金されます
- 入金サイクルはStripe Dashboardで確認・変更できます
- 週次入金、月次入金などの設定が可能

### 21.4.8 Stripe Connectの解除（パターンAへの切り替え）

パターンBからパターンAに切り替えたい場合は、「決済設定」タブのStripe Connectブロックにある「Stripe Connectを解除」ボタンを使います。

**手順：**
1. オーナーダッシュボード > 決済設定タブ
2. 「Stripe Connect：有効」ブロックの「Stripe Connectを解除」ボタンをクリック
3. 確認ダイアログで「OK」をクリック
4. 「Stripe Connectを解除しました」と表示され、画面が更新される
5. Stripe Secret Key の入力欄が活性化し、パターンAの設定が可能になる

**ポイント：**
- 解除はPOSLA側のDB（`stripe_connect_account_id` 等）を空にするだけで、Stripeダッシュボードのアカウント自体は残ります
- 必要であれば後で「Stripe Connectに登録して決済を開始する」ボタンから再接続できます
- Stripeダッシュボード側のアカウントを完全に削除したい場合は、Stripeダッシュボードから直接削除してください

::: warning 解除中の注意
解除から再接続（またはパターンA設定）までの間は、テナント決済（レジ・セルフレジ・テイクアウト）が一時的に停止します。営業時間外に実施することを推奨します。
:::

### 21.4.9 POSLA運営側の設定手順

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

## 21.5 データベース構造

### テナントテーブルのStripe関連フィールド

| フィールド | 型 | 説明 |
|-----------|-----|------|
| payment_gateway | ENUM('none','stripe') | 使用する決済ゲートウェイ |
| stripe_secret_key | VARCHAR(200) | パターンA用。テナント自前のStripe Secret Key（`sk_live_xxx`） |
| stripe_customer_id | VARCHAR(50) | Stripe BillingのカスタマーID（`cus_xxx`） |
| stripe_subscription_id | VARCHAR(50) | Stripe Billingのサブスクリプション（`sub_xxx`） |
| subscription_status | ENUM | none/trialing/active/past_due/canceled |
| current_period_end | DATETIME | 現在の課金期間終了日 |
| stripe_connect_account_id | VARCHAR(100) | パターンB用。Connect アカウントID（`acct_xxx`） |
| connect_onboarding_complete | TINYINT(1) | オンボーディング完了フラグ |
| charges_enabled | TINYINT(1) | Connect：決済受付可能か |
| payouts_enabled | TINYINT(1) | Connect：入金が有効か |

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

## 21.6 トラブルシューティング

### Stripe Billing関連

| 問題 | 原因 | 対処 |
|------|------|------|
| サブスクリプション開始後にプランが反映されない | Webhookが届いていない | Stripe Dashboardでイベントログを確認。再送信 |
| 請求書が発行されない | メールアドレス未登録 | テナント設定でメールアドレスを登録 |
| カード決済が失敗する | カード与信問題 | Stripe Customer Portalで別カードに変更 |

### パターンA（自前Stripeキー）関連

| 問題 | 原因 | 対処 |
|------|------|------|
| 「保存」後もカードリーダーが使えない | Secret Keyが無効 / Stripe Dashboardで失効 | Stripe Dashboardで新しいキーを発行して再保存 |
| Permission エラー | Stripe DashboardでTerminal機能が未有効 | Stripe Dashboard > Terminal を有効化 |
| 決済失敗 | Stripeアカウントの `charges_enabled=false` | 本人確認・事業者審査を完了 |

### パターンB（Stripe Connect）関連

| 問題 | 原因 | 対処 |
|------|------|------|
| 接続状態が「無効」のまま | オンボーディング未完了 | Stripeから届いたメールの指示に従って情報を追加入力 |
| `charges_enabled`がfalse | 審査中または書類不備 | Stripe Dashboardでメッセージを確認 |
| カードリーダー接続できない | Wi-Fi切断・ファームウェア古い | リーダー再起動・ファームウェア更新 |
| 決済完了するが注文が記録されない | Webhook失敗 | `subscription_events`テーブルと`audit_log`を確認 |
| 「Stripe Connectを解除」ボタンが表示されない | 未接続状態 | 接続完了後にのみ表示される |
