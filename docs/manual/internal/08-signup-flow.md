---
feature_id: A5
title: 新規申込フロー (LP + 自動アクティベーション + on-demand cell)
chapter: 08
audience: POSLA 運営チーム (内部)
keywords: [新規申込, LP, Stripe Checkout, 自動アクティベーション, on-demand cell, テナント作成]
related: [02-onboarding, 03-billing]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 8. 新規申込フロー (A5)

POSLA の LP (ランディングページ) から申込 → Stripe 決済 → 30日無料トライアル開始 → **on-demand cell provisioner** で専用 cell 作成 → オーナーがログイン可能になる一気通貫フロー。

::: warning これは社内向け資料です
テナントは読まなくて OK。運用開始後のサポート対応に必要な知識です。
:::

---

## 8.1 LP (ランディングページ)

- URL (本番): `https://<production-domain>/`
- 擬似本番確認: `http://127.0.0.1:8081/`
- 構成: ヒーロー → 機能一覧 → 料金 → 申込フォーム

### LP のファイル

- `public/index.html` — LP 全体
- `public/css/lp.css` — スタイル
- `public/signup-complete.html` — 決済成功後画面

---

## 8.2 申込フローの全体像

```
[1] LP → 申込フォーム入力
      ↓ POST /api/signup/register.php
[2] サーバー側バリデーション
      ・必須項目 / フォーマット
      ・ユーザー名 / メール重複チェック
      ・パスワード強度チェック (P1-5)
      ↓
[3] DB レコード仮作成 (is_active=0)
      ・tenants (subscription_status='none')
      ・stores (1店舗目)
      ・store_settings
      ・users (role='owner')
      ↓
[4] Stripe Customer 作成
      ↓
[5] Stripe Checkout Session 作成 (30日トライアル)
      ・metadata.new_signup = '1' / tenant_id / signup_token
      ↓
[6] Stripe Checkout 画面に遷移 → カード登録
      ↓
[7a] 成功 → success_url (signup-complete.html) へ
      ↓ POST /api/signup/activate.php { signup_token }
      ・tenants.is_active=1, subscription_status='trialing'
      ・stores.is_active=1
      ・users.is_active=1
      ・onboarding request を ready_for_cell へ更新
      ↓
[7b] Stripe Webhook (冗長化)
      POST /api/signup/webhook.php
      ・同じ処理を冪等に実行
      ↓
[8] 本番 provisioner ホスト
      php scripts/cell/provision-ready-cells.php --limit=1
      ・cell init / build / deploy / onboard / smoke
      ・control registry を active へ更新
      ↓
[9] signup-complete.html が準備完了を検知し、cell のログインURLを表示
```

---

## 8.3 API エンドポイント

### POST /api/signup/register.php

**認証不要**。LP フォームから呼ばれる。

**リクエスト** (2026-04-19 改修後 / B 案):
```json
{
  "tenant_name": "株式会社松の屋",
  "store_name": "渋谷店",
  "owner_name": "田中 太郎",
  "phone": "090-1234-5678",
  "email": "taro@example.com",
  "username": "tanaka",
  "password": "Password123",
  "address": "東京都渋谷区..." (任意),
  "store_count": 1,
  "hq_broadcast": false
}
```

**フィールドの DB 反映先**:

| パラメータ | 反映先カラム | 例 | 必須 |
|---|---|---|---|
| `tenant_name` | `tenants.name` | 株式会社○○ / 松の屋 | ✅ (新) |
| `store_name`  | `stores.name` / `store_settings.receipt_store_name` | 渋谷店 / 本店 | ✅ |
| `owner_name`  | `users.display_name` | 田中 太郎 | ✅ |

**後方互換 (旧 LP 互換)**:
- `tenant_name` を **未指定** で送信した場合、サーバー側で `store_name` を `tenants.name` にも流用します（`api/signup/register.php` 47 行目: `$tenantName = ... ?: $storeName;`）
- 旧 LP（`store_name` のみ）からのリクエストは引き続き受け付けます
- 新 LP は `tenant_name` を必ず送るので、企業名と店舗名が別文字列で記録されます

