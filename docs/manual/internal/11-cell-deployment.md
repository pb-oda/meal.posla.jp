---
title: Cell配備運用
chapter: 11
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [cell, deployment, blast-radius, docker, migration, rollback]
last_updated: 2026-04-26
maintainer: POSLA運営
---

# 11. Cell配備運用

POSLA は MVP から **cell architecture + 1 tenant / 1 cell** を基本にします。目的は、ある顧客の緊急修正、migration、検証、rollback が他顧客へ同時波及しない配備単位を持つことです。

## 11.1 採用方針

| 項目 | 方針 |
|---|---|
| コード | 1 codebase |
| build artifact | 1 image tag を複数 cell へ配布。app code は image に焼き込む |
| app | cell ごとに分離 |
| DB | cell ごとに分離 |
| uploads | cell ごとに分離 |
| cron / job | cell ごとに 1 scheduler |
| feature flag | tenant / cell 単位で制御 |
| POSLA管理 | MVP は cell 内管理。将来は中央 control plane 化 |

採用しない構成:

- shared single stack: deploy / DB / cron 障害が全顧客へ同時波及する。
- 顧客ごとのコードフォーク: blast radius は小さいが、保守と AI 運用支援の対象管理が破綻しやすい。

## 11.2 Cell の構成

1 cell は次を 1 セットで持ちます。

```text
same POSLA code / same image artifact
          |
          +--> cell-a  A社 app + A社 DB + A社 uploads + A社 scheduler
          +--> cell-b  B社 app + B社 DB + B社 uploads + B社 scheduler
          +--> cell-c  C社 app + C社 DB + C社 uploads + C社 scheduler
```

cell ごとの runtime 設定は `cells/<cell-id>/` に置きます。実値ファイルは git 管理しません。

```text
cells/<cell-id>/
├── app.env
├── db.env
├── cell.env
└── uploads/
```

ローカル台帳は `cells/registry.tsv` です。実値台帳なので git 管理しません。テンプレートは `cells/registry.example.tsv` に置きます。

新規 cell DB は `docker/db/cell-init/` で初期化します。この init は schema と schema migration のみを読み込み、ローカル開発用の `docker/db/init/03-seed.sql` は読み込みません。migration 名で管理されていても tenant / user を投入するデモデータ系 migration は skip します。1 tenant / 1 cell では、デモ tenant を初期DBへ混ぜないことを前提にします。

cell app image は `docker/php/Dockerfile.cell` で build します。`api/`, `public/`, `scripts/`, `sql/`, `privacy/`, `terms/` は image に含め、runtime で bind mount するのは `cells/<cell-id>/uploads/` のみにします。これにより、ホスト側のコード編集が稼働中の全 cell へ同時反映される構造を避けます。

## 11.3 初期作成

```bash
POSLA_CELL_DB_PASSWORD='<secure-db-password>' \
POSLA_CELL_DB_ROOT_PASSWORD='<secure-root-password>' \
  scripts/cell/cell.sh init acme-prod acme https://<production-domain> 18081 13306
scripts/cell/cell.sh registry
```

`app.env` では少なくとも次を cell 固有値にします。

```bash
POSLA_DB_HOST=db
POSLA_DB_NAME=...
POSLA_DB_USER=...
POSLA_DB_PASS=...
POSLA_APP_BASE_URL=https://<production-domain>
POSLA_ALLOWED_ORIGINS=https://<production-domain>
POSLA_ALLOWED_HOSTS=<production-domain>
POSLA_CELL_ID=acme-prod
POSLA_ENVIRONMENT=production
POSLA_DEPLOY_VERSION=<image-or-git-sha>
POSLA_CRON_ENABLED=1
```

`POSLA_DEPLOY_VERSION` は `scripts/cell/cell.sh <cell-id> build` が未指定時に現在の Git commit から自動更新します。手動で固定したい場合だけ、コマンド実行時の環境変数で明示します。

control stack の本番 env は `docker/env/app.production.env.example` を雛形にし、`POSLA_APP_BASE_URL` / `POSLA_ALLOWED_ORIGINS` / `POSLA_ALLOWED_HOSTS` を必ず本番ドメインへ置換します。`POSLA_ENVIRONMENT=production` では `localhost` / `127.0.0.1` / `host.docker.internal` の公開URL設定を起動時に拒否します。

`cell.env` では同一ホスト上で衝突しない port と image artifact を指定します。

```bash
POSLA_CELL_HTTP_PORT=18081
POSLA_CELL_DB_PORT=13306
POSLA_PHP_IMAGE=posla_php_cell:dev
```

`POSLA_PHP_IMAGE` も `build` 時に未指定なら `posla_php_cell:<deploy-version>` へ自動更新します。本番registryのimage名を使う場合は、`POSLA_PHP_IMAGE` を明示してbuildします。

