# POSLA 本番移行 API変更チェックリスト

> 作成日: 2026-04-05
> 目的: 開発環境→本番環境に移行する際、変更が必要なAPI URL・設定値・キーの完全一覧

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
| `posla_settings` | `stripe_price_standard` | テスト用Price ID → 本番用Price ID |
| `posla_settings` | `stripe_price_pro` | テスト用Price ID → 本番用Price ID |
| `posla_settings` | `stripe_price_enterprise` | テスト用Price ID → 本番用Price ID |
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
| `stripe_price_standard` | Standardプラン | 本番用Price IDを入力 |
| `stripe_price_pro` | Proプラン | 本番用Price IDを入力 |
| `stripe_price_enterprise` | Enterpriseプラン | 本番用Price IDを入力 |
| `connect_application_fee_percent` | Connect手数料 | 手数料率を入力（例: 3.5） |
| `smaregi_client_id` | スマレジ連携 | 本番アプリのClient ID |
| `smaregi_client_secret` | スマレジ連携 | 本番アプリのClient Secret |

### 7-2. コード変更が必要な箇所（まとめ）

| 変更内容 | ファイル数 | 箇所数 |
|---------|----------|--------|
| スマレジURL `.dev` → `.jp` | 3ファイル | 5箇所 |
| 本番ドメイン切替 — `APP_BASE_URL`（P1-8 で集約） | 1ファイル | 1箇所 |
| 本番ドメイン切替 — 個別ハードコード（9-2 参照） | 0ファイル | 0箇所 |
| **合計** | **4ファイル** | **6箇所** |

> ⚡ Stripe / Gemini / Google Places はキーの切り替えのみでコード変更不要。
> ⚡ スマレジ認可URLとドメイン関連が主なコード変更対象。
> ⚡ P1-8 で `APP_BASE_URL` を導入したことで OAuth コールバック系の書き換えは1ファイルで完結。
> ⚡ Stripe Checkout / Connect / Subscription の `success_url` / `cancel_url` / `return_url` はすべて `$_SERVER['HTTP_HOST']` から動的構築されているため、ドメイン切替後も自動追随する（コード変更不要）。

### 7-3. マイグレーションSQL 実行順序（未適用のもの）

本番DBに未適用のマイグレーションがある場合、以下の順序で適用:

```
sql/migration-l4-translations.sql    ← L-4 多言語対応（今回追加）
```

※ 他のマイグレーションは既に適用済みかを確認すること

---

## 8. 本番移行の推奨手順（全体フロー）

```
1.  コードデプロイ（全ファイルアップロード）
2.  api/config/app.php の APP_BASE_URL を本番ドメインに変更（P1-8）
3.  9-2 の個別ハードコード箇所を本番ドメインに変更（VitePressマニュアル再ビルド）
4.  スマレジURL 5箇所を .dev → .jp に変更（セクション 1）
5.  マイグレーションSQL実行
6.  POSLA管理画面でAPIキー設定（gemini, stripe, smaregi等）
7.  Stripe本番Webhook登録（本番ドメイン宛）
8.  smaregi デベロッパーダッシュボードに本番 redirect_uri 登録
9.  各テナント: スマレジOAuth再認証
10. 各テナント: 店舗マッピング + メニュー再インポート
11. 各テナント: メニュー一括翻訳実行
12. 動作確認: セクション 9-4 のチェックリストをすべて実施
```

---

## 9. 本番ドメイン切替（`eat.posla.jp` → 本番ドメイン）

> 現テスト環境ドメインは `eat.posla.jp`（ピリオド）。
> サーバ側ディレクトリ `/home/odah/www/eat-posla/` は Sakura のディレクトリ名で別物（こちらはハイフン）。
> 本番ドメインが確定したら、本セクションの手順に従って切り替える。

### 9-1. P1-8 で集約済み — 1ファイル変更で全 OAuth コールバックが追随

