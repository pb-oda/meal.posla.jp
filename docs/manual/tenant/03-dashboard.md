---
feature_id: DASHBOARD
title: 管理ダッシュボード
chapter: 03
plan: [all]
role: [owner, manager, staff]
audience: 全員
keywords: [ダッシュボード, タブ, 権限, ヘッダー, 店舗切替, ショートカット]
related: [02-login, 15-owner, 16-settings]
last_updated: 2026-04-18
maintainer: POSLA運営
---

# 3. 管理ダッシュボード

管理ダッシュボード（`dashboard.html`）は、マネージャーとスタッフが日常的に使用するメイン画面です。タブで機能を切り替えながら、店舗運営に必要な全ての操作を行います。

::: tip この章の範囲
- **`public/admin/dashboard.html`**: manager / staff 用の店舗運営ダッシュボード
- **`public/admin/owner-dashboard.html`**: owner 用の本部管理ダッシュボード（詳細は [15 章](./15-owner.md)）
- device ロールはここには入りません（2 章 2.5 参照）

本章の全記述は **2026-04-18 時点** のコード実装と突合済みです。変更があった場合は該当コード箇所を再確認してください。
:::

## 3.1 画面構成

### 3.1.1 ヘッダー

画面上部のヘッダーには以下の要素があります（`public/admin/dashboard.html` L35-57）。

| 位置 | 要素 | ID / 属性 | 説明 | 表示条件 |
|------|------|---------|------|--------|
| 左 | テナント名 | `header-tenant-name` | あなたの企業/ブランド名 | 常時 |
| 左 | 店舗セレクター | `store-selector-wrap` / `store-name` / `store-select` | 操作対象の店舗名表示・切替 | 複数店舗所属時のみドロップダウン表示 |
| 右 | **ハンディ** ショートカット | `header-tool-handy` | `../handy/index.html` を新タブで開く | `data-role-max="manager"` — **staff 非表示** |
| 右 | **KDS** ショートカット | `header-tool-kds` | `../kds/index.html` を新タブで開く | `data-role-max="manager"` — staff 非表示 |
| 右 | **レジ** ショートカット | `header-tool-register` | `../kds/accounting.html` を新タブで開く（内部で `cashier.html` にリダイレクト） | `data-role-max="manager"` — staff 非表示 |
| 右 | **注文画面** ショートカット | `link-customer-menu` | `../customer/menu.html?store_id=...` 等を新タブで開く（確認用） | `data-role-max="manager"` — staff 非表示 |
| 右 | ユーザー名 | `header-user-name` | ログイン中ユーザーの `displayName` または `email` | 常時 |
| 右 | **📖 取扱説明書** | `<a href="/public/docs-tenant/">` | 本マニュアルを新タブで開く | 常時 |
| 右 | **パスワード変更** | `btn-change-password` | パスワード変更モーダルを表示 | 常時 |
| 右 | **ログアウト** | `btn-logout` | `/api/auth/logout.php` を呼んで `index.html` に戻る | 常時 |

::: info ショートカットの staff 非表示ルール
`data-role-max="manager"` 属性が付いた要素は、スタッフロール（`userLevel=1`）より高い役職（`manager=2`, `owner=3`）までのみ表示される仕組みではなく、**「最大ロール manager まで」**なので manager 以下で表示されます。staff ロールは `L850-858` の明示的な非表示ループ（`_staffToolIds`）でも隠されています。

理由: staff は device アカウントで運用されるハンディ／KDS／レジに直接行く必要がないため。
:::

::: tip 右下に AI ヘルプデスク FAB（2026-04-19 新設）
画面右下に **オレンジ色（#ff6f00）の円形 ? ボタン**（60×60px）が常駐します。1 タップでチャットパネルが開き、マニュアル全章 + エラーカタログを知識ベースに回答してくれます（[14.6](./14-ai.md#146-ai-ヘルプデスク管理画面共通-fab) 参照）。dashboard.html / owner-dashboard.html / posla-admin/dashboard.html に共通設置。業務端末（KDS / レジ / ハンディ）には設置されません。
:::

### 3.1.2 owner ロールは自動で遷移

manager/staff が `dashboard.html` を直接開いても問題ありませんが、**owner がログインすると即座に `owner-dashboard.html` にリダイレクトされます**（`dashboard.html` L530-532）。

```javascript
if (_role === 'owner') {
  window.location.href = 'owner-dashboard.html';
  return;
}
```

owner が本部タブ（クロス店舗レポート等）を使うのが前提のため。

### 3.1.3 device ロールは直接 KDS/レジ/ハンディへ

device でログインすると dashboard.html の DOM は読み込まれず、即座に `visible_tools` に応じた画面にリダイレクトされます（`dashboard.html` L537-556）。優先順位は `handy > register > kds`。詳細は 2 章 2.5.4 参照。