`init` は password が未指定の場合だけ placeholder password を残します。本番cellでは `POSLA_CELL_DB_PASSWORD` / `POSLA_CELL_DB_ROOT_PASSWORD` を付けて作成し、`app.env` / `db.env` に `__REPLACE_*` が残っていないことを確認してから `deploy` します。

## 11.4 Tenant Onboarding

POSLA管理画面またはLP申込で顧客が追加されると、control DB の `posla_tenant_onboarding_requests` に作成待ちが残ります。LP経由でカード登録まで完了した顧客は、本番 provisioner ホスト上の on-demand provisioner が `ready_for_cell` を拾い、対象cellだけを作成します。POSLA管理画面は deploy コマンドを直接実行しません。

状態の意味:

| status | 意味 | 次アクション |
|---|---|---|
| `payment_pending` | LP申込後、支払い確認待ち | Stripe / webhook を確認 |
| `ready_for_cell` | 専用cell作成待ち | provisioner が自動作成。手動時はCell配備タブのコマンドを本番 provisioner ホストで順に実行 |
| `cell_provisioning` | cell作業中 | deploy / onboard / smoke を完了させる |
| `active` | 監視対象 | 外部運用基盤の同期対象 |
| `failed` | 作業失敗 | 原因を確認し、修正後に作業再開 |
| `canceled` | 決済前キャンセル / Stripe前段失敗でtenant実体を削除済み | Cell配備対象外。error_log / LP申込ログを確認 |

Cell配備タブで表示するもの:

- 顧客名、tenant slug、cell_id
- control registry の状態
- 推奨 app URL / health URL / DB host / uploads path
- `init` / `build` / `deploy` / `onboard-tenant` / `smoke` の実行コマンド
- 作業開始、失敗、control registry active 更新の操作

推奨 port は `posla_cell_registry` の既存 `app_base_url` / `health_url` / `db_host` を読んで、既存cellと衝突しない値を提示します。手動で変更する場合も、同一ホスト上では HTTP port / DB port を cell ごとに必ず分けます。

### on-demand provisioner

Stripe webhook / `signup-complete.html` は deploy を直接実行しません。決済完了後に control DB を `ready_for_cell` へ更新し、本番 provisioner ホスト上の localhost trigger service に通知します。POSLA管理画面の「テナント新規作成」も、同じく `ready_for_cell` を作成して trigger service に通知します。通常フローでは 1分ごとの timer / cron で巡回しません。

```bash
POSLA_PROVISIONER_TRIGGER_SECRET='<secret>' \
  php scripts/cell/provisioner-trigger-server.php
```

Web 側には同じ secret と trigger URL を設定します。

```bash
POSLA_PROVISIONER_TRIGGER_URL='http://127.0.0.1:19091/run'
POSLA_PROVISIONER_TRIGGER_SECRET='<secret>'
```

Docker 擬似本番からホスト側 trigger service を呼ぶ場合だけ、URL は `http://host.docker.internal:19091/run` にします。本番で Web と provisioner が同一ホスト上の通常プロセスなら `127.0.0.1` を使います。

事前確認だけ行う場合:

```bash
php scripts/cell/provision-ready-cells.php --limit=1 --dry-run
```

provisioner が実行する処理:

1. control DB の `ready_for_cell` を `cell_provisioning` に更新
2. `scripts/cell/cell.sh init` で cell env / DB env / uploads を作成
3. `build` / `deploy`
4. control DB の owner `password_hash` を使って `onboard-tenant`
5. `POSLA_CELL_SMOKE_STRICT=1 scripts/cell/cell.sh <cell> smoke`
6. 成功時に control `posla_cell_registry` と onboarding request を `active` へ更新

本番では、実行環境に `POSLA_CELL_APP_URL_PATTERN` を必ず設定します。未設定のまま production 環境で実行すると、provisioner は localhost のログインURLを作らずに停止します。

```bash
POSLA_CELL_APP_URL_PATTERN='https://{tenant_slug}.<production-domain>' \
  php scripts/cell/provision-ready-cells.php --limit=1 --dry-run
```

ローカル / 擬似本番検証では、未設定時に `http://127.0.0.1:<auto-port>` を使えます。本番で例外的に localhost URL を許可する場合だけ `POSLA_CELL_PROVISIONER_ALLOW_LOCAL_URL=1` を明示します。

cell 作成後、対象 cell DB に最初の tenant / store / owner を作ります。

標準手順:

```bash
scripts/cell/cell.sh acme-prod deploy
scripts/cell/cell.sh acme-prod migrate sql/migration-p1-40-cell-migration-ledger.sql
scripts/cell/cell.sh acme-prod migrate sql/migration-p1-41-cell-registry.sql
scripts/cell/cell.sh acme-prod register-db

POSLA_OWNER_PASSWORD='<initial-owner-password>' \
  scripts/cell/cell.sh acme-prod onboard-tenant acme 'Acme Inc.' 'Acme Main Store' owner 'Owner Name' owner@example.com

POSLA_CELL_SMOKE_STRICT=1 scripts/cell/cell.sh acme-prod smoke
```

