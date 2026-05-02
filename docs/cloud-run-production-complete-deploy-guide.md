# POSLA Cloud Run Production Deploy Complete Guide

最終更新: 2026-05-02

この文書は、POSLA本体 `meal.posla.jp` をGoogle Cloud Run本番へデプロイするための超詳細手順書です。作業場所、Git反映、GCPリソース、image build、Cloud Run service、Cloud Run Jobs、DB migration、OP連携、smoke、rollbackまでを1本で扱います。

secret、password、API key、tokenの実値はこの文書に書きません。実値はGoogle Cloud Secret Manager、Cloud Run env、Cloud SQL、Memorystore、Cloud Storage、OP Settingsで管理します。

## 1. 絶対ルール

| ルール | 理由 |
|---|---|
| 本番フォルダを直接編集しない | 本番はGitHub `main` またはrelease tagからだけ取り込む |
| テスト未検証commitを本番へ出さない | デグレ防止 |
| secretをGitへ入れない | 漏えい防止 |
| migration前にDB backupを取る | rollback不能リスクを下げる |
| Cloud Run webではcronを動かさない | Cloud Run Jobs + Schedulerで分離する |
| session/rate limitはRedisを使う | Cloud Run instance再作成でも状態を維持する |
| uploadsはCloud Storage mountを使う | container filesystemを永続化しない |
| デプロイ後はOPからping/snapshotを見る | POSLA本体だけでなく運用監視まで確認する |

## 2. 作業場所とbranch

| 場所 | フォルダ | branch | 作業 |
|---|---|---|---|
| 実装・検証 | `【テスト環境】meal.posla.jp` | `test` | 実装、Docker確認、pre-deploy check |
| 本番作業copy | `【本番環境】meal.posla.jp` | `main` | `git pull`、build、deploy |
| GitHub | `pb-oda/meal.posla.jp` | `test` / `main` | `test`を検証後に`main`へpromote |

標準フロー:

```text
1. testで実装
2. Docker / smoke / UI確認
3. testへcommit/push
4. testをmainへpromote
5. 本番フォルダでgit pull
6. Cloud Run image build / push
7. DB migration
8. Cloud Run service deploy
9. Cloud Run Jobs / Scheduler確認
10. OPからproduction Sourceを確認
```

## 3. 必要なGCPリソース

| リソース | 用途 | 必須 |
|---|---|---:|
| Google Cloud project | POSLA productionの親 | 必須 |
| Artifact Registry Docker repo | Cloud Run image保存 | 必須 |
| Cloud Run service `posla-web` | Web/API本体 | 必須 |
| Cloud SQL for MySQL | POSLA production DB | 必須 |
| Memorystore Redis / Valkey | session / rate limit | 必須 |
| Cloud Storage bucket | `/var/www/html/uploads` mount | 必須 |
| Secret Manager | DB password、SendGrid、OP token等 | 必須 |
| Cloud Run Jobs | cron処理 | 必須 |
| Cloud Scheduler | Cloud Run Jobsの定期実行 | 必須 |
| SendGrid | メール送信 | 必須 |
| VPC / Serverless VPC Access | private IP Redis/DBを使う場合 | 構成次第 |
| Domain / HTTPS | production domain | 必須 |

## 4. GCP側で先に決める値

この表を埋めてからdeployへ進みます。実値はこの文書へ書きません。

| 変数 | 例 | 保存先 |
|---|---|---|
| `PROJECT_ID` | `<project-id>` | gcloud config / shell |
| `REGION` | `asia-northeast1`など | gcloud config / shell |
| `REPOSITORY` | `<artifact-repo>` | Artifact Registry |
| `SERVICE` | `posla-web` | Cloud Run |
| `IMAGE_TAG` | Git short SHA | build時に生成 |
| `IMAGE_URL` | `${REGION}-docker.pkg.dev/...` | shell |
| `PRODUCTION_DOMAIN` | `<production-domain>` | DNS / Cloud Run custom domain等 |
| `CLOUD_SQL_INSTANCE` | `<project>:<region>:<instance>` | Cloud SQL |
| `UPLOADS_BUCKET` | `<bucket-name>` | Cloud Storage |
| `REDIS_HOST` | `<memorystore-host>` | Cloud Run env |
| `OP_PUBLIC_URL` | `<op-domain>` | Cloud Run env / OP Settings |

## 5. IAMと権限

デプロイ担当者またはCIには最低限次が必要です。

| 権限 | 用途 |
|---|---|
| Cloud Run Developer | service / job deploy |
| Service Account User | Cloud Run実行service accountを使う |
| Artifact Registry Writer | image push |
| Artifact Registry Reader | Cloud Runがimageを読む |
| Secret Manager Secret Accessor | Cloud Run runtimeがsecretを読む |
| Cloud SQL Client | Cloud SQL接続 |
| Storage Object Adminまたは限定権限 | uploads bucketの読み書き |
| Cloud Scheduler Admin | scheduler作成/更新 |

