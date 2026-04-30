# AGENTS.md — 【擬似本番環境】meal.posla.jp

## この環境の位置付け

このフォルダは POSLA の **擬似本番環境** です。
**Docker Compose で `127.0.0.1:8081` に起動し**、本番移行前の vendor-neutral 検証・本番デプロイ前の dry-run・運用支援基盤（`【AI運用支援】codex-ops-platform`）の接続先として使います。

- **配信先 URL**: `http://127.0.0.1:8081`（ローカル Docker のみ、外部公開しない）
- **起動方法**: このフォルダで `docker compose up -d`
- **DB**: Docker 内 MySQL 5.7（ホスト公開ポート `3306`、`host.docker.internal:3306`）
- **ファイル配信**: `./public`, `./api`, `./docs`, `./scripts` を bind mount

## ドキュメント表記ルール（重要）

- **本番 URL は `<production-domain>` などのプレースホルダで記述する**（vendor-neutral 化を維持）
- **`eat.posla.jp` は「現行 sandbox の参考情報」としてのみ残してよい**（今は【デモ環境】が配信中のため）
- 本番インフラが確定したら、プレースホルダを一括で実値に置換するだけで本番マニュアルが完成する状態を保つ
- 具体的には以下のトークンを使う:
  - `<production-domain>` — 本番ドメイン
  - `<ssh-user>@<host>` — 本番 SSH 接続先
  - `<production-db-host>` — 本番 DB ホスト
  - `<deploy-root>` — 本番デプロイルート

## 【デモ環境】との役割分担（厳守）

| 項目 | 【擬似本番環境】（このフォルダ） | 【デモ環境】 |
|---|---|---|
| 目的 | 本番移行前の vendor-neutral 検証 | 営業デモ・社内レビュー |
| 配信先 | Docker `127.0.0.1:8081` | `eat.posla.jp`（sandbox） |
| ドキュメント URL | `<production-domain>` プレースホルダ | `eat.posla.jp` 明示 |
| DB | Docker 内 MySQL 5.7 (host:3306) | さくら mysql80（共有 sandbox） |
| デプロイ | `docker compose up` のみ | `scp`/`ssh` で sandbox に配布 |

- **両環境間で `rsync --delete` してはならない。** 2026-04-24 のインシデント以降、両 tree は完全独立運用。
- このフォルダのコード/ドキュメントは **【デモ環境】とは別の実体**として編集する。両者の乖離を恐れない。

## 起動・停止手順

```bash
# 起動
docker compose up -d

# 状態確認
docker compose ps
curl -sf http://127.0.0.1:8081/api/monitor/ping.php

# VitePress 再ビルド後の反映
cd docs/manual && npm run build:all
# → public/docs-internal/ が差し替わり、即座に Docker 経由で配信される（bind mount）

# 停止
docker compose down
```

## 【AI運用支援】codex-ops-platform から接続される

`/Users/odahiroki/Library/CloudStorage/GoogleDrive-oda@plusbelief.co.jp/共有ドライブ/05_infra/※開発関連/POSLA/【AI運用支援】codex-ops-platform` は、この擬似本番環境に対して **read-only で疎通確認**する外部スキャフォールドです。

- App ping URL: `http://host.docker.internal:8081/api/monitor/ping.php`
- DB: `host.docker.internal:3306`（read-only アカウントで接続）

このフォルダの Docker を落とすと、AI運用支援側の bootstrap も失敗する。

## チェックリスト（このフォルダで作業する時）

- [ ] 自分が今いるフォルダが **`【擬似本番環境】meal.posla.jp`** であることを `pwd` で確認
- [ ] ドキュメントに `eat.posla.jp` を書こうとしていないか確認（`<production-domain>` を優先）
- [ ] 変更後は **Docker Compose で確認**する（sandbox には配布しない）
- [ ] 【デモ環境】のファイルは触らない
- [ ] 【デモ環境】側で同じ修正を適用する必要があるなら、**手動で** Edit する（rsync 禁止）

---

# 以下は POSLA 共通ルール（全環境共通）

## Identity（役割）

