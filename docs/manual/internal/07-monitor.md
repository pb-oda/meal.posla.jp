---
feature_id: I-1
title: 運用監視エージェント (Phase 1)
chapter: 07
audience: POSLA 運営チーム (内部)
keywords: [監視, 異常検知, Slack, UptimeRobot, cron]
related: [06-troubleshoot]
last_updated: 2026-04-26
maintainer: POSLA運営
---

# 7. 運用監視エージェント (I-1 Phase 1)

POSLA 本番運用で、サーバーやアプリケーションの異常を検知して **Slack + メール** でアラート通知する仕組み。

::: warning これは社内向け資料です
テナント (飲食店オーナー) は読まなくて OK。POSLA 運営チームの運用手順です。
:::

::: warning 現在の canonical は container scheduler です
擬似本番の現在実装では、外部 crontab ではなく **`docker/php/startup.sh` → `docker/php/cron-loop.sh`** が `php` コンテナ内で監視 cron を回しています。共有サーバ向け crontab 例は歴史的参考情報としてのみ扱ってください。
:::

---

## 7.1 アーキテクチャ (AI 停止リスク対応)

2 系統構成です。POSLA 本体は内部ログ集計と heartbeat 更新だけを担当し、死活監視の主体は POSLA 外部に置きます。

### 正系: `php` コンテナ内 scheduler

- 5 分毎に `api/cron/monitor-health.php` を実行
- PHP エラーログ / Stripe Webhook / メール送信失敗を検知
- 異常時: monitor_events に記録 + Slack 通知 + (連続 3 件で) 運営通知先（support@posla.jp）

### 正系の外形監視: codex-ops-platform

- POSLA の外側から 5 分毎に `https://<production-domain>/api/monitor/ping.php` を HTTP GET
- Cell Registry を読み、全 cell の ping / snapshot / errorNo / migration ledger / deploy履歴を確認
- 応答なし / `ok: false` → POSLA運営窓口へアラート
- POSLA 本体が落ちても、op 側から検知できる

### 補助の外形監視: UptimeRobot 等

- **UptimeRobot** / **cron-job.org** / **Better Uptime** 等 (外部)
- 5 分毎に `https://<production-domain>/api/monitor/ping.php` を HTTP GET
- 応答なし / `ok: false` → POSLA運営窓口（support@posla.jp）に直接アラート
- **インフラ全体ダウン時** でも外部から検知可能

---

## 7.2 初期セットアップ手順 (Hiro が初回のみ実施)

### Step 1: container scheduler の確認

```bash
docker compose logs --tail=100 php
docker compose exec -T php ps -ef
curl -sf http://127.0.0.1:8081/api/monitor/ping.php
```

::: warning ⚠ app env の正本は `docker/env/*.env` です
現行の監視 cron は `php` コンテナ内 scheduler が実行します。外部 cron / systemd timer / CI から `api/cron/*.php` を直接叩く場合だけ、少なくとも以下を **`docker/env/*.env` と同じ値で**渡してください:

- `POSLA_DB_HOST` / `POSLA_DB_NAME` / `POSLA_DB_USER` / `POSLA_DB_PASS`
- `POSLA_APP_BASE_URL` / `POSLA_FROM_EMAIL` / `POSLA_SUPPORT_EMAIL`
- `POSLA_ALLOWED_ORIGINS` / `POSLA_ALLOWED_HOSTS`

`POSLA_CRON_SECRET` は **HTTP 経由で `api/cron/monitor-health.php` を叩く場合のみ**必須。CLI 実行だけなら不要。

