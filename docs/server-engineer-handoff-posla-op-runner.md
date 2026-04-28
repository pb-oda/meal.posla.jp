# サーバエンジニア引き渡し資料 POSLA / OP / runner

作成日: 2026-04-28
対象: Cloud Run 本番デプロイ直前の引き渡し

## 1. この資料の目的

サーバエンジニアへ、POSLA本体、OP、runner、provisioner runner の役割と渡すコードを明確にするための引き渡し資料です。

この資料で扱うもの:

- POSLA本体: 飲食店が使うweb/API
- provisioner runner: 顧客cellをVM上に自動作成する専用runner
- OP: POSLAを監視・調査する内部運用画面
- runner: OPから呼ばれ、コード・DB・ログをread-only調査する実行基盤

OP alert / case から runner、Codex CLI までのE2E確認は、別紙 `docs/op-runner-codex-e2e-runbook.md` を正にする。

この資料で扱わないもの:

- secret実値
- 本番DB password
- API key
- 実顧客データ
- uploads実ファイル
- 既存cellの実行済みデータ

## 2. 渡す実フォルダ

以下3つを、役割ごとに分けて渡す。

```text
/Users/odahiroki/oda@plusbelief.co.jp - Google Drive/共有ドライブ/05_infra/※開発関連/POSLA/【擬似本番環境】meal.posla.jp
```

POSLA本体。Cloud Run用コード、provisioner runner、cell作成コードを含む。

```text
/Users/odahiroki/oda@plusbelief.co.jp - Google Drive/共有ドライブ/05_infra/※開発関連/POSLA/【AI運用支援】codex-ops-platform
```

OP。内部運用画面、外部監視、障害報告受信、runner連携を担当する。

```text
/Users/odahiroki/oda@plusbelief.co.jp - Google Drive/共有ドライブ/05_infra/※開発関連/POSLA/【エージェント】runner.posla.jp
```

runner。OPから呼ばれるread-only調査実行基盤。

## 3. 渡さないフォルダ

以下は本番新規デプロイには渡さない。

```text
/Users/odahiroki/oda@plusbelief.co.jp - Google Drive/共有ドライブ/05_infra/※開発関連/POSLA/【デモ環境】meal.posla.jp
/Users/odahiroki/oda@plusbelief.co.jp - Google Drive/共有ドライブ/05_infra/※開発関連/POSLA/BACUP
```

デモ環境はsandbox実体であり、本番新規構築の正本ではない。

## 4. 推奨の渡し方

第一候補は Git repository / Git branch で渡す。

理由:

- 差分管理できる
- rollbackしやすい
- サーバ上で `git pull --ff-only` が使える
- zip転送時の漏れやsecret混入を避けやすい

zip等で渡す場合は、以下を除外する。

### POSLA本体で除外するもの

```text
docker/env/app.env
docker/env/db.env
docker/env/provisioner.env
cells/
uploads/
.DS_Store
```

exampleファイルは渡す。

```text
docker/env/app.production.env.example
docker/env/provisioner.env.example
```

### OPで除外するもの

```text
env/ops-agent.env
data/*.json
.DS_Store
```

exampleファイルは渡す。

```text
env/ops-agent.env.example
data/*.example.json
```

`data/*.json` は実行時データなので除外する。ただし `data/*.example.json` は初期化テンプレートとして渡す。

### runnerで除外するもの

```text
env/runner.env
codex-home/
workspace/
data/codex-control.json
.DS_Store
```

exampleファイルは渡す。

```text
env/runner.env.example
```

## 5. 本番の配置単位

推奨配置:

```text
<deploy-root>/
  meal.posla.jp/          POSLA本体
  op.posla.jp/            OP
  runner.posla.jp/        runner
  backups/                backup退避先
  logs/                   reverse proxy / job / deploy log
```

Cloud Run前提の配置:

```text
Cloud Run: POSLA web/API
Cloud Run Jobs or Scheduler: POSLA cron
Memorystore: session / rate limit
Cloud SQL: POSLA control DB
GCS: uploads
GCE VM: provisioner runner
GCE VM or private container host: OP / runner
```

