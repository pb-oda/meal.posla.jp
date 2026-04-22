---
title: システム運用
chapter: 05
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [operations, 運営, POSLA, sakura, ssh, cron, deploy, mysql, backup]
last_updated: 2026-04-22
maintainer: POSLA運営
---

# 5. システム運用

POSLAシステムの運用に関する技術的な情報です。本章は **POSLA 運営（プラスビリーフ社）の運用担当者** が日常的に参照する一次資料です。

::: warning ⚠️ 現行 sandbox 環境前提の具体例

本章のコマンド例・ホスト名・パス・crontab 設定は、**現行 sandbox 環境（さくらのレンタルサーバ / FreeBSD / csh / mysql80、`odah@odah.sakura.ne.jp` / `/home/odah/www/eat-posla/`）を前提とした実例**です。運用原則（SSH デプロイ・バックアップ・cron・障害対応のフロー）は vendor-neutral ですが、具体値は本番インフラ確定後に読み替えてください:

- `odah@odah.sakura.ne.jp` → 本番 `<ssh-user>@<host>`
- `/home/odah/www/eat-posla/` → 本番 `/<deploy-root>/`
- `mysql80.odah.sakura.ne.jp` → 本番 DB ホスト
- Sakura コンパネ手順 → 本番 infra のコントロールパネル
- `/usr/local/bin/php` → 本番環境の PHP パス

本番インフラが決まったら、本章の「現行 sandbox: ...」とラベルされた節を読み替えるか、削除してください。
:::

::: tip この章を読む順番（おすすめ）
1. まず **5.1 技術スタック** で全体像を把握
2. **5.2 サーバー構成** で接続情報・ディレクトリ・パーミッションを確認
3. デプロイをやる人は **5.4 デプロイ手順** + **5.5 バックアップ運用**
4. DB を触る人は **5.6 マイグレーション** + **5.7 mysql コマンドライン**
5. cron を設定する人は **5.8 cron 設定**
6. 障害調査は **5.9 ログファイル** + **5.10 パーミッション**
7. SSH 鍵を扱う人は **5.11 SSH 鍵管理**
8. よくある質問は **5.20 FAQ 30 件**
:::

## 5.1 技術スタック

| 技術 | バージョン/仕様 |
|------|--------------|
| サーバーサイド | PHP（フレームワークなし） |
| フロントエンド | Vanilla JavaScript（ES5、IIFE パターン） |
| データベース | MySQL 5.7（Sakura mysql80 サーバー、実態は 8.0 系互換だがアプリは 5.7 文法で書く） |
| ビルドツール | なし（生PHPファイルをそのままデプロイ） |
| セッション管理 | PHPセッション（ファイルベース） |
| キャッシュ | OPcache（PHP 標準） |
| HTTPS | Sakura 標準の Let's Encrypt 自動更新 |

### コーディング規約

- **ES5厳守：** `const`/`let`/アロー関数/`async-await`は使用しない。`var`＋コールバック＋IIFEパターン
- **SQLインジェクション対策：** 全SQLはプリペアドステートメント（例外なし）
- **XSS対策：** ユーザー入力は`Utils.escapeHtml()`を通して`innerHTML`に挿入
- **マルチテナント：** 全クエリに`tenant_id`/`store_id`の絞り込みを含める
- **レスポンス形式：** `api/lib/response.php`の統一形式（`ok/data/error/serverTime`）

---

## 5.2 サーバー構成（現行 sandbox 参考）

### 5.2.1 接続情報

| 項目 | 値 |
|------|---|
| ホスト名 | `odah.sakura.ne.jp` |
| SSH ユーザー | `odah` |
| SSH ポート | 22 |
| シェル | csh（FreeBSD 標準。`%` プロンプト） |
| OS | FreeBSD（バージョンは Sakura 側で管理） |
| 公開ドキュメントルート | `/home/odah/www/eat-posla/` |
| 本番 URL | `https://eat.posla.jp` |
| Document Root の Web マッピング | `/home/odah/www/` → `https://odah.sakura.ne.jp/`、`/home/odah/www/eat-posla/` → `https://eat.posla.jp/` |
| PHP バージョン | サーバー側設定に従う（コントロールパネル参照） |
| DB ホスト | `mysql80.odah.sakura.ne.jp`（sandbox 参考値） |
| DB 名 | `<POSLA_DB_NAME>`（sandbox 現行値: `odah_eat-posla`） |
| DB ユーザー | `<POSLA_DB_USER>`（sandbox 現行値: `odah_eat-posla`） |
| DB パスワード | `<POSLA_DB_PASS>`（**2026-04-22 rotation 済。48-hex random。現行値は `api/.htaccess` および Sakura CRON 環境変数 UI のみに保管。ドキュメント・repo・チャット・メールに平文で書かない**） |
| 文字コード | utf8mb4 |

::: warning DB パスワードの取り扱い
- 旧 password `odah_eat-posla`（DB 名と同値だった Sakura 慣例）は 2026-04-22 H-01 Phase 3 で失効済み。以降に書かれた CLI 例・env ファイル例で `-podah_eat-posla` と表記されている箇所は **歴史的記録 / placeholder** であり、そのまま叩いても接続できない
- 現行値は `api/.htaccess` の `SetEnv POSLA_DB_PASS` および Sakura コンパネ「CRON 環境変数一覧」にのみ保管
- ローテーション手順は §5.3.4 / §5.3.5 / §5.3.6 を参照
:::

::: warning csh 制約
Sakura のシェルは csh です。**`2>&1` はそのまま動かない**ため、リダイレクトする場合は `bash -c "..."` でラップしてください。例: `ssh odah@odah.sakura.ne.jp 'bash -c "php /home/odah/www/eat-posla/api/cron/monitor-health.php >> /home/odah/log/cron.log 2>&1"'`
:::

### 5.2.2 ディレクトリ構成（本番）

```
/home/odah/
├── www/eat-posla/                  ← ドキュメントルート（HTTPS 公開対象）
│   ├── api/                        ← サーバー API
│   │   ├── auth/                   ← 認証 API（device-register, login, logout 等）
│   │   ├── store/                  ← 店舗運用 API
│   │   ├── customer/               ← お客さん向け API
│   │   ├── kds/                    ← KDS API
│   │   ├── owner/                  ← オーナー API
│   │   ├── posla/                  ← POSLA 管理 API
│   │   ├── subscription/           ← Stripe Billing
│   │   ├── connect/                ← Stripe Connect
│   │   ├── smaregi/                ← スマレジ連携
│   │   ├── monitor/                ← ヘルスチェック (ping.php)
│   │   ├── cron/                   ← cron スクリプト 4 本
│   │   ├── lib/                    ← 共通ライブラリ
│   │   └── config/                 ← database.php（DB接続設定）
│   ├── public/                     ← 静的 HTML/CSS/JS
│   │   ├── admin/                  ← テナント管理ダッシュボード
│   │   ├── posla-admin/            ← POSLA 運営管理画面
│   │   ├── customer/               ← セルフメニュー
│   │   ├── handy/                  ← ハンディ POS
│   │   ├── kds/                    ← KDS + レジ
│   │   ├── docs-tenant/            ← VitePress 公開マニュアル
│   │   └── docs-internal/          ← VitePress 内部マニュアル（Basic 認証）
│   ├── sql/                        ← マイグレーション SQL（参照用、本番では実行のみ）
│   └── _backup_YYYYMMDD/           ← デプロイ前バックアップ（運用ルール）
├── log/                            ← Apache/PHP のログ
│   ├── php_errors.log              ← PHP エラーログ（最重要）
│   ├── cron.log                    ← cron 実行ログ（手動 redirect で記録）
│   └── access.log                  ← Sakura 提供のアクセスログ
└── .ssh/                           ← サーバー側 authorized_keys
```

### 5.2.3 ローカル開発環境

| 項目 | 値 |
|------|---|
| プロジェクトルート | `/Users/odahiroki/Desktop/matsunoya-mt/` |
| SSH 鍵ファイル | `id_ecdsa.pem`（プロジェクトルート、chmod 600 必須） |
| パスフレーズ | `oda0428`（運用ノートに記載、Slack には書かない） |
| Git リモート | `https://github.com/pb-oda/posla-web` |
| 開発用 PHP | `php -S localhost:8000`（ビルトインサーバー） |

---

## 5.3 APIキー管理

### 5.3.1 POSLA共通キー

Gemini API/Google Places APIのキーはPOSLA運営が一括負担します。`posla_settings`テーブルで一元管理されています。

**管理 UI:** POSLA 管理画面（`https://eat.posla.jp/public/posla-admin/dashboard.html`）→「API設定」タブ

**取得 API:**

| エンドポイント | 用途 | 認証 |
|---|---|---|
| `GET /api/posla/settings.php` | 全設定値の取得 | POSLA 管理者セッション |
| `GET /api/store/settings.php?include_ai_key=1` | テナント側で AI キーを取得（後方互換、voice-commander 等） | テナント認証 |
| `POST /api/store/ai-generate.php` | Gemini プロキシ（推奨経路、P1-6 以降） | テナント認証 |

**サーバープロキシ:**
- `api/store/places-proxy.php` — Google Places API（CORS 回避＋キー非露出）
- `api/store/ai-generate.php` — Gemini API（フロントに API キーを渡さない）

### 5.3.2 決済・外部連携の保存先

POSLA では、決済・外部連携の設定は **POSLA 共通 (`posla_settings`)** と **テナント個別 (`tenants`)** に分かれます。  
サーバ移行時は `api/.htaccess` だけでなく、**DB restore 後にこの 2 系統が両方揃っているか**を確認します。

| 区分 | 主な保存先 | 主なキー / カラム | 主な用途 |
|---|---|---|---|
| POSLA 共通 | `posla_settings` | `gemini_api_key`, `google_places_api_key`, `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `stripe_price_base`, `stripe_price_additional_store`, `stripe_price_hq_broadcast`, `connect_application_fee_percent`, `smaregi_client_id`, `smaregi_client_secret`, `slack_webhook_url`, `ops_notify_email`, `monitor_cron_secret`, `web_push_vapid_*` | POSLA Billing / signup / Stripe Connect platform / Smaregi OAuth / 監視 / Push |
| テナント個別 | `tenants` | `stripe_secret_key`, `payment_gateway`, `stripe_connect_account_id`, `connect_onboarding_complete` | 店舗ごとの Direct Stripe / Connect 利用判定 |
| テナント個別（スマレジ接続結果） | `tenants` | `smaregi_contract_id`, `smaregi_access_token`, `smaregi_refresh_token`, `smaregi_token_expires_at` | 店舗単位のスマレジ接続状態 |

**補足**:

- `posla_settings.stripe_secret_key` は **POSLA 運営共通の Billing / Connect 用** です
- `tenants.stripe_secret_key` は **各テナントの Direct Stripe (Pattern A)** 用です
- `smaregi_client_id` / `smaregi_client_secret` は `posla_settings`、実際の接続結果トークンは `tenants` に入ります
- `monitor_cron_secret` や `web_push_vapid_private_pem` は DB dump / restore で引き継ぐ前提です

### 5.3.3 アプリ設定の環境変数管理（2026-04-22 更新）

2026-04-22 以降、POSLA のサーバ移行 / 本番切替は **PHP/JS を直接書き換えず、環境変数で切り替える**のが原則です。

- DB 接続: `api/config/database.php`
- URL / 送信元メール / CORS / CSRF 許可ドメイン: `api/config/app.php`
- cron HTTP 認証: `api/cron/*.php`

`api/config/database.php` / `api/config/app.php` は **env-first** です。通常運用ではこの 2 ファイルを直接編集せず、`api/.htaccess` と cron 実行環境を修正します。

| 環境変数 | 主な用途 | 読み取り先 | 通常の設定場所 | 備考 |
|---|---|---|---|---|
| `POSLA_DB_HOST` | MySQL ホスト | `api/config/database.php` | `api/.htaccess` / cron env | CLI でも必要 |
| `POSLA_DB_NAME` | DB 名 | `api/config/database.php` | 同上 | CLI でも必要 |
| `POSLA_DB_USER` | DB 接続ユーザー | `api/config/database.php` | 同上 | CLI でも必要 |
| `POSLA_DB_PASS` | DB 接続パスワード | `api/config/database.php` | 同上 | CLI でも必要 |
| `POSLA_APP_BASE_URL` | 正規ベース URL | `api/config/app.php` | `api/.htaccess` / cron env | 予約URL・signup導線・Smaregi callback 等に使用 |
| `POSLA_FROM_EMAIL` | システム送信元メール | `api/config/app.php` | `api/.htaccess` / cron env | signup / 予約通知 / 監視メール |
| `POSLA_SUPPORT_EMAIL` | 利用者向け問い合わせ先 | `api/config/app.php` | `api/.htaccess` / cron env | signup 完了案内・運営連絡先 |
| `POSLA_ALLOWED_ORIGINS` | CORS 許可 Origin | `api/config/app.php` → `api/lib/response.php` | `api/.htaccess` / cron env | カンマ区切り可 |
| `POSLA_ALLOWED_HOSTS` | CSRF 検証許可 host | `api/config/app.php` → `api/lib/auth.php` | `api/.htaccess` / cron env | カンマ区切り可 |
| `POSLA_CRON_SECRET` | HTTP 経由 cron の共有秘密 | `api/cron/monitor-health.php` 等 | `api/.htaccess` / HTTP cron 実行元 | CLI cron のみなら必須ではない |

**現在の設計上の重要ポイント**:

1. `api/config/database.php` の fallback credentials は 2026-04-22 に `''` placeholder 化済み  
   → DB 接続は env 未設定なら **明示的に失敗** する
2. `api/config/app.php` は `APP_BASE_URL` 等を env から読む  
   → 本番ドメイン切替でも PHP ソース修正は通常不要
3. `api/.htaccess` は git 管理外  
   → 正本 repo に commit しない
4. local の補助ファイル `api/.htaccess.current.local` も git 管理外  
   → current 値メモ用。server の正本ではない

**ローカル開発**:

- Docker local は `POSLA_ENV=local` のとき `POSLA_LOCAL_DB_*` を優先参照
- `api/.htaccess` は current server の mirror を置けるが、repo には含めない

**テンプレート**:

```bash
cp api/.htaccess.example api/.htaccess
```

`api/.htaccess.example` には、上記 10 項目の用途コメントと placeholder を記載済み。

詳細: [tenant 18 章 18.W.2](../tenant/18-security.md#18w2-db-認証情報の環境変数化修正-9-q2a)

### 5.3.4 サーバ移行 / 本番切替で実際に修正する場所（2026-04-22）

**原則**: サーバ移行担当者が通常修正するのは **server 上の `api/.htaccess` と cron 実行環境** です。  
PHP/JS 本体 (`api/config/database.php`, `api/config/app.php`, API 各ファイル) は通常編集しません。

#### 修正対象の一覧

| 対象 | 必須 | 何を入れるか | 備考 |
|---|---|---|---|
| `/<deploy-root>/api/.htaccess` | 必須 | DB / URL / メール / Origin / Host / cron secret | 最重要。移行時の本命ファイル |
| cron 実行環境（crontab / wrapper script） | 必須 | 少なくとも DB 4項目 + `POSLA_APP_BASE_URL` + `POSLA_FROM_EMAIL` + `POSLA_SUPPORT_EMAIL` + `POSLA_ALLOWED_*` | CLI は `.htaccess` を読まない |
| DB 本体 | 必須 | dump restore / user privilege / 未適用 migration | schema 差分確認が必要 |
| `posla_settings` | 必須 | Gemini / Places / Stripe / Smaregi / Slack / VAPID / `monitor_cron_secret` 等 | 値は DB にあるので dump restore で引き継ぐのが基本 |
| `~/.htpasswd-internal` | 任意 | `public/docs-internal/` の Basic 認証 | internal docs を引き継ぐ場合のみ |

#### `api/.htaccess` の例

```apache
SetEnv POSLA_DB_HOST  __REPLACE_WITH_HOST__
SetEnv POSLA_DB_NAME  __REPLACE_WITH_DBNAME__
SetEnv POSLA_DB_USER  __REPLACE_WITH_USER__
SetEnv POSLA_DB_PASS  __REPLACE_WITH_PASSWORD__

SetEnv POSLA_APP_BASE_URL  https://example.com
SetEnv POSLA_FROM_EMAIL  noreply@example.com
SetEnv POSLA_SUPPORT_EMAIL  info@example.com
SetEnv POSLA_ALLOWED_ORIGINS  https://example.com
SetEnv POSLA_ALLOWED_HOSTS  example.com

# HTTP 経由 cron を使う場合のみ必須
SetEnv POSLA_CRON_SECRET  __REPLACE_WITH_CRON_SECRET__
```

#### cron 側で忘れやすいこと

CLI の PHP は `api/.htaccess` を読みません。  
そのため **本番ドメイン切替後も旧 URL / 旧送信元メールが残らないよう、同じ env を cron 実行環境へ渡す**必要があります。

最低限、以下は cron 側に複製する:

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

`POSLA_CRON_SECRET` は **HTTP 経由で `api/cron/*.php` を叩く場合のみ**必要です。  
CLI だけで回すなら必須ではありませんが、将来 HTTP 監視や外部 cron-job を使う可能性があるなら合わせて持っておく方が安全です。

#### サーバ移行の最小手順

1. 旧サーバの `api/.htaccess`、crontab、DB dump を退避
2. 新サーバへコードを配置
3. `api/.htaccess.example` を元に新サーバの `api/.htaccess` を作成
4. cron 実行環境へ同じ env を設定
5. DB restore + 未適用 migration 実行
6. `posla_settings` / テナント決済キー / 外部サービス設定を確認
7. smoke test 実施
8. DNS / SSL / Webhook / OAuth redirect を切替

#### DB / 外部サービスで確認するもの（抜け漏れ防止）

| 区分 | どこを見るか | 最低限確認するもの | 移行時アクション |
|---|---|---|---|
| DB 本体 | MySQL | dump restore 成功、ユーザー権限、未適用 migration | restore → migration → `SELECT 1` / 代表テーブル件数確認 |
| POSLA 共通設定 | `posla_settings` | `gemini_api_key`, `google_places_api_key`, `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `stripe_price_*`, `connect_application_fee_percent`, `smaregi_client_id`, `smaregi_client_secret`, `slack_webhook_url`, `ops_notify_email`, `monitor_cron_secret`, `web_push_vapid_*` | dump restore 後に欠落・NULL・旧値がないか確認 |
| テナント個別決済 | `tenants` | `payment_gateway`, `stripe_secret_key`, `stripe_connect_account_id`, `connect_onboarding_complete` | representative tenant で Direct / Connect の両方を spot check |
| テナント個別スマレジ | `tenants` | `smaregi_contract_id`, `smaregi_access_token`, `smaregi_refresh_token`, `smaregi_token_expires_at` | live 運用する店舗だけ OAuth 再認証要否を確認 |
| Stripe Dashboard | Stripe 管理画面 | Webhook endpoint、Customer Portal 戻り URL、Checkout / Connect callback の戻り先 | 新ドメインに差し替え、旧 endpoint は無効化 or 保持方針を決める |
| Smaregi Developers | スマレジ開発者画面 | `redirect_uri`、production Client ID/Secret | `.dev` → `.jp` 切替が必要な場合のみ本番値へ更新 |
| Google Cloud Console | API キー制限 | Gemini / Places のリファラー or IP 制限 | 新ドメイン / 新サーバを許可 |
| 監視 / 外形監視 | UptimeRobot / cron-job.org / Better Uptime | `https://本番ドメイン/api/monitor/ping.php`、必要なら HTTP cron secret | 監視 URL と通知先を新環境へ切替 |
| internal docs 保護 | `public/docs-internal/.htaccess`, `~/.htpasswd-internal` | Basic 認証が有効か | 引き継ぐ場合だけ `.htpasswd` も移行 |

**実務上の考え方**:

- `api/.htaccess` と cron env は **コード外の環境差分**
- `posla_settings` / `tenants` の連携キーは **DB 内の運用差分**
- Stripe / Smaregi / Google / 監視サービスは **外部管理画面の差分**

この 3 層を分けて確認すると、移行漏れが起きにくいです。

#### smoke test（最低限）

- `GET /api/monitor/ping.php` → `db:"ok"` で返る
- `GET /api/auth/login.php` → `405`
- `GET /scripts/output/helpdesk-prompt-internal.txt` → `403`
- `GET /public/docs-internal/` → `401`（Basic auth）
- signup 導線 / 予約詳細 URL / customer menu URL が新ドメインで開く
- Smaregi callback / Stripe return URL / Connect callback が新ドメインへ戻る

#### ロールバック

1. backup した `api/.htaccess` を戻す
2. cron の env / wrapper を旧値に戻す
3. DNS / Webhook / OAuth redirect を旧サーバに戻す
4. `curl https://<旧ドメイン>/api/monitor/ping.php` で `db:"ok"` を確認

**完全一覧**は `docs/production-api-checklist.md` を参照。  
本章は「どのファイル / どの設定場所を触るか」の一次情報、`production-api-checklist.md` は外部サービスも含めた総合チェックリストとして使い分ける。

### 5.3.5 env 供給手段の選択肢（現行 sandbox vs 本番 VPS 推奨）

POSLA アプリは **env-first 設計**です（`api/config/database.php` / `api/config/app.php` が `getenv()` で環境変数を読む、fallback は `''`）。**どうやって env を供給するかは runtime 環境の選択**で、現行 sandbox（さくらの共有レンタルサーバ）と本番 VPS では推奨戦略が異なります。

#### (A) 現行 sandbox: `.htaccess` SetEnv（共有サーバの現実解）

さくらの共有レンタルサーバは vhost / PHP-FPM pool / systemd 等の server-level env 設定手段が制限されているため、`api/.htaccess` の `SetEnv` ディレクティブが現実的にほぼ唯一の供給元です。

```apache
# api/.htaccess (現行 sandbox)
SetEnv POSLA_DB_HOST    ...
SetEnv POSLA_DB_USER    ...
SetEnv POSLA_DB_PASS    ...
(以下略)
```

- **評価タイミング**: Apache / mod_php が HTTP リクエストを処理するときのみ
- **cron / CLI では評価されない**（後述の warning 参照）
- ファイルは `chmod 604`、FilesMatch で外部公開を遮断（dotfile 拒否）
- 現行 sandbox のこの運用は 5.3.4 の説明と一致

#### (B) 本番 VPS: server-level env 推奨

自前 VPS / クラウド VM / コンテナベースの本番環境では、`.htaccess` より **server-level の env 管理**を推奨します。候補（本番 infra に合わせて選択）:

| 供給手段 | 配置例 | 特徴 | 主な向き先 |
|---|---|---|---|
| **Apache vhost の `SetEnv`** | `<VirtualHost>` 内に `SetEnv POSLA_DB_HOST ...` | Apache 使用時、`.htaccess` より管理コストが低く secret も 1 箇所に集約 | Apache + mod_php |
| **Nginx + PHP-FPM pool env** | `www.conf` の `env[POSLA_DB_HOST] = ...` | Nginx + PHP-FPM 構成で標準。pool ごとに分離可 | Nginx + PHP-FPM |
| **systemd unit env file** | `EnvironmentFile=/etc/posla/posla.env` | systemd で php-fpm や custom service を管理する場合 | systemd 下のサービス全般 |
| **env file + `source` wrapper** | cron wrapper で `source /etc/posla/posla.env && php ...` | CLI 経路に env を渡す王道。HTTP 側と同じ env file を共有可能 | cron / CLI / ansible 類 |

**推奨しない**:
- `.env` を docroot 直下に置く（`.env` 自体の誤公開事故リスク）
- ソースコード（`api/config/*.php`）への平文直書き（env-first 設計の趣旨に反する）
- 共有サーバの `.htaccess` 流儀をそのまま VPS に持ち込む（vhost や pool の方が運用負荷・監査性ともに優）

#### ⚠ `.htaccess` は cron / CLI で読まれない（supplier の盲点）

::: warning 重要
`.htaccess` の `SetEnv` は **Apache / mod_php が HTTP リクエストを処理する時点**でのみ評価されます。`php /path/to/script.php` のように CLI から直接呼ぶ場合、cron から呼ぶ場合、ssh 経由で呼ぶ場合は **一切評価されません**。

| 実行経路 | `.htaccess` SetEnv 評価 | env の別供給が必要 |
|---|---|---|
| HTTP リクエスト（Apache + mod_php）| ✅ 評価される | 不要 |
| cron CLI (`php /path/...`) | ❌ 評価されない | **必須** |
| ssh から `php /path/...` | ❌ 評価されない | **必須** |
| systemd timer → `php ...` | ❌ 評価されない | **必須** |

cron / CLI では以下のいずれかで env を別途供給:
1. `crontab` に `POSLA_DB_HOST=... \n POSLA_DB_PASS=... \n */5 * * * * php ...` と env を直書き
2. wrapper script で `source /etc/posla/posla.env && php ...`
3. systemd timer + `EnvironmentFile=/etc/posla/posla.env`

これを忘れると cron 側だけ env 未設定になり、本番ドメイン切替後に cron 通知だけ旧ドメイン URL が残る等の事故が起きます。5.8.2 および 7 章「運用監視エージェント」の cron 登録節で必ず確認してください。
:::

#### (C) ローカル開発（Docker）

Docker local は `POSLA_ENV=local` 環境で `POSLA_LOCAL_DB_*` 系の env を `docker-compose.yml` から供給。`.htaccess` / vhost / systemd のいずれでもなく、**コンテナ env** が供給元です。詳細は `docker-compose.yml` と 5.3.3 参照。

### 5.3.6 env 運用インシデント記録（2026-04-22 cron 沈黙事故）

#### 発生内容

2026-04-22 朝、H-01 Phase 3（DB password rotation）を実行。新 password を MySQL 8.0.44 上で `SET PASSWORD` し、`api/.htaccess` の `SetEnv POSLA_DB_PASS` を新 48-hex 値に更新。HTTP 経由の API は即座に新 password で接続できており、正常動作を確認。

しかし **cron だけが沈黙**していた。検知は 2 時間後、`/api/monitor/ping.php` が `ok:false, cron_lag_sec=7306` を返して気付いた。`monitor_last_heartbeat` は `2026-04-22 09:20:00` で停止。

#### 根本原因

- sandbox 構成では `api/.htaccess` の `SetEnv` が env 供給の主な経路（§5.3.5 (A)）
- `.htaccess` は **Apache / mod_php が HTTP を処理するときのみ**評価される
- cron は `/usr/local/bin/php /home/odah/www/eat-posla/api/cron/monitor-health.php` を CLI 直叩きするため `.htaccess` を**読まない**
- Rotation 前は `api/config/database.php` の fallback 値 `'odah_eat-posla'` が cron でも偶然ヒットしていた（旧 password と同値）
- Rotation 後は MySQL 側で旧 password が失効 → cron は fallback を使って `Access denied` で PDO 接続失敗 → エラーログだけ吐いて heartbeat 更新できず沈黙

#### 復旧手順（実施済み）

1. Sakura コンパネ → 共有サーバー → CRON → **「環境変数一覧」UI** に以下 9 ペアを手動登録:
   | Key | Value（sandbox 現行） |
   |---|---|
   | `POSLA_DB_HOST` | `mysql80.odah.sakura.ne.jp` |
   | `POSLA_DB_NAME` | `odah_eat-posla` |
   | `POSLA_DB_USER` | `odah_eat-posla` |
   | `POSLA_DB_PASS` | `<rotated 2026-04-22、.htaccess と同値>` |
   | `POSLA_APP_BASE_URL` | `https://eat.posla.jp` |
   | `POSLA_FROM_EMAIL` | `support@posla.jp` |
   | `POSLA_SUPPORT_EMAIL` | `support@posla.jp` |
   | `POSLA_ALLOWED_ORIGINS` | `https://eat.posla.jp` |
   | `POSLA_ALLOWED_HOSTS` | `eat.posla.jp` |
