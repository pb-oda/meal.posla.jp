---
feature_id: stripe-full
title: Stripe決済の全設定（完全ガイド）
chapter: 21
plan: [all]
role: [owner, manager]
audience: beginner
keywords:
  - Stripe
  - ストライプ
  - 決済
  - クレジットカード
  - リーダー
  - S700
  - BBPOS
  - Stripe Reader
  - ターミナル
  - Connect
  - コネクト
  - サブスクリプション
  - 月額料金
  - 請求
  - Apple Pay
  - Google Pay
  - セルフレジ
  - ペアリング
related:
  - 02-login
  - 15-owner
  - 18-security
  - 22-smaregi-full
last_updated: 2026-04-09
maintainer: POSLA運営
---

# 21. Stripe決済の全設定（完全ガイド）

## 21.0 はじめにお読みください

このページは「Stripe（ストライプ）という決済サービスをPOSLAで使うための、すべての設定」を、**初めて触る人向けに1ステップずつ画面付きで解説**した完全ガイドです。

::: tip この章を読む順番（おすすめ）
1. まず **21.0.1「いくらかかるの？」** でお金の話を確認
2. **21.0.2「自分はどっちを選ぶ？」** で自分の状況を判定
3. 自分に該当する節（Aパターンなら21.3、Bパターンなら21.4）を順番にやる
4. レジでクレジットカードを使うなら **21.5「Stripe Reader S700」** を実施
5. うまくいかなかったら **21.7「30のよくある質問」** を確認
:::

### 21.0.1 いくらかかるの？

POSLAでStripeを使う場合、お金は2方向に流れます。

| 何の料金 | 誰が払う | 誰がもらう | だいたいの額 |
|---|---|---|---|
| **POSLA月額利用料** | あなた（テナント） | POSLA運営（プラスビリーフ） | 基本 ¥20,000/月 + 追加店舗 ¥17,000/店舗 + 本部配信アドオン ¥3,000/店舗（任意） |
| **Stripe決済手数料** | あなた（テナント） | Stripe | 決済額の約3.6% |
| **POSLA手数料**（Bパターンのみ） | あなた（テナント） | POSLA運営 | 決済額の1.0% |

::: tip POSLA月額の詳細
POSLA月額料金の詳細は **21.2** Stripe Billing の節を参照してください。30日間無料トライアルあり。
:::

::: warning 注意：Aパターン（自前Stripeキー）ならPOSLA手数料はかかりません
Stripeアカウントを既に持っている人は、POSLA手数料(1.0%)を払わずに済みます。詳細は21.0.2 で判定してください。
:::

### 21.0.2 自分はどっちを選ぶ？（パターンA / B 判定フロー）

```
質問1：あなたは既にStripeアカウントを持っていますか？
├─ はい → 質問2へ
└─ いいえ → 【パターンB（POSLA経由Connect）】を選ぶ → 21.4 へ進む

質問2：そのStripeアカウントは「本人確認・審査」を完了していますか？
（Stripe Dashboardで charges_enabled が「true」になっている）
├─ はい → 【パターンA（自前Stripeキー）】を選ぶ → 21.3 へ進む
└─ いいえ → まずStripe側で審査を完了させる、または【パターンB】を選ぶ
```

迷ったら **パターンB** で始めるのが安全です。POSLAが代行手続きをしてくれるので、後からパターンAに切り替えることもできます（21.4.10参照）。

### 21.0.3 必要なもの・所要時間

| 項目 | パターンA | パターンB |
|---|---|---|
| 事前に必要なもの | 自前のStripeアカウント | なし（POSLAが代行） |
| 設定作業の所要時間 | 10分 | 30分（オンボーディング入力含む） |
| 審査時間 | 不要 | 1〜3営業日 |
| カードリーダー追加時間 | 15分 | 15分 |

### 21.0.4 この章で扱う内容（章マップ）

- **21.1** 全体像（登場人物・お金の流れ・パターン比較）
- **21.2** Stripe Billing（POSLA月額料金の支払い）
- **21.3** パターンA：自前Stripeキー
- **21.4** パターンB：POSLA経由Connect
- **21.5** Stripe Reader S700（カードリーダー）の購入・接続・運用
- **21.6** セルフレジ（お客さんのスマホで決済）
- **21.7** よくある質問30件
- **21.8** トラブルシューティング表
- **21.9** データベース構造（POSLA運営向け）

---

## 21.1 全体像

### 21.1.1 登場人物

| 役割 | 説明 |
|------|------|
| **POSLA運営**（プラスビリーフ） | Stripeプラットフォーム事業者。Stripe Billing・Stripe Connectの親アカウントを持つ |
| **テナント**（飲食店） | POSLAに月額料金を支払い、Stripe Connect経由でお客さんから決済を受け取る |
| **お客さん** | テナントの飲食店で注文し、カード決済する |

### 21.1.2 お金の流れ図解

```
【Stripe Billing】POSLAの月額料金
テナント ──月額¥20,000〜──→ Stripe ──→ POSLA運営

【テナント決済：パターンA（自前Stripeキー）】
お客さん ─カード決済→ Stripe ─手数料3.6%─→ テナント受取
                                          （POSLA手数料なし）

【テナント決済：パターンB（POSLA経由Connect）】
お客さん ─カード決済→ Stripe ─手数料3.6% + POSLA手数料1.0%─→ テナント受取
```

### 21.1.3 パターンAとパターンBの比較表

| 項目 | パターンA：自前Stripeキー | パターンB：POSLA経由Connect |
|------|--------------------------|------------------------------|
| 事前準備 | Stripeアカウント開設・審査済 | 不要（POSLAが代行） |
| Secret Key管理 | テナントが `sk_live_xxx` をPOSLAに登録 | 登録不要（Connect Account ID のみ） |
| Stripe決済手数料 | 約3.6% | 約3.6% |
| POSLA手数料 | **なし** | **1.0%**（`application_fee`） |
| 入金先 | テナント自身のStripe残高 | テナント自身のStripe Connect残高 |
| 入金サイクル | Stripe Dashboardで設定 | 同左（通常7日後） |
| レジカードリーダー | ✅ 利用可能（S700） | ✅ 利用可能（S700） |
| セルフレジ | ✅ 利用可能 | ✅ 利用可能 |
| テイクアウト決済 | ✅ 利用可能 | ✅ 利用可能 |
| 切り替え | B解除後にA設定可 | A削除後にConnect接続可 |

::: tip 両方が設定されている場合
パターンA（自前Stripeキー）とパターンB（Connect）の両方が設定されているテナントは、**パターンB（Connect）が優先**されます。
:::

---

## 21.2 Stripe Billing（POSLA月額料金）

POSLA自体の月額料金の支払い設定です。お客さんとの決済（パターンA/B）とは別物です。

::: tip 2026-04-09 α-1 構成（重要）
2026-04-09 より、旧 standard / pro / enterprise の3プラン構成は廃止されました。POSLA は **単一プラン + アドオン** で提供されます。
:::

### 21.2.0 料金構造（α-1）

| 項目 | 月額 | 備考 |
|------|------|------|
| **基本料金（1店舗目）** | **¥20,000** | 全機能込み・必須 |
| **追加店舗（2店舗目以降）** | **¥17,000 / 店舗** | 1店舗あたり 15% 割引 |
| **本部一括メニュー配信アドオン**（任意） | **+¥3,000 / 店舗** | チェーン本部運用のみ |

#### 月額合計の例

| 規模 | 基本料金 | +本部配信 | 月額合計 |
|------|----------|----------|----------|
| 1店舗 | ¥20,000 | — | ¥20,000 |
| 3店舗 | ¥54,000 | (任意) | ¥54,000 〜 ¥63,000 |
| 5店舗 | ¥88,000 | +¥15,000 | ¥88,000 〜 ¥103,000 |
| 10店舗 | ¥173,000 | +¥30,000 | ¥173,000 〜 ¥203,000 |

#### 30日間無料トライアル

- 申込み時に Stripe にカード情報を登録します（**カード登録必須**）
- 登録から30日間は完全無料（機能制限なし）
- 31日目から自動で月額課金が開始されます
- 期間中に解約すれば **一切課金されません**

### 21.2.1 申込み手順（テナント側）

::: tip この節の前提
現状のスコープでは、**既にテナントアカウントが作成済み** で owner ユーザーがログインできる状態から申込みを開始します。未認証ユーザー向けのランディングページ・テナント自動作成フローは Stage 2 で実装予定です。
:::

#### 21.2.1.1 申込み前のチェックリスト

申込みボタンを押す前に、以下5項目を必ず確認してください。

| # | 確認項目 | 確認方法 | NG時の対応 |
|---|---------|---------|-----------|
| 1 | あなたのアカウントが **owner ロール** である | POSLAログイン後、画面右上のユーザー名をクリック → 「ロール」が `owner` と表示される | manager / staff では申込みできません。owner に依頼してください |
| 2 | テナントに **店舗が1つ以上登録されている** | owner-dashboard >「店舗管理」タブで店舗一覧を確認 | 店舗が0なら 21.2.1.4「実例：申込み前の店舗ゼロエラー」を参照して先に店舗追加 |
| 3 | **既存の有効サブスクリプションがない** | 「契約・プラン」タブ上部に「現在: 未契約」と表示されている | `trialing` / `active` の場合は申込み不可（`ALREADY_SUBSCRIPTION` エラー）。21.2.2 の構成変更へ |
| 4 | **使えるクレジットカード** が手元にある | VISA / Master / JCB / AMEX のいずれか。3Dセキュア対応推奨 | デビットカード・プリペイドカードは弾かれる可能性あり |
| 5 | **メールアドレス** が使える状態 | 申込み確認メール・請求書 PDF が届くアドレスを準備 | テナント会社の経理が読めるアドレス推奨 |

::: tip 確認1のチェック方法（補足）
画面右上のユーザー名をクリックして表示されないバージョンの場合は、ブラウザの開発者ツールで `JSON.parse(localStorage.getItem('mt_user') || '{}').role` を実行すると確認できます。返り値が `"owner"` なら OK。
:::

#### 21.2.1.2 操作手順（画面遷移付き）

##### ステップ1：契約・プランタブを開く

1. owner アカウントで `https://eat.posla.jp/public/admin/index.html` にログイン
2. ログイン後、自動で `owner-dashboard.html` にリダイレクトされます
3. 左メニュー or 上部タブから **「契約・プラン」** をクリック

**この時点での画面表示**

```
┌──────────────────────────────────────────┐
│  契約・プラン                             │
│                                          │
│  現在: 未契約                             │
│                                          │
│  店舗数: 3 店舗（自動取得）                │
│                                          │
│  料金内訳:                                │
│  ┌──────────────┬─────┬────┬────────┐  │
│  │ 基本料金      │¥20k │ ×1 │¥20,000 │  │
│  │ 追加店舗      │¥17k │ ×2 │¥34,000 │  │
│  └──────────────┴─────┴────┴────────┘  │
│                                          │
│  ☐ 本部一括配信アドオンを追加する          │
│     （チェーン本部運用向け）               │
│                                          │
│  月額合計: ¥54,000                        │
│  ※ 30日間無料トライアル                    │
│                                          │
│  [ 30日間無料で始める ]                   │
└──────────────────────────────────────────┘
```

##### ステップ2：本部一括配信アドオンの選択（任意）

チェーン本部運用が必要な場合のみチェック。判断に迷ったら **チェックなしで進める** ことを推奨します（後から Customer Portal で追加可能）。

- **チェックを ON にする条件**:
  - 複数店舗を運営しており、本部側で全店共通のメニュー（写真・カテゴリ・価格・カロリー・アレルゲン）を一元管理したい
  - 各店舗側ではメニュー編集を禁止し、本部マスタからの一括配信のみ許可したい
- **チェックなしで進める場合**:
  - 個人店・小規模チェーン（〜3店舗）
  - 各店舗で独自にメニューを編集したい
  - とりあえずトライアルだけしたい