## 3.2 メインタブ（グループタブ）一覧

ヘッダーの下に横並びで表示されるメインタブです。実装は `dashboard.html` L60-71。

| タブ名 | `data-group` | 最低ロール (`data-role-min`) | 最大ロール (`data-role-max`) | 店舗選択必須 (`data-requires-store`) |
|--------|------|------|------|--|
| メニュー管理 | `menu` | `manager` | — | ✅ |
| プラン・コース | `plan` | `manager` | — | ✅ |
| テーブル・フロア | `table` | `manager` | — | ✅ |
| 在庫・レシピ | `inventory` | `manager` | **`manager`** | ✅ |
| レポート | `report` | `manager` | — | ✅ |
| 店舗設定 | `config` | `manager` | — | ✅ |
| テイクアウト | `takeout` | `manager` | — | ✅ |
| AIアシスタント | `ai` | `manager` | — | ✅ |
| **予約管理** | `reservation` | `manager` | — | ✅ |
| **シフト管理** | `shift` | `staff` | — | ✅ |

### 3.2.1 ロール階層の読み方

`dashboard.html` L568 のロールレベル定義:

```javascript
var roleLevel = { staff: 1, manager: 2, owner: 3 };
```

表示判定:
```javascript
function checkRole(el) {
  var minLevel = roleLevel[el.dataset.roleMin] || 99;
  var maxLevel = el.dataset.roleMax ? (roleLevel[el.dataset.roleMax] || 99) : 99;
  return userLevel >= minLevel && userLevel <= maxLevel;
}
```

**意味**:
- `data-role-min="manager"` → manager(2) 以上でないと非表示 → staff(1) には見えない
- `data-role-min="staff"` → staff(1) 以上 → staff でも見える
- `data-role-max="manager"` → manager(2) 以下しか見えない（在庫タブはオーナーに見えない）

### 3.2.2 ロール別に見えるタブまとめ

| ロール | 見えるメインタブ |
|---|---|
| **owner** | （なし。`owner-dashboard.html` に自動リダイレクト） |
| **manager** | メニュー管理 / プラン・コース / テーブル・フロア / 在庫・レシピ / レポート / 店舗設定 / テイクアウト / AIアシスタント / 予約管理 / シフト管理 |
| **staff** | **シフト管理**（の 1 つだけ） |

::: info staff のダッシュボードはシフト管理に特化（2026-04-19 ポリシー更新）
staff ロールでログインした時のダッシュボードは **シフト管理タブのみ**に絞り込みました（`dashboard.html` L70 を `data-role-min="manager"` に変更）。

**理由**:
- staff のダッシュボード利用目的は **シフト確認 + 勤怠打刻 + 希望提出** が主
- 予約管理（編集・キャンセル・no-show 登録）は本来 manager 以上の権限
- 予約状況の「見るだけ」用途は **ハンディ POS の【📅 予約】タブ**で代替できる（フロアでの確認用）
- UI を絞ることで操作迷子を防止

**実装変更**:
- `dashboard.html` L70: `data-role-min="staff"` → `data-role-min="manager"` に変更
- `dashboard.html` L110: 予約サブタブも同様に変更
- API は `staff` でもアクセス可能（既存実装維持、将来のフロー柔軟性のため）
:::

### 3.2.3 `data-requires-store` と店舗未所属時の挙動

全メインタブに `data-requires-store` 属性があります。`stores=[]`（店舗に 1 件も所属していない状態）のユーザーがログインした場合、これらのタブは `L733-745` のループで `display:none` になり、結果として操作できるタブがなくなります。

テナントが新規契約直後、店舗を 1 件も作成していない段階では、**まず owner が `owner-dashboard.html` の「店舗管理」タブから店舗を作成**する必要があります。

## 3.3 サブタブ一覧

メインタブを押すとサブタブ群が切り替わります。`dashboard.html` L78-120 で定義。

### 3.3.1 メニュー管理 (`data-group="menu"`)

| サブタブ | `data-tab` | `data-role-min` | `data-role-max` | 説明 |
|---|---|---|---|---|
| カテゴリ管理 | `hq-categories` | manager | — | 本部メニューのカテゴリ（前菜／メイン／ドリンク等）管理 |
| オプション管理 | `option-groups` | manager | **manager** | トッピング・サイズ等のオプショングループ |
| 店舗メニュー | `store-menu` | manager | **manager** | 本部メニューの店舗別カスタマイズ（価格上書き／非表示／品切れ） |
| 限定メニュー | `local-menu` | manager | — | 店舗独自メニュー（`store_local_items`） |

