# POSLA本体 本番デプロイ手順書 超詳細版

最終更新: 2026-05-03

この文書は、POSLA本体 `meal.posla.jp` を本番インフラへデプロイするための、作業者向けの完全手順書です。初めて担当する人でも、上から順に確認すればデプロイできることを目的にしています。

対象は POSLA本体です。OP、AI運用支援、監視、Google Chat通知は `posla-ops-platform` 側の手順書を使います。

実値の secret、password、API key、webhook URL、token はこの文書に書きません。実値は Google Cloud Secret Manager、Cloud Run env、VPS env、OP Settings、POSLA管理画面の設定に入れます。

## 0. この手順で扱うもの

| 対象 | 何をするか | 主な場所 |
|---|---|---|
| POSLA本体 web/API | Cloud Run serviceへデプロイ | `【本番環境】meal.posla.jp` |
| POSLA cron | Cloud Run Jobs + Cloud Schedulerで実行 | Google Cloud |
| DB | migration適用、backup確認 | Cloud SQL |
| Redis | session / rate limit維持 | Memorystore Redis / Valkey |
| uploads | menu画像などの永続化 | Cloud Storage bucket mount |
| OP連携 | OPがPOSLAをread-only監視できるようにする | OP Source設定 |
| Release Plan | Actionsや自動化がどのcellへdeployするか判断する情報 | POSLA管理画面 |
| Feature Flags | デプロイ後の機能表示範囲を制御する情報 | POSLA管理画面 |

この文書ではOP自体のVPSデプロイは扱いません。OPは `posla-ops-platform/docs/operations/op-vps-deploy-operator-step-by-step.md` を使います。

## 1. 絶対に守ること

| ルール | 理由 |
|---|---|
| 本番フォルダを直接編集しない | 本番は GitHub `main` から取り込むだけにする |
| `test` で確認してから `main` にpromoteする | 未検証commitを本番へ出さない |
| secretをGitに入れない | 漏えい防止 |
| DB migration前にbackupを取る | DB変更はrollbackが難しい |
| Cloud Run webでcronを動かさない | Web requestと定期処理を分離する |
| session/rate limitはRedisを使う | Cloud Run instanceが入れ替わってもログインが維持される |
| uploadsはCloud Storageに置く | container filesystemは永続化されない |
| デプロイ後はOPでSource監視を見る | POSLA自身ではなくOPを監視の正にする |
| Feature Flagsをdeploy先指定として使わない | Feature Flagsは「誰に見せるか」、Release Planは「どこへdeployするか」 |

## 2. デプロイの全体像

標準の順番は次です。

```text
1. OP本番が先に動いていることを確認する
2. POSLAテスト環境で最終確認する
3. test branchへcommit/pushする
4. testをmainへpromoteする
5. 本番working copyでgit pullする
6. Cloud Run imageをbuildする
7. imageをArtifact Registryへpushする
8. production DB backupを取る
9. DB migrationを適用する
10. Cloud Run serviceをdeployする
11. Cloud Run Jobs / Schedulerをdeployまたは更新する
12. 本番URLでsmokeする
13. OP Source監視でsource_okを確認する
14. Google Chat通知が動くことを確認する
15. rollback情報を記録する
```

OPを先に確認する理由は、POSLAデプロイ後の異常検知をOPへ集約するためです。OPが未稼働のままPOSLAを先に出すと、デプロイ後の確認が人間の目視だけになります。

## 3. 作業するフォルダ

POSLAの親フォルダには複数のworking copyがあります。

| フォルダ | 役割 | 編集 |
|---|---|---|
| `【テスト環境】meal.posla.jp` | 実装、確認、commit | する |
| `【本番環境】meal.posla.jp` | Cloud Run本番デプロイ用working copy | 直接編集しない |
| `【POSLA運用】posla-ops-platform` | OP/監視/AI運用 | POSLA本体のdeployでは編集しない |

作業開始時に必ず確認します。

```bash
cd "<POSLA>/【テスト環境】meal.posla.jp"
pwd
git status -sb
git branch --show-current
git log --oneline -1
```