2. 次の `*/5` cron 実行時（11:35）に `monitor_last_heartbeat` が更新されたことを確認
3. `/api/monitor/ping.php` が `ok:true, cron_lag_sec≈193` に復帰したことを確認（12:38）

#### 学び（本番切替時に必ずやること）

| No | ルール |
|---|---|
| 1 | **DB password rotation は .htaccess 単独では完結しない。cron/CLI env も同期更新する**（sandbox なら Sakura コンパネ「環境変数一覧」、VPS なら crontab 変数 / wrapper / systemd EnvironmentFile） |
| 2 | Rotation 前に `api/config/database.php` の fallback 値に**古い real credential を残さない**（§5.3.4 で `''` に変更済、2026-04-22 H-01 Phase 4 で本番 config にも反映推奨） |
| 3 | `fallback ''` が発火した場合、PDO 接続は即失敗するので沈黙の長さを最小化できる（接続試行 → PHP error_log → monitor-health 次回走行時にエラーログを拾って Slack 通知する設計を前提） |
| 4 | **rotation 手順に cron env 更新を含めないドキュメントは事故を誘発する**。§5.3.4 / §5.3.5 / §5.8.2 の注記とこの §5.3.6 の三点を参照 |
| 5 | 本番 VPS では §5.3.5 (B) の vhost / PHP-FPM pool env / systemd EnvironmentFile 方式を採用し、HTTP と CLI の両方で同じ env file を共有することを推奨（rotation 時に 1 箇所で済む） |

#### 検知遅延の評価

- 沈黙時間: 約 2 時間（09:20 → 11:35）
- 検知経路: 副系（外部 uptime probe）ではなく、人間が `/api/monitor/ping.php` を手動確認して発見
- 改善 TODO: UptimeRobot 等の副系監視に `ok:false` / HTTP 503 での即時アラートを設定していれば ~5 分で検知できた。未設定状態 → 次スプリントで設定

---

## 5.4 デプロイ手順

POSLA はビルドプロセスがありません。**ローカルで編集 → SSH/SCP でアップロード → chmod 604 → 動作確認** の流れです。

::: warning ルール（厳守）
- **サーバー上でコード編集は禁止。** 全ての編集はローカル（プロジェクトルート）で行い、デプロイのみ SSH 経由
- デプロイ前に **必ず `_backup_YYYYMMDD/` にバックアップを取る**
- DB 変更（マイグレーション）は **デプロイより先に実行**（コード側が新カラムを参照しても落ちないように）
- 公開ファイルは **chmod 604**、PHP 実行ファイルも **chmod 604**、ディレクトリは **chmod 705**
- パスフレーズは expect スクリプトで自動入力（手で打つと csh 履歴に残る）
:::

### 5.4.1 デプロイ前のチェックリスト

| # | 確認項目 | 確認方法 | NG 時 |
|---|---|---|---|
| 1 | 変更ファイル一覧が手元にある | `git status` / `git diff --name-only` | コミットして洗い出す |
| 2 | ローカルで動作確認済み | `php -S localhost:8000 -t public` で動作テスト | 修正してから |
| 3 | ES5 互換チェック | `grep -nE "const |let |=>|async |await " public/admin/js/*.js` で 0 件 | const/let/arrow/async を var + コールバックに書き換え |
| 4 | プリペアドステートメント使用 | 変更した PHP の SQL に `?` プレースホルダ | べた書き SQL を `prepare` + `execute` に修正 |
| 5 | tenant_id / store_id 絞り込み | 新規 SQL の WHERE 句に `tenant_id` / `store_id` | 追加してから |
| 6 | バックアップディレクトリ名 | `_backup_$(date +%Y%m%d)` | 同日 2 回目は `_backup_20260419b/` のように添字を付ける |
| 7 | DB マイグレーションがある場合 | `sql/migration-*.sql` をローカルで dry-run（テスト DB） | 構文エラーを修正 |
| 8 | キャッシュバスター更新 | 変更した JS を読む HTML の `<script src="...?v=YYYYMMDD">` をバンプ | バンプ漏れがあると古い JS がキャッシュされる |
| 9 | SSH 鍵パーミッション | `ls -l id_ecdsa.pem` → `-rw-------` | `chmod 600 id_ecdsa.pem` |
| 10 | 本番 URL の確認 | `eat.posla.jp`（**ハイフン無し**） | `eat-posla.jp` ではない |

### 5.4.2 expect + scp 標準デプロイパターン

```bash
# パスフレーズ自動入力デプロイ（プロジェクトルートで実行）
SSH_KEY=./id_ecdsa.pem
REMOTE=odah@odah.sakura.ne.jp
REMOTE_DIR=/home/odah/www/eat-posla
TODAY=$(date +%Y%m%d)

# 1. リモートにバックアップディレクトリ作成 + 既存ファイルをコピー
expect -c "
spawn ssh -i $SSH_KEY -o StrictHostKeyChecking=no $REMOTE \"bash -c '
  mkdir -p $REMOTE_DIR/_backup_${TODAY}/api/store
  cp $REMOTE_DIR/api/store/staff-management.php $REMOTE_DIR/_backup_${TODAY}/api/store/
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 2. 変更ファイルを scp
expect -c "
spawn scp -i $SSH_KEY api/store/staff-management.php $REMOTE:$REMOTE_DIR/api/store/staff-management.php
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 3. パーミッション設定
expect -c "
spawn ssh -i $SSH_KEY $REMOTE \"chmod 604 $REMOTE_DIR/api/store/staff-management.php\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"
```

### 5.4.3 ASCII 配置イメージ

```
ローカル                                 Sakura
─────────────────                       ───────────────────────────
matsunoya-mt/                            /home/odah/www/eat-posla/
├── api/store/staff-management.php  ───→ ├── api/store/staff-management.php  (chmod 604)
├── public/admin/dashboard.html     ───→ ├── public/admin/dashboard.html     (chmod 604)
└── sql/migration-fda1-*.sql        ───→ └── 別途 mysql コマンドで適用 (5.6 参照)
                                          └── _backup_20260419/
                                              ├── api/store/staff-management.php  (旧版)
                                              └── public/admin/dashboard.html     (旧版)
```

### 5.4.4 デプロイ後の動作確認

| # | 確認 | コマンド/操作 | 期待結果 |
|---|---|---|---|
| 1 | サーバー応答 | `curl https://eat.posla.jp/api/monitor/ping.php` | `{"ok":true,...}` |
| 2 | 変更画面の表示 | Chrome シークレットウィンドウで該当 URL | 変更が反映されている |
| 3 | error_log の確認 | `tail -50 /home/odah/log/php_errors.log` | 新規 Fatal/Parse なし |
| 4 | ログイン疎通 | テスト owner アカウントでログイン | ダッシュボードに遷移 |
| 5 | 変更箇所の機能テスト | 影響範囲のシナリオを実行 | 期待動作 |
| 6 | キャッシュバスター効くか | DevTools → Network → 該当 JS の Status 200 + Response が新版 | バンプが効いている |

### 5.4.5 ロールバック手順

不具合が発覚したら、`_backup_YYYYMMDD/` から戻します。

```bash
expect -c "
spawn ssh -i $SSH_KEY $REMOTE \"bash -c '
  cp $REMOTE_DIR/_backup_${TODAY}/api/store/staff-management.php $REMOTE_DIR/api/store/staff-management.php
  chmod 604 $REMOTE_DIR/api/store/staff-management.php
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"
```

::: warning DB ロールバック
DB マイグレーションのロールバックは **手動 SQL** を書く必要があります。マイグレーション適用前の状態に戻すには事前に `mysqldump` バックアップが必要（5.5.2 参照）。
:::

---

## 5.5 バックアップ運用

### 5.5.1 ファイルバックアップ（`_backup_YYYYMMDD/`）

デプロイのたびに、変更対象ファイルだけを `/home/odah/www/eat-posla/_backup_YYYYMMDD/` 配下に元のディレクトリ構造のままコピーします。

| ルール | 内容 |
|---|---|
| ディレクトリ命名 | `_backup_YYYYMMDD/`（日次） |
| 同日 2 回目以降 | `_backup_20260419b/`, `_backup_20260419c/` のようにアルファベット添字 |
| 保持期間 | 30 日（古いバックアップは月次で手動削除） |
| バックアップ範囲 | 「そのデプロイで上書きされるファイルだけ」（全量コピーは不要） |
| 配置 | 元のディレクトリ構造を保つ（`api/store/settings.php` → `_backup_xxx/api/store/settings.php`） |

::: tip バックアップを取る理由
本番で問題が出たときに **1 ファイル単位で即座に戻せる** ため。`git checkout HEAD~1` ではローカルしか戻らないので、本番上にスナップショットを残すのが運用上必須。
:::

### 5.5.2 DB バックアップ（mysqldump）

毎日 1 回、自動 mysqldump で DB 全体をバックアップします。

```bash
# 手動バックアップ（credentials は env 経由で渡す。平文を script に書かない）
# 現行 sandbox: .htaccess と同じ値を一時的に手元でも供給できるようにする
# （例）~/.posla-sandbox.env を 0600 で置く:
#   export POSLA_DB_HOST=mysql80.odah.sakura.ne.jp
#   export POSLA_DB_NAME=odah_eat-posla
#   export POSLA_DB_USER=odah_eat-posla
#   export POSLA_DB_PASS=<rotated 2026-04-22、`.htaccess` / Sakura CRON env と同値>

source ~/.posla-sandbox.env
TODAY=$(date +%Y%m%d-%H%M)

mysqldump --single-transaction --quick --routines \
  -h "$POSLA_DB_HOST" -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  | gzip > /home/odah/db_backup/dump_${TODAY}.sql.gz
```

| ファイル名 | 用途 | 取得頻度 | 保持期間 |
|---|---|---|---|
| `dump_YYYYMMDD-HHMM.sql.gz` | フル DB バックアップ | 毎日 03:00（cron） | 7 日 |
| `dump_pre_migration_<name>.sql.gz` | マイグレーション前手動取得 | 手動（マイグレーション適用前必須） | 30 日 |

### 5.5.3 リストア手順

```bash
# 1. 復元先 DB を空にする（or 別 DB を作って検証）
mysql -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME -e "DROP DATABASE IF EXISTS \`${DBNAME}\`; CREATE DATABASE \`${DBNAME}\` DEFAULT CHARACTER SET utf8mb4;"

# 2. ダンプを流し込む
gunzip -c /home/odah/db_backup/dump_20260418-0300.sql.gz | mysql -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME

# 3. 動作確認
mysql -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME -e "SELECT COUNT(*) AS tenants FROM tenants;"
```

### 5.5.4 アップロード画像のバックアップ

| 対象 | 場所 | 頻度 | 方法 |
|---|---|---|---|
| メニュー画像 | `/home/odah/www/eat-posla/public/uploads/menu/` | 週 1 | rsync で運用 PC にミラー |
| 店舗ブランディング画像 | `/home/odah/www/eat-posla/public/uploads/branding/` | 週 1 | 同上 |
| レシート PDF | `/home/odah/www/eat-posla/storage/receipts/` | 月 1 | 同上 |

---

## 5.6 マイグレーション管理

### 5.6.1 基本ルール

- スキーマ定義：`sql/schema.sql`（初期スキーマ、**編集禁止**）
- マイグレーション：`sql/migration-*.sql`ファイルで管理
- 新しいカラム・テーブルが必要な場合は **新規マイグレーションファイル** を作成
- 既存テーブルの`ALTER TABLE`も **新規マイグレーションに記述**
- ファイル命名規則: `migration-<feature_id>-<short_desc>.sql`（例: `migration-fda1-device-tokens.sql`）

### 5.6.2 主要マイグレーションファイル一覧（適用順）

::: tip 適用順について
原則は **古い順**。スキーマに追加 → 後段で再 ALTER するパターンがあるため、新規環境にスキーマを構築する場合は **`schema.sql` → 全 migration を ファイル名昇順** で順次流す。
:::

| # | ファイル | 内容 |
|---|---|---|
| 1 | `schema.sql` | 初期スキーマ（必ず最初に） |
| 2 | `migration-a1-options.sql` | メニューオプション |
| 3 | `migration-a3-kds-stations.sql` | KDS ステーション |
| 4 | `migration-a4-removed-items.sql` | 注文の削除アイテム |
| 5 | `migration-b-phase-b.sql` | テーブルセッション |
| 6 | `migration-b2-plan-menu-items.sql` | プラン別メニュー |
| 7 | `migration-b3-course-session.sql` | コース予約 |
| 8 | `migration-c3-payment-gateway.sql` | 決済ゲートウェイ |
| 9 | `migration-c4-payments.sql` | 決済記録 |
| 10 | `migration-c5-cart-events.sql` | カート行動記録 |
| 11 | `migration-c5-inventory.sql` | 在庫テーブル |
| 12 | `migration-cb1-error-log.sql` | エラーログテーブル（CB1） |
| 13 | `migration-cp1-cashier-pin.sql` | 担当スタッフ PIN |
| 14 | `migration-cr1-refund.sql` | 返金フィールド |
| 15 | `migration-d1-ai-settings.sql` | AI 設定 |
| 16 | `migration-d2-places-api-key.sql` | Google Places キー |
| 17 | `migration-e1-username.sql` | ユーザー名対応 |
| 18 | `migration-e2-override-image.sql` | 店舗別画像オーバーライド |
| 19 | `migration-e3-call-alerts.sql` | スタッフ呼び出し |
| 20 | `migration-f1-token-expiry.sql` | テーブルトークン有効期限 |
| 21 | `migration-f2-order-items.sql` | order_items テーブル |
| 22 | `migration-fda1-device-tokens.sql` | デバイス自動ログイントークン（F-DA1） |
| 23 | `migration-fqr1-sub-sessions.sql` | サブセッション QR（F-QR1） |
| 24 | `migration-g1-audit-log.sql` | 監査ログ |
| 25 | `migration-g2-user-sessions.sql` | アクティブセッション一覧 |
| 26 | `migration-h1-welcome-message.sql` | お客様ウェルカムメッセージ |
| 27 | `migration-i1-monitor.sql` | monitor_events |
| 28 | `migration-i1-order-memo-allergen.sql` | 注文メモ・アレルゲン |
| 29 | `migration-i2-menu-enhancements.sql` | メニュー拡張 |
| 30 | `migration-i3-satisfaction-ratings.sql` | 満足度評価 |
| 31 | `migration-i4-table-memo.sql` | テーブルメモ |
| 32 | `migration-i5-last-order-kds-notify.sql` | ラストオーダー KDS 通知 |
| 33 | `migration-i6-table-merge-split.sql` | テーブル結合・分割 |
| 34 | `migration-i7-kitchen-call.sql` | キッチン呼び出し |
| 35 | `migration-l1-takeout.sql` | テイクアウト |
| 36 | `migration-l3-shift-management.sql` | シフト管理 |
| 37 | `migration-l3b-gps-staff-control.sql` | GPS スタッフ管理 |
| 38 | `migration-l3b2-user-visible-tools.sql` | ユーザー可視ツール |
| 39 | `migration-l3p2-labor-cost.sql` | 人件費 |
| 40 | `migration-l3p3-multi-store-shift.sql` | 多店舗シフト |
| 41 | `migration-l4-translations.sql` | 翻訳テーブル |
| 42 | `migration-l4-ui-translations-v2.sql` | UI 翻訳 v2 |
| 43 | `migration-l5-receipts.sql` | レシート |
| 44 | `migration-l7-google-place-id.sql` | Google Place ID |
| 45 | `migration-l8-self-checkout.sql` | セルフチェックアウト |
| 46 | `migration-l8-ui-translations.sql` | UI 翻訳 |
| 47 | `migration-l9-reservations.sql` | 予約テーブル |
| 48 | `migration-l9-table-auth.sql` | テーブル認証 |
| 49 | `migration-l10-posla-admin.sql` | POSLA 管理者テーブル |
| 50 | `migration-l10b-posla-settings.sql` | POSLA 設定テーブル |
| 51 | `migration-l11-subscription.sql` | サブスクリプション |
| 52 | `migration-l13-stripe-connect.sql` | Stripe Connect |
| 53 | `migration-l15-smaregi.sql` | スマレジ連携 |
| 54 | `migration-l16-plan-tier.sql` | プラン階層（旧） |
| 55 | `migration-n12-store-branding.sql` | 店舗ブランディング |
| 56 | `migration-p1-6c-drop-ai-api-key.sql` | テナント AI キー削除 |
| 57 | `migration-p1-6d-posla-admin-sessions.sql` | POSLA 管理セッション |
| 58 | `migration-p1-11-subscription-events-unique.sql` | sub_events 重複防止 |
| 59 | `migration-p1-22-drop-tenants-google-places-api-key.sql` | テナント Places キー削除 |
| 60 | `migration-p1-27-torimaru-demo-tenant.sql` | torimaru デモ |
| 61 | `migration-p1-34-tenant-hq-broadcast.sql` | 本部一括配信フラグ |
| 62 | `migration-p1-remove-square.sql` | Square 機能削除 |
| 63 | `migration-p119-checkout-i18n.sql` | チェックアウト i18n |
| 64 | `migration-p119b-receipt-rollback.sql` | レシートロールバック |
| 65 | `migration-p119c-fix-encoding.sql` | エンコーディング修正 |
| 66 | `migration-p133-collation-fix.sql` | 照合順序修正 |
| 67 | `migration-p1a-device-role.sql` | device ロール追加（P1a） |
| 68 | `migration-plan-remove-lite.sql` | lite プラン削除 |
| 69 | `migration-s6-session-pin.sql` | セッション PIN（S-6） |
| 70 | `migration-u2-printer.sql` | プリンタ設定 |
| 71 | `migration-s3-refund-pending.sql` | **【適用済 2026-04-19】** payments.refund_status ENUM に 'pending' 追加（S3 #10 二重返金防止） |
| 72 | `migration-s3-reservation-uniq.sql` | **【保留 2026-04-19】** reservations への複合 UNIQUE 追加（S3 #7 二重予約防止）。重複データ 1 件あり、テストデータ削除後に適用 |
| 73 | `migration-s3-fix-comment.sql` | **【スキップ 2026-04-19】** スキーマコメント文字化け修正（store_settings.default_hourly_rate カラム不在のため不要） |

### 5.6.3 マイグレーション実行手順

```bash
# 1. 事前バックアップ（必須）
TODAY=$(date +%Y%m%d-%H%M)
mysqldump --single-transaction -h "$POSLA_DB_HOST" \
  -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  | gzip > /home/odah/db_backup/dump_pre_migration_fda1_${TODAY}.sql.gz

# 2. ローカルから SQL ファイルを scp（事前に SQL をリポジトリに置いてから）
expect -c "
spawn scp -i id_ecdsa.pem sql/migration-fda1-device-tokens.sql odah@odah.sakura.ne.jp:/home/odah/www/eat-posla/sql/
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 3. SSH で SQL を流し込む
expect -c "
spawn ssh -i id_ecdsa.pem odah@odah.sakura.ne.jp \"bash -c '
  source ~/.posla-sandbox.env && \
  mysql -h \"\$POSLA_DB_HOST\" -u \"\$POSLA_DB_USER\" -p\"\$POSLA_DB_PASS\" \"\$POSLA_DB_NAME\" \
    < /home/odah/www/eat-posla/sql/migration-fda1-device-tokens.sql 2>&1
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 4. 適用確認
expect -c "
spawn ssh -i id_ecdsa.pem odah@odah.sakura.ne.jp \"bash -c '
  source ~/.posla-sandbox.env && \
  mysql -h \"\$POSLA_DB_HOST\" -u \"\$POSLA_DB_USER\" -p\"\$POSLA_DB_PASS\" \"\$POSLA_DB_NAME\" \
    -e \"DESCRIBE device_registration_tokens;\"
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"
```

::: warning マイグレーション実行のタイミング
**コードデプロイの直前** に実行する。先にコードをデプロイすると、新カラム参照で 500 エラーになる。逆に、SQL 変更だけ先行するのは OK（旧コードは新カラムを無視するため）。
:::

---

## 5.7 mysql コマンドラインの実用例

### 5.7.1 ログイン

```bash
# credentials は env 経由（~/.posla-sandbox.env は chmod 600）
source ~/.posla-sandbox.env

# 本番 DB に接続
mysql -h "$POSLA_DB_HOST" -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME"

# 1 行クエリ実行
mysql -h "$POSLA_DB_HOST" -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  -e "SELECT slug, name, is_active FROM tenants;"
```

### 5.7.2 よく使う調査クエリ

