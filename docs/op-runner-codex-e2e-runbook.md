# OP / runner / Codex E2E Runbook

作成日: 2026-04-28
対象: POSLA Cloud Run 本番構成 + OP / runner 運用支援

## 1. 結論

POSLAからアラートまたは障害報告が届いた時の期待経路は次です。

```text
POSLA Cloud Run
  -> OP /api/alerts.php or /api/cases.php
  -> OP auto investigation
  -> runner /api/investigate.php
  -> runner /api/ai-report.php
  -> Codex CLI
  -> OP investigation にAIレポート保存
  -> Google Chat等へ短い初動通知
```

2026-04-28 時点のローカル確認では、runner自体は Codex CLI 実行可能状態です。
ただし、OPからの自動経路はまだ完走していません。

確認結果:

```text
runner health:
  codex_cli_available=true
  codex_cli_execute_enabled=true
  codex_home_state=home_ready

OP case ingest:
  case作成=true
  investigation作成=true
  runner呼び出し=failed
  ai_report=disabled
```

失敗理由:

```text
runner /api/investigate.php が DB read-only 接続エラー時に JSON ではなく PHP Fatal error HTML を返した。
OP は runner response を JSON として読めず invalid_json_response になった。
```

つまり、現状は **「Codexへ指示している実感がない」のは正しい**。
Codex実行以前に、runner調査のDB probeで止まっている。

## 2. 本番のGitHub repo分割

コード保管はGitHubで確定。

本番正本repo:

| repo | 役割 | デプロイ先 |
|---|---|---|
| `https://github.com/pb-oda/meal.posla.jp` | POSLA本体、Cloud Run Dockerfile、cron job、provisioner、cell作成コード | Cloud Run / Cloud Run Jobs / provisioner VM |
| `https://github.com/pb-oda/op.posla.jp` | OP内部運用画面、alert/case受信、investigation管理、runner連携 | private VM / container host |
| `https://github.com/pb-oda/runner.posla.jp` | read-only調査runner、Codex CLI実行枠 | private VM / container host |

GitLabは本番正本にしない。
必要なら社内mirrorや停止しても問題ない検証用途に限定する。

## 3. branch / tag 方針

各repoで共通:

```text
main
  本番リリース候補の正本

feature/*
  開発用

hotfix/*
  緊急修正用

release/YYYYMMDD-n
  本番リリース固定用tagまたはbranch
```

本番デプロイでは、必ず以下を記録する。

```text
repo
commit sha
image tag
image digest
deployed_at
deployed_by
target
rollback target
```

POSLA本体は Cloud Run image digest を正とする。
OP / runner は最初はVM上の git commit + local build でもよいが、再現性を上げるならArtifact Registryへ寄せる。

## 4. image と deploy の流れ

### 4.1 POSLA

```text
GitHub meal.posla.jp
  -> docker build docker/php/Dockerfile.cloudrun
  -> Artifact Registry posla-web:<git-sha>
  -> Cloud Run service posla-web
  -> Cloud Run Jobs cron
```

Cloud Run service と Jobs は同じ image を使う。
Jobsはcommandだけ `docker/php/cron-job-cloudrun.sh` 相当に変える。

### 4.2 provisioner

```text
GitHub meal.posla.jp
  -> docker/provisioner/Dockerfile
  -> posla-provisioner container
  -> GCE / VPS provisioner VM
  -> /var/run/docker.sock
  -> scripts/cell/cell.sh
  -> customer cell app/db container
```

provisionerはVM管理者権限に近いため、専用VMにする。

### 4.3 OP

```text
GitHub op.posla.jp
  -> private VM / container host
  -> docker compose up -d --build
  -> https://<op-domain>
```

OPは顧客向けではない。
POSLA DB write、deploy、migration、rollbackは行わない。

### 4.4 runner