**LP フォームの見え方 (ASCII モック)**:

```
┌─────────────────────────────────────────────┐
│ 30日間無料ではじめる                         │
├─────────────────────────────────────────────┤
│ 企業名 (屋号) *                              │
│ [ 株式会社○○ / 松の屋               ]       │
│                                             │
│ 1 店舗目の店舗名 *                           │
│ [ 渋谷店 / 本店                      ]       │
│                                             │
│ 代表者名 *                                   │
│ [ 田中 太郎                          ]       │
│                                             │
│ 電話番号 *      | メールアドレス *           │
│ [ 090-...    ] | [ taro@example.com ]       │
│ ...                                         │
└─────────────────────────────────────────────┘
```

**レスポンス**:
```json
{
  "ok": true,
  "data": {
    "tenant_id": "eef2bc54...",
    "checkout_url": "https://checkout.stripe.com/...",
    "signup_token": "d9f9c3b1..."
  }
}
```

**エラー**:
- `MISSING_FIELDS` / `INVALID_EMAIL` / `INVALID_USERNAME` / `INVALID_PHONE`
- `WEAK_PASSWORD` (パスワードポリシー違反)
- `USERNAME_TAKEN` / `EMAIL_TAKEN` (重複)
- `STRIPE_NOT_CONFIGURED` / `PRICE_NOT_CONFIGURED` (POSLA 側設定不備)
- `STRIPE_ERROR` (Stripe 側エラー)

### POST /api/signup/activate.php

**認証不要**。success_url 到達時にフロントから呼ばれる。

**リクエスト**:
```json
{ "signup_token": "d9f9c3b1..." }
```

**処理**:
- `tenants.stripe_subscription_id = 'pending:' + token` で検索
- 見つかれば is_active=1 に更新 + メール送信
- 見つからなければ (Webhook で既に処理済) `already_activated: true` を返す
- 冪等 (複数回呼んでも OK)

### POST /api/signup/webhook.php

**Stripe Webhook エンドポイント**。POSLA 管理画面での登録が必要。

**登録 URL**:
```
https://<production-domain>/api/signup/webhook.php
```

**購読イベント**:
- `checkout.session.completed` — メタデータに `new_signup='1'` があれば新規申込として処理

**処理**: activate.php と同じ冪等処理。

### POST /api/signup/provisioning-status.php

**認証不要**。`signup-complete.html` から `signup_token` で呼ばれ、専用cellの準備状況を返します。

**レスポンス例**:
```json
{
  "ok": true,
  "data": {
    "status": "preparing",
    "onboarding_status": "cell_provisioning",
    "message": "専用環境を作成しています。完了後、この画面にログインボタンが表示されます。",
    "login_url": null,
    "refresh_after_ms": 5000
  }
}
```

`status=ready` になると `login_url` に対象cellのログインURLが入ります。

---

## 8.4 on-demand cell provisioner

Stripe webhook / activate は deploy を直接実行しません。Webhookは短時間で返し、`ready_for_cell` へ更新した直後に、本番 provisioner ホスト上の localhost trigger service へ通知します。通常フローでは 1分ごとの timer / cron で巡回しません。

```bash
POSLA_PROVISIONER_TRIGGER_SECRET='<secret>' \
  php scripts/cell/provisioner-trigger-server.php
```

Web 側には同じ secret と trigger URL を設定します。

```bash
POSLA_PROVISIONER_TRIGGER_URL='http://127.0.0.1:19091/run'
POSLA_PROVISIONER_TRIGGER_SECRET='<secret>'
```

Docker 擬似本番からホスト側 trigger service を呼ぶ場合だけ、URL は `http://host.docker.internal:19091/run` にします。

trigger service は POST `/run` を受けた時だけ `php scripts/cell/provision-ready-cells.php --request-id=<id>` をバックグラウンド起動します。LP申込だけでなく、POSLA管理画面の「テナント新規作成」も同じ trigger service を呼びます。サービスの常駐には systemd service 等を使いますが、systemd timer / cron は通常フローでは使いません。ローカルMacを販売フローの一部にはしません。

