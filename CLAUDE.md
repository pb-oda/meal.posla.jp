# CLAUDE.md — POSLA

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
  - **ユーザー名は POSLA 全体（グローバル）で一意であること**（例：matsunoya_tanaka、momonoya_suzuki01）。テナントを跨いだ衝突も禁止
    - 旧仕様（店舗内一意）からの変更（S3 セキュリティ修正 #14, 2026-04-19）。tenant ID をプレフィックスに付ける運用を推奨
  - パスワードはオーナー/店長が初期設定し、スタッフに伝える
  - スタッフは初回ログイン後に自分でパスワードを変更できる（`POST /api/auth/change-password.php`）
- **パスワードポリシー（P1-5）。**
  - 最低8文字以上
  - 英字（A-Z / a-z）を1文字以上必須
  - 数字（0-9）を1文字以上必須
  - 検証ロジックは `api/lib/password-policy.php` の `validate_password_strength()` に集約
  - 適用対象: 新規ユーザー作成（owner/manager）、自己パスワード変更（テナント側 + POSLA管理者側）
- **アカウント作成権限は階層型。**
  - オーナー → マネージャー・スタッフの両方を作成可能（全店舗対象）
  - マネージャー → 自分の所属店舗のスタッフのみ作成可能
  - スタッフ → アカウント作成権限なし
- **device ロール（P1a）— KDS / レジ端末専用アカウント。**
  - スタッフ（人）ではなく**端末**のためのログインアカウント
  - 1端末 = 1アカウント。`users.role = 'device'`
  - ロール階層は `device(0) < staff(1) < manager(2) < owner(3)`
  - **シフト管理・人件費・スタッフレポート・監査ログのスタッフ集計から除外される**
  - 作成は dashboard.html → スタッフ管理タブ → 「+ デバイス追加」ボタン（manager 以上）
  - owner-dashboard.html のユーザー一覧には**表示されない**（GET /api/owner/users.php で `role != 'device'`）
  - ログインすると dashboard.html ではなく `handy/index.html` / `kds/cashier.html` / `kds/index.html` に直接遷移する
    （`stores[0].userVisibleTools` または `staffVisibleTools` の `handy` > `register` > `kds` の優先度で振り分け）
  - `visible_tools` は `kds` / `register` / `handy` のいずれか（または複数組み合わせ、カンマ区切り）
  - サーバー側バリデーション: `api/store/staff-management.php` の `_validate_visible_tools()` で許可値以外は 400 エラー
  - 作成・更新・削除は `POST/PATCH/DELETE /api/store/staff-management.php?kind=device`
  - KDS / レジ系 API（`api/kds/*`、`api/connect/terminal-token.php`、`api/store/terminal-intent.php` 等）は
    device からも呼び出せるよう `require_role(...)` ではなく `require_auth()` のみで保護する
  - シフト割当（`api/store/shift/apply-ai-suggestions.php`）に device を指定するとサーバーが
    `DEVICE_NOT_ASSIGNABLE` エラーで拒否する

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
- パスワード検証は `api/lib/password-policy.php` の `validate_password_strength()` を必ず通す（独自実装禁止）
- パスワードは平文で保存・ログ出力しない。`password_hash(PASSWORD_DEFAULT)` でハッシュ化する
- AI APIキー（Gemini等）をフロントエンド（JS/HTML）に露出させない。サーバープロキシ経由で呼び出す

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

### サーバープロキシ経由の呼び出し（P1-6）
- フロントエンド（admin/AI機能）から Gemini API を直接叩かない。**ブラウザにAPIキーを渡さない**
- サーバー側プロキシ `api/store/ai-generate.php`（POST、`require_auth()` 必須）経由で呼び出す
  - リクエスト Body: `{ prompt, temperature?, max_tokens? }`
  - temperature: 0-2 にクランプ（未指定時は 0.7）
  - max_tokens: 1-8192 にクランプ（未指定時は 1024）
  - モデル: `gemini-2.0-flash`（ハードコード）
  - レスポンス: `{ text }`（生成テキストのみ）
  - エラー: `AI_NOT_CONFIGURED`(503) — `posla_settings.gemini_api_key` 未設定
