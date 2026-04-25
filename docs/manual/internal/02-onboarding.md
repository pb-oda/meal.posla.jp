---
title: テナントオンボーディング
chapter: 02
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [onboarding, 運営, POSLA]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 2. テナントオンボーディング

新規テナント（契約企業）の立ち上げ手順です。

::: warning この章は POSLA 運営（社内）専用
本書はテナント（飲食店）向けではありません。技術詳細・DB 構造・SQL 例・Stripe API レスポンス例を多用しています。テナントから問い合わせがあった場合の対応手順を中心に書かれています。
:::

::: tip 自動オンボーディング vs 手動オンボーディング
2026-04-18 より、LP（`/`）からの **完全自動申込フロー**（`api/signup/register.php`）が稼働しています。POSLA 運営の手動オペレーションは **緊急対応・特殊ケース・過去テナントの修復** 用途のみとなりました。本章は両方を扱います。
:::

---

## 2.0 はじめにお読みください（運営者の事前準備）

### 2.0.1 オンボーディング前のチェックリスト（10項目）

新規テナントを正しく立ち上げるため、以下10項目を確認してください。

| # | 確認項目 | 確認方法 | NG時の対応 |
|---|---------|---------|-----------|
| 1 | **POSLA 管理画面にログイン可能** | `/posla-admin/` にアクセス | 1.1.2 参照 |
| 2 | **Stripe Dashboard にアクセス可能** | https://dashboard.stripe.com | 社内 1Password Vault 確認 |
| 3 | **Stripe Billing キーが POSLA に設定済** | API 設定タブで `sk_live_…` 等が ●●● 表示 | 1.4.4 参照 |
| 4 | **Stripe Price ID 3 種が設定済** | base / additional_store / hq_broadcast | 1.4.1 参照 |
| 5 | **Stripe Webhook URL が登録済** | Stripe Dashboard → Webhook → `/api/signup/webhook.php` | Stripe で追加 |
| 6 | **Gemini API キーが設定済** | API 設定タブで `AIza…●●●` | 1.4.4 参照 |
| 7 | **メール送信が動作** | テストアドレスへ welcome メール送信テスト | mb_send_mail エラーログ確認 |
| 8 | **テナントの店舗住所・連絡先** がヒアリング済 | 申込フォーム or 電話で取得 | テナントに連絡 |
| 9 | **オーナー氏名・ユーザー名・初期パスワード** が決定 | テナントと相談 | 1.0.2 命名規約参照 |
| 10 | **本部一括メニュー配信アドオン** の希望有無確認 | チェーン本部運用なら ON | テナントに確認 |

::: warning 新サーバ立ち上げ / サーバ移行時の追加チェック（2026-04-23 追加）
SEC-/REL-HOTFIX-20260423 以降、以下が満たされていないと POSLA管理者ログインが成立しません:
- **HTTPS 証明書が有効**（secure cookie 必須。非 HTTPS では session 維持不可）
- **`POSLA_PHP_ERROR_LOG` env** 設定済 or 既定 path `/home/odah/log/php_errors.log` が書込可
- **`posla_admin_sessions` テーブル**が migration 済（`sql/migration-p1-6d-posla-admin-sessions.sql`、未適用なら fail-open で既存動作は保持）
- **`/tmp/posla_rate_limit/`** が書込可

**最初の入口:** 新サーバ移行担当者はまず [10. 新サーバ移行ハンドオフ](./10-server-migration.md) を参照。
**canonical:** 環境変数の正規表は [`internal/production-api-checklist.md §9-0`](./production-api-checklist.md)、運用インパクト詳細は [§5.3.7](./05-operations.md)、DB migration 実行確認リストは [§5.3.8](./05-operations.md)。
:::

### 2.0.2 テナント・スラッグ・ユーザー名の命名規約

**スラッグ（テナント識別子）：**
- 英小文字・数字・ハイフンのみ
- 3-30 文字
- 動詞・抽象語を避ける
- 例（実テナント）: `matsunoya`, `momonoya`, `torimaru`, `shibuya-ramen-001`

**店舗スラッグ：**
- テナント内で一意
- デフォルトは `default`（1 店舗目自動作成）
- 多店舗の場合: `tokyo-001`, `osaka-002`, `nagoya-003`

**オーナー ユーザー名：**
- 英数 + アンダースコア + ハイフン、3-40 文字
- **POSLA 全体（テナント横断）でグローバル一意**（S3 セキュリティ修正 #14、2026-04-19 仕様変更）
- 姓ローマ字単体（`tanaka` / `yamada`）は他テナントと衝突する可能性が高いので **テナント略号をプレフィックスに付ける**
- 推奨パターン: `<tenant_slug>_<姓>`（例: `matsunoya_tanaka`, `momonoya_yamada`）
- 衝突時は `signup/register.php` が `USERNAME_TAKEN` を返す → サフィックスを足す（`matsunoya_tanaka-2`）