期待値:

```text
branch: test
status: clean または自分がこれからcommitする変更だけ
```

本番working copyも確認します。

```bash
cd "<POSLA>/【本番環境】meal.posla.jp"
pwd
git status -sb
git branch --show-current
git log --oneline -1
```

期待値:

```text
branch: main
status: clean
```

本番working copyがdirtyならデプロイを止めます。本番フォルダで直接直しません。

## 4. 事前に決める値

作業前に次の値を決めます。値はこの文書に書かず、手元の一時メモやGoogle Cloud Console、Secret Manager、OP Settingsに入力します。

| 名前 | 説明 | 例 |
|---|---|---|
| `PROJECT_ID` | Google Cloud project ID | `<project-id>` |
| `REGION` | Cloud Run region | `asia-northeast1` |
| `REPOSITORY` | Artifact Registry repo | `<artifact-repo>` |
| `SERVICE` | Cloud Run service名 | `posla-web` |
| `PRODUCTION_DOMAIN` | 本番ドメイン | `meal.posla.jp` 等 |
| `CLOUD_SQL_INSTANCE` | Cloud SQL instance接続名 | `<project>:<region>:<instance>` |
| `UPLOADS_BUCKET` | uploads用Cloud Storage bucket | `<bucket-name>` |
| `REDIS_HOST` | Memorystore Redis host | `<redis-host>` |
| `OP_PUBLIC_URL` | OP本番URL | `https://<op-domain>` |
| `DEPLOY_COMMIT` | deployするGit commit | `git rev-parse --short HEAD` |

## 5. 必須secretと発行方法

secretは32文字以上のランダム値にします。POSLA管理画面の「設定センター > env Secret発行ヘルパー」で発行できます。CLIで発行する場合は次です。

```bash
openssl rand -hex 32
```

使う値:

| secret | 使う場所 | 用途 |
|---|---|---|
| `POSLA_DB_PASS` | Secret Manager / Cloud SQL | DB password |
| `POSLA_CRON_SECRET` | Cloud Run env / Cloud Run Jobs | cron / monitor-actions用 |
| `POSLA_OPS_READ_SECRET` | POSLA Cloud Run env、OP envまたはOP Source | OPがPOSLA snapshotを読む |
| `POSLA_OP_LAUNCH_SECRET` | POSLA Cloud Run env、OP VPS env | POSLA管理画面からOP sessionを発行する |
| `POSLA_PROVISIONER_TRIGGER_SECRET` | POSLA Cloud Run env、provisioner | cell provisioner呼び出し |
| `Release Plan Actions Token` | POSLA管理画面またはSecret Manager | GitHub ActionsがRelease Plan APIを読む |
| `障害報告 Token` | POSLA管理画面、OP Settings | 顧客障害報告をOPへ送る |

重要:

- `POSLA_OPS_READ_SECRET` は POSLA側とOP側で同じ値にします。
- `POSLA_OP_LAUNCH_SECRET` は POSLA側とOP側で同じ値にします。
- Release Plan Actions Tokenは、GitHub Actionsを使わない手動デプロイなら必須ではありません。
- SendGridは現在使いません。`POSLA_MAIL_TRANSPORT=none` を正とします。

## 6. POSLA管理画面で先に設定するもの

POSLA管理画面で設定できるものは、envに書かずUIを正にします。

| 設定 | 画面 | 説明 |
|---|---|---|
| OP画面URL | 設定センター / OP起動・障害報告 | POSLA管理画面からOPを開くURL |
| 障害報告 Endpoint | 設定センター / OP起動・障害報告 | 顧客からの障害報告をOPへ送るEndpoint |
| 障害報告 Token | 設定センター / OP起動・障害報告 | POSLAとOPで合わせるToken |
| Release Plan | Cell配備 / Release Plan | Actionsや自動化がdeploy対象を読む |
| Feature Flags | Feature Flags | デプロイ後、機能を誰に見せるかだけを制御 |
| メール送信元 | 設定センター | transportが`none`なら送信テスト失敗は正常 |