```sql
-- テナントの状態確認
SELECT id, slug, name, is_active, subscription_status, plan, created_at
FROM tenants ORDER BY created_at DESC;

-- テナント別の店舗数
SELECT t.slug, t.name, COUNT(s.id) AS store_count
FROM tenants t LEFT JOIN stores s ON t.id = s.tenant_id
GROUP BY t.id ORDER BY store_count DESC;

-- 直近 1 時間の注文件数（テナント別）
SELECT t.slug, COUNT(o.id) AS orders, SUM(o.total) AS revenue
FROM orders o
JOIN stores s ON s.id = o.store_id
JOIN tenants t ON t.id = s.tenant_id
WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY t.id;

-- posla_settings の確認（値はマスク）
SELECT setting_key,
  CASE WHEN LENGTH(setting_value) > 8
    THEN CONCAT(LEFT(setting_value, 4), '****', RIGHT(setting_value, 2))
    ELSE setting_value END AS masked_value,
  updated_at
FROM posla_settings ORDER BY setting_key;

-- 直近 1 日の error_log 上位 10
SELECT error_no, code, COUNT(*) AS cnt, MAX(message) AS sample
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY error_no, code
ORDER BY cnt DESC LIMIT 10;

-- 監査ログ: 特定スタッフの直近 50 操作
SELECT created_at, action, entity_type, entity_id
FROM audit_log
WHERE user_id = 'xxxxxxxx' ORDER BY id DESC LIMIT 50;

-- アクティブセッション一覧（テナント別）
SELECT t.slug, COUNT(*) AS active_sessions
FROM user_sessions us
JOIN users u ON u.id = us.user_id
JOIN tenants t ON t.id = u.tenant_id
WHERE us.expires_at > NOW()
GROUP BY t.id;
```

### 5.7.3 危険操作（要レビュー）

```sql
-- テナント無効化（全ユーザーがログイン不能になる）
UPDATE tenants SET is_active = 0 WHERE slug = 'xxxxx';

-- スタッフのパスワード強制リセット（password_hash は別途生成）
UPDATE users SET password_hash = ?, must_change_password = 1
WHERE id = ? AND tenant_id = ?;

-- 古い session の一括削除（運用 cron で自動化推奨）
DELETE FROM user_sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- 古い error_log の削除（90 日以上前）
DELETE FROM error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

---

## 5.8 cron 設定

### 5.8.1 cron 一覧（4 本）

| cron | 用途 | 推奨頻度 | スクリプト |
|---|---|---|---|
| auto-clock-out | 退勤打刻忘れの自動退勤（L-3） | 5 分毎 | `api/cron/auto-clock-out.php` |
| monitor-health | エラーログ監視 + Slack 通知（I-1, CB1） | 5 分毎 | `api/cron/monitor-health.php` |
| reservation-cleanup | 期限切れ holds 削除・auto-no-show（L-9） | 1 時間毎 | `api/cron/reservation-cleanup.php` |
| reservation-reminders | 予約リマインダー送信（L-9） | 5〜10 分毎 | `api/cron/reservation-reminders.php` |

### 5.8.2 crontab エントリ（現行 sandbox 参考）

現行 sandbox ではコントロールパネル → 「CRON」設定で登録します。`/usr/local/bin/php` のパスは sandbox 環境のもの。本番 infra では該当インフラのスケジューラに読み替え:

::: warning 本番ドメイン切替時は env の複製が必要
以下の cron コマンドだけをそのまま移すと、CLI 側では `.htaccess` が見えないため旧 URL / 旧送信元メール / DB 接続失敗が残る可能性があります。  
`POSLA_DB_*` / `POSLA_APP_BASE_URL` / `POSLA_FROM_EMAIL` / `POSLA_SUPPORT_EMAIL` / `POSLA_ALLOWED_*` は **crontab 変数または wrapper script** でも同じ値を渡してください。詳細は §5.3.4 を参照。
:::

```
# auto-clock-out: 5 分毎（00, 05, 10, ... 55 分に実行）
*/5 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/auto-clock-out.php >> /home/odah/log/cron-auto-clock-out.log 2>&1

# monitor-health: 5 分毎
*/5 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/monitor-health.php >> /home/odah/log/cron-monitor-health.log 2>&1

# reservation-cleanup: 毎時 0 分
0 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/reservation-cleanup.php >> /home/odah/log/cron-reservation-cleanup.log 2>&1

# reservation-reminders: 5 分毎
*/5 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/reservation-reminders.php >> /home/odah/log/cron-reservation-reminders.log 2>&1

# DB 日次バックアップ: 毎日 03:00（credentials は crontab 環境変数 or wrapper script から供給）
# 例: sakura コンパネの「CRON 環境変数」に POSLA_DB_HOST / POSLA_DB_NAME / POSLA_DB_USER / POSLA_DB_PASS を登録済みの前提
0 3 * * * mysqldump --single-transaction -h "$POSLA_DB_HOST" -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" | gzip > /home/odah/db_backup/dump_$(date +\%Y\%m\%d).sql.gz

# 古いログ削除: 毎日 04:00（30 日より古い cron ログを削除）
0 4 * * * find /home/odah/log -name "cron-*.log" -mtime +30 -delete
```

::: warning csh で cron を書くときの罠
- `2>&1` は **bash でしか動かない**。Sakura のコントロールパネルから登録する場合は内部的に sh が呼ばれるので OK だが、shell 経由で実行するときは `bash -c "..."` が必要
- `%` 記号は crontab 内では特殊文字（改行扱い）。`date +%Y%m%d` の `%` は `\%` でエスケープ
- パスは絶対パスで書く（cron は PATH が短い）
:::

### 5.8.3 cron 認証ヘッダ

cron スクリプトは CLI 実行が標準ですが、外部 URL から HTTP 経由で叩くこともできます。その場合は共有秘密が必須です。

```php
// api/cron/monitor-health.php の冒頭
$expected = getenv('POSLA_CRON_SECRET') ?: '';
if (!$expected || ($_SERVER['HTTP_X_POSLA_CRON_SECRET'] ?? '') !== $expected) {
    http_response_code(403); echo 'forbidden'; exit;
}
```

呼び出し例:
```bash
curl -H "X-POSLA-CRON-SECRET: xxxxxxxxxxxxxxxxxxxx" \
     https://eat.posla.jp/api/cron/monitor-health.php
```

### 5.8.4 cron が動いているかの確認

```bash
# 直近の実行ログを確認
tail -100 /home/odah/log/cron-monitor-health.log

# heartbeat の確認（monitor-health が動いていれば毎 5 分更新）
source ~/.posla-sandbox.env
mysql -h "$POSLA_DB_HOST" -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  -e "SELECT setting_value FROM posla_settings WHERE setting_key='monitor_last_heartbeat';"

# monitor_events に最近のイベントがあるか
mysql -h "$POSLA_DB_HOST" -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  -e "SELECT id, event_type, severity, title, created_at FROM monitor_events ORDER BY id DESC LIMIT 10;"
```

---

## 5.9 ログファイル

### 5.9.1 ログ一覧

| パス | 内容 | ローテーション |
|---|---|---|
| `/home/odah/log/php_errors.log` | PHP の Fatal/Parse/Warning エラー（**最重要**） | Sakura 標準（容量超過時） |
| `/home/odah/log/cron-*.log` | cron 各スクリプトの stdout/stderr | 5.8.2 のクリーンアップ cron で 30 日 |
| `/home/odah/log/access.log` | Apache アクセスログ（Sakura 提供） | Sakura 標準 |
| DB の `error_log` テーブル | API エラー全件（CB1 で導入） | 90 日（運用 cron で削除） |
| DB の `monitor_events` テーブル | 監視イベント（heartbeat / Slack 通知発火元） | 90 日（同上） |
| DB の `audit_log` テーブル | 全 admin 操作（誰が何をした） | 1 年（同上） |

### 5.9.2 php_errors.log の見方

```bash
# 直近のエラー 50 件
tail -50 /home/odah/log/php_errors.log

# Fatal だけ抽出
grep -E "PHP Fatal" /home/odah/log/php_errors.log | tail -20

# 直近 1 時間のエラー数
awk -v cutoff="$(date -v-1H +'%d-%b-%Y %H:%M:%S')" \
  '$0 > cutoff' /home/odah/log/php_errors.log | wc -l
```

### 5.9.3 error_log（DB）テーブルの SQL クエリ集

```sql
-- 直近 1 時間のエラー件数（E 番号別）
SELECT error_no, code, COUNT(*) AS cnt
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY error_no, code
ORDER BY cnt DESC LIMIT 20;

-- 5xx エラーのみ
SELECT created_at, error_no, code, message, endpoint, request_path
FROM error_log
WHERE http_status >= 500
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY created_at DESC;

-- 認証系エラー（ブルートフォース疑い）
SELECT ip_address, COUNT(*) AS cnt
FROM error_log
WHERE http_status IN (401, 403)
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY ip_address
HAVING cnt >= 10
ORDER BY cnt DESC;

-- 時間帯別エラー数（過去 24 時間）
SELECT DATE_FORMAT(created_at, '%H:00') AS hour, COUNT(*) AS cnt
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY hour ORDER BY hour;
```

---

## 5.10 パーミッション

### 5.10.1 推奨パーミッション一覧

| 対象 | パーミッション | 理由 |
|---|---|---|
| PHP ファイル（公開） | 604 | Apache から read 可能、所有者のみ書き込み可 |
| HTML / CSS / JS | 604 | 同上 |
| ディレクトリ | 705 | 一覧表示は不要、execute（cd）は必要 |
| `api/config/database.php` | 600 | DB パスワード保護（Apache は実行時に PHP 経由で読む） |
| `id_ecdsa.pem`（ローカル） | 600 | SSH 鍵保護（必須） |
| アップロードディレクトリ | 705 + ファイル 604 | 書き込み禁止（PHP からのみ書く場合は別途 chown） |
| ログファイル | 600 | 個人情報含む可能性（IP/UserAgent） |

### 5.10.2 設定方法

```bash
# 単一ファイル
chmod 604 /home/odah/www/eat-posla/api/store/staff-management.php

# ディレクトリ配下を一括（PHP のみ）
find /home/odah/www/eat-posla/api -type f -name "*.php" -exec chmod 604 {} \;

# ディレクトリだけ 705
find /home/odah/www/eat-posla -type d -exec chmod 705 {} \;
```

---

## 5.11 SSH 鍵管理

### 5.11.1 鍵ファイル

| 項目 | 値 |
|---|---|
| ファイル名 | `id_ecdsa.pem` |
| 配置場所 | プロジェクトルート（`/Users/odahiroki/Desktop/matsunoya-mt/`） |
| アルゴリズム | ECDSA（より新しいなら ED25519 を推奨） |
| パスフレーズ | `oda0428`（運用ノートに記載、Slack/メールには書かない） |
| パーミッション | **600 必須**（644 だと SSH が拒否） |

### 5.11.2 expect スクリプトでの自動入力

```bash
# 標準パターン
SSH_KEY=./id_ecdsa.pem
REMOTE=odah@odah.sakura.ne.jp

