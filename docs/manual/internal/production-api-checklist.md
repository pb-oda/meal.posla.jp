---
title: 本番切替 API チェックリスト (canonical)
chapter: 11
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム / サーバ移行担当
keywords: [production migration, docker, env, stripe, gemini, smaregi, cutover]
last_updated: 2026-04-25
maintainer: POSLA運営
---

# 11. 本番切替 API チェックリスト (canonical)

この章は、**擬似本番 `【擬似本番環境】meal.posla.jp` をそのまま本番へ載せる** ときの canonical checklist です。
本章の正本対象は **container-based な `meal.posla.jp` 系** であり、`eat.posla.jp` の共有サーバ運用ではありません。

::: tip この章の役割
- `docker/env/*.env` の canonical source
- 外部サービス切替の正本
- 本番切替直前の smoke test 項目
:::

## 1. スマレジ連携

コード変更が必要な場合のみ対応します。通常の本番切替で最初に見るのは **Client ID / Client Secret / redirect_uri** です。

確認点:

- `smaregi_client_id`
- `smaregi_client_secret`
- `https://meal.posla.jp/api/smaregi/callback.php` を live 側 redirect URI に登録

## 2. Gemini API / Google Places

コード差し替えは不要です。POSLA 管理画面の API 設定で live 値へ更新します。

対象キー:

- `gemini_api_key`
- `google_places_api_key`

## 3. Stripe Billing / Connect

コード差し替えは不要です。設定値だけ切り替えます。

対象キー:

- `stripe_secret_key`
- `stripe_publishable_key`
- `stripe_webhook_secret`
- `stripe_price_base`
- `stripe_price_additional_store`
- `stripe_price_hq_broadcast`
- `connect_application_fee_percent`

補足:

- 本番契約前の擬似本番では `STRIPE_NOT_CONFIGURED` で止まるのが正常
- live 値投入後に signup / checkout / portal / webhook を順に確認する

## 4. Google Chat / 運用通知

対象キー:

- `google_chat_webhook_url`
- `ops_notify_email`
- `monitor_cron_secret`

## 5. Web Push / VAPID

対象:

- `web_push_vapid_public`
- `web_push_vapid_private_pem`

現在の POSLA 管理画面 `PWA / Web Push` タブでは以下が可能です。

- VAPID 状態確認
- 公開鍵取得
- 秘密鍵取得
- bundle 取得
- 鍵生成と即時適用

## 6. テナント個別決済

owner-dashboard の「決済設定」で管理するものです。

- `tenants.stripe_secret_key`
- `tenants.payment_gateway`
- `tenants.stripe_connect_account_id`
- `tenants.connect_onboarding_complete`

## 7. POSLA管理画面で設定する共通キー

### 7-1. POSLA管理画面（`/posla-admin/`）で設定する共通キー

| setting_key | 用途 | 本番切替時の作業 |
|---|---|---|
| `gemini_api_key` | AI 機能全般 | live キー投入 |
| `google_places_api_key` | Places / レビュー | live キー投入 |
| `stripe_secret_key` | Stripe Billing | `sk_live_...` |
| `stripe_publishable_key` | Checkout | `pk_live_...` |
| `stripe_webhook_secret` | Stripe Webhook 検証 | live webhook 再登録後に更新 |
| `stripe_price_base` | 基本料金 | live Price ID |
| `stripe_price_additional_store` | 追加店舗 | live Price ID |
| `stripe_price_hq_broadcast` | 本部一括配信 | live Price ID |
| `connect_application_fee_percent` | Connect 手数料率 | 数値確認 |
| `smaregi_client_id` | Smaregi OAuth | live 値 |
| `smaregi_client_secret` | Smaregi OAuth | live 値 |
| `google_chat_webhook_url` | 運用通知 | live Webhook |
| `ops_notify_email` | 緊急通知先 | 実運用アドレス |

## 8. 本番移行の推奨手順

1. 新サーバへコード配置
2. `docker/env/db.env` と `docker/env/app.env` を作成
3. reverse proxy / TLS を用意
4. `docker compose up -d --build`
5. DB restore
6. POSLA 管理画面で共通設定投入
7. Stripe / Smaregi / Google / Chat の live 設定
8. smoke test
9. DNS 切替

## 9. 本番ドメイン切替 / サーバ移行（`meal.posla.jp` 系 canonical）