::: tip 本部メニューの編集はどこで?
- **アドオン契約 (`hq_menu_broadcast=1`) 中**: 本部メニューの本体編集は owner-dashboard の「本部メニュー」タブから（P1-28）。店舗側では各品目の name/base_price 等は readonly。
- **アドオン未契約の個人店**: 「店舗メニュー」タブから事実上 `menu_templates` を編集できる（従来運用）。
- **新規店舗限定品**: どちらも「限定メニュー」タブから `store_local_items` に追加。

詳細は [4 章](./04-menu.md) と CLAUDE.md 「メニュー編集経路」を参照。
:::

### 3.3.2 プラン・コース (`plan`)

| サブタブ | `data-tab` | 説明 |
|---|---|---|
| 食べ放題プラン | `time-plans` | 時間制プランの定義（開始時刻・終了時刻・価格） |
| コース管理 | `courses` | コース料理の構成品目・価格 |

### 3.3.3 テーブル・フロア (`table`)

| サブタブ | `data-tab` | 説明 |
|---|---|---|
| テーブル管理 | `tables` | テーブル追加・削除・座席数設定 |
| フロアマップ | `floor-map` | テーブルのビジュアル配置（ドラッグで位置調整） |

### 3.3.4 在庫・レシピ (`inventory`)

| サブタブ | `data-tab` | `data-role-max` | 説明 |
|---|---|---|---|
| 在庫・レシピ | `inventory` | manager | 食材管理／レシピ／棚卸し／AI 需要予測 |

親タブ `inventory` が `data-role-max="manager"` のため、**オーナーには見えません**（owner は owner-dashboard のクロス集計側で見る想定）。

### 3.3.5 レポート (`report`)

| サブタブ | `data-tab` | 説明 |
|---|---|---|
| 売上レポート | `sales` | 日次売上・期間集計 |
| レジ分析 | `register` | レジ開閉・キャッシュログ・過不足 |
| 注文履歴 | `orders` | 全注文の検索・エクスポート |
| 回転率・客層 | `turnover` | 時間帯別回転率・客層分析 |
| スタッフ評価 | `staff-report` | スタッフ別売上・接客レポート |
| 併売分析 | `basket` | バスケット分析（同時購入パターン） |

::: info ABC 分析は owner-dashboard に移動
以前は dashboard.html にあった「ABC 分析」は owner 専用に格上げされ、owner-dashboard.html の「ABC 分析」タブに移っています（`dashboard.html` L92 のコメント参照）。
:::

### 3.3.6 店舗設定 (`config`)

| サブタブ | `data-tab` | 説明 |
|---|---|---|
| 店舗設定 | `settings` | 営業時間／税率／レジ設定／ウェルカムメッセージ等（[16 章](./16-settings.md)） |
| KDS ステーション | `kds-stations` | KDS の画面分割ステーション定義 |
| スタッフ管理 | `staff-mgmt` | スタッフ / デバイスアカウント管理（詳細は [2 章](./02-login.md)） |
| 監査ログ | `audit-log` | 全操作の監査ログ閲覧 |
| 領収書設定 | `receipt-settings` | レシートの店名・住所・税表記 |

### 3.3.7 テイクアウト (`takeout`)

| サブタブ | `data-tab` | 説明 |
|---|---|---|
| テイクアウト注文 | `takeout-orders` | テイクアウト注文の受付一覧・ステータス管理（[9 章](./09-takeout.md)） |

### 3.3.8 AI アシスタント (`ai`)

| サブタブ | `data-tab` | 説明 |
|---|---|---|
| AI アシスタント | `ai-assistant` | SNS 投稿生成・売上分析・競合調査・需要予測（[14 章](./14-ai.md)） |

### 3.3.9 予約管理 (`reservation`)

| サブタブ | `data-tab` | `data-role-min` | 説明 |
|---|---|---|---|
| 予約台帳 | `reservation-board` | manager | ガント台帳・新規予約・着席・no-show 管理（[24 章](./24-reservations.md)） |

### 3.3.10 シフト管理 (`shift`)

| サブタブ | `data-tab` | `data-role-min` | `data-role-max` | 説明 |
|---|---|---|---|---|
| 週間カレンダー | `shift-calendar` | manager | — | シフトの割当・編集を行うメイン画面 |
| マイシフト | `shift-my` | **staff** | **staff** | 自分の確定シフトを閲覧（staff 専用） |
| 希望提出 | `shift-availability` | **staff** | **staff** | 希望シフト日時の提出（staff 専用） |
| 勤怠一覧 | `shift-attendance` | manager | — | 全スタッフの出退勤記録 |
| テンプレート | `shift-templates` | manager | — | シフトパターン（早番・遅番等）定義 |
| シフト設定 | `shift-settings` | manager | — | 提出締切・休憩デフォルト・GPS 等 |
| 集計サマリー | `shift-summary` | manager | — | 労働時間・人件費・労基法警告 |
| ヘルプ | `shift-help` | manager | — | 他店舗へのスタッフ派遣要請（チェーン本部運用） |