本番では `POSLA_CELL_APP_URL_PATTERN` を必ず設定します。未設定の production 環境では、localhost のログインURLを作らないよう provisioner が停止します。

```text
ready_for_cell
  ↓
cell_provisioning
  ↓ init / build / deploy / onboard-tenant / smoke
  ↓
active
```

`--dry-run` を付けると、実行予定のコマンドだけを確認できます。

---

## 8.5 サポート対応シナリオ

### ケース A: 「申込後にログインできない」

原因候補:
1. Webhook 未登録 + success_url にリダイレクトされずタブを閉じた → is_active=0 のまま
2. activate.php が失敗 (Stripe API 側の遅延等)

対応:
1. `SELECT * FROM users WHERE email = '{client_email}' AND is_active = 0;`
2. 該当ユーザーがいれば手動で有効化:
```sql
UPDATE users SET is_active=1 WHERE id='xxx';
UPDATE stores SET is_active=1 WHERE tenant_id='xxx';
UPDATE tenants SET is_active=1, subscription_status='trialing' WHERE id='xxx';
```
3. ウェルカムメールを再送 (手動 / スクリプト)

### ケース B: 「Stripe 決済画面で止まった」

- Stripe Dashboard で該当 Customer を検索
- checkout.session.expired イベントを確認
- 必要なら新しい Checkout URL を発行 (手動)

### ケース C: 「重複登録してしまった」

- username / email 重複は API 側で弾かれる
- それでも複数 tenant ができた場合、古い方を `is_active=0` にして運用

---

## 8.6 データクリーンアップ

申込途中で放棄されたテナント (is_active=0 のまま N 日経過) は定期的に削除:

```sql
DELETE FROM users WHERE tenant_id IN (
  SELECT id FROM tenants WHERE is_active=0 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
);
DELETE FROM store_settings WHERE store_id IN (
  SELECT id FROM stores WHERE tenant_id IN (
    SELECT id FROM tenants WHERE is_active=0 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
  )
);
DELETE FROM stores WHERE tenant_id IN (
  SELECT id FROM tenants WHERE is_active=0 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
);
DELETE FROM tenants WHERE is_active=0 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

※ 30 日は運用後調整。

---

## 8.7 トライアル管理

- Stripe Subscription `trial_period_days=30` で自動管理
- 31 日目に自動で初回課金
- トライアル中のキャンセルは Customer Portal から (既存 `api/subscription/portal.php`)

---

## 8.8 申込フローの完全シーケンス図

```
[お客様]                  [LP]                    [POSLA API]              [Stripe]
   │                       │                          │                       │
   ├──── 1. LP 開く ──────→ │                          │                       │
   │                       │                          │                       │
   │ ←─ 2. フォーム表示 ───┤                          │                       │
   │                       │                          │                       │
   ├── 3. 入力・送信 ─────→ ├── 4. POST /api/signup/register.php ─→            │
   │                       │                          │                       │
   │                       │                          ├── 5. レコード作成 ───→ │
   │                       │                          │   (tenants is_active=0,│
   │                       │                          │    users is_active=0)  │
   │                       │                          │                       │
   │                       │                          ├── 6. Stripe Checkout ─→
   │                       │                          │       Session 作成     │
   │                       │                          │                       │
   │ ←──── 7. Stripe Checkout URL に redirect ────────┤                       │
   │                       │                          │                       │
   ├── 8. Stripe で決済 ───────────────────────────────────────────────────────→
   │   (カード情報入力)                                                        │
   │                                                                          │
   │ ←──── 9. checkout.session.completed → POSLA Webhook 受信 ───────────────│
   │                                  │                                       │
   │                                  ├── 10. tenants.is_active=1            │
   │                                  │       users.is_active=1               │
   │                                  │       専用環境準備中メール送信         │
   │                                  │                                       │
   │ ←─── 11. signup-complete.html へ redirect ────                          │
   │       provisioning-status.php を polling                                │
   │                                  │                                      │
   │                                  ├── 12. activate.php 確認・冪等         │
   │                                  │       (Webhook 失敗時のフォールバック) │
   │                                  │                                       │
   │                                  ├── 13. provision-ready-cells.php       │
   │                                  │       cell init/build/deploy/smoke    │
   │                                  │                                       │
   │ ←─── 14. ログインURL表示 / メール ─┤                                    │
   │       (cell URL + ユーザー名 + パスワード)                              │
   │                                                                          │
   ├── 15. ログイン ───────→ /api/auth/login.php → ダッシュボード             │
