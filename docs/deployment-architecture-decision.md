# POSLA 配備アーキテクチャ設計メモ

作成日: 2026-04-25
最終更新: 2026-04-26
対象: `【擬似本番環境】meal.posla.jp`
目的: 顧客別の緊急修正や検証で、他顧客を巻き込まない配備単位を最初から決める。

## 結論

POSLA は **cell architecture** で開始する。

MVP では **1 tenant / 1 cell** を原則にする。これは single-tenant deployment と同じ blast radius を持たせるための運用ポリシーであり、コードベースは分けない。顧客向け cell に他 tenant を同居させない。

`posla-control-local` のような擬似本番 / control 用 source は顧客 cell ではない。codex-ops-platform が snapshot を読む入口として使うが、`registry.cells` に出す一覧は `tenant_id` / `tenant_slug` を持つ顧客専用 cell だけにする。source 自体は `posla_ops_sources` で管理し、`posla_cell_registry` には入れない。

採用しないもの:

- shared single stack: deploy / migration / cron / DB 障害が全顧客へ同時波及するため、最優先要件と合わない。
- コードフォーク型 single-tenant: blast radius は最小だが、顧客ごとのコード差分が増え、修正管理と AI 運用支援の対象管理が破綻しやすい。

## 現状分析

現在の擬似本番 Docker は 1 app + 1 DB の shared single stack である。

- `docker-compose.yml` は `php` と `db` の 2 サービス構成。
- `php` は `api/`, `public/`, `scripts/`, `uploads/` を bind mount する。
- `db` は MySQL 5.7 の named volume を 1 つ持つ。
- scheduler は app コンテナ内の `cron-loop.sh` が `monitor-health.php` を 5 分ごとに実行する。

アプリと DB の設計は multi-tenant 前提がかなり入っている。

- 認証セッションには `tenant_id`, `role`, `store_ids` を保持する。
- `require_store_access()` は owner でも `stores.tenant_id = session.tenant_id` を確認する。
- 多くの業務テーブルは `tenant_id` または `store_id` を持つ。
- `stores` は `tenant_id` 配下、`users` も `tenant_id` 配下。
- POSLA 管理者系の `posla_admins`, `posla_settings` は tenant に属さないグローバルテーブル。

つまり、データモデルは multi-tenant SaaS に寄っているが、配備単位はまだ single stack である。

## 推奨 cell 構成

1 cell は次を 1 セットで持つ。

- app: 同一 image / 同一 build artifact の PHP Apache コンテナ
- DB: cell 専用 MySQL database または MySQL instance
- scheduler: cell 専用 worker / cron
- storage: cell 専用 `uploads/` volume または object storage prefix
- cache / rate limit: MVP は app local でも可。複数 app replica 化する時点で cell 専用 Redis
- logs / monitor: cell_id を含む監視 endpoint とログ集約

推奨 domain / routing:

- MVP は tenant ごとに専用 host を割り当てる。
- `POSLA_APP_BASE_URL`, `POSLA_ALLOWED_ORIGINS`, `POSLA_ALLOWED_HOSTS` は cell ごとの env にする。
- 同一 public host で全 cell を受ける場合は、ログイン前に routing 先 cell が決まらないため、中央 login router が必要になる。

## 1 build artifact / 複数 deploy target

可能。

方針:

- repo は 1 つ。
- build artifact は `posla-app:<git-sha>` のように 1 つ。
- deploy 先を `cell_id` ごとに分ける。
- 緊急修正は対象 cell だけを新 version に pin する。
- 問題なければ canary cell から ring deploy する。

顧客固有修正は、原則コード分岐ではなく次で吸収する。

- tenant / cell feature flag
- env
- DB 設定
- UI 表示設定

## MVP で最初に必要な最小変更

