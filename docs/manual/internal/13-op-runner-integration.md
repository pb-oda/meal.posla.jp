---
title: OP / runner 連携運用
chapter: 14
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム / AI運用担当
keywords: [op, runner, codex, alert, incident, google-chat, monitor-event]
last_updated: 2026-04-27
maintainer: POSLA運営
---

# 14. OP / runner 連携運用

この章は、POSLA本体、AI運用支援基盤 `op.posla.jp`、調査実行基盤 `runner.posla.jp` の接続と日常運用をまとめた社内向け手順です。

::: warning この章の前提
- POSLA本体は顧客が使う業務システム
- OPはPOSLAを監視・調査する内部運用画面
- runnerはOPから呼ばれる読み取り専用の調査コンテナ
- Phase 1ではOP / runnerからdeploy、migration、DB writeを実行しない
:::

## 14.1 全体像

```text
POSLA control / cell
  |
  | alert / 障害報告
  v
op.posla.jp
  |
  | investigation request
  v
runner.posla.jp
  |
  | read-only
  +-- GitHub / POSLA code
  +-- POSLA DB SELECT
  +-- log files
  |
  v
Codex CLI report
  |
  v
Google Chat: 小田さんが今やること / 理由
```

## 14.2 役割分担

| 役割 | 担当 | 実行してよいこと | 実行しないこと |
|---|---|---|---|
| POSLA | 業務アプリ / cell | ping、snapshot、monitor_event作成、障害報告送信 | OPの調査実行、runner起動 |
| OP | 運用画面 | alert/case受信、分類、Investigation作成、Google Chat通知 | POSLA DB write、deploy、rollback |
| runner | 調査実行 | Git clone/fetch、コード検索、DB SELECT、ログ参照、Codex CLIレポート | git push、DB write、deploy、migration |
| 人間 | 当面は小田さん | 修正判断、コード修正、push、cell deploy、rollback承認 | 未確認のまま全cell一括反映 |

## 14.3 接続一覧

| 向き | 接続 | 用途 | 認証 |
|---|---|---|---|
| POSLA -> OP | `POST /api/alerts.php` | 監視アラート送信 | `X-OPS-ALERT-TOKEN` |
| POSLA -> OP | `POST /api/ingest/posla-case` | 障害報告窓口からの報告送信 | `X-OPS-CASE-TOKEN` |
| OP -> POSLA | `/api/monitor/ping.php` | 外形監視 | なし |
| OP -> POSLA | `/api/monitor/cell-snapshot.php` | cell registry / errors / monitor_events確認 | `X-POSLA-OPS-SECRET` |
| OP -> POSLA DB | MySQL SELECT | error_log / monitor_events / migration / deploy履歴確認 | read-only DB user |
| OP -> runner | `/api/health.php` / `/api/investigate.php` / `/api/ai-report.php` | 調査実行 | `X-Runner-Token` |
| runner -> GitHub | clone / fetch / checkout | 対象commitのコード確認 | read-only deploy key / HTTPS token |
| runner -> POSLA DB | MySQL SELECT | 証跡確認 | read-only DB user |

## 14.4 POSLA側で設定する値

POSLA control / cell の `app.env` または POSLA管理画面の API設定で、次を設定します。

| 変数 / setting | 用途 |
|---|---|
| `POSLA_OP_CASE_ENDPOINT` | `https://<op-domain>/api/ingest/posla-case` |
| `POSLA_OP_CASE_TOKEN` | POSLA管理画面で保存する場合はenv不要。envで固定する場合だけ、OP側 Settings の障害報告受信Tokenと同じ値 |
| `POSLA_OP_ALERT_ENDPOINT` | `https://<op-domain>/api/alerts.php` |
| `POSLA_OP_ALERT_TOKEN` | OP側 `OPS_ALERT_INGEST_TOKEN` と同じ値 |
| `POSLA_OPS_READ_SECRET` | OPが `cell-snapshot.php` を読むための共有秘密 |
| `google_chat_webhook_url` | OP側env未設定時のGoogle Chat通知先fallback |
| `monitor_cron_secret` | HTTP経由で `monitor-health.php` を実行する場合の共有秘密 |

