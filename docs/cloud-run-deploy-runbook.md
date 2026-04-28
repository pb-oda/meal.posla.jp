# POSLA Cloud Run Deploy Runbook

作成日: 2026-04-28
対象: `【擬似本番環境】meal.posla.jp`

## 1. 前提

本番値は repo に置かない。Cloud Run env / Secret Manager / Cloud SQL / Memorystore / Cloud Storage を正とする。

必須リソース:

| 用途 | 推奨 |
|---|---|
| Web | Cloud Run service `posla-web` |
| DB | Cloud SQL for MySQL |
| Session / rate limit | Memorystore Redis / Valkey |
| uploads | Cloud Storage bucket を `/var/www/html/uploads` に mount |
| cron | Cloud Run Jobs + Cloud Scheduler |
| mail | SendGrid API transport |
| secret | Secret Manager |

Redisについて:

- POSLA webはCloud Runで確定
- POSLA web/API の session / rate limit は Memorystore Redis / Valkey を使う
- Cloud Run webからVPS上Redisを使う構成は採用しない
- VPSは provisioner / OP / runner の配置先として使う可能性があるが、POSLA session用Redisとは別論点にする
- Redis portをpublic internetへ開けない

## 2. Image build

```bash
docker build -f docker/php/Dockerfile.cloudrun -t <region>-docker.pkg.dev/<project-id>/<artifact-repo>/posla-web:<image-tag> .
docker push <region>-docker.pkg.dev/<project-id>/<artifact-repo>/posla-web:<image-tag>
```

## 3. Web service

Cloud Run service の container port は `8080`。`PORT` は Cloud Run が注入するため、env file に固定値として置かない。

設定する env は [docker/env/app.production.env.example](../docker/env/app.production.env.example) を基準にする。

Web service では以下を必ず守る:

- `POSLA_ENV=production`
- `POSLA_ENVIRONMENT=production`
- `POSLA_CRON_ENABLED=0`
- `POSLA_SESSION_STORE=redis`
- `POSLA_SESSION_REDIS_REQUIRED=1`
- `POSLA_RATE_LIMIT_STORE=redis`
- `POSLA_UPLOADS_DIR=/var/www/html/uploads`
- `POSLA_MAIL_TRANSPORT=sendgrid`
- `POSLA_PROVISIONER_TRIGGER_URL=https://<provisioner-service-url>/run`

Cloud Storage bucket は `/var/www/html/uploads` に mount する。POSLA は `uploads/menu/...` をURLとして返すため、Apache の `/uploads` Alias がこの mount を配信する。

## 4. Cron jobs

同じ image を Cloud Run Jobs でも使う。command は以下。

```bash
/usr/local/bin/posla-cron-job-cloudrun.sh
```

5分間隔 job:

```dotenv
POSLA_CRON_TASK=every-5-minutes
```

実行内容:

- `api/cron/monitor-health.php`
- `api/cron/reservation-reminders.php`
- `api/cron/auto-clock-out.php`

1時間間隔 job:

```dotenv
POSLA_CRON_TASK=hourly
```

実行内容:

- `api/cron/reservation-cleanup.php`

Cloud Scheduler は Cloud Run Jobs の実行APIを叩く。Scheduler の認証は専用 service account で行い、公開HTTP endpointを増やさない。

## 5. Mail

Cloud Run では `mail()` / `mb_send_mail()` に依存しない。

```dotenv
POSLA_MAIL_TRANSPORT=sendgrid
POSLA_SENDGRID_API_KEY=__REPLACE_SENDGRID_API_KEY__
POSLA_FROM_EMAIL=noreply@<production-domain>
POSLA_MAIL_FROM_NAME=POSLA
```

送信元ドメインは SPF / DKIM / DMARC を設定する。管理画面の運用通知メールテストで疎通を確認する。

## 6. Smoke test

デプロイ後、最低限これを確認する。

```bash
curl -sf https://<production-domain>/api/monitor/ping.php
```

確認項目:

| 区分 | 確認 |
|---|---|
| DB | `db:"ok"` |
| cron | Cloud Run Job 実行後 `cron_lag_sec` が 900 秒未満 |
| Redis | POSLA管理者ログイン後、再デプロイしてもセッションが維持される |
| uploads | メニュー画像 upload 後、`https://<production-domain>/uploads/menu/...` で表示できる |
| mail | 管理画面から運用通知メールテストが成功 |
| order | 顧客メニュー注文 → KDS 表示 → 会計確定 |
| self checkout | Stripe 設定ありで「お会計」表示、OFF/未設定では非表示 |

## 7. まだ外部設計が必要なもの

`scripts/cell/*` の cell provisioning は Docker Compose / host-side 実行前提が残る。Cloud Run web service から直接 Docker 作業はしないため、`POSLA_PROVISIONER_TRIGGER_URL` の先には専用VM上の `posla_provisioner` container を置く。

詳細は [provisioner-runner.md](./provisioner-runner.md) を正とする。
