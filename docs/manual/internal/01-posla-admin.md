---
title: POSLA管理画面
chapter: 01
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [posla-admin, 運営, POSLA]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 1. POSLA管理画面

POSLA管理画面（`/public/posla-admin/`）は、プラスビリーフ社内のPOSLA運営スタッフが使用する管理コンソールです。テナント管理・APIキー設定・ダッシュボード統計・運用監視を提供します。テナント向けの管理ダッシュボード（`/public/admin/`）とは完全に分離された画面です。

::: tip 右下の AI ヘルプデスク FAB（internal モード、2026-04-19 設置）
POSLA 管理画面にも管理ダッシュボードと同じ **オレンジ色 ? ボタン**（右下、60×60 円形 FAB）が常駐します。POSLA 運営チームが利用するため、knowledge base は **internal モード**（`helpdesk-prompt-internal.txt`、tenant + operations + internal 8 章 + SYSTEM_SPECIFICATION）で動作します。テナント側 FAB より広い知識ベースを参照可能。詳細は [tenant/14.6](../tenant/14-ai.md#146-ai-ヘルプデスク管理画面共通-fab) 参照。
:::

::: warning この章は POSLA 運営（社内）専用
本書はテナント（飲食店）向けではありません。技術詳細・DB 構造・SQL 例を多用しています。テナントから問い合わせがあった場合の対応手順を中心に書かれています。
:::

---

## 1.0 はじめにお読みください（運営者の事前準備）

### 1.0.1 アクセスする前のチェックリスト（5項目）

POSLA 管理画面にログインする前に、以下5項目を必ず確認してください。

| # | 確認項目 | 確認方法 | NG時の対応 |
|---|---------|---------|-----------|
| 1 | **POSLA 管理者アカウント** が発行されている | `posla_admins` テーブルに自分の email が登録されている | 既存管理者に SQL 直接登録を依頼（1.7.1 参照） |
| 2 | **接続元 IP** が許可リストに含まれている | プラスビリーフ社内 VPN または許可済 IP からアクセス | VPN 接続後に再アクセス |
| 3 | **ブラウザ Cookie が有効** | Chrome / Edge / Safari の最新版推奨 | プライベートブラウジングを解除 |
| 4 | **2 段階認証アプリ**（将来導入予定） | 現状は password のみ | 未対応 |
| 5 | **Slack `#posla-alerts` チャンネル** に参加済み | 緊急アラートを受信できる状態 | 運営チーム責任者に招待依頼 |

### 1.0.2 アクセス URL と画面構成

| 環境 | URL | Basic 認証 |
|---|---|---|
| 本番 | `https://eat.posla.jp/public/posla-admin/` | あり（社内共有 ID/PW） |
| ステージング | `https://staging.eat.posla.jp/public/posla-admin/` | あり（同上） |
| ローカル開発 | `http://localhost:8080/public/posla-admin/` | なし |

::: warning Basic 認証
本番・ステージング環境では `.htaccess` による Basic 認証で **公開検索エンジンへのインデックス防止** と **第三者アクセス遮断** を二重で実施しています。Basic 認証の ID/PW は社内 1Password Vault `POSLA-Internal` に保管。
:::

### 1.0.3 画面遷移の全体図

```
[Basic 認証ダイアログ]
        ↓
[ログイン画面 index.html]
        ↓ POST /api/posla/login.php
[ダッシュボード dashboard.html]
        ├─ タブ1: ダッシュボード（統計）
        ├─ タブ2: テナント管理
        ├─ タブ3: テナント作成
        ├─ タブ4: API設定
        └─ ヘッダー右上
            ├─ パスワード変更ボタン
            └─ ログアウトボタン
```

---

## 1.1 初期セットアップ

### 1.1.1 最初の管理者アカウント作成

POSLAを新規デプロイした直後、管理者アカウントが存在しない状態でセットアップAPIを呼び出します。

**エンドポイント：** `POST /api/posla/setup.php`

**リクエスト：**
```json
{
  "email": "admin@plusbelief.co.jp",
  "password": "初期パスワード",
  "display_name": "管理者名"
}
```

**条件：** `posla_admins` テーブルが空の場合のみ動作します。既に管理者が存在する場合は 403 エラーが返されます。

**実行例（curl）：**
```bash
curl -X POST https://eat.posla.jp/public/api/posla/setup.php \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@plusbelief.co.jp","password":"InitialPw123","display_name":"Hiro"}'
```

**成功レスポンス：**
```json
{ "ok": true, "data": { "admin_id": "uuid-xxx", "message": "初期管理者を作成しました" }, "serverTime": "2026-04-19T05:00:00+09:00" }
```

::: danger 本番環境での注意
初期セットアップ完了後、`setup.php` ファイルは本番サーバーから削除することを **強く推奨** します（`mv setup.php setup.php.disabled`）。理由: 初期管理者削除 → 第三者がブラウザから setup.php を叩いて新規管理者を作成する攻撃を防ぐため。
:::

### 1.1.2 ログイン

1. ブラウザで `https://eat.posla.jp/public/posla-admin/` にアクセスします
2. Basic 認証ダイアログが表示されます → 社内共有 ID/PW を入力
3. POSLA ログイン画面（`index.html`）が表示されます
4. メールアドレスとパスワードを入力します
5. **「ログイン」** ボタンをクリックします
6. ログイン成功 → 自動的に `dashboard.html` にリダイレクト

**入力フィールド詳細：**

| フィールド | 必須 | 形式 | 例 |
|---|---|---|---|
| email | ✅ | メールアドレス形式 | `admin@plusbelief.co.jp` |
| password | ✅ | 8文字以上、英数混在 | `MySecurePw2026` |

**エンドポイント裏側：** `POST /api/posla/login.php`
- `posla_admins` テーブルから email で検索
- `password_verify($password, $hash)` で照合
- 成功時 `$_SESSION['posla_admin_id']` を設定
- `last_login_at` を更新

**セッション設定：**

| 項目 | 値 |
|---|---|
| セッション有効時間 | 8 時間 |
| Cookie 名 | `PHPSESSID` |
| HttpOnly | true |
| Secure | true（本番）|
| SameSite | Lax |
| パス | `/` |

ログイン成功時に **セッション ID が再生成**（`session_regenerate_id(true)`）され、固定セッション攻撃を防止します。最終ログイン日時は `posla_admins.last_login_at` に記録されます。

### 1.1.3 パスワード変更（P1-5）

POSLA 管理者は自分のパスワードを管理画面から変更できます。

1. POSLA 管理画面にログイン後、ヘッダー右上の **「パスワード変更」ボタン** を押す
2. パスワード変更モーダルが開きます：
   - **現在のパスワード**：現在ログインに使っているパスワード
   - **新しいパスワード**：新しいパスワード（パスワードポリシー要件あり）
   - **新しいパスワード（確認）**：もう一度同じものを入力
3. **「変更する」** を押す
4. 成功メッセージが表示されたらパスワード変更完了

**エンドポイント：** `POST /api/posla/change-password.php`

**パスワードポリシー：**

| 要件 | 内容 | 検証実装 |
|------|------|----------|
| 最低長 | 8 文字以上 | クライアント + サーバー両方 |
| 英字 | A-Z または a-z を 1 文字以上 | クライアント + サーバー両方 |
| 数字 | 0-9 を 1 文字以上 | クライアント + サーバー両方 |
| 現在パスワードと同一不可 | 新パスワード !== 旧パスワード | クライアント + サーバー両方 |

::: warning 現在のパスワードを忘れた場合
POSLA 管理者は単一の管理者しかいない場合、自力ではパスワードを復旧できません。データベースに直接接続して `posla_admins.password_hash` を更新する必要があります。本番環境では複数の管理者アカウントを作成しておくことを推奨します。

**緊急復旧 SQL：**
```sql
-- PHP 側で password_hash('NewPassword123', PASSWORD_DEFAULT) を実行して取得
UPDATE posla_admins
SET password_hash = '$2y$10$...生成済ハッシュ...'
WHERE email = 'admin@plusbelief.co.jp';
```
:::

::: info セッション挙動
POSLA 管理者用のセッションは現状単一 PHP セッションのみで、テナント側のような `user_sessions` テーブルによるマルチセッション管理は行っていません。そのため自己パスワード変更時の「他セッション無効化」処理は実装されていません（同一管理者で複数端末同時ログインしている場合、他端末は次回タイムアウトまで利用可能）。
:::

### 1.1.4 ログアウト

1. ヘッダー右上の **「ログアウト」** ボタンを押す
2. `DELETE /api/posla/logout.php` がコールされ、セッションが破棄
3. `index.html`（ログイン画面）に自動遷移

---

## 1.2 ダッシュボード（タブ1）

ログイン後、ダッシュボード画面が表示されます。

### 1.2.1 タブ一覧

| タブ ID | 表示名 | 説明 |
|------|------|------|
| `overview` | ダッシュボード | 統計情報と最近のテナント |
| `tenants` | テナント管理 | テナント一覧と編集 |
| `create-tenant` | テナント作成 | 新規テナントの登録 |
| `api-settings` | API設定 | グローバル API キーの管理 |

::: info 将来追加予定タブ
2026-Q3 で `monitor`（運用監視）と `signup-pending`（サインアップ管理）タブの追加を計画。現状は API 直接コール経由（`/api/posla/monitor-events.php`）で確認。
:::

### 1.2.2 統計情報の項目

ダッシュボード画面（`overview` タブ）の上部に表示される統計カード：

| 指標 | 取得 SQL | 説明 |
|------|---------|------|
| テナント総数 | `SELECT COUNT(*) FROM tenants WHERE is_active = 1` | 有効なテナント数 |
| 店舗総数 | `SELECT COUNT(*) FROM stores WHERE is_active = 1` | 全テナントの有効な店舗合計 |
| ユーザー総数 | `SELECT COUNT(*) FROM users WHERE is_active = 1` | 全テナントの有効なユーザー合計（device 含む） |
| 本部配信アドオン契約数 | `SELECT COUNT(*) FROM tenants WHERE hq_menu_broadcast = 1` | α-1 アドオン契約中のテナント数 |
| 最近のテナント | `SELECT … ORDER BY created_at DESC LIMIT 5` | 直近5件の新規テナント |

**裏側 API：** `GET /api/posla/dashboard.php`

**画面表示例（matsunoya 含む実テナント）：**
```
┌──────────────┬──────────────┬──────────────┐
│ テナント数    │ 店舗数        │ ユーザー数    │
│      3       │      5       │      18      │
└──────────────┴──────────────┴──────────────┘

【最近のテナント】
| テナント名     | Slug      | プラン   | 状態 | 作成日      |
|---------------|-----------|---------|------|------------|
| 松の屋         | matsunoya | standard | 有効 | 2026-03-01 |
| 桃の家         | momonoya  | standard | 有効 | 2026-03-15 |
| 鳥丸           | torimaru  | standard | 有効 | 2026-04-08 |
```

### 1.2.3 プラン分布（レガシー、α-1 では参考値）

α-1 構成移行（2026-04-09）以降、`tenants.plan` カラムは保持されているもののアクティブには参照されません。プラン分布表示は **歴史的データ参照用** として残しています。

---

## 1.3 テナント管理（タブ2）

### 1.3.1 テナント一覧の表示項目

全テナントが一覧表示されます。

| 項目 | データソース | 説明 |
|------|-------------|------|
| テナント名 | `tenants.name` | — |
| スラッグ | `tenants.slug` | URL 用識別子 |
| プラン | `tenants.plan` | レガシー、表示のみ |
| 店舗数 | `COUNT(stores)` | Stripe Subscription quantity と一致 |
| ユーザー数 | `COUNT(users)` | device ロール含む |
| サブスク | `tenants.subscription_status` | none / trialing / active / past_due / canceled |
| Connect | `tenants.stripe_connect_account_id` | 接続状態とアカウント ID |
| 状態 | `tenants.is_active` | 有効 / 無効 |
| 操作 | — | 「編集」ボタン |

**裏側 API：** `GET /api/posla/tenants.php`

### 1.3.2 サブスクリプション状態の意味

| 状態 | 意味 | 対応 |
|---|---|---|
| `none` | 未契約・サインアップ未完了 | 通常は webhook で `trialing` に遷移 |
| `trialing` | 30 日無料トライアル中 | 課金開始日に注意 |
| `active` | 通常課金中 | 正常 |
| `past_due` | 支払い失敗・遅延 | テナントに連絡、Stripe Dashboard で支払い再試行 |
| `canceled` | 解約済み | 30 日後にデータアーカイブ |

### 1.3.3 テナントの編集

テナント名をクリックすると編集モーダルが開きます。

| フィールド | 編集可否 | 説明 | DB カラム |
|-----------|---------|------|-----------|
| テナント名 | ✅ | 変更可能 | `tenants.name` |
| スラッグ | ❌ | 読み取り専用 | `tenants.slug` |
| 店舗数 | ❌ | Stripe Subscription の quantity で自動同期 | `COUNT(stores)` |
| 本部配信アドオン | ❌ | テナント側「決済設定」タブから契約・解除 | `tenants.hq_menu_broadcast` |
| 有効/無効 | ✅ | テナント全体の有効/無効切替 | `tenants.is_active` |

**裏側 API：** `PATCH /api/posla/tenants.php` body `{id, name?, is_active?}`

::: warning テナント無効化の影響
テナントを `is_active=0` にすると、そのテナントの **全ユーザーがログインできなくなります**。次回ログイン試行時に「このテナントは無効化されています」エラーが表示されます。

無効化が影響する範囲：
- 全 owner / manager / staff / device ユーザー
- ハンディ・KDS・レジ端末（API 401 エラー）
- セルフオーダー（顧客側 QR スキャン）
- Stripe 課金は **継続** される（無効化と解約は別。解約は Stripe Dashboard で手動実行）
:::

### 1.3.4 テナントの作成

「テナント作成」タブで新規テナントを登録します。

| フィールド | 必須 | 形式 | 例 |
|-----------|------|------|---|
| スラッグ | ✅ | 英小文字・数字・ハイフンのみ。一意 | `shibuya-ramen` |
| テナント名 | ✅ | 企業/ブランド名 | `渋谷ラーメン` |
| プラン | レガシー | standard / pro / enterprise | （影響なし） |

**裏側 API：** `POST /api/posla/tenants.php`

**実行例：**
```bash
curl -X POST https://eat.posla.jp/public/api/posla/tenants.php \
  -H "Cookie: PHPSESSID=..." \
  -H "Content-Type: application/json" \
  -d '{"slug":"shibuya-ramen","name":"渋谷ラーメン","plan":"standard"}'
```

::: tip α-1：プラン選択は不要
2026-04-09 の α-1 構成移行により、テナント作成時の「プラン選択」は不要になりました。全テナントが単一プラン（基本料金 ¥20,000/月、全機能込み）で作成されます。本部一括メニュー配信アドオンが必要な場合は、テナント作成後にオーナーが「決済設定」タブから契約します。
:::

::: warning スラッグの命名規約（実例）
- ✅ OK: `matsunoya`, `momonoya`, `torimaru`, `shibuya-ramen-001`
- ❌ NG: `Matsunoya`（大文字）, `松の屋`（日本語）, `shibuya_ramen`（アンダースコア）, `shibuya ramen`（空白）

スラッグは **店舗用 URL の一部** として使用されるため変更不可。慎重に決定してください。
:::

---

## 1.4 API設定（タブ4）

「API 設定」タブで、POSLA 全体で共通利用する API キーを管理します。

### 1.4.1 管理対象のキー一覧

**AI・地図 API：**

| キー名 | 説明 | 取得元 |
|--------|------|---------|
| `gemini_api_key` | Google Gemini API キー（AI 機能全般に使用） | Google AI Studio |
| `google_places_api_key` | Google Places API キー（競合分析・店舗情報に使用） | Google Cloud Console |

**Stripe Billing（サブスクリプション・α-1 構成）：**

| キー名 | 説明 | プレフィックス |
|--------|------|---------|
| `stripe_secret_key` | Stripe のシークレットキー | `sk_live_…` / `sk_test_…` |
| `stripe_publishable_key` | Stripe の公開キー | `pk_live_…` / `pk_test_…` |
| `stripe_webhook_secret` | Webhook の署名検証シークレット | `whsec_…` |
| `stripe_price_base` | 基本料金の価格 ID（¥20,000/月、quantity=1 固定） | `price_…` |
| `stripe_price_additional_store` | 追加店舗の価格 ID（¥17,000/月、quantity = 店舗数 - 1） | `price_…` |
| `stripe_price_hq_broadcast` | 本部一括配信アドオンの価格 ID（¥3,000/月、quantity = 店舗数。アドオン契約時のみ） | `price_…` |

**Stripe Connect（決済手数料）：**

| キー名 | 説明 | デフォルト |
|--------|------|---------|
| `connect_application_fee_percent` | テナント決済に対する POSLA の手数料率（%） | 1.0 |

**スマレジ連携：**

| キー名 | 説明 |
|--------|------|
| `smaregi_client_id` | スマレジ OAuth クライアント ID |
| `smaregi_client_secret` | スマレジ OAuth クライアントシークレット |

**運用監視 I-1（F-MV1）：**

| キー名 | 説明 |
|--------|------|
| `slack_webhook_url` | 異常検知アラートの通知先 Slack Incoming Webhook URL |
| `ops_notify_email` | Hiro ほか運営チームの通知先メールアドレス（連続 3 件以上のエラー時） |

詳細は [7. 運用監視エージェント](./07-monitor.md) 参照。

### 1.4.2 キーの表示とマスキング

API キー / シークレットはセキュリティのため**実値を一切返さない設計**です。GET レスポンスには `set`（boolean）と `masked`（マスク文字列）のみ含まれ、画面上にも実値は復元できません。

**マスキング形式：**
- 16文字以上: 先頭 8 文字 + `***...***` + 末尾 4 文字
- 16文字未満: `********`

| 元キー | 表示例 |
|---|---|
| `sk_test_1234567890ABCDEF` | `sk_test_***...CDEF` |
| `pk_live_abcd9876efgh5432` | `pk_live_***...5432` |
| `whsec_xxxxxxxx`（短い） | `********` |

- 表示専用 UI（目アイコンによる実値表示）は撤去されました。実値の確認はできません
- **キーを再設定するときは新値を入力欄に貼って保存。空欄送信は現状維持（誤削除防止）**
- **「APIキーを削除」** ボタンは AI / Stripe Billing 系のみを NULL 化します（対象: `gemini_api_key`, `google_places_api_key`, `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `stripe_price_base`, `stripe_price_additional_store`, `stripe_price_hq_broadcast`）。スマレジ連携キーや監視系シークレットは対象外で、個別に空欄送信ではなく明示的に値を上書きする運用です

**裏側 API：** `GET /api/posla/settings.php`（実値非返却・`set/masked` 構造のみ）/ `PATCH /api/posla/settings.php`（更新・空文字/未指定は現状維持）

### 1.4.3 手数料計算の仕組み

Stripe Connect の手数料は以下の計算式で算出されます。

```
application_fee_amount = ceil(charge_amount × fee_percent / 100)
最低手数料 = ¥1
```

**計算例：**

| 決済金額 | 手数料率 | 計算 | application_fee |
|---|---|---|---|
| ¥3,000 | 1.0% | `ceil(30) = 30` | ¥30 |
| ¥1,500 | 1.0% | `ceil(15) = 15` | ¥15 |
| ¥99 | 1.0% | `ceil(0.99) = 1` | ¥1（最低保証） |
| ¥10,000 | 1.5% | `ceil(150) = 150` | ¥150 |

### 1.4.4 API キー設定手順（実例）

#### Gemini API キーの設定

1. Google AI Studio にアクセス: https://aistudio.google.com/app/apikey
2. 「Create API key」→ 既存 GCP プロジェクト `posla-prod` を選択
3. 生成された `AIzaSy...` を コピー
4. POSLA 管理画面 → API 設定タブ → Gemini API キー欄にペースト
5. 「保存」をクリック
6. 「現在の設定」表で `AIzaSy●●●●●●●●` と表示されたら成功

#### Stripe キーの設定

1. Stripe Dashboard → 開発者 → API キー
2. シークレットキー（`sk_live_…`）をコピー
3. POSLA 管理画面 → API 設定タブ → Stripe Secret Key 欄にペースト
4. 同様に Publishable Key、Webhook Secret も設定
5. Price ID は事前に Stripe Dashboard → 商品から作成し、`price_…` をコピー

---

## 1.5 POSLA 管理画面の日次オペレーション

### 1.5.1 朝のチェックリスト（毎営業日 9:00 まで）

1. **POSLA ダッシュボード** で前日の統計確認:
   - 新規申込数（前日比）
   - アクティブテナント数（前日比 ±）
   - 売上合計（全テナント、Stripe Dashboard で確認）
   - エラー件数（`monitor_events`）
2. **monitor_events** を `GET /api/posla/monitor-events.php?since=YYYY-MM-DD` で確認
   - severity = critical があれば **即対応**
   - severity = error は 1 時間以内に確認
3. **info@posla.jp** メール確認・対応
4. Slack `#posla-alerts` チャンネル確認

### 1.5.2 営業中の対応

- 30 分毎に `monitor_events` を確認（critical があれば即対応）
- お客様からの問い合わせは原則 4 時間以内に回答
- Stripe Dashboard で `past_due` 状態のテナントを確認 → テナントに支払い再試行を依頼

### 1.5.3 夜のクローズ作業

1. その日の重要対応をログに残す（社内 Notion `POSLA-Operations` ページ）
2. 明日のフォローアップ予定をリマインド設定
3. 重大インシデントがあれば運営チーム全体に共有
4. monitor_events で対応済みのものは PATCH で `resolved=1` に更新

---

## 1.6 緊急時の対応フロー

### 1.6.1 サーバーダウン検知時

1. UptimeRobot からメールアラート受信
2. Sakura コンパネで状態確認: https://secure.sakura.ad.jp/
3. 必要なら運営チームメンバー全員に Slack で連絡
4. 復旧作業 → 復旧告知メール

### 1.6.2 個別テナントの問題

1. テナント名・店舗名を特定（問い合わせメールから抽出）
2. POSLA 管理画面 → テナント管理 → 該当テナントを検索
3. 該当テナントの DB 状態を確認（phpMyAdmin）
   - `SELECT * FROM tenants WHERE slug = 'matsunoya'`
   - `SELECT * FROM stores WHERE tenant_id = ?`
   - `SELECT * FROM users WHERE tenant_id = ? AND is_active = 1`
4. ログを確認（`/home/odah/log/php_errors.log`）
5. 必要なら DB 直接修復 or 機能調整

### 1.6.3 セキュリティインシデント

1. 即時に問題のある機能を `.htaccess` で 503 切替
2. 全運営チームに緊急 Slack 通知（`@here`）
3. 影響範囲調査
4. 修正後にテスト → 段階的ロールアウト
5. 事後インシデントレポート作成（Notion `Incidents-2026` ページ）

---

## 1.7 POSLA 管理者アカウントの追加

### 1.7.1 SQL 直接追加（推奨方法、setup.php は初回のみ）

```sql
INSERT INTO posla_admins (id, email, password_hash, display_name, is_active)
VALUES (
  UUID(),
  'newadmin@plusbelief.co.jp',
  '$2y$10$...',  -- PHP の password_hash で生成
  '新管理者名',
  1
);
```

**ハッシュ生成のワンライナー（PHP CLI）：**
```bash
php -r 'echo password_hash("InitialPw2026", PASSWORD_DEFAULT) . PHP_EOL;'
```

### 1.7.2 推奨運用

- 最低 2 名の管理者アカウントを常時保持（パスワード忘れ対策）
- 退職時はアカウントを `is_active=0` に変更
- パスワードは半年〜1 年に 1 回変更
- 同一人物が個人 email と会社 email で複数アカウント保持しない

---

## 1.8 AI ヘルプデスク FAB（helpdesk_internal モード）

POSLA 管理画面右下の **オレンジ色 ? ボタン** から、社内向け knowledge base を参照できる AI ヘルプデスクを利用できます。

### 1.8.1 internal モード vs tenant モードの違い

| 項目 | tenant モード | internal モード |
|---|---|---|
| 設置場所 | 管理ダッシュボード（テナント側）| POSLA 管理画面（社内）|
| Knowledge base | tenant + operations 章のみ | tenant + operations + internal 8 章 + SYSTEM_SPECIFICATION |
| 利用者 | 飲食店オーナー・マネージャー | プラスビリーフ社員 |
| プロンプトファイル | `helpdesk-prompt-tenant.txt` | `helpdesk-prompt-internal.txt` |
| 切替フラグ | `window.POSLA_HELPDESK_MODE = 'helpdesk_tenant'` | `window.POSLA_HELPDESK_MODE = 'helpdesk_internal'` |

### 1.8.2 主な使い方（社内）

- 「テナント XXX から〜という問い合わせがあった。対応手順は？」
- 「〜の DB 構造を教えて」
- 「stripe_subscription_id が NULL になっているテナントの修復 SQL は？」

### 1.8.3 internal モード固有の機能

- DB スキーマ・SQL クエリ・API エンドポイント詳細を含めた回答
- 内部章（01-posla-admin、02-onboarding、05-troubleshoot 等）を参照
- 顧客向け回答テンプレートを生成（コピペ可）

---

## 1.9 よくある質問（FAQ 25 件）

### Q1. Basic 認証の ID/PW を忘れた

A. 社内 1Password Vault `POSLA-Internal` を確認してください。1Password アクセス権がない場合は技術責任者（Hiro）に依頼。

### Q2. パスワードを忘れた

A. 別の管理者に依頼して `posla_admins.password_hash` を SQL で更新してもらう。単一管理者の場合は緊急復旧 SQL（1.1.3 参照）を Sakura phpMyAdmin で実行。

### Q3. テナントを完全に削除したい

A. **削除は推奨しません**（履歴データの参照不能になる）。代わりに `is_active=0` で無効化してください。本当に削除する場合は段階的に: orders → audit_logs → users → stores → tenants の順で `DELETE`。

### Q4. テナントのスラッグを変更したい

A. **変更不可**（URL に組み込まれているため、変更すると全リンク・QR コードが壊れる）。どうしても必要な場合は新スラッグでテナント新規作成 → データ移行 → 旧テナント無効化の手順を踏む。

### Q5. テナントの Stripe Customer ID を確認したい

A. SQL: `SELECT slug, name, stripe_customer_id FROM tenants WHERE slug = ?`。Stripe Dashboard 上では Customer ID で検索可能。

### Q6. テナントから「ログインできない」と問い合わせ

A. 確認項目：
1. `tenants.is_active = 1` か
2. `users.is_active = 1` か
3. `subscription_status` が `past_due` でないか
4. 該当ユーザーのアカウントロックがないか（`failed_login_count` 確認）

### Q7. 月次の売上はどう確認する？

A. Stripe Dashboard → 分析 → MRR（Monthly Recurring Revenue）を参照。POSLA ダッシュボードには現状売上指標は表示されません（将来追加予定）。

### Q8. 新規申込が 0 件 / 異常に少ない

A. `signup` レートリミット（5 回/時/IP）に引っかかっている可能性、または LP リンク切れ。LP `public/index.html` の確認 + `/api/signup/register.php` の動作確認を実施。

### Q9. monitor_events の critical が出続ける

A. severity=critical はサーバー全体に影響する重大障害（DB 接続失敗、Stripe Webhook 検証失敗連続等）。即時に技術責任者（Hiro）に Slack DM。

### Q10. テナント作成時に「DUPLICATE_SLUG」エラー

A. 同一スラッグが既に存在。`SELECT slug FROM tenants WHERE slug = ?` で確認 → 別スラッグを推奨。

### Q11. API キー保存後に「未設定」と表示される

A. ブラウザキャッシュ。Cmd+Shift+R（強制リロード）で再読み込み。それでも未設定なら DB 直接確認: `SELECT * FROM posla_settings WHERE setting_key = 'gemini_api_key'`。

### Q12. Gemini API キーを差し替えたい

A. 新キーを発行 → POSLA 管理画面 API 設定タブで上書き保存。旧キーは Google AI Studio で「Disable」にして無効化。

### Q13. POSLA 管理画面が真っ白になる

A. JS エラーの可能性。Chrome DevTools → Console を開いて確認。よくある原因：
- `posla-app.js` の `?v=…` キャッシュバスター更新漏れ
- `Utils` 未読み込み（`/public/shared/js/utils.js` の存在確認）

### Q14. テナントが「店舗追加できない」と問い合わせ

A. Stripe Subscription quantity と stores 数を比較。quantity が増えていない場合は Stripe Customer Portal でテナントが手動増加する必要あり（テナント側「決済設定」タブから）。

### Q15. テナント側 owner-dashboard で「本部メニュー」タブが出ない

A. `tenants.hq_menu_broadcast = 0` の状態。アドオン契約が必要。テナントに「決済設定」タブから本部一括配信アドオン追加を案内。

### Q16. POSLA 管理画面で店舗を直接操作したい

A. 現状未対応（テナント管理のみ）。SQL 直接操作 or 該当テナントの owner として代理ログイン（メンテナンスモード経由、要承認）。

### Q17. テナントのアドオン契約状態を SQL で一括確認

A.
```sql
SELECT slug, name, hq_menu_broadcast, subscription_status
FROM tenants
WHERE is_active = 1
ORDER BY hq_menu_broadcast DESC, name;
```

### Q18. POSLA 管理者数を増やしたい（一気に 5 人）

A. 1.7.1 の SQL を 5 回繰り返す。各人の email と password を 1Password で共有。

### Q19. 2 段階認証（2FA）を導入したい

A. 2026-Q3 計画。現状未対応。代替策として Basic 認証 + 強固なパスワード + IP 制限を併用。

### Q20. ステージング環境で本番データを使いたい

A. **禁止**（個人情報・決済情報を含むため）。ステージングは独立 DB（`odah_eat-posla-staging`）+ ダミーデータ運用。

### Q21. monitor_events を 30 日以上前まで遡って確認したい

A. `?since=2026-01-01` 等で since パラメータを指定。デフォルトは過去 7 日。

### Q22. テナントの所属ユーザー一覧を確認したい

A.
```sql
SELECT u.username, u.email, u.role, u.is_active, u.last_login_at
FROM users u
JOIN tenants t ON u.tenant_id = t.id
WHERE t.slug = 'matsunoya'
ORDER BY u.role, u.username;
```

### Q23. POSLA 管理画面のセッションが頻繁に切れる

A. セッション有効時間は 8 時間。8 時間超で自動ログアウトは仕様。長時間作業する場合は途中で操作を入れる（API コールでセッション延長）。

### Q24. AI ヘルプデスク FAB が出ない

A. 確認項目：
1. `<script>window.POSLA_HELPDESK_MODE = 'helpdesk_internal';</script>` が dashboard.html 末尾にあるか
2. `posla-helpdesk-fab.js` の読み込みが成功しているか（DevTools Network）
3. `posla_settings.gemini_api_key` が設定されているか

### Q25. AI ヘルプデスクが「AI_NOT_CONFIGURED」エラー

A. `posla_settings.gemini_api_key` 未設定。1.4.4 の手順で設定してください。

---

## 1.10 トラブルシューティング表

| 症状 | 原因の候補 | 確認手順 | 対応 |
|------|----------|---------|------|
| ログイン画面で「メール or パスワードが正しくない」 | 入力ミス / アカウント `is_active=0` | `SELECT is_active FROM posla_admins WHERE email=?` | 入力再確認 / アクティブ化 |
| ダッシュボードが空白 | API 401 | DevTools Network で確認 | 再ログイン |
| テナント一覧が読み込まれない | DB 接続失敗 | `/home/odah/log/php_errors.log` | DB サーバー確認 |
| API キー保存が反映されない | ブラウザキャッシュ | Cmd+Shift+R | 強制リロード |
| 「現在のパスワードが正しくありません」 | 入力ミス / 大文字小文字 | 別ブラウザで再ログイン試行 | 入力再確認 |
| AI ヘルプデスク FAB で 503 エラー | gemini_api_key 未設定 | `SELECT setting_value FROM posla_settings WHERE setting_key='gemini_api_key'` | API 設定タブで登録 |
| Stripe Webhook 失敗 | webhook secret 不一致 | Stripe Dashboard → Webhook ログ | secret 再取得・更新 |
| テナント作成で 500 エラー | slug 重複 / DB 制約違反 | `php_errors.log` | エラー詳細を確認 |
| モーダルが閉じない | JS エラー | DevTools Console | ページ再読み込み |
| Connect 状態が「登録中」のまま | テナントがオンボーディング中断 | Stripe Connect Dashboard | テナントに再開を依頼 |

---

## 1.11 想定問い合わせ別 対応テンプレート

### 1.11.1 「Stripe 決済が失敗する」

1. Stripe Dashboard でテナントの Customer 検索
2. 直近の Charge / PaymentIntent エラー詳細を確認
3. テナントに以下を確認:
   - クレジットカード有効期限
   - カード会社からの拒否（電話確認）
   - 3D セキュア完了
4. 必要なら別カードで再試行を案内

### 1.11.2 「アカウントロックされた」

1. `SELECT failed_login_count FROM users WHERE username=?` で確認
2. 5 回以上失敗ロック中なら SQL でリセット:
   ```sql
   UPDATE users SET failed_login_count = 0 WHERE username = ?
   ```
3. テナントに再ログインを依頼

### 1.11.3 「データが消えた」

1. **絶対に「消えてません」と即答しない**。必ず DB を確認
2. `SELECT … FROM orders WHERE store_id=? AND created_at >= ?` 等で実存確認
3. soft delete（`is_active=0`）の可能性も確認
4. バックアップ（毎日 03:00 自動取得）から復元可能

---

## X.X 更新履歴
- **2026-04-19**: 1.0 事前準備 / 1.8 AI ヘルプデスク / 1.9 FAQ / 1.10 トラブルシュート / 1.11 対応テンプレート 追加（Phase B Batch-13）
- **2026-04-19**: 1.5 日次オペレーション / 1.6 緊急時対応 / 1.7 管理者追加 拡充
- **2026-04-18**: frontmatter 追加、AI ヘルプデスク knowledge base として整備