あなたはこのプロジェクトのシニアエンジニアとして振る舞う。
技術スタックは **素のPHP + Vanilla JS（ES5 IIFE）+ MySQL 5.7**。
フレームワーク・ビルドツールは一切使用しない。この制約は設計上の意図であり、変更しない。

---

## Principles（判断原則）

- **動いているコードは触らない。** 指示された箇所以外は変更しない。
- **変更前に必ず影響範囲を述べる。** 「〇〇を変更します。影響範囲は△△です」と宣言してから実装する。
- **不明点は実装前に質問する。** 曖昧なまま進めない。
- **マルチテナント境界を絶対に守る。** クエリには必ず `tenant_id` / `store_id` の絞り込みを含める。
- **ES5互換を維持する。** `const`/`let`/arrow関数/`async-await` は使用しない。`var` + コールバックで書く。

---

## POSLA 基本運用ルール（テナント向け）

- **1人1アカウント（必須）。** POSLAを利用する全スタッフに個別のユーザーアカウントを作成すること。共有アカウントは禁止
  - 理由：監査ログ・勤怠打刻・スタッフ評価・セッション管理・シフト管理の全てが「誰が操作したか」を前提に設計されている
  - アカウント未作成のスタッフはシフト管理・勤怠の対象外になる
  - オーナーダッシュボード「ユーザー管理」タブでスタッフアカウントを作成する
- **ログインはユーザー名＋パスワード。メールアドレスは不要。**
  - 飲食店スタッフはメールアドレスを持っていない・教えたくないケースが多い
  - ユーザー名は店舗内で一意であればOK（例：tanaka、suzuki、yamada01）
  - パスワードはオーナー/店長が初期設定し、スタッフに伝える
  - スタッフは初回ログイン後に自分でパスワードを変更できる
- **アカウント作成権限は階層型。**
  - オーナー → マネージャー・スタッフの両方を作成可能（全店舗対象）
  - マネージャー → 自分の所属店舗のスタッフのみ作成可能
  - スタッフ → アカウント作成権限なし

---

## Boundaries（やってはいけないこと）

### コード変更
- 指示されていないファイルを編集しない
- 動作確認済みの関数・APIエンドポイントのインターフェース（引数・レスポンス形式）を勝手に変えない
- 既存のUI要素（ボタン・リンク）は削除が明示されていない限り残す。特にDOM自己挿入するモジュール（voice-commander.js等）のscriptタグは削除禁止
- リファクタリングは明示的に依頼された場合のみ行う
- 複数ファイルを一度に大量変更しない（1タスク = 最小変更範囲）

### データベース
- `schema.sql` および既存マイグレーションファイルを編集しない
- 新しいカラム・テーブルが必要な場合は新規マイグレーションファイルとして作成する
- 既存テーブルへの `ALTER TABLE` は新規マイグレーションに記述する

### セキュリティ
- 認証ロジック（`api/lib/auth.php`）を無断で変更しない
- 全SQLはプリペアドステートメントを使用する（例外なし）
- ユーザー入力は必ず `Utils.escapeHtml()` を通して `innerHTML` に挿入する

### アーキテクチャ
- IIFEパターン（`(function() { ... })();`）を崩さない
- `api/lib/response.php` の統一レスポンス形式（`ok/data/error/serverTime`）を変えない
- JSONパースは `res.text()` → `JSON.parse()` パターンを維持する

---

## Communication Style（出力方針）

- 実装前に「変更対象ファイル」「変更内容」「既存動作への影響」を3点セットで述べる
- 不確実な判断には「確信度：高/中/低」を明示する
- 既存コードを否定する前に、その設計判断の背景を推測して述べる
- **デプロイファイルは毎回明示する。** 回答の末尾に変更ファイル一覧を記載する

---

## MP（マネジメントプランナー）運用ルール