## 3.4 店舗切替

複数店舗に所属している manager / staff は、ヘッダーの店舗セレクターで操作対象の店舗を切り替えます。

### 3.4.1 手順

1. ヘッダー左側の **「店舗」** 欄を確認
2. 単一所属: 店舗名がそのまま表示（切替不可）
3. 複数所属: 現在の店舗名の右に **「店舗切替」** リンクが表示される
4. リンクをクリックするとドロップダウンが表示される
5. 操作したい店舗を選択 → 画面が切り替わる

### 3.4.2 選択中の店舗はブラウザに記憶される

選択した `store_id` はブラウザの `localStorage` に保存され、次回ログイン時も同じ店舗が選ばれます。PC を共有する場合は意図しない店舗が選択されていないか注意してください。

::: warning 編集中データに注意
店舗を切り替えると、表示中のデータ（メニュー・注文・シフト等）は全て切替先の店舗のものに置き換わります。編集中のフォームがある場合は先に保存してください。
:::

## 3.5 タブ切替時の挙動

### 3.5.1 自動読み込み

タブ切替時にデータは自動で再読み込みされます。手動リフレッシュは不要です。

::: info タブ復元（2026-04-19 追加）
ブラウザをリロード（F5 / Cmd+R）しても **直前に開いていたタブが自動的に復元** されます（`localStorage` に保存）。誤ってリロードしてしまっても、操作中のタブに即戻れます。別 PC / 別ブラウザでは引き継がれません（ブラウザローカル保存のため）。
:::

### 3.5.2 読み込み中の表示

タブによっては一時的に **「読み込み中...」** のスピナーが表示されます。ネットワークが遅いとこの表示が長くなります。

### 3.5.3 読み込み失敗時

API がエラーを返した場合、エラーメッセージが該当タブ内に表示されます（例: 「読み込みに失敗しました」）。その場合は以下を確認:

1. ネットワーク接続
2. ログインセッションが切れていないか（切れていれば 2 章の方法でログインし直す）
3. POSLA 運営のメンテナンス情報

## 3.6 スタッフ向けの特殊動作

### 3.6.1 スタッフのショートカット非表示

`dashboard.html` L847-858 の実装で、staff ロールは以下の要素が明示的に `display:none` になります。

```javascript
if (_role === 'staff') {
  var _staffToolIds = [
    'staff-tool-handy','staff-tool-kds','staff-tool-register',
    'header-tool-handy','header-tool-kds','header-tool-register'
  ];
  // 全てに display:none
}
```

理由: staff は device アカウント（KDS / レジ / 共有ハンディ）を使う設計のため、個人アカウントからハンディ／KDS／レジを直接開く経路は用意していない。個人ハンディはスタッフのスマホから `/handy/` に直接アクセスする運用。

### 3.6.2 勤怠打刻ボタン

staff ログイン時、ダッシュボードのデフォルト画面（**スタッフ画面、panel-staff-home**）に **🟢 出勤 / 🔴 退勤** ボタンが表示されます。`attendance-clock.js` が `#staff-attendance-slot` に挿入する仕組み（2026-04-19 ヘッダーから移動）。GPS 打刻が有効な店舗では位置情報チェックが入ります（16 章参照）。

::: info 実装詳細
現状、勤怠打刻は **ヘッダーではなくシフト管理タブ内** で行います。「常時ヘッダーに出退勤ボタンが表示される」という旧マニュアルの記述は **誤り** でした（2026-04-18 時点で実装上そのような UI はありません）。
:::

## 3.7 プラン制約（α-1 単一プラン）

POSLA は 2026-04-09 以降、**単一プラン + アドオン**構成です（CLAUDE.md 「プラン設計の原則」）。過去の standard / pro / enterprise 3 プラン構成は廃止済み。

### 3.7.1 全テナントに標準提供される機能

基本料金 ¥20,000/月（1 店舗目）+ ¥17,000/月（2 店舗目以降）を支払っている限り、以下は **全て使えます**:

- KDS・AI 音声・AI ウェイター・需要予測
- シフト管理・勤怠連携
- 予約（L-9）・テイクアウト・多言語
- ABC 分析・監査ログ・決済ゲートウェイ連携（Stripe／スマレジ）

### 3.7.2 アドオン契約時のみ現れる機能

**本部一括メニュー配信** (`features.hq_menu_broadcast=1`, +¥3,000/月/店舗) を契約すると:

- owner-dashboard.html に **「本部メニュー」** タブが出現（`owner-dashboard.html` L52）
- dashboard.html の「店舗メニュー」タブで、本部マスタ項目（name / base_price 等）が **readonly** になる
- 店舗独自品は「限定メニュー」タブから追加

未契約店舗では上記制御は入らず、従来通り「店舗メニュー」タブから `menu_templates` を直接編集可能。

::: warning 「プランに含まれない機能」は本部配信アドオンのみ
かつての「standard/pro/enterprise のプラン外タブは非表示」という記述は 2026-04-09 以降は該当しません。全契約者に全機能（本部配信以外）が標準提供されます。
:::

## 3.8 キャッシュバスター バージョン（2026-04-18 時点）

dashboard.html は JS ファイルの変更があるたびに `?v=xxx` を更新してブラウザキャッシュを無効化します。

| スクリプト | バージョン |
|---|---|
| `printer-service.js` | `20260418u2` |
| `admin-api.js` | `20260408p1a` |
| `menu-override-editor.js` | `20260408p130` |
| `table-editor.js` | `20260418` |
| `floor-map.js` | `20260408p1be` |
| `settings-editor.js` | `20260418u2` |
| `user-editor.js` | `20260418c` |
| `device-editor.js` | `20260418` |
| `ai-assistant.js` | `20260418helpdesk` |
| `shift-help.js` | `20260408p1be` |
| `reservation-board.js` | `20260418d` |

バージョン更新後も古いキャッシュが残っている場合は、ブラウザを強制再読み込み（Cmd+Shift+R / Ctrl+Shift+F5）してください。

## 3.9 よくある質問

**Q1. スタッフでログインしたら予約管理タブが見える？**

**見えません**（2026-04-19 変更）。staff ロールが見えるダッシュボードタブは **シフト管理のみ**。中のサブタブも `shift-my`（マイシフト）/ `shift-availability`（希望提出）のみ。

予約状況を「見るだけ」したい場合は、ハンディ POS の **【📅 予約】タブ**を使ってください（ホールスタッフが予約客の到着を待つ際の標準フロー）。

**Q2. タブの表示順を変えたい／不要なタブを隠したい**

UI 上で表示設定を変える機能は **現状ありません**。必要に応じて管理画面の実装を修正することになります（POSLA 運営にご相談ください）。

**Q3. 同じアカウントで別 PC を同時に開ける？**

可能です（2 章 2.3 参照）。ログイン時に警告情報がサーバーに返ってきますが、UI 表示は未実装のため気付きにくいです。`user_sessions` テーブルに記録は残ります。

**Q4. owner が `dashboard.html` に直接 URL アクセスしたら？**

L530-532 で即 `owner-dashboard.html` にリダイレクトされます。device が URL 直叩きしても同様に KDS / レジ / ハンディにリダイレクトされます。

**Q5. 「表示設定 ⚙ ボタン」というのは？**

過去のマニュアルに書かれていたが **現在は実装されていません**。UI カスタマイズ機能は未搭載です。

## 3.10 トラブルシューティング

| 症状 | 原因 | 対処 |
|---|---|---|
| 全てのタブが見えない | `stores=[]`（店舗未所属）状態 | owner が「店舗管理」から店舗を作成し、ユーザーに紐付ける |
| シフトタブが staff で見えない | 旧実装のキャッシュ or JS エラー | Cmd+Shift+R で強制リロード。コンソールエラーを確認 |
| 予約タブが staff で見えなくなった | 仕様変更（2026-04-19、staff には非表示） | 仕様通り。予約は manager 以上、または ハンディ POS の【📅 予約】タブで確認 |
| 店舗切替ドロップダウンが出ない | 1 店舗のみ所属 | 仕様通り。複数店舗を持てば出る |
| ハンディ／KDS／レジショートカットが見えない | staff でログインしている | 仕様通り。個人ハンディは `/handy/` に直接アクセス |
| 「取扱説明書」を押してもログイン画面に飛ぶ | docs-tenant の認証ゲートが動作している | 管理画面にログイン済みの状態で開き直すと閲覧可能 |

## 3.11 ダッシュボード初期化に使う API

ダッシュボード読み込み時、以下の API が連続で呼ばれます（`public/admin/js/admin-api.js`）。

- `GET /api/auth/me.php` — 現在のユーザー情報・所属店舗一覧
- `GET /api/auth/check.php` — セッション有効性軽量チェック（25min タイマーリセット用）
- `GET /api/store/settings.php?store_id=xxx` — 店舗設定（営業時間・税率・GPS 等）
- `GET /api/store/menu-version.php?store_id=xxx` — メニューキャッシュ更新判定（軽量）
- `GET /api/posla/dashboard.php`（POSLA 管理者のみ） — POSLA ダッシュボード統計