本番は container-based です。
`docker-compose.yml` と `docker/env/*.env` を正本として扱います。

### 9-0. 新サーバ移行で設定すべき環境変数一覧（canonical）

> **本節が env の canonical source です。**

#### 9-0-1. DB コンテナ側: `docker/env/db.env`

| 変数名 | 必須 | 用途 |
|---|---|---|
| `MYSQL_ROOT_PASSWORD` | ✅ | MySQL root パスワード |
| `MYSQL_DATABASE` | ✅ | アプリ DB 名 |
| `MYSQL_USER` | ✅ | アプリ DB ユーザー |
| `MYSQL_PASSWORD` | ✅ | アプリ DB パスワード |
| `TZ` | ✅ | タイムゾーン |

#### 9-0-2. PHP コンテナ側: `docker/env/app.env`

| 変数名 | 必須 | 用途 |
|---|---|---|
| `POSLA_DB_HOST` | ✅ | DB ホスト |
| `POSLA_DB_NAME` | ✅ | DB 名 |
| `POSLA_DB_USER` | ✅ | DB ユーザー |
| `POSLA_DB_PASS` | ✅ | DB パスワード |
| `POSLA_APP_BASE_URL` | ✅ | 正規 URL / callback / メール導線 |
| `POSLA_FROM_EMAIL` | ✅ | 送信元メール |
| `POSLA_SUPPORT_EMAIL` | ✅ | 問い合わせ先 |
| `POSLA_ALLOWED_ORIGINS` | ✅ | CORS origin |
| `POSLA_ALLOWED_HOSTS` | ✅ | Host / Origin 検証 |
| `POSLA_PHP_ERROR_LOG` | ✅ | PHP エラーログ出力先 |
| `POSLA_CRON_SECRET` | 条件付き | HTTP cron 用共有秘密 |

#### 9-0-3. ローカル擬似本番専用

| 変数名 | 用途 |
|---|---|
| `POSLA_ENV=local` | ローカル分岐 |
| `POSLA_LOCAL_DB_*` | ローカル db 優先参照 |

本番では通常未設定です。

### 9-1. env ファイルの配置場所

```text
<deploy-root>/docker/env/db.env
<deploy-root>/docker/env/app.env
```

作成手順:

```bash
cp docker/env/db.env.example docker/env/db.env
cp docker/env/app.env.example docker/env/app.env
```

### 9-2. 切替前に必ず確認すること

- `POSLA_APP_BASE_URL=https://meal.posla.jp`
- `POSLA_ALLOWED_ORIGINS=https://meal.posla.jp`
- `POSLA_ALLOWED_HOSTS=meal.posla.jp`
- `POSLA_PHP_ERROR_LOG` が書ける
- `uploads/` が永続化される
- `docker/php/startup.sh` が cron loop を起動する

### 9-3. 外部サービス側で更新する URL

| サービス | URL |
|---|---|
| Stripe signup webhook | `https://meal.posla.jp/api/signup/webhook.php` |
| Stripe subscription webhook | `https://meal.posla.jp/api/subscription/webhook.php` |
| Stripe Connect callback | `https://meal.posla.jp/api/connect/callback.php` |
| Smaregi callback | `https://meal.posla.jp/api/smaregi/callback.php` |
| health check | `https://meal.posla.jp/api/monitor/ping.php` |

### 9-4. smoke test

```bash
curl -sf https://meal.posla.jp/api/monitor/ping.php
curl -I https://meal.posla.jp/admin/
curl -I https://meal.posla.jp/customer/menu.html
curl -I https://meal.posla.jp/handy/
curl -I https://meal.posla.jp/kds/
curl -I https://meal.posla.jp/posla-admin/
curl -I https://meal.posla.jp/docs-tenant/
curl -I https://meal.posla.jp/docs-internal/
```

機能 smoke:

- POSLA 管理者ログイン
- owner ログイン
- tenant 作成
- API 設定保存
- Google Chat テスト通知
- PWA / Web Push の鍵画面確認
- 顧客メニュー表示
- cron heartbeat 更新

### 9-5. 本番切替時にコード変更が不要なもの

- Gemini / Places の API エンドポイント
- Stripe SDK 本体
- 顧客側 / 店舗側の主要 API

切り替えるのは **env / DB 設定 / 外部管理画面の URL とキー** です。