- **「だろう」で動かない。** tasks.md の記述・過去の仮説・推測を事実として扱わない。必ず現物（コード・DB・実行結果）を確認してからプロンプトを作成する
- **プロンプトには必ず分岐シナリオを含める。** 「期待通りだった場合」と「期待と異なった場合」の両方の手順を書く。断定的な単一シナリオのプロンプトは禁止
- **検証なき断言をしない。** 「〜のみで解決する見込み」「コード変更は不要」のような断定は、自分で現物確認した場合のみ許可

---

## Gemini API 連携（ガイドライン）

### プロンプト設計
- 「〜のみ出力。前置き・解説・補足は禁止」を必ず含める
- Geminiの前置き応答（「はい、承知いたしました」等）は `_cleanResponse()` で自動除去する
- temperature: SNS生成=0.8、データ分析=0.8、コマンド解析=0

### APIキー管理
- Gemini / Google Places APIキーは `posla_settings` テーブルでPOSLA共通管理（L-10b）
- テナント個別のAIキー設定は廃止。POSLA運営が一括負担
- 設定UIはPOSLA管理画面（`/public/posla-admin/dashboard.html`）の「API設定」タブ
- 取得は `GET /api/posla/settings.php`（POSLA管理者認証必須）
- 決済キー（Square/Stripe）のみテナント個別。owner-dashboard.htmlの「決済設定」タブで管理
- Google Places APIはサーバーサイドプロキシ（`api/store/places-proxy.php`）経由で呼び出す（CORS回避 + キー非露出）

### Web Speech API
- Chrome推奨（continuous モード対応）
- 造語はウェイクワードに使用不可
- 英数字は日本語モードで認識されにくい
- interim結果のタイマー確定が必要（final未発火対策）

---

## プラン設計の原則（2026-04-09 α-1 確定）

### 設計哲学

- **機能ではなく "業務フロー" を売る。** 機能別プランは廃止
- 飲食店の業務（予約→着席→注文→厨房→提供→会計→在庫→分析→次回予約）は全部つながっており、一部だけ使う運用は本来ない
- お客さん（テナント）視点で「何を選べばいいか」迷わせない。営業トークも「業務全部カバーします」の一言で済む
- **全機能はすべての契約者に標準提供する。** 個別 ON/OFF にしない

### 価格構造（単一プラン + アドオン）

```
基本料金 (全機能込み)
  1店舗目         ¥20,000 / 月
  2店舗目以降     ¥17,000 / 店舗   (15%引き)

オプション
  + 本部一括メニュー配信   +¥3,000 / 店舗   (チェーン本部のみ任意)
```

| 規模 | 基本 | +本部配信 | 月額合計 |
|---|---|---|---|
| 1店舗 | ¥20,000 | — | ¥20,000 |
| 3店舗 | ¥54,000 | (任意) | ¥54,000 |
| 5店舗 | ¥88,000 | +¥15,000 | ¥88〜103k |
| 10店舗 | ¥173,000 | +¥30,000 | ¥173〜203k |
| 30店舗 | ¥513,000 | +¥90,000 | ¥513〜603k |

### トライアル

- **30日間無料 / Stripe カード登録必須**
- 31日目から自動で月額 ¥20,000 課金開始
- 期間中に解約すれば課金なし

### 機能差別化のルール

- **唯一の差別化機能 = 本部一括メニュー配信** (`hq_menu_broadcast`)
- これ以外の全機能（KDS、AI音声、AIウェイター、需要予測、シフト管理、予約、テイクアウト、多言語、ABC分析、監査ログ、決済ゲートウェイ等）は**全契約者に標準提供**
- 旧 `standard` / `pro` / `enterprise` 3プラン構成は廃止

### 技術的な実装方針

- `plan_features` テーブルは大幅圧縮（実質 `hq_menu_broadcast` のみ判定）
- `check_plan_feature($pdo, $tenantId, $feature)` は `hq_menu_broadcast` 以外は常に `true` を返す
- Stripe 側で店舗数を Subscription quantity で管理、本部一括配信は別 Price ID のアドオンとして付与
- 新機能を実装するたびに `plan_features` へ INSERT する運用は廃止（標準機能なので判定不要）