runnerは外部公開しない。OPからprivate / same-hostで呼ぶ。
Phase 1 の OP / runner は runtime data、read-only source mount、runner専用 Codex HOME を持つため、まずはVMまたはprivate container hostが現実的。Cloud Runへ載せる場合は、runtime data永続化とsource参照方式を別途設計する。

## 5.0 Redis / Memorystore の方針

Redisは session / rate limit の共有先として使う。

POSLA本体はCloud Runで確定。したがって、POSLA web/API の session / rate limit 用Redisは **Memorystore Redis / Valkey** を採用する。

方針:

| 構成 | Redisの置き場所 | 判断 |
|---|---|---|
| POSLA web/API | Memorystore Redis / Valkey | 採用。Cloud Runからprivate接続する |
| Cloud Run webからVPS同居Redisを使う | 採用しない | private接続・監視・可用性の設計負荷が高い |
| OP / runner / provisionerだけをVPSに置く | Redis同居は必須ではない | POSLA webのsession用途にはならない |

サーバエンジニアの「VPSならRedis同居でよい」という提案は、POSLA webもVPSで動かす場合は妥当。
ただし今回はPOSLA本体がCloud Run確定なので、POSLA本体用Redisとしては採用しない。
VPSは provisioner / OP / runner の配置先として使う可能性があり、その場合のRedis同居はPOSLA sessionとは別論点にする。

やってはいけないこと:

- Redis portをpublic internetへ開ける
- Cloud RunからVPS上Redisへpublic IP経由で接続する
- Redis passwordをGit / docs / OP UIに保存する
- 本番でRedis永続化・backup・監視なしにsession正本として扱う

## 5.1 GitHub / Artifact Registry / GitLab の役割

本番では次の役割分担にする。

| 置き場 | 役割 | 本番での扱い |
|---|---|---|
| GitHub | コードの正本 | 継続利用する。サーバエンジニアはGitHubからclone/fetchする |
| Artifact Registry | build済みcontainer imageの置き場 | Cloud Run / VM がpullするimageを置く |
| GitLab | 社内用・停止してもよい環境向け | 今回の本番正本にはしない。必要ならmirror用途に限定する |

つまり、GitHubかArtifact Registryの二択ではない。

```text
GitHub
  -> source code
  -> docker build
  -> Artifact Registry
  -> Cloud Run / VM / private container host
```

## 5.2 本番で扱うcontainer image

Artifact Registry の想定:

```text
<region>-docker.pkg.dev/<gcp-project>/<artifact-repo>/<image-name>:<git-sha>
```

例:

```text
asia-northeast1-docker.pkg.dev/<gcp-project>/posla/posla-web:<git-sha>
```

| image名 | build元 | push先 | 起動先 | 用途 |
|---|---|---|---|---|
| `posla-web:<git-sha>` | `【擬似本番環境】meal.posla.jp/docker/php/Dockerfile.cloudrun` | Artifact Registry | Cloud Run service | POSLA web/API |
| `posla-web:<git-sha>` | 同上 | Artifact Registry | Cloud Run Jobs | POSLA cron。imageはwebと同じでcommandだけ変える |
| `posla-provisioner:<git-sha>` | `【擬似本番環境】meal.posla.jp/docker/provisioner/Dockerfile` | Artifact Registryまたはprovisioner VM local build | GCE provisioner VM | Cloud Runからtriggerを受けてcell作成を実行 |
| `posla-php-cell:<git-sha>` | `【擬似本番環境】meal.posla.jp/docker/php/Dockerfile.cell` | Artifact Registryまたはprovisioner VM local build | GCE provisioner VM上のcell app container | 顧客cell app |
| `mysql:5.7` | official image | Docker Hubまたはmirror | GCE provisioner VM上のcell db container | 顧客cell DB |
| `posla-op-runtime:<git-sha>` | `【AI運用支援】codex-ops-platform/docker/ops-agent/Dockerfile` | OP host local build推奨 | private VM/container host | OP実行用runtime。現状はrepo内 `public/` / `scripts/` / `data/` をvolume mountする |
| `posla-runner:<git-sha>` | `【エージェント】runner.posla.jp/Dockerfile` | Artifact Registryまたはrunner host local build | private VM/container host | OPから呼ばれるread-only調査runner |