### 2.0.3 オンボーディング全体フロー（自動 + 手動）

```
【自動フロー（A5 サインアップ）】
[LP /]
    ↓ 申込フォーム入力
POST /api/signup/register.php
    ↓ Stripe Customer 作成
    ↓ Checkout Session 作成 (trial_period_days=30)
[Stripe Checkout 画面]
    ↓ クレカ登録
[Stripe Webhook]
    ↓ POST /api/signup/webhook.php
    ↓ tenants/stores/users.is_active=1
    ↓ subscription_status='trialing'
    ↓ welcome メール送信
[/signup-complete.html]
    ↓ オーナーがメール経由でログイン
[管理ダッシュボード dashboard.html]
    ↓ オーナーが店舗詳細・メニュー設定

【手動フロー（緊急対応）】
[POSLA 管理画面 → テナント作成タブ]
    ↓ POST /api/posla/tenants.php
    ↓ DB 直接 SQL でオーナー・店舗追加
    ↓ パスワード手動設定 → テナントに連絡
```

---

## 2.1 自動サインアップフロー（A5、推奨）

### 2.1.1 LP（ランディングページ）からの申込

テナント側の操作（参考）：
1. ブラウザで `https://meal.posla.jp/` にアクセス
2. 「30日無料トライアルを始める」ボタンをクリック
3. 申込フォームに以下を入力:

| フィールド | 必須 | 形式 | バリデーション |
|---|---|---|---|
| 企業名 (屋号) | ✅ | 1-100 文字 | trim |
| 1 店舗目の店舗名 | ✅ | 1-100 文字 | trim |
| オーナー氏名 | ✅ | 1-60 文字 | trim |
| 電話番号 | ✅ | `0-9 + - ( ) スペース` 6-20 文字 | 正規表現 |
| メールアドレス | ✅ | メール形式 | filter_var EMAIL |
| ユーザー名 | ✅ | 英数 _-、3-40 文字 | 正規表現 |
| パスワード | ✅ | 8 文字以上、英字・数字必須 | password-policy |
| 住所 | 任意 | 1-200 文字 | trim |
| 店舗数 | 任意 | 1 以上 | 整数 |
| 本部配信アドオン | 任意 | チェックボックス | bool |

::: tip 「企業」と「店舗」の概念分離（A5 改修・2026-04-19）
従来のフォームは「店舗名」1 フィールドで `tenants.name` と `stores.name` の両方に同じ文字列を流し込んでいました。チェーン店舗運用では「松の屋」（企業 = テナント）と「渋谷店」（店舗）が別概念のため、2026-04-19 のフォーム改修で 2 フィールドに分離しました。

- `tenant_name` → `tenants.name` （例: 株式会社○○ / 松の屋）
- `store_name`  → `stores.name`  （例: 渋谷店 / 本店）

