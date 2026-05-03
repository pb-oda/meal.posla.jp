# POSLA Cloud Run Deploy Runbook

作成日: 2026-04-28
最終更新: 2026-05-02
対象: `【本番環境】meal.posla.jp` / Cloud Run production

超詳細な本番デプロイ手順は [cloud-run-production-complete-deploy-guide.md](./cloud-run-production-complete-deploy-guide.md) を正本とします。この文書は要点と実行順を短く確認するためのrunbookです。

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
| mail | 現状は `POSLA_MAIL_TRANSPORT=none`。実メール provider 決定後に別途実装・設定 |
| secret | Secret Manager |

Redisについて:

- POSLA webはCloud Runで確定
- POSLA web/API の session / rate limit は Memorystore Redis / Valkey を使う
- Cloud Run webからVPS上Redisを使う構成は採用しない
- VPSは provisioner / OP / runner の配置先として使う可能性があるが、POSLA session用Redisとは別論点にする
- Redis portをpublic internetへ開けない

## 2. デプロイ順

本番フォルダは直接編集しない。`test` で検証済みのcommitを `main` へpromoteし、本番フォルダはGitから取り込むだけにする。

```text
1. 【テスト環境】meal.posla.jp で実装、Docker確認、pre-deploy check
2. testへcommit/push
3. testをmainへpromote
4. 【本番環境】meal.posla.jp でgit pull
5. Cloud Run image build / push
6. 本番DB migration
7. Cloud Run service deploy
8. Cloud Run Jobs / Cloud Scheduler確認
9. 本番URL smoke
10. OPからping/snapshot/Google Chat通知確認
```

デプロイ担当者は、開始前に以下を確認する。

```bash
git status -sb
git branch --show-current
git log --oneline -1
BASE_URL=http://127.0.0.1:8081 bash scripts/cloudrun/pre-deploy-check.sh
```

この端末に `gcloud` がない場合は、Cloud Shellまたはgcloud導入済み端末で 5 以降を実行する。

## 3. Image build

デプロイ前に、作業コピーのcommit、Cloud Run用entrypoint、必須migration、OP監視endpointを確認する。

```bash
BASE_URL=http://127.0.0.1:8081 bash scripts/cloudrun/pre-deploy-check.sh
```

image tagはGit commitを含め、後からどのコードをdeployしたか追えるようにする。

```bash
PROJECT_ID=<project-id>
REGION=<region>
REPOSITORY=<artifact-repo>
IMAGE_TAG=$(git rev-parse --short HEAD)
IMAGE_URL=${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPOSITORY}/posla-web:${IMAGE_TAG}

gcloud auth configure-docker ${REGION}-docker.pkg.dev
docker build -f docker/php/Dockerfile.cloudrun -t ${IMAGE_URL} .
docker push ${IMAGE_URL}
```

ローカルでCloud Run image起動確認をする場合:

```bash
docker build -f docker/php/Dockerfile.cloudrun -t posla-web:predeploy .
docker run --rm -p 18080:8080 --env-file <local-test-env-file> -e PORT=8080 -e POSLA_CRON_ENABLED=0 posla-web:predeploy
curl -sf http://127.0.0.1:18080/api/monitor/ping.php
```

## 4. Web service

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

service deploy:

```bash
gcloud run deploy posla-web \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${IMAGE_URL} \
  --platform=managed \
  --port=8080 \
  --no-allow-unauthenticated
```

公開Webとして外部アクセスを許可する場合だけ、アクセス制御方針を確認してから `--allow-unauthenticated` を使う。公開前にCloud Armor、ロードバランサ、IAP、またはCloud Run IAMのどれで守るかを決める。

## 5. DB migration

Cloud Run service の切替前に、対象DBへ未適用 migration を適用する。

今回の通常レジ方針変更で最低限必要な migration:

- `sql/migration-p1-46-register-payment-detail.sql`
  - 通常レジのカード / QR / 電子マネー詳細を `payments` / `orders` に保存する
- `sql/migration-p1-47-register-close-reconciliation.sql`
  - レジ締め時の予想現金 / 実際現金 / 差額 / 支払い別売上 / 締めメモを `cash_log` に保存する
- `sql/migration-p1-70-terminal-monitoring-policy.sql`
  - OP監視向けに `handy_terminals.monitoring_enabled`、`operational_status`、`monitor_business_hours_only` を追加し、未使用端末や営業時間外を障害扱いしない

適用後、`payments.payment_method_detail`、`orders.payment_method_detail`、`cash_log.expected_amount`、`cash_log.difference_amount`、`cash_log.cash_sales_amount`、`cash_log.card_sales_amount`、`cash_log.qr_sales_amount`、`cash_log.reconciliation_note`、`handy_terminals.monitoring_enabled`、`handy_terminals.operational_status`、`handy_terminals.monitor_business_hours_only` が存在することを確認する。