`onboard-tenant` が作るもの:

| テーブル | 内容 |
|---|---|
| `tenants` | `is_active=1`, `subscription_status=trialing` の初期 tenant |
| `stores` | 1店舗目。`slug=default` |
| `store_settings` | 存在する場合だけ初期行 |
| `users` | owner / manager / staff / device ユーザー。password は `password_hash()` で保存 |
| `user_stores` | 初期4ロールと1店舗目の紐付け。device は `kds,register` を付与 |
| `posla_cell_registry` | registry table がある場合だけ tenant_id / tenant_slug / tenant_name を反映 |

環境変数:

| 変数 | 用途 |
|---|---|
| `POSLA_OWNER_PASSWORD` | owner 初期パスワード。`POSLA_OWNER_PASSWORD_HASH` 未指定時は必須 |
| `POSLA_OWNER_PASSWORD_HASH` | 既存 `password_hash()` を引き継ぐ場合に指定。LP申込の on-demand provisioner で使用 |
| `POSLA_STORE_SLUG` | 店舗 slug。省略時 `default` |
| `POSLA_SUBSCRIPTION_STATUS` | `none` / `trialing` / `active` など。省略時 `trialing` |
| `POSLA_HQ_MENU_BROADCAST` | 本部一括メニュー配信アドオン。省略時 `0` |
| `POSLA_MANAGER_USERNAME` / `POSLA_STAFF_USERNAME` / `POSLA_DEVICE_USERNAME` | 初期 username を明示したい場合だけ指定。省略時は `<tenant-slug>-manager` など |

外部運用監視用DB read-onlyユーザー:

| 変数 | 用途 |
|---|---|
| `POSLA_OPS_DB_READONLY_USER` | 外部運用基盤がDBを読むためのSELECT専用ユーザー。省略時 `posla_ops_ro` |
| `POSLA_OPS_DB_READONLY_PASSWORD` | SELECT専用ユーザーのパスワード。`provision-ready-cells.php` は自動生成する |
| `POSLA_OPS_DB_READONLY_HOST` | MySQL user のHost部。擬似本番は `%`、本番は外部運用基盤の接続元に合わせて制限する |

`scripts/cell/cell.sh init` は `cells/<cell-id>/db.env` に上記を記録し、cell DBの初回起動時に `docker/db/cell-init/02-ops-readonly-user.sh` が `GRANT SELECT ON <cell-db>.*` のみを付与する。POSLAアプリ本体はこのread-only資格情報を使用しない。

既存 cell が owner だけで作成済みの場合は、リリース前に不足ロールを補完します。

```bash
POSLA_OPS_USER_PASSWORD='<temporary-password>' \
  scripts/cell/cell.sh acme-prod ensure-ops-users acme
```

注意:

- `onboard-tenant` は対象 cell DB のみに書き込みます。
- 日本語の tenant / store / owner 名を壊さないため、`scripts/cell/cell.sh` の MySQL / mysqldump / restore は `--default-character-set=utf8mb4` を明示します。
- 既存 `tenant.slug`, `users.username`, `users.email` と重複する場合は停止します。
- 本番課金フローが有効な顧客は、Stripe 側の契約情報と `subscription_status` の整合を別途確認します。

## 11.5 Backup

対象 cell だけを backup します。

```bash
scripts/cell/cell.sh acme-prod backup
scripts/cell/cell.sh acme-prod backups
```

backup は `cells/<cell-id>/backups/<timestamp>_<deploy-version>/` に作成されます。このディレクトリは実値・DB dump を含むため git 管理しません。

標準で作成するもの:

| ファイル | 内容 |
|---|---|
| `db.sql` | cell DB dump。DB未起動時は作成しない |
| `app.env` | app runtime env snapshot |
| `db.env` | DB env snapshot |
| `cell.env` | port / image artifact snapshot |
| `manifest.env` | cell_id / deploy_version / php_image / port |
| `uploads-files.txt` | uploads 配下のファイル一覧 |

uploads本体のarchiveが必要な時だけ、次を付けます。

```bash
POSLA_CELL_BACKUP_UPLOADS=1 scripts/cell/cell.sh acme-prod backup
```

DB dump は `--default-character-set=utf8mb4` で作成します。backup 後に日本語名が含まれる顧客では、必要に応じて `db.sql` 内に店舗名・owner表示名が読める状態で残っていることを確認します。

## 11.6 Build / Deploy

初回または artifact を作る時:

```bash
scripts/cell/cell.sh acme-prod config
scripts/cell/cell.sh acme-prod build
```