Cloud Runの実行service accountには、runtimeに必要な権限だけを付けます。デプロイ担当者の権限とruntime service accountの権限を混ぜないでください。

## 6. Secret Manager設計

Secret Managerへ置く値の例です。実値はGitに書きません。

| Secret名例 | Cloud Run env | 用途 |
|---|---|---|
| `posla-db-pass` | `POSLA_DB_PASS` | production DB password |
| `posla-cron-secret` | `POSLA_CRON_SECRET` | cron endpoint secret |
| `posla-ops-read-secret` | `POSLA_OPS_READ_SECRET` | OP snapshot read secret |
| `posla-sendgrid-api-key` | `POSLA_SENDGRID_API_KEY` | SendGrid |
| `posla-op-alert-token` | `POSLA_OP_ALERT_TOKEN` | OP alert ingest |
| `posla-provisioner-trigger-secret` | `POSLA_PROVISIONER_TRIGGER_SECRET` | cell provisioner |

Secretは環境変数として渡してもよいですが、rotation方針を決めておきます。Google CloudはCloud RunでSecret Manager secretをenvまたはvolumeとして使えます。envの場合はinstance起動時に解決されるため、version指定の方が運用上安全です。

## 7. 本番フォルダの準備

本番フォルダでは編集しません。Gitから取り込みます。

```bash
cd "<POSLA>/【本番環境】meal.posla.jp"
git status -sb
git branch --show-current
git pull --ff-only
git log --oneline -1
```

期待値:

```text
branch: main
status: clean
HEAD: deploy対象commit
```

dirtyな場合はdeployを止めます。直接編集で直しません。

## 8. デプロイ前ローカル確認

テスト環境Dockerが起動している場合:

```bash
cd "<POSLA>/【テスト環境】meal.posla.jp"
BASE_URL=http://127.0.0.1:8081 bash scripts/cloudrun/pre-deploy-check.sh
curl -sf http://127.0.0.1:8081/api/monitor/ping.php
```

本番フォルダで構文とbuildを確認:

```bash
cd "<POSLA>/【本番環境】meal.posla.jp"
bash -n docker/php/startup-cloudrun.sh
bash -n docker/php/cron-job-cloudrun.sh
docker build -f docker/php/Dockerfile.cloudrun -t posla-web:predeploy .
```

ローカルruntime smokeを行う場合:

```bash
docker run --rm \
  --name posla-cloudrun-predeploy \
  -p 18080:8080 \
  --env-file "<local-test-env-file>" \
  -e PORT=8080 \
  -e POSLA_CRON_ENABLED=0 \
  posla-web:predeploy
```

別terminal:

```bash
curl -sf http://127.0.0.1:18080/api/monitor/ping.php
```

確認:

```text
HTTP 200
ok:true
db:"ok"
environment:"test"または検証用値
```

## 9. image build / push

Cloud Runに出すimage tagはGit commitを含めます。

```bash
PROJECT_ID=<project-id>
REGION=<region>
REPOSITORY=<artifact-repo>
SERVICE=posla-web
IMAGE_TAG=$(git rev-parse --short HEAD)
IMAGE_URL=${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPOSITORY}/posla-web:${IMAGE_TAG}

gcloud config set project ${PROJECT_ID}
gcloud auth configure-docker ${REGION}-docker.pkg.dev
docker build -f docker/php/Dockerfile.cloudrun -t ${IMAGE_URL} .
docker push ${IMAGE_URL}
```

push後にimageを確認:

```bash
gcloud artifacts docker images list \
  ${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPOSITORY}/posla-web \
  --project=${PROJECT_ID}
```

## 10. production env

`docker/env/app.production.env.example` を基準に、Cloud Run env / Secret Managerへ設定します。

必須:

```dotenv
TZ=Asia/Tokyo
POSLA_ENV=production
POSLA_ENVIRONMENT=production
POSLA_APP_BASE_URL=https://<production-domain>
POSLA_ALLOWED_ORIGINS=https://<production-domain>
POSLA_ALLOWED_HOSTS=<production-domain>
POSLA_CELL_ID=posla-control
POSLA_OPS_SOURCE_ID=posla-control
POSLA_DEPLOY_VERSION=<git-sha-or-image-tag>
POSLA_CRON_ENABLED=0
POSLA_SESSION_STORE=redis
POSLA_SESSION_REDIS_REQUIRED=1
POSLA_RATE_LIMIT_STORE=redis
POSLA_UPLOADS_DIR=/var/www/html/uploads
POSLA_UPLOADS_PUBLIC_PREFIX=uploads
POSLA_MAIL_TRANSPORT=sendgrid
```

