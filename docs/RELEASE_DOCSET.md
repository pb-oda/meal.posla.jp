# POSLA 本番直前リリース docs セット

このファイルは、`【擬似本番環境】meal.posla.jp` を **本番候補として仕上げる時に最低限見る docs** だけに絞った案内です。

## 1. このセットの目的

- `docs/` の正本を増やしすぎない
- 本番前チェックで読む順番を固定する
- 参考資料と運用正本を分離する

## 2. 本番前に必ず使う 6 点

1. [container-server-migration-runbook.md](./container-server-migration-runbook.md)
   新サーバへコンテナで載せ替える時の正本。
2. [production-api-checklist.md](./production-api-checklist.md)
   Stripe / Gemini / Places / Smaregi / Webhook / callback の切替確認。
3. [ops-monitor-guide.md](./ops-monitor-guide.md)
   heartbeat、cron、通知先、監視系の確認。
4. [error-catalog.md](./error-catalog.md)
   エラー番号の逆引き。
5. [smoke-test-checklist.md](./smoke-test-checklist.md)
   リリース前後の動作確認。
6. `manual/`
   テナント向け / 内部向け公開マニュアル source。

## 3. 環境変数の正本

この擬似本番で実際に使う env ファイルの正本は次です。

- [../docker/env/app.env](../docker/env/app.env)
- [../docker/env/db.env](../docker/env/db.env)
- 例示: [../docker/env/app.env.example](../docker/env/app.env.example), [../docker/env/db.env.example](../docker/env/db.env.example)

runtime code が読む場所:

- `api/config/app.php`
- `api/config/database.php`

新サーバ移行時は、docs の文章より **実際の env ファイルと runtime code** を優先して確認します。

## 4. docs build の正本

公開される docs は `docs/manual/` を build して作ります。

```bash
cd docs/manual
npm run build:all
```

生成先:

- `public/docs-internal/`
- `public/docs-tenant/`

## 5. `docs/` に残すもの / 残さないもの

残す:

- リリース運用に直接使う docs
- build script や UI から参照される docs
- 公開マニュアル source

残さない:

- 営業資料
- デモ補助資料
- credentials / ログインメモ
- 旧設計 archive
- 生成物の置き場としての `outputs/`

これらは `[*no_deploy](../*no_deploy)` へ退避します。

## 6. 現時点でまだ参考資料扱いのもの

- [posla-meal-service-master-draft.md](./posla-meal-service-master-draft.md)
  実装照合には有用だが、リリース運用の最小セットではない。
- [SYSTEM_SPECIFICATION.md](./SYSTEM_SPECIFICATION.md)
  build script 参照があるため残すが、運用の最小セットではない。
- [voice-commands.md](./voice-commands.md)
  helpdesk build 用の参照。
- [printer-guide.md](./printer-guide.md)
  参照が残る補助ガイド。

## 7. 今後の整理方針

1. まずはこの `RELEASE_DOCSET.md` を正本入口にする
2. 大型資料は、必要な内容だけを小さい正本に写してから `*no_deploy/` に移す
3. 参照が残るファイルは、コード側の参照を外してから移す
