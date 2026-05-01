---
title: システム運用
chapter: 05
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [operations, 運営, POSLA, docker, deploy, mysql, env, backup]
last_updated: 2026-04-30
maintainer: POSLA運営
---

# 5. システム運用

POSLAシステムの運用に関する技術的な情報です。本章は **POSLA 運営（プラスビリーフ社）の運用担当者** が日常的に参照する一次資料です。

## 5.0 仮完成時点の運用確認対象

2026-04-30 時点の擬似本番コードでは、店舗運用に必要な主要機能は次の画面群に分かれています。社内検証では、単にURLが200を返すだけでなく、各画面の空データ表示、権限、店舗境界、主要ボタン、エラー表示を確認します。

| 領域 | 入口 | 重点確認 |
|---|---|---|
| 管理画面 | `public/admin/dashboard.html` | 運営コックピット、全体検索、売上ドリル、初期設定、端末稼働、カート離脱、店舗切替、キャッシュリセット |
| メニュー | 管理画面「メニュー管理」 | カテゴリ、オプション、店舗メニューCSV、限定メニューCSV、翻訳、品切れ、代替提案、整備チェック |
| テーブル・注文 | 管理画面、ハンディ、顧客セルフメニュー | QR開放、注文、KDS反映、会計待ち、席移動、取消、テーブルセッション終了 |
| KDS | `public/kds/index.html` | 注文表示、ステーション、調理開始、完成、提供、音声操作、通知音、予約文脈 |
| レジ | `public/kds/cashier.html` | 開局、会計、外部決済記録、個別会計、返金、締め、緊急会計台帳 |
| 予約 | 顧客予約、管理画面「予約管理」 | 空席判定、Web予約、電話予約、Walk-in、受付待ち、変更履歴、リマインド、予約金、キャンセル待ち |
| テイクアウト | 顧客テイクアウト、管理画面「テイクアウト」 | 受取枠、オンライン決済、SLA、梱包、到着連絡、準備完了LINE通知、返金運用状態 |
| シフト・勤怠 | スタッフ画面、管理画面「シフト管理」 | スマホ表示、希望提出依頼、週間カレンダー、公開前チェック、交代/欠勤、候補承諾、当日・ヘルプ、勤怠 |
| レポート | 管理画面「レポート」「運営管理」 | 週次サマリー、レジ分析、注文履歴、満足度、併売、売上ドリル、カート離脱 |
| オーナー画面 | `public/admin/owner-dashboard.html` | 複数店舗、ユーザー管理、本部メニュー、契約、決済設定、外部連携 |
| POSLA管理 | `public/posla-admin/dashboard.html` | テナント、共通キー、監視、PWA/Push、OP連携 |

この確認は、顧客向けマニュアル `tenant/` と社内向けマニュアル `internal/` の記載根拠にもなります。実装確認なしに「できる」と書かないでください。

::: warning 正本はコンテナ運用です
このドキュメントの正本対象は **`【擬似本番環境】meal.posla.jp` の Docker Compose 構成**です。
`eat.posla.jp` の共有サーバ構成や `.htaccess` / 共有サーバ CRON 前提の説明は、下位節に残っていても **履歴資料**として扱ってください。
新サーバへの本番配備も **同じコンテナ構成を移植する前提**で記述します。
:::

::: tip この章を読む順番（おすすめ）
0. **新サーバ移行担当者は先に [10. 新サーバ移行ハンドオフ](./10-server-migration.md) を読む**
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
| データベース | MySQL 5.7 |
| 実行基盤 | Docker Compose（`php` + `db`） |
| ビルドツール | なし（PHP/JS をそのまま配信） |
| セッション管理 | PHP セッション（Redis保存） + DB セッション台帳 |
| キャッシュ | OPcache（PHP 標準） + Service Worker（staff 画面のみ） |
| HTTPS | 本番サーバ側 reverse proxy で termination |

### 5.1.1 在庫消費の冪等性担保と監査ログ

決済完了時（`checkout-confirm.php` または `process-payment.php`）に実行される在庫減算ロジックは、以下の正確な仕様に基づいています。

- **冪等性マーカー (`orders.stock_consumed_at`)**:
    - `UPDATE orders SET stock_consumed_at = NOW() WHERE id = ? AND stock_consumed_at IS NULL`
    - 注文単位（`orders` レベル）で atomic な claim を行い、同一注文の二重消費を防止します。
- **消費履歴ログ (`inventory_consumption_log`)**:
    - 消費された原材料ごとに以下の情報を記録します：
    - `order_id`: どの注文に紐付く消費か
    - `ingredient_id`: どの原材料が消費されたか
    - `quantity`: 消費量（レシピ数量 × 注文数）
    - `consumed_at`: 消費時刻
- **Full / Partial の挙動差分**:
    - **全額会計**: 卓全体の全注文に対し、上記の atomic claim を順次実行します。
    - **個別会計**: `order_items.payment_id` の更新をトリガーに、対象品目のみを計算して減算します（fail-open 時はエラーログを記録し会計を優先）。

---

## 5.2 サーバー構成（現行正本）

### 5.2.1 接続情報

| 項目 | 値 |
|------|---|
| ローカル配信 URL | `http://127.0.0.1:8081` |
| 本番ドメイン | `https://<production-domain>` |
| Compose プロジェクトルート | `【擬似本番環境】meal.posla.jp/` |
| アプリコンテナ | `php` |
| DB コンテナ | `db` |
| DB ホスト（コンテナ間） | `db` |
| DB ホスト（ホストOS から） | `127.0.0.1:3306` |
| DB 名 | `odah_eat-posla` |
| DB ユーザー | `odah_eat-posla` |
| DocumentRoot | `/var/www/html/public` |
| 監視 ping | `http://127.0.0.1:8081/api/monitor/ping.php` |
| 文字コード | utf8mb4 |

::: tip 重要
今後の本番サーバでも **この Compose 構成をそのまま移植**する前提です。
つまり、`docker-compose.yml` と `docker/env/*.env` を正しく持ち運べば、コードとドキュメントのズレは最小化できます。
:::

### 5.2.2 ディレクトリ構成（現行正本）

```
<deploy-root>/
├── docker-compose.yml              ← Compose 定義（正本）
├── docker/
│   ├── env/
│   │   ├── app.env                 ← アプリ用 env の正本
│   │   ├── app.env.example         ← テンプレート
│   │   ├── db.env                  ← DB 用 env の正本
│   │   └── db.env.example          ← テンプレート
│   ├── php/
│   │   ├── Dockerfile
│   │   ├── startup.sh              ← Apache + scheduler 起動
│   │   └── cron-loop.sh            ← 監視 cron ループ
│   └── db/init/
│       ├── 01-schema.sh
│       ├── 02-migrate.sh
│       └── 03-seed.sql
├── api/                            ← PHP API
├── public/                         ← 配信静的ファイル
│   ├── admin/                      ← テナント運用画面
│   ├── posla-admin/                ← POSLA 管理画面
│   ├── customer/                   ← 顧客画面
│   ├── handy/                      ← ハンディ
│   ├── kds/                        ← KDS / cashier
│   ├── docs-tenant/                ← VitePress 顧客向け build 出力
│   └── docs-internal/              ← VitePress 内部向け build 出力
├── docs/manual/                    ← VitePress source
├── scripts/                        ← 補助スクリプト
└── sql/                            ← schema / migration
```

### 5.2.3 ローカル開発環境

