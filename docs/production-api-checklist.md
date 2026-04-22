# POSLA 本番移行 API変更チェックリスト

> 作成日: 2026-04-05（最終更新: 2026-04-22 本番インフラ未確定前提で vendor-neutral 化）
> 目的: 開発環境→本番環境に移行する際、変更が必要なAPI URL・設定値・キーの完全一覧
>
> ⚠️ **現行 sandbox は さくらのレンタルサーバ（`odah@odah.sakura.ne.jp` / `/home/odah/www/eat-posla/`）を使用中**。本ドキュメント中の `/home/odah/www/eat-posla/` / `odah.sakura.ne.jp` / `mysql80.odah.sakura.ne.jp` は **現行 sandbox の参考情報**で、本番では `<ssh-user>@<host>` / `/<deploy-root>/` / 本番 DB ホストに置換してください。切替フロー自体は vendor-neutral。

---

## 1. スマレジ連携（L-15）— `.dev` → `.jp` 変更

**5箇所、3ファイル**

| # | ファイル | 行 | 変更前（開発） | 変更後（本番） |
|---|---------|-----|--------------|--------------|
| 1 | `api/lib/smaregi-client.php` | L112 | `https://id.smaregi.dev/authorize/token` | `https://id.smaregi.jp/authorize/token` |
| 2 | `api/lib/smaregi-client.php` | L207 | `https://api.smaregi.dev/` | `https://api.smaregi.jp/` |
| 3 | `api/smaregi/auth.php` | L40 | `https://id.smaregi.dev/authorize?` | `https://id.smaregi.jp/authorize?` |
| 4 | `api/smaregi/callback.php` | L64 | `https://id.smaregi.dev/authorize/token` | `https://id.smaregi.jp/authorize/token` |
| 5 | `api/smaregi/callback.php` | L114 | `https://id.smaregi.dev/userinfo` | `https://id.smaregi.jp/userinfo` |

**追加作業:**
- スマレジ開発者ダッシュボードで本番アプリを登録
- 本番用 Client ID / Client Secret を POSLA管理画面で設定
- 各テナントが本番環境でOAuth再認証（アクセストークン再取得）
- 店舗マッピング再設定 + メニュー再インポート

詳細: `docs/L15-smaregi-troubleshooting-log.md`「本番移行手順」セクション

---

## 2. Gemini API（AI機能全般 + L-4翻訳 + 管理画面AIアシスタント）— 変更不要だが確認必須

**APIエンドポイントの変更は不要**（開発・本番で同じURL）

| ファイル | 用途 | URL |
|---------|------|-----|
| `api/customer/ai-waiter.php` | お客様向けAIウェイター | `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent` |
| `api/kds/ai-kitchen.php` | KDS AI優先度判定 | 同上 |
| `api/store/translate-menu.php` | L-4 一括翻訳 | 同上 |
| `api/store/ai-generate.php` | 管理画面AIアシスタント統一プロキシ（P1-6） | 同上 |

::: tip サーバープロキシ経由（P1-6）
P1-6 で管理画面のAIアシスタント（SNS生成・売上分析・需要予測）はすべて `api/store/ai-generate.php` 経由に統一されました。`public/admin/js/ai-assistant.js` から Gemini API を直接呼び出さず、サーバー側プロキシでAPIキーを保護します。
:::

**確認事項:**
- `posla_settings` テーブルの `gemini_api_key` に本番用APIキーが設定されていること（POSLA管理画面 > API設定タブ）
- Google Cloud Console でAPIキーの制限設定:
  - HTTPリファラー制限（サーバーサイドなのでIP制限推奨）
  - API制限: Generative Language API のみ
- 課金設定: 従量課金が有効であること（無料枠超過時にエラーにならないように）
- レートリミット: Gemini 2.0 Flash の RPM/TPM 上限を確認
- フロントエンド（admin系JS）から Gemini API を直接叩いていないこと（`grep generativelanguage public/admin/js/*.js` の結果が0件）