**本番コンテナでも推奨**: `docker/env/app.env` と `docker/env/db.env` を唯一の正本にし、外部 scheduler 併用時だけその値を転写してください。詳細は [5.3.5 env 供給手段の選択肢](./05-operations.md#535-env-供給手段の選択肢現行コンテナ運用) を参照。
:::

### Step 2: Slack Webhook 作成

1. Slack のワークスペース → App → **Incoming Webhooks**
2. チャンネル選択 (例: `#posla-alerts`) → **Add Incoming Webhooks integration**
3. Webhook URL をコピー
4. POSLA 管理画面 → API 設定 → **Slack Webhook URL** に貼り付け (or SQL 直接):

```sql
UPDATE posla_settings SET setting_value = 'https://hooks.slack.com/services/XXX/YYY/ZZZ'
WHERE setting_key = 'slack_webhook_url';
```

### Step 3: 運営メール設定

```sql
UPDATE posla_settings SET setting_value = 'support@posla.jp'
WHERE setting_key = 'ops_notify_email';
```

### Step 4: 外形監視登録

1. codex-ops-platform に POSLA source を登録
2. Ping URL: `https://<production-domain>/api/monitor/ping.php`
3. Snapshot URL: `https://<production-domain>/api/monitor/cell-snapshot.php`
4. Monitoring Interval: `5 minutes`
5. 補助として UptimeRobot 等も同じ ping URL を登録
6. **Expected Status**: 200 のみ OK (ok:false で 503 返すように設計)

---

## 7.3 検知項目と閾値

| 項目 | 検知条件 | 通知 |
|---|---|---|
| PHP Fatal / Parse / Warning | 直近 5 分の `/home/odah/log/php_errors.log` | Slack (即時) |
| Stripe Webhook 失敗 | subscription_events.error_message 直近 5 分 | Slack (即時) |
| 予約メール送信失敗 | reservation_notifications_log.status='failed' 直近 5 分 | monitor_events のみ |
| 連続異常 | monitor_events の error/critical が 15 分で 3 件以上 | 運営通知先（support@posla.jp） |
| cron ラグ | last_heartbeat から 15 分以上経過 | codex-ops-platform / 外部 uptime → 運営通知先 |
| サーバーダウン | ping エンドポイント応答なし | codex-ops-platform / 外部 uptime → 運営通知先 |

---

## 7.4 監視イベントの見方

POSLA 管理画面 (posla-admin) で:

- `GET /api/posla/monitor-events.php?since=2026-04-18&limit=100`
- 一覧表示: severity / title / detail / created_at / resolved

### 対応後の処理

対応完了したイベントは以下で resolved マーク:
```
PATCH /api/posla/monitor-events.php { id: "xxx", resolved: 1 }
```

resolved_by にログインユーザー ID が記録される。

---

## 7.5 よくある対応パターン

### ケース A: PHP Fatal エラー

1. Slack 通知受信
2. monitor_events で詳細確認
3. 原因パターン:
   - カラム未存在 → マイグレーション未適用が多い → 該当 migration-*.sql を投入
   - Undefined index → コードのバグ → 該当ファイルを修正
   - Connection refused → DB or 外部 API 障害
4. 修正 → デプロイ → resolved マーク

### ケース B: Stripe Webhook 失敗

1. Slack 通知受信
2. subscription_events テーブルで error_message 確認
3. 典型: テナント紐付け失敗、署名検証失敗
4. Stripe ダッシュボードで Webhook 再送 → 解消確認

### ケース C: メール送信失敗

1. reservation_notifications_log で error_message 確認
2. 典型: SMTPサーバー障害、メアド違反
3. PHP mail() の代わりに SendGrid 等に移行検討 (Phase 2)

---

## 7.6 Phase 2 計画 (AI エージェント)

Phase 1 (通知のみ) の次は、**AI が能動的に調査・原因推定**する機能。

### 想定フロー

```
顧客から「注文が反映されない」問い合わせ
  ↓
AI エージェントが以下を調査:
  ・get_recent_orders(store_id, hours)
  ・get_session_state(table_session_id)
  ・search_php_errors(keyword, since)
  ・check_payment_status(order_id)
  ↓
Gemini Function Calling で原因推定
  ↓
管理画面に「推定原因 + 対処案」提示
```

現状未実装。Phase 2 で段階導入予定。

---

## 7.7 技術詳細

### ファイル構成

```
sql/migration-i1-monitor.sql                      — monitor_events テーブル
api/cron/monitor-health.php                       — 正系 cron
api/monitor/ping.php                              — 外部 uptime 向け
api/posla/monitor-events.php                      — 管理画面用 CRUD
docs/ops-monitor-guide.md                         — 技術ドキュメント
```

### 主要 DB

```sql
CREATE TABLE monitor_events (
  id, event_type, severity, source, title, detail,
  tenant_id, store_id,
  notified_slack, notified_email,
  resolved, resolved_at, resolved_by,
  created_at
);
```

### posla_settings のキー

- `slack_webhook_url` — Slack Incoming Webhook URL
- `ops_notify_email` — 運営緊急時のメール先
- `monitor_cron_secret` — CLI 以外から cron を叩く時の共有秘密
- `monitor_last_heartbeat` — cron 最終実行時刻 (ping で確認される)

---

## 7.8 monitor-events.php API 詳細

### 7.8.1 エンドポイント

`GET /api/posla/monitor-events.php` — モニターイベント一覧取得（POSLA 管理者）
`PATCH /api/posla/monitor-events.php` — イベントを `resolved=1` に更新

### 7.8.2 GET レスポンス例

```json
{
  "ok": true,
  "data": {
    "events": [
      {
        "id": "evt-xxx",
        "event_type": "stripe_webhook_failure",
        "severity": "warning",
        "source": "monitor-health",
        "title": "Stripe Webhook 受信失敗",
        "detail": "checkout.session.completed の処理で例外発生",
        "tenant_id": "t-xxx",
        "store_id": null,
        "notified_slack": 1,
        "notified_email": 0,
        "resolved": 0,
        "created_at": "2026-04-19 10:30:15"
      }
    ]
  }
}
```

### 7.8.3 PATCH リクエスト

```json
{
  "id": "evt-xxx",
  "resolved": 1,
  "resolution_note": "Webhook 設定を修正"
}
```

### 7.8.4 severity 一覧

| severity | 意味 | 通知 |
|---|---|---|
| `info` | 情報のみ | DB のみ記録 |
| `warning` | 注意（1 件で対応必須でない） | Slack 通知 |
| `error` | エラー（即時対応推奨） | Slack + メール（連続 3 件以上） |
| `critical` | 重大障害 | Slack + メール即時 |

### 7.8.5 event_type 一覧

| event_type | 説明 | 検知元 |
|---|---|---|
| `php_error` | PHP エラーログに新規記録 | monitor-health.php (5 分 cron) |
| `stripe_webhook_failure` | Stripe Webhook 処理失敗 | webhook.php |
| `mail_send_failure` | テナント宛メール送信失敗 | 各 mail() 呼び出し |
| `tenant_subscription_failed` | 月次請求失敗 | webhook.php |
| `db_connection_error` | DB 接続失敗 | get_db() |
| `external_api_error` | Gemini / Places / Stripe API 失敗 | 各プロキシ |
| `ping_no_response` | 外部 uptime からの ping が失敗 | UptimeRobot |

---

## 7.9 Slack 通知のフォーマット

### 7.9.1 通常通知

```
:warning: POSLA 監視アラート (warning)
─ event: stripe_webhook_failure
─ tenant: shibuya-ramen
─ detail: checkout.session.completed の処理で例外
─ time: 2026-04-19 10:30:15
─ </posla-admin/dashboard.html#monitor|管理画面で確認>
```

### 7.9.2 緊急通知

```
:rotating_light: POSLA 緊急アラート (critical)
─ event: db_connection_error
─ source: monitor-health
─ 全テナント影響の可能性あり
─ ↓ POSLA運営窓口（support@posla.jp）にも送信済み ↓
```

---

## 7.10 ヘルスチェック詳細

### 7.10.1 監視項目（monitor-health.php、5 分毎）

| チェック | 頻度 | 異常判定 |
|---|---|---|
| PHP エラーログ | 5 分 | 新規エラー 1 件以上 |
| Stripe Webhook 受信 | 5 分 | 過去 1 時間で受信 0 件かつ通常時間帯 |
| メール送信成功率 | 5 分 | 過去 10 件中 3 件以上失敗 |
| DB 接続 | 5 分 | `SELECT 1` 失敗 |
| ディスク空き容量 | 5 分 | 90% 以上使用 |
| API レスポンス時間 | 5 分 | 平均 5 秒以上 |

### 7.10.2 外形 ping（op 側）

codex-ops-platform が `/api/monitor/ping.php` を 5 分毎に叩く。補助として UptimeRobot や cron-job.org も同じ endpoint を叩けます。POSLA 管理画面自身は、POSLA停止時に表示できないため、外形監視の主体にしません。

レスポンス:
```json
{ "ok": true, "ts": "2026-04-19T10:30:15+09:00" }
```

500 エラー or タイムアウト → 即時 運営通知先（support@posla.jp）

---

## 7.11 自動対応（Phase 2 計画）

将来的に検知 → 自動修復のループを実装予定:

| 異常 | 自動対応 |
|---|---|
| Webhook 受信途絶 | Stripe API で過去 24h の events を fetch → 未処理を補完 |
| メール送信失敗 | リトライキューに追加（最大 3 回） |
| DB スロークエリ | 自動 EXPLAIN → 改善提案を Slack 通知 |
| ディスク空き 90% | 古い `_backup_*` ディレクトリを自動削除 |

---

## 更新履歴

- **2026-04-19**: 7.8 API 詳細 / 7.9 Slack フォーマット / 7.10 ヘルスチェック詳細 / 7.11 Phase 2 計画 追加
- **2026-04-18**: Phase 1 実装 (通知のみ、AI エージェントは Phase 2 で計画)