| 項目 | 値 |
|------|---|
| 起動 | `docker compose up -d` |
| 停止 | `docker compose down` |
| 再ビルド | `docker compose up -d --build` |
| コンテナ確認 | `docker compose ps` |
| PHP ログ | `docker compose logs php` |
| DB 接続 | `docker compose exec -T db mysql -uroot -prootpass odah_eat-posla` |

---

## 5.3 APIキー管理

### 5.3.1 POSLA共通キー

Gemini API/Google Places APIのキーはPOSLA運営が一括負担します。`posla_settings`テーブルで一元管理されています。

**管理 UI:** POSLA 管理画面（`https://<production-domain>/posla-admin/dashboard.html` または `http://127.0.0.1:8081/posla-admin/dashboard.html`）→「API設定」タブ

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
サーバ移行時は **`docker/env/*.env` と DB restore 後の設定値** の両方が揃っているかを確認します。

| 区分 | 主な保存先 | 主なキー / カラム | 主な用途 |
|---|---|---|---|
| POSLA 共通 | `posla_settings` | `gemini_api_key`, `google_places_api_key`, `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `stripe_price_base`, `stripe_price_additional_store`, `stripe_price_hq_broadcast`, `connect_application_fee_percent`, `smaregi_client_id`, `smaregi_client_secret`, `google_chat_webhook_url`, `codex_ops_case_*`, `codex_ops_alert_*`, `ops_notify_email`, `monitor_cron_secret`, `web_push_vapid_*`, `slack_webhook_url` | POSLA Billing / signup / Stripe Connect platform / Smaregi OAuth / 監視 / OP連携 / Push |
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

`api/config/database.php` / `api/config/app.php` は **env-first** です。通常運用ではこの 2 ファイルを直接編集せず、**`docker/env/app.env` と `docker/env/db.env`** を修正します。

| 環境変数 | 主な用途 | 読み取り先 | 通常の設定場所 | 備考 |
|---|---|---|---|---|
| `POSLA_DB_HOST` | MySQL ホスト | `api/config/database.php` | `docker/env/db.env` | Compose では `db` |
| `POSLA_DB_NAME` | DB 名 | `api/config/database.php` | `docker/env/db.env` | 現行 `odah_eat-posla` |
| `POSLA_DB_USER` | DB 接続ユーザー | `api/config/database.php` | `docker/env/db.env` | |
| `POSLA_DB_PASS` | DB 接続パスワード | `api/config/database.php` | `docker/env/db.env` | |
| `POSLA_LOCAL_DB_HOST` | local 分岐用 DB ホスト | `api/config/database.php` | `docker/env/app.env` | local branch 用 |
| `POSLA_LOCAL_DB_NAME` | local 分岐用 DB 名 | `api/config/database.php` | `docker/env/app.env` | local branch 用 |
| `POSLA_LOCAL_DB_USER` | local 分岐用 DB ユーザー | `api/config/database.php` | `docker/env/app.env` | local branch 用 |
| `POSLA_LOCAL_DB_PASS` | local 分岐用 DB パスワード | `api/config/database.php` | `docker/env/app.env` | local branch 用 |
| `POSLA_APP_BASE_URL` | 正規ベース URL | `api/config/app.php` | `docker/env/app.env` | 予約URL・signup・Stripe 戻り先等 |
| `POSLA_FROM_EMAIL` | システム送信元メール | `api/config/app.php` | `docker/env/app.env` | |
| `POSLA_SUPPORT_EMAIL` | 利用者向け問い合わせ先 | `api/config/app.php` | `docker/env/app.env` | |
| `POSLA_ALLOWED_ORIGINS` | CORS 許可 Origin | `api/config/app.php` → `api/lib/response.php` | `docker/env/app.env` | カンマ区切り可 |
| `POSLA_ALLOWED_HOSTS` | CSRF / Host 許可 | `api/config/app.php` → `api/lib/auth.php` | `docker/env/app.env` | カンマ区切り可 |
| `POSLA_CRON_SECRET` | HTTP 経由 cron の共有秘密 | `api/cron/*.php` | `docker/env/app.env` | scheduler / 外部 probe 用 |
| `POSLA_ENV` | 実行モード | `api/config/database.php` / `api/config/app.php` | `docker/env/app.env` | 現行 `local` |

**現在の設計上の重要ポイント**:

1. `docker-compose.yml` は `env_file` で `docker/env/db.env` と `docker/env/app.env` を読む
2. `api/config/database.php` / `api/config/app.php` は env 値を優先する
3. 本番でも **同じ 2 ファイルを正本**にし、Compose 起動時に注入する
4. `.example` は初期展開テンプレート、実運用では `.env` 実体を使う
5. 外部 scheduler を使う場合も、**この 2 ファイルの値と同じ env** を注入する

### 5.3.4 サーバ移行 / 本番切替で実際に修正する場所（2026-04-25）

**原則**: サーバ移行担当者が通常修正するのは **`docker/env/app.env` と `docker/env/db.env`** です。
PHP/JS 本体 (`api/config/database.php`, `api/config/app.php`, API 各ファイル) は通常編集しません。

::: tip 環境変数の canonical source は `docker/env/*.env`
新サーバ移行時はまず `docker/env/db.env.example` と `docker/env/app.env.example` を複製し、実値を投入してください。
外部サービスチェックは [production-api-checklist.md](./production-api-checklist.md) を併用します。
:::

::: warning 本番では env を **必ず明示設定** してください
`docker/env/*.env` に必要値を入れずに Compose を起動すると、DB 接続・URL 生成・メール送信・CORS・監視 cron がズレます。
本番運用では **`docker/env/app.env` と `docker/env/db.env` を server 側の唯一の正本**として扱ってください。
:::

#### 修正対象の一覧

| 対象 | 必須 | 何を入れるか | 備考 |
|---|---|---|---|
| `/<deploy-root>/docker/env/db.env` | 必須 | DB 接続 4 項目 | DB コンテナ / app コンテナ共通 |
| `/<deploy-root>/docker/env/app.env` | 必須 | APP URL / メール / Origin / Host / cron secret / local DB 系 | app コンテナ正本 |
| `docker-compose.yml` | 必須 | `env_file` と volume mount | 通常はテンプレートどおり、構成変更時のみ触る |
| scheduler / 外部 cron | 任意 | `POSLA_*` 一式 | HTTP cron / 外部 probe 併用時のみ |
| DB 本体 | 必須 | dump restore / user privilege / 未適用 migration | schema 差分確認が必要 |
| `posla_settings` | 必須 | Gemini / Places / Stripe / Smaregi / Slack / VAPID / `monitor_cron_secret` 等 | 値は DB にあるので dump restore で引き継ぐのが基本 |
| reverse proxy 設定 | 必須 | `<production-domain> -> app:80` | 本番サーバ側で管理 |

#### `docker/env/db.env` の例

```dotenv
POSLA_DB_HOST=db
POSLA_DB_NAME=odah_eat-posla
POSLA_DB_USER=odah_eat-posla
POSLA_DB_PASS=__REPLACE_WITH_DB_PASSWORD__
```

#### `docker/env/app.env` の例

```dotenv
POSLA_ENV=production
POSLA_LOCAL_DB_HOST=db
POSLA_LOCAL_DB_NAME=odah_eat-posla
POSLA_LOCAL_DB_USER=odah_eat-posla
POSLA_LOCAL_DB_PASS=__REPLACE_WITH_DB_PASSWORD__
POSLA_APP_BASE_URL=https://<production-domain>
POSLA_FROM_EMAIL=support@posla.jp
POSLA_SUPPORT_EMAIL=support@posla.jp
POSLA_ALLOWED_ORIGINS=https://<production-domain>
POSLA_ALLOWED_HOSTS=<production-domain>
POSLA_CRON_SECRET=__REPLACE_WITH_CRON_SECRET__
```

#### サーバ移行の最小手順

1. `docker/env/db.env.example` と `docker/env/app.env.example` を複製して実値を投入
2. サーバにコード一式を配置
3. `docker compose config` で env 展開結果を確認
4. `docker compose up -d --build`
5. DB を restore する場合は dump を import
6. migration が必要なら `docker/db/init/02-migrate.sh` 相当を実行
7. `posla_settings` / `tenants` / 外部サービス設定を確認
8. reverse proxy / SSL / Webhook / OAuth redirect を切替
9. smoke test を実施

#### DB / 外部サービスで確認するもの（抜け漏れ防止）

| 区分 | どこを見るか | 最低限確認するもの | 移行時アクション |
|---|---|---|---|
| DB 本体 | MySQL | dump restore 成功、ユーザー権限、未適用 migration | restore → migration → `SELECT 1` / 代表テーブル件数確認 |
| POSLA 共通設定 | `posla_settings` | `gemini_api_key`, `google_places_api_key`, `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `stripe_price_*`, `connect_application_fee_percent`, `smaregi_client_id`, `smaregi_client_secret`, `google_chat_webhook_url`, `codex_ops_case_*`, `codex_ops_alert_*`, `ops_notify_email`, `monitor_cron_secret`, `web_push_vapid_*`, `slack_webhook_url` | dump restore 後に欠落・NULL・旧値がないか確認 |
| テナント個別決済 | `tenants` | `payment_gateway`, `stripe_secret_key`, `stripe_connect_account_id`, `connect_onboarding_complete` | representative tenant で Direct / Connect の両方を spot check |
| テナント個別スマレジ | `tenants` | `smaregi_contract_id`, `smaregi_access_token`, `smaregi_refresh_token`, `smaregi_token_expires_at` | live 運用する店舗だけ OAuth 再認証要否を確認 |
| Stripe Dashboard | Stripe 管理画面 | Webhook endpoint、Customer Portal 戻り URL、Checkout / Connect callback の戻り先 | 新ドメインに差し替え、旧 endpoint は無効化 or 保持方針を決める |
| Smaregi Developers | スマレジ開発者画面 | `redirect_uri`、production Client ID/Secret | `.dev` → `.jp` 切替が必要な場合のみ本番値へ更新 |
| Google Cloud Console | API キー制限 | Gemini / Places のリファラー or IP 制限 | 新ドメイン / 新サーバを許可 |
| 監視 / 外形監視 | UptimeRobot / cron-job.org / Better Uptime | `https://<production-domain>/api/monitor/ping.php`、必要なら HTTP cron secret | 監視 URL と通知先を新環境へ切替 |

**実務上の考え方**:

- `docker/env/*.env` は **コード外の環境差分**
- `posla_settings` / `tenants` の連携キーは **DB 内の運用差分**
- Stripe / Smaregi / Google / 監視サービスは **外部管理画面の差分**

この 3 層を分けて確認すると、移行漏れが起きにくいです。

#### smoke test（最低限）

- `GET /api/monitor/ping.php` → `db:"ok"` で返る
- `GET /api/auth/login.php` → `405`
- `GET /docs-tenant/` → `200`
- `GET /docs-internal/` → `200`
- signup 導線 / 予約詳細 URL / customer menu URL が新ドメインで開く
- Smaregi callback / Stripe return URL / Connect callback が新ドメインへ戻る

#### ロールバック

1. backup した `docker/env/app.env` / `docker/env/db.env` を戻す
2. `docker compose up -d --build` を再実行
3. DNS / Webhook / OAuth redirect を旧サーバに戻す
4. `curl https://<旧ドメイン>/api/monitor/ping.php` で `db:"ok"` を確認

**完全一覧**は `docs/manual/internal/production-api-checklist.md` を参照。
本章は「どのファイル / どの設定場所を触るか」の一次情報、`production-api-checklist.md` は外部サービスも含めた総合チェックリストとして使い分ける。

### 5.3.5 env 供給手段の選択肢（現行コンテナ運用）

POSLA アプリは **env-first 設計**です。現行の正本は **Compose `env_file`** です。

| 供給手段 | 配置例 | 用途 |
|---|---|---|
| Compose `env_file` | `docker/env/app.env`, `docker/env/db.env` | 正本。HTTP / scheduler / CLI の共通基準 |
| `docker compose exec -e ...` | 一時的な検証 | 臨時確認のみ |
| 外部 scheduler の env 注入 | systemd / cron / CI | app コンテナ外から cron を叩く場合のみ |

**推奨しない**:
- PHP ソースへの credential 直書き
- 新サーバごとに `.htaccess` や vhost へ個別 secret を分散配置すること
- `docker/env/*.env` を更新せずに reverse proxy だけ差し替えること

#### 外部 scheduler を使う場合の注意

現行の擬似本番では `docker/php/startup.sh` → `docker/php/cron-loop.sh` が scheduler を起動します。
将来、本番で systemd timer や外部 cron から `api/cron/*.php` を直接叩く場合は、**`docker/env/app.env` / `docker/env/db.env` と同じ値**を注入してください。

### 5.3.6 env 運用インシデント記録（2026-04-22 cron 沈黙事故）

このインシデントの教訓は単純です。**HTTP と scheduler で env の正本を分けてはいけない**。
現行は `docker/env/*.env` を Compose が一括注入し、`docker/php/startup.sh` が同一コンテナ内で scheduler を立てるため、当時の `.htaccess` / cron 分断は解消しています。

本番切替時のルール:

| No | ルール |
|---|---|
| 1 | `docker/env/app.env` と `docker/env/db.env` を唯一の正本にする |
| 2 | DB password rotation 時は `docker/env/db.env` と DB 実体を同時更新する |
| 3 | `docker compose config` で展開後の env を確認してから再起動する |
| 4 | `docker compose up -d --build` 後に `api/monitor/ping.php` を必ず確認する |
| 5 | 外部 scheduler を併用する場合は、同じ env をその scheduler 側にも入れる |

### 5.3.7 SEC-/REL-HOTFIX-20260423 運用インパクト（2026-04-23）

2026-04-23 の 2 連 hotfix（`SEC-HOTFIX-20260423-B` + `REL-HOTFIX-20260423-SERVER-READY`）で POSLA管理者側の認証 hardening と新サーバ移行の portability blocker をまとめて解消した。**運用者が知っておく必要がある挙動変更**を 5 点ここに集約する。各項目の詳細実装は §98.9 / §97.X の監査記録 + `.claude/context/tasks.md` 順位 #14 / #15 を参照。

#### ① `POSLA_PHP_ERROR_LOG` 環境変数（新規）

PHP の `error_log(msg, 3, <path>)` 第 3 引数はこれまで `/home/odah/log/php_errors.log` とハードコードされていた。runtime PHP 67 ファイル / 169 箇所を `POSLA_PHP_ERROR_LOG` 定数 (`api/config/app.php`) へ機械置換済。

- **定義**: `if (!defined('POSLA_PHP_ERROR_LOG')) { define('POSLA_PHP_ERROR_LOG', _app_env_or('POSLA_PHP_ERROR_LOG', '/home/odah/log/php_errors.log')); }`
- **fallback**: 互換用に旧絶対パスを残しているが、現行運用では `docker/env/app.env` で `POSLA_PHP_ERROR_LOG` を明示設定する
- **設定場所**: **§5.3.4 の `api/.htaccess` 例と cron 実行環境の bash 例に掲載済**（canonical source）。`.htaccess` 単独では cron / CLI に効かない点は §5.3.5 / §5.3.6 と同じ落とし穴。
- **運用影響**: cron 側に env を渡し忘れると `error_log()` の出力先が未定義 fallback (`/home/odah/log/php_errors.log`) に書かれ、新サーバでは silent に失敗（§5.3.6 沈黙事故と同じ構造）。

#### ② POSLA管理者セッション失効（SESSION_REVOKED 401）

`require_posla_admin()` (`api/posla/auth-helper.php`) が `posla_admin_sessions` テーブルを毎回照合する仕様に変更された。

- **発火条件**:
  - 現 `session_id` に対応するレコードが非存在 → `change-password.php` で他セッション DELETE 済のケース
  - レコードの `is_active = 0` → `logout.php` で自分自身を soft-delete 済のケース
- **挙動**: `$_SESSION = []; session_destroy();` の後 `401 SESSION_REVOKED` を返す
- **テナント運用影響**: なし（tenant 側 `require_auth()` は従来通り）
- **POSLA 運営者の対応**:
  - パスワード変更した管理者自身の現セッションは影響なし
  - 別端末/ブラウザで同時ログインしていた場合、変更後は 401 で弾かれる → **再ログインを案内**する
  - fail-open: `posla_admin_sessions` テーブルが存在しない環境では認証を阻害しない（static キャッシュ）
- **5 分に 1 回 `last_active_at` 更新**: tenant 側 `_check_session_validity()` と同じ頻度管理

#### ③ POSLA管理者ログインの rate limit（失敗回数ベース）

`api/posla/login.php` に **failure-only** rate limit を追加。

- **上限**: 1 IP あたり **10 回 / 5 分**（tenant 側 `api/auth/login.php` と同値）
- **カウント対象**: `password_verify()` 失敗時のみ（成功ログインは加算されない）
- **超過時**: `429 RATE_LIMITED` を返す
- **ロックアウト時の運用者対応**:
  - 本人の環境で 5 分待機（自動解除）が基本
  - 緊急時のリセット: `/tmp/posla_rate_limit/<md5(ip:posla-login)>.json` を削除
  - ファイルベースなので `apachectl restart` / PHP-FPM 再起動でもリセットされる
- **`_posla_login_rate_limit_exceeded()`** helper で事前判定、`check_rate_limit()` は失敗後のみ呼ぶ設計（共有 NAT 配下の正当ログインで自己ロックしないため）

#### ④ 旧 POSLA管理者 AI 問い合わせの rate limit（履歴）

`api/posla/ai-helpdesk.php` に rate limit を追加。

- **Key**: `md5(IP + 'posla-ai-helpdesk:' + admin_id)` — **admin × IP の組み合わせ**ごと
- **上限**: **20 回 / 10 分**
- **超過時**: `429 RATE_LIMITED`。degraded fallback（キャッシュ or 関連 chunk 抜粋）には**自動移行しない**（rate limit は require_posla_admin 直後に配置しているため、quota 枯渇の degraded path には入らない）
- **Gemini 側 429 は別経路**で検出して degraded に fallback（`posla_helpdesk_is_quota_error()`）

::: warning 2026-04-25 以降: endpoint 自体を retire
POSLA管理画面の「AIコード案内」タブは**非AIのサポートセンター**に置換済です (`js/posla-supportdesk.js` + `shared/data/internal-supportdesk.json`)。旧 `api/posla/ai-helpdesk.php` と関連生成物は `*no_deploy/retired_ai_helpdesk/` へ退避しました。この rate limit は履歴メモとしてのみ残します。
:::

#### ⑤ Secure cookie 強制（HTTPS 判定付き）

`start_auth_session()` / `start_posla_session()` の `session_set_cookie_params()` に `'secure' => _is_https_request()` を追加。

- **判定条件**（以下のいずれかで secure=true）:
  - `$_SERVER['HTTPS']` がセット済で `'off'` 以外
  - `$_SERVER['HTTP_X_FORWARDED_PROTO']` が `'https'`（リバースプロキシ / CDN 経由）
  - `$_SERVER['SERVER_PORT']` が `443`
- **本番**: 常に HTTPS なので `secure=true` で自動付与
- **localhost http 開発**: `secure=false` で維持（ローカル開発が壊れない）
- **非 HTTPS で運用してはいけない**: secure 付き cookie は HTTP では送信されない → セッション維持不可。新サーバ立ち上げ時は HTTPS 証明書設定を**最初のチェックリスト項目**にする

#### 運用者チェックリスト（新サーバ移行時、最小セット）

| # | 項目 | 失敗時の症状 |
|---|---|---|
| 1 | HTTPS 証明書設定済 (Let's Encrypt / 購入証明書) | POSLA管理画面にログインできない（secure cookie が送られない） |
| 2 | `POSLA_PHP_ERROR_LOG` env 設定済（新 log path） or 既定 path が書込可 | `error_log` が silent に失敗、§5.3.6 のような沈黙事故再発リスク |
| 3 | `POSLA_ALLOWED_ORIGINS` / `POSLA_ALLOWED_HOSTS` 新サーバドメイン反映 | CORS / verify_origin 403 |
| 4 | `posla_admin_sessions` テーブル存在（`sql/migration-p1-6d-posla-admin-sessions.sql`） | fail-open 動作（SESSION_REVOKED が効かないが既存挙動は保持） |
| 5 | `/tmp/posla_rate_limit/` 書込可 | rate limit が silent に fail-open（サービスは継続、防御が効かない） |
| 6 | cron env にも §5.3.5 / §5.3.6 の全 env を供給 | cron 沈黙事故再発 |

### 5.3.8 新サーバ移行 SQL 実行確認リスト（2026-04-23）

§5.3.4 の「手順 step 5 DB restore + 未適用 migration 実行」「step 7 smoke test」を**実際に流す SQL** として明文化した checklist。`sql/` 配下には **94 個の migration file** (`sql/migration-*.sql`) があるが、基本戦略は「**旧サーバから dump を取って新サーバに restore する → 新 schema 側に存在する未適用分だけ追加実行 → smoke で健康確認**」。migration tracking テーブルは存在しないため、下記のような **schema-based 存在チェック**で判定する。

::: tip 前提
`sql/schema.sql` は「ある時点の完全 dump (60 テーブル + posla_admin_sessions / reservations / table_sub_sessions 等の直近 migration 込み)」。その後 `sql/migration-*.sql` で追加された **migration file は dump 化されていない**（`error_log` / `push_subscriptions` / `push_send_log` / `rating_reasons` 等）。運用中の旧サーバから dump する場合は全部入っているが、`schema.sql` だけで新サーバを立ち上げると最近の migration 分が欠落する点に注意。
:::

#### ① Schema health check（最初に 1 回流す）

```sql
-- 総テーブル数が想定と一致するか (旧サーバ dump と照合)
SELECT COUNT(*) AS total_tables
  FROM information_schema.tables
  WHERE table_schema = DATABASE();
-- 期待値: 60 前後 + 最近追加 migration 分 (error_log / push_* / rating_reasons で +4 程度)

-- 主要テーブル存在確認
SELECT table_name FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name IN ('tenants','stores','users','orders','order_items','payments',
                       'posla_admins','posla_admin_sessions','posla_settings',
                       'user_sessions','table_sub_sessions','reservations',
                       'error_log','push_subscriptions','push_send_log')
  ORDER BY table_name;
-- 期待: 15 行すべて返る。返らない行が "未適用 migration"
```

#### ② 直近 migration の適用確認（dump の snapshot 時期で抜けがち）

| 確認項目 | 確認 SQL | 欠落時に適用する migration |
|---|---|---|
| `users.role` ENUM に `'device'` | `SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='role';` → `'device'` を含む | `sql/migration-p1a-device-role.sql` |
| `users.cashier_pin_hash` | `SHOW COLUMNS FROM users LIKE 'cashier_pin_hash';` → 1 行 | `sql/migration-cp1-cashier-pin.sql` |
| `tenants.hq_menu_broadcast` | `SHOW COLUMNS FROM tenants LIKE 'hq_menu_broadcast';` → 1 行 | `sql/migration-p1-34-tenant-hq-broadcast.sql` |
| `orders.stock_consumed_at` | `SHOW COLUMNS FROM orders LIKE 'stock_consumed_at';` → 1 行 | `sql/migration-inv-p1-5-auto-consumption.sql` |
| `error_log` テーブル | `SHOW TABLES LIKE 'error_log';` → 1 行 | `sql/migration-cb1-error-log.sql` |
| `push_subscriptions` テーブル | `SHOW TABLES LIKE 'push_subscriptions';` → 1 行 | `sql/migration-pwa2-push-subscriptions.sql` |
| `push_send_log` テーブル | `SHOW TABLES LIKE 'push_send_log';` → 1 行 | `sql/migration-pwa2b-push-log.sql` |
| `posla_admin_sessions` テーブル | `SHOW TABLES LIKE 'posla_admin_sessions';` → 1 行 | `sql/migration-p1-6d-posla-admin-sessions.sql` |
| `emergency_payments` テーブル | `SHOW TABLES LIKE 'emergency_payments';` → 1 行 | `sql/migration-pwa4-emergency-payments.sql` |
| `reservations` テーブル | `SHOW TABLES LIKE 'reservations';` → 1 行 | `sql/migration-l9-reservations.sql` |
| `table_sub_sessions` テーブル | `SHOW TABLES LIKE 'table_sub_sessions';` → 1 行 | `sql/migration-fqr1-sub-sessions.sql` |

#### ③ `posla_settings` 完全性（必須キーの欠落・NULL 検知）

```sql
SELECT k AS expected_key,
       CASE
         WHEN s.value IS NULL OR s.value = '' THEN '❌ missing'
         ELSE '✓ present'
       END AS status
FROM (
  SELECT 'gemini_api_key' AS k UNION ALL
  SELECT 'google_places_api_key' UNION ALL
  SELECT 'stripe_secret_key' UNION ALL
  SELECT 'stripe_publishable_key' UNION ALL
  SELECT 'stripe_webhook_secret' UNION ALL
  SELECT 'stripe_webhook_secret_signup' UNION ALL
  SELECT 'stripe_webhook_secret_reservation' UNION ALL
  SELECT 'stripe_price_base' UNION ALL
  SELECT 'stripe_price_additional_store' UNION ALL
  SELECT 'stripe_price_hq_broadcast' UNION ALL
  SELECT 'connect_application_fee_percent' UNION ALL
  SELECT 'smaregi_client_id' UNION ALL
  SELECT 'smaregi_client_secret' UNION ALL
  SELECT 'google_chat_webhook_url' UNION ALL
  SELECT 'codex_ops_case_endpoint' UNION ALL
  SELECT 'codex_ops_case_token' UNION ALL
  SELECT 'codex_ops_alert_endpoint' UNION ALL
  SELECT 'codex_ops_alert_token' UNION ALL
  SELECT 'slack_webhook_url' UNION ALL
  SELECT 'ops_notify_email' UNION ALL
  SELECT 'monitor_cron_secret' UNION ALL
  SELECT 'web_push_vapid_public' UNION ALL
  SELECT 'web_push_vapid_private' UNION ALL
  SELECT 'web_push_vapid_subject'
) required
LEFT JOIN posla_settings s ON s.`key` = required.k
ORDER BY status DESC, expected_key;
-- ❌ missing が出た行は設定画面 (POSLA管理画面 → API設定) から登録する
```

#### ④ 代表データ件数 sanity（旧サーバ dump と一致するか）

```sql
SELECT 'tenants'              AS entity, COUNT(*) AS cnt FROM tenants
UNION ALL SELECT 'tenants_active',         COUNT(*) FROM tenants WHERE is_active=1
UNION ALL SELECT 'stores',                 COUNT(*) FROM stores
UNION ALL SELECT 'stores_active',          COUNT(*) FROM stores WHERE is_active=1
UNION ALL SELECT 'users',                  COUNT(*) FROM users
UNION ALL SELECT 'users_active',           COUNT(*) FROM users WHERE is_active=1
UNION ALL SELECT 'users_device_role',      COUNT(*) FROM users WHERE role='device'
UNION ALL SELECT 'posla_admins',           COUNT(*) FROM posla_admins
UNION ALL SELECT 'orders_last_30days',     COUNT(*) FROM orders WHERE created_at >= NOW() - INTERVAL 30 DAY
UNION ALL SELECT 'payments_last_30days',   COUNT(*) FROM payments WHERE created_at >= NOW() - INTERVAL 30 DAY
UNION ALL SELECT 'push_subscriptions_active', COUNT(*) FROM push_subscriptions WHERE enabled=1;
-- 期待: 旧サーバで同じ SELECT を走らせた結果と一致すること
```

#### ⑤ 整合性 spot check（親子関係・致命的破損検知）

```sql
-- (a) 親子関係: store に対応する tenant がちゃんと存在するか (FK 確認)
SELECT COUNT(*) AS orphan_stores
  FROM stores s LEFT JOIN tenants t ON s.tenant_id = t.id
  WHERE t.id IS NULL;
-- 期待: 0

-- (b) 親子関係: user に対応する tenant が存在するか
SELECT COUNT(*) AS orphan_users
  FROM users u LEFT JOIN tenants t ON u.tenant_id = t.id
  WHERE t.id IS NULL;
-- 期待: 0

-- (c) 親子関係: orders に対応する store が存在するか
SELECT COUNT(*) AS orphan_orders
  FROM orders o LEFT JOIN stores s ON o.store_id = s.id
  WHERE s.id IS NULL;
-- 期待: 0

-- (d) payments.order_ids (JSON) の参照先が実在するか (代表 100 件の sample 確認)
SELECT p.id, p.order_ids
  FROM payments p
  WHERE p.created_at >= NOW() - INTERVAL 30 DAY
    AND NOT EXISTS (
      SELECT 1 FROM orders o
        WHERE JSON_CONTAINS(p.order_ids, JSON_QUOTE(o.id))
    )
  LIMIT 10;
-- 期待: 0 行 (全 payment が有効な order を参照)

-- (e) POSLA管理者が最低 1 人 active
SELECT COUNT(*) AS active_posla_admins
  FROM posla_admins WHERE is_active=1;
-- 期待: 1 以上
```

#### ⑥ HTTP smoke test (DB 層検証の最後に)

DB 層で問題なし → `/api/monitor/ping.php` → `{"ok":true,"db":"ok",...}` を確認 (詳細は §5.3.4 「smoke test（最低限）」)。

::: warning migration 実行順序の注意
dump restore 後に**欠落している migration だけ**を手動適用するのが安全。全 migration を頭から流すと既存カラム/テーブルで `ERROR 1060 Duplicate column` や `ERROR 1050 Table already exists` が出て途中停止する。各 migration file は `CREATE TABLE IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS` で idempotent 設計を基本とするが、古い migration は idempotent 化されていない場合がある (個別確認)。
:::

---

## 5.4 デプロイ手順

現行の正本は **container-based deploy** です。
**コード配置 → `docker/env/*.env` 更新 → `docker compose config` → `docker compose up -d --build` → 疎通確認** の流れで配備します。

::: warning ルール（厳守）
- **サーバー上でコード編集は禁止。** 全ての編集はローカルで行い、配備は `docker compose` で反映する
- デプロイ前に **必ず DB ダンプ** を取り、必要ならコードスナップショットも残す
- DB 変更（マイグレーション）は **デプロイより先に実行**（コード側が新カラムを参照しても落ちないように）
- 正本の設定ファイルは **`docker/env/db.env` と `docker/env/app.env`**
- `docker compose config` で env 展開結果を確認してから再起動する
:::

### 5.4.1 デプロイ前のチェックリスト

| # | 確認項目 | 確認方法 | NG 時 |
|---|---|---|---|
| 1 | 変更ファイル一覧が手元にある | `git status` / `git diff --name-only` | コミットして洗い出す |
| 2 | ローカルで動作確認済み | `docker compose up -d` + `curl -sf http://127.0.0.1:8081/api/monitor/ping.php` | 修正してから |
| 3 | ES5 互換チェック | `grep -nE "const |let |=>|async |await " public/admin/js/*.js` で 0 件 | const/let/arrow/async を var + コールバックに書き換え |
| 4 | プリペアドステートメント使用 | 変更した PHP の SQL に `?` プレースホルダ | べた書き SQL を `prepare` + `execute` に修正 |
| 5 | tenant_id / store_id 絞り込み | 新規 SQL の WHERE 句に `tenant_id` / `store_id` | 追加してから |
| 6 | DB バックアップ取得 | `mysqldump` 実行済み | 取得してから |
| 7 | DB マイグレーションがある場合 | `sql/migration-*.sql` をローカルで dry-run（テスト DB） | 構文エラーを修正 |
| 8 | キャッシュバスター更新 | 変更した JS を読む HTML の `<script src="...?v=YYYYMMDD">` をバンプ | バンプ漏れがあると古い JS がキャッシュされる |
| 9 | env ファイル整合 | `docker/env/db.env` / `docker/env/app.env` | 欠落値を補完 |
| 10 | 本番 URL の確認 | `<production-domain>` | ドメインが変わる場合は `docker/env/app.env` も同時更新 |

### 5.4.2 Docker Compose 標準デプロイパターン

```bash
# 1. 正本 env を確認
cat docker/env/db.env
cat docker/env/app.env

# 2. デプロイ前 DB バックアップ
set -a
source docker/env/db.env
set +a
NOW=$(date +%Y%m%d-%H%M)
mysqldump --single-transaction --quick --routines \
  -h "$POSLA_DB_HOST" -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  | gzip > "/tmp/posla_pre_deploy_${NOW}.sql.gz"

# 3. compose 定義を検証
docker compose config > /tmp/posla-compose-${NOW}.yaml

# 4. コンテナを再配備
docker compose up -d --build

# 5. 稼働確認
curl -sf http://127.0.0.1:8081/api/monitor/ping.php
```

### 5.4.3 ASCII 配置イメージ

```
<deploy-root>/
├── docker-compose.yml
├── docker/
│   ├── env/
│   │   ├── db.env
│   │   └── app.env
│   └── php/
│       ├── Dockerfile
│       ├── startup.sh
│       └── cron-loop.sh
├── api/
├── public/
├── docs/
└── scripts/

bind mount / env_file
  ├── public/           → /var/www/html/public
  ├── api/              → /var/www/html/api
  ├── docs/             → /var/www/html/docs
  ├── scripts/          → /var/www/html/scripts
  ├── docker/env/db.env → db / php コンテナへ供給
  └── docker/env/app.env→ php コンテナへ供給
```

### 5.4.4 デプロイ後の動作確認

| # | 確認 | コマンド/操作 | 期待結果 |
|---|---|---|---|
| 1 | サーバー応答 | `curl https://<production-domain>/api/monitor/ping.php` | `{"ok":true,...}` |
| 2 | 変更画面の表示 | Chrome シークレットウィンドウで該当 URL | 変更が反映されている |
| 3 | error_log の確認 | `docker compose logs php --tail=100` | 新規 Fatal/Parse なし |
| 4 | ログイン疎通 | テスト owner アカウントでログイン | ダッシュボードに遷移 |
| 5 | 変更箇所の機能テスト | 影響範囲のシナリオを実行 | 期待動作 |
| 6 | キャッシュバスター効くか | DevTools → Network → 該当 JS の Status 200 + Response が新版 | バンプが効いている |

### 5.4.5 ロールバック手順

不具合が発覚したら、**直前のコード状態 + 直前の DB ダンプ** へ戻します。

```bash
# 1. コードを直前の正しい状態へ戻す
# 2. env も巻き戻す
docker compose down
docker compose up -d --build

# 3. 必要なら DB を戻す
gunzip -c /tmp/posla_pre_deploy_YYYYMMDD-HHMM.sql.gz \
  | docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"
```

::: warning DB ロールバック
DB マイグレーションのロールバックは **手動 SQL** を書く必要があります。マイグレーション適用前の状態に戻すには事前に `mysqldump` バックアップが必要（5.5.2 参照）。
:::

---

## 5.5 バックアップ運用

### 5.5.1 コードバックアップ

現行運用では、コードバックアップは **Git の正本 + 配備直前の DB ダンプ + 必要に応じた tarball** で管理します。

| ルール | 内容 |
|---|---|
| 推奨ファイル名 | `posla-code-YYYYMMDD-HHMM.tar.gz` |
| 保存先 | `/tmp/` または外部バックアップ領域 |
| 保持期間 | 30 日（古いバックアップは月次で手動削除） |
| バックアップ範囲 | `api/`, `public/`, `docs/`, `scripts/`, `docker/`, `sql/`, `docker-compose.yml` |
| 配置 | 展開すれば再度 `docker compose up -d --build` できる単位にする |

::: tip バックアップを取る理由
本番で問題が出たときに **同じ compose 定義 / 同じ env / 同じ docs build** をまとめて戻せるためです。
:::

### 5.5.2 DB バックアップ（mysqldump）

毎日 1 回、自動 mysqldump で DB 全体をバックアップします。

```bash
# 手動バックアップ（credentials は env 経由で渡す。平文を script に書かない）
set -a
source docker/env/db.env
source docker/env/app.env
set +a
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
| メニュー画像 | `<deploy-root>/public/uploads/menu/` | 週 1 | rsync / object storage / スナップショット |
| 店舗ブランディング画像 | `<deploy-root>/public/uploads/branding/` | 週 1 | 同上 |
| レシート PDF | `<deploy-root>/storage/receipts/` | 月 1 | 同上 |

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
| 74 | `migration-p1-69-shift-availability-announcements.sql` | シフト希望提出依頼。`shift_availability_announcements` と `shift_availability_announcement_reads` を追加し、店長/副店長からスタッフ画面への依頼表示と既読を管理 |

### 5.6.3 マイグレーション実行手順

```bash
# 1. 事前バックアップ（必須）
TODAY=$(date +%Y%m%d-%H%M)
set -a
source docker/env/db.env
source docker/env/app.env
set +a
mysqldump --single-transaction -h "$POSLA_DB_HOST" \
  -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  | gzip > /home/odah/db_backup/dump_pre_migration_fda1_${TODAY}.sql.gz

# 2. SQL をコンテナ DB に適用
docker compose exec -T db mysql \
  -u"$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  < sql/migration-fda1-device-tokens.sql

# 3. 適用確認
docker compose exec -T db mysql \
  -u"$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" \
  -e "DESCRIBE device_registration_tokens;"
```

::: warning マイグレーション実行のタイミング
**コードデプロイの直前** に実行する。先にコードをデプロイすると、新カラム参照で 500 エラーになる。逆に、SQL 変更だけ先行するのは OK（旧コードは新カラムを無視するため）。
:::

::: warning P1-69 シフト希望提出依頼
`api/store/shift/staff-home.php` と `api/store/shift/availability-announcements.php` は `shift_availability_announcements` を参照する。P1-69 適用前にコードだけ反映するとスタッフ画面で 500 エラーになるため、`migration-p1-69-shift-availability-announcements.sql` を先に適用する。
:::

---

## 5.7 mysql コマンドラインの実用例

### 5.7.1 ログイン

```bash
# credentials は env 経由
set -a
source docker/env/db.env
source docker/env/app.env
set +a

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
| monitor-health | エラーログ監視 + monitor_events + Google Chat / OP 通知（I-1, CB1） | 5 分毎 | `api/cron/monitor-health.php` |
| reservation-cleanup | 期限切れ holds 削除・auto-no-show（L-9） | 1 時間毎 | `api/cron/reservation-cleanup.php` |
| reservation-reminders | 予約リマインダー送信（L-9） | 5〜10 分毎 | `api/cron/reservation-reminders.php` |

### 5.8.2 スケジューラ設定（現行コンテナ運用）

現行の擬似本番では、`docker/php/startup.sh` から `docker/php/cron-loop.sh` を常駐起動しています。
つまり **PHP コンテナ内で scheduler が回る** のが正本です。外部 cron を併用する場合でも、`docker/env/db.env` と `docker/env/app.env` と同じ値を供給してください。

```bash
# scheduler プロセス確認
docker compose exec -T php ps -ef

# 直近ログ確認
docker compose logs php --tail=100

# 手動で monitor-health を 1 回実行
docker compose exec -T php php /var/www/html/api/cron/monitor-health.php
```

::: warning 外部 scheduler を使う場合
- `POSLA_DB_*` / `POSLA_APP_BASE_URL` / `POSLA_FROM_EMAIL` / `POSLA_SUPPORT_EMAIL` / `POSLA_ALLOWED_*` / `POSLA_CRON_SECRET` を同じ値で渡す
- `%` 記号は crontab 内では改行扱いなので `\%` エスケープが必要
- パスは絶対パスで書く
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
     https://<production-domain>/api/cron/monitor-health.php
```

### 5.8.4 cron が動いているかの確認

```bash
# 直近の実行ログを確認
tail -100 /home/odah/log/cron-monitor-health.log

# heartbeat の確認（monitor-health が動いていれば毎 5 分更新）
set -a
source docker/env/db.env
source docker/env/app.env
set +a
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
| `docker compose logs php` | Apache / cron-loop / PHP stderr の総合ログ | Docker ログドライバ設定に従う |
| `POSLA_PHP_ERROR_LOG`（現行既定: `/tmp/posla_php_errors.log`） | `error_log(msg, 3, ...)` で書かれる PHP 実行時ログ | env 設定に従う |
| `docker compose logs db` | MySQL コンテナログ | Docker ログドライバ設定に従う |
| DB の `error_log` テーブル | API エラー全件（CB1 で導入） | 90 日（運用 cron で削除） |
| DB の `monitor_events` テーブル | 監視イベント（heartbeat / Google Chat・OP 通知発火元） | 90 日（同上） |
| DB の `audit_log` テーブル | 全 admin 操作（誰が何をした） | 1 年（同上） |

### 5.9.2 php_errors.log の見方

```bash
# 直近のコンテナログ
docker compose logs php --tail=50

# error_log ファイルの中身
docker compose exec -T php sh -lc 'tail -50 "$POSLA_PHP_ERROR_LOG"'

# Fatal だけ抽出
docker compose exec -T php sh -lc 'grep -E "PHP Fatal" "$POSLA_PHP_ERROR_LOG" | tail -20'
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
2. 公開鍵を配備先サーバの `~/.ssh/authorized_keys` に追加
3. 新しい鍵で接続テスト
4. 古い鍵を `authorized_keys` から削除
5. プロジェクトの運用ノート・MEMORY.md を更新

---

## 5.12 セッション管理

POSLA は `$_SESSION` の実データを Redis に保存します。これにより、PHP app コンテナの rebuild / replace / deploy でログインセッションを失いにくくします。

一方で、セッションの有効性・失効・最終操作時刻は DB 側の台帳で管理します。

| 用途 | 保存先 |
|------|--------|
| PHP セッション本体 (`$_SESSION`) | Redis (`POSLA_SESSION_STORE=redis`) |
| テナントユーザーの有効性台帳 | `user_sessions` |
| POSLA管理者の有効性台帳 | `posla_admin_sessions` |

Redis 接続設定は `docker/env/app.env` で管理します。

| 環境変数 | 用途 |
|------|------|
| `POSLA_SESSION_STORE=redis` | PHP セッション保存先を Redis にする |
| `POSLA_SESSION_REDIS_REQUIRED=1` | Redis/session extension 不備時に fail-closed |
| `POSLA_SESSION_GC_MAXLIFETIME_SEC=28800` | Redis 側の PHP セッション有効期間 |
| `POSLA_REDIS_HOST=redis` | Redis host |
| `POSLA_REDIS_PORT=6379` | Redis port |
| `POSLA_REDIS_DATABASE=0` | Redis DB |
| `POSLA_REDIS_SESSION_PREFIX` | cell ごとの session key prefix |

### テナントユーザーのセッション

| 設定 | 値 |
|------|-----|
| 有効時間 | 8時間 |
| 無操作タイムアウト | 30分（25分で警告） |
| セッション再生成 | ログイン成功時 |
| マルチデバイス | 同時ログイン許可（警告表示） |
| セッション本体 | Redis |
| 有効性台帳 | `user_sessions` |

### POSLA管理者のセッション

| 設定 | 値 |
|------|-----|
| 有効時間 | 8時間 |
| Cookie属性 | HttpOnly、SameSite=Lax |
| セッション再生成 | ログイン成功時 |
| セッション本体 | Redis |
| 有効性台帳 | `posla_admin_sessions` |

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
| important_error | `monitor-health.php` で Stripe Webhook 失敗を tenant 別集計 → 代表店舗の manager+owner に自動 Push。5 分重複抑制。POSLA 運営への Google Chat / OP 通知は維持 |
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
| KDS | AIシェフ分析パネル | 60秒 |
| フロアマップ | テーブルステータス | 5秒 |
| ハンディPOS | スタッフ呼び出しアラート | 5秒 |
| ハンディPOS | メニューバージョンチェック | 3分 |
| セルフオーダー | 注文ステータス追跡 | 5秒 |
| セルフオーダー | スタッフ呼び出し応答チェック | 5秒 |
| テイクアウト管理 | 注文一覧 | 30秒 |
| POSレジ | 注文・テーブルデータ | 3秒 |
| POSレジ | 売上サマリー | 5秒 |

---

## 5.14a KDS端末状態帯と着手時間設定

KDSキッチン画面は、開きっぱなし端末が止まっていることに現場で早く気づけるよう、ステーション状況帯の下に端末状態帯を常時表示する。

| 表示 | 実装元 | 判定 |
|---|---|---|
| 最終更新 | `public/kds/index.html` の `_lastPollingAt` | `PollingDataSource.start()` 成功時に更新 |
| 遅延 | `Date.now() - _lastPollingAt` | 10秒超で黄、20秒超で赤 |
| live/stale | `OfflineStateBanner.isStale()` | staleなら赤 |
| 音声成功 | `localStorage: mt_kds_voice_diag_v1.lastSuccessAt` | 音声コマンド成功時刻を端末内から読む |
| 通信異常 | `_lastPollingError` | エラーがあれば赤 |

この表示は監査ログではない。ログインしているのはdeviceアカウントであり、個人スタッフの識別には使わない。

予約/テイクアウトの事前表示は、従来の固定20分からKDS端末ごとの設定に変更している。

| 設定 | 保存先 | 適用 |
|---|---|---|
| テイクアウト | `takeoutMin` | `orders.order_type='takeout'` + `pickup_at` |
| 予約 | `reservationMin` | `reservation_reserved_at` |
| カテゴリ別 | `categories[category_id]` | 注文品目の `category_id` で上書き。空欄はテイクアウト/予約の設定に従う |

`api/kds/orders.php` は各品目に `category_id` / `category_name` を付与する。KDSレンダラーは `KdsRenderer.setPrepLeadConfig()` で端末設定を受け取り、同じ注文に複数カテゴリがある場合は未完了品目の中で最も長い着手時間を使う。`defaultMin` は古い端末設定の互換値として残るが、現行UIでは直接設定しない。

店舗全体で統一したい場合は、将来 `store_settings` と管理画面設定へ昇格する。現行実装はDB変更なしの端末設定であり、複数KDS端末を置く店舗では開店前に各端末の設定確認が必要。

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
- API 単体性能チェック: `curl https://<production-domain>/api/store/revisions.php?store_id=X&channels=kds_orders` (要認証 cookie) のレスポンス時間が安定して 50ms 以上かかるなら見直し対象

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
2. `api/store/device-registration-token.php` がプレーントークンを生成し、`setupUrl`（例: `https://<production-domain>/admin/device-setup.html?token=xxxx`）を返す
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
| Google Chat / OP 通知が来ない | `google_chat_webhook_url` または OP 連携設定未設定 | `posla_settings` と OP の Alert ingest 設定を確認、curl で疎通テスト |

---

## 5.20 FAQ 30 件

### Q1. 本番 URL は？
A. 本番の正本ドメインは **`<production-domain>`** として扱います。ドメインを変更する場合は `docker/env/app.env` の `POSLA_APP_BASE_URL` / `POSLA_ALLOWED_ORIGINS` / `POSLA_ALLOWED_HOSTS` を同時更新してください。

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
A. 604 = `rw----r--`、644 = `rw-r--r--` です。コンテナ運用では bind mount 元の所有権や Docker 実行ユーザーとの整合も見る必要がありますが、公開コードは「所有者のみ書き込み・実行側は読み取り」が原則です。

### Q8. cron で `%` がエスケープされない
A. crontab 内で `%` は改行扱い。`date +\%Y\%m\%d` のようにバックスラッシュでエスケープ。

### Q9. `php_errors.log` がどこにあるか分からない
A. まず `docker compose logs php` を見ます。アプリ側の error_log 出力先は `POSLA_PHP_ERROR_LOG` です。

### Q10. mysql コマンドラインのパスワードを履歴に残したくない
A. `~/.my.cnf` にパスワードを書いて `chmod 600`。コマンドラインからは `-p` を省略できます。

### Q11. cron が止まっているか確認したい
A. `posla_settings.monitor_last_heartbeat` が 5 分以内に更新されていれば monitor-health は動作中。それ以外の cron は `cron-*.log` を `tail` で確認。

### Q12. error_log テーブルが急に膨れた
A. 攻撃 or アプリバグの多発。`SELECT error_no, COUNT(*) FROM error_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) GROUP BY error_no ORDER BY 2 DESC LIMIT 10` で原因 errorNo 特定。

### Q13. monitor_events に同じイベントが連発する
A. monitor-health.php の重複防止ロジック（直近 30 分の同タイトルチェック）が効いていない可能性。詳細は `api/cron/monitor-health.php` の `_logEvent` 周辺を確認。

### Q14. Stripe Webhook が失敗していると Google Chat / OP 通知される
A. monitor-health が `subscription_events` の `error_message` を 5 分間隔で集計、1 件以上あれば warn として記録 + Google Chat / OP 通知。詳細は `subscription_events` テーブル参照。

### Q15. デプロイ後にキャッシュで古い JS が読まれる
A. `<script src="...?v=YYYYMMDD">` の `?v=` をバンプ。同じ JS を読む全 HTML をバンプし忘れないこと（`grep -r "filename.js" public/`）。

### Q16. テナントを緊急停止したい
A. `UPDATE tenants SET is_active = 0 WHERE slug = ?`。次回操作時に全ユーザーがログイン画面にリダイレクトされます。

### Q17. 古い user_sessions / error_log を削除したい
A. 月次 cron で `DELETE FROM user_sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)` / `DELETE FROM error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)`。

### Q18. PHP のバージョンを確認したい
A. `docker compose exec -T php php -v`。

### Q19. OPcache を無効化してデプロイしたい
A. `docker compose restart php` で十分です。必要なら `docker compose exec -T php php -r 'opcache_reset();'` を実行します。

### Q20. ログファイルが大きすぎて開けない
A. `docker compose logs php --tail=1000` で末尾だけ取得します。`POSLA_PHP_ERROR_LOG` を直接見る場合は `docker compose exec -T php sh -lc 'tail -1000 "$POSLA_PHP_ERROR_LOG"'` を使います。

### Q21. テスト DB を作りたい
A. 別の Docker DB コンテナか一時 MySQL を作成し、`schema.sql` + 全 migration を流します。本番に影響を与えずに mysqldump リストアテストもできます。

### Q22. expect スクリプトでパスフレーズが一致しない
A. expect の `spawn` 直後の出力を `interact` で確認。バージョンによって `Enter passphrase for key '...':` の表記が異なるので、ワイルドカード `Enter passphrase*` を使う。

### Q23. デプロイ後に動作確認すべき URL は？
A. `https://<production-domain>/api/monitor/ping.php`（200 + `{"ok":true}`）+ 該当変更画面の表示。

### Q24. 監査ログ（audit_log）はどれくらい保持？
A. 1 年が目安。`DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)` を月次 cron に。

### Q25. Stripe Connect の Webhook イベントが届かない
A. Stripe Dashboard → Developers → Webhooks で配信ステータス確認。`stripe_webhook_secret` の不一致 or サーバー時刻ズレ（5 分以上）が主原因。

### Q26. メールが届かない（予約リマインダー等）
A. `reservation_notifications_log` の `status` を確認し、`failed` なら詳細メッセージを見ます。さらに `docker compose logs php` とメール基盤側ログを照合してください。

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