OP連携のrelease直前確認:

- `GET /api/monitor/ping.php` が `ok:true` を返す
- `GET /api/monitor/cell-snapshot.php` がOP read secret付きで取得できる
- snapshotに `reservations`、`shifts`、`takeout`、`menu_inventory`、`customer_line`、`external_integrations`、`terminal_heartbeat` が含まれる

DB migrationは本番DB backup取得後に実行する。migration後に上記カラム存在確認を行い、失敗した場合はCloud Run service切替を止める。

## 6. Cron jobs

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

job作成または更新の例:

```bash
gcloud run jobs deploy posla-cron-every-5-minutes \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${IMAGE_URL} \
  --command=/usr/local/bin/posla-cron-job-cloudrun.sh \
  --set-env-vars=POSLA_CRON_TASK=every-5-minutes

gcloud run jobs deploy posla-cron-hourly \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${IMAGE_URL} \
  --command=/usr/local/bin/posla-cron-job-cloudrun.sh \
  --set-env-vars=POSLA_CRON_TASK=hourly
```

初回確認:

```bash
gcloud run jobs execute posla-cron-every-5-minutes --project=${PROJECT_ID} --region=${REGION} --wait
gcloud run jobs execute posla-cron-hourly --project=${PROJECT_ID} --region=${REGION} --wait
```

## 7. Mail

Cloud Run では `mail()` / `mb_send_mail()` に依存しない。現時点ではSendGridを標準採用しないため、実メール送信は無効化して運用する。外部メールproviderを決めた時点で transport 実装とenvを追加する。

```dotenv
POSLA_MAIL_TRANSPORT=none
POSLA_FROM_EMAIL=noreply@<production-domain>
POSLA_MAIL_FROM_NAME=POSLA
```

実メールproviderを有効化する場合は送信元ドメインの SPF / DKIM / DMARC を設定する。管理画面の運用通知メールテストは、transport が `none` の間は失敗するのが正常。

## 8. Smoke test

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
| cashier payment | 通常レジのカード/QR/電子マネーは店舗既存決済の完了後に記録のみ保存され、`gatewayName` が空、`paymentMethodDetail` が選択値で返る |
| register close | レジ締めで予想現金 / 実際現金 / 差額 / 現金売上 / カード売上 / QR・電子マネー売上が保存され、差額ありの場合は理由メモが必須になる |
| self checkout | Stripe Connect 設定ありで「お会計」表示、OFF/未設定では非表示 |
| takeout payment | テイクアウトはPOSLA決済（Stripe Connect）で決済完了後に注文反映される |

OP連携確認:

```bash
curl -sf https://<production-domain>/api/monitor/ping.php
curl -sf -H 'X-POSLA-OPS-SECRET: <secret-from-runtime>' https://<production-domain>/api/monitor/cell-snapshot.php
```

secretをコマンド履歴に残したくない場合は、Cloud Shellや運用端末の一時環境変数からheaderへ渡す。

## 9. Rollback

アプリrevisionだけの問題なら、Cloud Runで直前の正常revisionへtrafficを戻す。

```bash
gcloud run revisions list --service=posla-web --project=${PROJECT_ID} --region=${REGION}
gcloud run services update-traffic posla-web \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --to-revisions=<previous-revision>=100
```

imageを戻す場合:

```bash
ROLLBACK_IMAGE_URL=<previous-known-good-image>
gcloud run deploy posla-web \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${ROLLBACK_IMAGE_URL} \
  --platform=managed \
  --port=8080
```

DB migrationを伴う場合は、安易に自動rollbackしない。事前backupからの復旧、forward fix、影響テーブルの手動確認を選ぶ。今回の `p1-46`、`p1-47`、`p1-70` は追加カラム中心のため、基本はforward fix優先とする。

rollback後もOPで以下を確認する。

- production Sourceが `source_ok`
- Google Chatへ復旧通知または手動報告が届いている
- 管理画面、注文、KDS、レジ締め、テイクアウトのsmokeが通る

## 10. まだ外部設計が必要なもの

`scripts/cell/*` の cell provisioning は Docker Compose / host-side 実行前提が残る。Cloud Run web service から直接 Docker 作業はしないため、`POSLA_PROVISIONER_TRIGGER_URL` の先には専用VM上の `posla_provisioner` container を置く。

詳細は [provisioner-runner.md](./provisioner-runner.md) を正とする。

## 11. 参考

- Google Cloud: Deploying container images to Cloud Run - https://cloud.google.com/run/docs/deploying
- Google Cloud: Create Cloud Run jobs - https://cloud.google.com/run/docs/create-jobs
- Google Cloud: Execute Cloud Run jobs - https://cloud.google.com/run/docs/execute/jobs
- Google Cloud: Cloud Scheduler documentation - https://cloud.google.com/scheduler/docs