---

## 3. Stripe 決済（L-11 サブスク / L-13 Connect / C-3 決済端末）

**APIエンドポイントの変更は不要**（Stripe PHP SDKがテスト/本番をキーで自動判別）

| 設定箇所 | 設定キー | 変更内容 |
|---------|---------|---------|
| `posla_settings` | `stripe_secret_key` | テストキー `sk_test_...` → 本番キー `sk_live_...` |
| `posla_settings` | `stripe_publishable_key` | テストキー `pk_test_...` → 本番キー `pk_live_...` |
| `posla_settings` | `stripe_webhook_secret` | テスト用 `whsec_...` → 本番用 `whsec_...` |
| `posla_settings` | `stripe_price_base` | 1店舗目の月額料金 Price ID（テスト用 → 本番用） |
| `posla_settings` | `stripe_price_additional_store` | 2店舗目以降（15%引）の Price ID（テスト用 → 本番用） |
| `posla_settings` | `stripe_price_hq_broadcast` | 本部一括メニュー配信アドオンの Price ID（任意・テスト用 → 本番用） |
| `posla_settings` | `connect_application_fee_percent` | 手数料率（数値は変更不要だが確認） |

**追加作業:**
- Stripe ダッシュボードで本番モードに切り替え
- 本番用 Webhook エンドポイントを登録
- 本番用 Price（サブスクプラン）を作成
- Connect の本番申請（StripeからのKYC審査あり）
- テナントごとの Connect アカウントは再オンボーディング不要（テスト中に作成したものは本番では使えないので新規作成）

---

## 4. Stripe パターンA（テナント自前キー、C-3）

**テナント個別設定**（POSLA共通ではない）

| 設定箇所 | 変更内容 |
|---------|---------|
| `tenants.stripe_secret_key` | テスト用 `sk_test_xxx` → 本番用 `sk_live_xxx` |
| `tenants.payment_gateway` | 値は `stripe` のまま（変更不要） |

**追加作業:**
- 各テナントがStripe Dashboardで本番モードに切り替え、`charges_enabled` を確認
- 本番用 Secret Key を owner-dashboard の「決済設定」タブで入力
- Stripe Dashboard > Terminal を有効化（カードリーダー利用時）

**動作確認:**
- `/api/connect/status.php` で `terminal_pattern: 'A'` が返ること（ただしパターンBと両方設定されている場合はBが優先されるので注意）
- レジ会計画面で Stripe Terminal 初期化が成功すること
- 少額テスト決済後、テナント自身のStripe残高に入金されること（application_feeは引かれない）

**Squareサポートについて:**
- 2026年4月のP1-2で Square サポートは完全削除されました
- `square_access_token` / `square_location_id` カラムは DROP 済み
- `payment_gateway` ENUM から `'square'` を除外済み
- 過去の決済履歴（`payments.gateway_name='square'`）は残っている可能性がありますが参照はしていません

---

## 5. Google Places API（L-7 Googleレビュー連携）

**APIエンドポイントの変更は不要**

| ファイル | 用途 |
|---------|------|
| `api/store/places-proxy.php` | Google Places API プロキシ |

**確認事項:**
- `posla_settings` の `google_places_api_key` に本番用キーが設定されていること
- Google Cloud Console でAPI制限設定（HTTPリファラー or IP制限）
- Places API の課金有効化

---

## 6. L-4 多言語対応 — 本番で必要な作業

**コード変更: なし**（開発・本番で同一コード）

**本番デプロイ手順:**
1. `sql/migration-l4-translations.sql` を本番DBに適用
2. `posla_settings` の `gemini_api_key` が設定されていることを確認（上記 #2）
3. 管理画面の「🌐 一括翻訳」ボタンで各店舗のメニューを翻訳実行
4. セルフオーダー画面で5言語切り替えを確認