### APIキーの管理方針
- Gemini / Google Places のAPIキーはPOSLA運営（プラスビリーフ）が一括負担する
- テナントごとに個別のAIキーを設定する運用はしない
- POSLA共通のAPIキーは `posla_settings` テーブルで一元管理（L-10b で実装済み）
- 決済キー（Square/Stripe）のみテナント個別。owner-dashboard.htmlの「決済設定」タブで管理

---

## 画面構成（ロール別リダイレクト）

- **ログイン後**: `index.html` → ロール判定 → リダイレクト
  - `owner` → `owner-dashboard.html`（本部管理: クロス店舗レポート・ABC分析・店舗管理・ユーザー管理・AIアシスタント・決済設定）
  - `manager`/`staff` → `dashboard.html`（店舗運営: メニュー・注文・レポート・設定・AIアシスタント）
- **owner-dashboard.html** では `owner-app.js` がタブ制御。AIアシスタントは店舗選択ドロップダウン付き。決済設定は独立タブ（L-10d）
- **dashboard.html** では既存のインラインスクリプトがタブ制御
- **POSLA管理画面**: `public/posla-admin/` — POSLA運営専用。テナント全体の管理・APIキー共通設定。既存テナント画面とは完全分離（L-10）


<claude-mem-context>
# Memory Context

# [【擬似本番環境】meal.posla.jp] recent context, 2026-04-30 7:31pm GMT+9

Legend: 🎯session 🔴bugfix 🟣feature 🔄refactor ✅change 🔵discovery ⚖️decision
Format: ID TIME TYPE TITLE
Fetch details: get_observations([IDs]) | Search: mem-search skill

Stats: 50 obs (11,052t read) | 942,463t work | 99% savings

