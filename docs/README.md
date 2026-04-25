# POSLA 擬似本番 docs 正本ガイド

この `docs/` は、`【擬似本番環境】meal.posla.jp` の **本番直前リリース向け正本 docs** を置く場所です。

- ローカル検証 URL: `http://127.0.0.1:8081`
- 本番記述: `<production-domain>` などのプレースホルダを使う
- `eat.posla.jp` は現行 sandbox の参考情報としてのみ扱う

## まず読む順番

1. [RELEASE_DOCSET.md](./RELEASE_DOCSET.md)
2. [AI運用引継ぎメモ_20260425.md](./AI運用引継ぎメモ_20260425.md)
3. [container-server-migration-runbook.md](./container-server-migration-runbook.md)
4. [production-api-checklist.md](./production-api-checklist.md)
5. [smoke-test-checklist.md](./smoke-test-checklist.md)

## 正本として残す docs

| 区分 | ファイル / ディレクトリ | 用途 |
|---|---|---|
| 公開マニュアル source | `manual/` | `public/docs-internal/` / `public/docs-tenant/` の生成元 |
| リリース運用 | `RELEASE_DOCSET.md` | 本番直前リリースで使う最小セット |
| セッション引継ぎ | `AI運用引継ぎメモ_20260425.md` | AI運用へ切り替える前提・注意点 |
| 新サーバ移行 | `container-server-migration-runbook.md` | コンテナ前提の新サーバ移行手順 |
| API / 外部設定 | `production-api-checklist.md` | 切替が必要な API / key / callback 一覧 |
| 監視運用 | `ops-monitor-guide.md` | cron / heartbeat / 通知運用 |
| エラー参照 | `error-catalog.md` | エラーコード調査 |
| スモーク | `smoke-test-checklist.md` | リリース前後の確認項目 |

## 参照が生きているため残すファイル

| ファイル | 理由 |
|---|---|
| `SYSTEM_SPECIFICATION.md` | 大型の実装参照資料として保持 |
| `voice-commands.md` | 音声導線の補助資料として保持 |
| `printer-guide.md` | `public/admin/js/settings-editor.js` の参照が残る |

## 参考資料として残しているファイル

| ファイル | 扱い |
|---|---|
| `posla-meal-service-master-draft.md` | 実装照合の大型参考資料。将来は縮約版へ置換候補 |

## `*no_deploy/` との境界

- `*no_deploy/` にあるものは deploy 対象外
- 営業資料、旧設計、機密メモ、旧 archive は `docs/` に戻さない
- `docs/` から何かを移す前に、コード参照・build 参照が残っていないか確認する

## 補足

- `docs/manual/node_modules/` は deploy 対象ではないが、**ローカル再ビルドのために残している build cache** です
- `docs/manual/.vitepress/` は VitePress source 設定です。削除しないでください
