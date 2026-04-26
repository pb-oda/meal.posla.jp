---
title: 新サーバ移行ハンドオフ
chapter: 10
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム / サーバ移行担当
keywords: [server migration, docker, compose, env, deploy, mysql, smoke test]
last_updated: 2026-04-25
maintainer: POSLA運営
---

# 10. 新サーバ移行ハンドオフ

この章は、**`【擬似本番環境】meal.posla.jp` の現実装をそのまま新サーバへ移す**ための要約版です。
本番もコンテナ運用を前提とし、公開 URL は `<production-domain>` で表記します。

実行順の詳細手順は [本番デプロイ手順書](./12-production-deploy-runbook.md) を正本として参照してください。サーバ移行の低レベル作業は [container-server-migration-runbook.md](../../container-server-migration-runbook.md) も併用します。この章は、POSLA運営メンバーが切替時に見落としやすい論点だけを短くまとめたものです。

::: warning 先に押さえること
本番化で事故になりやすいのはコード差分そのものではなく、**env / volume / reverse proxy / webhook / cron** です。
特に `docker/env/*.env` の実値化漏れ、`uploads/` 永続化漏れ、`POSLA_APP_BASE_URL` 未更新、Webhook 再確認漏れは優先して潰してください。
:::

## 10.1 現在の擬似本番モデル

| 項目 | 現実装 |
|---|---|
| 起動方式 | `docker compose up -d` |
| Web | `php:8.2-apache` |
| DB | `mysql:5.7` |
| 公開 path | `/admin/`, `/customer/`, `/kds/`, `/handy/`, `/posla-admin/`, `/api/` |
| ローカル確認 URL | `http://127.0.0.1:8081` |
| 本番想定 URL | `https://<production-domain>` |
| scheduler | `docker/php/startup.sh` → `docker/php/cron-loop.sh` |
| docs | `docs/manual` から `public/docs-tenant`, `public/docs-internal` を生成 |

## 10.2 新サーバでそのまま持っていくもの

```text
<deploy-root>/
├── api/
├── public/
├── sql/
├── scripts/
├── privacy/
├── terms/
├── uploads/
├── docs/
├── docker/
└── docker-compose.yml
```

必須ファイル:

- `docker/php/Dockerfile`
- `docker/php/startup.sh`
- `docker/php/cron-loop.sh`
- `docker/php/apache-vhost.conf`
- `docker/env/app.env.example`
- `docker/env/db.env.example`

## 10.3 env の正本

本番では repo 内ファイルを直接編集し続けず、**repo 外の env を正本**にします。

| 正本 | 役割 |
|---|---|
| `/etc/posla/<production-domain>.app.env` | app runtime env |
| `/etc/posla/<production-domain>.db.env` | MySQL init / runtime env |

`<deploy-root>/docker/env/app.env` と `db.env` は symlink 推奨です。

最低限必要な app env:

- `POSLA_DB_HOST`
- `POSLA_DB_NAME`
- `POSLA_DB_USER`
- `POSLA_DB_PASS`
- `POSLA_APP_BASE_URL=https://<production-domain>`
- `POSLA_FROM_EMAIL`
- `POSLA_SUPPORT_EMAIL`
- `POSLA_ALLOWED_ORIGINS=https://<production-domain>`
- `POSLA_ALLOWED_HOSTS=<production-domain>`
- `POSLA_PHP_ERROR_LOG`
- `POSLA_CRON_SECRET`

注意:

- `POSLA_LOCAL_DB_*` と `POSLA_ENV=local` はローカル専用です。本番では通常不要です
- `api/.htaccess` に secrets を置く運用はしません

## 10.4 起動順

```bash
ssh <ssh-user>@<host>
mkdir -p <deploy-root> /etc/posla /var/log/posla /var/lib/posla/uploads
rsync -av --exclude '.git/' --exclude '.DS_Store' --exclude '*no_deploy/' ./ <ssh-user>@<host>:<deploy-root>/

cp <deploy-root>/docker/env/app.env.example /etc/posla/<production-domain>.app.env
cp <deploy-root>/docker/env/db.env.example /etc/posla/<production-domain>.db.env
ln -snf /etc/posla/<production-domain>.app.env <deploy-root>/docker/env/app.env
ln -snf /etc/posla/<production-domain>.db.env <deploy-root>/docker/env/db.env

docker compose -f <deploy-root>/docker-compose.yml config -q
cd <deploy-root>
docker compose up -d --build
docker compose ps
```

## 10.5 DB restore

本番移行は **既存 dump restore が正** です。

```bash
docker compose -f <deploy-root>/docker-compose.yml up -d db
docker compose -f <deploy-root>/docker-compose.yml exec -T db \
  mysql -uroot -p'__REPLACE_ROOT_PASSWORD__' posla_prod < /path/to/posla-prod-YYYYMMDD.sql
```

restore 後は `posla_settings` を確認し、特に次を見ます。

- `gemini_api_key`
- `google_places_api_key`
- `stripe_secret_key`
- `stripe_publishable_key`
- `stripe_webhook_secret`
- `web_push_vapid_public`
- `web_push_vapid_private_pem`
- `google_chat_webhook_url`
- `ops_notify_email`

## 10.6 uploads / scheduler / proxy

最低限確認すること:

- `uploads/` が永続化されている
- `docker compose logs php` に `scheduler started` が出る
- `https://<production-domain>/api/monitor/ping.php` が `ok:true`
- reverse proxy が `X-Forwarded-Proto: https` を app に渡している
- DB は public に出ていない

## 10.7 外部設定

切替時に再確認する項目:

- Stripe webhook / callback
- Stripe Connect redirect
- Smaregi redirect URI
- Google Chat 通知先
- DNS / TLS

## 10.8 最低 smoke test

```bash
curl -i https://<production-domain>/
curl -i https://<production-domain>/api/monitor/ping.php
curl -i https://<production-domain>/admin/
curl -i https://<production-domain>/customer/menu.html
curl -i https://<production-domain>/handy/index.html
curl -i https://<production-domain>/kds/index.html
curl -i https://<production-domain>/posla-admin/dashboard.html
curl -i https://<production-domain>/docs-tenant/
curl -i https://<production-domain>/docs-internal/
```

加えて以下を実施します。

1. POSLA 管理者ログイン
2. tenant owner ログイン
3. `PWA / Web Push` タブで VAPID 状態取得
4. `monitor-health` 手動実行
5. `last_heartbeat` 更新確認

## 10.9 補足

- `gemini_api_key` 未設定だと AI helpdesk は `503 AI_NOT_CONFIGURED` になります
- メール relay 未設定時はメール送信系の不達が起き得ますが、これは app コードではなく infra 側の未整備である可能性があります
- docs はこの擬似本番専用です。デモ環境 docs を正本にしません