`build` は現在の Git commit を `cells/acme-prod/app.env` の `POSLA_DEPLOY_VERSION` に刻印し、`cells/acme-prod/cell.env` の `POSLA_PHP_IMAGE` も同じversionのtagへ更新します。これにより `ping.php` と外部運用基盤の Cell 一覧で、対象cellに入っているコードversionを確認できます。

対象 cell だけを更新:

```bash
scripts/cell/cell.sh acme-prod deploy
scripts/cell/cell.sh acme-prod ping
scripts/cell/cell.sh acme-prod ps
```

`deploy` は `docker-compose.cell.yml` を使い、指定した `cell-id` の app / DB / uploads だけを対象にします。既存の `docker-compose.yml` の擬似本番 stack は置き換えません。

`deploy` の処理順:

1. `docker compose config` で構成を検証する。
2. pre-deploy backup を作る。
3. `posla_cell_deployments` が存在すれば `planned` 履歴を記録する。
4. 対象 cell だけ `compose up -d --no-build` する。
5. `/api/monitor/ping.php` を待つ。
6. `posla_cell_registry` を `active` で更新する。
7. `posla_cell_deployments` に `deployed` 履歴を記録する。
8. 失敗時は `failed` 履歴を記録し、rollback hint を出して停止する。

POSLA管理画面の `Cell配備 > 次回デプロイ計画` では、次回デプロイ対象範囲を `release_plan_target_scope` として保存します。単一 cell の場合だけ `release_plan_target_cell_id` も保存します。これは Feature Flags とは別の設定です。

- 先行確認: `single_cell` を選び、通常は `POSLA運用確認用 / test-01` にだけデプロイする。
- コード統一: `all_active_cells` を選び、全 active cell へ同じコードを反映する。全体リリースではこれを使い、cell 間でコード差分を残さない。
- Actions / 自動化: Release Plan の `automation_mode=actions_allowed` の時だけ、保存済みの対象範囲を候補にできる。
- 未設定または `manual_only`: Actions は自動デプロイを停止扱いにする。
- 公開範囲: デプロイ後に Feature Flags で `cell` / `tenant` / `global` を別途判断する。

複数 cell だけに適用したい場合の判断:

- 複数のお客様に機能を見せたいだけなら、コードの複数 cell デプロイではなく Feature Flags の `tenant` scope を使う。
- 複数 cell へコード自体を先行反映したい場合は、Release Plan に `selected_cells` scope を追加してから使う。手入力やActions側入力で対象を上書きしてはいけない。
- `selected_cells` は先行確認・段階デプロイ用の一時運用とし、最終的には `all_active_cells` で全 active cell のコードを揃える。
- `selected_cells` を実装するまでは、Release Plan の正規対象は `single_cell` または `all_active_cells` のみとする。

標準の流れ:

1. `single_cell` で `POSLA運用確認用 / test-01` にデプロイし、smoke と画面確認をする。
2. 必要なら `selected_cells` を実装したうえで、少数 cell へ段階デプロイする。
3. 問題がなければ `all_active_cells` に切り替え、全 active cell へ同じコードを反映する。
4. コードが全 cell に入っていても、Feature Flags が OFF の間は顧客には表示しない。
5. 先行提供は `tenant` または `cell` scope、全体公開は最後に `global` scope を使う。

GitHub Actions などの自動化は、管理画面用 `api/posla/release-plan.php` ではなく、読み取り専用の `api/deploy/release-plan.php` を使います。

- Endpoint: `GET /api/deploy/release-plan.php`
- 認証: `Authorization: Bearer <GitHub Secret>`
- POSLA側Token保存先: `設定センター > Actions連携 > Release Plan Token`
- GitHub側Secret名: `POSLA_RELEASE_PLAN_TOKEN`
- workflow gate: `.github/workflows/release-plan-gate.yml`
- Token未設定: APIは `503 RELEASE_PLAN_TOKEN_NOT_CONFIGURED`
- Token不一致: APIは `401 UNAUTHORIZED`
- `manual_only`: APIは成功レスポンスを返すが `deploy_allowed=false`。Actionsは停止する。
- `actions_allowed`: `single_cell` または `all_active_cells` の対象だけを候補にする。
- Feature Flags: このAPIでは変更しない。

Actionsからの取得例:

```bash
curl -fsS \
  -H "Authorization: Bearer ${POSLA_RELEASE_PLAN_TOKEN}" \
  "https://meal.posla.jp/api/deploy/release-plan.php"
```

返却される `decision.target_cell_ids` が実行対象です。GitHub側で手入力した cell や環境指定がある場合でも、POSLA側Release Planと一致しなければ停止します。UI設定を正にするため、Actions側の入力値で対象を上書きしてはいけません。