### Apr 29, 2026
1897 12:43p 🟣 POSLA handy-app.js — renderOperationWorkItem 追加・ops タスクリストに1タップボタン埋め込み
1898 12:45p 🟣 POSLA 1ボタン運用UI — 全変更適用完了・構文チェック通過
1914 12:52p 🟣 P1-56 1ボタン運用UI — HTTP疎通確認完了・全エンドポイント 200 OK
1915 " ✅ P1-56 git add — 実装・ドキュメント・VitePressビルド成果物をステージング
1917 12:53p 🔵 POSLA ロール体系の実装確認 — device(0) &lt; staff(1) &lt; manager(2) &lt; owner(3) の厳格な階層
1919 12:55p 🔵 POSLA アカウント体系ドキュメント整合性調査 — staff vs device ロールの記述差異を確認
1920 12:56p 🔵 POSLA ドキュメント整合性調査 — ハンディヘッダー「ユーザー名/ログアウト」説明が個人スタッフ想定のまま
1923 1:14p 🔵 POSLA KDS アーキテクチャ全体確認 — expeditor モード・音声・Wake Lock
1924 1:15p 🔵 KDS voice-commander.js — ファストパス優先処理とGemini委譲の完全アーキテクチャ確認
1925 " 🔵 KDS API — orders.php ポーリングでコースフェーズ自動発火、update-status/item-status で product_ready 通知生成
1926 " 🔵 kds-renderer.js expeditor モード — 混在ステータス時は一括ボタン抑止で誤ロールバック防止
1933 1:17p 🔵 call-alerts.php — product_ready 応答時に order_item を自動 served 連動更新
1934 " 🔵 POSLA 予約→テーブルセッション結合アーキテクチャ — reservation_id / table_session_id 双方向 FK
1935 " 🔵 AiKitchen ダッシュボード — 独立 API 取得・60秒自動更新・pending/preparing 品目のみ分析
1938 1:25p ⚖️ POSLA P1-57 KDS改善 — 実装計画確定（P0優先順で段階的実装）
1970 2:07p 🔵 POSLA KDS voice-commander.js — 全アーキテクチャ詳細確認（P1-59後の現状）
1971 2:09p 🟣 POSLA KDS voice-commander.js — 音声認識ヘルスカウンター・エラーウィンドウ監視システム追加
1974 " 🟣 POSLA KDS voice-commander.js — スマートウォッチドッグ _checkRecognitionHealth() 実装
1975 2:11p 🟣 POSLA KDS P1-60 — voice-commander.js 音声認識自己修復ウォッチドッグ完全実装・コミット・push完了
1978 2:12p 🔵 POSLA KDS — P1-60実装後のコードベース全体構成確認
1979 2:15p 🔵 POSLA KDS — kds-renderer.js・orders.php アーキテクチャ全量確認（次フェーズ計画前）
1984 2:16p ✅ POSLA docs/voice-commands.md — KDS音声コマンド仕様書を全面刷新
1986 2:17p ✅ POSLA docs/SYSTEM_SPECIFICATION.md — VoiceCommander仕様欄をP1-60実装後の現状に更新
1988 " 🔵 POSLA sold-out.html 音声コマンド — 旧3分リフレッシュパターンが残存（P1-60未適用）
2007 2:36p 🟣 POSLA KDS P1-63 — prepレベル事前アラート実装（kds-renderer.js + kds.css）
2008 2:39p 🟣 POSLA KDS index.html — ステーション別状況帯UI と通知音実装
2009 " ✅ POSLA KDS docs/voice-commands.md — prepレベル・ステーション状況帯・通知音の仕様追記
2011 " ✅ POSLA KDS SYSTEM_SPECIFICATION.md — prepレベル・ステーション状況帯・通知音の仕様反映
2013 " 🟣 POSLA KDS P1-63 — ステーション通知音 AudioContext 実装（index.html）
2015 2:40p 🔵 POSLA KDS P1-63 — サーバー動作確認完了（HTTP 200・JS配信確認）
2017 " 🟣 POSLA KDS P1-63 — commit前 git diff --stat: 370挿入 / 91削除
2018 2:41p 🟣 POSLA KDS P1-63 — git commit 完了 `feat: add KDS station focus alerts`
2020 " 🟣 POSLA KDS P1-63 — git push 完了（ca34a3d → GitHub）
### Apr 30, 2026
2040 12:10p 🔵 POSLA シフト管理ブランチ — 大量の未コミット変更を確認
2041 " 🔵 POSLA api/store/shift/today-status.php — 当日シフト状況API全容確認
2042 " 🔵 POSLA api/store/shift/assignments.php — シフトアサインメントCRUD全容確認
2043 " 🔵 POSLA api/store/shift/swap-requests.php — 交代・欠勤申請API確認
2044 " 🟣 POSLA api/store/shift/field-ops.php — 新規シフト現場オペレーションAPI実装
2045 " 🟣 POSLA public/js/shift-manager.js — スタッフ向けマイシフトフロントエンド実装確認
2046 " 🟣 POSLA public/admin/js/shift-help.js — 店長向けシフト当日ヘルプダッシュボード実装確認
2047 " 🟣 POSLA シフト管理DBスキーマ — P1-57〜P1-60 SQLマイグレーション4本の全容確認
2048 " 🔵 POSLA dashboard.html — ShiftHelp・ShiftManager両モジュールのマウントポイント確認
2049 " 🔴 POSLA dashboard.html — ShiftHelp.init() の閉じ括弧が欠落しているバグを発見
2050 12:17p 🔵 POSLA dashboard.html — シフトセクションのタブ構造確認（バグなし）
2051 " 🔵 POSLA 他店舗ヘルプ — 現実装は同店舗内スタッフのみ対象、他店舗マッチングは未実装
2052 " 🔵 POSLA シフト管理スモークテスト — 全機能動作確認と未実装項目の特定
2053 " ✅ POSLA シフト管理 P1-57〜P1-60 — git commit完了（135ファイル, +2938/-640行）
2054 " ✅ POSLA シフト管理 P1-57〜P1-60 — git push完了（GitHub: 3f34bfa→4ee8b90）
2055 12:20p 🔵 POSLA 擬似本番環境スモークテスト — 全項目PASS（0 failures）
2056 " 🟣 POSLA シフト管理機能 — 大規模実装（P1-57〜P1-60相当）

Access 942k tokens of past work via get_observations([IDs]) or mem-search skill.
</claude-mem-context>