```text
GitHub runner.posla.jp
  -> private VM / container host
  -> docker compose up -d --build
  -> http://127.0.0.1:8094 or private URL
  -> OP からだけ呼ぶ
```

runnerは外部公開しない。
POSLA sourceは `RUNNER_REPO_URL=https://github.com/pb-oda/meal.posla.jp` を第一候補にする。

## 5. Alert / Case から Codex までの実行条件

Codexへ実際に指示が出たと言える条件は、以下が全部OKの時だけ。

| 段階 | 成功条件 |
|---|---|
| POSLA -> OP | `POST /api/alerts.php` または `POST /api/cases.php` が `ok=true` |
| OP classification | `classification=action_needed` または `emergency` |
| OP investigation | `auto_investigation.investigation_id` が作成される |
| OP -> runner investigate | `auto_investigation.runner.ok=true` |
| runner read-only調査 | runner response `data.status=investigated` |
| OP -> runner ai-report | `auto_investigation.ai_report.ok=true` |
| runner -> Codex CLI | ai_report response `data.status=codex_cli_executed` |
| OP保存 | investigation に `latest_ai_report.status=codex_cli_executed` が残る |

次は成功扱いにしない。

```text
investigation_created
runner.status=failed
runner.error=invalid_json_response
ai_report.status=disabled
codex_cli_unavailable
codex_cli_disabled
```

## 6. 本番前のE2Eテスト

### 6.1 runner health

```bash
curl -sf http://127.0.0.1:8094/api/health.php \
  -H "X-Runner-Token: <OPS_RUNNER_TOKEN>"
```

確認:

```text
ok=true
data.config.repo_status.state=ready
data.config.codex_cli_available=true
data.config.codex_cli_execute_enabled=true
data.codex.home_state=home_ready
```

### 6.2 OPからrunner investigate

OP hostから実行する。

```bash
curl -sf -X POST "$OPS_RUNNER_BASE_URL/api/investigate.php" \
  -H "Content-Type: application/json" \
  -H "X-Runner-Token: $OPS_RUNNER_TOKEN" \
  -d '{
    "task_id": "smoke-runner-investigate",
    "cell_id": "posla-control",
    "issue_type": "case",
    "error_no": "E3035",
    "summary": "runner smoke",
    "message": "レジで会計できない想定のE2E確認",
    "search_terms": ["E3035", "レジ", "会計"],
    "allowed_actions": ["read_code", "read_logs", "select_db"]
  }'
```

確認:

```text
ok=true
data.status=investigated
data.guardrails.db_write=false
data.guardrails.git_push=false
data.guardrails.deploy=false
```

### 6.3 OPからrunner ai-report

```bash
curl -sf -X POST "$OPS_RUNNER_BASE_URL/api/ai-report.php" \
  -H "Content-Type: application/json" \
  -H "X-Runner-Token: $OPS_RUNNER_TOKEN" \
  -d '{
    "task_id": "smoke-runner-ai-report",
    "cell_id": "posla-control",
    "customer_name": "smoke",
    "issue_type": "case",
    "error_no": "E3035",
    "summary": "runner ai smoke",
    "message": "レジで会計できない想定のE2E確認",
    "runner_result": {
      "ok": true,
      "data": {
        "status": "investigated",
        "checks": {
          "code": {"match_count": 1},
          "db": {"status": "ok"},
          "logs": {"status": "skipped"}
        }
      }
    }
  }'
```

確認:

```text
ok=true
data.status=codex_cli_executed
data.report.sections.operator_now が空ではない
```

### 6.4 POSLA/OP経由のcase E2E

```bash
curl -sf -X POST https://<op-domain>/api/cases.php \
  -H "Content-Type: application/json" \
  -H "X-OPS-CASE-TOKEN: <OPS_CASE_INGEST_TOKEN>" \
  -d '{
    "source": "posla_support",
    "customer_name": "smoke",
    "cell_id": "posla-control",
    "error_no": "E3035",
    "screen_name": "レジ",
    "message": "レジで会計できない想定のE2E確認",
    "priority": "high"
  }'
```