`.github/workflows/release-plan-gate.yml` はデプロイ自体を行いません。手動実行または将来のdeploy workflowから `workflow_call` で呼び出し、`deploy_allowed` / `target_scope` / `target_cell_ids` を次のjobへ渡すためのgateです。

deploy 後の smoke:

```bash
scripts/cell/cell.sh acme-prod smoke
POSLA_CELL_SMOKE_STRICT=1 scripts/cell/cell.sh acme-prod smoke
```

`smoke` は次を確認します。

| 確認 | 内容 |
|---|---|
| app ping | `/api/monitor/ping.php` が `ok=true` を返す |
| cell metadata | ping の `cell_id` / `deploy_version` が env と一致する |
| DB | cell DB に接続できる |
| migration ledger | `schema_migrations` が存在する |
| registry | `posla_cell_registry` / `posla_cell_deployments` が存在し、対象 cell 行がある |

初回起動直後は registry migration 前のため non-strict で確認してよいです。P1-40 / P1-41 適用後の通常運用では `POSLA_CELL_SMOKE_STRICT=1` を使い、metadata 不備を失敗として扱います。

## 11.7 Migration

全 cell 一括 migration は禁止です。必ず対象 cell を決めて、backup -> migrate -> smoke の順で進めます。

```bash
scripts/cell/cell.sh acme-prod migrate sql/migration-p1-40-cell-migration-ledger.sql
scripts/cell/cell.sh acme-prod migrate sql/migration-p1-41-cell-registry.sql
scripts/cell/cell.sh acme-prod register-db
scripts/cell/cell.sh acme-prod ping
```

初期 ledger:

- `sql/migration-p1-40-cell-migration-ledger.sql`
- 各 cell DB に `schema_migrations` を作成する
- `scripts/cell/cell.sh <cell-id> migrate ...` は migration key / checksum / deploy version / applied_by を記録する
- 適用済み migration は二重適用せず `Migration already applied` で終了する

Cell registry:

- `sql/migration-p1-41-cell-registry.sql`
- `posla_cell_registry` に cell の app / DB / uploads / image / deploy version を記録する
- `posla_cell_deployments` に deploy 履歴を残すためのテーブルを用意する
- `register-db` は現在の `cells/<cell-id>/app.env` / `cell.env` を読んで `posla_cell_registry` に upsert する

## 11.8 緊急修正フロー

1. 問題が出ている cell を特定する。
2. 修正 branch を作り、POSLA コードを修正する。
3. `scripts/cell/cell.sh <cell-id> build` で同一 artifact を build し、対象 cell の `POSLA_PHP_IMAGE` / `POSLA_DEPLOY_VERSION` を刻印する。
4. 対象 cell を `backup` する。
5. 必要な migration を対象 cell だけに適用する。
6. `register-db` で対象 cell の deploy metadata を更新する。
7. 対象 cell だけ `deploy` する。
8. `ping` と業務 smoke test を実行する。
9. 問題なければ他 cell へ ring deploy する。

## 11.9 Rollback

rollback は code image だけでは不十分です。対象 cell 単位で次を確認します。

| 対象 | rollback 方針 |
|---|---|
| app | 前 image tag に戻す |
| DB | migration 前 backup から restore。expand migration は原則戻さない |
| uploads | cell 専用 backup / object storage versioning |
| cron | `POSLA_CRON_ENABLED` と scheduler 多重起動を確認 |
| webhook | 対象 cell の callback URL だけ戻す |
| feature flag | 対象 tenant / cell の flag を戻す |

deploy失敗時の最小手順:

```bash
# 1. rollback対象backupを確認
scripts/cell/cell.sh acme-prod backups
scripts/cell/cell.sh acme-prod rollback-plan latest

# 2. envだけ戻す
POSLA_CELL_RESTORE_CONFIRM=acme-prod scripts/cell/cell.sh acme-prod restore-env latest

# 3. DBを戻す必要がある場合だけ db.sql を restore
POSLA_CELL_RESTORE_CONFIRM=acme-prod scripts/cell/cell.sh acme-prod restore-db latest

# 4. 対象cellだけ再deploy
scripts/cell/cell.sh acme-prod deploy
```

all-in-one で戻す場合:

```bash
POSLA_CELL_RESTORE_CONFIRM=acme-prod scripts/cell/cell.sh acme-prod rollback latest
```

安全ガード:

- `restore-env` / `restore-db` / `rollback` は `POSLA_CELL_RESTORE_CONFIRM=<cell-id>` がないと実行しない
- `restore-db` は backup 内の `db.sql` を cell DB に戻す破壊的操作
- `restore-db` は trigger DEFINER を含む dump を戻すため、cell DB コンテナ内の root credential で実行する
- restore 後は `posla_cell_deployments` に `rolled_back` を記録する
- all-in-one `rollback` は env restore -> DB restore -> deploy の順に実行する

## 11.10 Feature Flag

