# 4. 決済設定（Stripe Connect）

Stripe Connectは、テナント（加盟店）がお客さんからカード決済を受け取り、POSLAが手数料を徴収する仕組みです。

## 4.1 Stripe Connectの仕組み

### 関係者

| 役割 | 説明 |
|------|------|
| プラットフォーム（POSLA） | Stripe Connectのプラットフォーム事業者 |
| Connected Account（テナント） | Stripeに接続された加盟店アカウント |
| お客さん | カード決済を行うエンドユーザー |

### 決済フロー

```
お客さん → カード決済 → Stripe → テナントの売上 − POSLAの手数料
```

---

## 4.2 手数料設定

POSLA管理画面の「API設定」タブで`connect_application_fee_percent`を設定します。

**デフォルト値：** 1.0%

**計算式：**
```
手数料 = ceil(決済金額 × 手数料率 / 100)
最低手数料 = ¥1
```

**計算例：**
| 決済金額 | 手数料率 | 手数料 | テナント受取額 |
|---------|---------|--------|------------|
| ¥1,000 | 1.0% | ¥10 | ¥990 |
| ¥3,000 | 1.0% | ¥30 | ¥2,970 |
| ¥10,000 | 1.0% | ¥100 | ¥9,900 |
| ¥50 | 1.0% | ¥1（最低額） | ¥49 |

---

## 4.3 テナントのConnect接続

### オンボーディングフロー

1. テナントのオーナーがオーナーダッシュボードの「決済設定」タブを開きます
2. 「Stripe接続を開始」ボタンをクリックします
3. POSLAがStripe Express Accountを作成します
   - アカウントタイプ：Express
   - 国：JP（日本）
   - 有効化する機能：card_payments, transfers
4. Stripeのオンボーディングページにリダイレクトされます
5. テナントがStripe上で以下の情報を入力します
   - ビジネス情報
   - 銀行口座情報
   - 本人確認書類
6. オンボーディング完了後、POSLAにリダイレクトされます
7. `connect_onboarding_complete`フラグが1に更新されます

### 接続状態の確認

POSLAはStripeのアカウント情報を取得して以下を確認します。

| フィールド | 説明 |
|-----------|------|
| charges_enabled | 決済の受付が有効か |
| payouts_enabled | 入金（振込）が有効か |
| details_submitted | 必要情報の入力が完了しているか |

---

## 4.4 Stripe Terminal（カードリーダー）

テナントのPOSレジでカードリーダー（物理端末）を使用する場合、Stripe Terminalを利用します。

### 接続トークン

レジアプリがカードリーダーに接続するために、接続トークンが必要です。

**エンドポイント：** `POST /api/connect/terminal-token.php`

Stripe Terminal SDKがこのトークンを使用してリーダーとの通信を確立します。

### 決済の流れ

1. レジアプリがPaymentIntentを作成
2. Stripe Terminalがカード情報を収集
3. 決済を処理
4. 手数料を自動計算してPOSLAの取り分を差し引き
5. POSLAに決済結果を記録

---

## 4.5 テナントデータベースのConnect関連フィールド

| フィールド | 型 | 説明 |
|-----------|-----|------|
| stripe_connect_account_id | VARCHAR(100) | StripeのConnectアカウントID（acct_xxx） |
| connect_onboarding_complete | TINYINT(1) | オンボーディング完了フラグ（0/1） |