**デプロイファイル:**
- `sql/migration-l4-translations.sql` （DB）
- `api/store/translate-menu.php` （新規）
- `api/customer/ui-translations.php` （新規）
- `api/lib/menu-resolver.php` （変更）
- `public/customer/menu.html` （変更）
- `public/admin/dashboard.html` （変更）

---

## 7. 本番環境でのみ必要な設定

### 7-1. POSLA管理画面（`/public/posla-admin/`）で設定する共通キー

| `posla_settings` キー | 用途 | 本番で必要な操作 |
|----------------------|------|----------------|
| `gemini_api_key` | AI全機能 + L-4翻訳 | 本番用Gemini APIキーを入力 |
| `google_places_api_key` | レビュー連携 | 本番用Places APIキーを入力 |
| `stripe_secret_key` | サブスク課金 | `sk_live_...` を入力 |
| `stripe_publishable_key` | Checkout UI | `pk_live_...` を入力 |
| `stripe_webhook_secret` | Webhook検証 | 本番Webhook設定後のシークレット |
| `stripe_price_base` | 基本料金（1店舗目） | 本番用Price IDを入力 |
| `stripe_price_additional_store` | 追加店舗（2店舗目以降・15%引） | 本番用Price IDを入力 |
| `stripe_price_hq_broadcast` | 本部一括メニュー配信アドオン（任意） | 本番用Price IDを入力 |
| `connect_application_fee_percent` | Connect手数料 | 手数料率を入力（例: 3.5） |
| `smaregi_client_id` | スマレジ連携 | 本番アプリのClient ID |
| `smaregi_client_secret` | スマレジ連携 | 本番アプリのClient Secret |

### 7-2. 実作業が必要な箇所（まとめ、2026-04-22 更新）

| 作業 | ソースコード変更 | 設定変更箇所 | 備考 |
|---|---:|---:|---|
| サーバ移行 / 本番ドメイン切替 | 0ファイル | 2 箇所 | `api/.htaccess` + cron 実行環境 |
| Stripe / Gemini / Places / Slack 等 | 0ファイル | DB / 外部管理画面 | キー / Webhook / Price ID の切替 |
| スマレジ sandbox → production | 3ファイル | 5 箇所 | `.dev` → `.jp` は別論点 |
| VitePress マニュアル例示 URL の更新 | docs のみ | 任意 | 動作影響なし、整合性向上 |

> ⚡ 2026-04-22 時点では、本番ドメイン切替のために `api/config/app.php` を直接編集する必要はありません。  
> ⚡ 現在の runtime は `api/.htaccess` と cron env の切替で追随します。  
> ⚡ Stripe / Gemini / Google Places はキーや webhook の切替のみで、サーバ移行自体のソース修正は不要です。  
> ⚡ スマレジを live 運用に切り替える場合だけ `.dev` → `.jp` の別作業が残ります。

### 7-3. マイグレーションSQL 実行順序（未適用のもの）

本番DBに未適用のマイグレーションがある場合、以下の順序で適用:

```
sql/migration-l4-translations.sql    ← L-4 多言語対応（今回追加）
```

※ 他のマイグレーションは既に適用済みかを確認すること

---

## 8. 本番移行の推奨手順（全体フロー、2026-04-22 更新）

```
1.  コードデプロイ（全ファイルアップロード）
2.  server の api/.htaccess を本番値で作成 / 更新
3.  cron 実行環境へ同じ env（特に DB + APP/MAIL/ORIGIN）を設定
4.  DB restore + 未適用マイグレーション実行
5.  POSLA管理画面 / DB で共通設定を確認（gemini, stripe, smaregi, slack, vapid 等）
6.  Stripe本番Webhook / Connect / 顧客返却URL を本番ドメインで再設定
7.  smaregi を live で使う場合のみ `.dev` → `.jp` 切替 + redirect_uri 登録 + OAuth 再認証
8.  DNS / SSL / 外部監視を本番ドメインへ切替
9.  VitePress マニュアルの例示URLを更新する場合は再ビルド
10. 動作確認: セクション 9-4 のチェックリストをすべて実施
```

