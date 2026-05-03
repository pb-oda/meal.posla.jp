# POSLA Test Alert to OP E2E (legacy)

POSLA本体テスト環境からPOSLA運用OPへ、実alertを1件投入して経路を確認する旧手順です。現在の標準監視は **OP -> POSLA** のSource監視です。この手順はlegacy ingestの互換確認が必要な場合だけ使います。

## 前提

- OP擬似本番: `http://127.0.0.1:8091`
- OP受信口: `POST /api/ingest/posla-alert`
- tokenはGitに入れず、環境変数またはコマンド引数で渡します。
- この手順はPOSLA本体DBへ書き込みません。OP側にalert、triage、evidence、investigationが作られます。

## dry-run

```bash
POSLA_OP_ALERT_ENDPOINT=http://127.0.0.1:8091/api/ingest/posla-alert \
POSLA_OP_ALERT_TOKEN=<ops-alert-token> \
php scripts/ops/send-test-alert-to-op.php --dry-run
```

## 送信

```bash
POSLA_OP_ALERT_ENDPOINT=http://127.0.0.1:8091/api/ingest/posla-alert \
POSLA_OP_ALERT_TOKEN=<ops-alert-token> \
php scripts/ops/send-test-alert-to-op.php --run-workers
```

送信後、OPのCockpit、Alerts、Investigations、Notificationsを確認します。

## Dockerコンテナ内から送る場合

POSLA本体のPHPコンテナからOP擬似本番へ送る場合は、Docker Desktop上ではhost側のOPを `host.docker.internal` で参照します。

```bash
docker exec \
  -e POSLA_OP_ALERT_ENDPOINT=http://host.docker.internal:8091/api/ingest/posla-alert \
  -e POSLA_OP_ALERT_TOKEN=<ops-alert-token> \
  posla_php \
  php /var/www/html/scripts/ops/send-test-alert-to-op.php --run-workers
```

## 確認点

- OP APIが `201` を返す。
- alertの `source` が `posla_monitor_health` になる。
- severity `critical` と障害系titleにより、triageがhuman review付きで作られる。
- investigationがqueuedになり、`--run-workers` 指定時はwaiting_humanまで進む。
- `--run-workers` 指定時、workflow-workerとnotification-workerがOP側で実行される。
- notification-worker実行後、Google Chat向けdraftが作られる。