1. `POSLA_CELL_ID`, `POSLA_DEPLOY_VERSION`, `POSLA_ENVIRONMENT` を app config に追加し、`/api/monitor/ping.php` で確認できるようにする。
2. `POSLA_CRON_ENABLED` を追加し、app replica を増やしても scheduler が重複起動しないようにする。
3. cell ごとの env template を作る。最低限 `POSLA_DB_*`, `POSLA_APP_BASE_URL`, `POSLA_ALLOWED_*`, `POSLA_PHP_ERROR_LOG`, `POSLA_CRON_SECRET` を cell 単位にする。
4. migration ledger を追加する。各 cell に「どの migration をいつ適用したか」を記録し、cell 単位で backup -> migrate -> smoke -> promote できるようにする。
5. uploads を cell 専用 volume / prefix に分ける。
6. codex-ops-platform 側の target descriptor を cell 単位にする。`base_url`, DB 接続, docs root, verify pack を `cell_id` で束ねる。
7. POSLA 管理画面の扱いを決める。MVP は各 cell の POSLA 管理画面で手動管理でもよいが、早期に中央 control plane へ寄せる。

2026-04-25 時点の実装状況:

- 1 と 2 は最小実装済み。
- `docker/env/app.env.example` に cell / deploy metadata の env を追加済み。
- `docker-compose.cell.yml`, `cells/example/`, `scripts/cell/cell.sh` で cell 起動テンプレートを追加済み。
- `docker/php/Dockerfile.cell` で app code を image に焼き込む cell artifact build を追加済み。runtime の code bind mount は使わず、uploads のみ cell volume として mount する。
- cell DB 初期化は `docker/db/cell-init/` に分離済み。schema / schema migration のみを読み込み、ローカル開発用 demo seed と demo tenant/user data migration は読み込まない。
- `sql/migration-p1-40-cell-migration-ledger.sql` で cell DB ごとの migration ledger 初期テーブルを追加済み。
- `sql/migration-p1-41-cell-registry.sql` で cell registry / deployment history の初期テーブルを追加済み。
- `scripts/cell/cell.sh init` で `cells/<cell-id>/` の env 生成と `cells/registry.tsv` 記録が可能。
- `scripts/cell/cell.sh register-db` で対象 cell DB の `posla_cell_registry` へ自己メタデータを upsert 可能。
- `posla_ops_sources` で codex-ops-platform が読む POSLA control/source endpoint を管理する。source は顧客 cell ではないため `posla_cell_registry` と分離する。
- `scripts/cell/cell.sh migrate` は `schema_migrations` に checksum / cell_id / deploy_version を記録し、二重適用を防止する。
- `scripts/cell/cell.sh backup` は cell 単位で DB dump / env snapshot / uploads manifest を作る。
- `scripts/cell/cell.sh deploy` は pre-deploy backup を作り、`posla_cell_deployments` に `planned` / `deployed` / `failed` を記録する。
- `scripts/cell/cell.sh rollback-plan`, `restore-env`, `restore-db`, `rollback` で backup から対象 cell だけを戻せる。
- restore 系コマンドは `POSLA_CELL_RESTORE_CONFIRM=<cell-id>` 必須。`restore-db` は root credential で実行し、`rolled_back` 履歴を記録する。
- `scripts/cell/cell.sh smoke` で app ping / cell metadata / DB接続 / migration ledger / registry / deploy履歴を cell 単位で確認できる。
- `scripts/cell/cell.sh onboard-tenant` で対象 cell DB のみに初期 tenant / store / owner を作成し、cell registry に tenant metadata を反映できる。
- POSLA管理画面に **Cell配備** タブを追加し、`posla_tenant_onboarding_requests` と `posla_cell_registry` を見ながら、顧客ごとの専用cell作成コマンドを確認できる。UIはコマンドを直接実行せず、このMacでの承認実行を前提にする。
- `api/posla/cell-provisioning.php` で Cell配備キューのGET、作業状態更新、control registry active 同期を提供する。
- `cells/registry.tsv`, `cells/*/*.env`, `cells/*/uploads/`, `cells/*/backups/` は runtime 実値・秘密値・DB dump を含むため git 管理しない。
- `scripts/cell/cell.sh` の MySQL / mysqldump / restore は `--default-character-set=utf8mb4` を明示し、日本語の tenant / store / owner 名を壊さない。
- `api/.htaccess` の固定 `SetEnv POSLA_DB_*` / URL 値を外し、Docker / cell の env_file を正にした。
- `cell-rollbacktest` で init -> deploy -> backup -> rollback-plan -> restore-db guard -> restore-db -> rollback -> deploy history を確認済み。検証後、cell-rollbacktest のコンテナ / volume / 一時設定は削除済み。
- `cell-onboardtest` で init -> deploy -> migration -> register-db -> onboard-tenant -> smoke -> login API を確認済み。
- `test-01` で control DB の onboarding request / registry と専用cellを同期し、`http://127.0.0.1:18081` / `posla_test_01` として 1 tenant / 1 cell を作成済み。
- `test-01` で backup -> rollback-plan -> restore guard -> rollback -> strict smoke -> owner login を確認済み。rollback は `POSLA_CELL_RESTORE_CONFIRM=test-01` がない場合に停止し、確認付き実行後は対象cellだけ復元・再deployされた。