チェックを ON にすると料金内訳に「本部一括配信 ×3 = ¥9,000」が即座に追加され、月額合計が `¥54,000 → ¥63,000` に再計算されます。

##### ステップ3：「30日間無料で始める」ボタンをクリック

1. **「30日間無料で始める」** ボタンをクリック
2. ボタンが「処理中...」に変わります（1〜3秒）
3. ブラウザが自動で Stripe Checkout 画面にリダイレクトされます
4. URL が `https://checkout.stripe.com/c/pay/cs_live_xxxxx...` に変わっていることを確認

::: warning このタイミングで起こりうるエラー
- `PRICE_NOT_CONFIGURED` (500): POSLA運営側の Stripe Price ID 設定漏れ。POSLA運営に連絡してください
- `ALREADY_SUBSCRIBED` (400): 既存の有効サブスクリプションあり。21.2.1.1 確認3 を見直してください
- `NO_STORES` (400): 店舗が0件。先に店舗を追加してください
- 詳細は 21.2.7 トラブルシューティング を参照
:::

##### ステップ4：Stripe Checkout でカード情報入力

Stripe Checkout 画面の入力フィールド一覧と詳細：

| # | フィールド | 入力例 | 文字種・形式 | 補足 |
|---|----------|-------|------------|------|
| 1 | メールアドレス | `keiri@momonoya.jp` | RFC5322 形式 | 領収書・請求書送付先。後から Customer Portal で変更可能 |
| 2 | カード番号 | `4242 4242 4242 4242` | 13〜19桁の数字（自動でスペース挿入） | VISA / Master / JCB / AMEX 対応。デビット・プリペイドは弾かれる可能性 |
| 3 | 有効期限 | `12 / 28` | MM / YY 形式（4桁） | 過去日付は弾かれる |
| 4 | セキュリティコード（CVC） | `123` | 3桁（VISA/MC/JCB）または 4桁（AMEX） | カード裏面（AMEXは表面） |
| 5 | カード名義 | `TARO MOMONOYA` | 半角英大文字、姓名スペース区切り | カードに記載されている通り |
| 6 | 国 / 地域 | `日本` | プルダウン選択 | 自動で「日本」が選択されている |

::: tip 注文サマリー（左側）の確認
画面左側に「30日間の無料体験」のサマリーが表示されます。以下を確認してください。
- POSLA 基本料金 × 1 = ¥20,000
- POSLA 追加店舗 × 2 = ¥34,000（3店舗の場合）
- POSLA 本部一括配信 × 3 = ¥9,000（アドオン契約時のみ）
- 合計（30日後より）: ¥54,000 〜 ¥63,000 / 月
- 本日の請求金額: **¥0**
:::

##### ステップ5：「トライアルを開始」ボタンをクリック

1. 全項目を入力したら **「トライアルを開始」** ボタンをクリック
2. カード認証処理（3Dセキュア）が走る場合があります
   - 「カード会社のページで本人認証してください」と表示された場合は、表示されるパスワード or SMS コードを入力
3. 認証成功後、画面に「お支払い情報を保存しました」と表示されます（1〜2秒）
4. 自動的に POSLA に戻ります（リダイレクト先: `https://eat.posla.jp/public/admin/owner-dashboard.html?subscription=success`）

##### ステップ6：申込み完了の確認

POSLA に戻った後、契約・プランタブで以下を確認してください。

```
┌──────────────────────────────────────────┐
│  契約・プラン                             │
│                                          │
│  状態: ● トライアル中  (残り 30 日)       │
│  トライアル終了日: 2026-05-09             │
│                                          │
│  店舗数: 3 店舗                           │
│  本部一括配信アドオン: なし                │
│                                          │
│  料金内訳:                                │
│  ┌──────────────┬─────┬────┬────────┐  │
│  │ 基本料金      │¥20k │ ×1 │¥20,000 │  │
│  │ 追加店舗      │¥17k │ ×2 │¥34,000 │  │
│  └──────────────┴─────┴────┴────────┘  │
│                                          │
│  月額合計: ¥54,000 (2026-05-09 より)      │
│                                          │
│  [ サブスクリプション管理 (Stripe) ]       │
└──────────────────────────────────────────┘
```

::: tip 表示が更新されない場合
ブラウザの再読込（Cmd+R / Ctrl+R）を押してください。Stripe Webhook が POSLA に届くまで通常 1〜3 秒かかりますが、ブラウザはリダイレクト直後の状態をキャッシュしている可能性があります。再読込しても「未契約」のままなら 21.2.7 トラブルシューティングを参照してください。
:::

#### 21.2.1.3 入力フィールドの詳細とエラーメッセージ

Stripe Checkout 画面で入力ミスした場合の代表的なエラーメッセージと対処法：

| エラーメッセージ | 原因 | 対処 |
|----------------|------|------|
| `Your card number is incomplete.` | カード番号が桁数不足 | 全桁入力したか確認（VISA/MC/JCB は16桁、AMEX は15桁） |
| `Your card's expiration date is incomplete.` | 有効期限の MM / YY が不完全 | 4桁すべて入力 |
| `Your card's expiration year is in the past.` | 有効期限が過去 | 別のカードを使用 |
| `Your card was declined.` | カード会社が決済拒否 | カード会社に連絡 or 別のカードを使用 |
| `Your card does not support this type of purchase.` | デビット/プリペイドでサブスク不可 | クレジットカードを使用 |
| `Authentication failed.` | 3Dセキュア認証に失敗 | カード会社のパスワードを再設定して再試行 |

#### 21.2.1.4 申込み完了後の Stripe Dashboard 確認方法（POSLA運営向け）

申込みが正常に完了したかを Stripe Dashboard 側からも確認できます（POSLA運営アカウントでログイン）。