expect -c "
spawn ssh -i $SSH_KEY -o StrictHostKeyChecking=no $REMOTE \"hostname; uname -a\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"
```

### 5.11.3 鍵をローテーションする場合

1. 新しい鍵を生成: `ssh-keygen -t ed25519 -f id_ed25519_new.pem`
2. 公開鍵を Sakura の `~/.ssh/authorized_keys` に追加
3. 新しい鍵で接続テスト
4. 古い鍵を `authorized_keys` から削除
5. プロジェクトの運用ノート・MEMORY.md を更新

---

## 5.12 セッション管理

### テナントユーザーのセッション

| 設定 | 値 |
|------|-----|
| 有効時間 | 8時間 |
| 無操作タイムアウト | 30分（25分で警告） |
| セッション再生成 | ログイン成功時 |
| マルチデバイス | 同時ログイン許可（警告表示） |

### POSLA管理者のセッション

| 設定 | 値 |
|------|-----|
| 有効時間 | 8時間 |
| Cookie属性 | HttpOnly、SameSite=Lax |
| セッション再生成 | ログイン成功時 |

---

## 5.13 スマレジ連携

### 設定

POSLA管理画面でスマレジのOAuthクライアント情報を設定します。

### OAuthトークン管理

- テナントごとにアクセストークンとリフレッシュトークンを保持
- トークンの有効期限を追跡
- 有効期限の5分前に自動リフレッシュ
- トークン失効時はグレースフルに対応

### テナントのスマレジ関連フィールド

| フィールド | 説明 |
|-----------|------|
| smaregi_contract_id | スマレジの契約ID |
| smaregi_access_token | アクセストークン |
| smaregi_refresh_token | リフレッシュトークン |
| smaregi_token_expires_at | トークン有効期限 |
| smaregi_connected_at | 接続日時 |

### マッピングテーブル

| テーブル | 説明 |
|---------|------|
| smaregi_store_mapping | POSLA店舗 ↔ スマレジ店舗の紐付け |
| smaregi_product_mapping | POSLAメニュー ↔ スマレジ商品の紐付け |

---

## 5.13c PWA Phase 2a Web Push 補助 (2026-04-20)

Web Push の購読土台とバッジ表示を追加 (詳細は `internal/09-pwa.md` §9.13)。

| 機能 | 配置 | 役割 |
|---|---|---|
| `push-subscription-manager.js` | admin / owner / kds / handy | 通知許可ボタン (ユーザー操作起点) + 購読保存 |
| `badge-manager.js` | KDS / handy | `navigator.setAppBadge` でホーム画面アイコンに件数表示 (KDS = 呼び出し件数 / handy = 同) |
| `api/push/config.php` | サーバー | VAPID 公開鍵 + 有効/無効状態を返す |
| `api/push/subscribe.php` | サーバー | 購読保存 (UPSERT) |
| `api/push/unsubscribe.php` | サーバー | 自分の購読を soft delete |
| `api/lib/push.php` | サーバー | VAPID 取得 + 送信関数スタブ (Phase 2a では no-op) |
| SW push / notificationclick handler | admin/sw.js / kds/sw.js / handy/sw.js | サーバー送信 (Phase 2b) を受信して通知表示 |

運用観点:

- **Push は補助通知**。ポーリング / RevisionGate / KDS audio alert を置き換えない (これらは無変更で並行稼働)
- **fail-open**: 送信失敗で元の業務 API を失敗扱いにしない (Phase 2b 実装時の必須要件)
- **VAPID 鍵管理**: `posla_settings` テーブルに `web_push_vapid_public` (Base64URL 87 文字) / `web_push_vapid_private_pem` (PEM 241 文字) で保存。秘密鍵はフロントへ絶対に返さない (`api/posla/settings.php` の `_public_setting_keys()` 許可リストに **追加しない**)
- **購読の物理削除なし**: 410 Gone / 404 / ユーザー操作 unsubscribe では `enabled=0 + revoked_at` に soft delete
- **Phase 2b で実装**: VAPID JWT 署名 + AES-128-GCM 暗号化での実送信 / call-alerts.php と satisfaction-rating.php への送信トリガー / `api/push/test.php` → 2026-04-20 (7) 完了 (§5.13d)

---

## 5.13d PWA Phase 2b Web Push 実送信 (2026-04-20)

純 PHP で VAPID JWT (ES256) + RFC 8291 aes128gcm 暗号化を実装し、Phase 2a の no-op スタブを実送信に置き換え (詳細は `internal/09-pwa.md` §9.14)。

### 構成ファイル

| ファイル | 種別 | 役割 |
|---|---|---|
| `scripts/generate-vapid-keys.php` | CLI | P-256 鍵ペア生成 + 投入 SQL を stdout に出力 |
| `api/lib/web-push-encrypt.php` | lib | aes128gcm 暗号化 (HKDF + ECDH + AES-128-GCM) |
| `api/lib/web-push-vapid.php` | lib | VAPID JWT 署名 + DER→raw 64 バイト変換 |
| `api/lib/web-push-sender.php` | lib | cURL POST + 410/404 で購読 soft delete |
| `api/push/test.php` | API | テスト送信 (target=self / store) |
| `api/lib/push.php` | lib (修正) | スタブ → 実送信実装 + tenant_id 境界二重化 + owner NULL 購読の OR 拾い |
| `api/push/subscribe.php` | API (修正) | scope=owner 厳格化 (role=owner 限定) + store_id 必須化 (非 owner scope) |
| `api/kds/call-alerts.php` | API (修正) | kitchen_call で `push_send_to_store('call_alert')` |
| `api/customer/call-staff.php` | API (修正) | スタッフ呼び出しで `push_send_to_store('call_staff')` — Phase 2 の本命通知 |
| `api/customer/satisfaction-rating.php` | API (修正) | rating ≤ 2 で `push_send_to_roles(['manager','owner'], 'low_rating')` |
| `public/shared/js/push-subscription-manager.js` | JS (修正) | `setStoreId()` API 追加 + `.repeat()` を ES5 手動ループに置換 |
| `public/{kds,handy,admin/dashboard,admin/owner-dashboard}.html` | HTML (修正) | storeId ポーリングで setStoreId 連携 + キャッシュバスター更新 |

### 本番デプロイ手順 (要約)

1. 修正 3 ファイルを `_backup_<date>_pwa2b/` にバックアップ
2. 8 ファイルを tar 化 → SCP → リモート展開 → chmod 604
3. `posla_settings` の `web_push_vapid_*` 既存値を確認 (再生成厳禁)
4. 未投入なら `bash /tmp/run-vapid.sh` で生成 + INSERT (`ON DUPLICATE KEY UPDATE`)
5. 出力された秘密鍵 PEM を社内パスワード金庫に保管
6. 端末側でキャッシュリセット → 通知許可 → `POST /api/push/test.php` で着信確認

完全なコマンド列は `internal/09-pwa.md` §9.14.6。

### 鍵管理ルール (恒久)

- **公開鍵** (`web_push_vapid_public` / 87 char Base64URL) はフロント露出 OK (`/api/push/config.php`)
- **秘密鍵** (`web_push_vapid_private_pem` / 241 char PEM) は **絶対にフロントへ返さない**
  - `api/posla/settings.php` の `_public_setting_keys()` 許可リストに追加禁止
  - サーバーログにも出力しない
  - Git に commit しない
- **ローテーションは原則しない** (再生成 = 全購読無効化 = 全端末で再設定が必要)
- 強制ローテーション時は `UPDATE push_subscriptions SET enabled=0, revoked_at=NOW()` と全端末への事前告知をセットで実施

### 接続済みトリガー

| type | 発火条件 | 送信先 |
|---|---|---|
| `call_staff` | 顧客セルフメニューの「スタッフ呼び出し」(`POST /api/customer/call-staff.php`) | 同 store の全購読 (handy / KDS / レジ / pos-register) |
| `call_alert` | KDS 呼び出しボタン (`POST /api/kds/call-alerts.php`) | 同 store の全購読 (handy / KDS / レジ) |
| `low_rating` | 顧客が ★1 / ★2 評価 (`POST /api/customer/satisfaction-rating.php`) | 同 store の manager / owner 購読 **+ 同 tenant の owner-NULL 購読** (全店横断通知を希望した owner) |
| `push_test` | テスト送信 API (`POST /api/push/test.php`) | self: 自ユーザー / store: 当該店舗全員 (manager+ のみ叩ける) |

### マルチテナント境界 (Phase 2b レビュー対応)

- 全クエリに `tenant_id = ?` を必ず入れる (store_id / user_id が UUID で全体一意でも、POSLA 原則として明示)
- `_push_get_tenant_id_for_{store,user}()` ヘルパで DB 解決。業務 API 側は引数追加不要
- subscribe.php: `tenant_id` は `$user['tenant_id']` (サーバー側セッションから確定、クライアント値は信用しない)

### 購読時 storeId 紐付けの必須化 (Phase 2b レビュー対応)

- `push_send_to_store` は `WHERE store_id = ?` で絞るため、店舗紐付けなしの購読は店舗通知に乗らない
- 対応: `PushSubscriptionManager.setStoreId(id)` を追加 + 各画面で storeId 確定タイミングを捕捉
  - KDS: `KdsAuth.getStoreId()` を 1 秒ポーリング (確定まで)
  - handy: `localStorage('mt_handy_store')` を 5 秒ポーリング (店舗切替に追従)
  - admin (manager/staff): `getCurrentStoreId()` を 5 秒ポーリング (店舗切替に追従)
  - owner (owner-dashboard): NULL のまま = 全店横断通知希望 (`push_send_to_roles` で OR 拾い)
- subscribe.php 側: `scope !== 'owner'` で `store_id` 未指定 → `400 STORE_REQUIRED`
- `scope === 'owner'` は `role === 'owner'` のみ許可 (`role !== 'owner'` で 403 FORBIDDEN_SCOPE)

### fail-open 三重防御

1. `web_push_send_one()` が `Throwable` を内部キャッチして `['ok'=>false]` を返す
2. `push_send_to_*()` が catch で 0 件を返す
3. 業務 API (`call-alerts.php` / `satisfaction-rating.php`) で `try/catch` wrap

→ 通知が静かに飛ばないだけで、注文・呼び出し・評価そのものは絶対に通る。

### ロールバック

修正 3 ファイルだけ `_backup_<date>_pwa2b/` から戻せば Phase 2a の no-op に戻る。新規 5 ファイルは削除してよい。VAPID 鍵 (`posla_settings`) は残しても無害 (再展開時に再投入不要になる)。

### Phase 2b 拡張 (2026-04-20 追加)

Web Push 監視・抑制・管理 UI を追加した (詳細は `internal/09-pwa.md` §9.14.11 / §9.14.12 / §9.14.13)。

| 項目 | 内容 |
|---|---|
| 送信ログ | `push_send_log` テーブル (SQL: `sql/migration-pwa2b-push-log.sql`)。`type` / `tag` / `status_code` / `reason` / `sent_at` のみ記録 (title/body は PII として保存しない)。保持 90 日 |
| レート制限 | 同 user_id + type + tag の 2xx が直近 60 秒以内にあれば skip。tag 空ならスキップしない (テスト送信を通す) |
| important_error | `monitor-health.php` で Stripe Webhook 失敗を tenant 別集計 → 代表店舗の manager+owner に自動 Push。5 分重複抑制。POSLA 運営への Slack 通知は維持 |
| POSLA 管理 UI | dashboard.html に「PWA/Push」タブ追加。`api/posla/push-vapid.php` で VAPID 状態 / 購読統計 / 24h 送信統計を読み取り専用表示 |
| 実装しないもの | 秘密鍵ダウンロード / 鍵ローテーション / テスト送信 (いずれも誤操作リスクか認証系の不整合のため) |

**秘密鍵の保管記録 (2026-04-20)**:
- 生成直後に `/tmp/vapid-output.txt` (リモート) をローカル `~/Desktop/posla-secrets/vapid-backup-20260420.txt` (chmod 600) にダウンロード済
- リモート `/tmp/vapid-*.txt` `/tmp/run-vapid.sh` 等を `rm` でクリーンアップ済
- ユーザー側で 1Password / Bitwarden 等の金庫に手動移行
- 公開鍵: `BFRFB0qKKxpAupo4q00s9TU70oVJZS5IvcKwSQY5z0iPN1zN_KdHelTKm_CcTkS47D06EsgIaayV8rofyFAXJa4`

**SQL マイグレーション適用済み確認**: 2026-04-20 本番 `odah_eat-posla` DB に `push_send_log` テーブル作成済 (`SHOW TABLES LIKE 'push_send_log'` で確認可)

---

## 5.13e PWA Phase 3 通信不安定時の表示耐性 (2026-04-20)

業務端末 (KDS / レジ / ハンディ) で **通信が一時的に切れたとき** に画面が真っ白にならず、最後に取得できたデータを read-only で残す共通基盤 (詳細は `internal/09-pwa.md` §9.15)。

### 構成ファイル

| ファイル | 種別 | 役割 |
|---|---|---|
| `public/shared/js/offline-snapshot.js` | 新規 | localStorage ベースの GET レスポンス退避 (TTL 12h / 1.5MB 上限 / quota 握りつぶし / 顧客パス除外) |
| `public/shared/js/offline-state-banner.js` | 新規 | 黄バナー (古いデータ / 通信不調) + 緑バナー (復帰)。既存 `offline-detector.js` の赤バナーとは別 DOM (#offline-state-banner) で共存 |
| `public/kds/js/polling-data-source.js` | 修正 | snapshot 保存・復元 + `resetStaleState()` 公開 |
| `public/kds/js/kds-renderer.js` | 修正 | stale 時 action ボタン disabled |
| `public/kds/js/kds-alert.js` | 修正 | snapshot 保存・stale 表示 + ack PATCH オフラインガード |
| `public/handy/js/handy-alert.js` | 修正 | 同上 |
| `public/handy/js/handy-app.js` | 修正 | menu / tables snapshot + `submitOrder` ガード |
| `public/handy/js/pos-register.js` | 修正 | tables-status / orders snapshot + `processPayment` ガード |
| `public/kds/js/cashier-app.js` | 修正 | orders+sessions snapshot + `_submitPayment` / `_issueReceipt` / `_confirmRefund` / `_postCashLog` / `_processTerminalPayment` ガード |
| 4 HTML | 修正 | helpers ロード + `OfflineStateBanner.init()` |

### 通信不安定時の現場対応

| 症状 | 確認 |
|---|---|
| 黄色バナー「古いデータを表示中」 | 通信が切れている。**操作は通信復帰後** に行う。データは消えていないので落ち着いて |
| 緑バナー「通信が復帰しました」 | 自動で full fetch 中。3 秒で消える。表示は最新に置き換わる |
| 赤バナー「インターネット接続がありません」 (既存 OfflineDetector) | 端末の Wi-Fi / モバイル回線そのものが切れている。Wi-Fi 設定を確認 |
| 「再取得」ボタン押下 | 該当画面に依存: KDS は forceRefresh、handy/pos-register/cashier は location.reload。業務中はあまり押させない |

### オフライン中に押された業務ボタンの挙動

判定は `OfflineStateBanner.isOfflineOrStale()` 共通ヘルパで行う (offline / stale いずれかで true)。

| ボタン | 挙動 |
|---|---|
| KDS 調理開始 / 完成 / 提供済 / 取消 / フェーズ進行 | 関数側で判定して楽観更新も API 呼び出しも両方止める (`handleAction` / `handleItemAction` / `advancePhase`)。DOM disabled は視覚補助 |
| KDS / handy 呼び出し対応済み | window.alert + ボタン状態リセット (PATCH しない) |
| ハンディ「注文送信」 | toast「オフライン中または古いデータ表示中です…」(POST しない、キューもしない) |
| ハンディ 予約着席 / no-show / テーブル開放 / 着席モーダル決定 / メモ保存 / 着席取消 / サブセッション発行 | 全て `_guardOfflineOrStale()` で同じ toast → リターン |
| レジ 会計実行 | toast「…」。さらに `_fetchOrders({failClosed:true})` が失敗すれば「最新の注文状態を取得できませんでした」toast で会計中断 |
| レジ カード決済 (Stripe Terminal) | toast 表示後リターン、Terminal SDK 呼ばれない |
| レジ 返金 / 領収書発行 / レジ操作 (両替・釣銭追加・閉店) | 全て同じガード + toast |

### Service Worker 変更なし

`public/admin/sw.js` / `public/kds/sw.js` / `public/handy/sw.js` は **本フェーズで変更なし**。VERSION バンプ不要。`/api/` 完全 passthrough、POST/PATCH/DELETE passthrough、顧客パス除外、センシティブクエリ除外の既存方針を維持。snapshot は localStorage 完結のため SW Cache を使わない (テナント境界・トークン混在のリスク回避)。

### ロールバック

11 ファイルを `_backup_20260420_pwa3/` から戻すだけ。新規 2 helpers は削除可。SW 変更がないためロールバック作業なし。

---

## 5.13f PWA Phase 4 緊急レジモード (2026-04-20)

レジ画面専用の「通信障害でも会計事実を失わない」台帳機能 (詳細は `internal/09-pwa.md` §9.17)。

### 構成ファイル

| ファイル | 種別 | 役割 |
|---|---|---|
| `sql/migration-pwa4-emergency-payments.sql` | 新規 | `emergency_payments` テーブル (tenant_id 明示、UNIQUE (store_id, local_emergency_payment_id)) |
| `api/store/emergency-payments.php` | 新規 | 同期 API (POST=保存 / GET=管理者向け一覧) |
| `public/kds/js/emergency-register-store.js` | 新規 | IndexedDB ラッパ (DB: `posla_emergency_v1`) |
| `public/kds/js/emergency-register.js` | 新規 | UI + 同期ワークフロー |
| `public/kds/js/cashier-app.js` | 修正 | `CashierApp.getEmergencyContext()` 1 public method 追加 |
| `public/kds/cashier.html` | 修正 | 2 新規 JS ロード + `EmergencyRegister.init` |

SW 変更なし / 既存 process-payment.php 変更なし。

### ステータス一覧

| status | 端末側 / サーバー側 | 意味 |
|---|---|---|
| `pending_sync` | 端末のみ | IndexedDB に保存済、未送信 |
| `syncing` | 端末のみ | 送信中 |
| `synced` | 両方 | サーバー台帳保存完了。金額整合・注文重複なし |
| `conflict` | 両方 | orderIds が既に payments に存在 (二重会計の疑い) |
| `pending_review` | 両方 | 金額差異あり、手入力モード由来等。**管理者確認必須** |
| `failed` | 端末のみ | ネットワークエラー等で再試行待ち |

### 通信障害時の現場対応

| 症状 | 対応 |
|---|---|
| 通常会計ボタンが disabled (Phase 3 の stale バナー出現中) | ヘッダ右上の 🔴 「緊急レジ」ボタンを押す |
| 黄色の未同期件数バッジが表示されている | 未同期会計一覧を確認。再試行 or 通信復帰を待つ |
| カード決済したい | **外部決済端末で処理**し、「緊急レジ」の支払方法で `外部カード端末` を選び控え番号/承認番号を記録 |
| カード番号を入力したい | **絶対に入力しない**。UI に入力欄は存在しない設計。外部端末の控えを保管 |
| 同じ会計を 2 回タップしてしまった | UNIQUE キーで二重登録されない。レスポンスに `idempotent: true` が返る |

### 重要: オフライン中にやってはいけないこと

- ❌ 通常会計ボタンの JS を直接叩く (Phase 3 でガード済み)
- ❌ ブラウザのキャッシュ削除 / IndexedDB 削除 / ログアウト (未同期会計が消える)
- ❌ カード番号 / 有効期限 / セキュリティコードをメモする・POSLA に入力する (PCI-DSS 範囲外設計を維持)
- ❌ 緊急会計の記録後、さらに同じテーブルで通常会計する (二重会計。サーバー側で conflict 検出されるが運用で避ける)

### 管理者確認フロー (Phase 4a 時点)

1. 管理者が `GET /api/store/emergency-payments.php?store_id=X&status=conflict` などで一覧取得
2. status=conflict / pending_review を手動で精査
3. 必要に応じて管理画面から `payments` テーブルへ手動 INSERT (Phase 4c 以降で自動化予定)

### 既存機能への影響

- **通常会計**: `process-payment.php` 無変更。オンライン時は完全に同じ挙動
- **返金 / 領収書 / 締め**: 無変更 (オフライン対応しない)
- **SW**: 無変更、VERSION バンプなし
- **KDS / ハンディ / 顧客画面**: 無影響

### ロールバック

1. cashier-app.js / cashier.html を `_backup_20260420_pwa4/` から戻す
2. 新規 4 ファイル削除
3. `emergency_payments` テーブルは **DROP しない** (未同期会計が消える)
4. Phase 4b/4c で復旧する際は再デプロイで再開可能

---

## 5.13g PWA Phase 4b 緊急会計の堅牢化 + 管理画面台帳 (2026-04-20)

Phase 4a (端末 IndexedDB 台帳 + サーバー同期) を、次の 2 点で堅牢化 (詳細は `internal/09-pwa.md` §9.18)。

### 4b-1: 子テーブル `emergency_payment_orders`

| 項目 | 内容 |
|---|---|
| migration | `sql/migration-pwa4b-emergency-payment-orders.sql` |
| UNIQUE | `(store_id, order_id, status)` で当面 status='active' のみ登録 |
| 目的 | 別 localId で同じ order_id を緊急会計した場合、DB レベルで 1062 エラー → 親 `emergency_payments.status='conflict'` に UPDATE |
| JSON 併存 | Phase 4a の `order_ids_json` は互換維持。Phase 4c+ で廃止検討 |
| 未適用時 | `42S02` をキャッチして親を `pending_review + conflict_reason='emergency_payment_orders_missing'` に UPDATE。500 にしない |

### 4b-2: 管理画面「緊急会計台帳」タブ

| 項目 | 内容 |
|---|---|
| 配置 | dashboard.html 「レポート」グループのサブタブ |
| 権限 | manager / owner のみ (staff/device は 403) |
| 読み取り API | `GET /api/store/emergency-payment-ledger.php?store_id=X&from=YYYY-MM-DD&to=YYYY-MM-DD&status=all\|synced\|conflict\|pending_review\|failed` |
| 機能 | 期間 + status フィルタ / KPI (件数・合計・要確認・PIN 未検証) / 一覧テーブル / 行クリックで詳細モーダル |
| 操作 | **一切なし (確認専用)**。承認・却下・payments 転記・取消は Phase 4c 以降 |

### 運用手順

#### 日次・週次で緊急会計を確認する

1. 管理画面 → レポート → 緊急会計台帳
2. 期間: 直近 7 日 (デフォルト) か、営業日単位で from/to 指定
3. KPI 欄で「要確認 (conflict + pending_review)」「PIN 未検証」が 0 でなければ精査
4. 行をクリックして詳細: itemSnapshot / orderIds / 外部端末控え番号・承認番号を確認

#### conflict が出たら

- `conflict_reason` 欄を確認
  - `order_already_paid: <oid>` → 通常会計で既に処理済。緊急会計は**無効**扱いにする
  - `emergency_duplicate_order: order=<oid> dup_local=<localId>` → 別端末で同じ注文の緊急会計あり。どちらを正とするか精査
  - `child_unique_race: ...` → 別端末と同時 POST した race。上記と同様に精査
- 対応: 該当の外部端末控え (カード端末の控え、QR 決済の控え) と突合し、「どちらの localId を売上に計上するか」を管理者が判定
- 現時点では POSLA 側での承認・取消 UI はまだない。業務記録にメモして Phase 4c リリースを待つ

#### pending_review が出たら

- `conflict_reason` で以下を確認:
  - `amount_mismatch: ...` → subtotal+tax と totalAmount の差異。店舗の運用で手動調整したケース等
  - `manual_entry_without_context` → 手入力モードで記録 (テーブル / 注文未紐付け)
  - `table_not_found_or_wrong_store: ...` → tableId が DB に存在しない / 他店舗のもの
  - `order_not_found_or_wrong_store: ...` → orderId が DB に存在しない / 他店舗のもの
  - `pin_entered_but_not_verified` → 初回同期失敗による再試行で PIN が送信されなかった
  - `emergency_payment_orders_missing` → 子テーブル migration 未適用 (本番では発生しない想定)
  - `child_insert_failed: ...` → 子テーブル INSERT で想定外エラー (稀)

#### 外部端末控えとの突合

- 外部カード端末の「日次締め」「売上一覧」と、緊急会計台帳の「外部端末決済」行を突き合わせる
- `externalSlipNo` / `externalApprovalNo` を手がかりに照合
- 外部端末側にない緊急会計 → 二重記録の疑い。管理者判定
- 緊急会計にない外部端末決済 → 通常会計として処理された可能性。POSLA の payments 側で確認

### まだ売上レポートに自動反映されないこと

- 緊急会計台帳の記録は **sales / register / reports 系に自動で計上されない**
- 店舗の「日次売上」に緊急会計を含めたい場合は、外部決済端末の控えを別途管理する運用 (Phase 4a 時点と同じ)
- Phase 4c で「管理者承認 → payments 転記」フロー実装予定。それ以降は台帳 UI から承認 → 通常売上に合算される

### ロールバック

- 新規 3 ファイル (migration / ledger API / ledger JS) を削除すれば 4b 分ロールバック可能
- **`emergency_payment_orders` テーブルは DROP しない** (Phase 4c で再利用)
- `emergency-payments.php` / `admin-api.js` / `dashboard.html` の修正部は `_backup_20260420_pwa4b_12/` から復元可能
- migration 未適用状態に戻しても、emergency-payments.php POST は 42S02 をキャッチして pending_review で記録を残す (会計事実は失わない)

---

## 5.13h PWA Phase 4c-1 管理者解決フロー (2026-04-20)

Phase 4b の「読み取り専用台帳」に、管理者判断を DB に残す仕組みを追加 (詳細: `internal/09-pwa.md` §9.19)。

### 追加カラム (emergency_payments)

`resolution_status` (unresolved / confirmed / duplicate / rejected / pending) / `resolution_note` / `resolved_by_user_id` / `resolved_by_name` / `resolved_at`

**status と resolution_status は独立軸**: status = 同期/照合状態 (サーバー自動判定)、resolution_status = 管理者判断。どちらか上書きしない。

### 新規 API

`POST /api/store/emergency-payment-resolution.php`
- body: `{ store_id, emergency_payment_id, action, note }`
- action: `confirm` | `duplicate` | `reject` | `pending`
- 権限: manager / owner のみ (staff/device は 403)
- TX + FOR UPDATE で順序保証
- 同値再送 → idempotent / 終結状態から異値 → 409 CONFLICT

### 運用手順 (4c-1 時点)

#### 日次: 未解決の精査

1. 管理画面 → レポート → 緊急会計台帳
2. KPI「未解決」件数を確認
3. 件数があれば status フィルタで conflict / pending_review を優先精査
4. 行クリック → 詳細 → 外部端末控え (カード端末のレシート/端末画面) と突合
5. 判断結果を 4 ボタンのいずれかで記録:

| action | 選ぶ場面 |
|---|---|
| **有効確認 (confirm)** | 緊急会計の記録内容は正しく、売上として扱うべき。ただし payments 転記は Phase 4c-2 以降 |
| **重複扱い (duplicate)** | 通常会計で既に処理済み / 同一注文を別端末が緊急会計した — 売上計上しない (note で詳細記載) |
| **無効扱い (reject)** | テスト記録 / 誤入力 / 悪意ある記録 — 売上計上しない (note で詳細記載) |
| **保留 (pending)** | 現時点で判断できない。追加調査待ち (カード端末側の日締め待ち等) |

#### 外部端末控えとの突合手順

1. 緊急会計の `paymentMethod` を確認
   - `cash` → レジ現金残高との整合
   - `external_card` → カード端末 (Airpay/Square 等) の日次売上レポートと `externalSlipNo` / `externalApprovalNo` を突合
   - `external_qr` → QR 決済端末の控えと突合
   - `other_external` → 店舗運用ルールに従う
2. 外部端末側にある → **有効確認** (confirm)
3. 外部端末側になく通常会計で売上計上済 → **重複扱い** (duplicate)
4. 外部端末側にも通常会計にもない → 要調査 → **保留** (pending)、後日再確認で reject 判断も可

### まだ売上レポートに反映されないこと

- `confirmed` にしても `payments` には転記されない (Phase 4c-2 で実装予定)
- 売上レポート / レジ締め / 日次売上は引き続き `payments` テーブルのみを見る
- 管理者が手動で payments に転記する場合は Phase 4c-2 リリース前は既存の「手動 INSERT」運用を継続

### ロールバック

- `emergency-payment-resolution.php` / `admin-api.js` / `emergency-payment-ledger.js` を `_backup_20260420_pwa4c1/` から戻せば Phase 4b 時点に戻る
- カラム追加は `DROP COLUMN` で戻せるが、既に管理者の解決記録が入っている可能性あり → **DROP しない**
- ledger API は `hasResolutionColumn=false` のフォールバックを持っているため、新版 JS を旧版 PHP に差し戻しても画面は壊れない

---

## 5.13i PWA Phase 4c-2 emergency_payments → payments 転記 (2026-04-20)

Phase 4c-1 で `resolution_status='confirmed'` を付けた緊急会計を、manager/owner が明示操作で通常 payments に 1 件ずつ転記する機能 (詳細は `internal/09-pwa.md` §9.20)。

### 新規 API
`POST /api/store/emergency-payment-transfer.php`
- body: `{ store_id, emergency_payment_id, note }`
- 権限: manager / owner のみ (staff/device は 403)
- Idempotent: synced_payment_id 既存なら UPDATE/INSERT 無しで 200
- 主要 409: resolution_status != confirmed / failed / other_external / orderIds 空 / 既存 payments 重複 / orders.paid

### 運用手順: 日次転記

1. 管理画面 → レポート → 緊急会計台帳
2. KPI「転記可能」件数を確認
3. resolution_status=confirmed + 未転記の行を 1 件ずつ開く
4. 詳細モーダル内「売上転記」ブロックを確認
5. 外部端末の日次売上レポートと金額・控え番号を突き合わせ
6. 一致を確認したら note (任意) を入れて「売上へ転記する」を押す
7. 確認ダイアログ (合計 / 支払方法 / テーブル表示) で外部端末控えとの一致を最終確認
8. 転記後は台帳の解決列 + 転記列が「転記済み + payment_id」になり、売上レポート / レジ締め対象に含まれる

### 日次確認 SQL

```sql
-- その日の転記件数と金額
SELECT COUNT(*), SUM(total_amount)
  FROM emergency_payments
 WHERE tenant_id = ? AND store_id = ?
   AND synced_payment_id IS NOT NULL
   AND DATE(transferred_at) = CURDATE();

-- 転記可能だが未処理 (要確認)
SELECT id, total_amount, table_code, resolved_by_name, resolved_at
  FROM emergency_payments
 WHERE tenant_id = ? AND store_id = ?
   AND resolution_status = 'confirmed'
   AND synced_payment_id IS NULL
   AND status IN ('synced','conflict','pending_review')
   AND payment_method <> 'other_external'
   AND order_ids_json IS NOT NULL AND JSON_LENGTH(order_ids_json) > 0;

-- 整合性チェック (emergency と payments の金額一致確認)
SELECT e.id AS emergency_id, e.total_amount, p.total_amount AS payments_total
  FROM emergency_payments e
  LEFT JOIN payments p ON p.id = e.synced_payment_id AND p.store_id = e.store_id
 WHERE e.synced_payment_id IS NOT NULL
   AND (p.id IS NULL OR p.total_amount != e.total_amount);
-- → 0 行なら OK。1 行以上あれば手動精査
```

### 409 が出たら

| conflict_reason の内容 | 運用対応 |
|---|---|
| `order_already_finalized` | 通常会計で既に paid 化された。緊急会計側は転記せず resolution_status を **duplicate** に変更する運用 (Phase 4c-1 UI) |
| `order_already_in_payments` | 同上 (別 payments レコードで既に売上計上済み) |
| `order_not_found` | orders テーブルに存在しない → **reject** 扱いで |
| `order_update_rowcount_mismatch` | 並行して別管理者が転記した可能性。台帳再読み込みで状態確認 |

### まだやらないこと (Phase 4d 以降)

- **転記取消 / 売上取消**: 転記後に「やっぱり取り消したい」を実行するには、現状 payments から手動 DELETE + orders.status を 'served' に手動 UPDATE が必要。Phase 4d で専用 UI を実装予定
- **転記後の返金**: 返金は既存の refund フロー (payments.refund_status) を使う。緊急会計由来かどうかの区別は `payments.note` の "emergency_transfer src=..." 文字列で検索可能
- **manual_entry / other_external の反映**: Phase 4d で設計

### ロールバック

- `emergency-payment-transfer.php` / 新規 UI を `_backup_20260420_pwa4c2/` から戻せば Phase 4c-1 時点に戻る
- `emergency_payments` の transferred_* カラムは DROP しない (既に転記実績が入っている可能性)
- 転記済み payments レコードは DROP しない (売上データ保全)
- **転記処理中に起きた orders の status='paid' は rollback されない場合でも、整合性は「payments に INSERT されているかどうか」で判定する** (emergency_payments.synced_payment_id が真実)

---

## 5.13b PWA Phase 1 補助機能 (2026-04-20)

PWA 関連の運用改善 (詳細は `internal/09-pwa.md` §9.12):

| 機能 | 配置 | 役割 |
|---|---|---|
| `pwa-update-manager.js` | admin / owner / kds / cashier / handy / pos-register の 6 画面 | SW 新版検知バナー (「更新する」「後で」) + キャッシュリセットボタン (scope 別 prefix のみ削除、localStorage / Cookie は触らない) |
| `wake-lock-helper.js` | KDS (`public/kds/index.html`) のみ | 画面 Wake Lock を user gesture (KDSを開始ボタン) で取得、visibilitychange で再取得。状態を KDS ヘッダに表示 |
| SW message handler (Phase 1) | admin/sw.js / kds/sw.js / handy/sw.js | `SKIP_WAITING` (更新ボタン押下時) / `GET_VERSION` (デバッグ) |

運用観点:

- 業務中の自動リロードは行わない (操作中の事故防止)。「更新する」ボタン押下時のみ
- キャッシュリセットは現在 scope の Cache Storage のみ削除。ログイン情報・店舗選択は保持
- Wake Lock は **保証機能ではなく補助機能**。端末側の省電力モード / 画面ロック設定 / バッテリーセーバーで無効化されることがある
- 顧客セルフメニュー (`public/customer/`) には一切影響なし (二重防御で skip)

---

## 5.13j PWA Phase 4d-1 PIN 入力有無の永続化 + 台帳 UX (2026-04-20)

Phase 4c-2 までで完成した「緊急会計 → 管理者判断 → 売上転記」の台帳に、「PIN 入力の 4 状態」と「担当の自己申告プレフィクス」を追加する UX 改善 (詳細は `internal/09-pwa.md` §9.21)。**会計ロジックは一切変更していない**。

### 新規 migration

`sql/migration-pwa4d-pin-entered-client.sql`

```sql
ALTER TABLE emergency_payments
  ADD COLUMN pin_entered_client TINYINT(1) DEFAULT NULL
  COMMENT 'クライアントが PIN 入力したか。NULL=旧データ/不明、0=入力なし、1=入力あり'
  AFTER staff_pin_verified;
```

- **NOT NULL DEFAULT 0 にしない**。既存行には「PIN 入力したが不一致」「旧仕様で必須だった」「カラム未追加時代」が混在しており、全て「入力なし」と表示すると意味を誤らせるため NULL = 旧データ / 不明 を明示する
- 条件付き backfill: `staff_pin_verified=1` / `conflict_reason LIKE '%pin_entered_but_not_verified%'` のみ 1 を埋め、それ以外は NULL 残置
- INFORMATION_SCHEMA + PROCEDURE で冪等 (2 回実行しても安全)

### 緊急会計台帳の PIN 表示 (4 状態)

| 条件 | バッジ | 色 |
|---|---|---|
| `staffPinVerified = true` | ✓ 確認済 | 緑 |
| `pinEnteredClient = true && !staffPinVerified` | 入力不一致 | オレンジ |
| `pinEnteredClient = false && !staffPinVerified` | 入力なし | グレー |
| `pinEnteredClient = null && !staffPinVerified` | 未確認 (旧データ/不明) | グレー |

### 担当表示

- PIN 検証済 (`staffPinVerified = true`) → staff_name を通常表示 (users テーブル紐付きあり)
- PIN 未検証 → `(自己申告) staffName` と prefix 付き表示 (users 紐付きなし、クライアント自己報告)

### 手入力記録バッジ

`tableId/orderIds` 空、または `conflict_reason` に `manual_entry_without_context` を含む行に薄黄色バッジ (`#fff3cd`) + 行背景薄黄色 (`#fffde7`)。エラー色の赤にはしない (「管理者確認が必要」の意味)。

### 列名改名

- 「状態」→「**同期状態**」 (`status` 列)
- 「解決」→「**管理者判断**」 (`resolution_status` 列)

### 変更しない範囲

- `payments` / `orders` / `cash_log` / `table_sessions` 一切変更なし
- `process-payment.php` / `refund-payment.php` / `register-report.php` / `payments-journal.php` / `receipt.php` 変更なし
- `emergency-payment-transfer.php` / `emergency-payment-resolution.php` 変更なし
- Service Worker 変更なし