```

## 8.9 各 API の冪等性設計

### 8.8.1 register.php

- 同一 username で 2 回目 → 409 DUPLICATE_USERNAME
- 同一 email で 2 回目 → 409 DUPLICATE_EMAIL
- レートリミット: 5 req/h（IP ベース）
- 失敗時は INSERT がロールバックされる（トランザクション）

### 8.8.2 activate.php

- 既に `is_active=1` でも 200 OK 返却（冪等）
- signup_token が無効な場合は 401
- 何度呼んでも副作用なし

### 8.8.3 webhook.php

- Stripe からの再送（最大 3 日間）に対して冪等
- `tenants.subscription_id` でテナント特定
- 既に有効化済みのテナントは何もしない

---

## 8.10 メールテンプレート

### カード登録直後

この時点ではまだcell作成中のため、ログインURLは送らない。

```
件名: 【POSLA】お申込みありがとうございます — 専用環境を準備しています

[オーナー名] 様

POSLA にご登録いただきありがとうございます。
30日間無料トライアルを開始しました。

現在、専用環境を準備しています。通常 3〜5 分ほどで完了します。

■ 店舗名: [店舗名]
ユーザー名: [username]
パスワード: ご登録時に設定されたもの
準備状況: https://<production-domain>/signup-complete.html?t=[signup_token]

準備完了後、ログインURLをメールでお送りします。
```

### 専用環境準備完了後

```
件名: 【POSLA】専用環境の準備が完了しました

[オーナー名] 様

POSLA の専用環境の準備が完了しました。

■ ログイン情報
URL: https://[cell-domain]/admin/index.html
ユーザー名: [username]
パスワード: お申込み時に設定されたもの

■ 次のステップ
1. 上記 URL からログイン
2. パスワードを変更（画面右上の「パスワード変更」ボタン）
3. 店舗設定・メニュー登録を進める

■ ご注意
- 31 日目から自動的に月額 ¥20,000 課金開始
- 期間内キャンセルで請求なし
- ご質問は info@posla.jp まで

POSLA 運営チーム（プラスビリーフ株式会社）
```

---

## 8.11 異常系の対応

### 8.10.1 Stripe Checkout を閉じた場合

- 注文が `is_active=0` のまま残る
- 30 日後の cleanup スクリプトで削除
- 顧客が再度サインアップしたら新規 tenant を作成

### 8.10.2 Webhook 受信失敗

- Stripe は最大 3 日間リトライ
- それでも失敗した場合、`api/signup/activate.php` のフォールバックが補完
- `signup-complete.html` ページで JS から activate を呼んでいる

### 8.10.3 メール送信失敗

- `monitor_events` に `mail_send_failure` 記録
- 連続失敗で運営に Slack 通知
- 手動でメール再送する手順は `internal/06-troubleshoot.md` 参照

---

## 更新履歴

- **2026-04-19**: 8.3 申込フォームを「企業名 + 1 店舗目の店舗名」2 フィールドに分離（A5 改修）。`tenant_name` パラメータ追加 + ASCII モック + DB 反映先表 + 後方互換挙動を追記
- **2026-04-19**: 8.7 シーケンス図 / 8.8 冪等性 / 8.9 メールテンプレート / 8.10 異常系 追加
- **2026-04-18**: A5 実装完了 (LP + 自動アクティベーション)
- **2026-04-26**: 1 tenant / 1 cell 方針に合わせ、カード登録後は on-demand cell provisioner が専用環境を作成し、完了後にログインURLを表示する流れへ更新