最初の本番では最低限 `posla-web:<git-sha>` をArtifact Registryへpushする。
`posla-provisioner` / `posla-php-cell` / `posla-runner` は、運用を揃えるならArtifact Registryへpushする。初期構築を急ぐ場合は、それぞれのVM上でlocal buildでも起動できる。
OPは現状、image単体では完結せず、repo内の `public/` / `scripts/` / `data/` をvolume mountして動かす構成。まずはOP host上でrepoを配置し、`docker compose up -d --build` で起動する。

## 5.3 runnerの方針

runner は Cloud Run web/API の一部ではない。

本番初期方針:

- runnerはOPと同じprivate VM、または同じprivate network内のcontainer hostに置く
- `127.0.0.1:8094` またはprivate URLでOPからだけ呼ぶ
- internetへ公開しない
- DB接続はSELECT専用ユーザーだけ使う
- POSLA sourceは `RUNNER_REPO_URL=<posla-git-repo-url>` でGitHubからclone/fetchする方式を第一候補にする
- `RUNNER_CODEX_EXECUTE=0` で開始する
- Codex CLIを使う場合は、runner専用 `codex-home/` とOPの一時ゲートで制御する

runnerをCloud Runに載せるのは次フェーズ扱い。
理由は、runnerがworkspace、Codex HOME、read-only source、ログ参照を持つため、永続化・権限分離・secret遮断の設計が別途必要になるため。

2026-04-28 20:14 JST のローカル詳細smokeでは、POSLA障害報告 -> OP case -> runner -> Codex CLI まで完走済み。
同日、POSLA監視cron -> OP alert -> runner -> Google Chat の経路も完走確認済み。

確認済みの修正:

- runner DB read-only 接続失敗時も Fatal HTML ではなくJSONで返す
- runner repo sync が後から変更した `RUNNER_REPO_REF` のbranchをfetchできる
- POSLA `monitor-health.php` が `codex_ops_alert_endpoint` / `codex_ops_alert_token` をDB設定から読む
- POSLA `monitor-health.php` のOP alert timeoutを runner / Codex 同期処理に耐える値へ延長

本番前に残す確認:

- `OPS_CASE_INGEST_TOKEN` / `OPS_ALERT_INGEST_TOKEN` と POSLA側 token が一致している
- `OPS_ALLOW_UNTOKENED_INGEST=1` を本番に残していない
- `RUNNER_REPO_REF` が実際にデプロイしたPOSLA branch / tag / commitを指している
- `RUNNER_LOG_FILES` が本番ログを読め、runner result が `logs.status=skipped` ではない
- `docs/op-runner-codex-e2e-runbook.md` のE2E smokeで `data.status=codex_cli_executed` まで確認する

## 6. 各コンポーネントの役割

### 6.1 POSLA本体

役割:

- LP
- signup
- Stripe webhook
- owner / manager / staff画面
- order / KDS / register / reservation / inventory
- self order / self payment
- monitor endpoint
- OPへのalert / case送信
- Cloud Run web serviceとして動作

主なファイル:

```text
api/
public/
scripts/
sql/
docker/php/Dockerfile.cloudrun
docker/env/app.production.env.example
scripts/cloudrun/cron-runner.php
docker/php/cron-job-cloudrun.sh
docs/cloud-run-deploy-runbook.md
docs/cloud-run-readiness-review.md
```

### 6.2 provisioner runner

役割:

- Cloud Run POSLA web から `POST /run` を受ける
- VM上の Docker を操作する
- `scripts/cell/cell.sh` を実行する
- 顧客ごとの app / db cell container を作成する

主なファイル:

```text
docker-compose.provisioner.yml
docker/provisioner/Dockerfile
docker/env/provisioner.env.example
scripts/cell/provisioner-trigger-server.php
scripts/cell/provision-ready-cells.php
scripts/cell/cell.sh
docker-compose.cell.yml
docker/php/Dockerfile.cell
docs/provisioner-runner.md
```

重要:

- provisionerは `/var/run/docker.sock` をmountするため、専用VMに置く
- `POSLA_PROVISIONER_ROOT_DIR` はhost/containerで同じ絶対パスにする
- 本番推奨配置は `/srv/posla`

### 6.3 OP

役割:

- POSLA全体の外部監視
- alert / case受信
- Investigation管理
- runner調査の起動
- AI調査レポート表示
- Google Chat通知
- 運用ユーザー管理

主なファイル:

```text
../【AI運用支援】codex-ops-platform/README.md
../【AI運用支援】codex-ops-platform/docker-compose.yml
../【AI運用支援】codex-ops-platform/env/ops-agent.env.example
../【AI運用支援】codex-ops-platform/docs/16-production-deployment-runbook.md
../【AI運用支援】codex-ops-platform/docs/18-server-engineer-deploy-brief.md
../【AI運用支援】codex-ops-platform/SERVER_ENGINEER_SYSTEM_OVERVIEW.md
```

重要:

- Phase 1ではread-only
- POSLA DB write / deploy / rollbackはしない
- secret実値はOP UIやGitに保存しない

### 6.4 runner

役割:

- OPから調査依頼を受ける
- POSLA repoをclone/fetch
- コード検索
- DB SELECT
- ログ参照
- Codex CLIによる調査レポート生成

主なファイル:

```text
../【エージェント】runner.posla.jp/README.md
../【エージェント】runner.posla.jp/docker-compose.yml
../【エージェント】runner.posla.jp/env/runner.env.example
../【エージェント】runner.posla.jp/docs/01-runner-operations-manual.md
```

重要:

- UIなし
- 外部公開しない
- DBユーザーはSELECT専用
- 初期状態では `RUNNER_CODEX_EXECUTE=0`
- Codex CLIを有効化する場合も、OPの一時ゲートとrunner専用Codex HOMEを使う
- Cloud Runではなく、当面はOPと同じprivate VMまたはprivate container hostで常時起動する
- OPから `OPS_RUNNER_BASE_URL` で呼ぶ。外部ユーザーやPOSLA webからは直接呼ばない

## 7. サーバエンジニアへの依頼文

以下をそのまま渡せる。

```text
POSLA本番デプロイのため、以下3つのコード一式を役割別に配置してください。

1. 【擬似本番環境】meal.posla.jp
   - POSLA本体
   - Cloud Run web/API 用
   - provisioner runner / cell作成コードを含む

2. 【AI運用支援】codex-ops-platform
   - OP
   - 内部運用画面
   - POSLA監視、alert/case受信、runner連携用

3. 【エージェント】runner.posla.jp
   - runner
   - OPから呼ばれるread-only調査基盤

secret実値、DB password、API key、実顧客データ、uploads、cells実行データ、codex-homeは渡しません。
example envを元に、本番secretはSecret Managerまたは本番サーバ上のenvで設定してください。
```

## 8. 本番作業順

### Step 1. Git / codeを準備する

サーバ側では、可能ならGit cloneで配置する。

```bash
mkdir -p <deploy-root>
cd <deploy-root>

git clone <posla-repo-url> meal.posla.jp
git clone <op-repo-url> op.posla.jp
git clone <runner-repo-url> runner.posla.jp
```

branchを合わせる。

```bash
cd <deploy-root>/meal.posla.jp
git switch <posla-branch>
git pull --ff-only

cd <deploy-root>/op.posla.jp
git switch <op-branch>
git pull --ff-only

cd <deploy-root>/runner.posla.jp
git switch <runner-branch>
git pull --ff-only
```

### Step 2. GCPリソースを作る

POSLA本体用:

- Artifact Registry
- Cloud Run service
- Cloud Run Jobs or Cloud Scheduler
- Cloud SQL
- Memorystore
- GCS bucket
- Secret Manager

provisioner用:

- GCE VM
- Docker / Docker Compose plugin
- `/srv/posla`
- firewall / LB / IAP / TLS

OP / runner用:

- VMまたはprivate container host
- reverse proxy
- private network
- backup / log rotate

Artifact Registryはcontainer imageの置き場。GitHubはsource codeの正本。
本番ではGitHubを継続利用し、Cloud RunやVMが使うimageをArtifact Registryへpushする。

想定image:

```text
posla-web:<git-sha>
posla-provisioner:<git-sha>
posla-php-cell:<git-sha>
posla-op-runtime:<git-sha>
posla-runner:<git-sha>
```

最低限、Cloud Runに載せる `posla-web:<git-sha>` はArtifact Registryへpushする。