### 検証 SQL

```sql
-- pin_entered_client カラムが存在するか
SHOW COLUMNS FROM emergency_payments LIKE 'pin_entered_client';

-- backfill 件数
SELECT
  COUNT(*) AS total,
  SUM(CASE WHEN pin_entered_client IS NULL THEN 1 ELSE 0 END) AS null_rows,
  SUM(CASE WHEN pin_entered_client = 1 THEN 1 ELSE 0 END) AS entered_1
  FROM emergency_payments;
```

### ロールバック

Phase 4d-1 は追加のみの変更。問題があれば以下で戻せる:
- `_backup_20260420_pwa4d_1/` から API/UI/cache buster を戻す
- カラム自体は残して UI 側だけロールバックすれば無害 (旧 UI は `pin_entered_client` を無視する)

---

## 5.13k PWA Phase 4d-2 other_external の外部分類 (2026-04-21)

`payment_method='other_external'` の詳細を「商品券 / 事前振込 / 売掛 / ポイント充当 / その他」に機械可読化する UI/DB 改善 (詳細は `internal/09-pwa.md` §9.22)。**会計ロジックには触れていない**。

### 新規 migration (2 本)

- `sql/migration-pwa4d2a-emergency-external-method-type.sql` — `emergency_payments` に追加
- `sql/migration-pwa4d2b-payments-external-method-type.sql` — `payments` に追加

```sql
ALTER TABLE emergency_payments
  ADD COLUMN external_method_type VARCHAR(30) DEFAULT NULL
  COMMENT 'other_external 時のみ設定。voucher / bank_transfer / accounts_receivable / point / other。NULL=未分類または対象外'
  AFTER payment_method;

ALTER TABLE payments
  ADD COLUMN external_method_type VARCHAR(30) DEFAULT NULL
  COMMENT 'other_external 等の外部分類。通常 cash/card/qr では NULL。voucher / bank_transfer / accounts_receivable / point / other'
  AFTER payment_method;
```

- **DEFAULT NULL**。既存行は NULL のまま
- **backfill なし**。旧 `other_external` 行は「その他外部（未分類）」と表示し、4d-3 以降で管理者が手動分類できる余地を残す
- 2 本に分割して独立 apply / rollback 可能
- INFORMATION_SCHEMA + PROCEDURE で冪等

### 緊急会計台帳の表示

- `paymentMethod='other_external'` と `externalMethodType` の組み合わせで支払列が：
  - `+ voucher` → 「その他外部（商品券）」
  - `+ bank_transfer` → 「その他外部（事前振込）」
  - `+ accounts_receivable` → 「その他外部（売掛）」
  - `+ point` → 「その他外部（ポイント充当）」
  - `+ other` → 「その他外部（その他）」
  - `+ NULL` → 「その他外部（未分類）」← 旧データ
- 詳細モーダルには「外部分類:」行を `other_external` の時のみ追加
- 未分類はエラーの赤ではなくグレー中立色

### 緊急レジ UI (レジ画面)

- 支払方法で「その他外部決済」を選んだときだけ「分類（必須）」select が出る
- 初期値は空欄「選択してください」。**`voucher` を既定にしない**（商品券でないのに登録する事故を防ぐため）
- 未選択で記録しようとすると alert で止める
- `external_card` / `external_qr` / `cash` では select 非表示、値は送信しない

### 変更しない範囲

- `emergency-payment-transfer.php` (other_external の 409 維持)
- `payments.payment_method` ENUM
- `process-payment.php` / `register-report.php` / `payments-journal.php` / `refund-payment.php` / `receipt.php`
- Service Worker
- 顧客画面 / ハンディ / KDS 注文画面

### 検証 SQL

```sql
SHOW COLUMNS FROM emergency_payments LIKE 'external_method_type';
SHOW COLUMNS FROM payments LIKE 'external_method_type';

-- 分布 (emergency_payments)
SELECT payment_method, external_method_type, COUNT(*) FROM emergency_payments
 GROUP BY payment_method, external_method_type;
```

### ロールバック

- `_backup_20260420_pwa4d_2/` から API/UI/cache buster 復元
- カラムは残しても無害（旧 UI は `externalMethodType` を無視）

---

## 5.13l PWA Phase 4d-4a 手入力売上計上 (2026-04-21)

緊急会計台帳の手入力記録（`orderIds` 空）を通常 `payments` に計上する専用 API (詳細は `internal/09-pwa.md` §9.24)。**会計ロジックへの新規干渉はなく、既存 transfer とは独立経路**。

### 新規 API

`POST /api/store/emergency-payment-manual-transfer.php`
- manager / owner 限定
- 対象: `resolution_status='confirmed'` AND `synced_payment_id IS NULL` AND `orderIds 空` AND `payment_method 許可 4 種` AND `other_external は分類済み`
- 動作: TX + FOR UPDATE + `synced_payment_id` 流用で idempotent

### 反映先と反映しない先

| 画面 / API | 反映 |
|---|---|
| `payments-journal.php` | ✅ 反映（payments 直接 SELECT のため自動） |
| `register-report.cashLog` | ✅ cash 手入力は `cash_sale` INSERT で反映 |
| `register-report.externalMethodBreakdown` | ✅ other_external 分類済みは `payments.external_method_type` で反映 |
| `register-report.paymentBreakdown` | ❌ orders ベース集計のため出ない（他の 4c-2 転記分と同じ） |
| **`sales-report.php`** | ❌ **orders.status='paid' ベース集計のため出ない（4d-4a の仕様）** |
| `receipt.php` | △ `paid_items` があれば発行可能。手入力で明細が空だと「明細なし」表記 |
| `refund-payment.php` | ❌ `gateway_name=NULL` で NO_GATEWAY 400、現金は CASH_REFUND 400。手入力売上は構造上システム返金不可 |

### 運用ルール

- 「手入力売上は売上レポートに出ない」ことを店舗スタッフに必ず伝える
- 手入力計上した記録の取消は Phase 4d-5 以降で実装予定。当面は**誤計上に注意**
- 手入力計上済みの記録の resolution を `rejected` に戻せてしまう既存挙動は UI 側でガードしていないため、管理者教育で対処

### 検証 SQL

```sql
-- 本日の手入力計上分
SELECT id, payment_method, external_method_type, total_amount, note, paid_at
  FROM payments
 WHERE store_id = ? AND DATE(paid_at) = CURDATE()
   AND JSON_LENGTH(order_ids) = 0;

-- 緊急会計側との連携確認
SELECT e.id, e.payment_method, e.external_method_type,
       e.synced_payment_id, e.transferred_by_name, e.transferred_at
  FROM emergency_payments e
 WHERE e.store_id = ?
   AND JSON_LENGTH(e.order_ids_json) = 0
   AND e.synced_payment_id IS NOT NULL;
```

### ロールバック

- `_backup_20260421_pwa4d_4a/` から API/UI を戻す
- 新規 `emergency-payment-manual-transfer.php` を削除しても他 API に影響なし（独立経路）
- 既に作成された payments / cash_log 行は DB 上に残るが、既存レポートへの影響は §5.13l 反映表のとおり

---

## 5.13m PWA Phase 4d-5a 手入力計上の void (2026-04-21)

Phase 4d-4a で作成した手入力計上 payments を論理取消できるようにする API + UI (詳細は `internal/09-pwa.md` §9.25)。**通常会計 / 注文紐付き emergency transfer は対象外**で、API 側で 409 NOT_VOIDABLE_IN_4D5A に落ちる。

### 新規 migration

`sql/migration-pwa4d5a-payment-void.sql` — payments に `void_status VARCHAR(20) DEFAULT 'active'` + `voided_at/by` + `void_reason` + `idx_pay_void_status` 追加（INFORMATION_SCHEMA + PROCEDURE 冪等、backfill 不要）。

### 新規 API

`POST /api/store/emergency-payment-manual-void.php`
- manager / owner / reason 必須
- 二重ガード: `payments.order_ids=[]` AND `payments.note LIKE '%emergency_manual_transfer%'`
- cash は cash_log.cash_out で相殺（既存 ENUM 流用）
- 二重 void は idempotent

### 反映先と反映しない先

| 画面 / API | 反映 |
|---|---|
| `payments-journal.php` | ✅ `void_status` 返却（voided 行も一覧表示、UI バッジで「取消済み」）|
| `register-report.externalMethodBreakdown` | ✅ `void_status<>'voided'` で除外 |
| `register-report.cashLog` | ✅ `cash_out` 相殺行で期待残高が相殺される |
| `emergency-payment-ledger` | ✅ records に `paymentVoidStatus/paymentVoidedAt/paymentVoidReason` |
| `sales-report.php` | ❌ **変更なし**（手入力は元々 orders ベースに出ないため無影響）|
| `receipt.php` | ❌ **当時は変更なし**（既存再印刷導線を壊さない方針）※ 2026-04-21 Phase 4d-5c-ba で POST 新規発行に `VOIDED_PAYMENT` / `REFUNDED_PAYMENT` 409 ガードを追加済み（詳細 §5.13p） |
| `refund-payment.php` | ❌ **当時は変更なし**（手入力は元々 NO_GATEWAY で 400）※ 2026-04-21 Phase 4d-5c-ba で voided に対する 409 `ALREADY_VOIDED` ガードを追加済み（詳細 §5.13p） |
| `process-payment.php` / `emergency-payment-transfer.php` | ❌ **変更なし** |

### 運用ルール

- 手入力計上の誤操作を取り消す用途に限定
- 注文紐付き transfer を取消したい場合は 4d-5b、通常会計を取消したい場合は 4d-5c-ba / 4d-5c-bb-A（§5.13p / §5.13q）
- cash void の相殺は `cash_out` でレジ残高には反映されるが、`cashSales` KPI は純加算のまま（期待残高は差分 0 になるので実運用上問題なし）
- void 済み payment に対して refund-payment.php を叩いた場合、現在は **先に 409 `ALREADY_VOIDED` で弾かれる**（4d-5c-ba で追加）。以前の `CASH_REFUND` / `NO_GATEWAY` に到達する前で遮断される。

### 検証 SQL

```sql
-- void 済み手入力計上
SELECT id, payment_method, external_method_type, void_status, voided_at, void_reason
  FROM payments
 WHERE store_id = ? AND void_status = 'voided'
   AND JSON_LENGTH(order_ids) = 0
   AND note LIKE '%emergency_manual_transfer%';

-- 対応する cash_out 相殺行
SELECT id, type, amount, note, created_at
  FROM cash_log
 WHERE store_id = ? AND type = 'cash_out' AND note LIKE '手入力売上取消%'
 ORDER BY created_at DESC;
```

### ロールバック

- `_backup_20260421_pwa4d_5a/` から API/UI を戻す
- 新規 `emergency-payment-manual-void.php` を削除
- migration 自体は残して問題なし（既存行は `void_status='active'` のまま）
- 作成された voided 行は DB 上に残るが、既存レポートへの影響は上記反映表のとおり

---

## 5.13n PWA Phase 4d-5b 注文紐付き緊急会計転記 void (2026-04-21)

Phase 4c-2 で転記された注文紐付き payments を論理取消できるようにする (詳細は `internal/09-pwa.md` §9.26)。**A 案採用**: payments voided のみ、orders.status / table_sessions / tables.session_token は触らない (accounting void と operational reopen を分離)。

### 新規 API

`POST /api/store/emergency-payment-transfer-void.php`
- manager / owner / reason 必須
- 三重ガード: `synced_payment_id+JSON_LENGTH>0` / `payments.order_ids JSON_LENGTH>0` / `note LIKE '%emergency_transfer src=%' AND NOT LIKE '%emergency_manual_transfer%'`
- 手入力計上は 409 NOT_VOIDABLE_IN_4D5B (4d-5a へ誘導)
- 通常会計 payments も 409 (4d-5c 待ち)
- cash は cash_log.cash_out 相殺
- `payments.void_status` カラムは 4d-5a 流用、新規 migration なし

### 反映先と反映しない先

| 画面 / API | 反映 |
|---|---|
| `payments-journal.php` | ✅ void_status 表示 (4d-5a 既存) |
| `register-report.externalMethodBreakdown` | ✅ void 除外 (4d-5a 既存) |
| `register-report.cashLog` | ✅ cash_out 相殺で期待残高が相殺される |
| `emergency-payment-ledger` | ✅ paymentVoidStatus 反映 (4d-5a 既存) |
| **`sales-report.php`** | ❌ **残る**（orders.status='paid' のため。仕様、4d-5c で統合予定）|
| **`turnover-report.php`** | ❌ **残る**（同上）|
| `register-report.paymentBreakdown` | ❌ **当時は残る**（orders ベース集計のため）※ 2026-04-21 4d-5c-a で「注文に active payment が 1 件も無ければ除外」に更新済み（§5.13o）|
| `receipt.php` | ❌ **当時は変更なし**（voided でも再印刷可能）※ 2026-04-21 4d-5c-ba で **POST 新規発行** は 409 `VOIDED_PAYMENT` に変更、**GET detail 再印刷** は引き続き可能（§5.13p） |
| `refund-payment.php` | ❌ **当時は変更なし**（既存 NO_GATEWAY/CASH_REFUND で弾かれる）※ 2026-04-21 4d-5c-ba で voided に対する 409 `ALREADY_VOIDED` 先頭ガードを追加済み（§5.13p） |
| `orders.status` / `table_sessions` / `tables.session_token` | ❌ **触らない**（KDS 再表示・卓状態復活の事故回避）|

### 運用ルール

- 注文紐付き transfer の誤転記を取り消す用途
- **sales-report に売上が残るため、二重計上に注意**
- 4d-5c 以降で sales-report 統合が完了するまでは、運用で「取消済み = 売上から除外」を把握しておく
- テーブルセッションは戻らないため、お客さんが既に退店した後の取消が前提
- 同じ卓で再注文がある場合は、新しいセッションで通常会計を行う

### 検証 SQL

```sql
-- void 済み注文紐付き transfer (4c-2 経路)
SELECT id, payment_method, external_method_type, void_status, voided_at, void_reason
  FROM payments
 WHERE store_id = ? AND void_status = 'voided'
   AND JSON_LENGTH(order_ids) > 0
   AND note LIKE '%emergency_transfer src=%'
   AND note NOT LIKE '%emergency_manual_transfer%';

-- 対応する cash_out 相殺行
SELECT id, type, amount, note, created_at
  FROM cash_log
 WHERE store_id = ? AND type = 'cash_out' AND note LIKE '緊急会計転記取消%'
 ORDER BY created_at DESC;

-- orders.status は paid のままであることを確認 (戻されていない)
SELECT o.id, o.status, o.paid_at
  FROM orders o
 WHERE o.id IN (
   SELECT JSON_UNQUOTE(JSON_EXTRACT(p.order_ids, '$[0]'))
     FROM payments p
    WHERE p.id = '<voided payment id>'
 );
```

### ロールバック

- `_backup_20260421_pwa4d_5b/` から API/UI を戻す
- 新規 `emergency-payment-transfer-void.php` を削除
- 4d-5a の void カラムは流用なのでそのまま残してよい
- 既に voided の payments は DB 上に残るが、影響は上記反映表のとおり (sales-report には元々残る)

---

## 5.13o PWA Phase 4d-5c-a 売上系レポートの全 voided 注文除外 (2026-04-21)

Phase 4d-5b で「注文紐付き emergency transfer」を void した記録が、依然として `sales-report.php` / `turnover-report.php` / `register-report.paymentBreakdown` に売上として残っていた残リスクを解消する (詳細は `internal/09-pwa.md` §9.27)。

### 変更内容

3 つの売上系 API の集計 SQL に「**注文に紐づく payments が 1 件も active でなければ除外**」する NOT EXISTS 句を追加。

### 影響表

| 画面 / API | 変更 |
|---|---|
| `sales-report.php` (summary / itemRanking / hourly / tableSales / serviceTime) | ✅ 全 voided 注文を除外 |
| `turnover-report.php` の hourly / customer spend / priceBand | ✅ 同上 |
| `turnover-report.php` の **cooking analysis** | ❌ 触らない (調理時間は運用実績) |
| `turnover-report.php` の **table_sessions 回転率** | ❌ 触らない (テーブル回転は運用実績) |
| `register-report.paymentBreakdown` | ✅ 同上 |
| `register-report.cashLog` / `expectedBalance` | ❌ 4d-5a で cash_out 相殺済み |
| `register-report.externalMethodBreakdown` | ❌ 4d-5a で除外済み |
| `payments-journal.php` | ❌ 変更なし (voided 行も履歴として表示) |
| `process-payment.php` / `customer/checkout-confirm.php` 等 | ❌ 変更なし |

### 重要な仕様

- **「有効 payment」判定**: `void_status IS NULL OR void_status <> 'voided'`
  - migration-pwa4d5a 未適用環境では条件無しで従来挙動 (動的フォールバック)
- **payments 未紐付 orders は除外しない** (緊急会計外の通常運用を壊さない)
- **分割会計の「一部 voided」は集計に残る** (active が 1 件でもあれば全額計上)
- 新規 migration / UI / cache buster は不要

### 検証 SQL

```sql
-- 期間中の「全 voided」となった orders 件数 (= レポートから除外される件数)
SELECT COUNT(*) FROM orders o
WHERE o.store_id = ? AND o.status = 'paid'
  AND o.created_at >= ? AND o.created_at < ?
  AND EXISTS (SELECT 1 FROM payments p WHERE p.store_id=o.store_id AND JSON_CONTAINS(p.order_ids, JSON_QUOTE(o.id)))
  AND NOT EXISTS (SELECT 1 FROM payments p2 WHERE p2.store_id=o.store_id AND JSON_CONTAINS(p2.order_ids, JSON_QUOTE(o.id)) AND (p2.void_status IS NULL OR p2.void_status<>'voided'));

-- 期間中レポートに残る orders 件数 (= 除外を反映した sales-report.summary.orderCount と一致するはず)
SELECT COUNT(*) FROM orders o
WHERE o.store_id = ? AND o.status = 'paid'
  AND o.created_at >= ? AND o.created_at < ?
  AND NOT (
    EXISTS (SELECT 1 FROM payments p WHERE p.store_id=o.store_id AND JSON_CONTAINS(p.order_ids, JSON_QUOTE(o.id)))
    AND NOT EXISTS (SELECT 1 FROM payments p2 WHERE p2.store_id=o.store_id AND JSON_CONTAINS(p2.order_ids, JSON_QUOTE(o.id)) AND (p2.void_status IS NULL OR p2.void_status<>'voided'))
  );
```

### 性能注意

JSON_CONTAINS は INDEX が効かないフィルタとして動作。**現状本番 112 行レベルでは問題なし**だが、payments が 10 万行規模になった時点で再計測が必要。深刻になれば 4d-5c-c の `payment_order_links` 正規化テーブルへの移行を検討。

### ロールバック

- `_backup_20260421_pwa4d_5c_a/` から 3 API を戻す
- DB は触っていないので戻り作業不要
- 戻すと voided 2 件が再び sales-report に出るだけ (既知の残リスクに戻るのみ、追加被害なし)

---

## 5.13p PWA Phase 4d-5c-ba 通常会計 void（cash + 注文紐付き）(2026-04-21)

通常会計（`process-payment.php` 経由で作成された `payments`）のうち、**cash + gateway_name IS NULL + is_partial=0 + dine_in/handy** の組合せを論理取消する新規 API を追加 (詳細は `internal/09-pwa.md` §9.28)。**A 案踏襲**: payments voided のみ、`orders.status='paid'` / `table_sessions` / `tables.session_token` は触らない。

### 新規 API

`POST /api/store/payment-void.php`
- manager / owner / reason（1–255 文字）必須
- 三重ガード: `order_ids JSON_LENGTH>0` / `note NOT LIKE 'emergency_%'` / `synced_payment_id IS NULL`
- 補正(2): 紐付き orders 全件が `status='paid'` かつ `order_type IN ('dine_in','handy')` を要求
- void 済は idempotent（voided=false, idempotent:true）で 200

### 409 エラーコード

| コード | 条件 |
|---|---|
| `NOT_VOIDABLE_IN_4D5C_BA` | 注文紐付きなし / emergency_transfer / emergency_manual_transfer / synced_payment_id ありなど |
| `NOT_VOIDABLE_REFUNDED` | `refund_status != 'none'` |
| `NOT_VOIDABLE_METHOD` | 4d-5c-ba 時点では cash 以外（**4d-5c-bb-A で削除、§5.13q 参照**） |
| `NOT_VOIDABLE_GATEWAY` | `gateway_name != ''`（Stripe Connect 経由など） |
| `NOT_VOIDABLE_PARTIAL` | `is_partial=1`（分割会計） |
| `NOT_VOIDABLE_ORDER_MISSING` | 紐付き orders の一部が店舗境界内に無い |
| `NOT_VOIDABLE_ORDER_STATUS` | 紐付き order.status が `paid` 以外 |
| `NOT_VOIDABLE_ORDER_TYPE` | takeout など `dine_in/handy` 以外 |

### 連動ガード（本フェーズで同時追加）

| ファイル | 変更 |
|---|---|
| `refund-payment.php` | voided payments に対する refund は **先頭で 409 `ALREADY_VOIDED`** |
| `receipt.php` (POST) | voided payments への新規発行は 409 `VOIDED_PAYMENT`、refunded は 409 `REFUNDED_PAYMENT` |
| `receipt.php` (GET detail=1) | 既発行レシートの**再印刷は引き続き可能**（意図的）|

### 反映先と反映しない先

| 画面 / API | 反映 |
|---|---|
| `payments-journal.php` | ✅ void_status 返却（既存 4d-5a 流用） |
| `sales-report.php` / `turnover-report.php` / `register-report.paymentBreakdown` | ✅ `void_status='voided'` の payments のみに紐付く orders は集計から除外（4d-5c-a の NOT EXISTS 句で自動対応）|
| `register-report.cashLog` | ✅ cash の場合、`cash_out` 相殺行が note `通常会計取消 payment_void:<id>` で自動追加 |
| `register-report.externalMethodBreakdown` | ✅ `void_status<>'voided'` で除外（4d-5a 既存）|
| `orders.status` / `paid_at` / `payment_method` | ❌ **触らない**（KDS 再表示・卓復活の事故回避）|
| `table_sessions` / `session_token` | ❌ **触らない** |
| `payments.payment_method` ENUM / `cash_log.type` ENUM | ❌ **拡張なし** |

### UI 変更

- `public/kds/js/cashier-app.js` の取引ジャーナルに **「会計取消」ボタン** 追加
- 4d-5c-ba 時点の表示条件: `method==='cash' && !gatewayName && !isPartial && !isRefunded && !isVoided`
- **4d-5c-bb-A で条件緩和**（§5.13q 参照）
- サマリに **netTotal = total − refundTotal − voidedTotal** と「取消済: N 件 / -¥N」KPI
- voided 行は `✗ 取消済` 赤バッジ、`.ca-journal__item--voided` クラス付与

### 運用ルール

- 通常会計の誤計上を取り消す用途
- cash 以外 / gateway 経由 / 分割 / takeout は本 API の対象外。Stripe Connect 経由は返金（refund-payment）で対応
- お客さんへの現金返却は手渡しで別途実施（cash_log 相殺で会計は合う）
- 取消理由は必須・監査ログに残る

### 検証 SQL

```sql
-- void 済み通常会計
SELECT id, payment_method, total_amount, void_status, voided_at, void_reason
  FROM payments
 WHERE store_id = ? AND void_status = 'voided'
   AND JSON_LENGTH(order_ids) > 0
   AND (note IS NULL OR note NOT LIKE 'emergency_%');

-- 対応する cash_out 相殺行 (cash のみ)
SELECT id, type, amount, note, created_at
  FROM cash_log
 WHERE store_id = ? AND type = 'cash_out' AND note LIKE '通常会計取消 payment_void:%'
 ORDER BY created_at DESC;
```

### ロールバック

- `_backup_20260421e/` から 7 ファイル（`refund-payment.php.bak` / `receipt.php.bak` / `admin-api.js.bak` / `cashier-app.js.bak` / `cashier.css.bak` / `dashboard.html.bak` / `cashier.html.bak`）を戻す
- 新規 `api/store/payment-void.php` を削除（バックアップなし＝新規ファイル）
- 既に voided の通常会計 payments は DB 上に残るが、レポート除外は 4d-5c-a のロジックでそのまま継続

