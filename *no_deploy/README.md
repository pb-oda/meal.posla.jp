# no_deploy メモ

このフォルダは、`【擬似本番環境】meal.posla.jp` のうち
`docker compose up -d`、アプリ配信、DB 起動、docs 配信に不要なものを退避する場所です。

## 2026-04-25 に移動したもの

- `generated_outputs/outputs/`
  - 生成済みの設計資料出力。runtime では未使用。
- `archive_docs/archive/`
  - `docs/archive/` にあった旧設計・旧マニュアルの保管物。
- `root_misc/Stripe営業向け質問リスト_POSLA.docx`
  - ルート直下の単独営業資料。runtime では未使用。
- `root_misc/sshkey.txt`
  - 単独メモ。runtime では未使用。
- `junk/.DS_Store`
  - macOS 自動生成ファイル。
- `business_docs/business/`
  - `docs/business/` にあった営業・事業説明用の Markdown。
- `docs_misc/`
  - `docs/demo-guide.html`
  - `docs/sales-deck.html`
  - `docs/APIコスト試算.xlsx`
  - `docs/sales-pitch.pptx`
  - `docs/posla-economic-zone-roadmap.pptx`
  - `docs/ビジネス内部資料（初版）.pptx`
  - いずれも runtime / Docker / docs 配信には未使用。
- `docs_sensitive/internal/`
  - `docs/internal/credentials.md`
  - `docs/internal/posla飲食ログイン.txt`
  - 認証情報・ログイン情報を含むため、`docs/` 正本から分離。
- `docs_reference/`
  - `docs/demo-facilitator-runbook.md`
  - `docs/codex-ops-platform-architecture.md`
  - `docs/L15-smaregi-design.md`
  - いずれも運用正本ではなく、参考資料 / 旧設計メモ。
- `proposals/`
  - `POSLA提案資料_営業ストーリー案.md`
  - 営業 / 提案資料の叩き台。deploy 対象外。

## まだ残しているもの

- `id_ecdsa.pem`
  - SSH 接続で使うため残置。
- `擬似本番アカウント.txt`
  - 運用メモの可能性があるため残置。
- `docs/container-server-migration-runbook.md`
  - 新サーバ移行時の補助資料として残置。
- `docs/printer-guide.md`
  - 補助ガイドだが、参照が残っているため残置。
- `docs/voice-commands.md`
  - 音声導線の補助資料として残置。

## 2026-04-25 に追加で退避したもの

- `retired_ai_helpdesk/`
  - `api/posla/ai-helpdesk.php`
  - `public/shared/js/posla-helpdesk-fab.js`
  - `scripts/build-helpdesk-prompt.php`
  - `scripts/output/helpdesk-prompt-*.txt`
  - `scripts/output/helpdesk-rag-*.json`
  - 旧 AI helpdesk の endpoint / FAB / 生成物。2026-04-25 に runtime から retire。
- `docs_build_artifacts/manual_vitepress_dist*/`
  - `docs/manual/.vitepress/dist/` の退避先。
  - VitePress の一時 build artifact で、runtime / deploy には不要。

## 現在の docs 正本入口

- `docs/README.md`
  - `docs/` に何を残すかの整理基準。
- `docs/RELEASE_DOCSET.md`
  - 本番直前リリースで読む最小セット。

## 取り扱い

- ここに移したものは deploy 対象外。
- 完全削除する前に、参照や再利用の可能性を確認すること。