- `api/store/settings.php` の `?include_ai_key=1` 分岐は後方互換のため残しているが、`tenants.ai_api_key` ではなく `posla_settings` から読み出す（既存 voice-commander/shift-calendar/sold-out 用）
- `public/admin/js/ai-assistant.js` は完全にプロキシ経由に移行済み。`_apiKey` 変数は廃止し `_apiConfigured`（boolean）のみ保持

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

### 既存テナントの扱い

- 現状の matsunoya / momonoya / torimaru はすべて Hiro のテストデータのみ → grandfather 不要
- マイグレーション時は自由に再割当 or 削除可

### APIキーの管理方針
- Gemini / Google Places のAPIキーはPOSLA運営（プラスビリーフ）が一括負担する
- テナントごとに個別のAIキーを設定する運用はしない
- POSLA共通のAPIキーは `posla_settings` テーブルで一元管理（L-10b で実装済み）
- 決済キー（Stripe）のみテナント個別。owner-dashboard.htmlの「決済設定」タブで管理

---

## メニュー編集経路（重要・運用ルール）

POSLAのメニューは3層構造：
- `menu_templates` … 本部マスタ（テナント単位、全店共通の name/category/base_price/image/calories/allergens）
- `store_menu_overrides` … 店舗差分（NULL=本部継承、値あり=店舗上書き、is_sold_out/price/is_hidden/image_url）
- `store_local_items` … 店舗限定品（その店舗だけ、本部マスタに紐づかない）

お客さん側のセルフメニューでは `api/lib/menu-resolver.php` の `resolve_store_menu()` が3層を統合してから返す。

### 本部一括配信アドオン契約時の編集ルール（厳守）
- アドオン契約 = `features.hq_menu_broadcast=1` のテナント（チェーン本部運用）
- 全店共通の品目（本部マスタ）は **owner-dashboard 「本部メニュー」タブからのみ編集可**（owner ロール限定、P1-28 で実装）
- 店舗側 dashboard 「店舗メニュー」タブからは **本部マスタ項目（name/name_en/category_id/calories/allergens/base_price/image_url）は readonly**（P1-29 で実装）
- 店舗側で編集できるのは `store_menu_overrides` の項目のみ：店舗価格上書き / 非表示 / 売り切れ
- 店舗独自品は **dashboard 「限定メニュー」タブ（`store_local_items`）から追加**する。本部マスタには絶対に書き込まない

### アドオン未契約時の編集ルール（個人店・小規模チェーン）
- `features.hq_menu_broadcast=0` のテナント（基本料金のみ）
- owner-dashboard に「本部メニュー」タブは出ない
- dashboard 「店舗メニュー」タブから事実上 `menu_templates` を全項目編集できる（既存運用、変更しない）
- 店舗独自品は同じく「限定メニュー」タブから追加

### 全プラン共通
- **店舗独自メニューの作成は「限定メニュー」タブを使う**。「店舗メニュー」タブで新規追加するのではない（こちらは本部マスタ + 店舗オーバーライド統合表示）

---

## 画面構成（ロール別リダイレクト）

- **ログイン後**: `index.html` → ロール判定 → リダイレクト
  - `owner` → `owner-dashboard.html`（本部管理: クロス店舗レポート・ABC分析・店舗管理・ユーザー管理・AIアシスタント・決済設定）
  - `manager`/`staff` → `dashboard.html`（店舗運営: メニュー・注文・レポート・設定・AIアシスタント）
- **owner-dashboard.html** では `owner-app.js` がタブ制御。AIアシスタントは店舗選択ドロップダウン付き。決済設定は独立タブ（L-10d）
- **dashboard.html** では既存のインラインスクリプトがタブ制御
- **POSLA管理画面**: `public/posla-admin/` — POSLA運営専用。テナント全体の管理・APIキー共通設定。既存テナント画面とは完全分離（L-10）