1. [Stripe Dashboard](https://dashboard.stripe.com/) > **顧客** メニュー
2. 検索窓に `cus_` で始まる Customer ID または申込み時のメールアドレスを入力
3. ヒットした顧客をクリック
4. 「サブスクリプション」セクションで以下を確認：
   - **ステータス**: `Trialing`（緑のバッジ）
   - **試用期間**: 30日（`Trial ends on 2026-05-09` のような表示）
   - **次回請求**: トライアル終了日と一致
   - **現在の line items**: POSLA 基本料金 × 1、POSLA 追加店舗 × N、（任意）POSLA 本部一括配信アドオン × N

5. 「請求書」セクションで「Trial」と書かれた `¥0` の請求書が1件発行されていることを確認

::: tip POSLA DB 側の確認
POSLA 運営は以下の SQL でも確認できます。

```sql
SELECT id, slug, subscription_status, current_period_end, hq_menu_broadcast,
       stripe_customer_id, stripe_subscription_id
FROM tenants
WHERE id = 't-momonoya-001';
```

期待値：
- `subscription_status` = `trialing`
- `current_period_end` = トライアル終了日（30日後）
- `stripe_customer_id` = `cus_xxxxx`
- `stripe_subscription_id` = `sub_xxxxx`
:::

#### 21.2.1.5 実例：桃乃屋テナント（2026-04-09 申込み）

実際に 2026-04-09 に申込みを行った桃乃屋（`t-momonoya-001`）の事例：

| 項目 | 値 |
|------|-----|
| テナント ID | `t-momonoya-001` |
| owner アカウント | `momonoya-owner` |
| 店舗数 | 3店舗（s-momo-ikebukuro-001 / s-momo-shibuya-001 / s-momo-shinjuku-001） |
| 本部配信アドオン | なし |
| 申込み日時 | 2026-04-09 16:50 (JST) |
| トライアル終了日 | 2026-05-09 16:50 (JST) |
| 料金内訳 | ¥20,000 + ¥17,000 × 2 = ¥54,000/月 |
| Stripe Customer ID | `cus_xxxxx`（本番環境のため伏字） |
| Stripe Subscription ID | `sub_1TKDLwIYcOWtrFXdpc3cJO4w` |
| 初回課金日 | 2026-05-09（トライアル終了直後） |
| 初回請求金額 | ¥54,000 |

申込み後の流れ：
1. 16:50 に POSLA で「30日間無料で始める」をクリック
2. 16:51 に Stripe Checkout でカード情報入力 → 完了
3. 16:51 に POSLA Webhook で `tenants.subscription_status = 'trialing'` に更新
4. （P1-35 hotfix デプロイ後の 17:30 に手動 backfill で `current_period_end = 2026-05-09 16:50:14` を補完）
5. 翌月 2026-05-09 16:50 に Stripe が `invoice.paid` を発行 → ¥54,000 課金開始予定

::: tip 店舗数の自動反映
店舗数は申込み時に手動入力しません。`stores` テーブルの有効な店舗数（`is_active = 1`）から自動で算出され、申込み後に店舗を追加・削除しても Stripe Subscription の quantity が自動同期されます（→ 21.2.2 参照）。
:::

::: warning カードが弾かれた場合
- カード会社で利用制限がかかっていることがあります（特に新しいカード）
- 別のカードを試す or カード会社に連絡してください
- それでもダメなら 21.2.7 トラブルシューティング Q「カード決済が失敗する」を参照
:::

::: warning 既にサブスクリプションがある場合
既存の有効な subscription（`trialing` / `active` / `past_due`）があるテナントが再度 **「30日間無料で始める」** を押すと、サーバーは `ALREADY_SUBSCRIBED` エラーを返して申込みを拒否します。構成変更は Customer Portal から行ってください（→ 21.2.2）。
:::

### 21.2.2 構成変更手順

α-1 構成では「プラン変更」は存在しません。代わりに以下の3種類の構成変更が可能です。

::: tip 構成変更の基本
店舗追加・削除はすべて **POSLA 側の操作だけ** で完結します。Stripe Customer Portal を開く必要はありません（自動同期）。本部一括配信アドオンの追加・解除のみ Stripe Customer Portal を使用します。
:::

#### 21.2.2.1 店舗追加（自動同期）

##### 操作前のチェックリスト

| # | 確認項目 | 確認方法 |
|---|---------|---------|
| 1 | あなたが owner ロールである | 21.2.1.1 の確認1 と同じ |
| 2 | サブスクリプション状態が `trialing` または `active` | 「契約・プラン」タブ > 状態 を確認 |
| 3 | 追加する店舗のスラッグが既存と重複しない | 「店舗管理」タブで一覧確認 |

##### 操作手順（クリック単位）

1. owner-dashboard > **「店舗管理」** タブをクリック
2. 画面右上の **「+ 店舗を追加」** ボタンをクリック
3. モーダルダイアログが開きます。以下を入力：

   | フィールド | 入力例 | 必須 | 文字種・形式 |
   |----------|-------|------|------------|
   | 店舗名 | `桃乃屋 渋谷店` | ✅ | 全角OK、最大50文字 |
   | スラッグ | `momo-shibuya-002` | ✅ | 半角英数字とハイフンのみ。テナント内で一意 |
   | 住所 | `東京都渋谷区...` | 任意 | |
   | 電話 | `03-xxxx-xxxx` | 任意 | |

4. **「追加」** ボタンをクリック
5. ボタンが「処理中...」に変わります（1〜3秒）
6. ダイアログが閉じ、店舗一覧に新規店舗が追加されます
7. 「契約・プラン」タブに移動して料金内訳を確認すると、追加店舗 quantity が +1 されています

##### サーバー側で自動実行される処理

```
[POSLA Server]
1. INSERT INTO stores (...) VALUES (...)
2. INSERT INTO store_settings (...) VALUES (...)
3. _stores_sync_subscription() フック発火
   ↓
   sync_alpha1_subscription_quantity():
   - tenants.subscription_status を確認 (trialing/active のみ実行)
   - stores テーブルから有効店舗数を COUNT
   - 現在の Stripe subscription を取得
   - 差分計算: 追加店舗 quantity を desired と比較
   - 変更ありなら POST /v1/subscriptions/{sub_id} で
     items[N][id] + items[N][quantity] を更新
     (proration_behavior=create_prorations 付き)
4. Stripe → POSLA: customer.subscription.updated Webhook
5. webhook.php → extract_alpha1_state_from_subscription()
   → DB を最新状態で再同期
```

##### 課金への影響（実例：松の屋 3店舗 → 4店舗）

2026-04-09 に松の屋（`t-matsunoya-001`、Stripe sub_1TISlHIYcOWtrFXdWD7iTicn）で実際に行われた E2E テストの数値：

| タイミング | 状態 | 月額 | Stripe 側の動き |
|----------|------|------|----------------|
| **追加前** | 3店舗 (¥20,000 + ¥17,000 × 2) | ¥54,000 | line items 維持 |
| **追加後（即時）** | 4店舗 (¥20,000 + ¥17,000 × 3) | ¥71,000 | additional_store quantity 2 → 3、proration invoice item ¥X 作成 |
| **次回請求日 (2026-05-04)** | 4店舗の月額本体 + proration 累計 | ¥71,000 + 約 ¥11,530 = ¥82,530 | 通常請求 + proration を1枚の請求書で発行 |

**proration（日割り）の計算式**：

```
proration金額 = (追加店舗単価 / 当月日数) × 残日数
            = (¥17,000 / 30) × 残日数
            = 約 ¥567 / 日
```

例：4月9日に1店舗追加 → 次回請求日が5月4日 → 残25日 → 約 ¥567 × 25 = **¥14,175**

実例の松の屋は元の課金期間が違うため正確な内訳は Stripe Dashboard の請求書プレビューで確認可能。

::: tip 同期失敗時の挙動（フェイルセーフ）
Stripe API の呼び出しに失敗しても店舗追加自体は成功します。`_stores_sync_subscription()` は try-catch で例外を握り潰し、`error_log` にのみ記録します。これは「Stripe 接続不調で店舗追加が止まる」事態を避けるための設計判断です。

同期失敗時の復旧方法：
- POSLA 運営に連絡してください
- 運営側で `sync_alpha1_subscription_quantity($pdo, 'テナントID')` を手動実行して再同期できます
:::

#### 21.2.2.2 店舗削除（自動同期）

##### 操作前のチェックリスト

| # | 確認項目 |
|---|---------|
| 1 | 削除する店舗の過去の注文・売上レポートが**閲覧できなくなる**ことを理解している（論理削除なので残るが、UI からアクセス不可に） |
| 2 | 当該店舗にログイン中のスタッフがいない（オフィス時間外推奨） |
| 3 | 当該店舗で進行中の注文（KDS / レジ）がない |

##### 操作手順

1. owner-dashboard > **「店舗管理」** タブ
2. 削除したい店舗の右側にある **「削除」** ボタン（赤文字）をクリック
3. 確認ダイアログ「店舗 `xxxx` を削除しますか？この操作は取り消せません」で **「OK」**
4. 1〜3秒待つ
5. 店舗一覧から該当店舗が消えます
6. 「契約・プラン」タブで料金内訳を確認すると、追加店舗 quantity が -1 されています

##### サーバー側で自動実行される処理

```
[POSLA Server]
1. UPDATE stores SET is_active = 0 WHERE id = ?
2. _stores_sync_subscription() フック発火
3. sync_alpha1_subscription_quantity()
   → additional_store quantity -1
   → proration_behavior=create_prorations で
     クレジット（マイナスの invoice item）を作成
4. Webhook で DB 同期
```

##### 課金への影響（実例：松の屋 4店舗 → 3店舗）

E2E テストで追加した「P1-36 E2E テスト店」を削除した直後の数値：

| タイミング | 状態 | 月額 | Stripe 側の動き |
|----------|------|------|----------------|
| **削除前** | 4店舗 | ¥71,000 | additional_store × 3 |
| **削除後（即時）** | 3店舗 | ¥54,000 | additional_store × 2、proration credit `-¥X` 作成 |
| **次回請求日** | 3店舗の月額本体 - クレジット累計 | ¥54,000 - 約 ¥11,500 ≒ ¥42,500（参考値） | 通常請求からクレジットが差引 |

::: tip 論理削除の理由
店舗を物理削除すると過去の注文履歴・売上レポート・スタッフ評価データが孤立して参照不能になるため、POSLA では `is_active = 0` の論理削除として扱います。誤って削除した場合は POSLA 運営に連絡してください（DB 上で `is_active = 1` に戻すだけで復活可能）。

ただし論理削除でも Stripe 側の quantity は減算されるので、復活時には別途店舗追加扱いで再同期されます。
:::

#### 21.2.2.3 本部一括メニュー配信アドオンの追加・解除

::: warning 現状は Stripe Customer Portal から変更
店舗追加・削除と異なり、本部配信アドオンの追加・解除専用 UI はまだ実装されていません（Stage 2 以降で実装予定）。現状は以下の手順で Stripe Customer Portal から直接変更してください。
:::

##### 追加手順（チェーン本部運用を始める）

1. owner-dashboard > **「契約・プラン」** タブ
2. **「サブスクリプション管理 (Stripe)」** ボタンをクリック（青いボタン）
3. 新しいタブで Stripe Customer Portal が開きます
4. 「サブスクリプション」セクションの現在のプラン行で **「サブスクリプションを更新」** をクリック
5. 「プランを追加」または「商品を追加」ボタン
6. 商品リストから **「POSLA 本部一括配信アドオン」** を選択
7. 数量を **店舗数と同じ値** に設定（3店舗なら 3）
8. 画面下部の **「更新」** ボタンをクリック
9. 「サブスクリプションを更新しました」と表示されたら完了
10. POSLA に戻り（タブを閉じる）、owner-dashboard を **再読込** します
11. 左メニューに **「本部メニュー」** タブが出現していることを確認

##### 解除手順（チェーン本部運用を止める）

1. 上記 1〜4 と同じ手順で Stripe Customer Portal に入る
2. 現在の line items 一覧から「POSLA 本部一括配信アドオン」の右側 **「削除」** をクリック
3. **「更新」** をクリック
4. POSLA に戻って再読込
5. 「本部メニュー」タブが消えていることを確認

##### サーバー側の自動同期処理

```
[Stripe → POSLA]
1. Stripe Customer Portal で line item 追加/削除
2. Stripe → customer.subscription.updated Webhook 発火
3. webhook.php
   → extract_alpha1_state_from_subscription()
   → items 配列に stripe_price_hq_broadcast が含まれるか判定
   → tenants.hq_menu_broadcast = 0 / 1 に更新
4. owner-dashboard の applyPlanFeatureRestrictions() が
   次回読込時に「本部メニュー」タブの表示/非表示を切替
```

##### 課金への影響

| 操作 | quantity | 月額影響（3店舗の場合） | proration |
|------|---------|---------------------|----------|
| アドオン追加 | 0 → 3 | +¥9,000 | ¥9,000 × 残日数 / 30 で日割り即時請求 |
| アドオン解除 | 3 → 0 | -¥9,000 | ¥9,000 × 残日数 / 30 で日割りクレジット |

::: tip Stripe Customer Portal を経由する理由
店舗数の同期と違って「本部配信アドオンの ON/OFF」は経営判断の伴う変更（チェーン全体の運用方針が変わる）であり、誤操作を避けるため POSLA 側の1クリックではなく Stripe Customer Portal 経由としています。Stage 2 以降で owner-dashboard 内に専用 UI を追加予定です。
:::

### 21.2.3 解約手順

::: warning 現状は Stripe Customer Portal から解約
POSLA 画面上の専用「解約」ボタンはまだ実装されていません（Stage 2 以降で実装予定）。現状は Stripe Customer Portal から解約手続きを行います。
:::

#### 21.2.3.1 解約前のチェックリスト

| # | 確認項目 | 補足 |
|---|---------|------|
| 1 | 全店舗のスタッフに**解約予定日を共有済み** | 解約後はログインできなくなる |
| 2 | 過去の売上レポート・請求書を**ローカル保存済み** | 解約後はダウンロードできなくなる |
| 3 | 顧客データ（テーブル予約・LINE会員等）の**移行先**を決めている | 必要なら CSV エクスポートを先に実施 |
| 4 | 進行中の Stripe 決済（売掛・予約決済）が**ない** | 解約直前の入金は通常通り処理される |
| 5 | （チェーン店の場合）本部側の運用切替が完了している | |

#### 21.2.3.2 解約の操作手順（クリック単位）

##### ステップ1：Customer Portal を開く

1. owner アカウントで POSLA にログイン
2. owner-dashboard > **「契約・プラン」** タブを開く
3. 画面下部の **「サブスクリプション管理 (Stripe)」** ボタン（青）をクリック
4. 自動で別タブ or 新しいウィンドウで Stripe Customer Portal が開く
5. URL が `https://billing.stripe.com/p/session/xxxxx` になっていることを確認

##### ステップ2：解約ボタンを探す

1. Customer Portal のトップ画面で「サブスクリプション」セクションを確認
2. 現在のプラン名（例：「POSLA 基本料金」）の右側 **「サブスクリプションを更新」** リンクをクリック
3. プラン詳細画面が開く
4. 画面下部の **「プランをキャンセル」** または **「サブスクリプションをキャンセル」** リンク（小さい灰色文字）をクリック

##### ステップ3：キャンセル理由を選択（任意）

1. 「キャンセル理由を教えてください」とアンケート画面が出ます
2. 該当する理由をプルダウンから選択（任意項目、スキップ可能）
   - 「価格が高い」「機能不足」「他社への乗り換え」「事業終了」など
3. 自由記述欄にコメントを書く（任意）
4. **「サブスクリプションをキャンセル」** ボタンをクリック

##### ステップ4：解約確定

1. 「サブスクリプションをキャンセルしました」と表示される
2. Stripe 側で `cancel_at_period_end = true` が設定されます
3. 「サブスクリプションは 2026-05-09 にキャンセルされます」と表示されます（期間終了日）
4. **その日まではすべての機能が引き続き使えます**
5. Customer Portal を閉じる（タブを閉じる）

##### ステップ5：POSLA 側の状態確認

1. owner-dashboard に戻る
2. 「契約・プラン」タブを再読込
3. 状態が **「キャンセル予定: 2026-05-09」** に変わっていることを確認
4. 期間終了日まで引き続き利用可能

##### 期間終了時の自動処理

```
[Stripe → POSLA]
2026-05-09 (期間終了日 23:59:59)
↓
1. Stripe が customer.subscription.deleted Webhook を送信
2. POSLA webhook.php がイベント受信
3. tenants.subscription_status = 'canceled' に更新
4. tenants.hq_menu_broadcast はリセットしない (再契約時に復元するため)
5. オーナーは引き続きログイン可能だが、機能制限モードに移行
   → 注文受付・KDS・レジは停止
   → レポート閲覧・データエクスポートは可能（30日間）
```

#### 21.2.3.3 解約予約のキャンセル（解約取り消し）

期間終了日までの間に「やっぱり解約しない」と決めたら、解約予約を取り消せます。

1. owner-dashboard > 契約・プラン
2. **「サブスクリプション管理 (Stripe)」** ボタン
3. Customer Portal で「サブスクリプションは 2026-05-09 にキャンセルされます」と表示されている下に **「キャンセルを取り消す」** リンクをクリック
4. 「サブスクリプションを更新しました」と表示
5. POSLA 側の状態が `active` に戻ります（期間終了日のリセットなし）

#### 21.2.3.4 トライアル期間中の解約

30日無料トライアル期間中（`subscription_status = 'trialing'`）の解約は、通常の解約と同じ手順ですが**一切課金されません**。

| 操作タイミング | 結果 |
|--------------|------|
| トライアル開始日〜29日目に解約 | トライアル終了日（30日後）まで使える、その後 canceled。**¥0 課金** |
| トライアル30日目（最終日）に解約 | 即座に canceled、**¥0 課金** |
| トライアル31日目（自動課金後）に解約 | その月の課金は発生済み。次回課金日まで使える、その後 canceled |

::: tip カード情報の取り扱い
解約後、Stripe 側にカード情報は引き続き保存されます（再契約時に再入力不要）。完全削除したい場合は Customer Portal の「お支払い方法」セクションから手動で削除してください。
:::

#### 21.2.3.5 解約後の再契約

解約後（`canceled` 状態）に再契約する場合：

1. owner-dashboard > 契約・プラン
2. **「30日間無料で始める」** ボタンが再度表示される
3. ボタンをクリック → 新規申込みと同じフロー
4. **トライアル期間は付与されません**（初回でないため）
5. 即座に課金開始
6. 再契約時には前回の `hq_menu_broadcast` 設定が復元されます（DB 上にフラグが残置されているため）

::: warning トライアルは1テナント1回のみ
30日無料トライアルは「このテナントが今までに一度もサブスクリプションを持ったことがない場合」のみ付与されます。`subscription_status = 'none'` または `stripe_subscription_id` が空のときが該当。一度でもトライアル契約を経験したテナントは、再契約時にトライアルなしで即時課金されます。
:::

### 21.2.4 支払い状況の確認

#### 21.2.4.1 owner-dashboard 「契約・プラン」タブで確認できる項目

| 項目 | 内容 | 例 |
|------|------|-----|
| **契約状態** | none（未契約） / trialing（トライアル中） / active（有効） / past_due（延滞） / canceled（解約済み） | `● トライアル中 (残り 24 日)` |
| **店舗数** | 現在契約中の店舗数（基本料金に含まれる1店舗 + 追加店舗） | `3 店舗` |
| **本部一括配信アドオン** | あり / なし | `なし` |
| **料金内訳** | 各 line item の単価 × 数量 × 小計 | （表形式で表示） |
| **月額合計** | 上記の合計金額 | `¥54,000 / 月` |
| **次回請求日** | YYYY-MM-DD（トライアル中はトライアル終了日） | `2026-05-09` |
| **前回の支払い** | 成功 / 失敗（直近の `invoice.paid` / `invoice.payment_failed` イベント） | `成功 (2026-04-09)` |

#### 21.2.4.2 過去の請求書をダウンロードする

過去の請求書 PDF は POSLA 側ではなく Stripe Customer Portal からダウンロードします。

1. owner-dashboard > 契約・プラン > **「サブスクリプション管理 (Stripe)」** ボタン
2. Customer Portal 画面の **「請求履歴」** または **「インボイス」** セクション
3. 各請求書の右側 **「PDF」** または **「ダウンロード」** をクリック
4. ローカルに PDF が保存される

PDF の中身：
- 請求書番号（`POSLA-2026-04-XXXXX` 形式）
- 請求日
- 課金期間
- line items 一覧（base / additional_store / hq_broadcast）
- 小計・税額・合計
- proration（日割り）の明細
- POSLA運営の社名・住所・登録番号

::: tip 経理担当者向け
請求書 PDF はメールでも自動送信されます。送信先メールアドレスは Customer Portal の「アカウント情報」で変更可能（複数アドレス指定可）。
:::

#### 21.2.4.3 サーバー側 DB から状態を直接確認する（POSLA運営向け）

POSLA 運営は以下の SQL で全テナントのサブスクリプション状態を一覧できます。

```sql
SELECT
  id,
  slug,
  subscription_status,
  current_period_end,
  hq_menu_broadcast,
  stripe_customer_id,
  stripe_subscription_id,
  (SELECT COUNT(*) FROM stores s WHERE s.tenant_id = t.id AND s.is_active = 1) AS active_stores
FROM tenants t
WHERE subscription_status IS NOT NULL
ORDER BY current_period_end ASC;
```

異常テナント検出 SQL：

```sql
-- subscription_status と Stripe ID の不整合
SELECT id, slug, subscription_status, stripe_subscription_id
FROM tenants
WHERE (subscription_status IN ('trialing','active','past_due') AND stripe_subscription_id IS NULL)
   OR (subscription_status = 'none' AND stripe_subscription_id IS NOT NULL);

-- current_period_end が NULL の active/trialing
SELECT id, slug, subscription_status, current_period_end
FROM tenants
WHERE subscription_status IN ('trialing','active') AND current_period_end IS NULL;
```

::: warning current_period_end が NULL になる既知バグ
2026-04-09 の P1-35 hotfix までは Stripe billing_mode=flexible 環境で `current_period_end` が NULL のまま残るバグがありました。修正後は `extract_alpha1_state_from_subscription()` が items[0].current_period_end からフォールバック読み取りします。詳細は 21.2.7.3 を参照。
:::

### 21.2.5 支払い失敗時の自動再試行

#### 21.2.5.1 Stripe の自動再試行スケジュール

| 経過 | 状態変化 | テナント側の挙動 | POSLA側の挙動 |
|------|---------|----------------|--------------|
| 課金日 | `invoice.payment_failed` | 警告メール受信 | DB に subscription_events 記録 |
| **1日後** | Stripe が1回目の自動再試行 | （メールなし） | （成功なら active 維持） |
| **3日後** | Stripe が2回目の自動再試行 | 警告メール再送 | |
| **5日後** | Stripe が3回目の自動再試行 | 警告メール再送 | |
| **7日後** | 最終再試行で失敗 | 状態が `past_due` に | 画面上部に **赤い警告バナー**「お支払いが失敗しました。決済情報を更新してください」 |
| **14日後** | Stripe がサブスクリプションを停止 | 状態が `canceled` に | 機能制限モードに移行 |

::: tip Stripe 側のリトライ設定
上記スケジュールは Stripe Dashboard > 設定 > 課金 > **「サブスクリプションと請求書」** で運営側がカスタマイズ可能です。POSLA のデフォルトはこの 7 日 / 14 日設定です。
:::

#### 21.2.5.2 支払い失敗時の対処手順（テナント側）

##### ステップ1：警告メールを開く

Stripe から `Your POSLA payment failed` 件名のメールが届きます。本文に以下が記載されています：

- 失敗した請求書の請求書番号
- 失敗理由（カード会社の応答コード）
- カード情報を更新するためのリンク

##### ステップ2：カード情報を更新

メール内のリンクから直接 Customer Portal に遷移、または：

1. owner-dashboard > 契約・プラン > **「サブスクリプション管理 (Stripe)」**
2. Customer Portal で **「お支払い方法」** セクション
3. **「お支払い方法を追加」** で新しいカードを登録
4. 既存カードを **「削除」**
5. （オプション）失敗した請求書を **「今すぐ支払う」** で手動再試行

##### ステップ3：状態確認

1. 1〜2分後、POSLA に戻って契約・プランタブを再読込
2. 警告バナーが消え、状態が `active` に戻っていることを確認

#### 21.2.5.3 past_due 状態でも使える機能・止まる機能

`past_due` は「警告中だがまだ機能停止していない」中間状態です。

| 機能 | past_due | canceled |
|------|---------|---------|
| ログイン | ✅ | ✅ |
| 注文受付・KDS・レジ | ✅ | ❌ |
| メニュー編集 | ✅ | ❌ |
| レポート閲覧 | ✅ | ✅（解約後30日間） |
| データエクスポート（CSV） | ✅ | ✅（解約後30日間） |
| お客様セルフメニュー | ✅ | ❌ |
| 新規予約受付 | ✅ | ❌ |

::: warning past_due の上部バナー
画面上部に固定表示される赤バナーは閉じることができません。決済情報を更新するまで表示され続けます。スタッフ向けの「カスタマー向け表示」モードでは非表示にできます。
:::

### 21.2.6 POSLA運営側の初期設定（運営者向け）

::: tip 通常テナントは21.2.6 を読む必要はありません
ここはPOSLA運営（プラスビリーフ）が初回設定時に1度だけ行う作業です。
:::

**ステップ1：Stripe Dashboardで商品を作成**

1. [Stripe Dashboard](https://dashboard.stripe.com/) にPOSLA運営アカウントでログイン
2. 左メニュー **「商品カタログ」** → **「商品を追加」**
3. 以下の3商品を作成（α-1 構成）：

| 商品名 | 価格 | 課金タイプ | quantity |
|---|---|---|---|
| POSLA 基本料金 | ¥20,000 | 月額（毎月） | 1（固定） |
| POSLA 追加店舗 | ¥17,000 | 月額（毎月） | 店舗数 - 1 |
| POSLA 本部一括配信アドオン | ¥3,000 | 月額（毎月） | 店舗数（アドオン契約時のみ） |

4. 各商品の **価格ID（`price_xxxx` 形式）** を控えます
5. **トライアル期間の設定**: Stripe 側の Product 設定で「無料試用期間」を設定する必要は**ありません**。POSLA 側の `api/subscription/checkout.php` が初回申込（`subscription_status='none'` or `stripe_subscription_id` 空）のテナントに対してのみ、Stripe Checkout 生成時に `subscription_data[trial_period_days] => 30` を動的に付与します。2回目以降（既に解約済みのテナントが再契約する場合など）はトライアル無しで即時課金が開始されます

**ステップ2：APIキー取得**

1. Stripe Dashboard > **開発者** > **APIキー**
2. **シークレットキー** （`sk_live_xxx`）をコピー
3. **公開可能キー** （`pk_live_xxx`）をコピー

**ステップ3：Webhookエンドポイント登録**

1. Stripe Dashboard > **開発者** > **Webhooks** > **「エンドポイントを追加」**
2. URL: `https://eat.posla.jp/api/subscription/webhook.php`
3. リッスンするイベントを追加：
   - `checkout.session.completed`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
4. 作成後、**署名シークレット** （`whsec_xxx`）をコピー

**ステップ4：POSLA管理画面に入力**

1. POSLA管理画面 `/public/posla-admin/dashboard.html` にPOSLA運営アカウントでログイン
2. **「API設定」** タブを開く
3. 以下を入力して **「保存」** をクリック：

| キー名 | 値 |
|---|---|
| stripe_secret_key | `sk_live_xxx` |
| stripe_publishable_key | `pk_live_xxx` |
| stripe_webhook_secret | `whsec_xxx` |
| stripe_price_base | POSLA 基本料金 商品の価格ID（¥20,000/月） |
| stripe_price_additional_store | POSLA 追加店舗 商品の価格ID（¥17,000/月） |
| stripe_price_hq_broadcast | POSLA 本部一括配信アドオン 商品の価格ID（¥3,000/月） |

**ステップ5：動作確認**

1. テストテナント（test1〜test5 等）でサブスクリプション開始フローを実行
2. Stripe Dashboard > Webhooks > エンドポイント詳細 で全イベントが「200 OK」で受信されているか確認
3. POSLA側のDB `subscription_events` テーブルにイベントが記録されているか確認

### 21.2.7 トラブルシューティング

α-1 構成で発生しうるエラーの原因と対処を症状別にまとめます。テナント側の対応で解決しないものは POSLA 運営に連絡してください。

#### 21.2.7.1 症状別フローチャート

```
[症状を特定]
├─ 「30日間無料で始める」を押すとエラーが出る
│   ├─ ALREADY_SUBSCRIBED → 21.2.7.2
│   ├─ NO_STORES → 21.2.7.3
│   ├─ PRICE_NOT_CONFIGURED → 21.2.7.4
│   └─ INVALID_PLAN → 21.2.7.5
├─ 申込み完了したのに状態が反映されない
│   ├─ 状態が「未契約」のまま → 21.2.7.6
│   ├─ 店舗数が違う → 21.2.7.7
│   └─ 次回請求日が空白 → 21.2.7.8
├─ 店舗追加・削除しても Stripe に反映されない → 21.2.7.9
├─ 本部メニュータブが出ない / 消えない → 21.2.7.10
└─ Stripe Customer Portal が開かない → 21.2.7.11
```

#### 21.2.7.2 ALREADY_SUBSCRIBED エラー

**症状**: 「30日間無料で始める」をクリックすると `ALREADY_SUBSCRIBED` (400) が返る。

**原因**: 既にサブスクリプションが存在する（`subscription_status` が `trialing` / `active` / `past_due` のいずれか）。

**確認方法（テナント側）**:
1. 「契約・プラン」タブ上部の状態表示を確認
2. もし「未契約」と表示されているのに ALREADY_SUBSCRIBED が出るなら、表示と DB 状態が乖離している可能性あり

**確認方法（POSLA運営側）**:
```sql
SELECT id, subscription_status, stripe_subscription_id, current_period_end
FROM tenants WHERE id = 't-xxx';
```

**対処**:
- 既存サブスクリプションを Customer Portal で解約してから再申込み
- 既存サブスクリプションの構成変更が目的なら 21.2.2 へ
- Stripe 側に subscription が無いのに DB が trialing/active になっている場合（手動 INSERT した残骸など）は POSLA 運営に DB 修正を依頼

#### 21.2.7.3 NO_STORES エラー

**症状**: `NO_STORES` (400) `店舗を1つ以上登録してください` が返る。

**原因**: テナント配下に有効な店舗（`stores.is_active = 1`）が0件。

**対処**:
1. owner-dashboard > **「店舗管理」** タブを開く
2. **「+ 店舗を追加」** で1店舗以上を作成
3. 「契約・プラン」タブに戻って再度申込み

#### 21.2.7.4 PRICE_NOT_CONFIGURED エラー

**症状**: `PRICE_NOT_CONFIGURED` (500) が返る。

**原因**: POSLA 運営側の `posla_settings` テーブルに以下のいずれかの Price ID が未設定。
- `stripe_price_base`
- `stripe_price_additional_store`
- `stripe_price_hq_broadcast`（本部配信アドオン契約時のみ必要）

**確認方法（POSLA運営側）**:
```sql
SELECT setting_key, setting_value
FROM posla_settings
WHERE setting_key LIKE 'stripe_price_%';
```

**対処**:
- POSLA 運営は 21.2.6 ステップ4 を再実施して3つの Price ID を設定
- テナント側でできることはない（運営に連絡）

#### 21.2.7.5 INVALID_PLAN エラー（旧 standard/pro/enterprise 由来）

**症状**: 古いスクリプトが `plan=standard` を投げて `INVALID_PLAN` が返る。

**原因**: 2026-04-09 の P1-35 で `plan` パラメタは廃止されています。チェックアウト API は `{ hq_broadcast: bool }` のみ受け付けます。

**対処**:
- POSLA UI（owner-dashboard 契約・プラン）からの正常フローでは発生しません
- 直接 API を叩いている古いツール・curl コマンドが原因
- リクエスト body から `plan` を削除し、必要なら `hq_broadcast` のみ送信

#### 21.2.7.6 申込み完了後も状態が「未契約」のまま

**症状**: Stripe Checkout 完了後に POSLA に戻っても、契約・プランタブが「未契約」のまま。

**考えられる原因**:
1. Webhook が POSLA 側に届いていない
2. Webhook 署名検証に失敗している
3. ブラウザがキャッシュした古い状態を表示している

**確認方法（テナント側）**:
1. ブラウザのキャッシュクリア + 再読込（Cmd+Shift+R / Ctrl+Shift+F5）
2. それでも反映されないなら 5 分程度待ってもう一度確認

**確認方法（POSLA運営側）**:
1. Stripe Dashboard > Developers > Webhooks > 該当エンドポイント詳細
2. 「最近の試行」で `checkout.session.completed` が 200 OK で受信されているか確認
3. 200 以外なら `error_log` を確認：
   ```bash
   ssh odah@odah.sakura.ne.jp 'tail -100 ~/www/eat-posla/_logs/php_errors.log | grep -i webhook'
   ```
4. POSLA DB の subscription_events を確認：
   ```sql
   SELECT * FROM subscription_events
   WHERE tenant_id = 't-xxx'
   ORDER BY created_at DESC
   LIMIT 10;
   ```
5. 受信履歴があるのに DB 反映されていない場合は `extract_alpha1_state_from_subscription` 周辺のバグ可能性

**対処**:
- 運営側で webhook を再送（Stripe Dashboard から該当イベントの「再試行」ボタン）
- それでもダメなら手動で `tenants` テーブルを UPDATE

#### 21.2.7.7 店舗数が Stripe 側と乖離

**症状**: 「契約・プラン」タブの店舗数表示が、実際の `stores` テーブルの有効店舗数と違う。

**原因**: P1-36 自動同期フックが店舗追加・削除時に失敗している。

**確認方法**:
```bash
# POSLA 側の有効店舗数
mysql -e "SELECT tenant_id, COUNT(*) FROM stores WHERE is_active=1 GROUP BY tenant_id;"

# Stripe 側の subscription items
ssh odah@odah.sakura.ne.jp 'php /home/odah/www/eat-posla/p137-check-stripe.php t-xxx'
```

**対処**:
- POSLA 運営側で `sync_alpha1_subscription_quantity($pdo, 'テナントID')` を手動実行
- 実行後、Stripe Dashboard と POSLA 双方の店舗数が一致することを確認

#### 21.2.7.8 next_period_end が NULL のまま（P1-35 hotfix 関連）

**症状**: `tenants.current_period_end = NULL` だが `subscription_status = 'trialing'` / `active`。「契約・プラン」タブで次回請求日が空欄。

**原因**: 2026年以降の Stripe `billing_mode=flexible` では `current_period_end` が subscription オブジェクトの top-level から消え、`items[].current_period_end` に移動しています。P1-35 hotfix（2026-04-09 デプロイ済み）で `extract_alpha1_state_from_subscription()` が以下の順でフォールバック読み取りするように修正されました：

1. `$subData['current_period_end']` （旧形式）
2. `$subData['items']['data'][0]['current_period_end']` （新 flexible 形式）

**対処**:
- P1-35 hotfix 適用前に作成されたサブスクリプションは backfill が必要
- POSLA 運営側で以下を実行：
  ```bash
  ssh odah@odah.sakura.ne.jp 'cd ~/www/eat-posla && php -r "
    require \"api/lib/db.php\";
    require \"api/lib/stripe-billing.php\";
    \$pdo = get_db();
    \$config = get_stripe_config(\$pdo);
    \$sub = get_subscription(\$config[\"stripe_secret_key\"], \"sub_xxx\");
    \$state = extract_alpha1_state_from_subscription(\$sub[\"data\"], \$config);
    \$pdo->prepare(\"UPDATE tenants SET current_period_end = ? WHERE id = ?\")
        ->execute([\$state[\"current_period_end\"], \"t-xxx\"]);
  "'
  ```

#### 21.2.7.9 店舗追加・削除が Stripe に反映されない

**症状**: POSLA 側で店舗を追加・削除しても Stripe Subscription quantity が変わらない。

**確認手順**:
1. POSLA `error_log` で `_stores_sync_subscription` の警告を grep
   ```bash
   ssh odah@odah.sakura.ne.jp 'tail -200 ~/www/eat-posla/_logs/php_errors.log | grep stores_sync'
   ```
2. ログに `Stripe API error` などが出ていないか確認

**よくある原因**:
- `tenants.subscription_status` が `none` / `canceled` → 同期スキップ（仕様通り）
- Stripe API 一時障害（HTTP 5xx）→ 復旧後に手動再同期
- Stripe シークレットキーの期限切れ・無効化

**対処**:
- 21.2.7.7 の手動再同期スクリプトを実行
- API キーが原因なら 21.2.6 の Stripe APIキー再取得手順を再実施

#### 21.2.7.10 本部メニュータブが出ない / 消えない

**症状**: アドオン契約済みなのに owner-dashboard に「本部メニュー」タブが出ない。または解除済みなのに残ったまま。

**原因**: Customer Portal でのアドオン追加・解除後、Webhook 反映前にブラウザが古い状態を表示。

**対処**:
1. ブラウザのキャッシュクリア + 強制再読込
2. 5分経っても反映されないなら DB を直接確認：
   ```sql
   SELECT id, hq_menu_broadcast FROM tenants WHERE id = 't-xxx';
   ```
3. DB 値と Stripe 側の line items が乖離している場合は 21.2.7.7 の手動再同期スクリプトを実行（`extract_alpha1_state_from_subscription` が `hq_menu_broadcast` も同期します）

#### 21.2.7.11 Stripe Customer Portal が開かない

**症状**: 「サブスクリプション管理 (Stripe)」ボタンを押すと白画面 / エラー。

**確認方法**:
- ブラウザの開発者ツール > ネットワークタブで `/api/subscription/portal.php` のレスポンスを確認

**よくある原因**:
- `tenants.stripe_customer_id` が NULL → 申込みが未完了
- Customer Portal が Stripe Dashboard 側で無効化されている → 運営側で有効化
- ポップアップブロックが Customer Portal の新しいタブを止めている → ブラウザ設定で eat.posla.jp を許可

#### 21.2.7.12 ログ確認用コマンド集（POSLA運営向け）

```bash
# 直近の Stripe webhook ログ
ssh odah@odah.sakura.ne.jp 'tail -100 ~/www/eat-posla/_logs/php_errors.log | grep -i stripe'

# 直近の店舗同期ログ
ssh odah@odah.sakura.ne.jp 'tail -100 ~/www/eat-posla/_logs/php_errors.log | grep _stores_sync'

# 直近の subscription_events 全件
mysql -e "SELECT tenant_id, event_type, created_at FROM subscription_events ORDER BY created_at DESC LIMIT 20;"

# テナント別 subscription 状態スナップショット
mysql -e "
SELECT id, slug, subscription_status, current_period_end, hq_menu_broadcast,
       (SELECT COUNT(*) FROM stores s WHERE s.tenant_id = t.id AND s.is_active = 1) AS stores
FROM tenants t
WHERE subscription_status IS NOT NULL AND subscription_status != 'none'
ORDER BY current_period_end ASC;
"
```

### 21.2.8 よくある質問（FAQ）

#### 料金・プランについて

**Q1. 旧プラン（standard / pro / enterprise）はもう使えないのですか？**

A. はい、2026-04-09 をもって廃止されました。既存契約は α-1 構成へ自動マイグレーションされており、料金は新構成に従って計算されます。3プラン構成に戻すことはできません。

**Q2. なぜ追加店舗が15%引き（¥17,000）なのですか？**

A. 複数店舗運営はチェーン本部にとってのスケールメリットが大きいため、2店舗目以降は割引価格としています。一方で、店舗数が多いほど POSLA 側のサポート工数も増えるため、完全無料化や大幅割引はしていません。

**Q3. 1店舗だけ運営する場合、月額はいくらですか？**

A. 基本料金 ¥20,000 のみです。追加店舗料金は発生しません。

**Q4. 本部一括配信アドオンは1店舗でも契約できますか？**

A. 仕様上は可能ですが、機能の性質上「複数店舗を運営し本部から一括配信したい」ケースを想定しているため、1店舗での契約は推奨しません。

**Q5. 店舗数が変わったら料金はいつ反映されますか？**

A. 即座に反映されます。Stripe の proration（日割り）機能により、店舗追加時は当月の残日数分の差額が翌月の請求書に上乗せされ、削除時は同様に日割りクレジットが差引されます。

#### トライアルについて

**Q6. トライアル期間中にカードに課金されますか？**

A. されません。カード情報は申込み時に Stripe に登録しますが、トライアル30日間は ¥0 です。31日目から自動で月額課金が開始されます。

**Q7. トライアル期間中に解約したらどうなりますか？**

A. 一切課金されません。Customer Portal から解約すると、トライアル終了日まで使えて、その後 canceled になります。

**Q8. トライアル期間を延長できますか？**

A. テナント側からはできません。POSLA 運営の判断で延長することは可能なので、必要な場合は運営に相談してください（営業判断）。

**Q9. 1度トライアルを使ったテナントは再度トライアルできますか？**

A. できません。トライアルは1テナント1回のみです。解約後の再契約は、即座に課金が開始されます。

#### 構成変更について

**Q10. 店舗追加・削除は何回でもできますか？**

A. 制限ありません。何度でも追加・削除できます。各操作で proration が発生します。

**Q11. 店舗を一度に複数追加した場合、proration はどう計算されますか？**

A. 1店舗ずつフックが発火するので、個別に proration line item が作成されます。最終的な合計は同じです。

**Q12. 本部一括配信アドオンを月末に追加したら丸1ヶ月分課金されますか？**

A. されません。proration により残日数分のみ日割りで課金されます。例：月末1日前に追加なら ¥3,000 × 1/30 ≒ ¥100/店舗。

**Q13. 店舗を削除したのに Customer Portal で line item が残っているのはなぜですか？**

A. 「POSLA 追加店舗」line item は quantity が 0 になっても残ります（Stripe 仕様）。quantity 0 の line item は課金対象外なので問題ありません。

#### 解約・再契約について

**Q14. 解約したらデータは消えますか？**

A. すぐには消えません。解約後30日間はレポート閲覧・データエクスポート可能です。30日経過後は POSLA 運営の判断で物理削除されます（事前通知あり）。

**Q15. 解約後に再契約した場合、過去のデータは復活しますか？**

A. 30日以内なら復活可能です。30日を超えてデータが物理削除された後は復活できません。再契約時の `hq_menu_broadcast` フラグは復元されます。

**Q16. 解約予定日を過ぎる前に取り消せますか？**

A. はい。Customer Portal で「キャンセルを取り消す」をクリックすれば、解約予定が取り消されます。

#### 支払い・請求書について

**Q17. 請求書 PDF はどこからダウンロードできますか？**

A. Stripe Customer Portal の「請求履歴」セクションから PDF ダウンロード可能です。請求書は経理担当者にメールでも自動送信されます。

**Q18. 領収書は発行されますか？**

A. Stripe Customer Portal で発行可能です。請求書 PDF と領収書 PDF は別物として扱われます（日本の経理慣習に対応）。

**Q19. 支払いに失敗するとすぐに使えなくなりますか？**

A. すぐにはなりません。Stripe が7日間自動再試行し、それでも失敗すると `past_due` 状態になります。`past_due` でも14日間は機能利用可能です。14日を超えると `canceled` に移行します。

**Q20. 支払い方法を銀行振込にできますか？**

A. 現状はクレジットカードのみです。銀行振込・コンビニ払いは Stripe の Invoicing 機能で対応可能ですが、POSLA UI からは未実装（要相談）。

#### Stripe 側の質問

**Q21. Stripe 手数料は別途かかりますか？**

A. POSLA 月額料金（基本料金 + 追加店舗 + 本部配信）に対して、Stripe が決済手数料（約3.6%）を差し引きます。例：¥54,000 の月額 → 約 ¥1,944 が Stripe 手数料、POSLA 受取は ¥52,056。請求書にはこの内訳が明記されます。

**Q22. お客さんとの決済の Stripe 手数料とは別ですか？**

A. はい、別物です。POSLA 月額の決済は POSLA 運営の Stripe アカウントで処理され、お客さんからの決済（パターンA / B）はテナント自身の Stripe アカウントで処理されます。

**Q23. Stripe Dashboard にログインしたいです。**

A. Stripe Customer Portal が POSLA テナント向けの簡易管理画面です。フル機能の Stripe Dashboard は POSLA 運営アカウントでしかログインできません（プラットフォーム親アカウントのため）。

#### 運営者向けの質問

**Q24. （運営）テナントの subscription_status を手動で書き換えていいですか？**

A. 緊急時のみ可。書き換え後は必ず Stripe 側と整合性を取ってください。`extract_alpha1_state_from_subscription()` を使った再同期スクリプトの実行を推奨します。

**Q25. （運営）`plan_features` テーブルは削除していいですか？**

A. 1〜2週間の安定運用を確認してから削除してください。現状は rollback 安全性のため残置されています。`api/lib/auth.php` の `check_plan_feature()` は `hq_menu_broadcast` 以外のキーに対して常に `true` を返すため、テーブルの中身は実質参照されません。

---

## 21.3 パターンA：自前Stripeキー

テナントが自分のStripeアカウントを既に持っている場合の設定です。**POSLA手数料1.0%はかかりません。**

### 21.3.1 事前準備（Stripe側）

1. [Stripe Dashboard](https://dashboard.stripe.com/) にアクセス
2. アカウント未作成なら **「新規登録」** からアカウント作成
3. メールアドレス・氏名・パスワード・国（日本）を入力
4. メール認証 → ログイン
5. ダッシュボードの **「アカウントを有効化」** から本人確認・事業者審査を実施：
   - 法人/個人事業主の選択
   - 事業者情報（屋号・住所・電話・業種）
   - 代表者本人確認（運転免許証 or マイナンバーカードのアップロード）
   - 銀行口座情報（売上の入金先）
6. 審査を待つ（通常1〜3営業日、追加書類の要求があれば対応）
7. 審査完了後、Stripe Dashboard > **設定** > **アカウント** で **`charges_enabled = true`** になっていることを確認

### 21.3.2 Secret Keyの取得

1. Stripe Dashboard > **開発者** > **APIキー**
2. 「シークレットキー」のセクションで **「キーを表示」** をクリック
3. `sk_live_xxx...` という長い文字列が表示されるので **コピー**
4. このキーは **絶対に他人に見せない・SNSやチャットに貼らない** こと（漏れたらStripe Dashboardで「ローテーション」して新しいキーに差し替える）

### 21.3.3 POSLAへの登録手順

1. オーナー権限のアカウントでPOSLAにログイン
2. 左メニューの **「決済設定」** タブをクリック
3. 「決済ゲートウェイ」セクションの「使用する決済サービス」で **「Stripe」** を選択
4. 「Stripe Secret Key（パターンA用）」の入力欄に先ほどコピーした `sk_live_xxx...` を貼り付け
5. **「保存」** ボタンをクリック
6. 「保存しました」とトーストが表示される
7. 保存後はキーがマスクされ `sk_live_●●●●●●●●1234` のように下4桁だけ表示される
8. これでパターンAが有効。レジ・セルフレジ・テイクアウトのカード決済が使えるようになります

### 21.3.4 動作確認

1. レジアプリ `/public/kds/register.html` を開く
2. 適当なメニューを選択 → 合計金額を出す
3. 決済方法で **「クレジット（対面）」** を選択
4. カードリーダーが未接続なら 21.5 を先に実施
5. 少額（¥100など）でテスト決済を実行
6. Stripe Dashboard > **決済** で該当の決済が記録されていることを確認
7. テナント自身のStripe残高に入金されていることを確認（`application_fee` が引かれていない）

### 21.3.5 キーの更新・削除

#### 更新（キーをローテーションした場合）

1. Stripe Dashboard でキーをローテーションして新しい `sk_live_xxx` を発行
2. POSLA「決済設定」タブを開く
3. Stripe Secret Key欄に新しいキーを上書きして **「保存」**

#### 削除

1. POSLA「決済設定」タブを開く
2. Stripe Secret Key欄の隣にある **「削除」** ボタンをクリック
3. 「削除しますか？」と確認ダイアログが出るので **「OK」**
4. `stripe_secret_key = NULL` になり、パターンAが無効化されます

---

## 21.4 パターンB：POSLA経由Connect（Stripe Connect）

Stripeアカウントを持っていないテナントが、POSLA経由で間接的にStripe決済を使う方式です。

### 21.4.1 接続を開始する（テナント側）

1. オーナー権限でPOSLAにログイン
2. 左メニューの **「決済設定」** タブをクリック
3. 画面に「Stripe Connect：未接続」と表示される
4. **「Stripe Connectに登録して決済を開始する」** ボタンをクリック
5. 「Stripeアカウントを作成しています...」と表示される（数秒）
6. 自動的にStripeの **オンボーディング画面** にリダイレクト

### 21.4.2 オンボーディング入力（Stripe側）

7. Stripeのオンボーディング画面で以下を入力（10〜20分かかります）：

   **(1) 事業者情報**
   - 国：日本
   - 事業者タイプ：個人事業主 or 法人
   - 法人名 or 屋号
   - 住所（郵便番号→自動補完）
   - 電話番号
   - 業種：「飲食店・カフェ」を選択

   **(2) 代表者情報**
   - 氏名（漢字 + フリガナ）
   - 生年月日
   - 自宅住所
   - 電話番号

   **(3) 銀行口座情報**
   - 銀行名・支店名・口座種別（普通/当座）
   - 口座番号・名義人カナ

   **(4) 本人確認書類アップロード**
   - 運転免許証（表+裏）or マイナンバーカード（表）
   - 代表者の自撮り写真（Stripeアプリで撮影）

   **(5) 事業内容**
   - 商品/サービス：「飲食物の提供」
   - 平均単価
   - 想定月商

8. 全項目入力後、**「送信」** ボタンをクリック
9. 「申請を受け付けました」と表示
10. 自動的にPOSLAの「決済設定」タブにリダイレクト

### 21.4.3 審査結果を待つ

11. Stripeが審査します（通常1〜3営業日）
12. 審査中は「決済設定」タブに「Stripe Connect：審査中」と表示
13. 審査完了するとStripeから登録メールアドレスに通知が届きます
14. POSLAの「決済設定」タブを再度開くと「Stripe Connect：有効」に変わっています

### 21.4.4 接続状態の確認

「決済設定」タブで以下が確認できます。

| 項目 | 意味 | 期待される値 |
|------|------|---|
| charges_enabled | 決済受付が可能か | **true** |
| payouts_enabled | 銀行入金が有効か | **true** |
| details_submitted | 情報入力完了 | **true** |

3つすべてが true になっていれば決済可能です。いずれかが false の場合：
- Stripeから届いたメールを確認
- Stripe Dashboardで追加情報を入力
- 21.7 Q12「charges_enabledがfalseのまま」を参照

### 21.4.5 決済方法の選択

接続後、店舗ごとに利用する決済方法を選びます。

1. オーナーダッシュボード > **「店舗管理」** タブで店舗を選択
2. **「決済方法」** セクションで使う方法にチェック：

| 決済方法 | 説明 | 必要な接続 |
|---------|------|-----------|
| 現金 | テンキー入力 | 不要 |
| クレジット（対面） | Stripe Reader S700 | パターンA or B |
| QR決済 | PayPay等を外部アプリで処理 | 不要（記録のみ） |
| セルフレジ | お客さんスマホでStripe決済 | パターンA or B |

3. **「保存」** をクリック

### 21.4.6 手数料の仕組み（パターンBのみ）

```
お客さんの支払額 = 注文金額（例：¥3,000）
   ↓
Stripeが決済処理
   ↓
   ├── Stripe決済手数料 ¥108 (3.6%)
   ├── POSLA手数料 ¥30 (1.0%) → POSLA運営へ
   └── テナント受取 ¥2,862
```

| 計算項目 | 金額 |
|---|---|
| 決済金額 | ¥3,000 |
| Stripe決済手数料（3.6%） | ¥108 |
| POSLA手数料（1.0%） | ¥30 |
| **テナント受取額** | **¥2,862** |

POSLA手数料は `ceil(決済金額 × 1.0 / 100)` で計算され、最低 ¥1 です。

### 21.4.7 入金サイクル

- 決済から **約7日後** にテナント指定の銀行口座に自動入金されます
- Stripe Dashboard > **設定** > **入金スケジュール** で変更可能：
  - 自動入金（推奨）
  - 手動入金
  - 週次入金（毎週特定の曜日）
  - 月次入金

### 21.4.8 入金履歴の確認

1. Stripe Dashboard > **入金** で過去の入金一覧が見られます
2. 各入金をクリックすると、その入金に含まれる決済の内訳が表示されます

### 21.4.9 オンボーディング途中で離脱した場合の再開

1. POSLA「決済設定」タブを開く
2. 「Stripe Connect：オンボーディング未完了」と表示される
3. **「オンボーディングを続ける」** ボタンをクリック
4. 中断した箇所からStripeのオンボーディング画面が再開されます

### 21.4.10 Stripe Connectの解除（パターンAへ切り替える場合）

::: warning 解除すると一時的に決済が使えなくなる
解除から再接続（またはパターンAキー設定）までの間、レジ・セルフレジ・テイクアウトの決済が一時停止します。**営業時間外**に実施してください。
:::

1. オーナーダッシュボード > **「決済設定」** タブ
2. 「Stripe Connect：有効」ブロック内の **「Stripe Connectを解除」** ボタンをクリック
3. 「解除しますか？」と確認ダイアログが出るので **「OK」**
4. 「Stripe Connectを解除しました」と表示
5. POSLA側のDB（`stripe_connect_account_id` 等）が空になります
6. Stripe Secret Key の入力欄が活性化し、パターンAの設定が可能になります

::: tip Stripe側のアカウントは残る
解除はPOSLA側のひも付けを切るだけで、Stripe Dashboard上のアカウント自体は残ります。完全に削除したい場合は、Stripe Dashboardから直接削除してください。
:::

### 21.4.11 POSLA運営側の初期設定（運営者向け）

::: tip 通常テナントは21.4.11 を読む必要はありません
:::

**ステップ1：Stripe Connectプラットフォーム登録**
1. Stripe Dashboardで **「Connect」** を有効化
2. プラットフォーム情報を入力（POSLAのビジネス情報）
3. 対応国：日本（JP）
4. アカウントタイプ：**Express**

**ステップ2：Connect手数料の設定**

POSLA管理画面の **「API設定」** タブで以下を入力：

| キー名 | デフォルト | 説明 |
|--------|----------|------|
| connect_application_fee_percent | 1.0 | POSLA手数料率（%） |

**ステップ3：Stripe Terminal（カードリーダー）有効化**
- Stripe Dashboard > **Terminal** で機能を有効化
- POSLA側は接続トークン発行エンドポイント（`POST /api/connect/terminal-token.php`）が自動対応

**ステップ4：テナント接続状態の定期確認**
- POSLA管理画面 > **「テナント管理」** タブで `charges_enabled` / `payouts_enabled` / `details_submitted` を一覧確認

---

## 21.5 Stripe Reader S700（カードリーダー）の購入・接続・運用

対面でクレジットカード決済を受けるには **Stripe Reader S700** が必要です。本節は **14ステップで完全解説** します。

### 21.5.1 製品仕様

| 項目 | 値 |
|------|-----|
| 製品名 | Stripe Reader S700（BBPOS WisePOS E相当） |
| 価格 | **¥52,480**（税込、Stripe価格）|
| 接続方式 | **Wi-Fi**（有線LAN・USB不要） |
| 対応カード | VISA / Mastercard / JCB / AMEX / Diners / Discover |
| 対応決済 | タッチ決済 / IC決済 / Apple Pay / Google Pay |
| 画面 | 5インチタッチスクリーン |
| バッテリー | 連続8時間 |
| 充電 | 専用ドック付属 |
| 対応プラットフォーム | Web（Stripe Terminal JS SDK） |

### 21.5.2 購入方法

1. POSLA運営にメールで連絡：「S700を○台購入したい」と伝える
2. POSLA運営がStripeに発注 → 自宅/店舗住所に発送
3. 通常 5〜7営業日で到着
4. 代金はPOSLAの月額料金に上乗せ請求 or 別途請求書（初期費用扱い）

::: tip 自分でStripeから直接購入する場合（パターンA上級者向け）
パターンAでStripeアカウントを持っている人は、Stripe Dashboard > **Terminal** > **ハードウェア注文** から自分で発注することもできます。POSLA運営経由のほうが在庫管理・サポートが楽です。
:::

### 21.5.3 接続手順（14ステップ完全版）

#### 準備：箱から出して充電する

**ステップ1**：S700を箱から出します。中身は本体・専用充電ドック・電源アダプタ・USBケーブル・取扱説明書です

**ステップ2**：充電ドックを電源アダプタに接続し、コンセントにつなぎます

**ステップ3**：S700本体を充電ドックに置いて充電します。電源ボタン（本体上部）を長押しすると起動。初回は満充電まで2時間ほど

**ステップ4**：起動するとStripeロゴ → 「言語を選んでください」と表示。**「日本語」** をタップ

#### Wi-Fi接続

**ステップ5**：「ネットワークを選択してください」と表示。店舗のWi-Fiのアクセスポイント名（SSID）をタップ

**ステップ6**：Wi-Fiパスワードを入力 → **「接続」** をタップ

**ステップ7**：接続成功すると画面に **「ペアリング待ち」** または **「Pair me」** と表示されます

::: warning Wi-Fiにつながらない場合
- 5GHz帯のみのWi-Fiはダメ。**2.4GHz帯**を使用してください
- ルーターのMACアドレスフィルタリングがかかっていないか確認
- ゲストネットワークだとStripeサーバとの通信が遮断されることがある → 通常SSIDで接続
:::

#### POSLAレジアプリと接続（ペアリング）

**ステップ8**：レジ用PCまたはタブレットで Chrome ブラウザを開き、以下にアクセス：
```
https://eat.posla.jp/public/kds/register.html
```

**ステップ9**：オーナー or マネージャー権限のアカウントでログイン

**ステップ10**：レジ画面の右上にある **「カードリーダー接続」** ボタンをクリック

**ステップ11**：「接続トークンを取得しています...」と表示（数秒）

**ステップ12**：「リーダーを検出しています...」と表示。同じWi-Fiにつながっている S700 が一覧に出てきます

**ステップ13**：一覧から自分の S700 をクリック → **「接続」** をタップ

**ステップ14**：S700 の画面に「Connected to POSLA」と表示され、レジ画面でも **「リーダー：接続済み」** に変わります。これで完了 🎉

### 21.5.4 決済を受ける手順（営業中の操作）

1. レジでメニューをタップして合計金額を確定
2. **「会計に進む」** ボタンをクリック
3. 決済方法で **「クレジット（対面）」** を選択
4. **「決済を開始」** ボタンをクリック
5. S700の画面に金額が表示される
6. お客さんがS700にカードをタッチ or 挿入
7. PINコード入力（必要な場合）
8. S700が「処理中...」 → 「決済成功」と表示
9. レジ画面に「決済完了」と表示
10. **「レシートを印刷」** ボタン or **「電子レシート発行」** で完了

### 21.5.5 リーダーの日常運用

#### 営業前

- 充電ドックから外して所定の位置に置く
- 電源ボタンを押して起動 → Wi-Fi接続を確認
- レジアプリで「リーダー：接続済み」を確認

#### 営業中

- 操作不要。レジから決済指示を出すだけ

#### 営業後

- 充電ドックに戻す（電源OFFにしなくてOK、ドックに置けば自動充電）
- レジアプリは閉じてもOK（次回開いたとき自動再接続）

### 21.5.6 リーダーが2台以上ある場合

- 2台目以降も同じ手順（21.5.3 ステップ1〜14）でセットアップ
- レジアプリの「カードリーダー接続」で複数台が一覧に出るので、使うほうを選ぶ
- レジ用PC/タブレットそれぞれが別のリーダーに紐付けられます

### 21.5.7 ファームウェア更新

- 起動時に「ファームウェア更新があります」と表示されたら **「更新」** をタップ
- 更新中は電源を切らない（5〜10分）
- 更新後、再起動して通常通り使えます

---

## 21.6 セルフレジ（お客さんのスマホで決済）

セルフオーダー画面からお客さんがそのまま決済する機能です。

### 21.6.1 仕組み

```
お客さん ─スマホでメニューから注文─→ 厨房（KDS）に通知
       ↓
   料理を食べる
       ↓
       「お会計」ボタンをタップ
       ↓
   Stripe Checkout（外部の決済ページ）に遷移
       ↓
   カード or Apple Pay / Google Pay / Link で1タップ決済
       ↓
   元のセルフオーダー画面に戻る → 「お支払い完了」表示
```

### 21.6.2 有効化手順

1. オーナーダッシュボード > **「店舗管理」** タブで店舗を選択
2. **「決済方法」** セクションで **「セルフレジ」** にチェック
3. **「保存」**

### 21.6.3 お客さんの操作（参考：実際にお客さんに見える画面）

1. テーブルのQRコードをスマホでスキャン
2. メニューから商品を選んで注文
3. 食事
4. 画面下部の **「お会計」** ボタンをタップ
5. 未払い注文一覧と税率別内訳がモーダルで表示
6. 決済手段ロゴ列（VISA / Mastercard / JCB / AMEX / Apple Pay / Google Pay）と **「お支払いに進む」** ボタンが表示
7. **「お支払いに進む」** をタップ → Stripe Checkoutに遷移
8. クレジットカード入力 or Apple Pay / Google Pay / Link で1タップ決済
9. 決済完了後、自動的にセルフオーダー画面に戻り「お支払い完了」表示
10. **「領収書を表示」** ボタンで30分限定のお会計明細HTMLを表示（スクリーンショット保存可）

### 21.6.4 Apple Pay / Google Pay の有効化

::: tip コード変更不要
Apple Pay / Google Pay / Link は **Stripe Dashboard > 設定 > 決済方法** で有効化するだけで Stripe Checkout 上に自動表示されます。POSLA側のコード変更・ドメイン認証ファイル配置は **不要**。
:::

1. Stripe Dashboard > **設定** > **決済方法**
2. 「Apple Pay」「Google Pay」「Link」の各カードで **「有効にする」** をクリック
3. 数分後、セルフレジに自動反映されます

---

## 21.7 よくある質問30件

### A. 申込み・契約について

**Q1. Stripeアカウントを作るのにお金はかかりますか？**
A. アカウント開設は **無料** です。決済が発生した時のみ手数料がかかります。

**Q2. 法人じゃないと使えませんか？**
A. **個人事業主でも使えます。** Stripe登録時に「個人事業主」を選択してください。

**Q3. 審査でどれくらいの時間がかかりますか？**
A. 通常 **1〜3営業日** です。書類不備があると追加で数日かかります。

**Q4. 審査に落ちることはありますか？**
A. 飲食店業はほぼ通ります。落ちる場合は事業実態が確認できないケースが多い（屋号・住所が一致しない等）。

**Q5. 申込み中にカード決済が失敗します**
A. カード会社で利用制限がかかっている可能性があります。①別のカードを試す ②カード会社に連絡 ③それでもダメならStripeサポートに問い合わせ。

### B. パターン選択について

**Q6. パターンAとBはどちらが得ですか？**
A. **Aのほうが手数料が安い**（POSLA手数料1.0%が無い）です。ただしAはStripeアカウント開設・審査が自分で必要。Bは代行してもらえる代わりに1.0%上乗せ。

**Q7. パターンBで始めて、後からAに切り替えられますか？**
A. **可能です。** 21.4.10「Stripe Connectの解除」を参照。

**Q8. パターンAとB、両方設定したらどうなりますか？**
A. **Bが優先**されます。両方ある場合はBで処理。

### C. Stripe Reader S700について

**Q9. S700はいくらですか？**
A. **¥52,480（税込）** です。POSLA運営経由で発注してください。

**Q10. S700が無くてもクレジット決済はできますか？**
A. 対面のIC/タッチ決済はS700が必須です。**セルフレジ（お客さんスマホ決済）** ならS700無しでクレジット決済が使えます（21.6参照）。

**Q11. S700を複数台繋げますか？**
A. **可能です。** レジ用PC/タブレット1台に対して1台のS700をペアリング。複数のレジがあれば、それぞれに異なるS700を割り当てられます。

**Q12. S700のWi-FiがつながりませんA. 21.5.3 ステップ5の warning を参照。①2.4GHz帯を使う ②MACアドレスフィルタを外す ③ゲストネットワークではなく通常SSIDを使う。

**Q13. S700の決済が「処理失敗」になります**
A. ①カードの磁気不良の可能性 → 別のカードで試す ②`charges_enabled` を確認 ③Stripe Dashboard > Terminal > リーダー一覧 で該当S700の状態を確認 ④Wi-Fi切断の可能性 → S700を再接続。

**Q14. S700の電源が入りません**
A. ①充電ドックに正しく載っているか確認 ②電源ボタン（上部）を10秒長押しで強制再起動 ③それでもダメならPOSLA運営に連絡。

**Q15. S700を別の店舗に移動できますか？**
A. **可能ですが**、移動先のWi-Fiに再接続する必要があります。21.5.3 ステップ5以降を再実行してください。

### D. 決済手数料について

**Q16. 決済手数料は具体的にいくらですか？**
A. パターンAは Stripe決済手数料 **約3.6%** のみ。パターンBは **約3.6% + POSLA手数料1.0% = 約4.6%**。

**Q17. 手数料はいつ引かれますか？**
A. 決済時に自動的に引かれ、入金時に **差引後の金額** が銀行口座に振り込まれます。

**Q18. JCBやAMEXの手数料は違いますか？**
A. Stripeの基本料金は全カード一律3.6%です（2026年現在）。Stripe Dashboardで最新料金表を確認してください。

**Q19. 月額の固定費はありますか？**
A. **POSLA月額料金**（基本料金 ¥20,000 + 追加店舗 ¥17,000/店舗 + 本部配信アドオン ¥3,000/店舗(任意)）が固定でかかります。Stripe側の月額固定費はゼロ（決済時の手数料のみ）。詳しくは 21.2 を参照。

### E. 入金について

**Q20. 売上はいつ銀行口座に入金されますか？**
A. デフォルトで **決済から約7日後**。Stripe Dashboard > 設定 > 入金スケジュール で変更可能（手動・週次・月次など）。

**Q21. 入金額が想定と違います**
A. ①Stripe決済手数料3.6%が引かれている ②パターンBならPOSLA手数料1.0%も引かれている ③チャージバック・返金がある ④Stripe Dashboard > **入金** で内訳を確認できます。

**Q22. 入金先の銀行口座を変更したいです**
A. Stripe Dashboard > **設定** > **銀行口座と通貨** から変更可能。本人確認書類の再提出が必要な場合あり。

### F. トラブル・運用について

**Q23. 「Stripe Connect：審査中」のまま動きません**
A. 通常1〜3営業日。それ以上たっても進まない場合は ①Stripe Dashboardでメールを確認 ②追加情報の入力依頼が来ていないか確認 ③Stripeサポートに直接問い合わせ。

**Q24. `charges_enabled = false` のままです**
A. ①Stripe Dashboard > 設定 > アカウント で「不足情報」を確認 ②本人確認書類が不鮮明だと再提出になる ③事業内容の説明が不十分だと追加質問が来る → 全部対応。

**Q25. 決済は成功したのにPOSLAに注文記録が無いです**
A. Webhookが届いていない可能性。①POSLA側DB `subscription_events` テーブルを確認 ②Stripe Dashboard > Webhooks > 履歴で送信失敗がないか確認 ③POSLA運営に連絡。

**Q26. お客さんが「決済完了したのに領収書が出ない」と言っています**
A. ①セルフレジの場合：「領収書を表示」ボタンを押すと30分限定のHTMLが出る。スクリーンショット保存を案内 ②対面の場合：レジ画面の **「電子レシート発行」** または **「印刷」** ボタンを使う。

**Q27. 返金したいです**
A. Stripe Dashboard > **決済** > 該当決済をクリック → **「払い戻し」** ボタンで返金可能。POSLA側の `audit_log` にも自動記録されます。

**Q28. POSLAの月額料金を解約したいです**
A. 21.2.3 を参照。「契約・プラン」タブの **「サブスクリプションを解約」** ボタンで予約解約。期間終了時に解約されます。

**Q29. POSLAの月額料金を解約したらお客さんとの決済も止まりますか？**
A. **はい、機能制限モードになるため決済も停止します。** 解約予定日まではすべての機能が使えます。

**Q30. パスワードを忘れました（POSLAログイン）**
A. ログイン画面の **「パスワードを忘れた方」** リンクから再設定。詳細は [02-login](./02-login.md) 参照。

---

## 21.8 トラブルシューティング表

### Stripe Billing 関連

| 症状 | 原因 | 対処 |
|---|---|---|
| サブスクリプション開始後に契約状態が反映されない | Webhookが届いていない | Stripe Dashboard > Webhooks > 履歴で確認・再送信 |
| 請求書が発行されない | メールアドレス未登録 | テナント設定でメール登録 |
| カード決済が失敗する | カード与信問題 | Stripe Customer Portalで別カードに変更 |
| `subscription_status = past_due` | 支払い失敗 | カード情報を更新 → Stripe自動再課金 |

### パターンA（自前Stripeキー）関連

| 症状 | 原因 | 対処 |
|---|---|---|
| 「保存」後もカードリーダーが使えない | Secret Keyが無効 | Stripe Dashboardで再発行して再保存 |
| `Permission` エラー | Terminal機能が未有効 | Stripe Dashboard > Terminal を有効化 |
| 決済失敗 | `charges_enabled=false` | 本人確認・事業者審査を完了 |
| キーをPOSLAに登録したのに反映されない | キャッシュ | ブラウザキャッシュクリア・再ログイン |

### パターンB（Stripe Connect）関連

| 症状 | 原因 | 対処 |
|---|---|---|
| 接続状態が「無効」のまま | オンボーディング未完了 | Stripeから届いたメールに従って追加入力 |
| `charges_enabled=false` | 審査中・書類不備 | Stripe Dashboardでメッセージ確認 |
| カードリーダー接続不可 | Wi-Fi切断・古いファームウェア | リーダー再起動・ファーム更新 |
| 決済成功するが注文未記録 | Webhook失敗 | `subscription_events` と `audit_log` 確認 |
| 「Stripe Connectを解除」が表示されない | 未接続状態 | 接続完了後にのみ表示 |

### Stripe Reader S700 関連

| 症状 | 原因 | 対処 |
|---|---|---|
| Wi-Fiにつながらない | 5GHz帯のみ・MACフィルタ | 2.4GHz帯使用・フィルタ解除 |
| 「Pair me」のままレジから検出されない | レジが別Wi-Fi | レジPCも同じWi-Fiに接続 |
| 「処理失敗」が頻発 | カード磁気不良・通信不安定 | 別カードで試行・Wi-Fi再接続 |
| 充電できない | ドック汚れ・接触不良 | ドック端子を清掃・電源アダプタ交換 |
| 起動しない | バッテリー切れ・故障 | 1時間充電後に電源ボタン10秒長押し |
| 「Stripe Terminal not enabled」 | Stripe側でTerminal未有効 | Stripe Dashboard > Terminal を有効化 |

### セルフレジ関連

| 症状 | 原因 | 対処 |
|---|---|---|
| 「お会計」ボタンが出ない | セルフレジ未有効化 | 21.6.2 参照 |
| Stripe Checkout に遷移しない | publishable key未設定 | POSLA運営に連絡 |
| Apple Pay が出ない | Stripe側で未有効 | Stripe Dashboard > 決済方法 で有効化 |
| 「領収書を表示」が空白 | 30分の有効期限切れ | 30分以内に保存するよう案内 |

---

## 21.9 データベース構造（POSLA運営向け）

::: tip 通常テナントは21.9 を読む必要はありません
:::

### 21.9.1 tenants テーブルのStripe関連フィールド

| フィールド | 型 | 説明 |
|-----------|-----|------|
| payment_gateway | ENUM('none','stripe') | 使用する決済ゲートウェイ |
| stripe_secret_key | VARCHAR(200) | パターンA用。テナント自前のStripe Secret Key（`sk_live_xxx`） |
| stripe_customer_id | VARCHAR(50) | Stripe BillingのカスタマーID（`cus_xxx`） |
| stripe_subscription_id | VARCHAR(50) | Stripe Billingのサブスクリプション（`sub_xxx`） |
| subscription_status | ENUM | none / trialing / active / past_due / canceled |
| current_period_end | DATETIME | 現在の課金期間終了日 |
| stripe_connect_account_id | VARCHAR(100) | パターンB用。Connect アカウントID（`acct_xxx`） |
| connect_onboarding_complete | TINYINT(1) | オンボーディング完了フラグ |
| charges_enabled | TINYINT(1) | Connect：決済受付可能か |
| payouts_enabled | TINYINT(1) | Connect：入金が有効か |

### 21.9.2 subscription_events テーブル

全Webhookイベントの受信ログ（冪等性キー）。

| フィールド | 説明 |
|-----------|------|
| event_id | UUID（POSLA側） |
| tenant_id | 対象テナント |
| event_type | Webhookイベントタイプ |
| stripe_event_id | StripeのイベントID（**UNIQUE INDEX、P1-11で追加**） |
| data | イベントデータ（JSON） |
| created_at | 受信日時 |

### 21.9.3 関連API（2026-04-18 時点の実ファイル）

#### Stripe Billing（サブスクリプション）
| エンドポイント | 用途 |
|---|---|
| `POST /api/subscription/checkout.php` | Billing Checkout セッション作成 |
| `POST /api/subscription/portal.php` | Stripe Customer Portal セッション作成（解約・カード変更等） |
| `GET /api/subscription/status.php` | サブスクリプション状態取得 |
| `POST /api/subscription/webhook.php` | Stripe Webhook 受信（`customer.subscription.*`、`invoice.*`） |

#### Stripe Connect（決済代行）
| エンドポイント | 用途 |
|---|---|
| `POST /api/connect/onboard.php` | Connect Express Account 作成 + オンボーディングリンク取得 |
| `GET /api/connect/callback.php` | OAuth コールバック |
| `GET /api/connect/status.php` | Connect 接続状態取得（`charges_enabled` 等） |
| `POST /api/connect/disconnect.php` | Connect 切断（`stripe_connect_account_id` クリア） |
| `POST /api/connect/terminal-token.php` | Stripe Terminal SDK 用接続トークン（5 分有効） |

#### Stripe Terminal & 決済処理
| エンドポイント | 用途 |
|---|---|
| `POST /api/store/terminal-intent.php` | Stripe Terminal PaymentIntent 作成（Pattern A / B 両対応） |
| `POST /api/store/process-payment.php` | 全決済方法の実行統合（cash/card/qr/terminal） |
| `POST /api/store/refund-payment.php` | 返金処理（CR1） |

#### サインアップ
| エンドポイント | 用途 |
|---|---|
| `POST /api/signup/register.php` | テナント新規申込（30 日トライアル開始） |
| `POST /api/signup/activate.php` | 決済完了後の有効化 |
| `POST /api/signup/webhook.php` | サインアップ専用 Stripe Webhook |

### 21.9.4 関連マイグレーション

- `migration-c3-payment-terminal.sql` — Connect Account ID 列追加
- `migration-l10d-payment-settings.sql` — 決済設定タブ独立化
- `migration-p1-11-subscription-events-unique.sql` — Webhook冪等性 UNIQUE INDEX
- `migration-p1-7d-stripe-checkout-rollback.md` — automatic_payment_methods削除（Checkoutでは無効）

---

## 関連章

- [02-login](./02-login.md) — POSLAへのログイン手順
- [15-owner](./15-owner.md) — オーナーダッシュボード全体
- [18-security](./18-security.md) — APIキー・パスワード管理
- [22-smaregi-full](./22-smaregi-full.md) — スマレジ連携

---

::: tip この章の更新履歴
- **2026-04-09 (P1-34d)**: α-1 プラン構成反映（旧 standard/pro/enterprise 3プラン → 単一プラン+アドオン構成）。21.2 を全面書き換え：基本料金 ¥20,000 + 追加店舗 ¥17,000 + 本部一括配信アドオン ¥3,000、30日間無料トライアル、Stripe商品3つ（base / additional_store / hq_broadcast）に変更
- **2026-04-09**: フル粒度書き直し（21.0「はじめに」追加・S700 14ステップ詳細化・FAQ30件・トラブルシューティング表拡張）— P1-N13 AIヘルプデスク基準章として再構成
- **2026-04-07**: P1-7d ロールバック反映（automatic_payment_methods削除）
- **2026-04-06**: P1-7b セルフレジUI改善反映（VISA/MC/JCB/AMEX/Apple Pay/G Payロゴ追加・「お支払いに進む」文言）
- **2026-04-04**: 初版作成（L-10d 決済設定タブ独立化）
:::