---

## 9. 本番ドメイン切替 / サーバ移行（現行 sandbox `eat.posla.jp` → 本番ドメイン）

> 現行 sandbox ドメインは `eat.posla.jp`（ピリオド）。
> 現行 sandbox サーバ側ディレクトリ `/home/odah/www/eat-posla/` は さくらのレンタルサーバのディレクトリ名（こちらはハイフン、参考情報）。
> 本番ドメインと本番インフラが確定したら、本セクションの手順に従って切り替える。vendor-neutral な切替フローとして読み替え可能。

### 9-1. env 供給手段の選択（現行 sandbox vs 本番 VPS）

POSLA のアプリは **env-first 設計**です（`api/config/database.php` / `api/config/app.php` が `getenv()` で供給値を読む）。**env をどう供給するかは本番 infra の選択**で、以下のいずれかを使います。

| supplier | 向き先 | 特徴 |
|---|---|---|
| `api/.htaccess` の `SetEnv` | 共有レンタルサーバ（現行 sandbox） | さくらの共有サーバでは事実上これしか選べない |
| Apache vhost の `SetEnv` | VPS の Apache + mod_php | `.htaccess` より管理コストが低い |
| Nginx + PHP-FPM pool env (`www.conf` の `env[...]`) | VPS の Nginx + PHP-FPM | pool ごとに分離可、secret の集約監査性が良い |
| systemd unit `EnvironmentFile` | systemd 下のサービス | php-fpm や custom service を systemd で管理する場合 |
| env file + `source` wrapper | cron / CLI | **CLI 経路に env を渡す唯一の現実的手段**（`.htaccess` は CLI で読まれない）|

**推奨**:
- **現行 sandbox**: `api/.htaccess` SetEnv（共有サーバ制約、template は `api/.htaccess.example` 参照）
- **本番 VPS**: server-level env（vhost / pool / systemd）を正、`.htaccess` は持ち込まない
- **cron / CLI**: HTTP 側の supplier に関係なく**必ず別途 env を供給**（wrapper or crontab 直書き or systemd）