**後方互換**: API 側で `tenant_name` 未指定の場合は `store_name` を `tenants.name` に流用するため、旧 LP（`store_name` のみ送信）からの申込もそのまま動きます。詳細は [internal/08-signup-flow.md §8.3](./08-signup-flow.md#83-api-エンドポイント) を参照。
:::

4. 「次へ」→ Stripe Checkout 画面に自動遷移
5. クレジットカード情報を入力（**30 日間は課金されません**）
6. 申込完了ページ → 数分後にウェルカムメール受信

### 2.1.2 サインアップ API の動作（裏側）

**エンドポイント：** `POST /api/signup/register.php`

**処理フロー：**
1. レートリミット: 同一 IP から 5 回/時まで（`signup` キー）
2. バリデーション（重複・形式・パスワード強度）
3. `tenants` 仮作成（`subscription_status='none'`, `is_active=0`）
4. `stores` 仮作成（`is_active=0`、slug=`default`）
5. `store_settings` 作成（receipt 関連）
6. `users` 作成（owner ロール、`is_active=0`）
7. `user_stores` 紐付け
8. Stripe Customer 作成 → `tenants.stripe_customer_id` 保存
9. Stripe Checkout Session 作成
10. checkout_url を返却

**レスポンス例：**
```json
{
  "ok": true,
  "data": {
    "tenant_id": "abc123def456...",
    "checkout_url": "https://checkout.stripe.com/c/pay/cs_test_…",
    "signup_token": "xyz789..."
  }
}
```

### 2.1.3 Webhook によるアクティベーション

**エンドポイント：** `POST /api/signup/webhook.php`

Stripe が `checkout.session.completed` を送信 → 以下を実行:
- 署名検証（`stripe_webhook_secret`）
- `metadata.new_signup === '1'` 判定
- `tenants.is_active = 1`
- `tenants.subscription_status = 'trialing'`
- `tenants.stripe_subscription_id = sub_…` 更新
- `stores.is_active = 1`
- `users.is_active = 1`（owner のみ）
- ウェルカムメール送信（`mb_send_mail`）

### 2.1.4 ウェルカムメールの内容（matsunoya 実例）

```
件名: 【POSLA】ご登録ありがとうございます — ログイン情報のご案内

田中 太郎 様

POSLA にご登録いただきありがとうございます。
以下のログイン情報でサービスをご利用いただけます。

■ 店舗名: 松の屋
■ ログインURL: /admin/
■ ユーザー名: tanaka
■ パスワード: ご登録時に設定されたもの

★ 30日間無料トライアル中です。期間内のキャンセルで請求は発生しません。

ご不明な点は info@posla.jp までお気軽にお問い合わせください。

--
POSLA 運営チーム
```

### 2.1.5 Webhook 失敗時のフォールバック

Webhook が届かない / 失敗した場合に備えた手動アクティベーション API：

**エンドポイント：** `POST /api/signup/activate.php`

**処理：**
- `signup_token` を引数に取り、対応する tenant を `is_active=1` に変更
- POSLA 管理者認証必須
- 通常運用では使わないが、Stripe 障害時の救済策

::: warning S3 セキュリティ修正 #1, #12（2026-04-19）— Stripe 連携必須化
2026-04-19 以降、`activate.php` は **Stripe Subscription を必ず確認**してから有効化します。

- `signup_token` 不一致 → `INVALID_TOKEN` (404) で拒否（修正 #12）
- Stripe Subscription の status が `active` または `trialing` 以外（`past_due` / `incomplete` / `canceled` 等） → `PAYMENT_NOT_CONFIRMED` (402) で拒否（修正 #1）
- `tenants.stripe_subscription_id` 未設定 → `PAYMENT_NOT_CONFIRMED` (402)

**運用上の影響：**
- カード未登録のテナントを手動で有効化することは **不可能**（救済策としてもできない）
- オンボーディング前に必ず Stripe Checkout を完了させる（カード登録 → trialing 状態にする）こと
- Stripe webhook が届かない場合の救済策として activate.php を叩く時も、対象テナントが Stripe 側で trialing/active 状態であることを事前に確認
:::

### 2.1.6 Stripe Webhook の署名検証強化（S3 #2, #16, 2026-04-19）

- **`stripe_webhook_secret` 未設定時は fail-close (503)**: 修正前は署名チェックをスキップして処理続行していたが、現在は `WEBHOOK_NOT_CONFIGURED` で即拒否
- **タイムスタンプ許容 5 分**: webhook 署名のタイムスタンプが現在時刻 ±5 分から外れているリプレイは拒否（reservation-deposit-webhook も同様）
- 設定漏れがあると本番 webhook がすべて失敗するため、運用開始前に POSLA 管理画面 → API 設定タブで `stripe_webhook_secret` を必ず設定すること

---

## 2.2 手動オンボーディング（緊急対応用）

### 2.2.1 ステップ 1：テナント作成

POSLA 管理画面の「テナント作成」タブで新規テナントを登録します。

1. POSLA 管理画面にログイン
2. 「テナント作成」タブをクリック
3. 以下を入力:

| フィールド | 必須 | 入力例 | 説明 |
|---|---|---|---|
| Slug | ✅ | `shibuya-ramen` | URL 識別子。英小文字・数字・ハイフン |
| テナント名 | ✅ | `渋谷ラーメン` | 表示名 |
| 契約構成 | ✅ | POSLA標準 | 単一プラン固定 |
| 本部一括配信 | 任意 | ON / OFF | チェーン店のみ |

4. 「テナントを作成」ボタンをクリック
5. 「テナントを作成しました」トースト → 初期ログイン情報カードを確認
6. 作成時に `main` 店舗と `owner / manager / staff / device` の 4 アカウントが自動作成される

### 2.2.2 ステップ 2：オーナーアカウント作成（SQL 直接）

**手動でオーナーアカウントを作成する場合（緊急対応）：**

```sql
SET @tenant_id = (SELECT id FROM tenants WHERE slug = 'shibuya-ramen');
SET @user_id = REPLACE(UUID(), '-', '');

INSERT INTO users (
  id, tenant_id, username, email, password_hash, display_name, role, is_active
) VALUES (
  @user_id,
  @tenant_id,
  'tanaka',
  'tanaka@example.com',
  '$2y$10$...',  -- bcrypt ハッシュ
  '田中 太郎',
  'owner',
  1
);
```

**bcrypt ハッシュ生成：**
```bash
php -r 'echo password_hash("InitialPw2026", PASSWORD_DEFAULT) . PHP_EOL;'
```

**オーナーに伝える情報（メールテンプレート）：**
- ログイン URL：`/admin/`
- ユーザー名：`tanaka`
- 初期パスワード：`InitialPw2026`（**初回ログイン後の変更必須**）

### 2.2.3 ステップ 3：店舗作成

通常は LP サインアップ時に自動作成されますが、手動の場合：

```sql
SET @tenant_id = (SELECT id FROM tenants WHERE slug = 'shibuya-ramen');
SET @store_id = REPLACE(UUID(), '-', '');

INSERT INTO stores (
  id, tenant_id, slug, name, timezone, is_active, created_at, updated_at
) VALUES (
  @store_id, @tenant_id, 'default', '渋谷店', 'Asia/Tokyo', 1, NOW(), NOW()
);

INSERT INTO store_settings (
  store_id, receipt_store_name, receipt_phone, receipt_address, created_at, updated_at
) VALUES (
  @store_id, '渋谷店', '03-1234-5678', '東京都渋谷区xxx', NOW(), NOW()
);

INSERT INTO user_stores (user_id, store_id)
SELECT id, @store_id FROM users WHERE tenant_id = @tenant_id AND role = 'owner';
```

### 2.2.4 ステップ 4：初期設定の案内

店舗作成後、マネージャーまたはオーナーが管理ダッシュボードの「店舗設定」から以下を設定するよう案内します。

| 設定項目 | 説明 | 優先度 |
|---------|------|--------|
| 営業日区切り時刻 | 深夜営業の場合は調整必要（例: 4:00） | 高 |
| 税率 | 10% のままで OK の場合は変更不要 | 中 |
| 注文上限 | 必要に応じて調整 | 低 |
| ラストオーダー時刻 | 自動ラストオーダーが必要な場合 | 中 |
| レジ設定 | 支払方法の有効/無効（現金/カード/QR） | 高 |
| レシート設定 | 店舗名・住所・電話番号 | 高 |
| 外観カスタマイズ | ブランドカラー・ロゴ | 中 |

### 2.2.5 ステップ 5：メニュー登録

管理ダッシュボードの「メニュー管理」タブから登録します。

**登録順序：**
1. カテゴリの作成（前菜、メイン、ドリンク等）
2. メニューテンプレートの作成（品名、価格、画像等）
3. オプショングループの作成（トッピング、サイズ等）
4. オプションとメニューの紐付け

::: tip CSV インポート
大量のメニューがある場合、CSV インポート機能を利用すると効率的です。テンプレート CSV は管理画面からダウンロード可能。
:::

::: tip 本部一括配信アドオン契約時
チェーン本部運用の場合、メニューは **owner-dashboard.html「本部メニュー」タブ** で本部マスタとして登録します。各店舗側は readonly で参照のみ。詳細は [tenant/15-owner.md](../tenant/15-owner.md) 参照。
:::

### 2.2.6 ステップ 6：テーブル登録

管理ダッシュボードの「テーブル・フロア」タブから登録します。

1. テーブルコードと座席数を設定
2. QR コードを生成・印刷
3. テーブルに QR コードを設置

### 2.2.7 ステップ 7：アドオン契約の確認

α-1 構成では、通常の機能は **全契約者に標準提供** されるため、ここで設定することは以下のみです。

- `tenants.hq_menu_broadcast`: `1`（本部一括メニュー配信アドオン契約中）/ `0`（未契約）
- 有効化された場合、owner-dashboard に「本部メニュー」タブが出現し、店舗側ダッシュボードの本部マスタ項目が readonly になる

実装: `api/lib/auth.php` L238-246 の `check_plan_feature()` が `hq_menu_broadcast` 以外すべて `true` を返すため、`plan_features` テーブルは rollback 用として残置されているだけで、アクティブには参照されません。

アドオンの追加・解除は Stripe Billing のサブスクリプション変更（subscription items に `stripe_price_hq_broadcast` の追加/削除）で反映されます。詳細は 3 章「課金・サブスクリプション」を参照。

### 2.2.8 ステップ 8：決済設定（Stripe Connect）

Stripe 決済を有効にする場合、以下のいずれかの方式を選択します。

**パターン A：テナントが自前の Stripe アカウントを持っている場合**
1. オーナーダッシュボードの「決済設定」タブを開く
2. 「Stripe Secret Key」欄にテナントの Secret Key を入力して保存
3. レジの Stripe Terminal が直ちに利用可能（`terminal_pattern=A`）

**パターン B：テナントが自前の Stripe アカウントを持っていない場合（推奨）**
1. オーナーダッシュボードの「決済設定」タブを開く
2. 「Stripe Connect に登録して決済を開始する」をクリック
3. Stripe のオンボーディング画面で必要情報を入力（個人事業主 or 法人）
   - 法人名 / 代表者氏名
   - 銀行口座情報
   - 本人確認書類（運転免許証等）
4. `connect_onboarding_complete=1` になり、レジの Stripe Terminal が利用可能（`terminal_pattern=B`、application_fee 1.0% を POSLA が取得）

**API：**
- `POST /api/connect/onboard.php` — Connect オンボーディング開始
- `GET /api/connect/status.php` — 接続状態確認
- `POST /api/connect/disconnect.php` — 接続解除

### 2.2.9 ステップ 9：スマレジ連携（任意）

スマレジを既に使っているテナントは、POSLA から OAuth 連携できます。

1. オーナーダッシュボードの「外部連携」タブを開く
2. 「スマレジに連携」ボタン
3. スマレジの OAuth 画面で承認
4. `tenants.smaregi_contract_id` が記録され、メニュー・売上が自動同期

### 2.2.10 ステップ 10：運用開始

全設定完了後のチェックリスト：

- [ ] テナントが `is_active=1`
- [ ] `subscription_status` が `trialing` または `active`
- [ ] オーナーがログインできる
- [ ] 店舗が作成され `is_active=1`
- [ ] メニューが 5 品以上登録されている
- [ ] テーブルが登録され、QR コードが印刷されている
- [ ] レシート設定が完了している
- [ ] 支払方法が設定されている
- [ ] スタッフアカウントが作成されている（人数分）
- [ ] device アカウント（KDS/レジ/ハンディ用）が作成されている
- [ ] KDS 端末が設置され、動作確認済み
- [ ] セルフオーダーの QR コードをスマートフォンで読み取って注文テスト済み
- [ ] テストオーダーで会計まで完了している

---

## 2.3 スタッフへの導入ガイダンス

テナントのスタッフに以下を説明してください。

**全スタッフ：**
- ログイン方法（ユーザー名＋パスワード）
- 退勤時のログアウトの重要性
- 出退勤打刻の方法

**ホールスタッフ：**
- ハンディ POS の操作方法（注文入力、テーブル選択）
- スタッフ呼び出しアラートの確認・対応方法

**キッチンスタッフ：**
- KDS の操作方法（調理開始 → 完成 → 提供済み）
- ステーション選択の方法
- コースフェーズの進め方（コースがある場合）

**レジスタッフ：**
- POS レジの操作方法（テーブル選択 → 支払方法 → 確定）
- レジ開け・閉めの方法
- 個別会計の操作方法

**マネージャー：**
- 管理ダッシュボードの全タブの概要
- シフト管理の操作（全契約者）
- レポートの見方

---

## 2.4 オンボーディングで使う API（2026-04-19 時点）

### 新規申込（POSLA 運営なし、客が直接実行）

- `POST /api/signup/register.php` — テナント新規申込（レートリミット 5/時、`signup` キー）
- `POST /api/signup/activate.php` — メール認証後にテナントを有効化（webhook フォールバック）
- `POST /api/signup/webhook.php` — Stripe Webhook（`checkout.session.completed`）
- 詳細フロー: [internal/08-signup-flow.md](./08-signup-flow.md)

### POSLA 運営側のオペレーション（既存テナント手動操作）

- `GET/PATCH /api/posla/tenants.php` — テナント情報の確認・更新
- `POST /api/posla/tenants.php` — テナント新規作成
- `GET/PATCH /api/posla/settings.php` — POSLA 共通 API キー管理
- `GET /api/posla/dashboard.php` — POSLA ダッシュボード統計
- `POST /api/posla/setup.php` — 初期管理者アカウント作成（初回 1 回のみ）
- `GET /api/posla/me.php` — POSLA 管理者情報
- `POST /api/posla/login.php` / `logout.php` / `change-password.php` — 認証

### Stripe 関連

- `POST /api/connect/onboard.php` — Stripe Connect オンボード（パターン B）
- `GET /api/connect/status.php` — Connect 接続状態
- `POST /api/connect/disconnect.php` — Connect 切断
- `POST /api/subscription/checkout.php` — サブスク Checkout 作成（既存テナント追加店舗等）
- `GET /api/subscription/status.php` — サブスク状態取得
- `POST /api/subscription/webhook.php` — 既存テナント向け Webhook

### 監視・運用

- `GET /api/monitor/ping.php` — ヘルスチェック（外部 uptime 監視サービスから 5 分毎にコール）
- `GET/PATCH /api/posla/monitor-events.php` — モニターイベント管理（F-MV1）

---

## 2.5 オンボーディング後の標準サポートフロー

### 2.5.1 契約 1 週目（POSLA 運営側）

1. 申込日に自動でウェルカムメール送信（`api/signup/webhook.php`）
2. 翌営業日に POSLA 運営が **電話 / メールでフォロー連絡**
   - 「ログインできましたか？」
   - 「初期設定で困っていることはありませんか？」
   - 「メニュー登録は終わりましたか？」
3. 必要なら **初期設定支援 Zoom MTG**（30 分、無料）
4. 4 日目: 使用状況をチェック（注文 0 件なら個別フォロー）
5. 7 日目: 「使い心地はいかがですか？」アンケート

### 2.5.2 契約 1 ヶ月目（トライアル中）

1. 7 日目: アンケート送信
2. 14 日目: 主要機能の使用状況分析（POSLA Slack に自動通知）
3. 25 日目: トライアル終了 5 日前メール「31 日目から ¥20,000 課金開始します」
4. 31 日目: 自動課金開始、課金完了メール

### 2.5.3 契約 2 ヶ月目以降

1. 月初: 前月の売上レポート PDF を運営から送付（任意サービス）
2. 不定期: 新機能リリース時に告知メール
3. 必要時: トラブル対応・機能要望ヒアリング
4. 6 ヶ月毎: 利用満足度アンケート

---

## 2.6 アカウント創設の SQL 直接操作（緊急時）

通常はサインアップフローで自動作成されますが、稀に DB 直操作が必要な場合があります。

### 2.6.1 テナント手動作成

```sql
INSERT INTO tenants (
  id, slug, name, name_en, plan, is_active, hq_menu_broadcast,
  subscription_status, created_at, updated_at
) VALUES (
  REPLACE(UUID(), '-', ''),
  'shibuya-ramen',
  '渋谷ラーメン',
  'Shibuya Ramen',
  'standard',
  1,
  0,
  'none',
  NOW(),
  NOW()
);
```

### 2.6.2 オーナーアカウント手動作成

```sql
SET @tenant_id = (SELECT id FROM tenants WHERE slug = 'shibuya-ramen');
INSERT INTO users (
  id, tenant_id, username, email, password_hash, display_name, role, is_active
) VALUES (
  REPLACE(UUID(), '-', ''),
  @tenant_id,
  'tanaka-owner',
  'tanaka@example.com',
  '$2y$10$...',  -- bcrypt
  '田中 太郎',
  'owner',
  1
);
```

### 2.6.3 店舗の手動作成

```sql
SET @tenant_id = (SELECT id FROM tenants WHERE slug = 'shibuya-ramen');
INSERT INTO stores (id, tenant_id, slug, name, is_active)
VALUES (REPLACE(UUID(), '-', ''), @tenant_id, 'shibuya-001', '渋谷店', 1);
```

### 2.6.4 device アカウント（KDS/レジ/ハンディ）の手動作成

```sql
SET @tenant_id = (SELECT id FROM tenants WHERE slug = 'shibuya-ramen');
SET @device_id = REPLACE(UUID(), '-', '');

INSERT INTO users (
  id, tenant_id, username, password_hash, display_name, role, visible_tools, is_active
) VALUES (
  @device_id,
  @tenant_id,
  'shibuya-kds-001',
  '$2y$10$...',
  'KDS 1号機',
  'device',
  'kds',
  1
);

-- 該当店舗に紐付け
SET @store_id = (SELECT id FROM stores WHERE tenant_id = @tenant_id AND slug = 'shibuya-001');
INSERT INTO user_stores (user_id, store_id) VALUES (@device_id, @store_id);
```

### 2.6.5 注意事項

- bcrypt ハッシュは PHP の `password_hash($pw, PASSWORD_DEFAULT)` で生成
- UUID 生成は MySQL の `UUID()` 関数 + ハイフン除去（`REPLACE(UUID(), '-', '')`）
- 直接 SQL 操作は監査ログに残らないので、運営チーム内で記録を取る（Notion `POSLA-Operations` ページ）
- 必ず `tenant_id` の正しさを確認してから INSERT する

---

## 2.7 トラブルシューティング表

| 症状 | 原因の候補 | 確認手順 | 対応 |
|------|----------|---------|------|
| サインアップ後にメールが届かない | mb_send_mail 失敗 / 迷惑メール | `/home/odah/log/php_errors.log` で `_a5_send_welcome_mail` エラー確認 | テナント側スパムフォルダ確認 / 手動メール送信 |
| Stripe Checkout 画面に遷移しない | `stripe_secret_key` 未設定 | API 設定タブ確認 | キー登録 → 再申込 |
| Webhook が届かない | webhook URL 間違い / signature 不一致 | Stripe Dashboard → Webhook → ログ | URL 訂正 / secret 再設定 |
| 「STRIPE_NOT_CONFIGURED」エラー | `stripe_secret_key` 未設定 | `SELECT setting_value FROM posla_settings WHERE setting_key='stripe_secret_key'` | API 設定タブで登録 |
| 「PRICE_NOT_CONFIGURED」エラー | `stripe_price_base` 未設定 | 同上 | Stripe で Price 作成 → 登録 |
| 「USERNAME_TAKEN」エラー | 同名ユーザー存在 | `SELECT id FROM users WHERE username = ?` | 別ユーザー名を提案 |
| 「EMAIL_TAKEN」エラー | 同 email ユーザー存在 | `SELECT id FROM users WHERE email = ?` | 別 email を依頼 |
| ウェルカムメール送信後ログインできない | `is_active=0` のまま | `SELECT is_active FROM tenants WHERE id=?` | webhook 再実行 / activate.php 手動実行 |
| サインアップ完了したが trialing にならない | webhook が `not_new_signup` 判定 | webhook ログ確認 | metadata.new_signup を再 POST |
| Stripe Customer は作成されたが Tenant が DB にない | rollback 失敗 | `SELECT * FROM tenants WHERE stripe_customer_id=?` | Stripe 側を手動 detach + 再申込 |
| アドオン契約後も「本部メニュー」タブが出ない | hq_menu_broadcast 未更新 | `SELECT hq_menu_broadcast FROM tenants WHERE id=?` | 手動 UPDATE + ブラウザ強制リロード |
| Connect オンボーディング中断後再開できない | `stripe_connect_account_id` 残存 | `SELECT stripe_connect_account_id FROM tenants` | Stripe Dashboard で account 削除 → DB クリア → 再開 |

---

## 2.8 想定問い合わせ別 対応テンプレート

### 2.8.1 「申込みしたがメールが届かない」

1. テナントに以下を確認:
   - 申込日時
   - 入力したメールアドレス
   - 迷惑メールフォルダの確認
2. POSLA 側で確認:
   ```sql
   SELECT t.id, t.subscription_status, t.is_active, u.email, u.is_active
   FROM tenants t
   JOIN users u ON u.tenant_id = t.id AND u.role = 'owner'
   WHERE u.email = ?;
   ```
3. webhook ログで送信履歴確認
4. 必要なら手動でログイン情報を再送（テキストメール）

### 2.8.2 「初回ログインできない」

1. `users.is_active = 1` か確認
2. `tenants.is_active = 1` か確認
3. `subscription_status` が `trialing` or `active` か確認
4. パスワードリセット案内（管理者がリセット可能、2.8.5 参照）

### 2.8.3 「Stripe 決済が失敗する」

1. Stripe Dashboard → Customer 検索
2. 直近の Charge / PaymentIntent エラー詳細を確認
3. テナントに以下を確認:
   - クレジットカード有効期限
   - カード会社からの拒否（電話確認）
   - 3D セキュア完了
4. 必要なら別カードで再試行を案内

### 2.8.4 「トライアル期間を延長したい」

A. Stripe Dashboard → Customer → Subscription → Edit → Trial end を変更。

### 2.8.5 「パスワードを忘れた」

1. テナントオーナーに直接対応:
   ```sql
   UPDATE users
   SET password_hash = '$2y$10$新ハッシュ'
   WHERE tenant_id = ? AND username = ?;
   ```
2. 新パスワードを電話 or 別チャネルで通知
3. 初回ログイン後の自己変更を依頼

---

## 2.9 よくある質問（FAQ 28 件）

### Q1. サインアップ完了から有効化までどれくらい？

A. 通常は **数秒〜1 分以内**（Stripe webhook 経由）。30 分超えても有効化されない場合は webhook 失敗の可能性 → activate.php で手動有効化。

### Q2. クレジットカードを登録したくないテナントがいる

A. 30 日無料トライアルでも **カード登録必須**（α-1 仕様）。営業時に必ず説明してください。代替策として「銀行振込」は現在未対応。

### Q3. テナント作成後にスラッグを変更したい

A. **変更不可**（URL に組み込まれているため）。新スラッグでテナント新規作成 → データ移行 → 旧テナント無効化が必要。事前に十分確認してください。

### Q4. 1 つの会社で複数ブランド（テナント）を契約したい

A. 各ブランドごとに別テナント・別アカウントを作成。請求は別々（同一クレカ可）。例: `matsunoya`（とんかつ） + `momonoya`（カフェ）。

### Q5. オーナーアカウントを別の人に引き継ぎたい

A. 既存 owner で新 owner アカウントを作成 → 旧 owner を `is_active=0`。または SQL で email・display_name を直接更新。

### Q6. 店舗を 1 つから 5 つに増やしたい

A. テナント側「決済設定」タブから Stripe Customer Portal 経由で quantity 増加。POSLA 運営側の手動操作不要。

### Q7. 本部一括配信アドオンを後から追加したい

A. テナント側「決済設定」タブから「アドオン追加」ボタン。Stripe Subscription に `stripe_price_hq_broadcast` が追加され、`tenants.hq_menu_broadcast=1` に更新される。

### Q8. テストテナントを作りたい

A. 推奨方法: スラッグに `test-` プレフィックス（例: `test-matsunoya`）。Stripe は `sk_test_…` キーで切替（環境変数）。

### Q9. オンボーディング中断したテナントの掃除

A. 申込から 7 日間 `is_active=0` のままなら自動削除（cron バッチ予定、現状未実装）。手動の場合は `_a5_cleanup_tenant()` 関数のロジックを SQL で実行。

### Q10. メニュー登録の支援が欲しいと言われた

A. POSLA 運営側で代理登録可能（有料 / ¥10,000）。テナントから CSV を受領 → 管理ダッシュボード CSV インポート機能で登録。

### Q11. テナントがログイン情報を紛失

A. 別管理者経由でパスワードリセット。または POSLA 側で SQL 直接 reset（2.8.5 参照）。

### Q12. テナント名を変更したい

A. POSLA 管理画面 → テナント管理 → 編集 → name 更新。スラッグは変更不可。

### Q13. テナントの画面ブランディング（ロゴ・色）を変えたい

A. テナント側「店舗設定」→「外観カスタマイズ」タブから自分で設定可能。

### Q14. オンボーディング後にすぐ解約された

A. Stripe Dashboard で解約理由ヒアリング機能（Cancellation Reason）を活用。データは `canceled_reason` カラムに保存予定（未実装）。

### Q15. テナントが KDS 端末を準備していない

A. **標準環境** は Android タブレット 10〜11 インチ + Google Chrome 最新版（Android 10 以上）。家電量販店・Amazon 等で入手可能で、1 台 3〜8 万円。device アカウントを事前作成して管理画面 URL を伝える。

理由 (内部判断):
- KDS の音声操作（Web Speech API continuous モード）・通知音の安定再生・PWA インストール体験が iOS Safari では制約があるため、業務端末の **標準環境** から外している（iOS の Chrome アプリは中身が Safari ベースのため同様）
- iPad / Windows タブレットは「業務端末の標準環境」としては案内しない。すでに iPad を持っているテナントには、管理画面の確認用途として併用する形を案内する
- 詳細は `docs/manual/internal/09-pwa.md` の §9.10 標準環境ポリシーを参照

### Q16. Stripe Connect オンボーディングが終わらない

A. テナント側で本人確認書類アップロード待ち。Stripe Dashboard → Connect → Account 詳細で進捗確認。

### Q17. テナント間でデータを共有したい（マルチブランド統合）

A. **不可**（マルチテナント境界違反）。共有が必要な場合は同一テナント内の複数店舗運用を提案。

### Q18. サインアップ後に「ログイン URL がわからない」と問い合わせ

A. ウェルカムメールに記載されているが、再送案内: `/admin/`

### Q19. 30 日トライアル中にいくつ機能を試せる？

A. **全機能利用可能**（α-1 仕様）。アドオン（本部一括配信）も含む。

### Q20. テナントの月額料金を Stripe 上で確認したい

A. Stripe Dashboard → Customer → 該当テナント → Subscriptions タブ。Items に price_base + price_additional_store + price_hq_broadcast の組合せが表示される。

### Q21. テナントが「請求書が欲しい」と言ってきた

A. Stripe Dashboard → Customer → Invoices → ダウンロード。テナント自身も Customer Portal からダウンロード可能（テナント側「決済設定」タブから）。

### Q22. 有効化後すぐに `past_due` 状態になった

A. トライアル終了時の自動課金で決済失敗。Stripe Dashboard で原因確認 → テナントに連絡 → 別カード登録依頼。

### Q23. アカウント作成順序を間違えた（先に store 作って tenant がない）

A. 必ず tenants → stores → users の順序を守る。間違えた場合は SQL で一旦 DELETE → 再 INSERT。

### Q24. オンボーディング後のフォロー連絡をいつするか

A. 翌営業日 10:00-12:00 推奨（飲食店オーナーは午後忙しくなるため）。電話 + 同内容のメール送信。

### Q25. テナントが「使い方がわからない」とリピート連絡

A. オンボーディング Zoom MTG（30 分、無料）を提案。それでも改善しない場合は有料サポート（月額 ¥5,000）案内。

### Q26. テナントが他社（USEN レジ等）から移行したい

A. メニュー CSV 移行可能。売上履歴の移行は不可（POSLA 側に取り込む API なし）。

### Q27. 海外店舗のテナントは契約可能？

A. 現状は **国内のみ**（Stripe Connect 国内アカウントのみ対応）。timezone は `Asia/Tokyo` 固定。

### Q28. テナント数の上限は？

A. **上限なし**（システム的には）。サーバーリソース監視（monitor_events）で適宜スケール。

---

## 2.10 オンボーディング SLA（社内目標）

| 項目 | 目標 | 計測方法 |
|---|---|---|
| サインアップから有効化まで | 5 分以内 | webhook 受信時刻 - Checkout 完了時刻 |
| ウェルカムメール到達 | 10 分以内 | テナント受信時刻 |
| 翌営業日フォロー連絡 | 翌営業日 12:00 まで | Notion 記録 |
| 初期設定支援 Zoom MTG | 申込から 5 営業日以内 | スケジュール記録 |
| トライアル中の機能サポート問合せ回答 | 4 時間以内 | メール返信時刻 |

---

## X.X 更新履歴
- **2026-04-19**: 2.1.1 申込フォームを「企業名 + 1 店舗目の店舗名」2 フィールドに分離（A5 改修）。`tenant_name` パラメータ追加 + 後方互換挙動を追記
- **2026-04-19**: 2.0 事前準備 / 2.1 自動サインアップ / 2.5-2.10 拡充（Phase B Batch-13）
- **2026-04-19**: 2.5 サポートフロー / 2.6 SQL 直接操作 追加
- **2026-04-19**: 2.4 API エンドポイント一覧追加
- **2026-04-18**: frontmatter 追加、AI ヘルプデスク knowledge base として整備