Release PlanとFeature Flagsは役割が違います。

| 項目 | 役割 | GitHub Actionsから見るか |
|---|---|---|
| Release Plan | どのcellへdeployするか | 見る |
| Feature Flags | deploy後に誰へ機能を見せるか | 原則見ない |

手動デプロイでCloud Run serviceを更新する場合、通常はCloud RunのPOSLA本体に同じコードが入ります。複数cellへ個別配備する運用では、Release Planまたは明示したcell listを使って、どのcellへ同じimageを展開するか決めます。

## 7. OP側の事前確認

POSLAをdeployする前にOPが監視できる状態か確認します。

OPのVPSまたはローカルOPで次を確認します。

```bash
curl -sf https://<op-domain>/api/health
curl -sf https://<op-domain>/api/ops-release-readiness
```

期待:

```text
api/health: ok:true
ops-release-readiness: release_ready
source_monitoring: pass
google_chat_delivery: pass
runner_api_public: false
```

OP画面では次を見ます。

| OP画面 | 見るもの |
|---|---|
| 今やること | routine以外の赤い未対応がない |
| Health / Source | POSLA production Sourceが `source_ok` |
| Settings | Google Chat `ready_to_send`、backup confirmed |
| Runner | Phase 1 read-only、execution off |

## 8. テスト環境の最終確認

POSLAテスト環境で実行します。

```bash
cd "<POSLA>/【テスト環境】meal.posla.jp"
git status -sb
git branch --show-current
curl -sf http://127.0.0.1:8081/api/monitor/ping.php
```

期待:

```text
ok:true
db:"ok"
environment:"test"
cron_lag_sec < 900
```

擬似本番smokeを実行します。

```bash
OWNER_PASS=Demo1234 POSLA_PASS=Demo1234 bash scripts/audit/06-pseudo-prod-release.sh
```

期待:

```text
Failures: 0
```

Cloud Run pre-deploy checkを実行します。

```bash
BASE_URL=http://127.0.0.1:8081 bash scripts/cloudrun/pre-deploy-check.sh
```

期待:

```text
failures: 0
```

この時点でFailがある場合はデプロイしません。原因を直して、再度最初から確認します。

## 9. testへcommit / mainへpromote

テスト環境で変更をcommitします。

```bash
cd "<POSLA>/【テスト環境】meal.posla.jp"
git status -sb
git add -A
git commit -m "<変更内容がわかるcommit message>"
git push meal.posla.jp test
```

検証済みcommitをmainへpromoteします。

```bash
git push meal.posla.jp test:main
git ls-remote meal.posla.jp refs/heads/test refs/heads/main
```

期待:

```text
refs/heads/test と refs/heads/main が同じcommit
```

## 10. 本番working copyへ取り込む

本番フォルダでは編集しません。Git pullだけを行います。

```bash
cd "<POSLA>/【本番環境】meal.posla.jp"
git status -sb
git branch --show-current
git pull --ff-only
git log --oneline -1
git status -sb
```

期待:

```text
branch: main
pull: Fast-forward または Already up to date
status: clean
```

dirtyな場合は止めます。`git reset --hard` は勝手に実行しません。

## 11. Cloud Run image build

本番working copyで実行します。

```bash
cd "<POSLA>/【本番環境】meal.posla.jp"

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

確認:

```bash
gcloud artifacts docker images list \
  ${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPOSITORY}/posla-web \
  --project=${PROJECT_ID}
```

期待:

```text
IMAGE_TAG のimageが存在する
```

## 12. Cloud Run service env

`docker/env/app.production.env.example` を基準に設定します。

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
POSLA_DEPLOY_VERSION=<git-sha>
POSLA_CRON_ENABLED=0
POSLA_SESSION_STORE=redis
POSLA_SESSION_REDIS_REQUIRED=1
POSLA_RATE_LIMIT_STORE=redis
POSLA_UPLOADS_DIR=/var/www/html/uploads
POSLA_UPLOADS_PUBLIC_PREFIX=uploads
POSLA_MAIL_TRANSPORT=none
```

DB:

```dotenv
POSLA_DB_HOST=<cloud-sql-private-ip-or-host>
POSLA_DB_SOCKET=
POSLA_DB_NAME=<production-db-name>
POSLA_DB_USER=<production-db-user>
POSLA_DB_PASS=<Secret Manager secret>
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
POSLA_OPS_READ_SECRET=<same-as-op>
POSLA_OP_LAUNCH_SECRET=<same-as-op>
POSLA_OP_CASE_ENDPOINT=https://<op-domain>/api/ingest/posla-case
POSLA_OP_CASE_TOKEN=
```

`POSLA_OP_CASE_TOKEN` はUI保存を優先します。envは固定運用時のfallbackです。

## 13. Cloud Run service deploy

最小例:

```bash
gcloud run deploy ${SERVICE} \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --image=${IMAGE_URL} \
  --platform=managed \
  --port=8080
```

本番公開方法はインフラ設計に従います。

| 公開方式 | 説明 |
|---|---|
| Cloud Run直接公開 | `--allow-unauthenticated` を使う |
| Load Balancer配下 | Cloud Runは必要に応じて認証付きにし、LB側で公開 |
| IAP / Cloud Armor | 管理画面や内部面を追加で守る |

公開前に確認:

```bash
gcloud run services describe ${SERVICE} \
  --project=${PROJECT_ID} \
  --region=${REGION}
```

見るもの:

```text
image: 今pushしたIMAGE_URL
port: 8080
env: production値
uploads mount: 設定済み
Cloud SQL connection: 設定済み
VPC connector: Redis/DBに必要なら設定済み
```

## 14. DB backup

DB migration前にbackupを取ります。

Cloud SQLのbackup例:

```bash
gcloud sql backups create \
  --project=${PROJECT_ID} \
  --instance=<cloud-sql-instance-name>
```

backup確認:

```bash
gcloud sql backups list \
  --project=${PROJECT_ID} \
  --instance=<cloud-sql-instance-name> \
  --limit=5
```

期待:

```text
直近backupが成功状態
```

backupが取れない場合、DB migrationへ進みません。

## 15. DB migration

migrationは本番DBへ適用するため、必ず対象DBを確認します。

実行前確認:

```bash
echo "$PROJECT_ID"
echo "$REGION"
echo "$CLOUD_SQL_INSTANCE"
echo "$POSLA_DB_NAME"
```

Cloud SQLへ接続できる端末で、未適用migrationを適用します。具体的な接続方法はCloud SQL運用方針に合わせます。

例:

```bash
mysql \
  --host=<db-host> \
  --user=<db-user> \
  --password \
  <production-db-name> < sql/migration-p1-46-register-payment-detail.sql

mysql \
  --host=<db-host> \
  --user=<db-user> \
  --password \
  <production-db-name> < sql/migration-p1-47-register-close-reconciliation.sql

mysql \
  --host=<db-host> \
  --user=<db-user> \
  --password \
  <production-db-name> < sql/migration-p1-70-terminal-monitoring-policy.sql
```

確認:

```sql
SHOW COLUMNS FROM payments LIKE 'payment_method_detail';
SHOW COLUMNS FROM orders LIKE 'payment_method_detail';
SHOW COLUMNS FROM cash_log LIKE 'expected_amount';
SHOW COLUMNS FROM handy_terminals LIKE 'monitoring_enabled';
SHOW COLUMNS FROM handy_terminals LIKE 'operational_status';
SHOW COLUMNS FROM handy_terminals LIKE 'monitor_business_hours_only';
```

migration失敗時:

1. Cloud Run service deployを止める
2. エラー文を保存する
3. backupから戻す必要があるか判断する
4. OPに障害報告が上がる場合は調査caseを作る

## 16. Cloud Run Jobs

Web serviceではcronを動かしません。Cloud Run Jobsを使います。

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
gcloud run jobs execute posla-cron-every-5-minutes \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --wait

gcloud run jobs execute posla-cron-hourly \
  --project=${PROJECT_ID} \
  --region=${REGION} \
  --wait