ログアウト時:
- `DELETE /api/auth/logout.php` — セッション破棄
- パスワード変更時: `POST /api/auth/change-password.php`

## 3.12 関連章

- [2. ログインとアカウント](./02-login.md) — 認証・デバイスアカウント
- [15. オーナーダッシュボード](./15-owner.md) — owner 専用画面
- [16. 店舗設定](./16-settings.md) — 店舗設定タブの詳細
- [24. 予約管理 (L-9)](./24-reservations.md) — 予約タブ詳細
- [25. テーブル開放認証](./25-table-auth.md) — QR 開放と自動着席

## 3.13 操作前チェックリスト（初回ログイン時）

新しい店舗・新しいスタッフでダッシュボードを使い始める前に、以下を確認してください。

| # | 確認項目 | 確認方法 | NG時の対応 |
|---|---|---|---|
| 1 | 推奨ブラウザ (Chrome 最新) を使っているか | URL バー右上の Chrome マーク確認 | Chrome をインストール |
| 2 | 解像度が 1280×800 以上 | OS のディスプレイ設定 | より大きい画面に移動 / タブレット推奨 |
| 3 | Wi-Fi が安定 (5Mbps 以上) | https://fast.com で実測 | ルーター再起動 / 有線 LAN |
| 4 | ブラウザの cookie / localStorage が許可されている | Chrome 設定 → サイトの設定 | サードパーティ Cookie を eat.posla.jp に許可 |
| 5 | 自分のロールを把握 (owner / manager / staff) | ログイン後 ヘッダー右上 ユーザー名横 | 適切なロールを発行してもらう |
| 6 | 所属店舗が 1 つ以上ある | ヘッダー左の店舗名 | owner に店舗紐付け依頼 |

## 3.14 ダッシュボードの開き方（クリック単位）

**前提**: ブラウザで `https://eat.posla.jp/` を開ける状態。

1. アドレスバーに **`https://eat.posla.jp/`** と入力 → Enter
2. ログイン画面 (`index.html`) が表示される
3. **「ユーザー名」** 欄をタップ → 例: `tanaka`
4. **「パスワード」** 欄をタップ → 例: `Tanaka2025`
5. **【ログイン】** ボタンをタップ
6. ロール判定 → 自動遷移
   - `owner` → `owner-dashboard.html`
   - `manager` / `staff` → `dashboard.html`
   - `device` → `handy/index.html` または `kds/cashier.html` または `kds/index.html`
7. 初回ログイン時は **「パスワードを変更してください」** モーダルが出る (任意)

### 3.14.1 入力フィールド詳細表（ログインフォーム）

| フィールド | 必須 | 形式 | 補足 |
|---|:---:|---|---|
| ユーザー名 | ✅ | 英数字 + `_` `-` 1-32 文字 | 大文字小文字を区別 (`Tanaka` ≠ `tanaka`) |
| パスワード | ✅ | 8 文字以上、英字 1 + 数字 1 以上 | `password-policy.php` で検証 |

### 3.14.2 画面遷移の ASCII

```
[index.html (ログイン)]
        │
        │ POST /api/auth/login.php
        ▼
[role 判定]
        ├─ owner   → owner-dashboard.html
        ├─ manager → dashboard.html
        ├─ staff   → dashboard.html (シフトタブのみ)
        └─ device  → handy/index.html
                     or kds/cashier.html
                     or kds/index.html
                     (visible_tools の優先度: handy > register > kds)
```

## 3.15 タブ復元機能（CB1c）

ブラウザをリロード (Cmd+R / F5) しても、**直前に開いていたタブが自動復元** されます。

### 3.15.1 仕組み

実装: `dashboard.html` L926-927, L1097-1098

```javascript
var savedGroup = localStorage.getItem('posla_last_group');
var savedTab   = localStorage.getItem('posla_last_tab');
// ...タブ切替時に保存
localStorage.setItem('posla_last_tab', tabId);
if (_activeGroup) localStorage.setItem('posla_last_group', _activeGroup);
```

### 3.15.2 保存される情報

| キー | 保存タイミング | 例 |
|---|---|---|
| `posla_last_group` | メインタブ切替時 | `menu` / `report` / `shift` |
| `posla_last_tab` | サブタブ切替時 | `hq-categories` / `sales` / `shift-calendar` |
| `mt_selected_store` | 店舗切替時 | `2` (store_id) |
| `posla_active_subtabs` | サブタブの表示/非表示設定 | JSON object |

### 3.15.3 リセット方法

別の店舗 / 別のスタッフが同じ PC を使う場合は localStorage をクリアしてください。

1. Chrome → **設定** → プライバシーとセキュリティ → サイトの設定
2. `eat.posla.jp` を選択
3. **「データを削除」** クリック
4. ブラウザを再起動