DB:

```dotenv
POSLA_DB_HOST=<private-ip-or-host>
POSLA_DB_SOCKET=
POSLA_DB_NAME=<production-db-name>
POSLA_DB_USER=<production-db-user>
POSLA_DB_PASS=<secret>
```

Cloud SQL Unix socketを使う場合:

```dotenv
POSLA_DB_HOST=
POSLA_DB_SOCKET=/cloudsql/<project>:<region>:<instance>
```

Redis:

```dotenv
POSLA_REDIS_HOST=<memorystore-host>
POSLA_REDIS_PORT=6379
POSLA_REDIS_DATABASE=0
POSLA_REDIS_SESSION_PREFIX=posla:posla-control:sess:
POSLA_REDIS_RATE_LIMIT_PREFIX=posla:posla-control:rate:
```

OP連携:

```dotenv
POSLA_OPS_READ_SECRET=<secret>
POSLA_OP_ALERT_ENDPOINT=https://<op-domain>/api/ingest/posla-alert
POSLA_OP_ALERT_TOKEN=<secret>
```

## 11. Cloud Run service deploy

最小例:

```bash
gcloud run deploy ${SERVICE} \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${IMAGE_URL} \
  --platform=managed \
  --port=8080 \
  --no-allow-unauthenticated
```

Cloud SQL接続を使う場合:

```bash
gcloud run deploy ${SERVICE} \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${IMAGE_URL} \
  --platform=managed \
  --port=8080 \
  --add-cloudsql-instances=${CLOUD_SQL_INSTANCE} \
  --no-allow-unauthenticated
```

Cloud Storage bucketを `/var/www/html/uploads` にmountする場合:

```bash
gcloud run services update ${SERVICE} \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --execution-environment=gen2 \
  --add-volume=name=posla-uploads,type=cloud-storage,bucket=${UPLOADS_BUCKET} \
  --add-volume-mount=volume=posla-uploads,mount-path=/var/www/html/uploads
```

外部公開する場合は、公開方式を事前に決めます。

| 方式 | 用途 |
|---|---|
| `--allow-unauthenticated` | 一般公開WebとしてCloud Runを直接公開 |
| Cloud Load Balancer + Cloud Armor | WAF/IP制限/独自TLS制御をしたい場合 |
| IAP / IAM | 管理系など認証付きにしたい場合 |

POSLA本体の顧客向けWebは公開が必要ですが、管理画面や内部APIの認可はアプリ側・ネットワーク側で別途確認します。

## 12. DB migration

Cloud Run service切替前に、本番DB backupを取ります。

最低限必要なmigration:

| SQL | 目的 |
|---|---|
| `sql/migration-p1-46-register-payment-detail.sql` | 通常レジの支払い詳細 |
| `sql/migration-p1-47-register-close-reconciliation.sql` | レジ締め差額、支払い別売上 |
| `sql/migration-p1-70-terminal-monitoring-policy.sql` | OP監視向け端末policy |

適用前:

```bash
mysql --host=<host> --user=<user> --database=<db> --execute="SELECT DATABASE();"
```

適用:

```bash
mysql --host=<host> --user=<user> --database=<db> < sql/migration-p1-46-register-payment-detail.sql
mysql --host=<host> --user=<user> --database=<db> < sql/migration-p1-47-register-close-reconciliation.sql
mysql --host=<host> --user=<user> --database=<db> < sql/migration-p1-70-terminal-monitoring-policy.sql
```

確認:

```sql
SELECT COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'handy_terminals'
  AND COLUMN_NAME IN ('monitoring_enabled', 'operational_status', 'monitor_business_hours_only');
```

失敗した場合はCloud Run service切替を止めます。migrationを伴うrollbackは自動で行いません。

## 13. Cloud Run Jobs

Web serviceでは `POSLA_CRON_ENABLED=0` にします。cronはCloud Run Jobsで分離します。

5分間隔job:

```bash
gcloud run jobs deploy posla-cron-every-5-minutes \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${IMAGE_URL} \
  --command=/usr/local/bin/posla-cron-job-cloudrun.sh \
  --set-env-vars=POSLA_CRON_TASK=every-5-minutes
```

1時間間隔job:

```bash
gcloud run jobs deploy posla-cron-hourly \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${IMAGE_URL} \
  --command=/usr/local/bin/posla-cron-job-cloudrun.sh \
  --set-env-vars=POSLA_CRON_TASK=hourly
```

初回実行:

```bash
gcloud run jobs execute posla-cron-every-5-minutes --project=${PROJECT_ID} --region=${REGION} --wait
gcloud run jobs execute posla-cron-hourly --project=${PROJECT_ID} --region=${REGION} --wait
```

