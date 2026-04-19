---
title: 課金・サブスクリプション
chapter: 03
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [billing, 運営, POSLA]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 3. 課金・サブスクリプション

POSLAはStripe Billingを使用したSaaS型のサブスクリプション課金モデルを採用しています。

::: tip 2026-04-09 α-1 構成（確定）
2026-04-09 より、**単一プラン + アドオン**構成に切り替わりました。本章は α-1 構成で書かれています。機能判定（`check_plan_feature`）は α-1 ベースで一本化済（`hq_menu_broadcast` のみ判定、それ以外は常に true）。ただし POSLA 管理画面のテナント編集 API（`api/posla/tenants.php`）では旧 3 プラン文字列（`standard` / `pro` / `enterprise`）も互換のため受付可能。新規テナントには α-1 構成のみ推奨。
:::

::: tip この章を読む順番（運営者向け）
1. **3.1 プラン構成（α-1）** で価格体系を把握
2. **3.3 Stripe Billing 設定** で Price ID / Webhook 構築
3. **3.4 ライフサイクル** と **3.5 構成変更** で運用フロー
4. 障害発生時は **3.10 トラブルシューティング** と **3.11 FAQ**
:::

## 3.1 プラン構成（α-1）

POSLA は **単一プラン + アドオン** で提供されます。機能別の段階プランは存在しません。

### 価格構造

| 項目 | 月額 | 備考 |
|------|------|------|
| **基本料金（1店舗目）** | **¥20,000** | 全機能込み |
| **追加店舗（2店舗目以降）** | **¥17,000 / 店舗** | 15% 割引 |
| **本部一括メニュー配信アドオン**（任意） | **+¥3,000 / 店舗** | チェーン本部運用のみ |

### 月額合計の例

| 規模 | 基本料金 | +本部配信アドオン | 月額合計 |
|------|----------|------------------|----------|
| 1店舗 | ¥20,000 | — | ¥20,000 |
| 3店舗 | ¥54,000 | (任意) | ¥54,000 〜 ¥63,000 |
| 5店舗 | ¥88,000 | +¥15,000 | ¥88,000 〜 ¥103,000 |
| 10店舗 | ¥173,000 | +¥30,000 | ¥173,000 〜 ¥203,000 |
| 30店舗 | ¥513,000 | +¥90,000 | ¥513,000 〜 ¥603,000 |

### 設計哲学

- **機能ではなく "業務フロー" を売る。** 飲食店業務は予約→着席→注文→厨房→提供→会計→在庫→分析→次回予約まで一続きで、一部だけ使う運用は本来ない
- **全機能はすべての契約者に標準提供する。** 個別 ON/OFF にしない
- **唯一の差別化機能 = 本部一括メニュー配信**（`hq_menu_broadcast`）。これだけが任意アドオンとして別 Price ID で提供される

### 標準機能一覧（全契約者共通）

以下はすべての契約者が標準で利用できます。営業時に「全部入りです」の一言で済ませられる構成です。

- セルフオーダー / ハンディPOS / フロアマップ / テーブル合流分割
- KDS / AI音声コマンド / AIウェイター / AI需要予測
- 在庫管理 / シフト管理 / 店舗間ヘルプ要請 / 統合シフトビュー
- 高度レポート（回転率・客層・スタッフ評価・併売分析）/ 監査ログ / 満足度評価
- テイクアウト / 決済ゲートウェイ（Stripe Connect）
- 多言語対応 / クロス店舗分析（ABC分析）
- オフライン検知 / マルチセッション制御

### 本部一括メニュー配信アドオン（任意）

- アドオン契約 = `tenants.hq_menu_broadcast = 1`
- 有効時：owner-dashboard に「本部メニュー」タブが出現し、本部マスタからの一括配信が可能
- 店舗側 dashboard 「店舗メニュー」タブからは本部マスタ項目（name/category/calories/allergens/base_price/image_url）が読み取り専用
- 未契約時：店舗側 dashboard 「店舗メニュー」タブから本部マスタを自由に編集可能

---

## 3.2 トライアル

- **30日間無料**
- **Stripe カード登録必須**（無料期間中に課金は発生しないが、カード情報を Stripe に預ける）
- 31日目から自動で月額 ¥20,000 課金開始（追加店舗・アドオンがあればその分も含む）
- 期間中に解約すれば一切課金なし
- **1テナント1回限り。** 解約後の再契約は即時課金

### トライアル付与判定

`api/subscription/checkout.php` は以下の条件を満たすテナントにのみ `subscription_data[trial_period_days] => 30` を Stripe Checkout 生成時に付与します。

| 判定対象 | トライアル付与条件 |
|---|---|
| `tenants.subscription_status` | `'none'` |
| `tenants.stripe_subscription_id` | `NULL` または空文字列 |

両方成立 → トライアル 30 日付与 / どちらか不成立 → 即時課金開始（再契約扱い）

---

## 3.3 Stripe Billing設定

### 3.3.1 事前準備チェックリスト（POSLA 運営者）

新規 Stripe アカウントから Billing 運用を開始する場合、以下 7 項目を順番に確認してください。

| # | 確認項目 | NG時の対応 |
|---|---------|-----------|
| 1 | Stripe アカウントが本番モード（Live） | 「Test mode」トグルを OFF にする |
| 2 | プラットフォームの本人確認・事業者審査が完了 | Stripe Dashboard > 設定 > アカウント で「不足情報」を補完 |
| 3 | 商品カタログに3種類の Price ID が作成済み | 3.3.2 を実施 |
| 4 | Webhook エンドポイントが本番ドメインで登録済み | 3.3.4 を実施 |
| 5 | Customer Portal が有効化済み | Stripe Dashboard > 設定 > Customer Portal でアクティブ化 |
| 6 | `posla_settings` に Price / Secret / Webhook シークレット投入済み | 3.3.5 を実施 |
| 7 | テスト テナントで `trialing` → `active` までの一連フローが通る | 3.4 を実施 |

