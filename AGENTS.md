# AGENTS.md — POSLA

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

# [matsunoya-mt] recent context, 2026-04-19 5:15pm GMT+9

Legend: 🎯session 🔴bugfix 🟣feature 🔄refactor ✅change 🔵discovery ⚖️decision
Format: ID TIME TYPE TITLE
Fetch details: get_observations([IDs]) | Search: mem-search skill

Stats: 20 obs (4,732t read) | 1,498,741t work | 100% savings

### Apr 19, 2026
451 2:32p 🔵 matsunoya-mt プロジェクト構成の全体確認
452 2:36p 🔵 matsunoya-mt: docs/manual の3系統構成を確認
455 " 🔵 matsunoya-mt: 3系統マニュアルの章・セクション構造を詳細確認
456 2:39p 🔵 マニュアルと実コードの差分確認 — APIエンドポイント8件が実ファイルなし
457 " 🔵 テナントマニュアル内の壊れたMarkdownリンク7件を確認
458 " 🔵 α-1プラン移行後のデッドコード確認 — tenants.planとplan_featuresは機能制限に未使用
459 " 🔵 GPS認証の実装確認 — KDSはクライアント側haversine・勤怠はサーバー側・/api/store/gps-check.phpは実在しない
460 " 🔵 AIヘルプデスクknowledge base確認 — helpdesk-prompt-*.txtが生成済みで最新状態
477 2:54p 🔵 matsunoya-mt git status — 大規模変更の全体像確認
482 2:55p 🔵 matsunoya-mt マニュアル構成の確定ファイル一覧
483 " 🔵 マニュアル参照API vs 実在APIの差分 — 許容範囲内の3件のみ
484 " 🔵 CP1 担当スタッフPIN — process-payment.php と refund-payment.php の実装確認
486 2:57p 🔴 docs/manual/tenant/15-owner.md — 未実装API /api/owner/categories-csv.php の記述を削除
487 " 🔴 docs/manual/internal/06-troubleshoot.md — PIN_INVALID ログ例のAPIパスを実装に合わせて修正
488 " 🔵 マニュアル全APIリスト整合性チェック完了 — 残存する意図的な欠如は foo.php のみ
490 2:59p 🔴 docs/manual/internal/05-operations.md — バックアップ例示のプレースホルダー foo.php を実在ファイルに修正
491 3:07p 🔵 matsunoya-mt 新セッション — 静的コードレビュー（バグ報告専従）開始
492 3:08p 🔵 matsunoya-mt PHP全ファイル構文チェック — 全155ファイルでエラーゼロを確認
493 " 🔵 matsunoya-mt APIセキュリティパターン — 認証・テナント境界の一貫した実装を確認
494 " 🔵 matsunoya-mt エラーコードカタログ — E1xxx〜E9xxxの9系統233件が実装済み

Access 1499k tokens of past work via get_observations([IDs]) or mem-search skill.
</claude-mem-context>