または、シークレットモード (Cmd+Shift+N) で運用すれば毎回クリーンな状態でログイン可能。

## 3.16 AI ヘルプデスク FAB（CB1c 新設、右下オレンジボタン）

実装: `public/shared/js/posla-helpdesk-fab.js`

### 3.16.1 何ができるか

- ダッシュボードから離れずに **マニュアル全章 + エラーカタログ** を AI に質問可能
- エラー番号 `[E3024]` のような表示を **そのまま貼り付ける** だけで、意味と対処方法を返してくれる
- 過去 4 ターンの会話履歴を文脈として保持

### 3.16.2 配置位置

| 画面 | FAB あり | mode |
|---|:---:|---|
| `dashboard.html` (店舗運営) | ✅ | `helpdesk_tenant` |
| `owner-dashboard.html` (本部管理) | ✅ | `helpdesk_tenant` |
| `posla-admin/dashboard.html` (POSLA 運営) | ✅ | `helpdesk_internal` (内部資料も検索) |
| KDS / レジ / ハンディ (業務端末) | ✗ | (作業中の誤タップ防止) |

### 3.16.3 操作手順

1. 画面右下の **オレンジ色 (#ff6f00) の ? ボタン** (60×60px) をタップ
2. チャットパネルが右下に展開 (380×600px、モバイルは全画面)
3. 入力欄に質問を入力
4. **【送信】** ボタン or Enter キー (Shift+Enter は改行)
5. 「AI が回答を準備中…」 → 数秒で回答表示
6. 上部の「×」ボタンで閉じる (会話履歴は保持される)

### 3.16.4 サジェストチップ

初回起動時に以下のチップが表示される:

- 「E3024 とは？」
- 「会計手順」
- 「返金手順」
- 「出勤打刻」

タップすると即座に該当質問が送信される。

### 3.16.5 API エンドポイント

`POST /api/store/ai-generate.php`
- Body: `{ mode: 'helpdesk_tenant', prompt: 'エラー E3024 とは？' }`
- Response: `{ ok: true, data: { text: '...' } }`
- エラー: `AI_NOT_CONFIGURED` (503) — POSLA 運営に連絡

## 3.17 店舗切替の実例（matsunoya / torimaru）

### 3.17.1 matsunoya: 1 オーナー、3 店舗（渋谷・新宿・池袋）

```
ヘッダー左:
店舗: 松乃家 渋谷店 ▼ [店舗切替]
        │ クリック
        ▼
        ┌──────────────┐
        │ 松乃家 渋谷店 ✓│
        │ 松乃家 新宿店  │
        │ 松乃家 池袋店  │
        └──────────────┘
```

- `localStorage.setItem('mt_selected_store', '2')` で渋谷を記憶
- 翌日もログインすれば渋谷から開始

### 3.17.2 torimaru: 個人店、1 店舗のみ

```
ヘッダー左:
店舗: とり丸 (切替不可)
```

- 店舗が 1 つしかないので **「店舗切替」リンクは出ない**
- ドロップダウンも非表示

## 3.18 ロール別タブ可視性のまとめ実例

### 3.18.1 staff `tanaka` でログインした場合

| メインタブ | 表示？ |
|---|:---:|
| メニュー管理 | ✗ (manager 以上) |
| プラン・コース | ✗ |
| テーブル・フロア | ✗ |
| 在庫・レシピ | ✗ |
| レポート | ✗ |
| 店舗設定 | ✗ |
| テイクアウト | ✗ |
| AI アシスタント | ✗ |
| 予約管理 | ✗ (2026-04-19 から非表示) |
| シフト管理 | ✅ (`shift-my` / `shift-availability` のみ) |

→ tanaka はログイン後、**シフト管理タブだけ** が見える。マイシフト確認 + 希望提出が主用途。

### 3.18.2 manager `kobayashi` でログインした場合

| メインタブ | 表示？ |
|---|:---:|
| メニュー管理 | ✅ |
| プラン・コース | ✅ |
| テーブル・フロア | ✅ |
| 在庫・レシピ | ✅ (manager 限定) |
| レポート | ✅ |
| 店舗設定 | ✅ |
| テイクアウト | ✅ |
| AI アシスタント | ✅ |
| 予約管理 | ✅ |
| シフト管理 | ✅ (全サブタブ) |

→ kobayashi は店舗運営の全機能を使える。

### 3.18.3 owner `oda` でログインした場合

→ 即座に `owner-dashboard.html` にリダイレクトされ、`dashboard.html` のタブは見ない。
→ `owner-dashboard.html` には「店舗管理 / ユーザー管理 / クロス店舗レポート / ABC 分析 / 本部メニュー (アドオン契約時) / 決済設定 / AI アシスタント」タブが表示される ([15 章](./15-owner.md))。

## 3.19 トラブルシューティング表（dashboard 全般）

| 症状 | 原因 | 対処 | 参照 |
|---|---|---|---|
| 全タブ非表示 | `stores=[]` | owner が店舗追加 + ユーザー紐付け | [15 章](./15-owner.md) |
| シフトタブが staff で見えない | キャッシュ or JS エラー | Cmd+Shift+R で強制リロード | [19 章](./19-troubleshoot.md) |
| 店舗切替ドロップダウン無 | 1 店舗のみ所属 | 仕様 | — |
| ハンディ/KDS/レジショートカット無 | staff でログイン | 仕様 (device で運用) | [2 章 2.5](./02-login.md) |
| 「取扱説明書」がログイン画面に飛ぶ | docs-tenant Basic 認証 | 管理画面ログイン状態で再アクセス | — |
| FAB (右下 ? ボタン) が出ない | JS 読込失敗 | DevTools コンソールで `posla-helpdesk-fab.js` 404 確認 | [14.6](./14-ai.md) |
| FAB を開いても応答なし | `AI_NOT_CONFIGURED` | POSLA 運営に連絡 (Gemini キー未設定) | [14 章](./14-ai.md) |
| タブ復元が効かない | localStorage 無効 / シークレットモード | 通常モードで開く / Cookie 許可 | — |
| owner がログインしても owner-dashboard に飛ばない | キャッシュ | Cmd+Shift+R | — |
| device で URL 直叩きしたら dashboard が見える | 通常はリダイレクトされる | キャッシュ / cookie クリア → 再ログイン | — |
| 「読み込みに失敗しました」 | API 障害 / セッション切れ | 再ログイン or POSLA 運営に連絡 | — |
| 「権限が不足しています」 | ロール不足 | 上司に依頼 | — |

## 3.20 よくある質問 (FAQ)

**Q1. ログインしたまま PC を放置したら？**

25 分操作なし → 警告モーダル表示。30 分で自動ログアウト。再ログインが必要。

**Q2. 同じアカウントで別 PC を同時に使える？**

可能ですが、`user_sessions` テーブルに記録され、警告情報がレスポンスに含まれます (UI 表示は未実装)。

**Q3. パスワード変更したら他端末はどうなる？**

P1-5 セキュリティ強化により、**他端末は強制ログアウト** されます。再ログインで復帰可能。

**Q4. owner が dashboard.html に直接 URL アクセスしたら？**

L530-532 で即 `owner-dashboard.html` にリダイレクト。device も同様。

**Q5. 「表示設定 ⚙ ボタン」というのは？**

旧マニュアルの記述ですが **未実装**です。UI カスタマイズ機能は 2026-04 時点で搭載していません。

**Q6. タブ復元は別ブラウザでも引き継がれる？**

引き継がれません。`localStorage` はブラウザローカル保存のため。Chrome → Edge への移行は不可。

**Q7. シークレットモードで使っても大丈夫？**

可能です。ただし毎回タブ復元・店舗選択がリセットされます。

**Q8. FAB を画面の左下に移動できる？**

現状不可 (`posla-helpdesk-fab.js` で `right:20px` ハードコード)。要望があれば POSLA 運営に伝えてください。

**Q9. ダッシュボードのテーマカラーを変えられる？**

現状不可。POSLA 標準カラー (オレンジ #ff6f00 系) で固定。

**Q10. 1 アカウントで複数テナントを跨げる？**

跨げません。テナント境界は絶対です。複数テナントを管理する場合はテナントごとにアカウントを発行してください。

## 3.21 関連章

- [2. ログインとアカウント](./02-login.md)
- [14. AI アシスタント](./14-ai.md) — 14.6 AI ヘルプデスク FAB
- [15. オーナーダッシュボード](./15-owner.md)
- [16. 店舗設定](./16-settings.md)
- [19. トラブルシューティング](./19-troubleshoot.md)

## 3.22 更新履歴

- **2026-04-19**: フル粒度展開 (操作前チェックリスト、入力フィールド表、画面遷移 ASCII、タブ復元 CB1c、FAB CB1c、ロール別実例、トラブル表、FAQ 10 件)
- **2026-04-18**: コード突合で全面書き直し。ロール制御・サブタブ一覧を実装（`dashboard.html` L60-120, L524-596）と完全一致させた。旧記述のうち以下を訂正:
  - スタッフは「シフト管理タブのみ」→ 「予約管理／シフト管理の 2 タブ」
  - standard/pro/enterprise プラン制御 → α-1 単一プラン + アドオンのみ
  - 勤怠打刻ヘッダーボタン → シフト管理タブ内
  - 表示設定 ⚙ ボタン → 未実装