---

## 5.13q PWA Phase 4d-5c-bb-A 手動 card / 手動 qr の void 拡張 (2026-04-21)

4d-5c-ba の範囲を **cash 限定 → `payment_method ∈ {'cash','card','qr'}` すべて**に拡張する最小スライス (詳細は `internal/09-pwa.md` §9.29)。**対象は "ゲートウェイ非経由" の手動 card / qr のみ**で、Stripe Connect などの外部決済は引き続き対象外。

### 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/store/payment-void.php` | `NOT_VOIDABLE_METHOD` ブロック削除（method 追加ガードは ENUM 制約に委譲）。gateway_name ガードはそのまま残すので、gateway 経由の card は引き続き 409 `NOT_VOIDABLE_GATEWAY` |
| `public/kds/js/cashier-app.js` | 取引ジャーナルの `canVoid` 条件から `method==='cash'` を除去。条件は `!isRefunded && !isVoided && !gatewayName && !isPartial` |
| `public/kds/cashier.html` | キャッシュバスター `?v=20260421-pwa4d-5c-ba` → `?v=20260421-pwa4d-5c-bb-A` |

### 反映先と反映しない先

| 画面 / API | 反映 |
|---|---|
| `payment-void.php` のガード構造（`order_ids` / `note` / `synced_payment_id` / `order_type='dine_in|handy'` など） | ❌ **変更なし**（4d-5c-ba 三重ガード + 補正(2) はそのまま活用）|
| cash_log INSERT ロジック（`$paymentMethodOnPayments === 'cash'`）| ❌ **変更なし**（手動 card / qr では cash_log に相殺が入らない。現運用と整合）|
| `refund-payment.php` / `receipt.php` のガード | ❌ **変更なし**（4d-5c-ba で導入済み） |
| sales-report / turnover-report / register-report の除外 | ❌ **変更なし**（4d-5c-a の NOT EXISTS 句がそのまま働く） |

### 運用ルール

- POS レジ > 取引履歴の **「会計取消」ボタン** の表示条件が緩和。`gateway_name=NULL` の手動 card / qr にもボタンが出る
- 手動 card / qr の void は `cash_log` に相殺行を INSERT **しない**（cash_log は cash 現金のみ。手動 card / qr は元から cash_log に計上していない）
- Stripe Connect 経由の card は引き続き表示されない（ボタン非表示）→ 返金（refund-payment）で対応
- 取消後の `cashLogId` レスポンスは **null**（cash_log 非関与を明示）

### 検証 SQL

```sql
-- void 済み手動 card / qr (gateway 非通過)
SELECT id, payment_method, IFNULL(gateway_name,'(null)') AS gw, total_amount, void_status, voided_at
  FROM payments
 WHERE store_id = ? AND void_status = 'voided'
   AND payment_method IN ('card','qr')
   AND (gateway_name IS NULL OR gateway_name = '')
   AND is_partial = 0;

-- 上記に対する cash_log 相殺行が「無い」ことの確認 (手動 card / qr は cash_log に入らない)
SELECT COUNT(*) AS should_be_zero
  FROM cash_log
 WHERE note LIKE '通常会計取消 payment_void:%'
   AND note LIKE CONCAT('%', '<voided manual card/qr payment_id>', '%');
```

### ロールバック

- `_backup_20260421f/` から 3 ファイル（`payment-void.php.bak` / `cashier-app.js.bak` / `cashier.html.bak`）を戻す
- DB は触っていないので戻り作業不要
- 戻すと **手動 card / qr の void ボタンが非表示**になり、API でも 409 `NOT_VOIDABLE_METHOD` に戻るだけ（既に voided の手動 card / qr は DB 上に残るが、レポート除外は継続）

### 次スライス候補（本フェーズ対象外）

| 論点 | 状況 |
|---|---|
| 論点 B: 分割会計 (is_partial=1) の void | **未着手**。同一 order に複数 payments 行があるため、個別 void / 一括 void の意味論と orders.status の扱いを要合意 |
| 論点 C: takeout の void | **対応済み（POS cashier 経路のみ）**。2026-04-21 4d-5c-bb-C で `order_type='takeout'` + `status='paid'` + cash + no gateway + is_partial=0 を void 可能に（§5.13s）。online takeout (status='served' / gateway 経由) は引き続き別スライス |
| 論点 D: 返金チェーン（void ⇄ refund の相互連鎖）| **スコープ外で確定**。実データ 0 件のため仕様先行を避ける |

---

## 5.13r PWA Phase 4d-5c-bb-A-post1 takeout online payment ENUM mismatch 修正 (2026-04-21)

`api/customer/takeout-payment.php` の payments INSERT が `payment_method="online"` を使っていたが、`payments.payment_method` は `ENUM('cash','card','qr')` で `'online'` は未収録。`STRICT_TRANS_TABLES` 下で INSERT が PDOException (ERROR 1265 Data truncated) で失敗し、try/catch で握りつぶされていたため、**オンライン takeout 決済の payments 行が永続的に欠落**していた (詳細は `internal/09-pwa.md` §9.30)。

### 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/customer/takeout-payment.php` | INSERT リテラル `"online"` → `"card"`。経緯コメントを冒頭に追加。**他の変更なし** |

### 判断根拠

- 既存 `api/customer/checkout-confirm.php:276` が Stripe online 決済を `$paymentMethod = 'card'` で記録しており、`card + gateway_name + external_payment_id` は既に POS の Stripe Terminal と takeout 側で一貫した表現
- schema 変更なし（ENUM 拡張不要）、migration なし
- 論点 C（takeout void 本体）の前提となる「payments 行が正しく残る」ことの担保だけが目的

### 反映先と反映しない先

| 対象 | 反映 |
|---|---|
| takeout online 決済で payments に `payment_method='card'` で 1 行記録される | ✅ |
| takeout 注文の orders.status 遷移 (`pending_payment` → `pending`) | ❌ **不変** |
| takeout-orders.php の `'online'` 判定 (`ONLINE_PAYMENT_REQUIRED` / `$status` 分岐) | ❌ **不変**。アプリ層フラグとしてそのまま |
| `refund-payment.php` | ❌ **不変**。Connect 非 terminal の takeout payment refund は Direct 経路フォールスルーする既存挙動のまま |
| receipt / sales-report / register-report / turnover-report | ❌ **不変**。card + gateway_name は既存集計で扱える |
| 4d-5c-ba / 4d-5c-bb-A の void | ❌ 影響なし。新規 takeout card payment は `gateway_name` 有りで `payment-void.php` では `NOT_VOIDABLE_GATEWAY` 409（論点 C 待ち）|

### 運用上の未確認事項（次スライスで扱う）

- Stripe Connect で決済された takeout payment の refund 経路 — post1 時点では `refund-payment.php:185` の `stripe_connect_terminal` のみ特別扱いで、`'stripe_connect'` は Direct 経路 (tenant の `$gwConfig['token']`) にフォールスルーする不整合があった。→ 2026-04-21 **4d-5c-bb-D で解消済**（§5.13t）。新 helper `create_stripe_destination_refund` + Pattern C routing 追加で、takeout Connect Checkout の destination charges refund を canonical に成立させ、本番 test mode で実証

### 検証 SQL

```sql
-- 本修正後、新規 takeout online 決済で payments に card 行が残ることの確認
SELECT p.id AS payment_id, p.payment_method, p.gateway_name, p.external_payment_id, p.total_amount,
       o.id AS order_id, o.status, o.order_type
  FROM payments p
  INNER JOIN orders o ON JSON_CONTAINS(p.order_ids, JSON_QUOTE(o.id))
 WHERE o.order_type = 'takeout'
   AND p.payment_method = 'card'
   AND p.gateway_name IN ('stripe', 'stripe_connect')
 ORDER BY p.paid_at DESC LIMIT 10;

-- 旧仕様の残骸が残っていないことの確認 (常に 0 件)
SELECT COUNT(*) FROM payments WHERE payment_method = 'online';
```

### ロールバック

- `/home/odah/www/eat-posla/_backup_20260421h/takeout-payment.php.bak` から 1 ファイル戻す
- DB は無変更
- 戻すと新規 takeout online 決済で再び payments 行が欠落する状態に戻る（既存の行には影響なし）

---

## 5.13s PWA Phase 4d-5c-bb-C POS cashier 経由 takeout の void 拡張 (2026-04-21)

論点 C (takeout void) の最小安全スライス。詳細は `internal/09-pwa.md` §9.31。

### 対象範囲（**混ぜるな危険**の責務境界）

- ✅ **対象**: `order_type='takeout' + status='paid' + cash + gateway_name IS NULL + is_partial=0`（POS cashier で会計された takeout の誤計上修正用途）
- ❌ **対象外（引き続き 409）**:
  - online takeout (`card + stripe/stripe_connect`) — `status='served'` 終端で status ガードに弾かれる
  - Stripe Connect 非 terminal 経路の refund — `refund-payment.php:185` は `stripe_connect_terminal` のみ特別扱い、takeout の `stripe_connect` は Direct 経路へフォールスルー（未実証のまま）
  - takeout + gateway 経由の card → 409 `NOT_VOIDABLE_GATEWAY`
  - takeout + is_partial=1 → 409 `NOT_VOIDABLE_PARTIAL`
  - takeout + status IN ('served','pending','preparing','ready','pending_payment') → 409 `NOT_VOIDABLE_ORDER_STATUS`

### 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/store/payment-void.php` | order_type ガード `'dine_in'\|'handy'` → `'dine_in'\|'handy'\|'takeout'` 追加、冒頭コメントと 409 メッセージを追随更新 |
| `public/kds/js/cashier-app.js` | canVoid コメントに takeout を追加（**実行ロジック不変**） |
| `public/kds/cashier.html` | cache buster `?v=20260421-pwa4d-5c-bb-A` → `?v=20260421-pwa4d-5c-bb-C` |

### 運用ルール

- 取消可能になるのは **POS cashier で会計された takeout** のみ（現場で現金会計 → 領収書印字 → 後からキャンセルが必要になったケースなど）
- online Stripe takeout は決済済み → 原則 refund 経路（本スライス対象外、別論点で検討）
- cash 取消は `cash_log.cash_out` 相殺で期待残高が合う（4d-5c-ba 原則継承）。お客さんへの現金返却は手渡しで別途実施
- `orders.status='paid'` / `paid_at` / `payment_method` は不変。卓状態 / レシート再印刷 / 売上除外も既存路線で自動適用

### 検証 SQL

```sql
-- void 済み takeout payments
SELECT p.id, p.payment_method, IFNULL(p.gateway_name,'(null)') AS gw,
       p.total_amount, p.void_status, p.voided_at,
       o.id AS order_id, o.order_type, o.status
  FROM payments p
  INNER JOIN orders o ON JSON_CONTAINS(p.order_ids, JSON_QUOTE(o.id))
 WHERE o.order_type = 'takeout'
   AND p.void_status = 'voided'
 ORDER BY p.voided_at DESC;

-- 対応する cash_out 相殺行
SELECT id, type, amount, note, created_at
  FROM cash_log
 WHERE note LIKE '通常会計取消 payment_void:%'
   AND created_at >= ?
 ORDER BY created_at DESC;
```

### ロールバック

- `/home/odah/www/eat-posla/_backup_20260421i/*.bak` から 3 ファイル戻す
- DB 改変なし
- 既に voided の takeout payments は DB 上に残るが、4d-5c-a のレポート除外はそのまま継続（追加被害なし）

### 次スライス候補（本回対象外）

- ~~online takeout void + Stripe Connect 非 terminal refund 経路の実証~~ → **4d-5c-bb-D で refund 経路は実証済**（§5.13t）。void 側は方針として実装せず（accounting void と refund の責務分離を堅持、online takeout は refund-payment.php が唯一の経路）
- ~~論点 B: 分割会計 (is_partial=1) の void~~ → 2026-04-21 **意味論未確定のため実装前停止、docs 固定**（§5.13x）
- 論点 D: 返金チェーン (実データ 0 件、スコープ外確定)

---

## 5.13t PWA Phase 4d-5c-bb-D Connect Checkout takeout の refund 経路整備 (2026-04-21)

Stripe Connect Checkout (`gateway_name='stripe_connect'`) 経由 takeout online 決済の refund を canonical な destination charges refund 経路で成立させる (詳細は `internal/09-pwa.md` §9.32)。**void ではなく refund で扱う方針**を堅持 (accounting void と refund の責務分離、`payment-void.php` は online/gateway takeout を引き続き `NOT_VOIDABLE_GATEWAY` 409 で弾く)。

### 対象範囲

- ✅ **実証済**: `order_type='takeout' + gateway_name='stripe_connect' + gateway_status='succeeded'` の refund。本番 test mode で payment `a90993dd...` を decision → refund 成功 (`re_3TOYeSIYcOWtrFXd2Vpiscix`)、Stripe 側 `transfer_reversal: trr_1TOYgoIYcOWtrFXdO3Y1PZX6` 発行で destination charges の Connect account transfer 引き戻しまで確定
- ⚠ **実データ 0 件のため未実証**: `stripe_connect_terminal` (Pattern B、POS Stripe Terminal)、Direct stripe (Pattern A、tenant Direct key 必須)、`stripe_terminal` (Pattern A)
- ❌ **実装していない**: cash / 手動 card / 手動 qr の refund (現状 refund-payment.php の事前ガードで拒否。cash は手渡し、手動 card/qr は POS 取引履歴の「会計取消」ボタン = 4d-5c-bb-A/C の void 経路で対応)

### 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/customer/takeout-payment.php` | **D-fix1**: `:77` / `:111` で `$gatewayStatus = $result['payment_status'] ?: 'paid'` → `'succeeded'` ハードコード正規化 (`checkout-confirm.php:77` と揃え `refund-payment.php:152` の INVALID_STATUS を解消) |
| `api/lib/payment-gateway.php` | **D-fix2 (helper)**: 新関数 `create_stripe_destination_refund($platformSecretKey, $paymentIntentId, $amountYen, $reason)` 追加。`Stripe-Account` ヘッダなし、Platform context、`reverse_transfer=true` + `refund_application_fee=true` 付与 |
| `api/store/refund-payment.php` | **D-fix2 (routing)**: Pattern 判定を 2 分岐 → **3 分岐** (Pattern B `stripe_connect_terminal` / Pattern C `stripe_connect` / Pattern A その他)<br>**D-fix3**: preflight を claim 前に移動。経路判定 + config 取得を `UPDATE refund_status='pending'` の**前**で完了させる。config 不足時は `rollBack + json_error` で claim に立てない。claim 後の失敗 exit は `refund_failed revert` パス 1 箇所のみに統一 |

### 3 Pattern 対応表

| gateway_name | Pattern | helper | Stripe-Account ヘッダ | 備考 |
|---|---|---|---|---|
| `stripe_connect_terminal` | B | `create_stripe_connect_refund` | **あり** | POS Terminal direct charges |
| `stripe_connect` | **C (新)** | **`create_stripe_destination_refund`** | **なし** | Checkout destination charges |
| その他 (`stripe` / `stripe_terminal` 等) | A | `create_stripe_refund` | なし | tenant Direct key |

### claim/revert 構造

```
BEGIN TX
  SELECT FOR UPDATE
  validate (void_status / cash / gateway_name / refund_status / gateway_status)
  preflight (route + config, 不足なら rollBack + json_error)
  claim (UPDATE refund_status='pending')
COMMIT
--- TX 外 ---
call helper per $refundPlan
on failure: revert ('pending'→'none') + json_error REFUND_FAILED  ← 唯一の claim 後 exit
on success: UPDATE refund_status='full'
```

### 運用ルール

- takeout の online 決済（Stripe Connect Checkout 経由）の誤決済を取り消したい場合は、refund-payment.php 経由で処理
- PIN 入力が必須（CP1b）。スタッフは該当店舗で出勤中である必要あり
- refund 成功後は `orders.status` は **変更しない**（online takeout は `status='served'` or `'pending'` のままで lifecycle 完了、会計フラグは payments 側で表現）
- `payment-void.php` への online/gateway takeout 入りは禁止（今後も 409 `NOT_VOIDABLE_GATEWAY` のまま）

### 5 件目 follow-up（→ 4d-5c-bb-E で解消済）

4d-5c-bb-D 時点では「Stripe `/v1/refunds` の `reason` が enum 制約 (`duplicate` / `fraudulent` / `requested_by_customer` の 3 値のみ) で自由文を渡すと REFUND_FAILED 502 になる」既知不具合として切り出していた。→ 2026-04-21 **4d-5c-bb-E で `api/lib/payment-gateway.php` 内に `_normalize_stripe_refund_reason()` 正規化 helper を追加し、3 refund helper の Stripe API 送信時に canonical enum に変換**することで解消（§5.13u）。DB `payments.refund_reason` / 監査ログ側の自由文保持は不変。

### 検証 SQL

```sql
-- 本修正後に refund 成功した takeout payments
SELECT p.id, p.payment_method, p.gateway_name, p.external_payment_id,
       p.refund_status, p.refund_id, p.refund_amount, p.refunded_at, p.refunded_by
  FROM payments p
  INNER JOIN orders o ON JSON_CONTAINS(p.order_ids, JSON_QUOTE(o.id))
 WHERE o.order_type = 'takeout'
   AND p.gateway_name = 'stripe_connect'
   AND p.refund_status = 'full'
 ORDER BY p.refunded_at DESC;
```

### ロールバック

- `/home/odah/www/eat-posla/_backup_20260421j/takeout-orders.php.bak`（URL 構築 fix 前）
- `/home/odah/www/eat-posla/_backup_20260421k/takeout-payment.php.bak`（verify context fix 前）
- `/home/odah/www/eat-posla/_backup_20260421m/{payment-gateway.php, takeout-payment.php, refund-payment.php}.bak`（D-fix 前）
- 戻すと 4d-5c-bb-D 以前の「takeout online payments が記録されない / `stripe_connect` refund が Pattern A に誤ルーティング」状態に戻るのみ（追加被害なし）

---

## 5.13u PWA Phase 4d-5c-bb-E Stripe refund reason enum 正規化 (2026-04-21)

4d-5c-bb-D の follow-up #5 を最小 slice で解消 (詳細は `internal/09-pwa.md` §9.33)。Stripe `/v1/refunds` の `reason` は enum 制約 (`duplicate` / `fraudulent` / `requested_by_customer`) のみ受けるが、`refund-payment.php` は body の自由文 `$reason` をそのまま helper 経由で Stripe API に送っていたため、自由文入力時は REFUND_FAILED 502 になっていた。

### 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/lib/payment-gateway.php` | (1) 新 private helper `_normalize_stripe_refund_reason($reason)` 追加 (enum 外は `'requested_by_customer'` に fallback、enum 値は素通し)。(2) 3 refund helper (`create_stripe_refund` / `create_stripe_connect_refund` / `create_stripe_destination_refund`) の `'reason' => $reason ?: 'requested_by_customer'` を `'reason' => _normalize_stripe_refund_reason($reason)` に差し替え (各 1 行) |

### 不変な動作

- `refund-payment.php` の API インターフェース / リクエスト body / レスポンス形式: 不変
- DB `payments.refund_reason` への自由文保存 (200 文字まで): 不変
- 監査ログ `write_audit_log` への自由文記録: 不変
- 4d-5c-bb-D の 3 Pattern routing / preflight / claim-revert: 不変
- `payment-void.php` / `takeout-orders.php` / `takeout-payment.php` / `refund-payment.php` 本体: 不変
- schema / migration: なし

### 運用ルール

- スタッフは現場で「誤会計、再調理のため」等の自由文 `reason` を入力してよい（従来どおり DB と監査ログに保存される）
- Stripe ダッシュボード上の Refund 画面の `reason` 表示は常に `requested_by_customer` になる（canonical 表記）
- 業務上の理由は DB `payments.refund_reason` と監査ログで追える

### 本番検証結果（2026-04-21 test mode、E2E）

- 新規 takeout Connect Checkout `48f09085-3a5c-4805-be98-0245e37ac933` を決済 → 自由文日本語 107 文字 reason で refund 実行
- HTTP 200 `refund_id=re_3TOaEWIYcOWtrFXd1P7Su27T`, Stripe 側 Refund.reason=`requested_by_customer`, transfer_reversal=`trr_1TOaFwIYcOWtrFXd25MlePBJ`
- POSLA DB: `refund_status='full'`、`refund_reason` に自由文 107 文字完全保持
- 既存 4d-5c-bb-D 成功証跡 (`a90993dd...` / `re_3TOYeSIYcOWtrFXd2Vpiscix`) は不変

### ロールバック

- `/home/odah/www/eat-posla/_backup_20260421n/payment-gateway.php.bak` から 1 ファイル戻す
- DB 改変なし
- 戻すと 4d-5c-bb-E 以前の「自由文 reason で REFUND_FAILED 502」状態に戻る（4d-5c-bb-D の routing は無影響）

---

## 5.13v PWA Phase 4d-5c-bb-F takeout-payment silent-fail 是正 + UI false-positive 解消 (2026-04-21)

`api/customer/takeout-payment.php` の silent-fail 設計（verify 成功後の DB 永続化失敗を catch で握りつぶして `payment_confirmed:true` を返していた）を是正し、あわせて `public/customer/js/takeout-app.js` の `.catch` 分岐で発生していた customer-facing false-positive（いかなる決済確認失敗でも "ご注文を受け付けました" 完了画面に遷移）を解消する (詳細は `internal/09-pwa.md` §9.34)。

### 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/customer/takeout-payment.php` | 決済成功後の `orders.status` UPDATE + `payments` INSERT を単一 TX に閉じ込め、失敗時は `rollBack + json_error('PAYMENT_RECORD_FAILED', 汎用メッセージ, 500)`。pi_id / gateway_name / order_id は `php_errors.log` のみに記録 |
| `public/customer/js/takeout-app.js` | success 分岐の `.catch` を「section 5 完了画面」から「赤字失敗表示 + 店舗連絡案内 + 注文番号表示 + 新規注文ボタン」に差し替え。cancel 分岐 (オレンジ) と視覚区別 |
| `public/customer/takeout.html` | cache buster `?v=20260418online` → `?v=20260421-pwa4d-5c-bb-F` |
| `scripts/generate_error_catalog.py` + 再生成物 | `PAYMENT_RECORD_FAILED (E6028)` を PINNED_NUMBERS に追加、`api/lib/error-codes.php` / `scripts/output/*` / 99-error-catalog を再生成 |

### 新 error code

| code | errorNo | http | 用途 |
|---|---|---|---|
| `PAYMENT_RECORD_FAILED` | E6028 | 500 | Stripe 側 decision は完了、POSLA 側 DB 永続化失敗時に返す。customer-facing response には pi_id 等を含めず、運営リカバリの手掛かりは `php_errors.log` の `[4d-5c-bb-F][takeout-payment] persistence_failed` 行を参照 |

### 運用ルール

- `PAYMENT_RECORD_FAILED` 検知時:
  1. `/home/odah/log/php_errors.log` を `[4d-5c-bb-F][takeout-payment] persistence_failed` で grep
  2. order_id + pi_id + エラー詳細を取得
  3. DB 確認 (`orders.status='pending_payment'` / `payments` にその pi の行がない)
  4. Stripe dashboard で PaymentIntent 成功を確認
  5. 手動で payments 行 INSERT + orders 更新、または Stripe 側 refund で返金
- お客様側には顧客画面で「決済確認に失敗しました」と店舗連絡案内 + 注文番号が表示される（cancel とは別 UI）

### 本番検証（2026-04-21 test mode）

- 正常系 E2E: 新規 takeout Connect order → decision → 完了画面 → `orders.status='pending'` + payments 行作成 （従来通り）
- 障害注入: 本番安全性優先で skip（UNIQUE 制約なし + PRIMARY KEY random 生成のため安全な INSERT 失敗手段がない）。コード/UI レベル実証で代替

### ロールバック