詳細・比較表・推奨しない配置の注意点は internal docs の [5.3.5 env 供給手段の選択肢](./manual/internal/05-operations.md#535-env-供給手段の選択肢現行-sandbox-vs-本番-vps-推奨) を参照。

### 9-1a. 現行 sandbox / 同等の共有レンタルサーバ: `api/.htaccess` の例

> 現行 sandbox ドメインは `eat.posla.jp`、現行 sandbox サーバ側ディレクトリは `/home/odah/www/eat-posla/`（参考情報）。  
> **現行 sandbox の移行時は `api/.htaccess`** が本命ファイル、通常は PHP ソースを直接編集しない。
> **本番 VPS では §9-1 の推奨に従い `.htaccess` ではなく server-level env を使うこと**（以下の SetEnv 例は shared hosting / 検証 VPS の template として流用可）。

修正対象:

| 設定名 | 用途 | 設定場所 |
|---|---|---|
| `POSLA_DB_HOST` | MySQL ホスト | `api/.htaccess` |
| `POSLA_DB_NAME` | DB 名 | `api/.htaccess` |
| `POSLA_DB_USER` | DB ユーザー | `api/.htaccess` |
| `POSLA_DB_PASS` | DB パスワード | `api/.htaccess` |
| `POSLA_APP_BASE_URL` | 正規ベース URL | `api/.htaccess` |
| `POSLA_FROM_EMAIL` | システム送信元メール | `api/.htaccess` |
| `POSLA_SUPPORT_EMAIL` | 利用者向け問い合わせ先 | `api/.htaccess` |
| `POSLA_ALLOWED_ORIGINS` | CORS 許可 Origin | `api/.htaccess` |
| `POSLA_ALLOWED_HOSTS` | CSRF 許可 host | `api/.htaccess` |
| `POSLA_CRON_SECRET` | HTTP cron 共有秘密 | `api/.htaccess`（HTTP cron を使う場合） |

例:

```apache
SetEnv POSLA_DB_HOST  mysql80.example.ne.jp
SetEnv POSLA_DB_NAME  posla_prod
SetEnv POSLA_DB_USER  posla_prod
SetEnv POSLA_DB_PASS  __REPLACE_WITH_PASSWORD__

SetEnv POSLA_APP_BASE_URL  https://example.com
SetEnv POSLA_FROM_EMAIL  noreply@example.com
SetEnv POSLA_SUPPORT_EMAIL  info@example.com
SetEnv POSLA_ALLOWED_ORIGINS  https://example.com
SetEnv POSLA_ALLOWED_HOSTS  example.com

# HTTP 経由で cron を叩く場合のみ
SetEnv POSLA_CRON_SECRET  __REPLACE_WITH_CRON_SECRET__
```

**注意**:

- `api/config/database.php` と `api/config/app.php` は env-first。**通常は編集しない**
- `api/.htaccess` は git 管理外。server 上で直接管理する
- `api/.htaccess.example` はテンプレート、`api/.htaccess.current.local` はローカル補助ファイル

### 9-2. 必須変更 — cron 実行環境

CLI の PHP は `.htaccess` を読みません。  
そのため、予約通知・監視メール・DB 接続がある cron は **`api/.htaccess` と同じ値を crontab / wrapper script にも渡す**必要があります。

最低限、以下は cron 側へ複製する:

```bash
POSLA_DB_HOST=...
POSLA_DB_NAME=...
POSLA_DB_USER=...
POSLA_DB_PASS=...
POSLA_APP_BASE_URL=https://example.com
POSLA_FROM_EMAIL=noreply@example.com
POSLA_SUPPORT_EMAIL=info@example.com
POSLA_ALLOWED_ORIGINS=https://example.com
POSLA_ALLOWED_HOSTS=example.com
```

`POSLA_CRON_SECRET` は **HTTP 経由で `api/cron/*.php` を叩く場合のみ**必須。CLI 実行だけなら不要。

**つまり server 移行の core は `api/.htaccess` + cron env の 2 箇所** です。

#### 9-2-2. ドキュメント側（VitePress マニュアルビルド成果物、任意）

`docs/manual/` 配下のマニュアル（VitePress ソース）には例として `eat.posla.jp` が記載されている箇所があり、ビルド成果物は tenant 向けが `public/docs-tenant/`、internal 向けが `public/docs-internal/` にデプロイされる:

| # | ファイル | 種別 | 対応方法 |
|---|---------|------|---------|
| 1 | `public/docs-tenant/tenant/21-stripe-full.html` | VitePress ビルド成果物 | ソース `docs/manual/tenant/21-stripe-full.md` を更新 → 再ビルド |
| 2 | `public/docs-tenant/tenant/22-smaregi-full.html` | 同上 | ソース `docs/manual/tenant/22-smaregi-full.md` を更新 → 再ビルド |
| 3 | `public/docs-internal/internal/03-billing.html` など | 同上 | ソース `docs/manual/internal/*.md` を更新 → 再ビルド |
| 4 | `public/docs-tenant/assets/*.js` | VitePress バンドル | tenant 再ビルドで自動更新 |
| 5 | `public/docs-internal/assets/*.js` | 同上 | internal 再ビルドで自動更新 |

**手順:** マニュアルソース（`docs/manual/**/*.md`）を本番ドメインで grep → 置換 → `npm run build:internal` / `npm run build:tenant` で再ビルド → `public/docs-*` を再デプロイ。

> ⚡ アプリケーション動作には影響しない（マニュアルの記載例のみ）。本番運用上は必須ではないが、ドキュメント整合性のため推奨。

#### 9-2-3. デモガイド・サンプルURL

| # | ファイル | 対応方法 |
|---|---------|---------|
| 1 | `docs/demo-guide.html` | 本タスク対象外（`docs/` 配下、デモ環境向け資料）。本番切替時にデモ環境を残すか撤去するか別途判断 |

### 9-3. インフラ・外部サービス側の作業

- [ ] **DNS 切替**: 本番ドメインの A レコード or CNAME を POSLA サーバに向ける
- [ ] **SSL証明書**: Let's Encrypt or 商用証明書を本番ドメインで発行・配置
- [ ] **Stripe Webhook 再登録**: Stripe Dashboard > Webhooks で本番ドメイン宛のエンドポイント（`https://本番ドメイン/api/stripe/webhook.php` 等）を新規登録。旧 `eat.posla.jp` 宛は無効化 or 削除
- [ ] **Apple Pay ドメイン認証**: Stripe Checkout 経由なら Stripe 側で自動。Payment Element 等で独自実装している場合は `apple-developer-merchantid-domain-association` ファイルを `/.well-known/` に配置する必要あり
- [ ] **Stripe 顧客返却 URL 確認**: Stripe Dashboard で設定している顧客返却URL（カスタマーポータルの戻りURL等）を本番ドメインに更新
- [ ] **smaregi デベロッパーダッシュボード**: 本番アプリの redirect_uri を `https://本番ドメイン/api/smaregi/callback.php` に登録（既存テスト環境用 `https://eat.posla.jp/api/smaregi/callback.php` は残してもよい）
- [ ] **Google Cloud Console**: Gemini API キー / Places API キーの HTTP リファラー制限を本番ドメインに更新（IP制限の場合は不要）
- [ ] **SendGrid / メール配信サービス**（使用していれば）: Sender Domain Authentication を本番ドメインで設定
- [ ] **Cookie ドメイン設定**: PHP セッションクッキーに `domain` 指定がある場合、本番ドメインに更新（現行コードは `domain` 未指定でブラウザ自動推定なので変更不要）
- [ ] **CORS 設定**: もし `Access-Control-Allow-Origin` がコード内でハードコードされていれば本番ドメインに更新（現行コードは `*` または同一オリジン前提なので変更不要）
- [ ] **HTTPS リダイレクト**: `.htaccess` 等で HTTP → HTTPS の強制リダイレクトを設定

### 9-4. 切替後の動作確認チェックリスト

- [ ] HTTPS で本番ドメインにアクセスできる（証明書エラーなし、HSTS が効く）
- [ ] セルフメニュー → カート追加 → 注文送信 → KDS反映
- [ ] お会計 → Stripe Checkout 遷移 → Apple Pay/Google Pay/カード決済 → 支払済み反映
- [ ] テイクアウト注文 → オンライン決済 → 戻りURL（本番ドメイン）で正常表示
- [ ] サブスク Checkout → 戻りURL（本番ドメイン）で `?subscription=success` 受領
- [ ] Stripe Connect オンボーディング → コールバック → `connect_account_id` 保存
- [ ] スマレジ OAuth: owner-dashboard → スマレジ連携 → 認可画面の `redirect_uri` パラメータが `https://本番ドメイン/api/smaregi/callback.php` になっていること（ブラウザの URL バーで確認）
- [ ] スマレジ認可承諾後、`?smaregi=success` で owner-dashboard に戻り、`tenants.smaregi_access_token` / `smaregi_contract_id` が更新されている
- [ ] 多言語切替（ja / en / zh-Hans / zh-Hant / ko）
- [ ] 領収書表示
- [ ] POSLA 管理画面ログイン → ダッシュボード表示
- [ ] AI ウェイター応答（本番 Gemini API キー使用）
- [ ] 旧テスト環境（`eat.posla.jp`）からの旧ブックマークでアクセスした場合のリダイレクト or 案内表示（任意）
