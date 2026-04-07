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

## 2. Gemini API（AI機能全般 + L-4翻訳）— 変更不要だが確認必須

**APIエンドポイントの変更は不要**（開発・本番で同じURL）

| ファイル | 用途 | URL |
|---------|------|-----|
| `api/customer/ai-waiter.php` L105 | AIウェイター | `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent` |
| `api/kds/ai-kitchen.php` L98 | AIキッチン | 同上 |
| `api/store/translate-menu.php` L272 | L-4 一括翻訳 | 同上 |

**確認事項:**
- `posla_settings` テーブルの `gemini_api_key` に本番用APIキーが設定されていること
- Google Cloud Console でAPIキーの制限設定:
  - HTTPリファラー制限（サーバーサイドなのでIP制限推奨）
  - API制限: Generative Language API のみ
- 課金設定: 従量課金が有効であること（無料枠超過時にエラーにならないように）
- レートリミット: Gemini 2.0 Flash の RPM/TPM 上限を確認

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

## 4. Square 決済（C-3 決済端末）

**テナント個別設定**（POSLA共通ではない）

| 設定箇所 | 変更内容 |
|---------|---------|
| `tenants.square_access_token` | サンドボックス用トークン → 本番用トークン |
| `tenants.square_location_id` | サンドボックスLocation → 本番Location |
| `tenants.payment_gateway` | 値は `square` のまま（変更不要） |

**追加作業:**
- 各テナントが Square Developer Dashboard で本番アプリを作成
- 本番用 Access Token を owner-dashboard の「決済設定」タブで入力

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
| **合計** | **3ファイル** | **5箇所** |

> ⚡ Stripe / Gemini / Google Places はキーの切り替えのみでコード変更不要。
> ⚡ スマレジだけがURLのコード変更が必要。

### 7-3. マイグレーションSQL 実行順序（未適用のもの）

本番DBに未適用のマイグレーションがある場合、以下の順序で適用:

```
sql/migration-l4-translations.sql    ← L-4 多言語対応（今回追加）
```

※ 他のマイグレーションは既に適用済みかを確認すること

---

## 8. 本番移行の推奨手順（全体フロー）

```
1. コードデプロイ（全ファイルアップロード）
2. スマレジURL 5箇所を .dev → .jp に変更
3. マイグレーションSQL実行
4. POSLA管理画面でAPIキー設定（gemini, stripe, smaregi等）
5. Stripe本番Webhook登録
6. 各テナント: スマレジOAuth再認証
7. 各テナント: 店舗マッピング + メニュー再インポート
8. 各テナント: メニュー一括翻訳実行
9. 動作確認: セルフオーダー → 注文 → KDS → 会計 → スマレジ同期
10. 動作確認: 多言語切替（5言語）
```