### Step 2.1. Artifact Registryへimageをpushする

POSLA web image は必須。

```bash
cd <deploy-root>/meal.posla.jp

export GCP_PROJECT_ID=<gcp-project>
export GCP_REGION=asia-northeast1
export ARTIFACT_REPO=posla
export IMAGE_TAG="$(git rev-parse --short=12 HEAD)"
export IMAGE_BASE="${GCP_REGION}-docker.pkg.dev/${GCP_PROJECT_ID}/${ARTIFACT_REPO}"

docker build \
  -f docker/php/Dockerfile.cloudrun \
  -t "${IMAGE_BASE}/posla-web:${IMAGE_TAG}" \
  .

docker push "${IMAGE_BASE}/posla-web:${IMAGE_TAG}"
```

Cloud Run service と Cloud Run Jobs は同じ image を使う。
Jobs側はcommandを `docker/php/cron-job-cloudrun.sh` 相当に差し替える。

provisioner / cell / runner image もArtifact Registryへ寄せる場合:

```bash
cd <deploy-root>/meal.posla.jp

docker build \
  -f docker/provisioner/Dockerfile \
  -t "${IMAGE_BASE}/posla-provisioner:${IMAGE_TAG}" \
  .

docker build \
  -f docker/php/Dockerfile.cell \
  -t "${IMAGE_BASE}/posla-php-cell:${IMAGE_TAG}" \
  .

docker push "${IMAGE_BASE}/posla-provisioner:${IMAGE_TAG}"
docker push "${IMAGE_BASE}/posla-php-cell:${IMAGE_TAG}"
```

```bash
cd <deploy-root>/runner.posla.jp
export IMAGE_TAG="$(git rev-parse --short=12 HEAD)"

docker build \
  -t "${IMAGE_BASE}/posla-runner:${IMAGE_TAG}" \
  .

docker push "${IMAGE_BASE}/posla-runner:${IMAGE_TAG}"
```

OPは現状、Artifact Registryへpushするself-contained imageではなく、VM上のrepoとcompose volume mountで動かす。
runner / provisioner は、初期構築では各VM上のlocal buildでもよい。
ただし、複数VM運用や再現性を重視する場合はArtifact Registryへpushする。

### Step 3. POSLA Cloud Run webをデプロイする

正本:

```text
docs/cloud-run-deploy-runbook.md
docker/env/app.production.env.example
```

build対象:

```text
docker/php/Dockerfile.cloudrun
```

確認:

```bash
curl -sf https://<production-domain>/api/monitor/ping.php
```

### Step 4. POSLA cronをCloud Run Jobsで設定する

正本:

```text
scripts/cloudrun/cron-runner.php
docker/php/cron-job-cloudrun.sh
docs/cloud-run-deploy-runbook.md
```

確認項目:

- `api/cron/monitor-health.php` が実行される
- `POSLA_CRON_SECRET` が一致している
- OP alert endpointへ送信できる

### Step 5. provisioner VMを起動する

正本:

```text
docs/provisioner-runner.md
docker/env/provisioner.env.example
docker-compose.provisioner.yml
```

VM上の想定:

```bash
cd /srv/posla
cp docker/env/provisioner.env.example docker/env/provisioner.env
vi docker/env/provisioner.env
set -a
. docker/env/provisioner.env
set +a
docker compose -f docker-compose.provisioner.yml up -d --build
curl -sf http://127.0.0.1:19091/health
```

Cloud Run側には以下を設定する。

```text
POSLA_PROVISIONER_TRIGGER_URL=https://<provisioner-service-url>/run
POSLA_PROVISIONER_ALLOW_REMOTE_TRIGGER=1
POSLA_PROVISIONER_TRIGGER_SECRET=<secret>
POSLA_PROVISIONER_TRIGGER_TIMEOUT_SEC=2
```

### Step 6. OPを起動する

正本:

```text
../【AI運用支援】codex-ops-platform/docs/18-server-engineer-deploy-brief.md
../【AI運用支援】codex-ops-platform/docs/16-production-deployment-runbook.md
../【AI運用支援】codex-ops-platform/env/ops-agent.env.example
```

起動例:

```bash
cd <deploy-root>/op.posla.jp
cp env/ops-agent.env.example env/ops-agent.env
vi env/ops-agent.env
docker compose up -d --build
curl -sf http://127.0.0.1:8091/api/status.php
```

本番では `host.docker.internal` を残さない。

また、現在の `docker-compose.yml` はローカル擬似本番の実フォルダをread-only mountする足場です。
本番では、POSLA source mountを本番配置先へ置き換えるか、production用compose overrideを作る。

置換例:

```text
../【擬似本番環境】meal.posla.jp/api
  -> <deploy-root>/meal.posla.jp/api

../【擬似本番環境】meal.posla.jp/public
  -> <deploy-root>/meal.posla.jp/public

../【擬似本番環境】meal.posla.jp/docs
  -> <deploy-root>/meal.posla.jp/docs

../【擬似本番環境】meal.posla.jp/scripts
  -> <deploy-root>/meal.posla.jp/scripts

../【擬似本番環境】meal.posla.jp/sql
  -> <deploy-root>/meal.posla.jp/sql
```

### Step 7. runnerを起動する

正本:

```text
../【エージェント】runner.posla.jp/docs/01-runner-operations-manual.md
../【エージェント】runner.posla.jp/env/runner.env.example
```

起動例:

```bash
cd <deploy-root>/runner.posla.jp
cp env/runner.env.example env/runner.env
vi env/runner.env
docker compose up -d --build
curl -sf http://127.0.0.1:8094/api/health.php \
  -H 'X-Runner-Token: <OPS_RUNNER_TOKEN>'
```

OP側には以下を設定する。

```text
OPS_RUNNER_BASE_URL=http://127.0.0.1:8094
OPS_RUNNER_TOKEN=<OPS_RUNNER_TOKEN>
```

現在の `docker-compose.yml` はローカル擬似本番を `/sources/meal.posla.jp` にread-only mountする足場です。
本番ではどちらかに統一する。

```text
案A: RUNNER_REPO_URL=<posla-git-repo-url> にしてrunnerがGit clone/fetchする
案B: <deploy-root>/meal.posla.jp を /sources/meal.posla.jp にread-only mountする
```

案Bの場合、composeのvolumeを本番配置先へ置き換える。

runner envで必ず合わせる値:

```text
RUNNER_REPO_REF=<deployed-posla-branch-or-tag-or-commit>
RUNNER_LOG_FILES=<comma-separated-readable-log-files>
```

`RUNNER_REPO_REF` は、POSLA control / cell に実際に入れた commit と合わせる。
OPの Code / runner repo sync 結果で、runnerが読んでいる commit と deploy manifest の commit が一致することを確認する。
`RUNNER_LOG_FILES` が未設定だと、runner調査はコードとDBだけになり `logs.status=skipped` になる。

### Step 8. 接続確認する

POSLA:

```bash
curl -sf https://<production-domain>/api/monitor/ping.php
```

provisioner:

```bash
curl -sf http://127.0.0.1:19091/health
```

OP:

```bash
curl -sf https://<op-domain>/api/status.php
```

runner:

```bash
curl -sf http://127.0.0.1:8094/api/health.php \
  -H 'X-Runner-Token: <OPS_RUNNER_TOKEN>'
```

OPからrunner:

```bash
curl -sf https://<op-domain>/api/runner-repo.php
```

### Step 9. E2E smokeを行う

最低限確認する。

- POSLA webが表示できる
- POSLA `ping.php` が `ok`
- Cloud Run Job cronが成功する
- OPへalert/caseが届く
- OPからPOSLA snapshotを読める
- OPからrunner healthを読める
- runnerがPOSLA repoをsyncできる
- runnerが読んでいるPOSLA commitがデプロイ済みcommitと一致する
- runnerがDB SELECTだけできる
- runnerが本番ログを読める。ログ未接続の場合は `logs.status=skipped` を残タスクにする
- POSLA障害報告 -> OP case -> runner -> Codex CLI で `auto_investigation.status=ai_report_ready`
- POSLA監視cron -> OP alert -> runner -> Google Chat で `op_alert_sent=1`
- provisionerが `/health` を返す
- 実申込から `ready_for_cell` を経てcell作成できる
- 作成cellの `ping.php` が `ok`

## 9. secret管理