env と POSLA管理画面の両方に値がある場合は、env を優先します。

## 14.5 alert の流れ

POSLAの `monitor-health.php` は、次のような異常を検知すると `monitor_events` に記録します。

- PHP error_log の閾値超過
- Stripe Webhook失敗
- 5xx増加
- 認証エラー大量発生
- 予約通知失敗
- cron heartbeat遅延

OP連携が設定済みの場合、同じ内容をOPへ `alert ingest` します。

```text
monitor-health.php
  -> monitor_events INSERT
  -> OP /api/alerts.php POST
  -> OPが分類
  -> 要対応/緊急ならrunner調査
  -> Google Chatへ短文通知
```

### Google Chatに飛ぶもの

| 起点 | Chat通知 |
|---|---|
| POSLAから直接届いた `要対応` / `緊急` alert | 送る |
| POSLAから直接届いた `問題なし` / `要観察` alert | 送らない |
| OP画面の `POSLA alerts同期` | 送らない |
| tenant画面の障害報告窓口 | 送る |

`POSLA alerts同期` は過去分・滞留分の確認用途です。これをChat通知すると古いイベントまで一括通知されるため、通知対象外にしています。

## 14.6 障害報告の流れ

tenant画面の障害報告窓口から報告されると、OPの `/api/ingest/posla-case` が受け取ります。

```text
顧客 / 店舗スタッフ
  -> 障害報告窓口
  -> OP /api/ingest/posla-case
  -> Investigation作成
  -> runner調査
  -> Codex CLIレポート
  -> Google Chat
```

Token設定の基本:

- OP側 Settings > POSLA接続 > POSLAから受ける障害報告 でTokenを発行して保存します。
- 同じTokenをPOSLA管理画面 > OP起動・障害報告 > 障害報告 Token に保存します。
- env `POSLA_OP_CASE_TOKEN` / `POSLA_OPS_CASE_TOKEN` はUI保存が使えない初期構築・固定運用時のfallbackです。
- Token実値は保存後に画面へ表示しません。再発行したらOP側とPOSLA側を同じ値で更新します。

Google Chatには、長い証跡ではなく次の2つを短く出します。

```text
【小田さんが今やること】
...

【理由】
...
```

詳細な code hits、DB確認結果、monitor_events、AIレポート全文はOPのInvestigationに保存します。

## 14.7 monitor_event の扱い

`monitor_events` は、POSLAが「監視上の異常」としてDBに残すイベントです。生ログではありません。

対応済みにする条件:

- テスト由来だと確認した
- 設定修正やコード修正が終わった
- OP / runner の調査で「問題なし」と判断した
- 同じエラーが再発していない

対応済みAPI:

```http
PATCH /api/posla/monitor-events.php
Content-Type: application/json

{
  "id": "event-id",
  "resolved": 1
}
```

解決済みにしてよい例:

| event | 判断 |
|---|---|
| テスト通知で作った `E1025` | テスト完了後に解決済み |
| AI設定未投入の状態でAI機能をテストした `E9004` | POSLA運営側の設定投入が完了していることを確認できたら解決済み |
| Stripe sandbox設定ミスのWebhook失敗 | 設定修正後に解決済み |

解決済みにしてはいけない例:

| event | 理由 |
|---|---|
| 決済できない状態が継続 | Tier 0障害 |
| DB接続失敗 | POSLA全体停止の可能性 |
| 同一errorNoが増え続けている | 原因未解消 |

## 14.8 runner が読むコード

本番では runner は本番cellへSSHしてファイルを読むのではなく、GitHubのPOSLA repoを読みます。

```text
GitHub main / release tag
  -> runner workspace
  -> 対象commitをcheckout
  -> errorNo / API / 画面名で検索
```