成功条件:

```text
ok=true
auto_investigation.status=ai_report_ready
auto_investigation.runner.ok=true
auto_investigation.ai_report.ok=true
```

## 7. 現在見つかったblocker

ローカルテストで次を確認した。

```text
日時: 2026-04-28 19:28 JST
test: OP /api/cases.php に手動caseをPOST
result:
  case created
  investigation created
  runner failed
  ai_report disabled
reason:
  runner /api/investigate.php が DB read-only 接続エラーで PHP Fatal error HTML を返した
  OP auto runner は JSON を期待するため invalid_json_response になった
```

修正方針:

- runner の DB read-only credential / 接続元許可を本番値で正しく設定する
- runner の `runner_db_probe()` は `mysqli_sql_exception` をcatchし、必ずJSONで `db.status=error` を返すようにする
- DB read-only が失敗しても、code search / log check / ai-report へ進める設計にする
- 修正後、6.2 -> 6.3 -> 6.4 の順で再テストする

このblockerが残っている間は、POSLAから障害報告が来ても「Codexへ正常に指示できている」とは言わない。

## 8. 本番導入時の最小env

OP:

```text
OPS_RUNNER_BASE_URL=http://127.0.0.1:8094
OPS_RUNNER_TOKEN=<OPS_RUNNER_TOKEN>
OPS_AUTO_INVESTIGATE=1
OPS_AUTO_INVESTIGATE_ALERTS=1
OPS_AUTO_INVESTIGATE_CASES=1
OPS_AUTO_RUNNER_TRIGGER=1
OPS_AUTO_AI_REPORT=1
```

runner:

```text
RUNNER_AUTH_TOKEN=<OPS_RUNNER_TOKEN>
RUNNER_REPO_URL=https://github.com/pb-oda/meal.posla.jp
RUNNER_REPO_REF=main
RUNNER_DB_HOST=<cloud-sql-or-readonly-db-host>
RUNNER_DB_PORT=3306
RUNNER_DB_DATABASE=<readonly-db-name>
RUNNER_DB_USER=<select-only-user>
RUNNER_DB_PASSWORD=<select-only-password>
RUNNER_CODEX_COMMAND=codex
RUNNER_CODEX_ARGS=exec,--sandbox,danger-full-access,--color,never,--skip-git-repo-check,-
RUNNER_CODEX_HOME=codex-home
RUNNER_CODEX_EXECUTE=0
RUNNER_CODEX_CONTROL_PATH=data/codex-control.json
RUNNER_CODEX_TIMEOUT_SEC=120
```

Codexまで通すE2E確認時、または本番で自動AIレポートを許可した後だけ `RUNNER_CODEX_EXECUTE=1` にする。
初期構築では `0` で起動し、runner health / investigate が安定してから開ける。

POSLA:

```text
POSLA_OP_CASE_ENDPOINT=https://<op-domain>/api/cases.php
POSLA_OP_CASE_TOKEN=<OPS_CASE_INGEST_TOKEN>
POSLA_OP_ALERT_ENDPOINT=https://<op-domain>/api/alerts.php
POSLA_OP_ALERT_TOKEN=<OPS_ALERT_INGEST_TOKEN>
```

## 9. 残タスク

- runner repoで `runner_db_probe()` のFatal errorをJSON error化する
- runner DB read-only credential / 接続元許可を本番用に確定する
- runner Codex CLI login / `codex-home/` 永続化方式を確定する
- OP / runner の本番private networkを確定する
- GitHub repo 3本のbranch/tag運用を確定する
- Artifact Registry image tag / digest記録方式を確定する
- OP investigation に image digest / repo sha / deploy target を必ず残す
- 6.2 / 6.3 / 6.4 のE2E smokeを本番前に通す