- `/home/odah/www/eat-posla/_backup_20260421o/{takeout-payment.php, takeout-app.js, takeout.html}.bak` から 3 ファイル戻す
- `api/lib/error-codes.php` は generator 再生成物で互換（戻さなくても既存動作に影響なし）
- DB 改変なし
- 戻すと 4d-5c-bb-F 以前の silent-fail 状態（false positive）に戻る、追加被害なし

---

## 5.13w PWA Phase 4d-5c-bb-G checkout-confirm.php verify context bug 是正 (2026-04-21)

4d-5c-bb-D で takeout-payment.php について解消した「Connect Checkout destination charges の session retrieve context 不一致」bug と完全同型の bug が `api/customer/checkout-confirm.php:70` (dine_in online のセルフレジ経路) に残っていたため、1 行修正で解消 (詳細は `internal/09-pwa.md` §9.35)。本番 test mode で修正前後の A/B 証跡取得。

### 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/customer/checkout-confirm.php` | `:70` の `verify_stripe_checkout($platformConfig['secret_key'], $stripeSessionId, $connectInfo['stripe_connect_account_id'])` から第 3 引数を削除、Platform context で retrieve (takeout bb-D と対称) |

### 本番 test mode 実証

- Phase 2a (修正前): test order `44f31d9d-...` / session `cs_test_a1z7Nb...` → checkout-confirm.php は **HTTP 502 PAYMENT_GATEWAY_ERROR (E6014)**、menu.html に「Stripe設定が見つかりません」表示、DB は orders.status='pending' 維持 / payments 0 件
- Stripe A/B retrieve: Platform context=200/paid、Connect context=404/No such session（bb-D と同じ症状確定）
- Phase 2c (修正後): 同じ session で再叩き → **HTTP 200**、payment `3bac1c88-...` (card + stripe_connect + pi_3TObj0IYcOWtrFXd1bdyWtb0 + gateway_status='succeeded') 作成、orders.status='paid'

### 既存 13 件への影響

- 本番 DB の `dine_in + gateway_name='stripe_connect'` 13 件は 2026-04-05〜04-14 に作成、それ以降の新規決済なし。過去コードの産物と確定
- 本スライスは新規 decision のみに影響し、**既存 13 件には触らない**（レコード不変）

### 不変な動作

- Direct Stripe 経路: 不変
- `checkout-session.php` (create 側) / `takeout-*.php` / `refund-payment.php` / `payment-void.php` / 4d-5c-bb-A〜F の成果: 全て不変
- menu.html / customer UI の既存 catch 失敗表示（menu.html:2511-2517）: 不変（元から正しく設計されていた）
- schema / migration: なし

### 運用ルール

- セルフレジ Stripe Checkout 決済（dine_in online）は Connect Checkout 経路で正常完了できるようになった
- ただし本番 Connect onboarding 完了テナントのみ有効 (momonoya のみ onboarding=1)
- Direct Stripe 経路 (tenant の `stripe_secret_key` 設定) は引き続き不変だが、本番 tenant で Direct 運用なし

### ロールバック

- `/home/odah/www/eat-posla/_backup_20260421p/checkout-confirm.php.bak` から 1 ファイル戻す
- DB 改変なし（test data として残置）
- 戻すと 4d-5c-bb-G 以前の 502 bug 状態に戻る、追加被害なし

### test 注文退避の恒久化（2026-04-21 運用決定）

- `orders.id='d735b9d7-97f6-42d9-a62d-7a15c1fbd32f'` は **4d-5c-bb-G 実施時に退避した test 注文として恒久 cancelled**。復旧予定なし
- memo に経緯記録: `[4d-5c-bb-G tmp cancel for test 2026-04-21]`
- momonoya (t-momonoya-001) は CLAUDE.md 上 test tenant 扱い（grandfather 不要と確定済）のため実運用影響なし
- 同一 T01 / 同一 session_token 上で `44f31d9d-...` (paid) を bb-G 成功証跡として残す方が監査性が高い

---

## 5.13x 論点 B（分割会計 void）実装前停止記録 (2026-04-21)

現物調査の結果、**コード未実装ではなく意味論未確定**のため split payment (`is_partial=1`) の void は実装前停止とした。コード変更なし、schema 変更なし、本番データ無変更。詳細は `internal/09-pwa.md` §9.36。

### 現物データ分布の要点

- `is_partial=1` active payments: **35 件**（100% cash + null gateway + matsunoya + dine_in、void 実例 0）
- 同一 order への紐付き数: 1〜5 partial payments
- 現物は **test 痕跡（overpayment / sum > order_total / cancelled 残骸）と実運用が混在**、安定 split 運用データは稀

### 維持される動作

- `api/store/payment-void.php:269-279` の `NOT_VOIDABLE_PARTIAL` 409 はそのまま維持
- 新 API / 新エラーコード / 新 UI は追加しない
- 4d-5c-ba〜G の成果は完全不変

### 再着手のために業務合意が必要な 5 項目

1. split void スコープ: 個別 payment 単位（A）vs order 全体一括（B）
2. void 後の `orders.status`: `paid` 維持 vs `pending_payment` 戻し
3. reports への金額反映: 現行維持 vs 4d-5c-c 先行統合
4. 合流会計での void 波及範囲（同一 payment が複数 order に紐付く場合）
5. **運用需要そのもの**（現物 void 実例 0 件のため、そもそもニーズが発生するかを先に確認）

### 再着手トリガー

- 業務側から split void 運用ニーズが報告され、上記 5 項目のうち 1/2/3/5 が合意された
- 4d-5c-c（sales-report の payments ベース統合）が先行完了し、論点 E が自動解消された
- 本番で split void の手動リカバリ需要が発生し、業務フローが固まった

---

## 5.13y Phase 4d-5c-c（reports payments ベース統合）— 単独実装不可判断 (2026-04-21)

現物調査の結果、`sales-report.php` / `turnover-report.php` / `register-report.php` を payments ベースへ寄せる案は **基盤不整合により単独では閉じない**。コード変更ゼロで判断のみ docs に固定する。詳細は `internal/09-pwa.md` §9.37。

### 決定的な現物事実

- `orders.status='paid'` **7,546 件中 7,430 件 (98.5%)** が `payments` 行 0 件（handy / 旧 POS flow、payments バイパス経路）
- matsunoya shibuya 2026-04 の 21 日間実測: orders ベース 90 件 ¥154,270 vs payments ベース 85 件 ¥166,530（**差 ¥12,260**）
- orders ↔ payments は 1:0 / 1:N (split 35) / N:1 (merged 24) / N:M が共存

### 維持される動作（触らない）

- `sales-report.php` / `turnover-report.php` / `register-report.php` の既存集計ロジック全て
- 4d-5c-a の `$voidExclude` NOT EXISTS 句
- `register-report.externalMethodBreakdown` / `cashLog` / `cashSales` / `expectedBalance`（既に payments/cash_log 正本で正しい）
- 4d-5c-ba〜G / 論点 B §9.36 の成果
- schema / migration なし、新 API / error code なし

### 5 つの設計問いへの回答（全て NO）

| Q | 答え | 理由 |
|---|---|---|
| Q1. totalRevenue を payments 合計へ寄せる？ | ❌ | paid orders 7,430 件が消える |
| Q2. paymentBreakdown を payments 正本にする？ | ❌（paymentBreakdown は）/ ✅（externalMethodBreakdown と cashLog は既に正本） | handy cash/card が消える |
| Q3. priceBands を payments 金額ベース？ | ❌ | split で意味論崩壊 |
| Q4. orderCount を payments ベース？ | ❌ | split 重複 / merged 欠落 |
| Q5. merged を 4d-5c-c だけで扱える？ | ❌ | 金額分配ルール未定義 |

### 本来必要な前提作業の順序

1. **4d-6**: handy paid orders の payments 記録担保（または業務合意で受容）
2. **4d-7**: merged payment の金額分配ルール定義（按分 / 均等 / 先着 etc.）
3. **4e**: `payment_order_links` 正規化テーブル導入（orders ↔ payments の N:M 表現）
4. **論点 B** §9.36 の業務ルール合意
5. 以上が揃って初めて **4d-5c-c** 実装着手可能

### 再着手トリガー

- 4d-6 が完了（handy paid に payments 記録が入るか、業務で受容決定）
- 4d-7 が合意済み（金額分配ルール）
- 4e 正規化本番反映済み
- 業務側から「部分 void の売上反映が急務」との明示要求 + 上記前提セットが揃う

### 現状は「既に正しい部分」で運用継続

- cash 系は `cash_log` で正しく記録されている（cash_sale / cash_in / cash_out / expected vs close で over/short 追跡可能）
- 外部分類（voucher / bank_transfer / accounts_receivable / point / other）は `payments.external_method_type` で正しく集計される
- ゲートウェイ決済（`gateway_name` / `external_payment_id`）は payments に残り、refund 経路（4d-5c-bb-D 3 Pattern routing）で処理可能

つまり **会計個別記録レベルでは payments/cash_log で整合が取れており、orders ベースの集計レポートと並行で運用できる**。reports の金額統合は前提作業完了後に実施。

---

## 5.13z Phase 4d-6 paid write-path 安全性検証（止血不要判定）(2026-04-21)

§5.13y で「4d-5c-c 着手の前提」として切り出した **4d-6**（paid orders ↔ payments の 1:0 解消、handy paid の payments 記録担保）を現物で確定。結論は **「現行 live 経路は clean、7,430 件は demo seeder 由来の legacy artifact、コード変更不要で停止」**。詳細は `internal/09-pwa.md` §9.38。

### 決定的な現物事実

- live write-path で paid を書けるのは `api/store/process-payment.php` のみ、同一 TX で payments INSERT を担保
- `api/kds/close-table.php` は deprecated（2026-04-02）+ 唯一の caller `accounting-renderer.js` が**どの HTML にも `<script>` 参照なし**の dead code
- `api/kds/update-status.php` は `'paid'` を valid 値に含むが KDS UI に `data-status="paid"` が存在せず UI 経由で送られない
- `api/store/handy-order.php` は `status='pending'` でしか INSERT しない、PATCH も paid/cancelled を拒否
- `api/kds/update-item-status.php` の item→order 昇格は preparing/ready/served のみ、paid にならない

### 7,430 件 payments-less paid orders の発生元

| tenant | 月 | 件数 |
|---|---|---|
| t-torimaru-001（Hiro test tenant） | 2026-03 | 5,671 |
| t-torimaru-001 | 2026-04 | 1,733 |
| t-matsunoya-001 | 2026-03 | 26 |

`scripts/p1-27-generate-torimaru-sample-orders.php` が `INSERT INTO orders ... VALUES ('paid','handy',...)` で直接投入（process-payment.php バイパス）。torimaru 2026-04 日次分布は 230〜250 件/日で均一、2026-04-08 急落後ゼロ、実運用形状ではない。

### 維持される動作（触らない）

- 全 PHP / JS / HTML（コード変更ゼロ）
- schema / migration なし
- 本番 7,430 件（read-only 調査のみ）
- 4d-5c-ba〜G / §5.13x / §5.13y の成果完全不変

### 本回はスコープ外（別論点として切り出し）

- `close-table.php` の物理削除 or 404 化（dead endpoint 防御）
- `update-status.php` の `'paid'` reject 化（UI 未使用値の API 側絞り込み）
- `scripts/p1-27-generate-torimaru-sample-orders.php` の seeder 書き換え（demo 整形）
- 7,430 件の backfill / 削除 / reports フィルタ（demo tenant 分離運用）

### §5.13y 前提ツリーへの反映

§5.13y の「本来必要な前提作業」1. **4d-6** は本節で satisfied。ただし **2. 4d-7 merged payment 金額分配**、**3. 4e payment_order_links 正規化**、**4. 論点 B §5.13x** は未消化のため、**4d-5c-c の着手可否は依然 No**。

---

## 5.13aa Phase 4d-7 merged payment 金額分配ルール（按分不要判定）(2026-04-21)

§5.13y で「4d-5c-c 着手の前提」として切り出した **4d-7** を現物で確定。結論は **「按分ルールは problem statement 自体が ill-posed / 現行実装で全 consumer が既に整合 / コード変更不要で停止」**。詳細は `internal/09-pwa.md` §9.39。

### 決定的な現物事実

- `JSON_LENGTH(order_ids) >= 2` の payments を `is_partial` で分類すると **merged 13 件 / partial_multi 10 件 / 合計 23 payments / 82 orders**
- **merged（非 partial）13 件は payment.total vs SUM(orders.total) 突合で全件 diff=0（100% 整合）**
- `merged_table_ids` を使った「合流会計 UI」の live 実績は **0 件**（UI 存在するが本番で一度も使われていない）
- §5.13y で「merged payment 乖離例」と記載した `311a3585 ¥900 × 3 orders ¥4,580` は **`is_partial=1` の partial_multi（単一 table 内分割会計）であって merged ではない**。本件は論点 B §5.13x の範疇

### 5 切り分けルール判定

| ルール | 判定 |
|---|---|
| A 按分しない（reports は order 正本維持） | ✅ 既に成立 |
| B 比例按分 | ❌ 不要 |
| C 均等按分 | ❌ 不要 |
| D UI 起点の明示配分 | ❌ 不要 |
| E 全 order 一体 void/refund | ✅ 既に成立（payment-void.php / refund-payment.php が payment 単位で atomic）|

### 4e 正規化の必要性再評価

4e `payment_order_links` 正規化は単独では何も解決しない。先に論点 B §5.13x（partial_multi の reports 集計ルール）の業務合意が必要で、その合意内容次第で 4e 自体が不要になる可能性が大きい。

### §5.13y 前提ツリー更新

- 4d-6 ✅ 完了（§5.13z / §9.38）
- 4d-7 ✅ 完了（§5.13aa / §9.39、按分不要確定）
- 論点 B §5.13x ❌ 未決
- 4e 正規化 ❓ §5.13x 合意内容次第で再評価
- **4d-5c-c 着手可否は依然 No**、残る唯一の必須前提は §5.13x 業務ルール合意

### 維持される動作（触らない）

- 全 PHP / JS / HTML（コード変更ゼロ）
- schema / migration なし
- 本番 23 payments / 82 orders（read-only SQL 6 本で確認のみ）
- §9.37.3 の「merged は乖離する」記述は履歴保全のため直接書き換えず、§9.39.3 に訂正記録を固定
- 4d-5c-ba〜G / §5.13x / §5.13y / §5.13z の成果完全不変

---

## 5.13ab 論点 B partial_multi 業務ルール合意 — A 案（void 不可維持）で固定 (2026-04-21)

§5.13x（論点 B）で意味論未確定のまま停止していた split void のうち、§5.13aa で 4d-7 を閉じた時点で残った唯一の必須前提「partial_multi（`is_partial=1` ∧ `JSON_LENGTH(order_ids)>=2`）の業務ルール」を現物 10 件で確定し、**A 案（引き続き void 不可、`NOT_VOIDABLE_PARTIAL` 409 を業務ルール確定として維持）** を正式合意として固定する。詳細は `internal/09-pwa.md` §9.40。

### 決定的な現物事実

- partial_multi 10 件は全て **`t-matsunoya-001 / s-shibuya-001 / tbl-shib-01` 単一 test 環境**に集中
- 100% `cash` + `null gateway`、**void 実例ゼロ / refund 実例ゼロ**
- 3 パターン分類:
  - **α 完全分割（7 件）**: 5 人割り勘 5 件 ¥4,400 + 2 人割り勘 2 件 ¥3,700、全 orders.status=paid で**既に終端**
  - **β cancelled 残骸（3 件）**: 全 orders.status=cancelled、partial sum が orders SUM と整合しない → 本論点 B の範疇外（別論点: order cancel 時の payments クリーンアップ）
- 全 consumer（`process-payment.php` / `payment-void.php` / `refund-payment.php` / `receipt.php` / `payments-journal.php` / `cashier-app.js` / sales/register/turnover-report）で**「partial は触れない」が一貫表現済**
- UI: `canVoid = !isPartial` / `canRefund = gateway && method!=='cash'` / `*` 印表示で operator に視覚的伝達

### A/B/C/D/E 判定結果

| 選択肢 | 判定 |
|---|---|
| **A. partial_multi 引き続き void 不可** | **✅ 採用** |
| B. order 全体やり直し（全 partial 一括 void） | ❌ 棄却（需要 0、§9.36.5 未決 4 点を解く overhead が重すぎる）|
| C. 1 payment 単位 void 可 | ❌ 棄却（§5.13x で単独実装不可確定）|
| D. システム非対応・運営手動 | ❌ 実質 A と同義 |
| E. 4e payment_order_links 正規化先行 | ❌ 棄却（A 採用下で導入動機なし）|

### 4e payment_order_links の最終判定

本 A 案合意により、**4e 正規化は不要確定**。merged は JSON_CONTAINS で整合済、partial_multi は void 不可で閉じる、single-order split 25 件も同じ A 案延長でカバー可能、関係テーブルを要求する consumer がない。

### §5.13x / §5.13y 前提ツリー最終ステータス

- 4d-6 ✅ §5.13z
- 4d-7 ✅ §5.13aa
- 論点 B §5.13ab ✅（本節、A 案固定）
- 4e 正規化 ❌ 不要確定
- **4d-5c-c 着手可否は依然 No**（§5.13y 本丸論点の revenue 差 / priceBands 意味論 / itemRanking は本合意では解消しない、残るのは業務側明示需要のみ）

### 再着手トリガー（本 A 案合意を覆す条件）

以下のいずれか 2 つ以上が同時成立した場合のみ B/C 方向の再設計に進める:

1. 本番テナント（test ではない）から partial void 運用ニーズが具体的に報告される
2. 「全員分 refund → 再会計」で回避できないケースが運用で発生する
3. 4d-5c-c（reports payments ベース統合）が先行完了し partial void 後の金額表現が自動定義される

### 維持される動作（触らない）

- `payment-void.php:267-277` の `NOT_VOIDABLE_PARTIAL` 409 は**業務ルール確定として維持**
- 全 PHP / JS / HTML / schema / migration / 本番 DB（read-only SQL `/tmp/b1_detail.sql` のみ）
- §5.13x 本文は履歴保全のためそのまま、§5.13ab が subset の最終判定を追加する構造
- 4d-5c-ba〜G / §5.13x / §5.13y / §5.13z / §5.13aa の成果完全不変

---

## 5.14 ポーリング間隔一覧

各画面のポーリング（自動更新）間隔のまとめです。

| 画面 | ポーリング対象 | 間隔 |
|------|-------------|------|
| KDS | 注文データ | 3秒 |
| KDS（オフライン時） | 注文データ | 30秒 |
| KDS | スタッフ呼び出しアラート | 5秒 |
| KDS | AIキッチンダッシュボード | 60秒 |
| フロアマップ | テーブルステータス | 5秒 |
| ハンディPOS | スタッフ呼び出しアラート | 5秒 |
| ハンディPOS | メニューバージョンチェック | 3分 |
| セルフオーダー | 注文ステータス追跡 | 5秒 |
| セルフオーダー | スタッフ呼び出し応答チェック | 5秒 |
| テイクアウト管理 | 注文一覧 | 30秒 |
| POSレジ | 注文・テーブルデータ | 3秒 |
| POSレジ | 売上サマリー | 5秒 |

---

## 5.14b 軽量リビジョン確認 (RevisionGate)

POSLA は **WebSocket / SSE を使わず**、既存のポーリング方式を維持したまま、変更が無いときに重い API を叩かない仕組みを導入しています。これによりポーリング間隔自体は変更せず（KDS 注文 3 秒 / 呼び出しアラート 5 秒のまま）、サーバ DB 負荷だけを軽量化しています。

### 5.14b.1 目的

KDS / 呼び出し系は「変化が無い時間」が大半を占めるため、毎回重い JOIN クエリを叩くと無駄な DB アクセスが発生します。RevisionGate は「直近の変更時刻」を 1 行の MAX クエリで取得し、前回値と同じであれば本体 API の呼び出しをスキップします。

### 5.14b.2 構成

| 要素 | ファイル | 役割 |
|---|---|---|
| 軽量リビジョン API | `api/store/revisions.php` | `?store_id=X&channels=kds_orders,call_alerts` で各チャネルの最新更新時刻を返す。1 リクエストで複数チャネル一括取得可 |
| クライアントゲート | `public/shared/js/revision-gate.js` | `RevisionGate.shouldFetch(channel, storeId)` → `Promise<boolean>`。同フレーム内の並行呼び出しは 1 リクエストにバッチ統合 |
| KDS 注文ポーラー | `public/kds/js/polling-data-source.js` | `kds_orders` チャネルでゲート判定 + `MAX_GAP_MS=30000` で必ず 30 秒に 1 回は full fetch |
| KDS 呼び出しポーラー | `public/kds/js/kds-alert.js` | `call_alerts` チャネルでゲート判定。アラート表示中（`_lastAlertIds.length > 0`）はゲート bypass で elapsed_seconds 更新を担保 |
| ハンディ呼び出しポーラー | `public/handy/js/handy-alert.js` | 同上 |

### 5.14b.3 対象チャネル (Phase 1)

| channel | 集計元 | 派生方法 |
|---|---|---|
| `kds_orders` | `orders.updated_at`, `order_items.updated_at` (当日営業日範囲) | `MAX()` の最大値 |
| `call_alerts` | `call_alerts.created_at`, `call_alerts.acknowledged_at` | `MAX(GREATEST(created_at, COALESCE(acknowledged_at, created_at)))` |

DB 変更は無し。既存の `updated_at` / `created_at` / `acknowledged_at` カラムから派生しています。

### 5.14b.4 失敗時の挙動 (フェイルオープン)

`revisions.php` が 4xx/5xx を返した場合、ネットワークエラー、JSON パース失敗、`null` レスポンスのいずれの場合も `RevisionGate.shouldFetch()` は `true` を返します。つまり **ゲートが死んでいる間は従来通り full fetch で動作** します。これは「軽量化のためのギミックが既存の業務動作を壊さない」ことを最優先にした設計です。

### 5.14b.5 必ず実行されるパス (絶対 skip しない)

以下は revision が変わらない場合でも実行されます。RevisionGate を入れる前と機能が変わらないことを担保するためです。

- `polling-data-source.js` の **30 秒に 1 回の強制 full fetch** (`MAX_GAP_MS = 30000`)
  - 目的: コース自動発火 (`api/kds/orders.php` 内 `check_course_auto_fire()`) / 経過時間警告 / 低評価アラート / KDS ステーション設定変更 を 30 秒以内に確実に反映させる
- `kds-alert.js` / `handy-alert.js` で **アラート表示中は毎回 full fetch**
  - 目的: 各アラートの `elapsed_seconds`（「5秒前」「1分前」表示）はサーバー算出のため、ゲート skip すると表示が固まる
- `acknowledgeAlert` 直後 / `forceRefresh()` 呼び出し時は `RevisionGate.invalidate()` してから即時 fetch
  - 目的: 操作直後の即時反映を担保

### 5.14b.6 調査・トラブル対応時の注意

- ブラウザの DevTools → Network ログで **`orders.php` がほぼ呼ばれていないように見える** ことがありますが、これは異常ではありません。`revisions.php` が中心になり、変更があったときだけ `orders.php` がスキップされず通る設計です
- 30 秒以内には必ず `orders.php` が叩かれるはずなので、5 分以上 `orders.php` がゼロなら逆に異常 (RevisionGate が常に `false` を返している / クライアント側が壊れている可能性)
- `revisions.php` が高頻度で 5xx を返している場合は、DB が遅い / `idx_order_updated` 等のインデックスが効いていない / 当日営業日範囲が異常に大きい などを疑う
- API 単体性能チェック: `curl https://eat.posla.jp/api/store/revisions.php?store_id=X&channels=kds_orders` (要認証 cookie) のレスポンス時間が安定して 50ms 以上かかるなら見直し対象

### 5.14b.7 Phase 2 候補 (未実装)

以下は Phase 2 以降で同じパターンを適用する候補です。実装時は同様に「フェイルオープン + MAX_GAP 強制 fetch + 必要時 invalidate」のセットを守る前提です。

- POS レジの `kds/orders.php` ポーリング (`view=cashier`)
- フロアマップ / ハンディの `tables-status.php` (新チャネル `table_status`)
- セルフオーダーの `customer/order-status.php` (セッション単位の新チャネル)
- テイクアウト管理の `takeout-orders.php`
- POS レジの売上サマリー (`sales-summary`)