deploy済みcommitは、cell deploy時の `POSLA_DEPLOY_VERSION` と deploy manifest で追跡します。

人間のローカル修正運用:

1. ローカルのPOSLAコードを修正
2. test / smoke
3. GitHubへpush
4. 対象cellへdeploy
5. deploy manifestへcommitを記録
6. OP / runnerが同じcommitを読めるようになる

runner workspaceの変更は正本ではありません。将来patch-runnerを使う場合も、直接mainへpushせずPR経由にします。

## 14.9 デプロイ日のE2E確認

本番接続後、必ず次を1回通します。

### alert E2E

1. POSLA管理画面から監視テスト通知を実行
2. `monitor_events` に記録される
3. OPの Alert に入る
4. 要対応 / 緊急なら Investigation が作成される
5. runner が調査する
6. Codex CLI レポートが作成される
7. Google Chat に短文通知が届く
8. OPで詳細を確認する
9. テストeventを `resolved=1` にする

### 障害報告 E2E

1. tenant画面の障害報告窓口からテスト報告
2. OPの Cases に入る
3. Investigation が作成される
4. runner / Codex調査が走る
5. Google Chatに `小田さんが今やること` が届く
6. テストcaseをOPで削除または対応済みにする

## 14.10 日常運用

毎日見るもの:

| 場所 | 確認内容 |
|---|---|
| OP 総合状態 | POSLA ping、DB read-only、runner health |
| OP 顧客cell | 新規cellが自動同期されているか |
| OP Alert | `要対応` / `緊急` がないか |
| OP Cases | 顧客障害報告が残っていないか |
| OP Investigations | 人間確認待ちがないか |
| Google Chat | 新規alert / case通知 |

対応の基本:

1. Google Chatを見る
2. `小田さんが今やること` を読む
3. 顧客確認、設定修正、コード修正、様子見のどれかを判断
4. コード修正が必要ならPOSLA repoで修正
5. 対象cellだけdeploy
6. smoke
7. OP / POSLA側のeventを解決済みにする

## 14.11 Phase 1 で禁止すること

- OPから直接DB UPDATE / DELETE / INSERT
- runnerからDB write
- runnerからgit push
- runnerから本番deploy
- runnerからmigration
- runnerからrollback
- secret / env実値をAIに渡す
- 顧客の個人情報や決済情報をAIへ渡す

## 14.12 本番接続で詰まった時

| 症状 | 確認 |
|---|---|
| OPにcellが出ない | POSLA `cell-snapshot.php`、`POSLA_OPS_READ_SECRET`、Cell Registry |
| OPにalertが来ない | `POSLA_OP_ALERT_ENDPOINT`、`POSLA_OP_ALERT_TOKEN`、OP `OPS_ALERT_INGEST_TOKEN` |
| 障害報告が来ない | `POSLA_OP_CASE_ENDPOINT`、`POSLA_OP_CASE_TOKEN`、OP `OPS_CASE_INGEST_TOKEN` |
| Chatに届かない | OP `OPS_GOOGLE_CHAT_WEBHOOK_URL`、POSLA `google_chat_webhook_url` fallback |
| runner調査が走らない | `OPS_RUNNER_BASE_URL`、`OPS_RUNNER_TOKEN`、runner `RUNNER_AUTH_TOKEN` |
| AIレポートが作られない | runner `RUNNER_CODEX_EXECUTE`、Codex実行ゲート、Codex login状態 |
| logs skipped | runner `RUNNER_LOG_FILES` が未設定 |
| DB read-only失敗 | `posla_ops_ro`、接続元Host、Firewall、DB名 |

## 14.13 関連ドキュメント

- [7. 監視・アラート](./07-monitor.md)
- [11. Cell配備運用](./11-cell-deployment.md)
- [12. 本番デプロイ手順書](./12-production-deploy-runbook.md)
- [13. 本番切替 API チェックリスト](./production-api-checklist.md)