Cloud Schedulerは上記jobsを定期実行するように設定します。Schedulerの実行service accountは専用にし、必要権限だけを付与します。

## 14. デプロイ直後smoke

production domain:

```bash
curl -sf https://<production-domain>/api/monitor/ping.php
```

期待:

```text
ok:true
db:"ok"
environment:"production"
```

OP read secret付きsnapshot:

```bash
OPS_READ_SECRET=<secret>
curl -sf \
  -H "X-POSLA-OPS-SECRET: ${OPS_READ_SECRET}" \
  https://<production-domain>/api/monitor/cell-snapshot.php
```

snapshotで見る項目:

- `reservations`
- `shifts`
- `takeout`
- `menu_inventory`
- `customer_line`
- `external_integrations`
- `terminal_heartbeat`
- `kds`
- `register`
- `tier0`

## 15. 業務smoke

最低限、次を人間が確認します。

| 項目 | 確認 |
|---|---|
| 管理者ログイン | ログイン、セッション維持 |
| メニュー画像 | upload後、`/uploads/menu/...` で表示 |
| 顧客注文 | メニュー表示、注文作成 |
| KDS | 注文が表示される |
| レジ | 会計確定、支払い詳細保存 |
| レジ締め | 予想現金、実際現金、差額、メモ |
| テイクアウト | 決済、注文反映、通知 |
| 予約 | 予約作成、通知、リマインド |
| シフト | 打刻、退勤、自動退勤 |
| メール | SendGridで運用通知テスト |

## 16. OP連携確認

OP側でproduction Sourceを作成または更新します。

| Source項目 | 値 |
|---|---|
| environment | `production` |
| base url | `https://<production-domain>` |
| ping url | `https://<production-domain>/api/monitor/ping.php` |
| snapshot url | `https://<production-domain>/api/monitor/cell-snapshot.php` |
| auth type | `ops_read_secret` |

OPで実行:

```bash
php workers/probe-worker/worker.php --limit 10
php workers/notification-worker/worker.php --limit 10
php scripts/ops-release-readiness.php --format=markdown
```

OP画面で確認:

- Source Healthが `source_ok`
- ping最終時刻が更新される
- snapshot最終時刻が更新される
- 異常タブに不要なテスト端末heartbeatが出ない
- Google Chat通知が必要な時だけ届く

## 17. rollback

### 17.1 revision rollback

Cloud Run revisionだけを戻す場合:

```bash
gcloud run revisions list \
  --service=${SERVICE} \
  --project=${PROJECT_ID} \
  --region=${REGION}

gcloud run services update-traffic ${SERVICE} \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --to-revisions=<previous-revision>=100
```

### 17.2 image rollback

既知の正常imageへdeployし直す場合:

```bash
ROLLBACK_IMAGE_URL=<previous-known-good-image>
gcloud run deploy ${SERVICE} \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${ROLLBACK_IMAGE_URL} \
  --platform=managed \
  --port=8080
```

### 17.3 DB migration後のrollback

DB migrationを伴う場合は、安易に自動rollbackしません。

選択肢:

| 選択 | 使う場面 |
|---|---|
| forward fix | 追加カラム中心でデータ破壊がない場合 |
| revision rollback + DBそのまま | 旧appが追加カラムを無視できる場合 |
| backup restore | データ破壊や整合性崩れがある場合 |
| 手動補正 | 限定的なデータ不整合の場合 |

rollback後もOPでproduction SourceとGoogle Chat通知を確認します。

## 18. 作業完了条件

以下が全部OKならdeploy完了です。

- Cloud Run service revisionが最新imageを使っている
- production pingが `ok:true`
- production snapshotがOP read secret付きで取得できる
- DB migration確認SQLがOK
- Cloud Run Jobsが手動実行OK
- Schedulerが次回実行予定を持つ
- 管理者ログインOK
- 注文/KDS/レジ/テイクアウト/予約/シフトsmoke OK
- uploads表示OK
- SendGrid送信OK
- OP Source Healthが `source_ok`
- Google Chat通知確認OK
- rollback先revision/imageが記録されている

## 19. 参考

- Google Cloud: Deploying container images to Cloud Run - https://cloud.google.com/run/docs/deploying
- Google Cloud: Artifact Registry and Cloud Run - https://cloud.google.com/artifact-registry/docs/integrate-cloud-run
- Google Cloud: Create Cloud Run jobs - https://cloud.google.com/run/docs/create-jobs
- Google Cloud: Execute Cloud Run jobs - https://cloud.google.com/run/docs/execute/jobs
- Google Cloud: Cloud Storage volume mounts for Cloud Run - https://cloud.google.com/run/docs/configuring/services/cloud-storage-volume-mounts
- Google Cloud: Cloud Scheduler documentation - https://cloud.google.com/scheduler/docs