```

期待:

```text
Execution completed successfully
```

Cloud Schedulerは、Cloud Run Jobs実行APIを呼びます。Scheduler専用service accountを使い、公開cron endpointを増やしません。

## 17. 本番URL smoke

本番deploy直後に実行します。

```bash
PRODUCTION_DOMAIN=<production-domain>

curl -i https://${PRODUCTION_DOMAIN}/
curl -i https://${PRODUCTION_DOMAIN}/api/monitor/ping.php
curl -i https://${PRODUCTION_DOMAIN}/posla-admin/dashboard.html
curl -i https://${PRODUCTION_DOMAIN}/docs-internal/internal/09-pwa.html
```

期待:

| URL | 期待 |
|---|---|
| `/` | HTTP 200 |
| `/api/monitor/ping.php` | HTTP 200、`ok:true`、`db:"ok"` |
| `/posla-admin/dashboard.html` | HTTP 200 |
| `/docs-internal/...` | HTTP 403または404 |

HSTS確認:

```bash
curl -I https://${PRODUCTION_DOMAIN}/ | grep -i strict-transport-security
```

期待:

```text
strict-transport-security: max-age=31536000; includeSubDomains
```

## 18. POSLA管理画面 smoke

ブラウザで確認します。

1. `https://<production-domain>/posla-admin/dashboard.html` を開く
2. POSLA管理者でログインする
3. ダッシュボードが表示される
4. 設定センターを開く
5. OP画面URL、障害報告Endpoint、障害報告Tokenが設定済みであることを見る
6. Release Planを開く
7. deploy対象が意図どおりであることを見る
8. Feature Flagsを開く
9. 顧客向け機能の表示対象が意図どおりであることを見る

重要:

- Release Planが未設定なら、Actions自動deployは止める
- Feature Flags未設定でもコードdeploy自体はできます
- Feature Flagsはdeploy対象ではありません

## 19. OP Source監視確認

OPでproduction Sourceを登録または更新します。

Source設定:

| 項目 | 入れるもの |
|---|---|
| label | `POSLA production` など |
| base URL | `https://<production-domain>` |
| ping URL | `https://<production-domain>/api/monitor/ping.php` |
| snapshot URL | `https://<production-domain>/api/monitor/cell-snapshot.php` |
| auth type | `ops_read_secret` |
| read secret | POSLA Cloud Run envの `POSLA_OPS_READ_SECRET` と同じ値 |

OP画面で実行:

1. Settings / POSLA接続を開く
2. Sourceを登録または更新
3. Pingを実行
4. Snapshotを実行
5. Healthを開く

期待:

```text
source_ok
failed_count: 0
ping_failed_count: 0
snapshot_failed_count: 0
business_attention_count: 0
```

OP release readiness:

```bash
curl -sf https://<op-domain>/api/ops-release-readiness
```

期待:

```text
status: release_ready
block: 0
```

## 20. 業務smoke

最低限、次を確認します。

| 分類 | 操作 | 期待 |
|---|---|---|
| 管理画面 | POSLA管理画面ログイン | ログインできる |
| テナント | 対象テナントを開く | healthが異常ではない |
| 店舗管理 | 管理画面ログイン | ログインできる |
| 顧客メニュー | メニュー表示 | 商品が表示される |
| 注文 | 顧客注文 | KDSまたは注文一覧に出る |
| 会計 | レジ会計 | 決済情報が保存される |
| レジ締め | レジ締め保存 | 予想現金、実際現金、差額が保存される |
| 予約 | 予約画面表示 | 予約フォームが表示される |
| テイクアウト | テイクアウト画面表示 | 商品が表示される |
| uploads | 画像URL表示 | `/uploads/...` が200 |
| session | 再デプロイ後 | ログインが維持される |

## 21. Google Chat通知確認

Google Chat通知はOP側で行います。POSLA側では直接Google Chat設定を持ちません。

確認:

1. OP SettingsでGoogle Chatが `ready_to_send`
2. `send_enabled=true`
3. `dry_run=false`
4. Source Health smokeまたはテスト通知がHTTP 200
5. Google Chatスペースに通知が届く