### 3.3.2 Stripe 上での商品作成手順（クリック単位）

1. [Stripe Dashboard](https://dashboard.stripe.com/) にプラットフォーム親アカウントでログイン
2. 左メニュー **「商品カタログ」** → **「商品を追加」** をクリック
3. 以下の3商品を作成：
   - **POSLA 基本料金** — 月額 ¥20,000（テナントの subscription に必ず1個含まれる）
   - **POSLA 追加店舗** — 月額 ¥17,000（quantity = 店舗数 - 1。1店舗のみのテナントは line item 自体を作らない）
   - **POSLA 本部一括配信アドオン** — 月額 ¥3,000（quantity = 店舗数。アドオン契約時のみ追加）
4. 各商品の価格IDを控える（`price_xxxx` 形式）
5. POSLA管理画面 `/public/posla-admin/dashboard.html` の「API設定」タブに登録

::: warning Stripe Dashboard 側で「無料試用期間」を設定してはいけません
Stripe 側で trial_period_days を商品の Price に設定すると、再契約時のテナントにもトライアルが付与されてしまいます。POSLA は申込みフロー側（`api/subscription/checkout.php`）で動的に付与しているため、Dashboard 側設定は **必ず空欄** にしてください。
:::

### 3.3.3 入力フィールド対応表

| キー（posla_settings.setting_key） | 値の例 | 取得元 |
|---|---|---|
| `stripe_secret_key` | `sk_live_51...` | Stripe Dashboard > 開発者 > API キー |
| `stripe_publishable_key` | `pk_live_51...` | 同上 |
| `stripe_webhook_secret` | `whsec_...` | Stripe Dashboard > 開発者 > Webhooks > 該当エンドポイント詳細 |
| `stripe_price_base` | `price_1S...` | 商品カタログ > POSLA 基本料金 > 価格 |
| `stripe_price_additional_store` | `price_1S...` | 商品カタログ > POSLA 追加店舗 > 価格 |
| `stripe_price_hq_broadcast` | `price_1S...` | 商品カタログ > POSLA 本部一括配信 > 価格 |
| `connect_application_fee_percent` | `1.0` | 自由設定（Connect 手数料率） |

### 3.3.4 Webhookの設定

1. Stripe Dashboard > **「開発者」** → **「Webhooks」** > **「エンドポイントを追加」**
2. URL に本番ドメインの webhook を入力：`https://eat.posla.jp/api/subscription/webhook.php`
3. 以下のイベントを選択（**5 イベント必須**）：
   - `checkout.session.completed`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
4. 作成後、**署名シークレット**（`whsec_xxx`）を控え、`posla_settings.stripe_webhook_secret` に登録
5. 「最近の試行」タブで `200 OK` が返ることを確認

### 3.3.5 設定画面での投入手順（POSLA 管理画面）

```
[POSLA 管理画面ログイン]
└─ /public/posla-admin/dashboard.html
   └─ 「API設定」タブ
      ├─ Stripe Secret Key 入力
      ├─ Stripe Publishable Key 入力
      ├─ Stripe Webhook Secret 入力
      ├─ Stripe Price Base 入力
      ├─ Stripe Price Additional Store 入力
      ├─ Stripe Price HQ Broadcast 入力
      └─ [保存] ボタン
```

保存後の確認 SQL：

```sql
SELECT setting_key, LEFT(setting_value, 12) AS preview
FROM posla_settings
WHERE setting_key LIKE 'stripe_%'
ORDER BY setting_key;
```

---

## 3.4 サブスクリプションのライフサイクル

### 3.4.1 新規契約（30日トライアル開始）— P1-35 Stage 1 実装済み

> **現状のスコープ**: 既存テナントの owner が「契約・プラン」タブから申込む経路のみサポート。未認証ユーザー向け LP / テナント自動作成は Stage 2 で実装予定。

#### サーバー側の処理フロー

1. 既存テナントの owner が owner-dashboard の **「契約・プラン」** タブを開く
2. 「本部一括メニュー配信アドオン」チェックボックスの ON/OFF を選択（店舗数は `stores` テーブルから自動算出）
3. **「30日間無料で始める」** ボタンをクリック
4. `POST /api/subscription/checkout.php` が呼ばれる
5. サーバー側で以下を実行：
   - `subscription_status NOT IN ('none', 'canceled')` なら `ALREADY_SUBSCRIBED` で 400 を返す
   - `build_alpha1_line_items()` で line_items を組み立て（base ×1 + additional_store ×(N-1) + 任意で hq_broadcast ×N）
   - 初回申込（`subscription_status === 'none'` or `stripe_subscription_id` 空）なら `subscription_data[trial_period_days] => 30` を Checkout に付与
6. Stripe Checkout セッション URL を返し、ブラウザがリダイレクト
7. オーナーが Stripe Checkout 画面でカード情報を入力し送信
8. `checkout.session.completed` Webhook が発火
9. POSLA が既存テナントの以下を更新：
   - `stripe_subscription_id` = Stripe の sub ID
   - `subscription_status` = `'trialing'`（Stripe の実 status をそのまま反映）
   - `current_period_end` = トライアル終了日（= 30日後）
   - `hq_menu_broadcast` = Stripe subscription items から動的に判定（アドオン契約時は 1）
10. 30日後に Stripe が自動課金を実行（`invoice.paid` + `customer.subscription.updated` Webhook 発火）
11. `customer.subscription.updated` で `subscription_status` が `'active'` に更新される

#### 画面遷移（ASCII）

```
┌────────────────────────┐    ┌────────────────────────┐    ┌────────────────────────┐
│ owner-dashboard        │    │ Stripe Checkout        │    │ owner-dashboard        │
│  契約・プラン タブ     │ ─▶ │  カード情報入力        │ ─▶ │  ?subscription=success │
│  [30日間無料で始める]   │    │  [トライアルを開始]     │    │  ● トライアル中 30日   │
└────────────────────────┘    └────────────────────────┘    └────────────────────────┘
                                        │
                                        ▼
                              ┌────────────────────────┐
                              │ Stripe Webhook         │
                              │  checkout.session.     │
                              │   completed            │
                              │  ↓                     │
                              │ POSLA: tenants UPDATE  │
                              └────────────────────────┘
```

### 3.4.2 ステータスの遷移

| ステータス | 意味 | UI 表示 |
|-----------|------|---------|
| `none` | サブスクリプション未設定 | 「未契約」 |
| `trialing` | 試用期間中 | 「● トライアル中 (残り N 日)」 |
| `active` | 有効（支払い済み） | 「● 有効 (次回請求 YYYY-MM-DD)」 |
| `past_due` | 支払い延滞 | 上部に**赤い警告バナー**「お支払いが失敗しました」 |
| `canceled` | キャンセル済み | 「キャンセル済み（YYYY-MM-DD）」、機能制限モード |

#### 状態遷移図

```
          [新規申込]
              │
              ▼
   none ──────────────▶ trialing
                            │
                       30日経過 + invoice.paid
                            ▼
                          active ◀──┐
                            │        │
                  invoice.payment_  │ カード情報更新
                  failed (7日後)     │ → invoice.paid
                            ▼        │
                        past_due ────┘
                            │
                  14日後 / 解約 / cancel_at_period_end 到達
                            ▼
                        canceled
```

### 3.4.3 Webhookイベントの処理

| イベント | POSLAの処理 |
|---------|------------|
| `checkout.session.completed` | 既存テナントの `stripe_subscription_id` / `subscription_status` / `current_period_end` / `hq_menu_broadcast` を更新。`extract_alpha1_state_from_subscription()` で Stripe の items 配列から α-1 状態を抽出 |
| `customer.subscription.updated` | 店舗数・アドオンの変更を反映。同じく `extract_alpha1_state_from_subscription()` で items を解析し、`hq_menu_broadcast` を動的に同期 |
| `customer.subscription.deleted` | `subscription_status` を `canceled` に更新。`hq_menu_broadcast` はリセットしない（再契約時に復元するため） |
| `invoice.paid` | 支払い成功のログ記録（`subscription_events` テーブルに記録） |
| `invoice.payment_failed` | 支払い失敗のログ記録 |

::: warning billing_mode=flexible への対応（P1-35 hotfix）
2026年以降の Stripe の新 `billing_mode=flexible` では `current_period_end` が subscription オブジェクトの top-level から消え、`items[].current_period_end` に移動しました。`extract_alpha1_state_from_subscription()` は以下の順でフォールバック読み取りを行います：

1. `$subData['current_period_end']` （旧形式）
2. `$subData['items']['data'][0]['current_period_end']` （新 flexible 形式）

この対応を忘れると webhook は 200 を返すが `tenants.current_period_end` が NULL のままになる。
:::

### 3.4.4 Webhookの検証

- HMAC-SHA256署名によるリクエスト検証（`api/lib/stripe-billing.php` 内の `verify_webhook_signature()`）
- タイムスタンプチェック（5分以内のリクエストのみ有効）
- 検証失敗は400エラーを返却
- 二重受信防止: `subscription_events.stripe_event_id` を UNIQUE INDEX 化（P1-11）

### 3.4.5 イベントログ（subscription_events）— P1-25 ハイブリッド堅牢化

全Webhookイベントは`subscription_events`テーブルに記録されます。

| フィールド | 説明 |
|-----------|------|
| `event_id` | UUID（POSLA 側） |
| `tenant_id` | 対象テナント（不明時は NULL 許容） |
| `event_type` | Webhookイベントタイプ |
| `stripe_event_id` | StripeのイベントID（**UNIQUE INDEX、P1-11**） |
| `data` | イベントデータ（JSON） |
| `created_at` | 受信日時 |

#### 監視 SQL（運営チーム向け）

```sql
-- 直近 24 時間の payment_failed
SELECT tenant_id, stripe_event_id, JSON_EXTRACT(data, '$.last_payment_error.message')
FROM subscription_events
WHERE event_type = 'invoice.payment_failed'
  AND created_at > NOW() - INTERVAL 1 DAY;

-- イベント受信頻度（テナント別）
SELECT tenant_id, event_type, COUNT(*) AS cnt
FROM subscription_events
WHERE created_at > NOW() - INTERVAL 7 DAY
GROUP BY tenant_id, event_type
ORDER BY cnt DESC;

-- 二重受信検知（UNIQUE INDEX があるので発生しないはずだが、念のため）
SELECT stripe_event_id, COUNT(*) FROM subscription_events
GROUP BY stripe_event_id HAVING COUNT(*) > 1;
```

---

## 3.5 構成変更

α-1 構成では「プラン変更」は存在しません。代わりに以下の3種類の構成変更が発生します。

### 3.5.1 店舗追加 — P1-36 自動同期実装済み

owner が owner-dashboard「店舗管理」タブから店舗を追加した場合：

1. `POST /api/owner/stores.php` が呼ばれる
2. `stores` テーブルに新規行を INSERT、`store_settings` にデフォルト行を INSERT
3. **P1-36 フック `_stores_sync_subscription()` が自動発火**
4. `sync_alpha1_subscription_quantity($pdo, $tenantId)` が実行される：
   - `tenants.subscription_status` が `none` / `canceled` ならスキップ
   - `stores` テーブルから有効な店舗数を COUNT
   - `get_stripe_config()` で price ID を取得
   - `build_alpha1_line_items()` で desired line_items を組み立て
   - Stripe API から現在の subscription を取得
   - 差分計算: 追加店舗 Price の quantity を desired と比較し、変更があれば `POST /v1/subscriptions/{id}` で `items[N][id]` + `items[N][quantity]` を送信
   - `proration_behavior=create_prorations` 付きで送信するため、差額（¥17,000/店舗）が**日割りで即時 proration invoice item として追加**される
5. Stripe から `customer.subscription.updated` Webhook が発火
6. webhook.php が `extract_alpha1_state_from_subscription()` で DB 側の `hq_menu_broadcast` を同期

**重要**: sync の失敗は店舗追加自体を止めない。`_stores_sync_subscription()` が try-catch で例外を握り潰し、`error_log` にのみ記録する。

### 3.5.2 店舗削除 — P1-36 自動同期実装済み

1. `DELETE /api/owner/stores.php?id=xxx` が呼ばれる
2. 論理削除：`stores.is_active = 0` に UPDATE
3. **P1-36 フック `_stores_sync_subscription()` が自動発火**
4. 上記と同じ流れで `sync_alpha1_subscription_quantity()` が追加店舗 Price の quantity を -1
5. `proration_behavior=create_prorations` により**日割りクレジットが発生し、次回請求書から差引**される

### 3.5.3 本部一括配信アドオンの追加・解除 — Stage 1 では手動運用

::: warning Stage 1 制限
本部一括配信アドオンの追加・解除専用 UI はまだ実装されていません。Stage 2 以降で owner-dashboard に専用 UI を実装予定です。

**現状の運用**: owner が「契約・プラン」タブの **「サブスクリプション管理 (Stripe)」** ボタンをクリック → Stripe Customer Portal で直接 line item を追加・削除する。Portal で変更があれば `customer.subscription.updated` Webhook が発火し、`extract_alpha1_state_from_subscription()` が `hq_menu_broadcast` 列を自動同期する。
:::

同期の流れ（Customer Portal 経由の場合）：

1. owner が Customer Portal で「本部一括配信」line item を追加 または 削除
2. Stripe が `customer.subscription.updated` Webhook を発火
3. webhook.php が `extract_alpha1_state_from_subscription()` を実行
4. items 配列に `stripe_price_hq_broadcast` が含まれているかで `has_hq_broadcast` を判定
5. `tenants.hq_menu_broadcast` を 0 / 1 に更新
6. owner-dashboard 次回アクセス時に「本部メニュー」タブの表示/非表示が切り替わる

### 3.5.4 月途中変更（proration）の日割り計算

```
proration金額 = (line item 単価 / 当月日数) × 残日数
```

#### 実例: 松の屋 3店舗 → 4店舗（2026-04-09 E2E テスト）

| タイミング | 状態 | 月額本体 | proration | 次回請求合計 |
|----------|------|---------|----------|------------|
| **追加前** | 3店舗 | ¥54,000 | — | ¥54,000 |
| **4月9日 追加** | 4店舗 | ¥71,000 | ¥17,000 × 25/30 ≒ ¥14,167 (5月4日請求まで) | ¥71,000 + ¥14,167 = ¥85,167 |
| **次月以降** | 4店舗 | ¥71,000 | — | ¥71,000 |

#### 実例: 4店舗 → 3店舗（即時削除）

| タイミング | 状態 | 月額本体 | proration | 次回請求合計 |
|----------|------|---------|----------|------------|
| **削除前** | 4店舗 | ¥71,000 | — | — |
| **削除直後** | 3店舗 | ¥54,000 | -¥17,000 × 残日数/30 | ¥54,000 - クレジット |

::: tip テナントにいくら課金されるかを事前に見たい
Stripe Dashboard > 該当 customer > 「次回請求のプレビュー」で proration 込みの金額を確認できます。営業チームから問い合わせがあった場合の根拠データとしてこれを使ってください。
:::

---

## 3.6 テナントデータベースのサブスクリプション関連フィールド

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `stripe_customer_id` | VARCHAR(50) | StripeのカスタマーID（`cus_xxx`） |
| `stripe_subscription_id` | VARCHAR(50) | StripeのサブスクリプションID（`sub_xxx`） |
| `subscription_status` | ENUM | `none` / `trialing` / `active` / `past_due` / `canceled` |
| `current_period_end` | DATETIME | 現在の課金期間の終了日 |
| `hq_menu_broadcast` | TINYINT(1) | **α-1 / P1-34** 本部一括配信アドオン契約フラグ（0=未契約、1=契約） |

::: tip plan_features テーブルについて
旧 standard / pro / enterprise 構成で使われていた `plan_features` テーブルは、α-1 移行後も rollback 安全性のためデータベース上に残置されています。`api/lib/auth.php` の `check_plan_feature()` は `hq_menu_broadcast` 以外のキーに対して常に `true` を返すため、`plan_features` テーブルの中身は実質参照されません。1〜2週間の安定運用後に物理削除予定です。
:::

::: warning tenants.plan カラムも事実上デッドフィールド（2026-04-19 時点）
`tenants.plan` カラム（ENUM 'standard'/'pro'/'enterprise'）もコード上に残っていますが、α-1 以降は **機能制限の判定には一切使われていません**（`check_plan_feature()` が参照しない）。`api/posla/tenants.php` L84-90 / L134 / L147 でデフォルト 'standard' として INSERT されますが、値が何であっても動作は変わりません。

posla-admin/dashboard.html L164-168 の **「プラン」ドロップダウン**（standard/pro/enterprise 選択肢）も同様に表示上残っていますが、実質効果なし。将来リリースで `tenants.plan` カラム削除・UI からのドロップダウン削除を予定しています。

現行の本当の機能差別化は `tenants.hq_menu_broadcast` のみ（アドオン契約の有無）。
:::

---

## 3.7 stripe-billing.php の主要関数

### 3.7.1 get_stripe_config($pdo)

`posla_settings` テーブルから `stripe_` で始まるキーを全件取得し、連想配列で返す。

```php
$config = get_stripe_config($pdo);
// $config['stripe_secret_key']
// $config['stripe_price_base']
// $config['stripe_price_additional_store']
// $config['stripe_price_hq_broadcast']
// $config['stripe_webhook_secret']
```

### 3.7.2 build_alpha1_line_items($config, $storeCount, $hasHqBroadcast)

α-1 構成の line_items 配列を組み立てる。Checkout・Subscription 更新で共通利用。

```php
// 例: 3店舗、本部配信なし
$items = build_alpha1_line_items($config, 3, false);
// → [
//      ['price' => 'price_base', 'quantity' => 1],
//      ['price' => 'price_additional_store', 'quantity' => 2],
//    ]

// 例: 5店舗、本部配信あり
$items = build_alpha1_line_items($config, 5, true);
// → [
//      ['price' => 'price_base', 'quantity' => 1],
//      ['price' => 'price_additional_store', 'quantity' => 4],
//      ['price' => 'price_hq_broadcast', 'quantity' => 5],
//    ]
```

### 3.7.3 extract_alpha1_state_from_subscription($subData, $config)

Stripe の subscription オブジェクトから α-1 状態（`store_count` / `has_hq_broadcast` / `current_period_end`）を抽出する。Webhook 受信時に DB 同期に使用。

`current_period_end` は前述の通り flexible 対応で 2 段階フォールバック。

### 3.7.4 sync_alpha1_subscription_quantity($pdo, $tenantId)

現在のテナント状態（店舗数、アドオン契約状況）から Stripe Subscription items を計算し、差分があれば `POST /v1/subscriptions/{sub_id}` で更新する。

呼び出し契機:
- テナントが新店舗を追加・削除したとき（`api/owner/stores.php`）
- アドオン ON/OFF 切替時（`api/subscription/portal.php` 経由）
- 月次 cron で全テナント差分監査（運用予定）

### 3.7.5 detect_legacy_plan()（デッドコード）

`stripe-billing.php` L244 にコメント付きで残置されている関数。旧 standard/pro/enterprise の判定。
**呼び出し元 0 件**。1〜2 週間の安定運用後に物理削除予定。

### 3.7.6 calculate_application_fee($amount, $feePercent)

Stripe Connect の手数料計算（4 章で詳述）。

```php
$fee = max(1, ceil($amount * $feePercent / 100));
```

例: ¥3,000 × 1.0% = ¥30

---

## 3.8 月次請求の流れ

### 3.8.1 自動請求のタイミング

- Stripe Subscription の `billing_cycle_anchor` に基づく
- 通常: 契約日から 30 日経過した同じ日（毎月）
- トライアル中（30 日間）は請求なし、31 日目に初回課金
- 月途中の変更（店舗追加/削除/アドオン）は proration として次回請求書に合算

### 3.8.2 請求失敗時のリトライスケジュール

| 経過 | 状態変化 | テナント側の挙動 | POSLA側の挙動 |
|------|---------|----------------|--------------|
| 課金日 | `invoice.payment_failed` | 警告メール受信 | DB に subscription_events 記録 |
| **1日後** | Stripe が1回目の自動再試行 | （メールなし） | （成功なら active 維持） |
| **3日後** | Stripe が2回目の自動再試行 | 警告メール再送 | |
| **5日後** | Stripe が3回目の自動再試行 | 警告メール再送 | |
| **7日後** | 最終再試行で失敗 | 状態が `past_due` に | 画面上部に **赤い警告バナー** |
| **14日後** | Stripe がサブスクリプションを停止 | 状態が `canceled` に | 機能制限モードに移行 |

::: tip Stripe 側のリトライ設定
上記スケジュールは Stripe Dashboard > 設定 > 課金 > **「サブスクリプションと請求書」** で運営側がカスタマイズ可能です。POSLA のデフォルトはこの 7 日 / 14 日設定です。
:::

### 3.8.3 請求書の発行

- Stripe が自動で PDF 請求書を発行
- お客様メールに自動送信
- POSLA 側からは Customer Portal 経由でダウンロード可
- 請求書番号フォーマット: `POSLA-YYYY-MM-XXXXX`（Stripe 側で自動採番）

### 3.8.4 past_due でも使える機能・止まる機能

| 機能 | past_due | canceled |
|------|---------|---------|
| ログイン | ✅ | ✅ |
| 注文受付・KDS・レジ | ✅ | ❌ |
| メニュー編集 | ✅ | ❌ |
| レポート閲覧 | ✅ | ✅（解約後30日間） |
| データエクスポート（CSV） | ✅ | ✅（解約後30日間） |
| お客様セルフメニュー | ✅ | ❌ |
| 新規予約受付 | ✅ | ❌ |

---

## 3.9 解約フロー

### 3.9.1 通常解約

1. オーナーが owner-dashboard → 契約・プラン → **「サブスクリプション管理 (Stripe)」**
2. Stripe Customer Portal で「サブスクリプションをキャンセル」
3. Stripe が `cancel_at_period_end=true` 設定
4. 期間末（次回請求日の前日）に自動停止
5. POSLA で `subscription_status = canceled` に更新
6. テナント `is_active=1` のまま、ただし subscription 機能停止
7. 30 日間データ保持後、cleanup スクリプトで物理削除（運営判断）

### 3.9.2 即時解約（特例）

- POSLA 運営の判断で `tenants.is_active=0` に即更新
- 全ユーザー即時ログイン不可
- 通常運用ではしない（顧客対応上）

#### 即時解約 SQL

```sql
UPDATE tenants SET is_active = 0 WHERE id = 't-xxx';
-- 同時に Stripe 側で subscription を即時 cancel する場合
-- → Stripe Dashboard で当該 customer の subscription を「即座にキャンセル」
```

### 3.9.3 解約予約のキャンセル（ロールバック）

期間終了日までの間に「やっぱり解約しない」と決めたら、Customer Portal で解約予約を取り消せる。

1. Customer Portal で「キャンセルを取り消す」
2. Stripe が `cancel_at_period_end=false` に戻す
3. POSLA 側は次回 `customer.subscription.updated` Webhook で `subscription_status='active'` に戻る

### 3.9.4 解約後の再契約

30 日以内なら同じテナント・同じユーザーで再契約可能。30 日超なら新規申込必要。

| 経過時間 | テナントデータ | 再契約時の挙動 |
|---|---|---|
| 0〜30日 | 残置 | 即課金（トライアルなし）、`hq_menu_broadcast` フラグも復元 |
| 30日超 | 物理削除済み | 新規テナント作成扱い |

---

## 3.10 トラブルシューティング

### 3.10.1 症状別フローチャート

```
[症状を特定]
├─ 「30日間無料で始める」を押すとエラーが出る
│   ├─ ALREADY_SUBSCRIBED → 3.10.2
│   ├─ NO_STORES → 3.10.3
│   ├─ PRICE_NOT_CONFIGURED → 3.10.4
│   └─ INVALID_PLAN → 3.10.5
├─ 申込み完了したのに状態が反映されない
│   ├─ 状態が「未契約」のまま → 3.10.6
│   ├─ 店舗数が違う → 3.10.7
│   └─ 次回請求日が空白 → 3.10.8
├─ 店舗追加・削除しても Stripe に反映されない → 3.10.9
├─ 本部メニュータブが出ない / 消えない → 3.10.10
└─ Stripe Customer Portal が開かない → 3.10.11
```

### 3.10.2 ALREADY_SUBSCRIBED エラー

**症状**: `POST /api/subscription/checkout.php` が 400 `ALREADY_SUBSCRIBED` を返す。

**原因**: `tenants.subscription_status` が `trialing` / `active` / `past_due` のいずれか。

**確認 SQL**:
```sql
SELECT id, subscription_status, stripe_subscription_id, current_period_end
FROM tenants WHERE id = 't-xxx';
```

**対処**: 既存 subscription を Customer Portal で解約 → 再申込み、または 3.10.6 / 3.10.7 で DB 同期を確認。

### 3.10.3 NO_STORES エラー

**症状**: `NO_STORES` (400) `店舗を1つ以上登録してください` が返る。

**原因**: `stores.is_active=1` が0件。

**対処**: テナントに「店舗管理」タブから店舗を1つ以上追加するように案内。

### 3.10.4 PRICE_NOT_CONFIGURED エラー

**症状**: `PRICE_NOT_CONFIGURED` (500)。

**原因**: `posla_settings` の Price ID 不足（`stripe_price_base` / `stripe_price_additional_store` / `stripe_price_hq_broadcast`）。

**確認 SQL**:
```sql
SELECT setting_key FROM posla_settings WHERE setting_key LIKE 'stripe_price_%';
```

**対処**: 3.3.5 を再実施。

### 3.10.5 INVALID_PLAN エラー（旧 standard/pro/enterprise 由来）

**症状**: 古いスクリプトが `plan=standard` を投げて `INVALID_PLAN` が返る。

**原因**: 2026-04-09 の P1-35 で `plan` パラメタは廃止。チェックアウト API は `{ hq_broadcast: bool }` のみ受け付ける。

**対処**: リクエスト body から `plan` を削除し、必要なら `hq_broadcast` のみ送信。

### 3.10.6 申込み完了後も状態が「未契約」のまま

**確認 1**: Stripe Dashboard > Webhooks > 該当エンドポイント > 「最近の試行」で 200 OK が出ているか。

**確認 2**: サーバー error_log

```bash
ssh odah@odah.sakura.ne.jp 'tail -100 ~/www/eat-posla/_logs/php_errors.log | grep -i webhook'
```

**確認 3**: subscription_events に記録されているか

```sql
SELECT * FROM subscription_events
WHERE tenant_id = 't-xxx'
ORDER BY created_at DESC LIMIT 10;
```

**対処**: Stripe Dashboard で webhook を「再試行」、または手動で `tenants` 行を UPDATE。

### 3.10.7 店舗数が Stripe 側と乖離

**症状**: 「契約・プラン」表示の店舗数と `stores.is_active=1` の COUNT が違う。

**確認**:
```bash
mysql -e "SELECT tenant_id, COUNT(*) FROM stores WHERE is_active=1 GROUP BY tenant_id;"
```

**対処**: `sync_alpha1_subscription_quantity($pdo, 't-xxx')` を手動実行。

```bash
ssh odah@odah.sakura.ne.jp 'cd ~/www/eat-posla && php -r "
  require \"api/lib/db.php\";
  require \"api/lib/stripe-billing.php\";
  sync_alpha1_subscription_quantity(get_db(), \"t-xxx\");
"'
```

### 3.10.8 current_period_end が NULL のまま（P1-35 hotfix 関連）

**原因**: billing_mode=flexible への対応漏れ（P1-35 hotfix 適用前のサブスクリプション）。

**対処（手動 backfill）**:
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

### 3.10.9 店舗追加・削除が Stripe に反映されない

**確認**:
```bash
ssh odah@odah.sakura.ne.jp 'tail -200 ~/www/eat-posla/_logs/php_errors.log | grep _stores_sync'
```

**よくある原因**:
- `tenants.subscription_status` が `none` / `canceled` → 同期スキップ（仕様通り）
- Stripe API 一時障害（HTTP 5xx）→ 復旧後に手動再同期
- Stripe シークレットキーの期限切れ・無効化

### 3.10.10 本部メニュータブが出ない / 消えない

**原因**: Customer Portal でのアドオン追加・解除後、Webhook 反映前のキャッシュ。

**対処**:
1. ブラウザのキャッシュクリア + 強制再読込
2. `SELECT id, hq_menu_broadcast FROM tenants WHERE id='t-xxx';` で DB 値を確認
3. Stripe 側 line items との乖離があれば 3.10.7 の手動同期スクリプト実行

### 3.10.11 Stripe Customer Portal が開かない

**確認**:
- `tenants.stripe_customer_id` が NULL → 申込みが未完了
- ブラウザの開発者ツール > ネットワークで `/api/subscription/portal.php` レスポンスを確認

**対処**:
- Stripe Dashboard > 設定 > Customer Portal でアクティブ化
- ポップアップブロックを許可

### 3.10.12 監視に使うログコマンド集

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

---

## 3.11 FAQ（POSLA 運営者向け 30 件）

### A. プラン構成・価格

**Q1. 旧プラン（standard / pro / enterprise）はもう使えないのですか？**
A. はい、2026-04-09 をもって廃止。`tenants.plan` カラムは残置されているがコードからは参照されない。

**Q2. テナントが「もっと安いプランは無いか」と聞いてきました**
A. ありません。α-1 構成は単一プランのみ。基本料金 ¥20,000 が下限。

**Q3. 1店舗だけ運営する場合、月額はいくらですか？**
A. 基本料金 ¥20,000 のみ。追加店舗料金は発生しない。

**Q4. 本部一括配信アドオンは1店舗でも契約できますか？**
A. 仕様上は可能だが、機能の性質上「複数店舗を運営し本部から一括配信したい」ケースを想定しているため、1店舗での契約は推奨しない。

**Q5. 営業値引き（特別割引）は技術的に可能ですか？**
A. 可能。Stripe Dashboard > Coupons で割引を発行し、当該テナントの subscription に手動付与する。POSLA UI からの付与はできない。

### B. トライアル

**Q6. トライアル期間中にカードに課金されますか？**
A. されない。31日目から自動課金開始。

**Q7. トライアル期間中に解約したらどうなりますか？**
A. 一切課金されない。

**Q8. トライアル期間を延長できますか？**
A. テナント側からはできない。POSLA 運営が Stripe Dashboard > 該当 subscription > 「Trial end」を手動で延長することは可能（営業判断）。

**Q9. 1度トライアルを使ったテナントは再度トライアルできますか？**
A. できない。`subscription_status='none'` かつ `stripe_subscription_id` が空のときのみ自動付与される。

**Q10. テストテナントでトライアルをリセットしたい**
A. SQL で以下を実行：
```sql
UPDATE tenants SET subscription_status='none', stripe_subscription_id=NULL,
       current_period_end=NULL WHERE id='t-test-xxx';
```
ただし Stripe 側の subscription も削除しないと整合性が崩れる。

### C. 構成変更

**Q11. 店舗追加・削除は何回でもできますか？**
A. 制限なし。各操作で proration が発生。

**Q12. 店舗を一度に複数追加した場合、proration はどう計算されますか？**
A. 1店舗ずつフックが発火するので、個別に proration line item が作成される。最終的な合計は同じ。

**Q13. 本部一括配信アドオンを月末に追加したら丸1ヶ月分課金されますか？**
A. されない。proration により残日数分のみ日割り課金。例：月末1日前に追加なら ¥3,000 × 1/30 ≒ ¥100/店舗。

**Q14. 店舗を削除したのに Customer Portal で line item が残っているのはなぜですか？**
A. 「POSLA 追加店舗」line item は quantity が 0 になっても残る（Stripe 仕様）。quantity 0 の line item は課金対象外。

**Q15. アドオンを契約期間中に何回もON/OFFされたら困らないですか？**
A. 都度 proration が発生する。Stripe API 呼び出しが増えるが堅牢性は問題なし。

### D. 解約・再契約

**Q16. 解約したらデータは消えますか？**
A. すぐには消えない。30日間レポート閲覧・データエクスポート可能。30日経過後は POSLA 運営判断で物理削除。

**Q17. 解約後に再契約した場合、過去のデータは復活しますか？**
A. 30日以内なら復活可能。30日超で物理削除後は復活不可。

**Q18. 解約予定日を過ぎる前に取り消せますか？**
A. はい。Customer Portal で「キャンセルを取り消す」をクリック。

**Q19. テナントが「即時解約してほしい」と言ってきました**
A. Customer Portal で `cancel_at_period_end=true` のままだと期間末まで残る。即時にしたい場合は Stripe Dashboard で「即座にキャンセル」を選び、POSLA 側は webhook で自動的に `canceled` になる。または運営が手動で `tenants.is_active=0` に。

**Q20. 解約後の再契約時、トライアル30日は付与されますか？**
A. されない。トライアルは1テナント1回限り（`subscription_status='none'` AND `stripe_subscription_id` 空のときのみ）。

### E. 支払い・請求書

**Q21. 請求書 PDF はどこからダウンロードできますか？**
A. Stripe Customer Portal の「請求履歴」セクション。

**Q22. 領収書は発行されますか？**
A. Stripe Customer Portal で発行可能。請求書 PDF と領収書 PDF は別物（日本の経理慣習に対応）。

**Q23. 支払いに失敗するとすぐに使えなくなりますか？**
A. すぐにはならない。Stripe が7日間自動再試行 → 失敗で `past_due` → 14日で `canceled`。

**Q24. 支払い方法を銀行振込にできますか？**
A. 現状はクレジットカードのみ。銀行振込・コンビニ払いは Stripe の Invoicing 機能で対応可能だが POSLA UI からは未実装。

**Q25. テナントが「請求書の宛名が違う」と言ってきました**
A. Stripe Customer Portal の「アカウント情報」で宛名・住所変更可能。Customer Portal は POSLA owner ロール本人がアクセスできる。

### F. 技術・運用

**Q26. テナントの subscription_status を手動で書き換えていいですか？**
A. 緊急時のみ可。書き換え後は必ず Stripe 側と整合性を取ること。`extract_alpha1_state_from_subscription()` を使った再同期スクリプトの実行を推奨。

**Q27. plan_features テーブルは削除していいですか？**
A. 1〜2週間の安定運用を確認してから削除。現状は rollback 安全性のため残置。

**Q28. Webhook が届かない場合のリトライ間隔は？**
A. Stripe が指数バックオフで最大 3 日間リトライ。それ以降は手動「再試行」が必要。

**Q29. POSLA プラットフォームの Stripe アカウント自体を別アカウントに移管したい**
A. 大規模作業。新アカウントで Price ID を再作成 → posla_settings 更新 → 全テナントの Customer / Subscription を新アカウントに「Customer Migrate」する必要あり。Stripe サポートと連携。

**Q30. テスト環境で本番と同じ webhook を受信したい**
A. Stripe CLI の `stripe listen --forward-to localhost:8080/api/subscription/webhook.php` を使う。テスト用の `whsec_xxx` が CLI 起動時に表示されるので `posla_settings` のテスト環境用エントリに登録。

---

## 3.12 返金処理の堅牢化（2026-04-19 S3 修正 #10）

`POST /api/store/refund-payment.php` に以下の排他制御を導入:

- `BEGIN` トランザクション開始
- `SELECT ... FROM payments WHERE id=? AND store_id=? FOR UPDATE` で対象行をロック
- 現状 `refund_status` を確認 → 既に `pending` または `refunded` なら `ALREADY_REFUNDED` (409) で拒否
- `UPDATE payments SET refund_status='pending'` で中間状態に遷移（他リクエストはここで弾かれる）
- Stripe Refund API 呼び出し
- 成功時: `refund_status='refunded'` に更新 + `COMMIT`
- 失敗時: `refund_status` を元に戻して `ROLLBACK`

新マイグレーション: `sql/migration-s3-refund-pending.sql`（`payments.refund_status` ENUM に `'pending'` を追加、適用済）。

**運用への影響**:
- 同じ payment に対して 2 回連続で「返金実行」を押しても、2 回目は `ALREADY_REFUNDED` で弾かれる（フロント側 `_refunding` フラグ + サーバー側 `pending` 状態の二重防御）
- Stripe API 呼び出し中に POSLA サーバーがクラッシュすると `pending` のまま残る → 復旧手順は手動で `payments.refund_status` を確認し、Stripe Dashboard で実際の返金有無を照合してから手動更新

詳細: [tenant 18 章 18.W.3](../tenant/18-security.md#18w-s3-セキュリティ修正一式2026-04-19)

---

## X.X 更新履歴
- **2026-04-19 (S3 セキュリティ修正)**: 3.12 を新設。refund-payment.php に `BEGIN + SELECT FOR UPDATE + pending 中間状態` の排他制御を追加。新マイグレーション `migration-s3-refund-pending.sql` 適用済。
- **2026-04-19**: フル粒度展開（チェックリスト / 状態遷移図 / proration 実例 / 監視 SQL / トラブル症状別 / FAQ 30件）。3.3 事前準備チェックリスト追加、3.4 ライフサイクル ASCII 図追加、3.10 トラブルシューティング章新設、3.11 FAQ 章新設
- **2026-04-19**: 3.7 主要関数 / 3.8 月次請求 / 3.9 解約フロー 追加
- **2026-04-18**: frontmatter 追加、AIヘルプデスク knowledge base として整備