## Git 作業識別名

この擬似本番環境から commit / push する場合は、GitLab などの正本 remote が確定するまで `meal.posla.jp` を branch / tag の識別名として使う。

例:

```bash
git switch -c meal.posla.jp/cell-architecture
git commit -m "meal.posla.jp: add cell deployment controls"
git push origin meal.posla.jp/cell-architecture
```

remote alias は実際の URL が確定してから追加する。未確定 URL に対して `git remote add` はしない。

## 運用設計

deploy:

- cell 単位で deploy する。
- canary cell -> 対象 tenant cell -> ring の順に進める。
- deploy は image tag と env version を記録する。

schema migration:

- 全 cell 一括実行は禁止。
- cell ごとに backup を取得し、migration ledger lock を取って実行する。
- expand / contract を基本にし、破壊的 ALTER は別 window に分ける。

queue / job:

- job は必ず cell 内で閉じる。
- 将来 queue を入れる場合は cell 専用 queue を使う。
- job payload には `cell_id`, `tenant_id`, `store_id` を含める。

cache:

- per-cell namespace を必須にする。
- 複数 app replica 化する場合は file based rate limiter を Redis へ寄せる。

feature flag:

- tenant flag と cell flag を分ける。
- 緊急修正の回避策はまず flag で局所化する。

tenant onboarding:

- cell 作成 -> DB 初期化 -> env 設定 -> tenant 初期作成 -> smoke -> DNS / webhook 有効化の順にする。
- onboarding 完了時に codex-ops-platform の target registry に cell を登録する。

rollback:

- rollback 対象は code image だけではなく DB / env / uploads / webhook を含む。
- まず対象 cell だけを戻す。
- migration 後 rollback は restore が必要になる可能性を前提にする。

## 将来移行

cell -> shared:

- 低リスク tenant を同一 cell に収容するだけでよい。コード変更は最小。

cell -> single-tenant:

- 既に 1 tenant / 1 cell なら実質完了。複数 tenant cell から分離する場合は tenant scoped export / import が必要。

shared -> cell:

- 最も難しい。tenant scoped export、外部キー整合、uploads 分離、webhook 再配線が必要。顧客ゼロの今避けるべき移行。

## リスク

- POSLA 管理画面と `posla_settings` は現在グローバル DB 前提なので、cell 分割時は設定同期または中央 control plane が必要。
- Stripe / Smaregi / LINE など webhook は、どの cell に配送するかを明確にする必要がある。
- cell 数が増えると運用対象は増えるため、codex-ops-platform の target registry / probe / verify が必須になる。
- cross-cell analytics は単一 DB の SQL では取れない。将来は集計 DB / ETL が必要。

## 判断

MVP は **cell architecture + 1 tenant / 1 cell** で始める。これにより、顧客別緊急修正は対象 cell だけへ deploy でき、将来は同じ codebase / artifact のまま cell 数や tenant 収容数を調整できる。