POSLA側のメール送信元テストは、`POSLA_MAIL_TRANSPORT=none` の間は失敗するのが正常です。SendGridは使いません。

## 22. Release Plan / Actions

Actionsを使う場合だけ、この節を実施します。

Release Plan API:

```text
GET /api/deploy/release-plan.php
Authorization: Bearer <Release Plan Actions Token>
```

Actions側の想定:

1. POSLA Release Plan APIを読む
2. `deploy_allowed` を確認する
3. `target_scope` を確認する
4. `target_cell_ids` へdeployする
5. Feature Flagsは変更しない

Release Planの意味:

| `target_scope` | 意味 |
|---|---|
| `single_cell` | 保存済みの1 cellへdeploy |
| `all_active_cells` | active cellすべてへ同じコードをdeploy |

`automation_mode`:

| 値 | 意味 |
|---|---|
| `manual_only` | Actionsはdeployしない |
| `actions_allowed` | ActionsがRelease Planに従ってdeployしてよい |

手動デプロイの場合:

- Cloud Run service更新は人間が明示して行う
- 複数cellへ展開する場合は、Release Planまたは作業指示に従って同じimageを展開する
- Feature Flagsはdeploy後の表示制御に使う

## 23. rollback

アプリrevisionだけ戻す場合:

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

rollback後に確認:

```bash
curl -sf https://${PRODUCTION_DOMAIN}/api/monitor/ping.php
```

DB migrationを伴う場合:

1. アプリrevision rollbackだけで戻せるか判断する
2. DBが新旧両方のコードに対応できるならrevisionだけ戻す
3. DBを戻す必要がある場合はbackup restore手順を使う
4. OPに障害報告caseを作る

## 24. デプロイ記録

デプロイ後、次を記録します。secret値は記録しません。

```text
日時:
作業者:
POSLA commit:
Cloud Run image:
Cloud Run revision:
DB backup id:
migration:
OP Source:
smoke結果:
rollback先revision:
残課題:
```

## 25. 失敗したら止める場所

| 失敗 | 止めるか | 対応 |
|---|---:|---|
| test smoke Fail | 止める | 修正して再確認 |
| git status dirty | 止める | 変更者を確認 |
| docker build失敗 | 止める | build error修正 |
| image push失敗 | 止める | Artifact Registry/IAM確認 |
| DB backup失敗 | 止める | backup成功まで進まない |
| migration失敗 | 止める | service切替しない |
| Cloud Run deploy失敗 | 止める | revision/log確認 |
| ping `ok:false` | 止める | DB/env/cron確認 |
| OP snapshot失敗 | 本番監視NG | read secret、snapshot URL、OP Source確認 |
| Google Chat不達 | 監視通知NG | OP Settings、webhook、dry-run確認 |

## 26. 最終チェックリスト

デプロイ開始前:

- [ ] OP本番が起動している
- [ ] OP Google Chatが `ready_to_send`
- [ ] POSLAテスト環境 smoke Fail 0
- [ ] `test` と `main` が同じcommit
- [ ] 本番working copyが clean
- [ ] `meal.posla.jp` など本番ドメインがDNS解決できる
- [ ] TLS証明書が有効
- [ ] Secret Manager / Cloud Run envが設定済み
- [ ] Cloud SQL backupが取れる
- [ ] RedisがCloud Runから到達できる
- [ ] uploads bucket mountが設定済み
- [ ] `POSLA_OPS_READ_SECRET` がPOSLA/OPで一致
- [ ] `POSLA_OP_LAUNCH_SECRET` がPOSLA/OPで一致
- [ ] Release Plan TokenはActions利用時だけ設定済み

デプロイ後:

- [ ] `/api/monitor/ping.php` が `ok:true`
- [ ] `/docs-internal/...` が403または404
- [ ] HSTS headerあり
- [ ] POSLA管理画面へログインできる
- [ ] OP Sourceが `source_ok`
- [ ] Google Chatへ通知できる
- [ ] Cloud Run Jobsが成功
- [ ] rollback先revisionを記録済み