Feature Flag は契約機能ではなく、顧客向け機能の rollout / preview 制御に使います。`hq_menu_broadcast` のような契約アドオンは引き続き `tenants` と billing 側で管理し、Feature Flag には混ぜません。

デプロイ対象 cell は POSLA 側の Cell配備 / リリース計画で管理します。先行確認は `single_cell`、コード統一を目的にした通常リリースは `all_active_cells` を使います。OP 側は監視、runner、実行承認、証跡を管理します。POSLA管理画面の Feature Flags はデプロイ先を決める場所ではなく、顧客向け機能を「誰に見せるか」だけに限定します。

DB:

- `posla_feature_flags`: flag 定義。MVP の初期値はすべて default OFF。
- `posla_feature_flag_overrides`: `global` / `cell` / `tenant` scope の上書き。

解決順:

```text
default
  -> global override
  -> cell override
  -> tenant override
```

POSLA管理画面の `Feature Flags` タブから、対象 tenant を選んで解決結果を確認し、`cell` または `tenant` scope で override します。MVP では緊急修正や先行検証は `tenant` または `cell` scope を優先し、`global` は全 cell へ影響するため最後に使います。

使い方:

1. 新しい顧客向け機能は、原則として `default_enabled=0` で追加する。
2. コードはデプロイ済みでも、Feature Flag が OFF の間は顧客に表示しない。
3. 先行確認のデプロイは Release Plan の `single_cell` で `POSLA運用確認用 / test-01` を選ぶ。
4. 複数のお客様だけへ提供する場合は、コードの複数 cell デプロイではなく、対象顧客の `tenant` scope で ON にする。
5. コードを統一する時は Release Plan の `all_active_cells` で全 active cell へ同じコードを反映する。
6. 先行確認の公開は `POSLA運用確認用 / test-01` の `cell` scope で ON にする。
7. 全顧客へ公開する時だけ `global` scope を ON にする。`global` 変更は理由必須。
8. 問題が出た場合は、まず対象 `tenant` / `cell` override を OFF または解除する。コード rollback より先に flag rollback で影響を止められるか確認する。

Actions などでデプロイを自動化する場合も、デプロイと Feature Flag の公開判断は分ける。Actions は POSLA側の Release Plan API に保存された対象範囲だけを候補にし、コード反映・migration・smoke までを担当する。`single_cell` は先行確認、`selected_cells` は実装後の段階デプロイ、`all_active_cells` はコード統一のための全体反映として扱う。`selected_cells` 未実装の状態でActions側の入力値だけで複数 cell を選ばない。`global` scope は自動で ON にしない。自動化フローに Feature Flag API を組み込む場合は、明示承認済みの release plan と変更理由を持つ場合だけ `cell` / `tenant` / `global` を変更する。

初期 flag:

| feature_key | 用途 |
|---|---|
| `support_console_v2` | POSLA管理画面サポート導線の段階展開 |
| `tenant_preview_release` | 特定 tenant/cell への先行検証導線 |

適用 migration:

```bash
scripts/cell/cell.sh acme-prod migrate sql/migration-p1-42-feature-flags.sql
```

rollback では、まず対象 tenant / cell の override を OFF または解除します。コードを戻す前に flag だけで影響を止められる場合は、DB restore より flag rollback を優先します。

## 11.11 外部運用基盤接続

POSLA は、MVP では外部運用基盤に対して次の read-only 情報だけを提供します。

- app: `https://<production-domain>/api/monitor/ping.php`
- cell snapshot: `https://<production-domain>/api/monitor/cell-snapshot.php`
- DB: cell ごとの read-only account
- docs: 対象 cell の運用ドキュメント

コード修正は外部運用基盤から直接コンテナへ入って行いません。正本 repo で修正し、build artifact を作り、POSLA側で指定された対象 cell へ反映します。deploy 実行の承認、runner、証跡は OP 側の policy / approval で扱い、対象 cell の設定と顧客向け Feature Flags には混ぜません。

### 11.11.0 障害報告 / 監視アラート bridge

テナント画面の障害報告窓口から、障害報告を設定済みの外部運用基盤へ送れます。
`monitor-health.php` で検知した監視アラートも、同じ外部運用基盤へ送れます。送信後の分類・調査・通知は POSLA 外の運用基盤側で扱います。

POSLA側:

- API: `/api/support/op-case.php`
- 監視: `/api/cron/monitor-health.php`
- POSLA管理画面: `API設定 > 外部障害報告 / 監視アラート連携`
- 障害報告 設定キー: `codex_ops_case_endpoint`, `codex_ops_case_token`
- 監視アラート 設定キー: `codex_ops_alert_endpoint`, `codex_ops_alert_token`
- env override: `POSLA_OP_CASE_ENDPOINT`, `POSLA_OP_CASE_TOKEN`, `POSLA_OP_ALERT_ENDPOINT`, `POSLA_OP_ALERT_TOKEN`