::: warning Phase 2 適用時の注意
時間経過で動くサーバ側ロジック（コース自動発火など）が他にある場合は、必ず該当 API の中身を確認してから RevisionGate を被せてください。RevisionGate の本来意図は「DB 負荷削減」であり、「ポーリング呼び出しを減らすこと自体」が目的ではない点に注意。
:::

---

## 5.15 F-DA1 デバイス自動ログイン（ワンタイムトークン）

### 5.15.1 概要

2026-04-18 追加機能。新しい KDS / レジ / ハンディタブレットを設置する際、手動でユーザー名・パスワードを入力せず、**ワンタイムトークン URL** で自動ログインできる仕組み。

### 5.15.2 実装

- トークン発行 API: `api/store/device-registration-token.php`（manager 以上、`require_role`）
- セットアップ HTML: `public/admin/device-setup.html`（トークン URL の遷移先、ページ内 JS が自動 POST）
- 登録 API: `api/auth/device-register.php`（POST、token を検証して device ユーザー新規作成 + セッション確立）
- DB: `device_registration_tokens` テーブル（`sql/migration-fda1-device-tokens.sql`）
- UI: dashboard.html の **【トークン発行】** ボタン（`btn-token-device`、L303）

### 5.15.3 セキュリティ設計

- ワンタイムトークン（`is_used=1` で再利用不可、保存はハッシュ `sha256(plainToken)`）
- 有効期限（発行後 `expires_hours` 時間、デフォルト数時間）
- レートリミット: `check_rate_limit('device_register:' . IP, 3, 3600)` — 同一 IP から 1 時間 3 回
- `SELECT ... FOR UPDATE` でレース条件防止
- tenant / store の `is_active=1` チェック
- device ユーザーのユーザー名は `device_` + UUID 先頭 12 文字、パスワードはランダム 32 桁を `password_hash()` で保存（平文は即破棄）
- 成功時に `audit_log` に `event='device:register'` 記録

### 5.15.4 使用フロー

1. 管理者が dashboard → スタッフ管理 → **【トークン発行】** をクリックし、表示名と `visible_tools` を入力
2. `api/store/device-registration-token.php` がプレーントークンを生成し、`setupUrl`（例: `https://eat.posla.jp/public/admin/device-setup.html?token=xxxx`）を返す
3. URL を新端末の Chrome で開く（QR 化して読み取り可）
4. `device-setup.html` の IIFE が `POST /api/auth/device-register.php` を自動実行
5. サーバーがトークン検証 → device ロールのユーザー新規作成 → user_stores 紐付け → セッション確立
6. トークンは `is_used=1` フラグが立ち、以降は無効
7. レスポンスの `stores[0].userVisibleTools` の優先度（`handy` > `register` > `kds`）で 3 秒後に対応画面へ自動遷移

## 5.16 F-QR1 サブセッション

### 概要

2026-04-18 追加。1 つの物理テーブルに複数グループが着席して別会計する運用（相席）に対応。

### 実装

- API: `api/store/sub-sessions.php`（GET/POST）
- DB: `table_sub_sessions` テーブル、`orders.sub_session_id` 外部キー
- SQL: `sql/migration-fqr1-sub-sessions.sql`
- UI: ハンディの **【📟 個別QR】** ボタン、POSレジのサブセッション別会計

## 5.17 CR1 返金処理

### 概要

2026-04-18 追加。会計済み注文の返金（全額・部分）を Stripe Refund API 経由で実行。

### 実装

- API: `api/store/refund-payment.php`（POST、manager 以上）
- SQL: `sql/migration-cr1-refund.sql`（`orders.refund_status`, `refunded_at`, `refund_amount` カラム）
- UI: POSレジ画面の【📋 取引履歴】→【返金】ボタン
- ロジック:
  - Stripe カード決済 → Stripe Refund API で自動処理、3〜5 営業日でお客様カードに返金
  - 現金 → 記録のみ、物理返却はスタッフ対応
  - QR 決済 → PayPay/d払い管理画面で別途返金、POSLA 側は記録のみ
- 権限: `require_role('manager')`、`WHERE id = ? AND store_id = ?` でテナント境界二重チェック

## 5.18 F-MV1 モニターイベント（POSLA 運営向け）

### 概要

POSLA 本番運用で発生する異常・警告をリアルタイムで記録・通知する仕組み。

### 実装

- API: `api/posla/monitor-events.php`（GET/PATCH、require_auth）
- DB: `monitor_events` テーブル
- ソース: `api/cron/monitor-health.php`（5 分 cron）、`api/monitor/ping.php`（外部 uptime）
- 通知: Slack Webhook + POSLA運営窓口（support@posla.jp）

詳細は 07-monitor.md 参照。

---

## 5.19 トラブルシューティング表（運用作業中によくある問題）

| 症状 | 原因 | 対処 |
|---|---|---|
| `Permission denied (publickey)` | 鍵パーミッション 644 | `chmod 600 id_ecdsa.pem` |
| `Enter passphrase for key` で止まる | expect が拾えていない | `expect "Enter passphrase"` の文字列が一致しているか確認（バージョンで微妙に違う） |
| `mysqldump: Got error: 1045` | DB パスワード誤り | `-p` の直後にスペースなしで連結（例: `-p"$POSLA_DB_PASS"`）。env-sourced 方式を推奨 |
| `2>&1: command not found` | csh で bash 構文を使った | `bash -c "..."` でラップする |
| デプロイ後 500 エラー | DB マイグレーション未適用 | `php_errors.log` を見て新カラム参照エラーを確認、SQL を流す |
| デプロイしたのに古いまま | キャッシュバスター未バンプ | HTML の `?v=` を更新 |
| cron が動いていない | パスが相対 / `php` のフルパス漏れ | `/usr/local/bin/php` 絶対パスに修正 |
| `monitor_last_heartbeat` が古い | monitor-health cron 停止 | crontab 確認 + `cron-monitor-health.log` 確認 |
| `error_log` テーブルが急膨張 | 攻撃 or バグ多発 | 上位 IP / errorNo を集計、IP ブロック or 修正 |
| Slack 通知が来ない | `slack_webhook_url` 未設定 | `posla_settings` で確認、curl で疎通テスト |

---

## 5.20 FAQ 30 件

### Q1. 本番 URL は `eat.posla.jp` と `eat-posla.jp` のどちら？
A. **`eat.posla.jp`**（ハイフン無し）。ハイフン付きは別ドメインなので絶対に使わないでください。

### Q2. SSH ログインで Permission denied が出る
A. `chmod 600 id_ecdsa.pem` を実行。`644` だと SSH 側で拒否されます。

### Q3. csh で `2>&1` が動かない
A. csh は bash 構文をサポートしません。`bash -c "command 2>&1"` でラップしてください。

### Q4. デプロイのバックアップディレクトリ命名規則は？
A. `_backup_YYYYMMDD/`。同日 2 回目以降は `_backup_20260419b/`, `_backup_20260419c/` のように添字を付けます。

### Q5. バックアップは全量取るべき？
A. いいえ、**そのデプロイで上書きされるファイルだけ** を元のディレクトリ構造のまま `_backup_xxx/` に置きます。全量は `mysqldump`（DB）と週次 rsync（画像）で別途。

### Q6. DB マイグレーションを先にやる？コードを先にやる？
A. **DB を先**。コードを先にデプロイすると、新カラム参照で 500 エラーになります。逆方向（DB だけ先行）は旧コードが新カラムを無視するので安全です。

### Q7. パーミッション 604 と 644 の違いは？
A. 604 = `rw----r--`（その他は read のみ）、644 = `rw-r--r--`（グループも read 可）。Sakura では Apache が「その他」扱いなので 604 で OK、グループに権限を渡す必要なし。

### Q8. cron で `%` がエスケープされない
A. crontab 内で `%` は改行扱い。`date +\%Y\%m\%d` のようにバックスラッシュでエスケープ。

### Q9. `php_errors.log` がどこにあるか分からない
A. `/home/odah/log/php_errors.log`。Sakura のコントロールパネル → 「ログ表示」からも確認できます。

### Q10. mysql コマンドラインのパスワードを履歴に残したくない
A. `~/.my.cnf` にパスワードを書いて `chmod 600`。コマンドラインからは `-p` を省略できます。

### Q11. cron が止まっているか確認したい
A. `posla_settings.monitor_last_heartbeat` が 5 分以内に更新されていれば monitor-health は動作中。それ以外の cron は `cron-*.log` を `tail` で確認。

### Q12. error_log テーブルが急に膨れた
A. 攻撃 or アプリバグの多発。`SELECT error_no, COUNT(*) FROM error_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) GROUP BY error_no ORDER BY 2 DESC LIMIT 10` で原因 errorNo 特定。

### Q13. monitor_events に同じイベントが連発する
A. monitor-health.php の重複防止ロジック（直近 30 分の同タイトルチェック）が効いていない可能性。詳細は `api/cron/monitor-health.php` の `_logEvent` 周辺を確認。

### Q14. Stripe Webhook が失敗していると Slack 通知される
A. monitor-health が `subscription_events` の `error_message` を 5 分間隔で集計、1 件以上あれば warn として記録 + Slack 通知。詳細は `subscription_events` テーブル参照。

### Q15. デプロイ後にキャッシュで古い JS が読まれる
A. `<script src="...?v=YYYYMMDD">` の `?v=` をバンプ。同じ JS を読む全 HTML をバンプし忘れないこと（`grep -r "filename.js" public/`）。

### Q16. テナントを緊急停止したい
A. `UPDATE tenants SET is_active = 0 WHERE slug = ?`。次回操作時に全ユーザーがログイン画面にリダイレクトされます。

### Q17. 古い user_sessions / error_log を削除したい
A. 月次 cron で `DELETE FROM user_sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)` / `DELETE FROM error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)`。

### Q18. PHP のバージョンを確認したい
A. SSH 接続後 `php -v`。Sakura 側のコントロールパネルで PHP バージョン切替が可能。

### Q19. OPcache を無効化してデプロイしたい
A. Sakura の管理画面で OPcache を一時無効化、または `opcache_reset()` を呼ぶスクリプトを実行。通常はファイル更新で自動再読込される。

### Q20. ログファイルが大きすぎて開けない
A. `tail -1000 /home/odah/log/php_errors.log` で末尾だけ取得。または `awk` で日付フィルタ。

### Q21. テスト DB を作りたい
A. Sakura の DB 管理画面で別 DB を作成し、`schema.sql` + 全 migration を流す。本番に影響を与えずに mysqldump リストアテストもできる。

### Q22. expect スクリプトでパスフレーズが一致しない
A. expect の `spawn` 直後の出力を `interact` で確認。バージョンによって `Enter passphrase for key '...':` の表記が異なるので、ワイルドカード `Enter passphrase*` を使う。

### Q23. デプロイ後に動作確認すべき URL は？
A. `https://eat.posla.jp/api/monitor/ping.php`（200 + `{"ok":true}`）+ 該当変更画面の表示。

### Q24. 監査ログ（audit_log）はどれくらい保持？
A. 1 年が目安。`DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)` を月次 cron に。

### Q25. Stripe Connect の Webhook イベントが届かない
A. Stripe Dashboard → Developers → Webhooks で配信ステータス確認。`stripe_webhook_secret` の不一致 or サーバー時刻ズレ（5 分以上）が主原因。

### Q26. メールが届かない（予約リマインダー等）
A. Sakura の `mail()` / `mb_send_mail()` は標準で動くが、`reservation_notifications_log` の `status` を確認。`failed` なら詳細メッセージあり。

### Q27. POSLA 運用秘密（パスフレーズ・DB パス）を共有したい
A. **Slack/メールには書かない**。1Password などのシークレットマネージャー or 直接対面で口頭。

### Q28. SQL マイグレーションの dry-run は？
A. テスト DB で実行 → エラーがなければ本番。MySQL は DDL のロールバックを正式サポートしないため、本番では `mysqldump` バックアップが事実上のロールバック手段。

### Q29. monitor-health.php を手動で動かしたい
A. SSH 後 `php /home/odah/www/eat-posla/api/cron/monitor-health.php`。CLI 実行なら認証不要、結果は標準出力に JSON。

### Q30. 緊急で全テナントを止めたい（重大脆弱性発覚時）
A. `.htaccess` を差し替えて全 API を 503 に。または `index.html` をメンテナンス中ページに置換。詳細は **6.10.2** 緊急メンテナンス手順を参照。

---

## X.X 更新履歴
- **2026-04-21 (論点 B partial_multi 業務ルール合意)**: §5.13ab 新設。§5.13x で停止していた split void のうち、§5.13aa で 4d-7 を閉じた段階で残った唯一の必須前提「partial_multi 業務ルール」を現物 10 件で確定、**A 案（void 不可維持、`NOT_VOIDABLE_PARTIAL` 409 を業務ルール側からも fix）** を正式合意として固定。全 10 件が `t-matsunoya-001 / s-shibuya-001 / tbl-shib-01` 単一 test 環境に集中、100% cash+null gateway、void/refund 実例ゼロ、α 完全分割 7 件（orders.status=paid 終端）+ β cancelled 残骸 3 件（別論点）。全 consumer が「partial は触れない」を既に一貫表現、UI ボタン非表示 + 409/400 で弾き済。B/C 棄却（需要 0 + 実装 overhead / §5.13x 単独実装不可確定）、D 実質 A と同義、E 棄却（導入動機なし）。**4e payment_order_links 正規化は本合意で不要確定**（merged JSON_CONTAINS + partial_multi void 不可 + single-order split 同論理延長で関係テーブル要求場面なし）。§5.13y 前提ツリー最終ステータス: 4d-6 ✅ / 4d-7 ✅ / 論点 B §5.13ab ✅ / 4e ❌ 不要、**4d-5c-c 着手可否は依然 No**（§5.13y 本丸論点の revenue 差 / priceBands / itemRanking は本合意では解消しない、残るのは業務明示需要のみ）。再着手トリガー: 本番テナント partial void 需要 + refund 再会計で回避不能ケース、の 2 つ以上同時成立時のみ解除。コード変更ゼロ / schema 変更ゼロ / 本番データ無変更 / §5.13x 本文は履歴保全のため維持、§5.13ab が subset 最終判定を追加する構造。4d-5c-ba〜G と §5.13x〜§5.13aa の成果完全不変。詳細は `internal/09-pwa.md` §9.40。
- **2026-04-21 (PWA Phase 4d-7)**: §5.13aa 新設。§5.13y で「4d-5c-c 着手の前提」として切り出した 4d-7（merged payment 金額分配ルール）を現物で確定。`JSON_LENGTH(order_ids) >= 2` を `is_partial` で分類し、merged 13 件 / partial_multi 10 件 / 合計 23 payments / 82 orders と整理。**merged 13 件は全件 payment.total = SUM(orders.total) で diff=0（100% 整合）**、`merged_table_ids` の live 使用は 0 件。§5.13y で例示した `311a3585` は partial_multi（is_partial=1 の単一 table 内分割会計）誤分類だったと訂正、論点 B §5.13x の範疇。全 consumer（process-payment.php write / payment-void / refund-payment / receipt / payments-journal / sales-report / register-report / turnover-report）が merged を既に整合的に処理済。5 切り分けルール判定: A 按分しない✅ / B 比例❌ / C 均等❌ / D UI 明示❌ / E 全 order 一体✅、いずれも **4d-7「按分ルール」自体が ill-posed** と確定、コード変更ゼロで停止。4e payment_order_links 正規化も先に §5.13x 業務合意が必要、その合意内容次第で 4e 自体が不要になる可能性大と再評価。§5.13y 前提ツリー更新: 4d-6 ✅ / 4d-7 ✅ / 論点 B §5.13x ❌ 未決 / 4e ❓ 再評価、**4d-5c-c 着手可否は依然 No**、残る唯一の必須前提は §5.13x 業務ルール合意。コード変更ゼロ / schema 変更ゼロ / 本番データ無変更（read-only SQL 6 本）/ 4d-5c-ba〜G と §5.13x・§5.13y・§5.13z の成果完全不変。詳細は `internal/09-pwa.md` §9.39。
- **2026-04-21 (PWA Phase 4d-6)**: §5.13z 新設。§5.13y で「4d-5c-c 着手の前提」として切り出した 4d-6 を現物で確定。live write-path で paid を書けるのは `process-payment.php` のみ（payments INSERT を同一 TX で担保）、`close-table.php` は deprecated + dead code（`accounting-renderer.js` どの HTML にも `<script>` 参照なし）、`update-status.php` は `'paid'` を受け付けるが KDS UI 未使用、`handy-order.php` は `pending` のみ投入。7,430 件 payments-less paid orders は 99.65% が `t-torimaru-001`（demo tenant）で `scripts/p1-27-generate-torimaru-sample-orders.php` の直接 INSERT 由来。**現行 live 経路は clean / 7,430 は demo artifact / コード変更不要で停止**。§5.13y の前提 1（4d-6）は satisfied、ただし 4d-7 / 4e / §5.13x が未消化のため 4d-5c-c 着手は依然 No。コード変更ゼロ / schema 変更ゼロ / 本番データ無変更 / 4d-5c-ba〜G と §5.13x・§5.13y の成果完全不変。詳細は `internal/09-pwa.md` §9.38。
- **2026-04-20 (PWA Phase 2b)**: §5.13d 新設。Web Push 実送信の運用節。VAPID 鍵生成 (`scripts/generate-vapid-keys.php`)、純 PHP 暗号化/署名/送信ライブラリ (`api/lib/web-push-{encrypt,vapid,sender}.php`)、テスト送信 API (`api/push/test.php`)、`api/lib/push.php` の no-op→実送信置換、`call-alerts.php` / `satisfaction-rating.php` のトリガー接続。秘密鍵 (`web_push_vapid_private_pem`) の `_public_setting_keys()` 追加禁止と社内パスワード金庫保管を運用ルール化。本番デプロイ手順書は `internal/09-pwa.md` §9.14.6 を正本とし、本節は要約。
- **2026-04-20 (PWA Phase 4 緊急レジモード)**: §5.13f 新設。通信障害時にも会計事実を失わない IndexedDB + サーバー台帳 (`emergency_payments`) + 同期 API 仕様を文書化。カード情報は一切保存しない三重防御。`payments` への自動変換は Phase 4c 以降で予定。
- **2026-04-20 (PWA Phase 4c-2 payments 転記)**: §5.13i 新設。confirmed の緊急会計を明示操作で payments に 1 件ずつ転記する API + UI を追加。TX + FOR UPDATE + rowCount 検証で二重売上完全防止。paid_at は client_created_at 優先。payment_method は cash/card/qr に mapping + gateway_name で元値保持。日次確認 SQL / 409 運用対応表 / ロールバック手順を文書化。**process-payment.php / payments schema / orders.status / order_items は一切変更なし**。
- **2026-04-20 (PWA Phase 4c-1 管理者解決フロー)**: §5.13h 新設。新規 migration で resolution 5 カラム追加、新規 API `emergency-payment-resolution.php` で manager/owner が「有効確認 / 重複扱い / 無効扱い / 保留」を記録。TX + FOR UPDATE、同値 idempotent、終結からの異値遷移は 409。ledger API 拡張 + 42S22 フォールバック。**payments 転記 / 売上反映は Phase 4c-2 以降**。
- **2026-04-20 (PWA Phase 4b-1 / 4b-2)**: §5.13g 新設。子テーブル `emergency_payment_orders` (UNIQUE 制約で order_id 二重登録を DB レベル封じる)。dashboard.html 「レポート > 緊急会計台帳」タブ追加 (manager/owner 限定の読み取り専用)。新規 API `emergency-payment-ledger.php` で期間 + status フィルタ + KPI。操作ボタン一切なし。payments 転記・承認・売上反映は Phase 4c 以降に明示分離。
- **2026-04-20 (PWA Phase 3 音声経路レビュー指摘対応)**: KDS の状態変更関数 (handleAction/handleItemAction/advancePhase) が offline/stale で `Promise.resolve({ok:false})` を返していたため、voice-commander が「成功」と誤発話する問題を修正。戻り値を `Promise.reject` に変更 + voice-commander 側で `json.ok === true` のみ成功扱いの二重防御。voice-commander のスタッフ呼び出し POST と品切れ PATCH にも `_voiceIsOfflineOrStale` ガード追加。index.html の click 3 箇所に `.catch(function(){})` 追加で unhandled rejection 回避。詳細は §9.15.2 + 更新履歴 (13)。
- **2026-04-20 (PWA Phase 3 レビュー指摘対応)**: §5.13e の「オフライン中のボタン挙動」表を関数レベルガード仕様に更新。KDS 状態変更 (handleAction/handleItemAction/advancePhase) を関数冒頭で止めるよう修正、cashier `_fetchOrders({failClosed:true})` 追加で会計前リフレッシュ失敗時の会計中断、ハンディ状態変更 7 箇所 (予約/着席/テーブル開放/メモ/着席取消/サブセッション/着席モーダル click) にガード追加、cashier.html の offline-detector → state-banner 読み込み順修正、.includes() → .indexOf()!==-1 置換、OfflineStateBanner.isOfflineOrStale() 共通ヘルパ追加。詳細は §9.14 相当の Phase 3 更新履歴 (12)。
- **2026-04-20 (PWA Phase 3 通信不安定時の表示耐性)**: §5.13e 新設。新規 `offline-snapshot.js` / `offline-state-banner.js`、KDS / handy / cashier / pos-register の主要 GET に snapshot 保存・復元、状態変更 API (PATCH/POST/DELETE) にオフラインガード追加 (キューせず toast 返し)。オフライン会計・決済・注文送信は一切実行しない。SW 変更なし。詳細は `internal/09-pwa.md` §9.15。
- **2026-04-20 (PWA Phase 2b 拡張)**: §5.13d に「Phase 2b 拡張」を追記。(B) 送信ログ `push_send_log` テーブル + レート制限 (同 user+type+tag で 60 秒 2xx 済みなら skip)。(C) `monitor-health.php` Stripe Webhook 失敗検知に `important_error` 自動 Push 接続 (tenant 代表店舗の manager/owner、5 分重複抑制)。(D) POSLA 管理画面 `dashboard.html` に PWA/Push タブ追加 (`api/posla/push-vapid.php` 読み取り専用統計)。秘密鍵は `~/Desktop/posla-secrets/vapid-backup-20260420.txt` にローカル退避済み + リモート `/tmp/vapid-*.txt` クリーンアップ済み。
- **2026-04-20 (PWA Phase 2b レビュー指摘対応)**: §5.13d を更新。(1) `api/customer/call-staff.php` (顧客スタッフ呼び出し Push) をトリガーに追加 — これが Phase 2 本命通知。(2) `push_send_to_*()` に `tenant_id = ?` を追加してマルチテナント境界二重化。(3) `push_send_to_roles()` は同 tenant の owner NULL 購読 (owner-dashboard で全店通知希望) を OR 条件で拾う。(4) `subscribe.php` を厳格化 — `scope=owner` は `role=owner` のみ許可 (非 owner が NULL 購読を作れる権限漏れを塞ぐ)、`scope!='owner'` は store_id 必須。(5) `PushSubscriptionManager.setStoreId()` API + 各画面で storeId ポーリング反映 (KdsAuth / HandyApp / StoreSelector)。(6) 通知 URL を SW 許可 prefix `/public/{handy,admin}/...` に統一。(7) `.repeat()` を ES5 互換ループに置換。
- **2026-04-19 (S3 セキュリティ修正一式)**: 5.3.3 を新設（DB 認証情報の環境変数管理、`api/.htaccess` の `SetEnv` ディレクティブ運用）。5.6.2 マイグレーション一覧に S3 系 3 件を追加（`migration-s3-refund-pending.sql` 適用済 / `migration-s3-reservation-uniq.sql` 保留 / `migration-s3-fix-comment.sql` スキップ）。
- **2026-04-19**: フル粒度化（Sakura 構成・SSH 接続・デプロイ手順・mysql コマンドライン・cron 設定・パーミッション・FAQ 30 件を追加）
- **2026-04-19**: F-DA1 / F-QR1 / CR1 / F-MV1 の新機能を文書化
- **2026-04-18**: frontmatter 追加、AIヘルプデスク knowledge base として整備