| ファイル | 行 | 変更内容 |
|---------|-----|---------|
| `api/config/app.php` | L10 | `define('APP_BASE_URL', 'https://eat.posla.jp');` → `define('APP_BASE_URL', 'https://本番ドメイン');` |

このファイルを書き換えるだけで、以下が自動追随する:

- `api/smaregi/auth.php` の `redirect_uri`（L37）
- `api/smaregi/callback.php` の `redirect_uri`（L69）
- 今後追加される他の OAuth コールバック（Square Connect, Google OAuth 等）

> P1-8（2026-04-07）で `APP_BASE_URL` 定数を導入。今後 OAuth 系 URL を追加するときは必ずこの定数を使うこと。ハードコード禁止。

### 9-2. 個別書き換えが必要な箇所（コード）

`grep -rn "eat\.posla\.jp\|eat-posla\.jp"`（`api/`, `public/`, `sql/` 配下、`_backup_`/`docs/`除外）の結果:

| # | ファイル | 行 | 種別 | 変更必要 | 備考 |
|---|---------|-----|------|---------|------|
| 1 | `api/config/app.php` | L10 | `APP_BASE_URL` 定義 | ✅ 必要 | **9-1 で対応済み**（このファイル1行のみ） |

**実コードでハードコードされている URL は `api/config/app.php` のみ。** その他のすべての URL は以下の方法で動的に構築されている:

| 構築方法 | 該当箇所 | ドメイン切替時の挙動 |
|---------|---------|-----------------|
| `$_SERVER['HTTP_HOST']` から動的構築 | `api/customer/checkout-session.php` L97-106（success_url/cancel_url）<br>`api/customer/takeout-orders.php` L342-352（success_url/cancel_url）<br>`api/subscription/checkout.php` L70-75（success_url/cancel_url）<br>`api/subscription/portal.php` L40（return_url）<br>`api/connect/onboard.php` L38-42（return_url/refresh_url） | ✅ 自動追随（リクエストされたホスト名をそのまま使用） |
| `APP_BASE_URL` 定数経由（P1-8） | `api/smaregi/auth.php` L37<br>`api/smaregi/callback.php` L69 | ✅ 9-1 で1行変更すれば自動追随 |

> ⚡ **コードレベルでは 9-1 の1行変更だけで本番ドメイン切替が完了する。**

#### 9-2-2. ドキュメント側（VitePress マニュアルビルド成果物）

`docs/manual/` 配下のマニュアル（VitePress ソース）には例として `eat.posla.jp` が記載されている箇所があり、ビルド成果物が `public/docs/` 以下にデプロイされている:

| # | ファイル | 種別 | 対応方法 |
|---|---------|------|---------|
| 1 | `public/docs/tenant/21-stripe-full.html` | VitePress ビルド成果物 | ソース `docs/manual/tenant/21-stripe-full.md` を更新 → 再ビルド |
| 2 | `public/docs/tenant/22-smaregi-full.html` | 同上 | ソース `docs/manual/tenant/22-smaregi-full.md` を更新 → 再ビルド |
| 3 | `public/docs/internal/features/21-stripe-full.html` | 同上 | ソース `docs/manual/internal/features/21-stripe-full.md` を更新 → 再ビルド |
| 4 | `public/docs/internal/features/22-smaregi-full.html` | 同上 | ソース `docs/manual/internal/features/22-smaregi-full.md` を更新 → 再ビルド |
| 5 | `public/docs/assets/tenant_21-stripe-full.md.*.js` | VitePress バンドル | 上記再ビルドで自動更新 |
| 6 | `public/docs/assets/tenant_22-smaregi-full.md.*.js` | 同上 | 同上 |
| 7 | `public/docs/assets/internal_features_21-stripe-full.md.*.js` | 同上 | 同上 |
| 8 | `public/docs/assets/internal_features_22-smaregi-full.md.*.js` | 同上 | 同上 |

**手順:** マニュアルソース（`docs/manual/**/*.md`）を本番ドメインで grep → 置換 → `npm run docs:build` で再ビルド → `public/docs/` を再デプロイ。

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
