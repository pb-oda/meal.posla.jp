---
title: Cell配備運用
chapter: 11
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [cell, deployment, blast-radius, docker, migration, rollback]
last_updated: 2026-04-25
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

`cell.env` では同一ホスト上で衝突しない port と image artifact を指定します。

```bash
POSLA_CELL_HTTP_PORT=18081
POSLA_CELL_DB_PORT=13306
POSLA_PHP_IMAGE=posla_php_cell:dev
```

`init` は placeholder password を残します。本番cellでは `app.env` / `db.env` の `__REPLACE_*` を必ず実値に置換してから `deploy` します。

## 11.4 Tenant Onboarding

cell 作成後、対象 cell DB に最初の tenant / store / owner を作ります。

標準手順:

```bash
scripts/cell/cell.sh acme-prod deploy
scripts/cell/cell.sh acme-prod migrate sql/migration-p1-40-cell-migration-ledger.sql
scripts/cell/cell.sh acme-prod migrate sql/migration-p1-41-cell-registry.sql
scripts/cell/cell.sh acme-prod register-db

POSLA_OWNER_PASSWORD='<initial-owner-password>' \
  scripts/cell/cell.sh acme-prod onboard-tenant acme 'Acme Inc.' 'Acme Main Store' owner 'Owner Name' owner@example.com

scripts/cell/cell.sh acme-prod smoke
```

`onboard-tenant` が作るもの:

| テーブル | 内容 |
|---|---|
| `tenants` | `is_active=1`, `subscription_status=trialing` の初期 tenant |
| `stores` | 1店舗目。`slug=default` |
| `store_settings` | 存在する場合だけ初期行 |
| `users` | owner ユーザー。password は `password_hash()` で保存 |
| `user_stores` | owner と 1店舗目の紐付け |
| `posla_cell_registry` | registry table がある場合だけ tenant_id / tenant_slug / tenant_name を反映 |

環境変数:

| 変数 | 用途 |
|---|---|
| `POSLA_OWNER_PASSWORD` | owner 初期パスワード。必須 |
| `POSLA_STORE_SLUG` | 店舗 slug。省略時 `default` |
| `POSLA_SUBSCRIPTION_STATUS` | `none` / `trialing` / `active` など。省略時 `trialing` |
| `POSLA_HQ_MENU_BROADCAST` | 本部一括メニュー配信アドオン。省略時 `0` |

注意:

- `onboard-tenant` は対象 cell DB のみに書き込みます。
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

## 11.6 Build / Deploy

初回または artifact を作る時:

```bash
scripts/cell/cell.sh acme-prod config
scripts/cell/cell.sh acme-prod build
```

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
3. 同一 artifact を build する。
4. 対象 cell のみ `POSLA_PHP_IMAGE` / `POSLA_DEPLOY_VERSION` を更新する。
5. 対象 cell を `backup` する。
6. 必要な migration を対象 cell だけに適用する。
7. `register-db` で対象 cell の deploy metadata を更新する。
8. 対象 cell だけ `deploy` する。
9. `ping` と業務 smoke test を実行する。
10. 問題なければ他 cell へ ring deploy する。

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

## 11.10 codex-ops-platform 接続

`【AI運用支援】codex-ops-platform` は、MVP では次の read-only 接続から始めます。

- app: `https://<production-domain>/api/monitor/ping.php`
- DB: cell ごとの read-only account
- docs: 対象 cell の運用ドキュメント

コード修正は codex-ops-platform から直接コンテナへ入って行いません。正本 repo で修正し、build artifact を作り、承認された deploy コマンドで対象 cell へ反映します。

## 11.11 Git 管理名

この擬似本番環境の commit / push は、GitLab などの正本 remote が確定するまで `meal.posla.jp` を作業識別名として扱います。

推奨 branch / tag 例:

```bash
git switch -c meal.posla.jp/cell-architecture
git commit -m "meal.posla.jp: add cell deployment controls"
git push origin meal.posla.jp/cell-architecture
```

remote alias は実際の GitLab URL が確定してから追加します。未確定の URL に対して `git remote add` しません。

## 11.12 重要な禁止事項

- `api/.htaccess` に `POSLA_DB_*` や URL 値を固定しない。
- 全 cell に対して一括 migration しない。
- 1 cell 内で scheduler を複数起動しない。
- 顧客固有要件でコードを fork しない。まず feature flag / env / DB 設定で吸収する。
- demo 環境と擬似本番環境を `rsync --delete` しない。
