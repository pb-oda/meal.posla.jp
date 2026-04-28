# POSLA Provisioner Runner

作成日: 2026-04-28
対象: `【擬似本番環境】meal.posla.jp`

## 1. 役割

`posla-provisioner` は Cloud Run 上の POSLA web から trigger を受け、専用VM上で `scripts/cell/provision-ready-cells.php` を実行するコンテナです。

実行すること:

- `posla_tenant_onboarding_requests.status = ready_for_cell` の申込を拾う
- `scripts/cell/cell.sh init/build/deploy/onboard-tenant/smoke` を実行する
- tenantごとの `app` / `db` コンテナを `docker-compose.cell.yml` で作成する
- 成功/失敗を control DB に記録する
- 準備完了メールを送る

## 2. 構成

```text
Cloud Run: POSLA web
  |
  | POST /run
  v
GCE VM: provisioner dedicated host
  └─ posla_provisioner container
       - /var/run/docker.sock
       - /srv/posla
       - scripts/cell/provisioner-trigger-server.php
       - scripts/cell/provision-ready-cells.php
       - scripts/cell/cell.sh
       |
       +─ posla_<cell>_php
       +─ posla_<cell>_db
```

## 3. 起動

VM上のrepoを `/srv/posla` に配置する。

`POSLA_PROVISIONER_ROOT_DIR` は host / container で同じ絶対パスにする。provisioner container内の `docker compose` は host docker.sock を叩くため、bind mount の host path が一致していないと cell の volume mount がずれる。

```bash
cd /srv/posla
cp docker/env/provisioner.env.example docker/env/provisioner.env
vi docker/env/provisioner.env
set -a
. docker/env/provisioner.env
set +a
docker compose -f docker-compose.provisioner.yml up -d --build
```

疎通確認:

```bash
curl -sf http://127.0.0.1:19091/health
```

## 4. Cloud Run 側

Cloud Run web service には以下を設定する。

```dotenv
POSLA_PROVISIONER_TRIGGER_URL=https://<provisioner-service-url>/run
POSLA_PROVISIONER_ALLOW_REMOTE_TRIGGER=1
POSLA_PROVISIONER_TRIGGER_SECRET=__REPLACE_PROVISIONER_TRIGGER_SECRET__
POSLA_PROVISIONER_TRIGGER_TIMEOUT_SEC=2
```

`POSLA_PROVISIONER_TRIGGER_SECRET` は provisioner VM 側と完全一致させる。

## 5. セキュリティ

`posla_provisioner` は `/var/run/docker.sock` をmountするため、実質的にVM管理者権限を持つ。

必須:

- provisioner専用VMにする
- VM firewall / Load Balancer / IAP 等で `/run` への到達元を制限する
- `POSLA_PROVISIONER_TRIGGER_SECRET` を Secret Manager / パスワード金庫で管理する
- `POSLA_PROVISIONER_BIND_ADDR=127.0.0.1` を既定にし、外部公開する場合は前段proxyでTLS終端する
- `cells/` と Docker volumes をVM snapshot / backup対象にする
- OP alert で失敗を監視する

## 6. 同時実行

`scripts/cell/provision-ready-cells.php` は `POSLA_PROVISIONER_LOCK_FILE` で排他する。複数triggerが来ても、cell作成処理は1本ずつ実行される。

## 7. 残タスク

- provisioner VM の実作成
- `/srv/posla` へのrepo配置
- `docker/env/provisioner.env` の本番値作成
- Cloud Run から provisioner VM への安全な通信経路作成
- VM firewall / TLS / IAP / LB の確定
- VM snapshot / backup 設定
- `{tenant_slug}.<production-domain>` を受ける DNS / reverse proxy / TLS 証明書運用の確定
- provisioner log rotate / 監視通知の設定
- 実申込での end-to-end smoke