接続先側:

- 障害報告 Endpoint: `https://<ops-domain>/api/ingest/posla-case`
- 監視アラート Endpoint: `https://<ops-domain>/api/alerts.php`
- 障害報告 env/header: `POSLA_OPS_CASE_TOKEN` / `X-OPS-CASE-TOKEN`
- 監視アラート env/header: `OPS_ALERT_INGEST_TOKEN` / `X-OPS-ALERT-TOKEN`

送信される主な情報:

- 顧客名 / tenant_id / tenant_slug
- cell_id / environment / deploy_version / source_id
- store_id / store_name
- errorNo / 障害報告本文 / 発生時刻
- 画面名 / 画面URL / ログインユーザー / role / user_agent

`codex_ops_case_token` と `codex_ops_alert_token` は秘密値として扱い、GETでは実値を返しません。endpoint は非秘密値として管理画面に表示します。

### 11.11.1 read-only snapshot contract

`/api/monitor/cell-snapshot.php` は外部運用基盤用の read-only 契約です。公開 ping より詳細な情報を返すため、`X-POSLA-OPS-SECRET` または `X-POSLA-CRON-SECRET` が必須です。

環境変数:

```bash
POSLA_OPS_READ_SECRET=<shared-read-secret>
```

リクエスト例:

```bash
curl -H "X-POSLA-OPS-SECRET: <shared-read-secret>" \
  https://<production-domain>/api/monitor/cell-snapshot.php
```

返す主な情報:

| key | 内容 |
|---|---|
| `contract_version` | `codex-ops-cell-snapshot-v1` |
| `cell_id` / `deploy_version` / `environment` | 現在の app metadata |
| `source_id` / `ops_source` | 外部運用基盤が snapshot を読む POSLA control/source endpoint。顧客 cell ではない |
| `db` | DB接続状態 |
| `cron` | `monitor_last_heartbeat` と遅延秒数 |
| `registry.cell` | 現在の app が顧客 cell の場合だけ、その顧客 cell 行 |
| `registry.control_cell` | v1 後方互換の項目。control/source は `ops_source` を使う |
| `registry.cells` | `tenant_id` / `tenant_slug` を持つ顧客専用 cell 一覧。`posla-control-local` のような control source は含めない |
| `deployments` | `posla_cell_deployments` の直近履歴 |
| `migrations` | `schema_migrations` の直近履歴と24h失敗数 |
| `feature_flags` | `global/cell` 解決後の flag 状態 |
| `errors` | `error_log` の直近集計。Tier 0 系は別枠 |
| `monitor_events` | 未解決イベント |
| `tier0` | 決済・レジの最重要監視指標 |

Tier 0 監視では、まず次を MVP 指標にします。

- 決済・レジ・スマレジ・領収書系 `errorNo` の直近増加
- `pending_payment` のまま15分以上残る注文
- `refund_status='pending'` のまま10分以上残る決済
- `emergency_payments` の未解決件数
- 外部決済の `gateway_status` 分布

Tier 0 の詳細項目は、運用基盤側で人間がすぐ判断できるように、可能な限り `tenant_id` / `tenant_slug` / `tenant_name` / `store_id` / `store_slug` / `store_name` を含めます。

| key | 内容 |
|---|---|
| `pending_payment_orders` | 15分以上 `pending_payment` の注文。`order_id`, 金額, tenant/store 表示名を含む |
| `pending_refunds` | 10分以上 `refund_status='pending'` の決済。`payment_id`, gateway, tenant/store 表示名を含む |
| `gateway_problem_payments` | 24時間以内の gateway status 異常候補。`payment_id`, gateway, tenant/store 表示名を含む |
| `emergency_unresolved_items` | 未解決の緊急会計。`emergency_payment_id`, table, 金額, tenant/store 表示名を含む |

`errors.recent_5m` / `errors.tier0_15m` と `monitor_events.unresolved` も、`tenant_id` / `store_id` だけでなく顧客名・店舗名を返します。運用基盤側の一覧では、IDだけではなく `tenant_name` と `store_name` を優先表示します。

外部運用基盤側の初期 collector は、この snapshot を各 cell から定期取得し、`ok=false`、`tier0.status != ok`、または Tier 0 error 件数増加を最優先で表示します。

### 11.11.2 errorNo 表示契約

`json_error()` のレスポンスには必ず `error.errorNo` を含めます。

- 既存カタログ登録済み code: `E3035` のような正式番号を返す
- 未カタログ code: `E0xxx` の暫定番号を安定生成して返す
- `E0xxx` が出た場合: その code を正式カタログへ追加し、`scripts/generate_error_catalog.py` で再生成する
- フロント側は `Utils.formatError()` / `Utils.createApiError()` を使い、APIレスポンス全体の `error.errorNo` と `serverTime` を落とさず表示する
- 直接 `json.error.message` だけを表示する実装は禁止。例外的に画面専用文言へ置き換える場合も、問い合わせコードと発生時刻は残す