secret実値は以下のどれかで渡す。

- Google Secret Manager
- 1Password等の社内金庫
- 本番サーバ上でサーバエンジニアが直接生成

チャット、Markdown、Git、OP UIには書かない。

最低限必要なsecret:

```text
POSLA_DB_PASS
POSLA_CRON_SECRET
POSLA_OPS_READ_SECRET
POSLA_OP_CASE_TOKEN
POSLA_OP_ALERT_TOKEN
POSLA_PROVISIONER_TRIGGER_SECRET
OPS_AUTH_PASSWORD_HASH
OPS_ALERT_INGEST_TOKEN
OPS_CASE_INGEST_TOKEN
OPS_RUNNER_TOKEN
RUNNER_AUTH_TOKEN
RUNNER_DB_PASSWORD
STRIPE_SECRET_KEY
STRIPE_WEBHOOK_SECRET
GEMINI_API_KEY
GOOGLE_PLACES_API_KEY
SENDGRID_API_KEY
```

## 10. 本番前チェックリスト

- [ ] POSLA repoが最新branchで渡されている
- [ ] OP repoが最新branchで渡されている
- [ ] runner repoが最新branchで渡されている
- [ ] secret実値がGit/zip/docsに入っていない
- [ ] `.env` 実ファイルを渡していない
- [ ] OP `data/*.json` 実データを渡していない
- [ ] runner `codex-home/` を渡していない
- [ ] POSLA `uploads/` を渡していない
- [ ] POSLA `cells/` 実データを渡していない
- [ ] GCP Project / region が決まっている
- [ ] `<production-domain>` が決まっている
- [ ] `<op-domain>` が決まっている
- [ ] `{tenant_slug}.<production-domain>` のDNS方針が決まっている
- [ ] provisioner VMの配置が決まっている
- [ ] OP/runnerの配置が決まっている
- [ ] OP -> runner -> Codex E2Eで `data.status=codex_cli_executed` を確認している
- [ ] backup / snapshot / log rotate が決まっている

## 11. 現時点の状態

コード側は Cloud Run デプロイ直前まで準備済み。

確認済み:

- Cloud Run用 Dockerfile build OK
- 擬似本番 `http://127.0.0.1:8081/api/monitor/ping.php` OK
- provisioner compose config OK
- provisioner `/health` OK
- `scripts/cloudrun/cron-runner.php` 構文OK
- `api/lib/db.php` / `api/lib/rate-limiter.php` 構文OK
- runner health は Codex CLI 実行可能状態
- OP case ingest は case / investigation 作成までOK

未完了:

- GCP本番リソース作成
- Secret Manager登録
- DNS / TLS
- Cloud Run env設定
- Cloud Run Jobs設定
- provisioner VM作成
- OP/runner本番配置
- 実申込E2E smoke
- OP -> runner -> Codex E2E smoke
- runner本番ログ `RUNNER_LOG_FILES` 設定

## 12. 残タスク

- 未コミット変更をGit branch / commitにまとめる
- POSLA / OP / runner のGitHub repo branch / tag運用を確定する
- GCP Project / region / Artifact Registry を確定する
- Cloud SQL / Memorystore / GCS bucket を作成する
- Secret Managerへ本番secretを登録する
- POSLA Cloud Run serviceを作成する
- POSLA Cloud Run Jobs / Schedulerを作成する
- provisioner専用VMを作成する
- `/srv/posla` にPOSLA repoを配置する
- `docker/env/provisioner.env` の本番値を作成する
- Cloud Runからprovisioner VMへの安全な通信経路を作る
- OPの本番配置方式を確定する
- runnerの本番配置方式を確定する
- OP/runnerのprivate接続を確認する
- runner DB read-only credential / 接続元許可を本番値で確定する
- runner `RUNNER_REPO_REF` とPOSLA deploy commitの一致確認を運用手順に入れる
- runner `RUNNER_LOG_FILES` を本番ログへ接続する
- OP -> runner -> Codex E2Eで `data.status=codex_cli_executed` を確認する
- `{tenant_slug}.<production-domain>` のDNS / reverse proxy / TLSを設定する
- backup / snapshot / log rotate / 監視通知を設定する
- 実申込からcell作成までE2E smokeを行う