顧客画面では次の3点を問い合わせ情報として出せる状態にします。

```text
お問い合わせコード: E3035
発生時刻: 2026-04-26 10:15
画面または操作: 会計 / 注文 / ログイン など
```

### 11.11.3 POSLA control/source endpoint

`posla_ops_sources` は、外部運用基盤が POSLA を読む入口です。顧客に提供する app / DB / uploads の単位ではないため、`posla_cell_registry` とは分けて管理します。

MVP のローカル source:

| 項目 | 値 |
|---|---|
| source_id | `posla-control-local` |
| ping_url | `http://127.0.0.1:8081/api/monitor/ping.php` |
| snapshot_url | `http://127.0.0.1:8081/api/monitor/cell-snapshot.php` |

POSLA管理画面の **外部運用連携** タブから source metadata を確認・編集できます。接続先側には `snapshot_url` と認証ヘッダーを登録し、そこから `registry.cells` を同期します。

### 11.11.4 検証済みローカルcell

2026-04-26 時点で、擬似本番 control DB から `test-01` を 1 tenant / 1 cell として作成し、次を確認済みです。

| 項目 | 結果 |
|---|---|
| app URL | `http://127.0.0.1:18081` |
| DB | `127.0.0.1:13306` / `posla_test_01` |
| tenant | `TEST` / `test-01` |
| store | `TEST 本店` |
| owner | `test-01-owner` / `TEST オーナー` |
| ops users | `test-01-manager` / `test-01-staff` / `test-01-kds01` を `ensure-ops-users` で補完済み |
| smoke | `POSLA_CELL_SMOKE_STRICT=1 scripts/cell/cell.sh test-01 smoke` 通過 |
| rollback | `POSLA_CELL_RESTORE_CONFIRM=test-01 scripts/cell/cell.sh test-01 rollback latest` 通過 |
| login | `test-01-owner` で専用cellへログイン成功 |
| feature flag | `tenant_preview_release` を tenant scope で一時ON/OFFし、解決結果が `tenant` -> `default` に戻ることを確認 |
| snapshot | control snapshot で `onboarding_progress=100`, `health_score=100`, `cell_tier0_status=ok` を確認 |

この検証で、対象cellだけの backup -> rollback -> deploy -> smoke が成立し、他cellや擬似本番 control stack を巻き込まないことを確認しました。

## 11.12 リリース前チェックリスト

POSLA側のリリース前には、少なくとも次を確認します。

| 項目 | 確認内容 |
|---|---|
| 顧客追加 | LP申込 / POSLA管理画面追加の両方で `posla_tenant_onboarding_requests` に記録される |
| Cell配備 | **Cell配備** タブに顧客名・店舗名・cell_id・衝突しない推奨port・実行コマンドが表示される |
| 支払い前失敗 | Stripe customer / checkout 作成前段で失敗した申込は `canceled` になり、配備待ちに残らない |
| deploy | 対象cellだけ `backup -> deploy -> smoke` できる |
| migration | 対象cellだけ `migrate` でき、`schema_migrations` に記録される |
| rollback | `POSLA_CELL_RESTORE_CONFIRM=<cell_id>` がない restore / rollback は停止する |
| errorNo | 顧客画面・管理画面・会計・予約・KDS/ハンディで `errorNo` と発生時刻が表示される |
| snapshot | control/source の `/api/monitor/cell-snapshot.php` に `registry.cells` / `onboarding` / `tier0` / `feature_flags` が出る |
| リリース準備 | POSLA管理画面ダッシュボードの「リリース準備状況」が `リリース準備OK` になる |
| 外部運用連携 | 接続先側は `snapshot_url` と認証ヘッダーで read-only 取得し、`tenant_name` / `store_name` を表示する |

## 11.13 Git 管理名

この擬似本番環境の commit / push は、GitLab などの正本 remote が確定するまで `meal.posla.jp` を作業識別名として扱います。

推奨 branch / tag 例:

```bash
git switch -c meal.posla.jp/cell-architecture
git commit -m "meal.posla.jp: add cell deployment controls"
git push origin meal.posla.jp/cell-architecture
```

remote alias は実際の GitLab URL が確定してから追加します。未確定の URL に対して `git remote add` しません。

## 11.14 重要な禁止事項

- `api/.htaccess` に `POSLA_DB_*` や URL 値を固定しない。
- 全 cell に対して一括 migration しない。
- 1 cell 内で scheduler を複数起動しない。
- 顧客固有要件でコードを fork しない。まず feature flag / env / DB 設定で吸収する。
- demo 環境と擬似本番環境を `rsync --delete` しない。
