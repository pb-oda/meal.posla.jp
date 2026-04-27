---
feature_id: pwa-staff
title: スタッフ画面 PWA 化
chapter: 9
plan: [base]
role: [posla_admin, owner, manager]
audience: internal
keywords: [PWA, ServiceWorker, manifest, ホーム画面, オフライン, スタッフ]
related: [/internal/05-operations.md, /internal/06-troubleshoot.md]
last_updated: 2026-04-19
maintainer: posla
---

# 9. スタッフ画面 PWA 化 (meal.posla.jp)

## 9.0 はじめにお読みください

POSLA の **スタッフ向け業務画面のみ** を PWA (Progressive Web App) として構成し、タブレットやスマホのホーム画面に追加 → 1 タップでアプリのように起動できるようにする。**顧客側のセルフメニュー / 予約 / テイクアウト画面は意図的に PWA から除外** している。

::: tip 標準環境
業務端末（KDS / レジ / ハンディ）の **標準環境は Android 端末 + Google Chrome 最新版**（2026-04-20 確定）。iOS Safari でもホーム画面追加は可能だが、音声コマンド・通知音・PWA インストールバナー (`beforeinstallprompt`) が安定しないため業務端末としては非推奨。詳細は §9.6 / §9.10 を参照。
:::

::: warning 顧客セルフメニューはブラウザ運用
顧客が QR からアクセスするセルフメニュー (`/customer/menu.html` 等) には Service Worker / manifest を一切載せない。
理由:
- 1 回限りのスキャン → 注文 → 会計でセッションが終わる短期利用フロー
- ホーム画面追加されると古いキャッシュが残るリスク (来店ごとにメニュー差分があるため)
- セッション情報・QR トークン・テーブル ID が URL に含まれるため、過去のキャッシュが他人に流用されるリスク
- 二重防御: SW の fetch handler でも `/customer/` および `/api/customer/` を含む request は強制 passthrough
:::

## 9.1 対象画面とスコープ

| スコープ | パス | 用途 | manifest theme | アイコン |
|---|---|---|---|---|
| `/admin/` | `index.html` `dashboard.html` `owner-dashboard.html` `device-setup.html` | 管理者・店長操作 | `#1976d2` (青) | 管 |
| `/kds/` | `index.html` `cashier.html` `sold-out.html` | キッチン・レジ | `#2e7d32` (緑) | 厨 |
| `/handy/` | `index.html` `pos-register.html` | ハンディ POS | `#ff6f00` (橙) | 配 |

::: info accounting.html を除外する理由
`public/kds/accounting.html` は 2026-04-02 以降 `cashier.html` への redirect 専用。空ボディなので PWA 化しない。
:::

## 9.2 ファイル構成

各スコープに 3 ファイル + アイコン 4 種を配置:

```
public/admin/
├── manifest.webmanifest      ← Web App Manifest
├── sw.js                     ← Service Worker (scope: /admin/)
├── pwa-register.js           ← クライアント登録スクリプト
└── icons/
    ├── icon.svg              ← マスターベクター
    ├── icon-192.png          ← Android 推奨
    ├── icon-512.png          ← splash 用
    └── apple-touch-icon.png  ← iOS Safari 用 (180x180)
```

`public/kds/` `public/handy/` も同構成。

## 9.3 キャッシュ戦略

| 対象 | 戦略 | 理由 |
|---|---|---|
| `/api/*` | **キャッシュしない (passthrough)** | 業務データ整合性。注文・会計は常に最新 |
| `/api/customer/*` | passthrough (二重防御) | 同上 |
| `/customer/*` | passthrough (二重防御) | 顧客側 PWA 化禁止 |
| POST / PATCH / DELETE / PUT | passthrough | mutating request はキャッシュしない |
| URL に `token` `session` `checkout` `reservation` `store_id` `table_id` `pin` `staff_pin` 含む | passthrough | 個別セッション・取引 ID は他人に流用される |
| HTML | network-first → 失敗時キャッシュ | オフライン時もログイン画面等を表示 |
| CSS / JS / 画像 / フォント / manifest | stale-while-revalidate | 更新は次回読込で取得 |

## 9.4 業務データ整合性のための非対応

以下は意図的に **実装しない**:

- オフライン注文の自動キュー (会計差分の温床)
- オフライン会計の自動再送 (二重課金リスク)
- バックグラウンド同期 (Background Sync API)
- プッシュ通知 (Web Push)
- IndexedDB によるメニュー / 注文ローカルストア

オフライン時は HTML のキャッシュ表示のみで、業務操作は不可 (network-first で 503 表示)。

## 9.5 バージョニングと更新

各 SW のキャッシュ名は `posla-<scope>-static-<VERSION>` の形式:
- `posla-admin-static-<VERSION>`
- `posla-kds-static-<VERSION>`
- `posla-handy-static-<VERSION>`

`<VERSION>` の真実は各 `sw.js` 内の `var VERSION` 変数。コメントには書かない (本ドキュメントとコメントの二重管理を避けるため)。

現行版 (2026-04-20 時点):
- `public/admin/sw.js` → `v6`
- `public/kds/sw.js` → `v4`
- `public/handy/sw.js` → `v4`

新版デプロイ時は `sw.js` の `VERSION` を 1 つ上げる。`activate` ハンドラで `posla-<scope>-` prefix の旧 cache を全削除 → 古いアセット混在事故を防止。

PWA Phase 1 (2026-04-20) で **手動更新方式** に変更されました。新版 SW は install 後 `waiting` 状態に留まり、ユーザーが PWAUpdateManager の「更新する」ボタンを押したときに初めて `skipWaiting` → `clients.claim` で切替わります (詳細は §9.12)。

::: tip 手動更新方式の判断理由 (Phase 1 で変更)
- 旧仕様 (Phase 1 前): install 直後に `self.skipWaiting()` → waiting をスキップ → activate → `clients.claim()` で既存タブも即座に新 SW 制御下に置く
- 新仕様 (Phase 1 以降): install 後は `waiting` 状態に留まる → PWAUpdateManager がバナーを表示 → ユーザーの「更新する」操作で `postMessage({type: 'SKIP_WAITING'})` → SW が `skipWaiting` → `controllerchange` → クライアント側で `location.reload()`
- 変更の理由:
  - 業務操作中 (注文入力中・会計途中) に予期せず JS / HTML が切り替わるリスクを回避
  - 新版がいつ反映されたかをユーザーが認識できる (バナーで明示)
  - キャッシュリセットボタンと組み合わせて現場で復旧しやすい
- 初回 install (controller なし) は仕様により自動的に activate されるため新規ユーザーには影響なし
- API は常に passthrough なので、SW が切り替わっても REST レスポンス形式の不整合は発生しない
- 静的アセット (CSS/JS) は新 SW の activate 後に順次差し替わる。古いキャッシュは activate 時の `caches.delete()` で除去
- `clients.claim()` は activate ハンドラに残っているため、`skipWaiting` 発火後は既存タブも新 SW 制御下に入る (= reload で確実に新版に切替)
:::

## 9.6 ホーム画面への追加方法

### Android Chrome (標準環境・推奨)
1. Chrome で対象 URL を開く
2. アドレスバーまたはメニュー (︙) → 「アプリをインストール」または「ホーム画面に追加」
3. ホーム画面アイコンから standalone 起動 (manifest の `display: fullscreen` (KDS) / `standalone` (handy/admin) に従う)

PWA としてインストール条件を満たした場合、Chrome は自動的に **`beforeinstallprompt` イベント** を発火する。POSLA は `public/shared/js/install-prompt-banner.js` (ES5 IIFE) でこのイベントをキャプチャし、`admin/dashboard.html` と `admin/owner-dashboard.html` の画面下部に「📱 POSLA をホーム画面に追加して 1 タップで起動できます。インストールしますか?」+ [インストール] [後で] バナーを表示する。詳細は §9.11。

### iOS Safari (補助・サポート対象外)
1. Safari で対象 URL を開く (例: `https://meal.posla.jp/admin/dashboard.html`)
2. 共有ボタン → 「ホーム画面に追加」
3. 名前 (例: `POSLA管理`) を確認 → 追加
4. ホーム画面のアイコンタップで standalone 起動

::: warning iOS は `beforeinstallprompt` 非対応
iOS / iPadOS の Safari (および中身が Safari の iOS Chrome) は、Web 側からインストールダイアログを出す API (`beforeinstallprompt`) を提供していない。そのため画面内の「インストール」ボタンからの能動的な促進は不可能で、ユーザーが手動で共有メニューから追加する必要がある。この仕様差が、業務端末の標準環境を Android Chrome に絞る大きな理由のひとつ。
:::

## 9.7 トラブルシューティング

| 症状 | 原因 | 対処 |
|---|---|---|
| ホーム画面追加メニューが出ない | manifest 読み込み失敗 / icon サイズ不足 | DevTools → Application → Manifest を確認 |
| 古い JS / CSS が表示される | SW キャッシュが残存 | DevTools → Application → Service Workers → Unregister + Storage Clear、または Ctrl+Shift+R |
| API レスポンスが古い | 通常はキャッシュしない設定。されていたら設定異常 | DevTools → Network で `(from ServiceWorker)` 表示がないか確認 |
| オフライン時の表示が崩れる | HTML はキャッシュされるが API は 503 になるので部分崩壊 | 期待通り。業務継続不可なのは仕様 |
| 顧客 menu.html が PWA 化されている | 二重バグ | 本仕様に違反。`public/customer/` 配下に SW 登録があれば即削除 |
| インストール案内バナーが Android Chrome で出ない | `beforeinstallprompt` 発火条件未達 (HTTPS / manifest / SW / 一定の利用エンゲージメント) または既に「後で」で dismiss 済 | DevTools → Application → Manifest で installable 判定、`localStorage['posla.installPrompt.dismissed.<scope>']` を削除 |
| iOS Safari でインストール案内バナーが出ない | iOS は `beforeinstallprompt` 非対応のため、青いガイドバナー (共有メニュー手順) のみ表示する仕様 | 設計通り。Safari の共有ボタン (□↑) → 「ホーム画面に追加」を案内 |
| ホーム画面のアイコンを長押しで削除したら「ブックマーク削除」のような表示になる | iOS / Android の OS 側仕様。POSLA 側で制御不可 | 利用者には「アプリ削除と同じ感覚で OK」と案内する |
| 既インストール済み PWA の挙動がおかしい (古い start_url で開く / 古い manifest が残る) | manifest 変更前にインストールされた PWA はホーム画面側に古い情報が残ることがある | ホーム画面アイコンを削除 → ブラウザで対象 URL を開き直し → 再インストール |
| Android Chrome で「アプリをインストール」が一度も出ていない | エンゲージメント不足 (ページ訪問が短すぎ / 戻りがない) または `display: minimal-ui` 等で要件未達 | 一度ブラウザで操作してから再訪問。または右上メニュー (︙) → 「ホーム画面に追加」/「アプリをインストール」から手動 |

## 9.8 確認コマンド

### 顧客側に PWA 登録が混入していないこと
```bash
rg -n "serviceWorker|manifest.webmanifest|pwa-register|navigator.serviceWorker" \
  public/customer
```
→ ヒットゼロが期待値。

### Service Worker が `/api/` をキャッシュしないことの確認
```bash
rg -n "/api/|caches\.put|caches\.open|fetch\(" \
  public/admin/sw.js \
  public/kds/sw.js \
  public/handy/sw.js
```
→ `/api/` 検出は passthrough 分岐のみのはず。

### 各スコープ scope の確認
```bash
grep -l "manifest.webmanifest" public/admin/*.html \
  public/kds/*.html \
  public/handy/*.html
```
→ 全 staff HTML がリストに含まれること。

## 9.9 デプロイ対象ファイル

- `public/admin/manifest.webmanifest` `sw.js` `pwa-register.js` `icons/*`
- `public/kds/manifest.webmanifest` `sw.js` `pwa-register.js` `icons/*`
- `public/handy/manifest.webmanifest` `sw.js` `pwa-register.js` `icons/*`
- 編集された 9 HTML (`<head>` 末尾と `<body>` 末尾に各 1〜3 行追加)

## 9.10 標準環境ポリシー (2026-04-20 確定)

POSLA の業務端末方針:

| 用途 | 標準環境 | 推奨環境 | サポート対象外 |
|---|---|---|---|
| KDS | Android タブレット 10〜11" + Chrome 最新版 | — | iPad/Safari, Fire, Samsung Internet, アプリ内ブラウザ, 古い Android (9 以前) |
| レジ | Android タブレット + Chrome 最新版 | PC + Chrome / Edge 最新版 | iPad/Safari, Fire |
| ハンディ | Android スマホ/小型タブレット + Chrome 最新版 | — | iPhone/Safari, Fire, 古い Android |
| 管理画面 | PC + Chrome 最新版 | PC + Edge / Android Chrome | IE, 古いブラウザ |
| 顧客セルフメニュー | iPhone/Android 問わず主要ブラウザ全般 | — | (制限なし) |

### 標準環境バナー (`public/shared/js/standard-env-banner.js`)

KDS / レジ / ハンディ画面の上部に、Android Chrome 以外でアクセスされた場合に控えめな注意バナーを表示する ES5 IIFE helper。

- 配置: `public/shared/js/standard-env-banner.js`
- 読み込み: KDS/cashier/handy 各 HTML の `<body>` 末尾 (pwa-register.js の直後) に
  ```html
  <script src="../shared/js/standard-env-banner.js?v=20260420-env"></script>
  <script>StandardEnvBanner.init('kds');</script>
  ```
- scope 引数: `'kds'` / `'cashier'` / `'handy'` / `'pos-register'`
- 顧客画面 (`public/customer/`) では二重防御で動作しない (URL 判定で skip)
- 閉じるボタン押下後は `localStorage['posla.envBanner.dismissed.<scope>'] = '1'` に記録
- 表示する UA 分類:
  - `android-chrome` → 表示しない (標準環境)
  - `desktop-ok` (PC Chrome / Edge) → 表示しない (管理用途として許容)
  - `ios-safari` (iPhone/iPad/Touch Mac) → 黄色警告バナー
  - `fire` (Silk / KFAUWI 系) → 茶色警告バナー
  - `other` (古い Android, Samsung Internet, アプリ内ブラウザ等) → 黄色警告バナー
- innerHTML は固定文言のみ。動的入力を絶対に挿入しない (XSS 防止)

### 業務端末セットアップチェック (Definition of Ready)

新しい業務端末を投入する際の確認項目:

- [ ] Android 端末か (バージョン 10 以降を推奨)
- [ ] Google Chrome 最新版か (設定 → アプリ → Chrome → 更新)
- [ ] Chrome の自動更新が有効か (Google Play で「自動更新」ON)
- [ ] PWA としてホーム画面に追加済みか (任意。1 タップ起動を推奨)
- [ ] マイク権限が許可されているか (KDS 音声コマンド利用時)
- [ ] 通知音テストができるか (KDS 起動オーバーレイで「タップして開始」を 1 回押す)
- [ ] Wi-Fi 接続があるか (端末右上のアイコンを確認)
- [ ] 画面ロック OFF / 省電力モード OFF (KDS 常時運用時)
- [ ] KDS 画面が常時前面で開かれているか
- [ ] 標準環境バナーが出ていないか (出ていたら端末入れ替えを推奨)

将来的には `public/admin/device-setup.html` をデバイス自動登録だけでなく上記環境チェックも実行する画面に拡張予定だが、現時点ではドキュメント運用とする。

## 9.11 PWA インストール案内バナー (`install-prompt-banner.js`)

スタッフ管理画面 (`admin/dashboard.html`, `admin/owner-dashboard.html`) にホーム画面追加 (PWA インストール) を能動的に促進する ES5 IIFE helper。

- 配置: `public/shared/js/install-prompt-banner.js`
- 読み込み: 各 admin HTML の `<body>` 末尾 (`pwa-register.js` の直後) に
  ```html
  <script src="../shared/js/install-prompt-banner.js?v=20260420-install"></script>
  <script>InstallPromptBanner.init('admin');</script>   <!-- owner-dashboard では 'owner' -->
  ```
- scope 引数: `'admin'` / `'owner'`。閉じた状態を画面別に保存
- 顧客画面 (`public/customer/`) では二重防御で動作しない
- 既にインストール済み (`display-mode: standalone` / iOS standalone / `android-app://` referrer) なら表示しない
- 「後で」/「閉じる」押下後は `localStorage['posla.installPrompt.dismissed.<scope>'] = '1'` に記録
- `appinstalled` イベント発火時もバナーを片付け、dismissed フラグを立てる

### 表示分岐

| ブラウザ環境 | 動作 |
|---|---|
| Android Chrome / PC Chrome / PC Edge | `beforeinstallprompt` をキャプチャして緑バナー表示。[インストール] で `prompt()` 呼出 |
| iOS Safari (iPhone/iPad/Touch Mac) | イベント非対応のため青バナーで「Safari の共有ボタン (□↑) →「ホーム画面に追加」」案内 |
| PC Safari, 古いブラウザ等 | 何もしない (PWA インストール不可のため) |

### `beforeinstallprompt` の挙動メモ

- このイベントは Chrome 系ブラウザのみ発火。発火条件は HTTPS + manifest + SW + 一定の利用エンゲージメントを満たすこと
- ユーザー操作 (クリック等) を起点にしないと `prompt()` は呼べない → バナーの [インストール] ボタンクリックで初めて呼出
- `userChoice` Promise の `outcome` が `'accepted'` か `'dismissed'` か返る
- 一度 `dismissed` されると、Chrome は次回の発火まで一定時間 (90 日程度) 待つ
- iOS は API 自体非対応 (Safari 17 時点)

### innerHTML / セキュリティ

`innerHTML` は使用せず、`textContent` のみで文言を設定 (固定文言)。動的入力は一切受け取らない。XSS 経路なし。

## 9.12 PWA Phase 1 — 更新マネージャ + Wake Lock (2026-04-20 追加)

Phase 1 のスコープ:

- 更新マネージャ (`public/shared/js/pwa-update-manager.js`) を 6 画面に配置 (admin/owner/kds/cashier/handy/pos-register)
- Wake Lock helper (`public/shared/js/wake-lock-helper.js`) を **KDS のみ** に配置
- SW VERSION バンプ: `admin v4→v5` / `kds v2→v3` / `handy v2→v3`
- SW に message handler を追加: `SKIP_WAITING` (waiting → activate 切替) / `GET_VERSION` (デバッグ用)

Phase 1 で **やらない**:

- Push 通知 / バッジ表示
- オフライン注文送信 / オフライン会計 / オフライン決済
- 顧客セルフメニュー (`public/customer/`) の PWA 対応
- DB 変更 / API 仕様変更
- レジ・ハンディへの Wake Lock 展開 (Phase 1 後に判断)

### 9.12.1 PWAUpdateManager (`public/shared/js/pwa-update-manager.js`)

**目的:** SW の新版を検知し、画面下部に小さな更新バナーで通知する。「更新する」ボタン押下時のみ skipWaiting + reload。自動リロードは行わない (業務操作中の事故防止)。あわせて画面右下にキャッシュリセットボタンを置き、現場で表示異常を復旧できるようにする。

**読み込み:**

```html
<script src="../shared/js/pwa-update-manager.js?v=20260420-pwa1"></script>
<script>PWAUpdateManager.init({ scope: 'kds', cachePrefix: 'posla-kds-' });</script>
```

**options:**

| key | 必須 | 説明 |
|---|---|---|
| `scope` | yes | 画面別 ID (`'admin'` / `'owner'` / `'kds'` / `'cashier'` / `'handy'` / `'pos-register'`) |
| `cachePrefix` | yes | キャッシュリセット対象 prefix。SW の `SCOPE_PREFIX` と一致させる |

**SW スコープと prefix の対応:**

| HTML | scope | cachePrefix |
|---|---|---|
| `public/admin/dashboard.html` | `admin` | `posla-admin-` |
| `public/admin/owner-dashboard.html` | `owner` | `posla-admin-` |
| `public/kds/index.html` | `kds` | `posla-kds-` |
| `public/kds/cashier.html` | `cashier` | `posla-kds-` |
| `public/handy/index.html` | `handy` | `posla-handy-` |
| `public/handy/pos-register.html` | `pos-register` | `posla-handy-` |

**更新バナー:**

- `controllerchange` 待ちでリロード (skipWaiting 完了確認)
- フォールバック: 3 秒後に強制リロード
- 「後で」ボタン押下でバナー非表示 (永続化はしない。新版検出のたびに再表示)

**キャッシュリセット:**

- `caches.keys()` から `cachePrefix` 一致のものだけ削除
- localStorage / sessionStorage / 認証 Cookie / 店舗選択情報は **削除しない**
- 削除後 `location.reload()` で再読込

**顧客画面 (`public/customer/`):** URL 判定で early return。二重防御。

### 9.12.2 WakeLockHelper (`public/shared/js/wake-lock-helper.js`)

**目的:** KDS 画面表示中に Screen Wake Lock を取得し、画面が暗くなりにくくする。**保証機能ではなく補助機能**。

**読み込み:** **KDS のみ** (`public/kds/index.html`)

```html
<script src="../shared/js/wake-lock-helper.js?v=20260420-pwa1"></script>
<script>
  WakeLockHelper.attachStatusElement(document.getElementById('kds-wake-lock-status'));
</script>
```

「KDSを開始」ボタン (`#kds-startup-btn`) の click ハンドラ内で `WakeLockHelper.acquire()` を呼ぶ。**user gesture 起点で取得することが成功率を上げるポイント** (`navigator.wakeLock.request('screen')` は環境によって user gesture 要件あり)。

**動作:**

- `navigator.wakeLock` が無い → `'unsupported'` 状態を表示
- 取得成功 → `'active'` 状態を表示
- 失敗 → `'blocked'` 状態を表示 (バッテリーセーバー / 省電力 / OS 設定)
- `visibilitychange` で画面が visible に戻ったら自動再取得
- `pagehide` で release を試みる
- 失敗を throw しない / `console.error` を撒かない

**KDS ヘッダーの状態表示:**

`#kds-wake-lock-status` の `textContent` に以下のいずれかが入る (textContent のみ・innerHTML 不使用)。

| state | 表示文言 |
|---|---|
| `active` | 画面維持: 有効 |
| `blocked` | 画面維持: 端末設定を確認 |
| `released` | 画面維持: 一時停止 |
| `unsupported` | 画面維持: 未対応 |
| `pending` | 画面維持: 確認中 |

**Wake Lock が効かないときの確認ポイント:**

- Android Chrome 最新版か (Safari は対応が限定的)
- 端末の省電力モード OFF
- 画面ロック設定「なし」または最長
- バッテリーセーバー OFF
- KDS 画面がフォアグラウンドで開かれているか
- ブラウザのサイト設定で画面表示権限が拒否されていないか

### 9.12.3 SW message handler (Phase 1 で追加)

3 つの SW (`admin/sw.js`, `kds/sw.js`, `handy/sw.js`) に共通で追加:

```javascript
self.addEventListener('message', function (event) {
  if (!event.data) return;
  if (event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  } else if (event.data.type === 'GET_VERSION') {
    if (event.ports && event.ports[0]) {
      event.ports[0].postMessage({ version: VERSION, scope: SCOPE_NAME });
    }
  }
});
```

PWAUpdateManager の「更新する」ボタンから `postMessage({ type: 'SKIP_WAITING' })` で発火。`GET_VERSION` はデバッグ用 (現状利用なし)。

PWA Phase 1 (修正版・2026-04-20) で **`install` イベント内の `self.skipWaiting()` を撤廃** しました。新版 SW は install 後 `waiting` 状態に留まり、PWAUpdateManager の「更新する」ボタンが押されるまで activate されません。

- 既存タブが開かれていない状態で新版が install されたケース: 仕様により controller がいないため自動的に activate される (新規ユーザーは影響なし)
- 既存タブが開かれた状態で新版が install されたケース: `waiting` に留まる → PWAUpdateManager がバナーを表示 → ユーザー操作で切替
- これにより業務操作中の予期せぬリロードが発生しなくなった

あわせて `pwa-register.js` 側でも `reg.waiting.postMessage({type: 'SKIP_WAITING'})` の自動送信を撤廃しています (旧実装は新版を即時適用していたため Phase 1 の方針と矛盾していた)。

### 9.12.4 install / activate / fetch ロジックの変更点

Phase 1 で追加したのは `message` handler と VERSION バンプ + `install` 内の自動 `skipWaiting` 削除のみ。以下は **すべて無変更**:

- `/api/` 完全 passthrough (キャッシュ禁止)
- `/api/customer/` 二重防御 passthrough
- `/customer/` 二重防御 passthrough
- POST/PATCH/DELETE/PUT passthrough
- センシティブクエリ (token / session / pin 等) を含む URL は passthrough
- HTML: network-first + 失敗時キャッシュ
- 静的アセット: stale-while-revalidate
- `activate` 時の旧 cache 削除 (`SCOPE_PREFIX` 一致の旧 VERSION)
- `clients.claim()` (activate ハンドラ内で維持。`skipWaiting` 後は既存タブも新 SW 制御下に入る)

## 9.13 PWA Phase 2a — Web Push 購読土台 + バッジ管理 (2026-04-20)

Phase 2a のスコープ:

- 購読保存 (subscribe / unsubscribe) と DB テーブル
- フロント UI (admin / owner: ボタンつき設定パネル / KDS / handy: ヘッダ簡易表示)
- SW の push / notificationclick / message ハンドラ
- バッジ管理 (KDS / handy で呼び出し件数 → setAppBadge)
- VAPID 公開鍵を返す `GET /api/push/config.php`
- 内部送信関数の "枠組み" のみ (実送信は Phase 2b)

Phase 2a で **やらない** (Phase 2b 以降に分離):

- VAPID 秘密鍵を使った実送信 (RFC 8030 / VAPID JWT 署名 / AES-128-GCM 暗号化 / HKDF)
- POSLA 管理 UI での VAPID キー設定画面
- call_alert / low_rating / important_error 各 API からの送信トリガー実装
- `POST /api/push/test.php` (テスト送信)

**実送信を Phase 2b に分離した理由:** Web Push 実送信は VAPID JWT 署名 + ペイロード暗号化 + cURL POST が必要。本プロジェクトは Composer / vendor 未導入のため、minishlink/web-push-php 等の既存ライブラリを安全に組み込めない。素 PHP で実装する場合は暗号化の正しさ検証が大きい。Phase 2a で土台を確立し、送信は Phase 2b で別タスクとして安全に追加する方針。

### 9.13.1 DB スキーマ (`push_subscriptions`)

`sql/migration-pwa2-push-subscriptions.sql` で追加。

| カラム | 型 | 用途 |
|---|---|---|
| `id` | VARCHAR(36) PK | 内部 ID |
| `tenant_id` | VARCHAR(36) NOT NULL | テナント境界 |
| `store_id` | VARCHAR(36) NULL | 店舗紐付け (owner で全店共通の場合 NULL 可) |
| `user_id` | VARCHAR(36) NOT NULL | 購読者 |
| `role` | VARCHAR(20) NOT NULL | owner / manager / staff / device |
| `scope` | VARCHAR(20) NOT NULL | admin / owner / kds / cashier / handy / pos-register (許可リスト検証) |
| `endpoint` | TEXT NOT NULL | PushSubscription.endpoint |
| `endpoint_hash` | CHAR(64) UNIQUE | SHA-256(endpoint) — 一意制約 + ログ用 |
| `p256dh`, `auth_key` | VARCHAR | 公開鍵 / Auth secret |
| `user_agent`, `device_label` | VARCHAR | 端末識別補助 |
| `enabled` | TINYINT | 0=無効 (soft delete) |
| `created_at`, `updated_at`, `last_seen_at`, `revoked_at` | DATETIME | 監査用 |

Index:
- `uk_endpoint_hash` (UNIQUE) — 重複防止
- `idx_store_enabled_scope` (store_id, enabled, scope) — 店舗 + scope での絞り込み
- `idx_user_enabled` (user_id, enabled) — 自己購読一覧
- `idx_tenant_role_enabled` (tenant_id, role, enabled) — manager/owner 宛て送信用

### 9.13.2 VAPID キー管理方針

| key (`posla_settings.setting_key`) | 用途 | フロント露出 |
|---|---|---|
| `web_push_vapid_public` | VAPID 公開鍵 (Base64URL、87 文字) | `GET /api/push/config.php` で返す |
| `web_push_vapid_private_pem` | VAPID 秘密鍵 (PEM 形式、241 文字) | **絶対に露出しない**。サーバー内 `get_posla_setting()` 経由のみ |

POSLA 共通インフラ設定 (Gemini API キーと同じ運用)。`api/posla/settings.php` の `_public_setting_keys()` 許可リストには **追加しない** (allowlist 漏れ = 機密扱い = 安全側)。

VAPID キー生成は `scripts/generate-vapid-keys.php` (Phase 2b で追加) を CLI で 1 回実行 → 出力 SQL を `posla_settings` に手動 INSERT する運用。設定画面 UI は情報表示のみ (Phase 2b 拡張 §9.14.13) — 鍵生成・ローテーションは依然 CLI 固定 (誤操作リスク回避)。

### 9.13.3 通知種別と送信条件 (Phase 2b 実装済み・2026-04-23 現在)

実装済みは 5 種。すべて `push_send_to_store` / `push_send_to_roles` / `push_send_to_user` (`api/lib/push.php`) 経由で送信。

| type | 送信元 | 送信先 | 実 payload (title / body / url / tag) |
|---|---|---|---|
| `call_alert` | `api/kds/call-alerts.php:192` (KDS →スタッフ呼び出し POST 直後) | 店舗全員 (`push_send_to_store`) | title=`キッチンから呼び出し` / body=理由文 / url=`/handy/index.html` / tag=`kitchen_call_<alertId>` |
| `call_staff` | `api/customer/call-staff.php:99` (来店客がセルフメニュー画面からスタッフ呼び出し) | 店舗全員 (`push_send_to_store`) | title=`お客様呼び出し: テーブル <tableCode>` / body=理由文 / url=`/handy/index.html` / tag=`call_staff_<tableCode>` |
| `low_rating` | `api/customer/satisfaction-rating.php:198` (満足度 rating<=2 INSERT 後) | 同 store の `role IN (manager, owner)` (`push_send_to_roles`) | title=`満足度: 低評価` / body=`評価 N / 商品名 / 理由ラベル` / url=`/admin/dashboard.html` / tag=`low_rating_<ratingId>` |
| `important_error` | `api/cron/monitor-health.php:257` (I-1 運用支援エージェント異常検知) | 同 store の `role IN (manager, owner)` (`push_send_to_roles`) | title=`重要エラー: <title>` / body=detail 先頭 140 字 / url=`/admin/dashboard.html` / tag=`<type>` |
| `push_test` | `api/push/test.php:59/68` (POSLA管理画面「テスト送信」ボタン) | 自分宛 (`push_send_to_user`) または店舗全員 (`push_send_to_store`、manager/owner のみ) | POST body で指定した `title` / `body` / `url` / `tag` をそのまま |

すべて **fail-open**: 送信失敗 (ネットワーク・410 Gone・404) で元の業務 API を失敗扱いにしない。送信失敗の subscription は `enabled=0 + revoked_at` に soft delete (`api/lib/web-push-sender.php`)。

`important_error` の送信失敗のみ例外的に `/home/odah/log/php_errors.log` に `[I-1][push] important_error_failed` を記録する (監視系通知の静かな失敗を避けるため)。

### 9.13.4 SW push handler

3 SW (admin/sw.js, kds/sw.js, handy/sw.js) で同じ構造:

```javascript
self.addEventListener('push', function (event) {
  var payload = {};
  try { if (event.data) payload = event.data.json(); }
  catch (e) { /* fallback to text() */ }
  var title = payload.title || 'POSLA 通知';
  var options = {
    body: payload.body || '',
    icon: payload.icon || '/<scope>/icons/icon-192.png',
    badge: payload.badge || '/<scope>/icons/icon-192.png',
    tag: payload.tag || 'posla-<scope>',
    data: { url: payload.url || '/<scope>/index.html' }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});
```

`notificationclick` は **同一 origin かつ各 scope の `/<scope>/` 配下のみ** に open を制限。`/customer/` 配下の URL や外部 URL は無視します。

### 9.13.5 バッジ管理 (`badge-manager.js`)

`navigator.setAppBadge` / `clearAppBadge` を使用。Badging API 未対応ブラウザでは静かに no-op。

| 画面 | 算出根拠 (Phase 2a) |
|---|---|
| KDS | DOM 内 `.kds-call-item` の件数 (5 秒ごと) |
| ハンディ | DOM 内 `.handy-call-item` の件数 (5 秒ごと) |
| 管理画面 / オーナー画面 | Phase 2a では **未実装** (安全に取れる件数の定義が要検討。Phase 2b で重要アラート件数等を追加) |

### 9.13.6 セキュリティ設計

- `/api/push/*` は全て `require_auth()` (認証必須)
- `tenant_id` / `user_id` / `role` は **サーバー側セッションから確定** (クライアント値を信用しない)
- `store_id` はクライアントから受け取るが `require_store_access()` で検証
- `scope` は許可リスト (`admin/owner/kds/cashier/handy/pos-register`) で検証
- 全 SQL プリペアドステートメント
- `notificationclick` の URL は同一 origin かつ scope 別許可 prefix のみ
- 顧客画面 (`public/customer/`) には新規 helper 一切混入なし (URL 判定で early return)
- ページロード直後の `Notification.requestPermission()` 呼び出しなし (ユーザー操作起点のみ)
- Push payload に機密情報を入れない (Phase 2b 実装時に注意)
- 送信失敗時の endpoint は SHA-256 ハッシュでログ出力 (生 endpoint は記録しない)

### 9.13.7 ポーリングと Web Push の独立性 (重複動作仕様)

POSLA 業務端末には**新着検知の独立した 2 系統**が同居する。両者の同期・重複排除は**サーバー側・クライアント側ともに行わない**（見逃し防止を優先）。

**系統A: 画面内ポーリング + AudioContext チャイム** (PWA なしでも動作)

| 画面 | 対象 | 実装 | interval (現物) |
|---|---|---|---|
| KDS `kds/index.html` | 注文一覧リフレッシュ | `setInterval(refresh, 2000)` (line 546) | 2 秒 |
| KDS / ハンディ 呼び出しアラート | `call-alerts.php` ポーリング + beep | `kds-alert.js:15` / `handy-alert.js:16` `POLL_INTERVAL = 5000` | 5 秒 |
| レジ `cashier-app.js:170` | 新規注文ポーリング | `_pollTimer = setInterval(_fetchOrders, 5000)` | 5 秒 |
| レジ 売上サマリー `cashier-app.js:332` | 集計更新 | `_salesPollTimer = setInterval(_fetchSalesSummary, 30000)` | 30 秒 |
| ハンディ `handy-app.js:513` | テーブル状態 | `_tablesPollTimer = setInterval(loadTableStatus, 5000)` | 5 秒 |
| 緊急レジ バッジ `emergency-register.js:37` | 未解決件数バッジ | `_badgeTimer = setInterval(_refreshBadge, 8000)` | 8 秒 |
| 品切れ `sold-out-renderer.js:106` | 品切れ一覧更新 | `setInterval(load, 30000)` | 30 秒 |
| バッジ更新 `kds/index.html:559` | アプリバッジ件数 | `setInterval(updateBadge, 5000)` | 5 秒 |

音源は Web Audio API (`AudioContext`) で beep を合成。ハンディは **モバイル自動再生規制回避のため明示的なユーザー操作でアンロック**が必須 (`handy-alert.js:39-76` `_setupAudioUnlock` / `_showAudioEnableBanner`)。KDS はタブレット常時表示想定でアンロック不要。

**系統B: Web Push (§9.13.3 の 5 種)** (PWA 化 + 通知許可時のみ)

SW の `push` handler → `showNotification` → OS 通知。`notificationclick` で scope 別の `/<scope>/` URL を開く。

**4 パターン挙動表:**

| 画面状態 | 通知許可 | ポーリング音 | Web Push 通知 | 備考 |
|---|---|---|---|---|
| フォアグラウンド | 許可 | ✅ 発火 | ✅ 発火 | **両方同時発火** (Android Chrome は foreground でも OS 通知表示)。POSLA 側重複排除なし |
| フォアグラウンド | 不許可 | ✅ 発火 | — | PWA 導入前と同等 (ポーリングのみ) |
| バックグラウンド / 画面閉 | 許可 | — (timer 停止) | ✅ 発火 | タブ非アクティブで polling 停止、Web Push のみ動作 |
| バックグラウンド / 画面閉 | 不許可 | — | — | 画面再開時にポーリングが溜まった状態を一括表示 |

**重複排除しない理由:** KDS の注文、呼び出しアラートは「確実に気付かせる」ことが業務上の primary goal。foreground でポーリング音と OS 通知が同時に鳴っても、スタッフにとっては**どちらかに気付ければ OK** というフェールセーフ設計。自動排除はバグで鳴らない方が業務影響大きい。

**ポーリングの再開:** `visibilitychange` ハンドラでバックグラウンド復帰時に即座に 1 回 fetch → 以降 `setInterval` 再開、という実装はしていない (タブ切替で `setInterval` は通常継続するが、一部ブラウザは background timer throttling あり)。業務上は画面を常時開いている前提で設計している。

## 9.14 PWA Phase 2b — Web Push 実送信 (2026-04-20)

Phase 2b のスコープ:

- VAPID JWT (ES256) 署名 + RFC 8291 aes128gcm ペイロード暗号化を **純 PHP で実装** (Composer / vendor 不使用)
- cURL でエンドポイントへ送信 + 410 Gone / 404 で購読を soft delete
- `push_send_to_store` / `push_send_to_roles` / `push_send_to_user` を Phase 2a スタブから実送信に置き換え
- `api/kds/call-alerts.php` (POST kitchen_call) と `api/customer/satisfaction-rating.php` (rating ≤ 2) に送信トリガー接続
- `api/push/test.php` (テスト送信エンドポイント、target=self / store) 追加
- VAPID 鍵生成 CLI スクリプト (`scripts/generate-vapid-keys.php`) 追加

Phase 2b 初期 (2026-04-20 (7)) では実装対象外だった項目 **→ 2026-04-20 (9) Phase 2b 拡張で対応済み**:

- ✅ POSLA 管理 UI (`public/posla-admin/`) での VAPID 情報表示 — **実装済み (§9.14.13)**。ただし鍵ローテーション・秘密鍵ダウンロード・テスト送信は意図的に UI 非搭載 (誤操作回避・独自認証系)
- ✅ `important_error` 自動トリガー — **実装済み (§9.14.12)**。`monitor-health.php` の Stripe Webhook 失敗を tenant 別集計 → 代表店舗の manager/owner に Push、5 分重複抑制
- ✅ レート制限 (1 ユーザー宛て連投ガード) — **実装済み (§9.14.11)**。`push_send_log` を SELECT して同 user+type+tag で 60 秒内 2xx 済みなら skip
- ✅ 送信ログテーブル (`push_send_log`) — **実装済み (§9.14.11)**。`sql/migration-pwa2b-push-log.sql` 適用済。PII 無し (title/body/endpoint 非保存) / 保持 90 日

Phase 2b でも引き続き **やらない**:

- cURL multi による並列送信 — 1 店舗あたりの購読数が少ない前提で逐次送信
- POSLA 運営 (posla_admins) 向け Web Push — 独自認証系で SW 非対応。運営通知は Google Chat / OP 側で受ける (Phase 2c 以降で Web Push 対応を検討)
- `reservation_notifications_log` 失敗 / `error_log` 閾値超過からの important_error — 将来拡張点 (§9.14.12 に拡張ポイント明記)

### 9.14.1 ファイル一覧 (Phase 2b 新規 / 修正)

**新規 5 ファイル:**

| ファイル | 役割 |
|---|---|
| `scripts/generate-vapid-keys.php` | VAPID 鍵生成 CLI。`openssl_pkey_new` で P-256 鍵ペアを生成し、Base64URL 公開鍵 + PEM 秘密鍵 + `posla_settings` 投入用 SQL を標準出力に書く |
| `api/lib/web-push-encrypt.php` | RFC 8291 aes128gcm 暗号化。HKDF-SHA256 + ECDH-P-256 (`openssl_pkey_derive`) + AES-128-GCM (`openssl_encrypt`) を組み合わせ、SW が受け取れる暗号化ボディを生成 |
| `api/lib/web-push-vapid.php` | VAPID JWT 署名 (ES256)。`openssl_sign` の DER 出力を JWS RFC 7515 §3.4 の raw 64 バイト (r:32 ‖ s:32) に変換。`Authorization: vapid t=<JWT>, k=<pub>` ヘッダを組み立てる |
| `api/lib/web-push-sender.php` | cURL POST ラッパ。201/200/202 を成功扱い、410/404 で `push_subscriptions.enabled=0 + revoked_at=NOW()`、429/5xx は一時失敗 (購読保持)、その他は失敗として返す |
| `api/push/test.php` | テスト送信エンドポイント。`target=self` (require_auth で誰でも) / `target=store` (manager / owner のみ) |

**修正 4 ファイル (Phase 2b 本体) + 4 HTML (レビュー指摘修正 / storeId 連携):**

| ファイル | 変更内容 |
|---|---|
| `api/lib/push.php` | Phase 2a no-op スタブを実送信に置換。`tenant_id` 条件追加でマルチテナント境界を二重化 (`_push_get_tenant_id_for_{store,user}()`)。`push_send_to_roles()` は owner NULL 購読を OR 条件で拾う (同 tenant の全店横断通知を希望している owner-dashboard 向け)。VAPID 未設定 / 例外は全て握りつぶし (fail-open) |
| `api/kds/call-alerts.php` | POST `kitchen_call` の INSERT 直後に `push_send_to_store($pdo, $storeId, 'call_alert', [...])` を呼ぶ。URL は `/handy/index.html` |
| `api/customer/satisfaction-rating.php` | INSERT/UPDATE 後に `_notify_low_rating_if_needed()` を呼び、`rating ≤ 2` のときだけ `push_send_to_roles(['manager','owner'], 'low_rating', [...])` を発火。URL は `/admin/dashboard.html` |
| `api/customer/call-staff.php` | POST スタッフ呼び出しの INSERT 直後に `push_send_to_store($pdo, $storeId, 'call_staff', [...])`。URL は `/handy/index.html`。顧客→スタッフ呼び出しは業務上の最重要通知のため、店舗内の全 enabled 購読 (handy / kds / cashier / pos-register) に届ける |
| `api/push/subscribe.php` | `scope=owner` は `role=owner` のみ許可 (非 owner が scope 詐称で NULL 購読を作れた権限漏れを塞ぐ)。`scope !== 'owner'` は owner ロールでも store_id 必須 |
| `public/shared/js/push-subscription-manager.js` | `setStoreId(id)` 公開メソッド追加 (後から storeId が確定したときに `subscribe.php` を再 POST して DB の store_id を UPSERT 更新)。`.repeat()` を `_padRight()` 手動ループに置換 (ES5 互換) |
| `public/kds/index.html` | KdsAuth.getStoreId() を 1 秒ポーリングして `PushSubscriptionManager.setStoreId()` に渡す |
| `public/handy/index.html` | `localStorage('mt_handy_store')` を 5 秒ポーリングして setStoreId (店舗切替に追従) |
| `public/admin/dashboard.html` | `getCurrentStoreId()` を 5 秒ポーリングして setStoreId |
| `public/admin/owner-dashboard.html` | owner-scope の NULL 購読ポリシーをコメント明記 |

**Phase 2b 拡張 (2026-04-20 追加):**

| ファイル | 種別 | 役割 |
|---|---|---|
| `sql/migration-pwa2b-push-log.sql` | 新規 | `push_send_log` テーブル (送信監査 + レート制限 + 重複抑制) |
| `api/posla/push-vapid.php` | 新規 | POSLA 管理画面 PWA/Push タブ用情報 API (GET、読み取り専用) |
| `api/lib/web-push-sender.php` | 修正 | `web_push_send_one/batch` に `type` / `tag` 引数追加 + 送信後に `push_send_log` へ INSERT |
| `api/lib/push.php` | 修正 | `_push_filter_subs_by_rate_limit()` 追加 (60 秒内 2xx 済みを除外) + SELECT に `tenant_id` / `user_id` / `store_id` 追加 (ログ記録用) |
| `api/cron/monitor-health.php` | 修正 | Stripe Webhook 失敗を tenant 別に集計 → `_pushImportantError()` で該当 tenant の manager/owner に Web Push (5 分重複抑制) |
| `public/posla-admin/dashboard.html` | 修正 | 「PWA/Push」タブ追加 (情報表示のみ) |
| `public/posla-admin/js/posla-app.js` | 修正 | `loadPushStatus()` 追加 (タブ切替時に API 呼び出し + 描画) |

### 9.14.2 暗号化フロー (RFC 8291 aes128gcm) 詳細

`web_push_encrypt_payload($payload, $p256dhB64u, $authB64u)` の処理:

1. **ephemeral 鍵生成** — `openssl_pkey_new` で P-256 (prime256v1) の一時鍵ペア (as_private, as_public) を作る。`openssl_pkey_get_details` の `ec.x` / `ec.y` を 32 バイトに左ゼロ詰めして `0x04 || X || Y` の 65 バイト uncompressed 公開鍵を組み立てる
2. **ECDH 共有秘密** — `openssl_pkey_derive(uaPubKey, ephPriv, 32)` で 32 バイトの IKM を導出。UA 側公開鍵は `subscription.keys.p256dh` の Base64URL を 65 バイトに decode し、SubjectPublicKeyInfo PEM (prefix `3059301306072a8648ce3d020106082a8648ce3d030107034200`) に変換してから `openssl_pkey_get_public` に渡す
3. **PRK_key** — `HKDF-Extract(salt=auth_secret, ikm=ECDH)` = `HMAC-SHA256(auth_secret, ikm)`
4. **IKM2** — `HKDF-Expand(PRK_key, "WebPush: info\x00" || ua_public(65) || as_public(65), 32)`
5. **salt** — `random_bytes(16)` で新規生成 (毎回ランダム)
6. **PRK** — `HKDF-Extract(salt, IKM2)`
7. **CEK** — `HKDF-Expand(PRK, "Content-Encoding: aes128gcm\x00", 16)` (16 バイト = AES-128 鍵長)
8. **NONCE** — `HKDF-Expand(PRK, "Content-Encoding: nonce\x00", 12)` (12 バイト = GCM IV 長)
9. **padding** — `payload || 0x02` (delimiter のみ。最大 record size 4096 内に収まる前提)
10. **AES-128-GCM** — `openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16)`。AAD は空、tag は 16 バイト
11. **body 構築** — `salt(16) || rs=4096(BE 4) || idlen=65(1) || keyid=as_public(65) || ciphertext+tag`

返り値は `['body' => binary, 'as_public_b64u' => Base64URL]`。

### 9.14.3 VAPID JWT 署名 (ES256) 詳細

`web_push_vapid_jwt($endpoint, $privatePem, $subject, $ttlSec=43200)` の処理:

- **header**: `{"typ":"JWT","alg":"ES256"}` を JSON → Base64URL
- **payload**: `{"aud":<origin>, "exp":<unix+ttl>, "sub":<mailto:...>}` を JSON → Base64URL
  - `aud` は endpoint URL を `parse_url` して `scheme://host[:port]` を組み立てる (path / query は含めない)
  - `exp` は最大 24 時間 (RFC 8292)、最小 60 秒 (内部でクランプ)。デフォルト 12 時間
  - `sub` は contact (mailto: または https:)。本番では `mailto:contact@posla.jp` を使用
- **signing input**: `headerB64 + "." + payloadB64`
- **署名**: `openssl_sign($input, $der, $privKey, OPENSSL_ALGO_SHA256)` で DER 形式の ECDSA 署名を取得
- **DER → raw 変換**: PHP の `openssl_sign` は ASN.1 DER (`SEQUENCE { INTEGER r, INTEGER s }`) を返すが、JWS RFC 7515 §3.4 は raw 64 バイト (`r:32 ‖ s:32`) を要求。`_vapid_der_to_raw_es256()` で SEQUENCE / INTEGER タグを読み飛ばし、先頭の符号ビット用 `0x00` を `ltrim` して 32 バイト固定にゼロ詰めする
- **戻り値**: `headerB64 + "." + payloadB64 + "." + sigB64`

`web_push_vapid_auth_header()` は上記 JWT に加え `Authorization: vapid t=<JWT>, k=<publicKeyB64u>` の文字列を返す。

### 9.14.4 送信フロー (`web_push_send_one`)

| 段階 | 処理 |
|---|---|
| 1. 入力検証 | `endpoint / p256dh / auth_key` のいずれか欠落で `invalid_subscription` を返す |
| 2. 暗号化 | `web_push_encrypt_payload()` で aes128gcm ボディ生成 |
| 3. VAPID | `web_push_vapid_auth_header()` で Authorization 文字列生成 |
| 4. POST | cURL で endpoint に POST。HTTP ヘッダ: `Authorization` / `Content-Type: application/octet-stream` / `Content-Encoding: aes128gcm` / `TTL: 86400` / `Urgency: normal` / `Content-Length` |
| 5. ステータス判定 | 2xx=ok / 410,404=`gone_disabled` (DB soft delete) / 413=`payload_too_large` / 429,5xx=`transient_failure` / その他=`http_error` |

**fail-open 設計**: `web_push_send_one` は `Throwable` を内部でキャッチして `['ok'=>false, 'reason'=>...]` を返す。`push_send_to_*` も例外をキャッチして 0 を返す。業務 API (call-alerts / satisfaction-rating) はさらに `try/catch` で wrap。三重防御で「通知が飛ばないだけで、注文・呼び出し・評価そのものは絶対に通る」を保証。

### 9.14.5 送信トリガー一覧 (Phase 2b 接続済み)

| type | トリガー API | 送信先 SQL 条件 | payload |
|---|---|---|---|
| `call_staff` | `POST /api/customer/call-staff.php` (顧客セルフメニューからのスタッフ呼び出し INSERT 直後) | `tenant_id = ? AND store_id = ? AND enabled = 1` (店舗内全員) | `{title:'お客様呼び出し: テーブル <code>', body:<reason>, url:'/handy/index.html', tag:'call_staff_<tableCode>'}` |
| `call_alert` | `POST /api/kds/call-alerts.php` (kitchen_call INSERT 直後) | `tenant_id = ? AND store_id = ? AND enabled = 1` | `{title:'キッチンから呼び出し', body:<reason>, url:'/handy/index.html', tag:'kitchen_call_<alertId>'}` |
| `low_rating` | `POST /api/customer/satisfaction-rating.php` (rating ≤ 2 で INSERT/UPDATE 後) | `tenant_id = ? AND enabled = 1 AND ( (store_id = ? AND role IN ('manager','owner')) OR (store_id IS NULL AND role='owner' AND scope='owner') )` — **owner NULL 購読 (owner-dashboard) も同 tenant の全店横断通知として拾う** | `{title:'満足度: 低評価', body:'★<n> 低評価が入りました / <品目> / <理由>', url:'/admin/dashboard.html', tag:'low_rating_<ratingId>'}` |
| `push_test` | `POST /api/push/test.php` | target=self: `user_id = ? AND tenant_id = ?`<br>target=store: `store_id = ? AND tenant_id = ?` (manager+ のみ) | `{title:'POSLA テスト通知', body:'これはテスト通知です。届いていれば設定 OK です。'}` |

**`push_send_to_roles` の owner NULL 拾いルール** (Phase 2b 修正 #2):
- owner-dashboard は `store_id=NULL` で購読保存する (= 全店横断通知希望)
- `push_send_to_roles` は `$roles` に `'owner'` が含まれるときだけ、同 tenant で `store_id IS NULL AND role='owner' AND scope='owner'` の購読も OR 条件で追加取得
- 結果: 店舗 X で低評価が起きたとき、店舗 X の owner 購読 + owner-dashboard で全店通知に入ったオーナー両方に届く
- call_alert / call_staff は `push_send_to_store` なので owner NULL は拾わない (店舗内スタッフ向けの運用通知であり、オーナーが全店の個別呼び出しを受けるのは過剰)

`important_error` は Phase 2b では未接続 (将来追加用に push.php 関数は揃っている)。

### 9.14.6 コンテナ配備手順

PWA / Web Push の本番配備は、**個別ファイルを SCP するのではなく Compose 構成ごと反映**するのが正本です。

#### 手順 1: env と DB の事前確認

1. `docker/env/app.env` の `POSLA_APP_BASE_URL` が `https://meal.posla.jp` になっていること
2. `docker/env/app.env` の `POSLA_CRON_SECRET` が設定されていること
3. `posla_settings` に `web_push_vapid_public` / `web_push_vapid_private_pem` があること
4. 既存購読を維持したい場合は **鍵を再生成しない**こと

#### 手順 2: 配備

```bash
docker compose config
docker compose up -d --build
```

#### 手順 3: VAPID 状態確認

POSLA 管理画面の `PWA / Web Push` タブで以下を確認します。

- 公開鍵あり
- 秘密鍵あり
- 購読件数が取得できる
- 24h 送信統計が取得できる

または API 直接確認:

```bash
curl -s http://127.0.0.1:8081/api/posla/push-vapid.php
```

#### 手順 4: 鍵生成・適用が必要な場合

現行実装では **POSLA 管理画面の `PWA / Web Push` タブから生成・適用・取得まで完結**できます。
ただし、**再生成すると既存購読は原則再許可が必要**になるため、既存運用中のテナントがいる環境では実施前に告知してください。

#### 手順 5: キャッシュ確認

PWA インストール済み端末は SW / Cache Storage に古い manifest や公開鍵を保持していることがあるため、必要に応じて:

1. PWA を開く
2. 画面の更新導線またはブラウザ再読込を行う
3. 通知設定を再確認する

#### 手順 6: 動作確認 (テスト送信)

任意の端末でログイン後、ブラウザ DevTools コンソールで:

```javascript
fetch('/api/push/test.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({target: 'self'})
}).then(r => r.json()).then(console.log);
```

期待結果:

| レスポンス | 意味 | 対処 |
|---|---|---|
| `{sent:1, available:true}` | OK。OS 通知が表示されればフルチェイン成功 | — |
| `{sent:0, available:true}` | VAPID OK だが購読 0 件。当該ユーザーが通知許可していない | 手順 7 を実施 |
| `{sent:0, available:false}` | VAPID 未設定 | 手順 5 を実施 |
| `{ok:false, error:UNAUTHORIZED}` | 未ログイン | ログインして再試行 |
| `{sent:1, available:true}` だが通知が出ない | サブスクリプションは生きてるが OS 通知が抑止されている (ブラウザ通知許可解除 / OS のサイレントモード等) | OS 設定確認 |

`target=store` テスト (manager / owner のみ):

```javascript
fetch('/api/push/test.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({target: 'store', store_id: '<UUID>'})
}).then(r => r.json()).then(console.log);
```

#### 手順 9: 業務トリガー実機確認

| シナリオ | 操作 | 期待結果 |
|---|---|---|
| call_alert | KDS 画面の「呼び出し」ボタンを押す | 同 store の購読端末 (handy / KDS / レジ) に「キッチンから呼び出し」通知が届く |
| low_rating | 顧客側セルフメニューで品目評価 ★1 を送信 | 同 store の manager / owner 購読端末に「★1 低評価が入りました」通知 |
| 高評価で送信なし | ★3〜★5 を送信 | 通知が来ないこと (`rating ≤ 2` のみトリガー) |

### 9.14.7 ロールバック手順

問題発生時は、**コード・env・DB をそれぞれ元のスナップショットへ戻す**のが原則です。

```bash
docker compose down
# コード or env をロールバック
docker compose up -d --build
```

VAPID 鍵は `posla_settings` に残しておけば、アプリコードだけを戻しても再配備時に再投入不要です。

::: tip VAPID 鍵を消さない理由
鍵を消す = ブラウザ側の購読が無効化される。push.php だけロールバックする限り「通知が静かに飛ばないだけ」で、業務 API は通る (fail-open 設計)。鍵は残しておけば次回 Phase 2b 再デプロイ時に手順 5 をスキップできる。
:::

### 9.14.8 鍵管理運用ルール (恒久)

- **公開鍵 (`web_push_vapid_public`)** はフロントへ `GET /api/push/config.php` で返す。露出 OK
- **秘密鍵 (`web_push_vapid_private_pem`)** は **絶対にフロントへ返さない**
  - `api/posla/settings.php` の `_public_setting_keys()` 許可リストに **追加禁止**
  - サーバーログにも出力しない
  - Git に commit しない (バックアップは社内パスワード金庫のみ)
  - POSLA 管理画面 `api/posla/push-vapid.php` も秘密鍵本体を返さず、`private_pem_set` (bool) と `private_pem_length` (文字数) のみ返す
  - **秘密鍵の保管済み記録 (2026-04-20)**: 生成直後に CLI 出力 `/tmp/vapid-output.txt` をローカル `~/Desktop/posla-secrets/vapid-backup-20260420.txt` (chmod 600) にダウンロード済み。リモートの `/tmp/vapid-*.txt` `/tmp/run-vapid.sh` 等の一時ファイルは `rm` でクリーンアップ済み。ユーザーが社内パスワード金庫 (1Password / Bitwarden) に手動で移すこと。以降の再生成は §9.14.8 ローテーション手順に従う
- **鍵ローテーションは原則しない**。生成は 1 回。再生成すると全購読が無効化される
- 強制ローテーションが必要な場合 (秘密鍵漏洩等) の手順:
  1. テナント全テナントへ「全業務端末で通知再設定が必要」と事前告知
  2. 手順 5 を再実行 (ON DUPLICATE KEY UPDATE で上書き)
  3. `UPDATE push_subscriptions SET enabled=0, revoked_at=NOW()` で全購読を一括無効化
  4. 各端末でキャッシュリセット → 再購読

### 9.14.9 トラブルシューティング (Phase 2b 固有)

| 症状 | 原因候補 | 確認方法 / 対処 |
|---|---|---|
| `test.php` が `available:false` を返す | VAPID 鍵未投入 | 手順 4 で確認、未投入なら手順 5 |
| `test.php` が `sent:0, available:true` | 購読 0 件 (端末で許可してない) | `SELECT COUNT(*) FROM push_subscriptions WHERE user_id=? AND enabled=1` |
| `sent:1` でも通知が来ない | (a) ブラウザの通知許可が後から OFF (b) OS のサイレントモード (c) SW が古いまま (Phase 2a の no-op SW) | (c) はキャッシュリセット → SW 更新で解決 |
| 410 Gone でいきなり購読が消える | エンドポイント側 (FCM 等) が購読を破棄 | 期待動作。`enabled=0 + revoked_at` を確認。再購読すれば復活 |
| `encrypt_or_sign_failed` がログに出る | (a) `p256dh` の長さが 65 バイトでない (壊れた購読) (b) 秘密鍵 PEM 不正 | (a) 該当 subscription を `enabled=0` に。(b) 手順 4 で `web_push_vapid_private_pem` の長さ確認。241 文字想定 |
| `transient_failure` (5xx / 429) が頻発 | エンドポイント側の一時障害 | 1 時間後に再試行で改善するのが通常。続く場合は FCM ステータスを確認 |
| Authorization 401/403 | (a) JWT 期限切れ (12h 超) (b) `aud` ホスト不一致 (c) `sub` 不正 | (a) JWT は 12h 毎に毎送信で再生成しているので通常起きない。(b) endpoint の origin と JWT aud を比較 |
| 特定店舗だけ通知が届かない | 対象端末の購読が `store_id = NULL` のまま | `SELECT id, role, scope, store_id FROM push_subscriptions WHERE user_id=? AND enabled=1` で確認。NULL なら端末で画面をリロード → setStoreId ポーリングが走り、subscribe.php が UPSERT して store_id が入る |
| subscribe.php が 400 STORE_REQUIRED を返す | handy / kds / admin で storeId が画面初期化時に未確定のまま subscribe 実行 | `PushSubscriptionManager.setStoreId()` のポーリングが storeId 確定前に requestPermission を押すと発生。KdsAuth / HandyApp / StoreSelector が storeId を確定するまで通知許可ボタンを押さない。あるいは初回 subscribe を setStoreId 後に遅延させる (現状は setTimeout ポーリングで自動再送信) |
| subscribe.php が 403 FORBIDDEN_SCOPE を返す | manager / staff / device が scope=owner で購読しようとした (通常ありえない) | フロント側の scope 指定を見直す (dashboard.html=admin, owner-dashboard.html=owner, kds=kds, handy=handy)。悪意ある改ざんなら本検証で弾けている |
| オーナーが全店の低評価通知を受けられない | owner-dashboard で通知許可していない or `store_id IS NULL` の購読になっていない | (a) owner-dashboard の通知設定パネルで許可済みか確認。(b) DB で `SELECT * FROM push_subscriptions WHERE role='owner' AND scope='owner' AND store_id IS NULL AND enabled=1` で該当行があるか |

### 9.14.10 Phase 2b 実装上の注意点 (将来の自分への申送り)

- **csh / 多重クォート**: リモートシェルが csh のため、`ssh ... "bash -c '...'"` の中で `'...'` 内に改行や `>` を多用すると Ambiguous output redirect が出ることがある。**SQL や複数行スクリプトはファイル化して `bash /tmp/...sh` で呼ぶ** のが確実 (手順 5 がそのパターン)
- **`mysql -e "..."` のクォート**: `LIKE '%...%'` を多重 SSH 越しに渡すとエスケープが破綻しやすい。SQL ファイルを SCP して `mysql < file.sql` の方が安全 (手順 4 がそのパターン)
- **DER → raw 変換**: `openssl_sign` の DER は `INTEGER` の前に符号ビット用 `0x00` が付くことがあるので、`ltrim($r, "\x00")` してから 32 バイトに左ゼロ詰めする。逆に DER の長さ (`SEQUENCE` 全体長) は短形式 / 長形式の両対応が必要 (`_vapid_der_to_raw_es256()` で実装済み)
- **fail-open の徹底**: 業務 API (`call-alerts.php` / `satisfaction-rating.php`) では push 送信を必ず `try { ... } catch (\Throwable $e) { /* swallow */ }` で wrap。push 内部で例外が出ても業務処理を絶対に止めない。これは Web Push 仕様上の不確実性 (FCM 側のレート / 一時障害 / endpoint 形式変更) を業務処理から隔離するための設計
- **JWT 有効期限**: RFC 8292 で最大 24h。デフォルト 12h で運用中。`web_push_vapid_jwt()` が毎送信ごとに新規生成するためキャッシュ不要 (キャッシュすると複数 endpoint の `aud` を間違える)
- **AAD = 空**: AES-128-GCM の Additional Authenticated Data は空文字列 `''` を使う (RFC 8291)。`openssl_encrypt` の第 7 引数を `''` で渡すこと
- **storeId は購読保存時に必須**: push_send_to_store の WHERE は `store_id = ?` で絞るため、store_id=NULL の購読は店舗通知 (`call_alert` / `call_staff` / `low_rating` の店舗分) に一切乗らない。KDS / handy / admin(manager/staff) は `PushSubscriptionManager.setStoreId()` を使って storeId 確定後に subscribe.php を再 POST する (内部の UPSERT で DB の store_id を上書き)。KDS は `KdsAuth.getStoreId()` を 1 秒ポーリング、handy は `localStorage('mt_handy_store')` を 5 秒ポーリング、admin は `getCurrentStoreId()` を 5 秒ポーリングで追従
- **scope=owner は role=owner 限定**: subscribe.php は `scope === 'owner' && user.role !== 'owner'` を 403 FORBIDDEN_SCOPE で弾く。旧実装では非 owner が scope=owner で NULL 購読を作れる権限漏れがあった。store_id=NULL OK は `scope === 'owner'` のときだけ (= role=owner 確定)
- **owner NULL 購読の扱い**: `push_send_to_store` は NULL を拾わず、`push_send_to_roles` のみが OR 条件で NULL を拾う。owner-dashboard の「全店通知を受けたい owner」と、特定店舗に張り付く owner (store_id 指定で subscribe) の両方を同時に成立させる設計
- **マルチテナント境界の二重化**: push_send_to_{store,roles,user} の全クエリに `tenant_id = ?` を明示。store_id / user_id は UUID で全体一意だが、POSLA 原則に従い明示すべき。呼び出し側は tenant_id を引数で受けない設計 (ユーティリティ関数 `_push_get_tenant_id_for_{store,user}()` で DB 解決) のため、業務 API の呼び出し箇所は書き換え不要
- **通知 URL は runtime path を使う**: 現行は `/admin/` `/kds/` `/handy/` 配下のみを許可します。payload の URL も runtime path に合わせます。
- **ES5 互換の罠 (.repeat / .padStart / .includes / .find / arrow / const/let / template literal)**: 業務端末の対象ブラウザは Android Chrome 最新版でサポートされるが、POSLA 方針として `var + コールバック + ES5 組込関数のみ` を維持。`String.prototype.repeat()` は避け、`while (str.length < n) str += c;` の手動ループか小さな `_padRight(str, len, char)` ヘルパを使う。新規 JS を書く前に古いコードを grep して既存ヘルパを探すこと

## 9.14.11 送信ログ + レート制限 (Phase 2b 拡張 / 2026-04-20)

### テーブル設計 (`push_send_log`)

SQL: `sql/migration-pwa2b-push-log.sql`

| カラム | 型 | 用途 |
|---|---|---|
| `id` | VARCHAR(36) PK | 内部 ID (`bin2hex(random_bytes(18))`) |
| `tenant_id` | VARCHAR(36) NULL | テナント境界 (subscription から取得) |
| `store_id` | VARCHAR(36) NULL | 店舗紐付け |
| `subscription_id` | VARCHAR(36) NULL | `push_subscriptions.id` (購読が物理削除されても NULL で残す) |
| `user_id` | VARCHAR(36) NULL | 購読者 (レート制限判定のキー) |
| `type` | VARCHAR(40) | `call_staff` / `call_alert` / `low_rating` / `important_error` / `push_test` |
| `tag` | VARCHAR(100) NULL | 固定ラベル (`kitchen_call` / `low_rating` / `call_staff_<table>` 等)。PII は入れない |
| `status_code` | INT NULL | HTTP 2xx=成功 / 410,404=gone / 429,5xx=一時失敗 / 0=送信前失敗 |
| `reason` | VARCHAR(60) NULL | `sent` / `gone_disabled` / `transient_failure` / `encrypt_or_sign_failed` 等 |
| `sent_at` | DATETIME | `CURRENT_TIMESTAMP` |

**Index:**
- `idx_tenant_sent (tenant_id, sent_at)` — tenant 別の送信履歴参照
- `idx_user_type_tag_sent (user_id, type, tag, sent_at)` — レート制限 SELECT 用
- `idx_type_tenant_sent (type, tenant_id, sent_at)` — important_error の重複抑制 SELECT 用
- `idx_sent_at (sent_at)` — 90 日保持用 cron DELETE 用

### PII 方針

- **title / body / url は保存しない**。個人特定 (店舗名・顧客コメント等) を残さないため
- `tag` は固定ラベルのみなので保存 OK
- 送信先 endpoint は保存しない (既に subscription_id で紐付くだけで十分)

### 保持期間

- 90 日 (error_log / user_sessions と同等)
- `DELETE FROM push_send_log WHERE sent_at < DATE_SUB(NOW(), INTERVAL 90 DAY)` を月次 cron で実行推奨
- 当面は `api/cron/` 系 cron には組み込まず、MySQL event か手動削除で対応

### INSERT タイミング

- `web_push_send_one()` 内で、結果確定直後 (成功/失敗問わず)
- 送信前に失敗した場合 (`invalid_subscription` / `encrypt_or_sign_failed` / `curl_error`) でも INSERT する → 失敗原因の記録
- `push_send_log` テーブル未作成環境 (migration 未適用時) でも `try/catch` で握りつぶし、業務 API を巻き込まない (fail-open)

### レート制限ロジック (`_push_filter_subs_by_rate_limit`)

`api/lib/push.php` の `_push_send_to_subscriptions` 内で送信前に呼ぶ:

```sql
SELECT DISTINCT user_id
  FROM push_send_log
 WHERE type = ? AND tag = ?
   AND status_code >= 200 AND status_code < 300
   AND sent_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
   AND user_id IN (?, ?, ...)
```

- 該当 user は購読配列から除外
- **`tag` が空文字 / null の場合はレート制限しない** (テスト送信等で全件通す)
- 判定単位は `user_id`。同じユーザーが複数端末を購読していても 1 ユーザー扱い (無駄な多重送信防止)
- `push_send_log` テーブル未作成環境でも例外をキャッチして全件通す (fail-open)

#### tag の粒度設計 (Phase 2b レビュー指摘 #3 対応)

レート制限は `type + tag` で一意判定するため、tag に識別子を含めないと
「別イベントだが同じ tag」のせいで 2 通目以降が抑制されてしまう。そのため各
トリガーは以下のように **イベント固有 ID** を tag に埋めている:

| type | tag 例 | 意図 |
|---|---|---|
| `call_staff` | `call_staff_<tableCode>` | 同じテーブルからの連打は 60 秒抑制 (妥当)。別テーブルの呼び出しは即通知 |
| `call_alert` | `kitchen_call_<alertId>` | 同じ alert を 60 秒内に再 POST しても 1 通のみ。別 alert は即通知 |
| `low_rating` | `low_rating_<ratingId>` | 同じ評価 ID の UPDATE は抑制。別品目/別 order の低評価は即通知 |
| `important_error` | `<type>` (例: `stripe_webhook_fail`) | **意図的に固定 tag**。同 tenant+同種エラーの連続発火を 5 分抑制 (スパム防止) |
| `push_test` | `push_test` | テスト用。連打テストをしたい場合は tag を変えて呼ぶ |

**設計原則**: 「同じイベントの 2 重発火は抑制」「別イベントは即通知」を両立する
ため、連打防止したい粒度 (テーブル / alert / rating / エラー種別) の ID を tag
に含める。`kitchen_call` や `low_rating` のような**固定 tag は使わない**。

### 運用確認

- 24 時間統計: POSLA 管理画面「PWA/Push」タブで確認 (`api/posla/push-vapid.php` 経由)
- 個別送信トレース: `SELECT * FROM push_send_log WHERE user_id = ? ORDER BY sent_at DESC LIMIT 20`
- gone_disabled が多発する端末: `SELECT subscription_id, COUNT(*) FROM push_send_log WHERE status_code IN (404,410) GROUP BY subscription_id ORDER BY 2 DESC` で洗い出し

## 9.14.12 important_error 自動トリガー (Phase 2b 拡張 / 2026-04-20)

### 発火元

`api/cron/monitor-health.php` (5 分毎 cron)。

Phase 2b の初期接続は **Stripe Webhook 失敗** のみ。将来拡張で以下を追加可能:

- `reservation_notifications_log.status = 'failed'` → tenant/store を引いて発火
- `error_log` の閾値超過 (同 errorNo が 5 分で 20 件超 / 5xx が 3 件超) → tenant 特定できるものは発火

### 実装

`_pushImportantError($pdo, $tenantId, $type, $title, $detail)` ヘルパ:

1. **重複抑制**: `push_send_log` で直近 5 分に `tenant_id = ? AND type = 'important_error' AND tag = ?` で 2xx 済みならスキップ
2. **代表店舗取得**: `SELECT id FROM stores WHERE tenant_id = ? AND is_active = 1 ORDER BY created_at ASC LIMIT 1`
3. **送信**: `push_send_to_roles($pdo, $storeId, ['manager','owner'], 'important_error', ...)`
   - `push_send_to_roles` の OR 条件により owner-dashboard の store_id=NULL 購読も拾う
4. **fail-open**: `Throwable` をキャッチして `error_log` に記録、cron 自体は止めない

### 送信先の整理

| 対象 | 通知手段 |
|---|---|
| 該当テナントの manager / owner (店舗紐付け購読) | Web Push (important_error) |
| 該当テナントの owner (owner-dashboard NULL 購読) | Web Push (push_send_to_roles OR 拾い) |
| POSLA 運営 (POSLA運営窓口（support@posla.jp）等) | Google Chat / OP 通知 + 連続 3 件超で `ops_notify_email` |
| POSLA 管理者 (posla_admins 独自認証) | **対象外** (独自認証系で SW 非対応) |

POSLA 運営向けの Web Push は Phase 2c 以降で検討 (別テーブル `posla_push_subscriptions` + `posla-admin/` 配下 SW が必要になり影響範囲が広い)。

### スパム防止

- 同 tenant + 同 type で 5 分以内に送信済みなら skip
- これにより「Stripe が 5 分連続で失敗し続けてる」ケースでも Push は 5 分に 1 回
- Google Chat / OP 通知側の頻度制限は OP の alert/case ルールに従う

## 9.14.13 POSLA 管理画面 PWA/Push タブ (Phase 2b 拡張 / 2026-04-20)

### 配置

- `public/posla-admin/dashboard.html` に `data-tab="pwa-push"` の新タブ追加
- パネル `#posla-panel-pwa-push` 内に 3 セクション: VAPID 状態 / 購読統計 / 直近 24h 送信統計
- タブ切替で `loadPushStatus()` が `GET /api/posla/push-vapid.php` を呼んで表示

### 返却データ (`api/posla/push-vapid.php`)

```json
{
  "vapid": {
    "public_key": "BFRFB0...",
    "public_key_length": 87,
    "private_pem_set": true,
    "private_pem_length": 241,
    "available": true
  },
  "subscriptions": {
    "total": 12,
    "enabled": 10,
    "tenants_with_subs": 3,
    "by_role": { "owner": 2, "manager": 3, "staff": 4, "device": 1 }
  },
  "recent_24h": {
    "total": 45, "sent_ok": 40, "gone_disabled": 2, "transient": 1, "other_error": 2,
    "by_type": { "call_staff": 20, "call_alert": 8, "low_rating": 5, "push_test": 12 }
  }
}
```

### 意図的に実装しない機能

| 機能 | 実装しない理由 |
|---|---|
| 秘密鍵ダウンロード | CLI 出力を金庫運用する方針を崩さない。UI からの誤操作 (スクショ / コピペ流出) を避ける |
| 鍵ローテーション | 再生成 = 全購読無効化 = 全端末で再設定。UI ボタン一発で起動する動作は危険すぎる。§9.14.8 の CLI + SQL 手順で厳密管理 |
| テスト送信 | POSLA 管理者は `posla_admins` 独自認証系で `users` テーブル外 → SW 非対応で自分宛テストが成立しない |

### 権限

- `GET /api/posla/push-vapid.php` は `require_posla_admin()` で POSLA 管理者認証必須
- 秘密鍵本体は API レスポンスに含めない (`private_pem_set` と `private_pem_length` のみ)

## 9.15 PWA Phase 3 — 通信不安定時の表示耐性 (2026-04-20)

### 9.15.0 目的とスコープ

KDS / レジ / ハンディ で **通信が一時的に切れたとき** の以下を解決する:

1. 画面が真っ白にならない
2. 最後に正常取得できた業務データを **read-only の参考表示** として残す
3. 「通信断」「古いデータ」「復帰」を明確に表示する
4. 通信復帰時に安全に再取得する

**Phase 3 が解決しないこと (意図的設計):**

- オフライン中の **注文送信 / 会計 / 決済 / 返金 / 領収書発行 / 状態変更** はキューしない (実行しない)
- API レスポンスを Service Worker Cache に保存しない
- 顧客セルフメニュー (`public/customer/`) は対象外 (1 回限りの利用フロー)

### 9.15.1 ファイル一覧

**新規 2 ファイル:**

| ファイル | 役割 |
|---|---|
| `public/shared/js/offline-snapshot.js` | localStorage ベースの snapshot ヘルパ。`save / load / clear / clearScope / formatSavedAt`。TTL 12h、1.5MB 超は保存スキップ、quota 例外握りつぶし、顧客パス early return |
| `public/shared/js/offline-state-banner.js` | stale / 復帰バナー。既存 `OfflineDetector` の online 復帰イベントを購読 + 各画面が明示的に `markStale / markFresh / showFetchError` を呼ぶ。再取得ボタン (`onRetry` callback) |

**修正 11 ファイル:**

| ファイル | 変更内容 |
|---|---|
| `public/kds/js/polling-data-source.js` | `_doPoll` 成功時に `OfflineSnapshot.save('kds', storeId, 'orders', data)` + `markFresh`。失敗+初回のみ snapshot 復元 + `markStale`。失敗+既存表示ありなら `markStale` のみ。`resetStaleState()` 公開 (復帰時に呼ぶ) |
| `public/kds/js/kds-renderer.js` | `render()` 末尾で `_applyStaleReadonlyIfNeeded()` を呼び、`OfflineStateBanner.isStale()` が true なら `.kds-card__action` / `.kds-card__cancel` / `.kds-item-action` を全部 disabled + tooltip |
| `public/kds/index.html` | `offline-snapshot.js` / `offline-state-banner.js` を `?v=20260420-pwa3` でロード + `OfflineStateBanner.init({ scope:'kds', onRetry: PollingDataSource.resetStaleState })`。polling-data-source / kds-renderer / kds-alert もキャッシュバスター更新 |
| `public/kds/js/kds-alert.js` | `_doPoll` 成功時に snapshot 保存 (`call_alerts` channel)。失敗+未描画時のみ snapshot 復元 + `_renderAlerts(alerts, true)` で stale ヘッダ追加 + 対応済みボタン非表示。`_acknowledgeAlert` 冒頭でオフラインガード (alert 出してリターン) |
| `public/handy/js/handy-alert.js` | 同上 (handy 版) |
| `public/handy/js/handy-app.js` | `loadMenu / loadTables` を成功時 snapshot 保存、失敗+未描画時のみ snapshot 復元。`_applyMenuData / _applyTablesData` を内部関数化して再利用。`submitOrder` 冒頭でオフラインガード (toast 出してリターン、キューしない) |
| `public/handy/index.html` | snapshot/state-banner ロード + `OfflineStateBanner.init({ scope:'handy' })` (再取得は location.reload) |
| `public/handy/js/pos-register.js` | `loadTables / loadTakeoutOrders / loadOrders` 成功時 snapshot、失敗+未描画時のみ snapshot 復元。`processPayment` 冒頭でオフラインガード |
| `public/handy/pos-register.html` | offline-detector + snapshot/state-banner をロード (元々 offline-detector が無かったため新規追加) |
| `public/kds/js/cashier-app.js` | `_fetchOrders` 成功時に `orders + sessions` を snapshot 保存、失敗+未描画時のみ復元。`_submitPayment` / `_issueReceipt` / `_executeRefund` (`_confirmRefund`) / `_postCashLog` / `_processTerminalPayment` の冒頭でオフラインガード (toast 出してリターン) |
| `public/kds/cashier.html` | snapshot/state-banner を cashier-app.js の前にロード (cashier-app.js が初回 fetch で OfflineSnapshot を参照するため) |

### 9.15.2 設計詳細

#### snapshot 保存先 / 形式

- **localStorage 一択**。SW Cache / IndexedDB / Cookie は使わない
- key: `posla_snapshot::<scope>::<storeId>::<channel>` (tenant 混在防止)
- value (JSON): `{ scope, storeId, channel, savedAt(ms), data }`
- scope: `kds` / `handy` / `pos-register` / `cashier`
- channel 命名 (現状):
  - `kds::<storeId>::orders` — KDS 注文一覧 (`api/kds/orders.php`)
  - `kds::<storeId>::call_alerts` — 呼び出しアラート
  - `handy::<storeId>::menu` — ハンディ用メニュー
  - `handy::<storeId>::tables` — ハンディ用テーブル一覧
  - `handy::<storeId>::call_alerts`
  - `pos-register::<storeId>::tables-status`
  - `pos-register::<storeId>::takeout-orders`
  - `pos-register::<storeId>::table-orders-<tableId>`
  - `cashier::<storeId>::orders-sessions`

#### 保存ポリシー

- TTL: **12 時間**。期限超過した snapshot は load 時に自動削除して null 返却
- サイズ上限: **1.5MB** (JSON.stringify 後)。超えたら save 諦め (業務 API は止めない)
- quota 例外: try/catch で握りつぶし、save が false 返すだけ
- storeId が空の場合: 保存しない (tenant 混在のリスク回避)
- 顧客パス (`/customer/`) からの呼び出し: early return

#### 表示ポリシー

- 通信エラー (一時): 黄色バナーで `通信が不安定です。最新情報ではない可能性があります。` を 4 秒
- 古いデータ表示中: 黄色バナーで `[最終取得: HH:mm。] 古いデータを表示中です。操作は通信復帰後に行ってください。` を手動 hide まで常時 + 「再取得」ボタン
- 通信復帰: 緑バナーで `通信が復帰しました。最新情報を取得しています。` を 3 秒
- 既存 `#offline-banner` (赤、`offline-detector.js`) とは別 DOM (`#offline-state-banner`) で共存。z-index は赤 (9999) > 黄/緑 (9998)

#### オフライン中のボタン挙動 (レビュー指摘 #1/#3 対応後)

**共通ガード**: `OfflineStateBanner.isOfflineOrStale()` が真を返したら、**関数側でローカル状態変更も API 呼び出しも両方止める**。DOM disabled だけでは voice-commander など直接関数を叩く経路で防げないため。

| 画面/関数 | 具体的ガード |
|---|---|
| KDS `KdsRenderer.handleAction / handleItemAction / advancePhase` | 関数冒頭で `_guardOfflineOrStale()` → true なら `Promise.resolve({ok:false, error:'offline_or_stale'})` を返し、楽観更新すらしない |
| KDS `render()` 後 | stale なら action ボタンを disabled + tooltip + opacity 0.5 (視覚補助。真のガードは関数側) |
| KdsAlert / HandyAlert `_acknowledgeAlert` | 冒頭で `isOfflineOrStale` チェック、window.alert + ボタン状態リセット |
| KdsAlert / HandyAlert render | stale 時は対応済みボタンを **DOM ごと出さない** (二重防御) |
| HandyApp (IIFE 内 `_guardOfflineOrStale()`) で 8 箇所ガード | `submitOrder` / `seatReservationFromHandy` / `markReservationNoShow` / `openTableForCustomer` / 着席モーダル click / メモ保存 click / `cancelSession` / サブセッション発行 click |
| CashierApp | `_submitPayment / _issueReceipt / _confirmRefund / _postCashLog / _processTerminalPayment` 各冒頭で `OfflineStateBanner.isOfflineOrStale()` 判定 |
| CashierApp `_submitPayment` | `_fetchOrders({ failClosed: true })` → 失敗時 toast で中断 (fail-closed)。古いデータで会計続行しない (レビュー指摘 #2 対応) |
| PosRegister `processPayment` | 冒頭で `isOfflineOrStale` チェック |

#### `_fetchOrders({ failClosed })` の意味 (cashier-app.js)

- `failClosed: false` (デフォルト) — ポーリング用途。失敗時は snapshot 復元 + stale バナー表示、Promise は resolve する (既存挙動)
- `failClosed: true` — 会計前強制リフレッシュ用途。失敗時は snapshot 復元はするが Promise を **reject** する。呼び出し側 (`_submitPayment`) が catch で toast 出して会計を中断する

#### KdsRenderer の戻り値は reject (voice-commander との整合 / レビュー指摘 2026-04-20)

- `handleAction` / `handleItemAction` / `advancePhase` は `_guardOfflineOrStale` が true のとき **`Promise.reject(new Error('offline_or_stale'))`** を返す。
  - 旧実装は `Promise.resolve({ok:false})` を返していたが、voice-commander の `.then(function(){ _beepSuccess(); })` が戻り値を見ずに「成功」として音と ✓ を出す誤作動があった
- voice-commander 側も二重防御として:
  - `_voiceIsOfflineOrStale()` で 4 経路 (`_executeStatusUpdate` / `_executeItemStatusUpdate` / `_executeStaffCall` / `_executeSoldOutToggle`) をガード
  - `_isResultSuccess(json)` で `json.ok === true` のみ成功扱い。既存の fetch catch が undefined に resolve しても成功音にならない
- KdsRenderer が reject を返すようになったため、HTML 側の click ハンドラ (`public/kds/index.html`) 3 箇所に `.catch(function(){})` を追加して unhandled rejection を避けている
- voice-commander の **スタッフ呼び出し POST** (`api/kds/call-alerts.php`) と **品切れトグル PATCH** (`api/kds/sold-out.php`) も `_voiceIsOfflineOrStale` で冒頭ガード済

#### Service Worker への影響

- **3 SW (admin/kds/handy) は変更なし**。VERSION バンプも不要
- 既存方針 (`/api/` 完全 passthrough、POST/PATCH/DELETE passthrough、顧客パス除外、センシティブクエリ除外) を維持
- 理由: snapshot は localStorage で完結。SW Cache に API レスポンスを入れる選択肢は採らない (テナント境界・セッショントークン混在のリスク)

### 9.15.3 既知の限界

| 限界 | 説明 |
|---|---|
| 複数端末の独立性 | snapshot は端末の localStorage に閉じる。同じ user_id でも端末ごとに別。共有しない |
| ブラウザのプライベートモード | localStorage が一時的なため、再起動で snapshot 消える |
| 12 時間超 | 12h を超えた snapshot は古すぎるので使わない (load で自動削除) |
| 1.5MB 超のレスポンス | save スキップ。次回 fetch 成功までは前回の (もっと小さかった) snapshot で凌ぐ |
| 通信が断続的に切れる場合 | `OfflineDetector` の online/offline イベントが頻発するため、緑バナー → 再 fetch を頻繁に繰り返す。これは仕様 |
| Wake Lock / Push との干渉 | 現状なし (それぞれ別モジュール、別 DOM)。実機で問題が出たら Phase 3 のバナー側を弱める |
| ハンディ メニュー編集中の通信断 | カート内容は `_cart` 配列にメモリ保持されるが、ハンディアプリ自体をリロードすると消える。再取得ボタンは `location.reload()` を行うため、押下確認は推奨しない (バナーには出さない) |

### 9.15.4 手動スモーク手順 (Android Chrome PWA)

1. KDS PWA を起動 → 注文一覧を正常取得
2. DevTools の Network → Offline (または機内モード / Wi-Fi OFF)
3. 数十秒待つ → 既存の注文一覧表示は **消えない**
4. 黄色バナー「古いデータを表示中」が表示される
5. 「調理開始」「完成」「提供済」「取消」ボタンが disabled
6. 機内モード解除 → 緑バナー「通信が復帰しました」 → 自動で full fetch → 通常モード復帰、ボタン有効化
7. ハンディ で同様に: 注文送信ボタン押下 → toast「オフライン中は注文を送信できません」、API は呼ばれない
8. レジ で同様に: 「現金会計」「カード会計」ボタン押下 → toast「オフライン中は会計できません」
9. cashier の Stripe Terminal 経路: `_processTerminalPayment` 冒頭でガード、Terminal SDK は呼ばれない
10. **顧客セルフメニュー** (`/customer/menu.html` 等) は完全に影響なし — snapshot ロードもされない (early return)

### 9.15.5 ロールバック

修正 11 ファイルを `_backup_20260420_pwa3/` から戻すだけ。新規 2 ファイル (offline-snapshot.js / offline-state-banner.js) は単純削除可 (他から参照されているのは Phase 3 修正部分のみ)。
SW 変更なしのため、SW のロールバック作業は不要。

## 9.17 PWA Phase 4 — 緊急レジモード (2026-04-20)

### 9.17.0 目的

レジ画面で **通信障害・API 障害・stale 表示中でも「会計事実」を絶対に失わない** ための端末内台帳 + 同期システム。

**Phase 4 が解決すること:**
1. 通常会計 (`process-payment.php`) が止まったときに、端末内 IndexedDB に会計記録を残せる
2. 現金 / 外部カード端末 / 外部 QR 端末 / その他外部決済の 4 種を記録できる
3. 復帰後に自動でサーバー (`emergency_payments` テーブル) へ同期できる
4. 同一会計の二重送信を idempotent に防ぐ
5. 金額差異・注文重複を conflict / pending_review として可視化

**Phase 4 が解決しないこと (明示的非対象):**

- **POSLA がカード決済を代替する** (カード情報は取らない、外部端末で処理する前提)
- **オフライン中の process-payment.php キュー** (禁止。既存 process-payment はオンライン時専用)
- **オフライン返金 / 取消 / 締め処理** (いずれも整合性の担保ができないので実装しない)
- **emergency_payments → payments 自動変換** (Phase 4b に分離。今回は「台帳保存 + 管理者確認」まで)

### 9.17.1 アーキテクチャ全体像

```
[レジ端末]                      通信断           [POSLA サーバー]
 ┌──────────────────┐                             ┌─────────────────┐
 │ cashier.html      │                             │ /api/store/     │
 │  ├ 通常会計ボタン │── process-payment.php ─────│   process-      │
 │  └ 緊急レジボタン │   (オンライン時のみ)        │   payment.php   │
 │     (Phase 4)     │                             └─────────────────┘
 │                   │
 │  EmergencyRegister│ ── 同期 (idempotent) ────── ┌─────────────────┐
 │  ├ IndexedDB 保存 │──→                          │ /api/store/     │
 │  ├ 即レシート表示 │                             │  emergency-     │
 │  └ 自動再試行     │                             │  payments.php   │
 │                   │                             └─────────┬───────┘
 │  EmergencyRegStore│                                       │
 │   (IDB wrapper)   │                             ┌─────────▼───────┐
 └──────────────────┘                             │ emergency_      │
                                                    │ payments table  │
                                                    │ (POSLA 台帳)    │
                                                    └─────────────────┘
```

### 9.17.2 ファイル一覧

**新規 4 ファイル:**

| ファイル | 役割 |
|---|---|
| `sql/migration-pwa4-emergency-payments.sql` | `emergency_payments` テーブル (tenant_id 明示、UNIQUE (store_id, local_emergency_payment_id)、JSON 型で order_ids_json / item_snapshot_json) |
| `api/store/emergency-payments.php` | 同期 API (POST=保存 / GET=管理者向け一覧)。idempotent、カード情報 field 拒否、orderId 重複検出、金額差異検出 |
| `public/kds/js/emergency-register-store.js` | IndexedDB ラッパ (ES5 IIFE / callback ベース)。`posla_emergency_v1.emergency_payments` |
| `public/kds/js/emergency-register.js` | UI + ワークフロー。ヘッダボタン / 緊急会計モーダル / レシート / 一覧 / 同期再試行 |

**修正 2 ファイル:**

| ファイル | 変更内容 |
|---|---|
| `public/kds/js/cashier-app.js` | `CashierApp.getEmergencyContext()` 1 メソッド追加。既存内部変数は公開せず snapshot のみ返す |
| `public/kds/cashier.html` | 2 新規 JS ロード + `EmergencyRegister.init({ getContext })` 初期化 |

**変更なし:** SW / 既存 process-payment.php / payments テーブル / 他画面。

### 9.17.3 emergency_payments テーブル仕様

マイグレーション: `sql/migration-pwa4-emergency-payments.sql`

| カラム | 型 | 必須 | 用途 |
|---|---|---|---|
| `id` | VARCHAR(36) PK | ✓ | サーバー側 UUID |
| `tenant_id` | VARCHAR(36) | ✓ | **明示 (既存 payments にはなし。Phase 4 から必須)** |
| `store_id` | VARCHAR(36) | ✓ | 店舗境界 |
| `local_emergency_payment_id` | VARCHAR(80) | ✓ | **idempotency key** (端末生成、UNIQUE 制約) |
| `device_id` | VARCHAR(80) | — | 端末識別 |
| `staff_user_id` / `staff_name` / `staff_pin_verified` | — | | 担当スタッフ (PIN 検証通ったら staff_pin_verified=1) |
| `table_id` / `table_code` | — | — | 対象テーブル |
| `order_ids_json` | JSON | — | 対象注文 ID 配列 |
| `item_snapshot_json` | JSON | — | 明細 snapshot |
| `subtotal_amount` / `tax_amount` / `total_amount` | INT | ✓ | 金額内訳 |
| `payment_method` | VARCHAR(50) | ✓ | `cash / external_card / external_qr / other_external` |
| `received_amount` / `change_amount` | INT | — | 現金時 |
| `external_terminal_name` / `external_slip_no` / `external_approval_no` | — | — | 外部端末時の任意メモ。**カード番号は入れない** |
| `note` | — | — | 自由記述 (255 文字) |
| `status` | VARCHAR(30) | ✓ | `synced` / `conflict` / `pending_review` / `failed` (server 側視点) |
| `conflict_reason` | — | — | conflict 時の理由文字列 |
| `synced_payment_id` | — | — | **Phase 4c 以降で payments.id と連携するとき記録** |
| `client_created_at` | DATETIME | — | 端末側の会計記録時刻 |
| `server_received_at` | DATETIME | ✓ | サーバー到達時刻 |

**Index:**
- `UNIQUE (store_id, local_emergency_payment_id)` — idempotency
- `(store_id, status, created_at)` — 店舗別の要確認一覧
- `(tenant_id, status)` — テナント横断
- `(table_id)` / `(server_received_at)`

**カード番号 / 有効期限 / CVV 相当のカラムは一切存在しない** (PCI-DSS 範囲外設計)。

### 9.17.4 IndexedDB 仕様

| 項目 | 値 |
|---|---|
| **DB name** | `posla_emergency_v1` |
| **DB version** | `1` |
| **Store name** | `emergency_payments` |
| **keyPath** | `localEmergencyPaymentId` |
| **Index** | `status` / `createdAt` / `storeId` |

**保存対象フィールド:**
```javascript
{
  localEmergencyPaymentId,   // eme-<store8>-<dev12>-<tsBase36>-<rand8>
  storeId, storeName,
  deviceId,                   // localStorage 'posla_emergency_device_id' に永続保存
  staffName,                  // PIN 検証成功時は display_name で上書き
  tableId, tableCode,
  orderIds: [],               // CashierApp から取得した注文 ID 配列
  itemSnapshot: [],           // { orderId,itemId,name,qty,price,taxRate,status }
  subtotal, tax, totalAmount,
  paymentMethod,              // cash / external_card / external_qr / other_external
  receivedAmount, changeAmount,                                     // 現金時
  externalTerminalName, externalSlipNo, externalApprovalNo,         // 外部端末時
  note,
  status,                     // pending_sync → syncing → synced/conflict/pending_review/failed
  createdAt (ms), syncedAt (ms),
  syncAttempts,
  lastError, conflictReason,
  appVersion
}
```

**禁止フィールド (ラッパ側で自動 drop):** `cardNumber / pan / cardExpiry / expiry / cvv / cvc / securityCode / cardholder` — ラッパの `_sanitize()` で落とす二重防御。API 側も同名フィールドが来たら 400 で拒否。

### 9.17.5 同期 API 仕様

#### POST `/api/store/emergency-payments.php`

認証: `require_auth()` + `require_store_access($storeId)`

Request body:
```json
{
  "payment": {
    "localEmergencyPaymentId": "eme-...-...-...-...",
    "storeId": "<uuid>",
    "deviceId": "dev-...",
    "staffName": "...", "staffPin": "1234",
    "tableId": "<uuid>", "tableCode": "A3",
    "orderIds": ["..."],
    "itemSnapshot": [{"orderId":"..","itemId":"..","name":"..","qty":1,"price":800,"taxRate":10}],
    "subtotal": 727, "tax": 73, "totalAmount": 800,
    "paymentMethod": "external_card",
    "externalTerminalName": "Airpay", "externalSlipNo": "0000123",
    "note": "...",
    "clientCreatedAt": "2026-04-20T14:30:00+09:00",
    "appVersion": "20260420-pwa4"
  }
}
```

Response (統一形式):
```json
{
  "ok": true,
  "data": {
    "emergency_payment_id": "<uuid>",
    "status": "synced" | "conflict" | "pending_review",
    "conflict_reason": null | "...",
    "synced_payment_id": null,
    "idempotent": false | true
  },
  "serverTime": "..."
}
```

**status 判定ロジック (2026-04-20 レビュー指摘対応後):**

優先度順に評価。`conflict` > `pending_review` > `synced`。

**トランザクション範囲**: `orderIds` が空でない場合、以下の判定と INSERT は **単一トランザクション内**で実行する。`orders` テーブルを `SELECT ... FOR UPDATE` でロックしてから payments / emergency_payments 重複チェック → INSERT を行うことで、別端末からの同時 POST で同じ `orderId` に対する緊急会計が両方 `synced` になる race を防ぐ (レビュー指摘 2回目 #2 対応)。orderIds が空 (手入力モード) の場合はロック対象がないため通常トランザクションなし。

1. **orders ロック** (指摘 2回目 #2): `SELECT id FROM orders WHERE store_id=? AND id IN (?) FOR UPDATE` で対象注文行をロック。欠けているものは `pending_review` (`order_not_found_or_wrong_store`)
2. **金額整合性**: `abs((subtotal + tax) - totalAmount) > 1` → `pending_review` (`amount_mismatch`)
3. **手入力モード** (指摘 2回目 #1): `hasTableContext=false` または (`tableId` 空 AND `orderIds` 空) → `pending_review` (`manual_entry_without_context`)。クライアントから送られる `hasTableContext` フラグで判定
4. **table 存在確認**: `tableId` 指定時、`tables` テーブルで当該店舗に存在しなければ → `pending_review` (`table_not_found_or_wrong_store`)
5. **既存 payments 重複**: `orderIds` のいずれかが `payments.order_ids` JSON に含まれる → `conflict` (`order_already_paid`)
6. **emergency_payments 内重複** (指摘 #1): `orderIds` のいずれかが **別 localId** の既存 `emergency_payments.order_ids_json` に含まれる (status: synced/conflict/pending_review) → `conflict` (`emergency_duplicate_order`)
7. **PIN 入力はしたが検証未達** (指摘 #4): `pinEnteredClient=true` かつ `staffPinVerified=0` → `pending_review` (`pin_entered_but_not_verified`)
8. それ以外 → `synced`

**FOR UPDATE + トランザクションで race 防止**: 別端末 A と B が同じ orderId に対して同時 POST した場合、先に A がトランザクション内で `SELECT FOR UPDATE` したら B は A のコミットまで待機する。A の INSERT コミット後に B が SELECT すると `emergency_payments` に A の記録が見えるため、B は `emergency_duplicate_order` で `conflict` になる。これにより「両方 synced」という二重登録が発生しない。

**Idempotency (再送対策):**

- UNIQUE (store_id, local_emergency_payment_id) により同一 localId の 2 回目以降は既存行を返すのみ
- レスポンスに `idempotent: true` を付与
- 並行送信で ER_DUP_ENTRY (1062) が発生した場合も既存行を返して 200

**カード情報拒否:**

- 本 API に `cardNumber / pan / cardExpiry / expiry / cvv / cvc / securityCode / cardholder` フィールドが 1 つでも含まれていたら `400 FORBIDDEN_FIELD` (errorNo=E3040)
- フィールドが空でも値があるだけで拒否

#### GET `/api/store/emergency-payments.php?store_id=X[&status=pending_review]`

認証: `require_auth` + `require_store_access` + `role IN (manager, owner)`

最近 200 件の一覧を返す (tenant_id 二重境界で絞り込み)。

### 9.17.6 状態遷移

```
                端末 IndexedDB                             サーバー台帳
  [新規記録]                                               [INSERT]
   status='pending_sync' ────── 初回同期 ──────→  status='synced'
        │                                                   │
        │                       ネットエラー                 │ orderIds 重複検出
        │                      ┌─ timeout                   ├→ 'conflict'
        ▼                      ▼                            │ 金額差異
   status='syncing' ── 失敗 ──→ status='failed'             ├→ 'pending_review'
        │                      │                            │
        │                      └─ 同期再試行 ──────────────→│ (UNIQUE 同一 localId → idempotent)
        ▼                                                    │
   status='synced' (または conflict / pending_review)       │
```

端末 status と サーバー status は独立 (端末側が自分の進行を、サーバー側が台帳上の最終判定を持つ)。

### 9.17.7 CashierApp.getEmergencyContext()

```javascript
CashierApp.getEmergencyContext()
// => { storeId, storeName, userName, userRole,
//      tableId?, tableCode?, isMerged, mergedGroupKeys?,
//      orderIds: [],
//      items: [{orderId,itemId,name,qty,price,taxRate,status}],
//      subtotal, tax, totalAmount, paymentMethod,
//      hasTableContext: bool }
// 返り値が null になるのは _storeId 未確定時のみ
```

**設計ポイント (レビュー指摘 #2 反映後):**
- 既存内部変数 (`_items / _checkedItems / _selectedTableId` 等) への直接参照は**返さない**
- snapshot を新規オブジェクトとしてコピーして返す
- `_calcTotal()` を内部で呼び、`subtotal10 + subtotal8` / `tax10 + tax8` / `finalTotal` をまとめる
- 既存会計済み品目 (`paymentId` 付き) は除外
- **テーブル/品目がなくても store-level コンテキストを返す** (`storeId` / `storeName` / `userName` / `userRole` のみ、`items=[]` / `totalAmount=0` / `hasTableContext=false`)
- `_storeId` 未確定時だけ null を返す
- 内部例外発生時もフォールバックで store-level コンテキストを返す (緊急レジが「会計事実を失わない」目的を最優先)

### 9.17.7b バッジ表示仕様 (レビュー指摘 #6 反映後)

バッジ件数は「未同期 + 要確認」の合算:
- **未同期**: `pending_sync / syncing / failed`
- **要確認**: `conflict / pending_review`

表示ルール:
- `total === 0` → バッジ非表示
- `needsReview === 0 && unsynced > 0` → バッジ = 数字のみ (例: `3`)
- `needsReview > 0` → バッジ先頭に `!` (例: `!5`) で要確認ありを視覚的に区別
- tooltip で「未同期: N 件 / 要確認: M 件」を表示
- API: `EmergencyRegisterStore.countNeedsAttention(cb)` が `{ total, unsynced, needsReview }` を返す
- 後方互換: `countUnsynced(cb)` は `total` のみ返すラッパ (既存呼び出し互換)

### 9.17.8 通常会計との共存ルール

| 状態 | 通常会計 | 緊急レジ |
|---|---|---|
| オンライン + online | ✓ 従来通り | 手動起動可 (店長判断) |
| OfflineStateBanner.isOfflineOrStale() | ✗ ボタン disabled + toast | ✓ 推奨 |
| process-payment.php が 5xx を返す | ✗ エラー表示 | ✓ 緊急レジに切り替えて記録を残す |

通常会計が成立したレシートと緊急会計控えは**別物**。同じ注文に対して両方を行わないよう運用で徹底する。サーバー側では orderIds 重複検出 → conflict で可視化される。

### 9.17.9 Phase 4b 残課題

- **子テーブル `emergency_payment_orders`** (指摘 2回目 #2 の発展)
  - `emergency_payment_id` FK + `order_id` に UNIQUE(store_id, order_id)
  - トランザクション + FOR UPDATE だけでなく、DB レベルの一意制約で二重登録を絶対防止
  - 現状 Phase 4a では `order_ids_json` (JSON 型) で保持しており、JSON_CONTAINS 検索は INDEX が効かないため大量データ時に遅い
- **emergency_payments → payments 自動変換**
  - status='synced' の緊急会計を、管理者確認後に通常 `payments` テーブルへ転記する
  - idempotency: `synced_payment_id` カラムに payments.id を記録して二重変換防止
  - 現状は手動運用 (管理者が `payments` へ INSERT するための管理画面を用意する必要あり)
- **売上レポート反映**
  - 現在のレポート (sales / close) は payments のみ集計
  - emergency_payments を合算するか、変換後のみ集計するかは Phase 4c 以降で設計
- **管理画面 UI**
  - POSLA 管理画面 (`/posla-admin/`) または owner-dashboard に「緊急会計台帳」タブ
  - 店舗別・状態別の一覧、conflict / pending_review の手動解決フロー
- **緊急会計の修正 / 取消**
  - Phase 4a/4b では記録後の修正は不可 (IndexedDB に残す)。Phase 4c 以降で「承認取消」を設計

### 9.17.10 セキュリティ設計

| 項目 | 設計 |
|---|---|
| カード番号 / 有効期限 / CVV | **UI に入力欄なし / IDB に保存しない / API で受け付けない** (三重防御) |
| 外部端末の控え番号 / 承認番号 | 任意入力のみ (フリーテキスト、DB に保存 OK) |
| note (自由記述) | 表示時に `Utils.escapeHtml()` 相当で XSS 防止 |
| マルチテナント境界 | `tenant_id = user['tenant_id']` を全 SQL に明示 |
| PIN | API 送信時のみ平文 (HTTPS)、DB には hash 保存しない (検証結果の 0/1 のみ) |
| Idempotency | UNIQUE (store_id, local_emergency_payment_id) でサーバー側二重防御 |
| store 権限 | `require_store_access($storeId)` |
| 一覧 GET | manager/owner のみ (staff/device は閲覧不可) |

### 9.17.11 なぜ通常 process-payment.php をキューしないか

オフライン中に process-payment.php のリクエストをキューして後で自動実行する設計は **意図的に採用しない**:

1. **注文状態の競合**: オフライン中に他端末で会計済みになる可能性。キューが送られたとき orders.status が既に 'paid' だと二重課金。
2. **金額差異**: オフライン中に KDS で品目が `cancelled` された場合、キュー送信時に金額が合わなくなる。
3. **返金不可**: キューが自動送信されて失敗した場合、どの時点で失敗したかスタッフに通知されず、取り返しがつかない。
4. **PIN セキュリティ**: process-payment は PIN + 出勤中スタッフ検証を必須とするが、キュー保持中に PIN が端末に残ると漏洩リスク。

→ **代わりに「会計事実だけ別台帳に残し、管理者確認で手動連携する」方式**。これが emergency_payments の設計趣旨。

### 9.17.12 決済端末側オフライン決済との責務分界

カード / QR 決済は **外部決済端末が担当**:

- Airpay / Square / Stripe Terminal / 電子決済端末は、自前でオフライン対応 (一部) / 自前で承認・売上確定を行う
- POSLA は「外部端末で決済した」という**事実だけ**を緊急会計として記録する
- `payment_method='external_card'` + `externalSlipNo='0000123'` で「外部端末の控え番号がこれ」と記録するのみ
- POSLA は**カード承認済みと断定しない**。外部端末側の突合で最終確定する

### 9.17.13 デプロイ / ロールバック

**デプロイ対象 (6):**
- `sql/migration-pwa4-emergency-payments.sql` (DB 適用必須)
- `api/store/emergency-payments.php`
- `public/kds/js/emergency-register-store.js`
- `public/kds/js/emergency-register.js`
- `public/kds/js/cashier-app.js` (public method 追加のみ)
- `public/kds/cashier.html` (script ロード追加 + キャッシュバスター)

**SW 変更なし** (VERSION バンプ不要)。

**ロールバック手順:**
1. cashier-app.js / cashier.html を `_backup_20260420_pwa4/` から戻す
2. 新規 4 ファイルを削除
3. `emergency_payments` テーブルは **DROP しない** (既に未同期会計が記録されている可能性。Phase 4b 以降で再利用する)
4. **未同期会計を抱えた端末が存在する場合、IDB を手動で吐き出して管理者に送る手順** (JSON export 等) を別途 Phase 4c 以降で用意する必要あり

### 9.17.14 手動スモーク手順 (Android Chrome PWA)

1. レジ画面を PWA として開く → ヘッダ右上に 🔴 「緊急レジ」ボタンが表示される
2. ボタン押下 → メインモーダルが開く
3. 「+ 新しい緊急会計を記録」→ 現在選択中のテーブル / 合計が表示される (なければ手入力モード)
4. 支払方法 = 現金 を選択 → 受領額入力 → 記録ボタン
5. PIN 入力 → 確認 → レシート表示 (「POSLA 未同期の緊急会計控え」と明示)
6. ヘッダの「緊急レジ」ボタン横バッジに 1 表示
7. 機内モードを解除 → 自動同期 → バッジが 0 に戻る
8. 「未同期会計一覧」→ status=synced で表示される
9. **再度同じ localId で POST** (DevTools Network → Replay) → `idempotent: true` が返る
10. 顧客セルフメニューに一切影響がないこと (`/customer/menu.html` にヘッダボタンが出ない)

## 9.18 PWA Phase 4b-1 / 4b-2 — 子テーブル + 管理画面台帳 (2026-04-20)

Phase 4a で「会計事実を失わない」までを達成した緊急レジモードを、次の 2 点で堅牢化。

**Phase 4b-1 (DB 補助)**: 注文 ID 単位で二重登録を物理的に封じる子テーブル `emergency_payment_orders` 追加
**Phase 4b-2 (管理画面)**: manager / owner が店舗単位で緊急会計を確認できる**読み取り専用台帳**タブを追加

**今回もやらないこと (Phase 4c 以降):**

- emergency_payments → payments 自動転記
- orders.status = paid への変更
- order_items.payment_id の変更
- 売上レポートへの合算
- 管理画面からの「承認」「却下」「取消」ボタン
- 通常 `process-payment.php` の変更

### 9.18.1 ファイル一覧

**新規 3:**

| ファイル | 役割 |
|---|---|
| `sql/migration-pwa4b-emergency-payment-orders.sql` | 子テーブル DDL |
| `api/store/emergency-payment-ledger.php` | 管理画面向け読み取り API (GET only)。既存 `emergency-payments.php` GET とは **別ファイル**で分離 (レスポンス形式互換) |
| `public/admin/js/emergency-payment-ledger.js` | dashboard.html「緊急会計台帳」タブ JS (ES5 IIFE、`EmergencyPaymentLedger.init/load`) |

**修正 3:**

| ファイル | 変更内容 |
|---|---|
| `api/store/emergency-payments.php` | POST の emergency_payments INSERT 直後、同じトランザクション内で `emergency_payment_orders` に order_id ごとの行を INSERT。`ER_DUP_ENTRY(1062)` → 親を `status='conflict' + conflict_reason='child_unique_race: ...'` に UPDATE。テーブル未作成 `42S02/1146` → 親を `status='pending_review' + conflict_reason='emergency_payment_orders_missing'` に UPDATE |
| `public/admin/js/admin-api.js` | `AdminApi.getEmergencyPaymentLedger(from, to, status)` 追加 (既存 `storeGet` パターン) |
| `public/admin/dashboard.html` | 「レポート」グループに `data-tab="emergency-ledger"` + `<div class="tab-panel" id="panel-emergency-ledger">` + switch case + script ロード追加 |

### 9.18.2 emergency_payment_orders テーブル

```sql
CREATE TABLE emergency_payment_orders (
  id VARCHAR(36) PK,
  tenant_id, store_id, emergency_payment_id, local_emergency_payment_id, order_id,
  status VARCHAR(30) DEFAULT 'active',
  created_at DATETIME,
  UNIQUE KEY uk_store_order_active (store_id, order_id, status),
  ...
);
```

**MySQL 5.7 対応**: partial unique index が使えないため、`status` を UNIQUE に含める方式で「同一 (store_id, order_id) に active 行は 1 つだけ」を担保。当面 `status='active'` のみ登録。将来 Phase 4c+ で `invalidated / resolved` を追加する余地を残す。

**`order_ids_json` (JSON 型) との併存理由**:
- Phase 4a 時点の互換性維持 (既存 GET API / cashier-side の同期データ構造を変えない)
- 子テーブルは二重防止の**補助**。JSON カラムは当面消さない
- Phase 4c 以降で JSON 廃止 (子テーブル正) を検討

### 9.18.3 POST 処理の変更点

既存の Phase 4a 6 条件判定 + FOR UPDATE トランザクションに加え、emergency_payments INSERT の**直後・同じトランザクション内**で子テーブル INSERT を実行:

```
1. FOR UPDATE で orders 行ロック
2. status 判定 (既存 6 条件)
3. INSERT emergency_payments (status 決定済み)
4. ★新★ orderIds 非空 & status != 'conflict' のとき:
     foreach orderIds:
       INSERT emergency_payment_orders (order_id, status='active')
     catch ER_DUP_ENTRY (1062):
       UPDATE emergency_payments SET status='conflict',
         conflict_reason='child_unique_race: <order_id>'
     catch 42S02 / 1146 (テーブル未作成):
       UPDATE emergency_payments SET status='pending_review',
         conflict_reason='emergency_payment_orders_missing'
     catch その他 PDOException:
       UPDATE ... status='pending_review', conflict_reason='child_insert_failed: ...'
5. COMMIT
```

**Idempotent 再送** (同一 localId 2 回目以降) は `emergency_payments` の既存行検索段階で commit return するため、子 INSERT は実行されない。

**なぜ migration 未適用でも 500 にしないか**: 「会計事実を失わない」を最優先。子テーブルが無くても Phase 4a の emergency_payments 台帳には記録される。status=pending_review + 明示的 conflict_reason で管理者に気付かせる。

### 9.18.4 管理画面「緊急会計台帳」タブ (4b-2)

**配置**: dashboard.html 「レポート」グループのサブタブ。`data-tab="emergency-ledger" data-group="report" data-role-min="manager" data-requires-store`。

**権限**: manager / owner のみ表示 + API 側で `role IN (manager, owner)` を強制 (staff / device は 403)。

**UI**:

- 期間フィルタ (from / to、デフォルト直近 7 日、最大 92 日)
- status フィルタ (all / synced / conflict / pending_review / failed)
- KPI 4 つ: 件数 / 合計金額 / 要確認件数 (conflict + pending_review) / PIN 未検証件数
- 一覧テーブル: 日時 / status / テーブル / 合計 / 支払方法 / 控え・承認番号 / 担当 / PIN 検証 / conflict_reason / localId 短縮
- 行クリックで詳細モーダル: itemSnapshot 明細 / orderIds / note / 時刻情報
- **操作ボタン一切なし**。黄色バナーで「確認専用。売上反映・承認・修正はまだできません (Phase 4c 予定)」と明示

**API レスポンス形式** (`GET /api/store/emergency-payment-ledger.php`):

```json
{
  "ok": true,
  "data": {
    "summary": { "count", "totalAmount", "needsReviewCount", "pinUnverifiedCount" },
    "records": [ { id, localEmergencyPaymentId, tableId, tableCode,
                   orderIds[], itemSnapshot[],
                   subtotal, tax, totalAmount, paymentMethod,
                   receivedAmount, changeAmount,
                   externalTerminalName, externalSlipNo, externalApprovalNo,
                   status, conflictReason, syncedPaymentId,
                   staffName, staffPinVerified, note,
                   clientCreatedAt, serverReceivedAt } ],
    "filter": { storeId, from, to, status }
  },
  "serverTime": "..."
}
```

JSON カラム (`order_ids_json` / `item_snapshot_json`) は PHP 側で `json_decode` → 配列で返す。フロント側の `JSON.parse` 失敗リスクを排除。

### 9.18.5 なぜ payments 転記をまだしないか

| 理由 | 詳細 |
|---|---|
| **返金・取消の責任分界が未定義** | 緊急会計を payments に転記した後に顧客クレームがあったとき、通常の返金フローが通るのか。外部端末側の控えとの突合順序は？ |
| **売上レポートとの時刻整合** | emergency_payments の `client_created_at` (端末時刻) と payments.paid_at (サーバー時刻) の扱い。時差で日付を跨ぐケース |
| **PIN 未検証の扱い** | Phase 4a の `pinEnteredClient=true && staffPinVerified=0` ケースを payments に転記してよいか。担当者不明の取引を売上に入れない方が保守的 |
| **手入力モード** | `manual_entry_without_context` は orderIds がないため、どの orders に対応する会計か機械判定不能。管理者の手動照合が必須 |
| **外部端末との突合** | カード端末 (Airpay 等) の売上と POSLA 側の緊急会計がいつ付き合わせられたか、その後 POSLA 側の記録を動かしてよいか |

→ **Phase 4c** で「管理者による 1 件ずつ承認 → payments 転記」フローを設計予定。自動転記は Phase 4d 以降。

### 9.18.6 Phase 4c 以降の予定

- **Phase 4c-1**: 管理画面に「承認」「却下」操作追加 (emergency_payments.status → `approved` / `rejected`)
- **Phase 4c-2**: 承認済みのみを payments に転記する別 API。`synced_payment_id` で idempotent
- **Phase 4d**: 売上レポート合算 / 売上レポートとの時刻整合ポリシー確定
- **Phase 4e**: JSON order_ids_json 廃止 + 子テーブルのみ運用
- **Phase 4f**: 緊急会計の IDB エクスポート / 管理画面からの手動取り込み

### 9.18.7 スモーク手順

1. 管理画面 (manager) で「レポート > 緊急会計台帳」タブ表示
2. 期間指定 → 一覧取得 → KPI 表示 → 行クリックで詳細
3. staff ロールで API を直接叩く → 403 を確認
4. 別端末から同じ order_id で緊急会計を同時 POST → 一方が `conflict` (`child_unique_race`) で台帳に記録されることを確認
5. migration 未適用環境 (テスト) では `pending_review` + `emergency_payment_orders_missing` になる (本番では発生しない)

## 9.19 PWA Phase 4c-1 — 管理者解決フロー (2026-04-20)

Phase 4b で実装した「読み取り専用台帳」に、管理者 (manager/owner) の判断結果を残す仕組みを追加。

### 9.19.0 目的とスコープ

**やること:**
- 緊急会計 1 件ごとに「有効確認 / 重複扱い / 無効扱い / 保留」を管理者が記録
- 解決理由 (note) / 解決者 / 解決日時を DB に永続化
- 台帳画面に解決状態カラムと操作 UI を追加
- state machine + idempotent + マルチテナント境界付き

**やらない (Phase 4c-2 / 4d 以降):**
- `emergency_payments → payments` への転記 ← **絶対にしない**
- `orders.status = 'paid'` 更新
- `order_items.payment_id` 更新
- 売上レポートへの合算
- レジ締めへの反映
- 解決後の取消フロー (Phase 4c-2 以降)
- 自動承認 / 自動変換

### 9.19.1 status と resolution_status の 2 軸設計

| 軸 | カラム | 意味 | 変更タイミング |
|---|---|---|---|
| 同期状態 | `status` | Phase 4a の `synced / conflict / pending_review / failed` | 端末からの同期 POST 時 (サーバー側自動判定) |
| 解決判断 | `resolution_status` | Phase 4c-1 の `unresolved / confirmed / duplicate / rejected / pending` | 管理者による手動記録 |

**status と resolution_status は独立軸。どちらかを上書きしない。**

例:
- `status='conflict'` でも `resolution_status='confirmed'` はありうる (通常会計と重複していたが管理者が精査して「この緊急会計が正」と判断した場合)
- `status='synced'` でも `resolution_status='duplicate'` はありうる (同期は正常だが店舗運用で別会計と統合する判断)

### 9.19.2 ファイル一覧

**新規 2:**

| ファイル | 役割 |
|---|---|
| `sql/migration-pwa4c-emergency-payment-resolution.sql` | `emergency_payments` に resolution 5 カラム + 2 INDEX 追加 (INFORMATION_SCHEMA + PROCEDURE で再実行安全) |
| `api/store/emergency-payment-resolution.php` | POST only。state machine + idempotent + TX + FOR UPDATE |

**修正 4:**

| ファイル | 変更内容 |
|---|---|
| `api/store/emergency-payment-ledger.php` | SELECT / summary に resolution 5 カラム / 5 KPI 追加。migration 未適用時 (SQLSTATE 42S22) は legacy SELECT にフォールバック。レスポンスに `hasResolutionColumn` ヒント追加 |
| `public/admin/js/admin-api.js` | `resolveEmergencyPayment(emergencyPaymentId, action, note)` 追加 |
| `public/admin/js/emergency-payment-ledger.js` | 一覧に解決列 + 解決者/日時列追加、KPI タイル 4 → 8、詳細モーダルに解決ブロック + 4 操作ボタン + note textarea + 二重クリック防止 |
| `public/admin/dashboard.html` | admin-api.js / emergency-payment-ledger.js キャッシュバスター `?v=20260420-pwa4c1` |

### 9.19.3 追加カラム (emergency_payments)

| カラム | 型 | デフォルト | 備考 |
|---|---|---|---|
| `resolution_status` | VARCHAR(30) NOT NULL | `'unresolved'` | unresolved / confirmed / duplicate / rejected / pending |
| `resolution_note` | VARCHAR(255) NULL | NULL | duplicate / reject は API で必須 |
| `resolved_by_user_id` | VARCHAR(36) NULL | NULL | `users.id` |
| `resolved_by_name` | VARCHAR(100) NULL | NULL | display_name (なければ username) |
| `resolved_at` | DATETIME NULL | NULL | 解決 UPDATE 時に NOW() |

**INDEX 2:**
- `idx_emergency_resolution (tenant_id, store_id, resolution_status, resolved_at)`
- `idx_emergency_resolved_by (resolved_by_user_id)`

### 9.19.4 API `emergency-payment-resolution.php`

**Request (POST)**:
```json
{
  "store_id": "<uuid>",
  "emergency_payment_id": "<uuid>",
  "action": "confirm" | "duplicate" | "reject" | "pending",
  "note": "..."
}
```

**action → resolution_status map**:
- `confirm` → `confirmed`
- `duplicate` → `duplicate`
- `reject` → `rejected`
- `pending` → `pending`

**認証**: `require_auth()` + `require_store_access($storeId)` + `role IN ('manager','owner')` (staff/device は **403 FORBIDDEN**)

**検証**:
- `store_id` / `emergency_payment_id` / `action` 必須 → 400 VALIDATION
- `action` allowlist (confirm/duplicate/reject/pending) → 400 VALIDATION
- **`duplicate` / `reject` は `note` 必須**、`confirm` / `pending` は任意 → 400 VALIDATION
- `note` は 255 文字で切る

**State machine (TX + FOR UPDATE で実行)**:

全状態共通のルール: **現在 resolution_status と新 resolution_status が同値なら UPDATE なしで `idempotent: true` を返す** (resolved_at 上書き防止)。

| 現在 resolution_status | 新 action (resolution_status 換算) | 結果 |
|---|---|---|
| `unresolved` / `pending` / null | 同じ値 (例: unresolved→unresolved は存在しない、pending→pending) | **idempotent: true** (UPDATE なし、既存 resolved_at/name を返却) |
| `unresolved` / `pending` / null | 異なる値 (例: unresolved→confirmed, pending→duplicate) | **UPDATE 実行** (resolved_at = NOW(), idempotent: false) |
| `confirmed` / `duplicate` / `rejected` (終結状態) | 同じ値 | **idempotent: true** (UPDATE なし) |
| `confirmed` / `duplicate` / `rejected` | 異なる値 | **409 CONFLICT** (`'既に解決済みです (現在: ...)'`)。取消フローは Phase 4c-2 以降 |

**解決者名の取得**: `users.display_name` を `tenant_id + user_id` で LIMIT 1 取得。取れなければ `require_auth()` の `username` にフォールバック。

**エラーコード** (既存登録済みのみ使用、新規追加なし):
- `VALIDATION` (E2073) / `FORBIDDEN` (E3010) / `NOT_FOUND` (E4005) / `CONFLICT` (E7001) / `DB_ERROR` (E1005)

### 9.19.5 ledger API 拡張と 42S22 フォールバック

**summary 追加フィールド (5)**: `unresolvedCount / confirmedCount / duplicateCount / rejectedCount / pendingResolutionCount`

**records 追加フィールド (5)**: `resolutionStatus / resolutionNote / resolvedByUserId / resolvedByName / resolvedAt`

**レスポンス追加**: `hasResolutionColumn: bool` (UI が操作ボタンを出すか判定)

**42S22 フォールバック** (migration 未適用の保険):

1. summary SQL が `SQLSTATE=42S22` (unknown column) で失敗 → `$hasResolutionColumn=false` に落とし、resolution 抜きの legacy summary SQL で再試行
2. records SELECT も同様に 2 経路 (resolution 付き → 失敗時 legacy)
3. records の resolution 系フィールドは isset チェック → 未定義なら null で返す (前回 `$totalAmount` 未定義インシデントの再発防止)
4. UI は `hasResolutionColumn=false` を見て解決操作 UI を完全非表示 + 「migration 未適用。管理者に連絡」警告表示

### 9.19.6 なぜ payments 転記をまだしないか

- 転記後の「取消」が設計未確定 (Phase 4c-2 で承認取消フロー設計予定)
- 売上レポートとの時刻整合 (client_created_at vs paid_at) の扱いが未決
- `confirmed` を取り消して `duplicate` に変える際、既に payments にあったらどう戻すか
- カード端末側の売上との突合タイミングが Phase 4c-2 設計依存

→ **Phase 4c-1 は「管理者判断を残す」まで**。転記は Phase 4c-2 で同期フロー込みで設計。

### 9.19.7 UI 挙動

- 一覧テーブル: 「解決」列 (バッジ色分け) / 「解決者・日時」列追加
- KPI: 既存 4 (件数/合計/要確認/PIN 未検証) + 新 4 (未解決/有効確認済/重複扱い/無効扱い) = **計 8 タイル**。`grid-template-columns: repeat(auto-fit, minmax(140px, 1fr))` で自動折返し
- 詳細モーダル:
  - 解決ブロック (現在 status + 解決者 + 日時 + note)
  - `unresolved` / `pending` 時のみ 4 操作ボタン + note textarea 表示
  - `confirmed` / `duplicate` / `rejected` 時は「既に解決済み (Phase 4c-2 以降で取消可能)」と表示
  - `hasResolutionColumn=false` 時は「migration 未適用」警告表示
- 二重クリック防止: `_resolving = true` フラグ + ボタン全 disabled
- 409 エラー時: 「別の管理者が既に解決を記録した可能性あり」と表示して台帳再読み込みを促す

### 9.19.8 Phase 4c-2 / 4d に残す内容

1. **confirmed の緊急会計を `payments` に転記する API** (`synced_payment_id` で idempotent)
2. **解決取消フロー** (`confirmed → unresolved` 等への逆遷移)
3. **売上レポート合算 / レジ締め反映**
4. **転記時の返金ポリシー** (転記後に外部端末側で売上取消が出たときの扱い)
5. **POSLA 運営向け横断管理 UI** (tenant 横断で未解決 / 要確認を見る)

### 9.19.9 スモーク手順

1. manager ログイン → 「レポート > 緊急会計台帳」を開く
2. KPI に 8 タイル表示確認 (未解決 / 有効確認済 / 重複 / 無効)
3. 行クリック → 詳細モーダル → 解決ブロック表示
4. 操作ボタン 4 つ表示 (unresolved の場合)
5. 「重複扱い」ボタン → note 空 → 「note の入力が必要です」表示確認
6. note 入力 → confirm ダイアログ → OK → リロードで解決済みに変わる
7. 同じ行を再度開き、同じ「重複扱い」→ idempotent で成功
8. 別の「有効確認」ボタン → 409 「既に解決済みです」トースト
9. staff ロールで API 直接叩く → 403
10. 未認証で POST → 401 (本番確認済み)

## 9.20 PWA Phase 4c-2 — emergency_payments → payments 転記 (2026-04-20)

Phase 4c-1 で管理者が記録した `resolution_status='confirmed'` の緊急会計を、**1 件ずつ明示操作で**通常 payments テーブルに転記する API + UI を追加。

### 9.20.0 目的とスコープ

**やること:**
- manager / owner が詳細モーダルから「売上へ転記する」を押すと payments に INSERT
- 対応する orders を `status='paid'` に UPDATE
- emergency_payments.synced_payment_id に payment.id を記録して idempotent 担保

**絶対にやらない:**
- 自動転記 / 一括転記 / cron
- `duplicate / rejected / pending / unresolved` の転記
- 転記取消 (Phase 4d)
- payments テーブルのスキーマ変更
- order_items.payment_id の更新 (全額会計相当)
- table_sessions / tables.session_token の変更 (Phase 4d)
- emergency_payment_orders.status の変更 ('active' のまま維持)
- `process-payment.php` の再利用や変更

### 9.20.1 ファイル一覧

**新規 2:**

| ファイル | 役割 |
|---|---|
| `sql/migration-pwa4c2-emergency-payment-transfer.sql` | emergency_payments に 4 カラム + 1 INDEX 追加 (INFORMATION_SCHEMA + PROCEDURE で再実行安全) |
| `api/store/emergency-payment-transfer.php` | POST only。TX + FOR UPDATE + 二重売上ガード |

**修正 4:**

| ファイル | 変更内容 |
|---|---|
| `api/store/emergency-payment-ledger.php` | records に transferred_* 4 フィールド、summary に `transferableCount / transferredCount`、レスポンスに `hasTransferColumns` 追加。resolution / transfer の migration 適用状態を**事前チェック**して SELECT 文を動的に組む |
| `public/admin/js/admin-api.js` | `transferEmergencyPayment(emergencyPaymentId, note)` 追加 |
| `public/admin/js/emergency-payment-ledger.js` | KPI に「転記可能」「売上転記済」2 タイル追加 (計 10)、詳細モーダルに「売上転記」ブロック (転記可否の各理由分岐 / confirm ダイアログ / 二重クリック防止 / 409 トースト) |
| `public/admin/dashboard.html` | admin-api.js / emergency-payment-ledger.js cache buster `?v=20260420-pwa4c2` |

### 9.20.2 追加カラム (emergency_payments)

| カラム | 型 | デフォルト | 備考 |
|---|---|---|---|
| `transferred_by_user_id` | VARCHAR(36) NULL | NULL | 転記実行者 user_id |
| `transferred_by_name` | VARCHAR(100) NULL | NULL | display_name (なければ username) |
| `transferred_at` | DATETIME NULL | NULL | 転記 UPDATE 時 NOW() |
| `transfer_note` | VARCHAR(255) NULL | NULL | 管理者メモ + 近似フラグ `[tax split approximated]` |

**追加 INDEX:**
- `idx_emergency_synced_payment (tenant_id, store_id, synced_payment_id)` (「転記済みを除外」「payment_id 逆引き」用)

### 9.20.3 API `emergency-payment-transfer.php`

**Request (POST)**:
```json
{
  "store_id": "<uuid>",
  "emergency_payment_id": "<uuid>",
  "note": "任意 (200 文字まで)"
}
```

**認証**: `require_auth + require_store_access + role IN ('manager','owner')`。staff/device は 403

**バリデーション / state machine**:

| 条件 | レスポンス |
|---|---|
| store_id / emergency_payment_id 欠落 | 400 VALIDATION |
| 対象 emergency_payments 不在 (tenant+store+id) | 404 NOT_FOUND |
| `synced_payment_id IS NOT NULL` | **200 idempotent:true** (UPDATE / INSERT 一切なし) |
| `resolution_status !== 'confirmed'` | 409 CONFLICT |
| `status === 'failed'` | 409 CONFLICT |
| `total_amount <= 0` | 400 VALIDATION |
| `payment_method === 'other_external'` | 409 CONFLICT (Phase 4d) |
| `orderIds` 空 (manual_entry) | 409 CONFLICT (Phase 4d) |
| orders のいずれか不在 | 409 CONFLICT (order_not_found) |
| orders.status IN ('paid','cancelled') | 409 CONFLICT (order_already_finalized) |
| 既存 payments.order_ids に同一 order_id が含まれる | 409 CONFLICT (order_already_in_payments) |
| UPDATE orders rowCount != orderIds.count | 409 CONFLICT (並行転記) + ROLLBACK |

**トランザクション順序**:

```
BEGIN
  SELECT emergency_payments FOR UPDATE (tenant+store+id)
  → 早期 idempotent: synced_payment_id あれば commit + return
  → resolution_status / status / total_amount / payment_method / orderIds 検証 (409/400)
  SELECT orders FOR UPDATE (IN orderIds + store_id)
  → 件数不一致 → 409 order_not_found
  → status IN ('paid','cancelled') → 409 order_already_finalized
  SELECT payments JSON_CONTAINS (各 order_id)
  → ヒット → 409 order_already_in_payments
  tax split 再集計 (itemSnapshot.taxRate)
  payment_method mapping (cash/card/qr) + gateway_name
  paid_at 決定 (client_created_at > server_received_at > NOW())
  INSERT payments (merged_cols / gateway_cols は動的分岐)
  UPDATE payments SET gateway_name/external_payment_id/gateway_status (external_* のみ)
  UPDATE orders SET status='paid', payment_method, paid_at, updated_at
    WHERE id IN (?) AND store_id=? AND status NOT IN ('paid','cancelled')
    rowCount == orderIds.count 検証 → 不一致なら ROLLBACK + 409
  UPDATE emergency_payments SET synced_payment_id, transferred_*, updated_at
COMMIT
```

### 9.20.4 payments INSERT カラム対応表

| payments カラム | ソース | 備考 |
|---|---|---|
| id | `generate_uuid()` | 新規 |
| store_id | emergency.store_id | |
| table_id | emergency.table_id | null 可 |
| merged_table_ids / merged_session_ids | **NULL** | emergency に保存してないため |
| session_id | **NULL** | 同上 (Phase 4d で設計) |
| order_ids | `JSON_encode(orderIds)` | emergency.order_ids_json を再 encode |
| paid_items | emergency.item_snapshot_json そのまま | null 可 |
| subtotal_10 / tax_10 / subtotal_8 / tax_8 | itemSnapshot.taxRate から再集計 | 下記ポリシー参照 |
| total_amount | emergency.total_amount | |
| payment_method ENUM | **mapping** (下記参照) | ENUM は 'cash'/'card'/'qr' のみ |
| gateway_name | 'external_card' / 'external_qr' / NULL | external 系を識別 |
| external_payment_id | emergency.external_slip_no | 100 文字まで |
| gateway_status | emergency.external_approval_no | 30 文字まで |
| received_amount / change_amount | emergency のそのまま | |
| is_partial | **0** (常に全額会計) | order_items を触らないため |
| user_id | 転記実行者 user_id | |
| note | `<入力 note> / emergency_transfer src=<localId先頭40> [tax split approximated]?` | 200 文字まで |
| paid_at | `client_created_at > server_received_at > NOW()` | **NOW() は最後の fallback** |
| created_at | NOW() | システム記録時刻 |

### 9.20.5 payment_method mapping

| emergency | payments.payment_method (ENUM) | payments.gateway_name |
|---|---|---|
| `cash` | `cash` | NULL |
| `external_card` | `card` | `'external_card'` |
| `external_qr` | `qr` | `'external_qr'` |
| `other_external` | **転記不可 (409)** | — |

`other_external` は ENUM に収まる正しい近似値が決められないため、Phase 4d で「手動判定 → payment_method 指定」UI を追加する前提で今回スコープ外。

### 9.20.6 paid_at ポリシー

1. `emergency.client_created_at` が NULL でなければ → それを paid_at に使う (端末で会計した実時刻)
2. なければ `emergency.server_received_at` を使う (サーバー到達時刻)
3. どちらも無ければ `NOW()` (最後の fallback)

**orders.paid_at も同じ値を使う**。payments.paid_at と orders.paid_at がズレないようにする。

**NOW() を第一選択にしない理由**: 売上計上日時が「管理者が転記ボタンを押した時刻」にずれると、営業日を跨いだ場合に売上レポートの日付が実際と違ってしまう。

### 9.20.7 tax split ポリシー

1. `itemSnapshot` の各 item に `taxRate` (8 or 10) がある → taxRate ごとに集計
2. taxRate 欠落 item が 1 つでもあれば → 全額 10% に寄せる + transfer_note に `[tax split approximated]` を追記
3. itemSnapshot の合計が total_amount と一致しなければ → 同じく approximated
4. itemSnapshot が空 → emergency.subtotal/tax を 10% 側に寄せる + approximated

approximated の記録:
- payments.note に ` / tax split approximated` 追記
- emergency_payments.transfer_note に ` [tax split approximated]` 追記

### 9.20.8 table_sessions / order_items を触らない方針

- **order_items**: 全額会計相当なので `payment_id` を更新しない (process-payment.php の `!isPartial` 経路と同じ)
- **table_sessions**: 緊急会計が発動する時点で既にセッションが終了している (別手段で table クローズ済み) ことが多いため、状態変更を加えない。もし table_sessions が残っている場合は Phase 4d の「転記取消 / セッションクリーンアップ」で設計

### 9.20.9 UI (emergency-payment-ledger.js)

詳細モーダルで「管理者解決」ブロックの直下に「売上転記」ブロックを追加。

**表示状態の分岐** (優先度順):

| 条件 | 表示 |
|---|---|
| `syncedPaymentId` あり | ✓ 転記済み (payment_id / 転記者 / 日時 / note) |
| `hasTransferColumns=false` | migration 未適用警告 |
| `resolutionStatus !== 'confirmed'` | 先に有効確認してください |
| `status === 'failed'` | 同期失敗記録は転記できません |
| `orderIds` 空 | 手入力モードは Phase 4d |
| `paymentMethod === 'other_external'` | Phase 4d |
| 上記すべて解除 | 警告文 + note textarea + 「売上へ転記する」ボタン |

**操作時の挙動**:
- confirm ダイアログ: 合計 / 支払方法 / テーブルを提示、外部端末控えとの一致を要求
- 二重クリック防止 (`_transferring` フラグ + ボタン disabled)
- 成功 → モーダル閉じる + 台帳リロード
- 409 (order_already_finalized / order_already_in_payments / rowcount_mismatch) → 「既に通常会計済みまたは別管理者が転記済みの可能性があります」トースト + 台帳再読み込みを促す

### 9.20.10 Idempotency

- `synced_payment_id IS NOT NULL` を SELECT FOR UPDATE 内で判定 → 既存 payment_id を返す (UPDATE 一切なし)
- レスポンスに `idempotent: true`
- race 条件: FOR UPDATE で先行トランザクションがコミットするまで待つ + orders 側 rowCount 検証で二重売上完全防止

### 9.20.11 Phase 4d に残すこと

1. **転記取消 / 売上取消** (確認済みを unresolved に戻す / payments から削除 or refund 経路で処理)
2. **転記後の返金ポリシー**
3. **manual_entry_without_context の売上反映方式** (manual payment 別テーブル or payments.table_id=null 対応)
4. **payment_method='other_external' の mapping UI**
5. **合流会計 (merged_table_ids) の転記対応**
6. **POSLA 運営向け横断管理 UI** (tenant 横断の転記状況)
7. **order_ids_json 廃止** (子テーブル一本化)
8. **emergency_payment_orders.status 運用** (転記済みを 'transferred' に遷移させるか)
9. **IDB エクスポート / 端末故障救出**
10. **転記後の table_sessions クリーンアップ**

### 9.20.12 スモーク手順

1. manager ログイン → 台帳 → 転記可能な緊急会計 (resolution_status='confirmed' かつ synced_payment_id 空) を詳細モーダルで開く
2. 「売上転記」ブロックに「売上へ転記する」ボタンが表示されることを確認
3. 押下 → confirm → 成功 → リロード → 「✓ 転記済み: payment_id xxx」表示
4. 同じ行を再度開き、再度転記 → ボタン非表示 / 転記済み情報のみ
5. 別の緊急会計で同じ order_id を緊急会計 + confirmed → 転記しようとして 409 (order_already_in_payments)
6. duplicate / rejected / unresolved / pending は転記ボタンが出ない (理由文のみ表示)
7. orderIds 空 / other_external は理由文のみ表示
8. staff で POST 直叩き → 403
9. 未認証で POST → 401 (本番確認済み)
10. GET で transfer API → 405 (本番確認済み)

### 9.20.13 SQL 運用確認

```sql
-- 転記済み件数
SELECT COUNT(*) FROM emergency_payments WHERE synced_payment_id IS NOT NULL;

-- 転記可能だが未転記
SELECT id, total_amount, resolved_by_name, resolved_at
FROM emergency_payments
WHERE tenant_id = ? AND store_id = ?
  AND resolution_status = 'confirmed'
  AND synced_payment_id IS NULL
  AND status IN ('synced','conflict','pending_review')
  AND payment_method <> 'other_external'
  AND order_ids_json IS NOT NULL AND JSON_LENGTH(order_ids_json) > 0;

-- 転記済み + 対応 payments の整合性チェック
SELECT e.id AS emergency_id, e.synced_payment_id, p.id AS payment_id, p.total_amount, p.paid_at
FROM emergency_payments e
LEFT JOIN payments p ON p.id = e.synced_payment_id AND p.store_id = e.store_id
WHERE e.synced_payment_id IS NOT NULL
  AND (p.id IS NULL OR p.total_amount != e.total_amount);
```

最後のクエリが 0 行なら整合性 OK。行があれば要手動精査。

## 9.21 Phase 4d-1: PIN 入力有無の永続化 + 台帳 UX 改善

### 9.21.1 目的

Phase 4c-2 までで「会計事実の保全 / 管理者判断 / 売上転記」は揃った。
Phase 4d-1 は **会計ロジックに触れず、台帳の情報粒度だけを上げる** 改善。

- 端末側の `pinEnteredClient` (PIN を入力した事実) を emergency_payments にサーバー永続化する
- 既存の `staff_pin_verified` (bcrypt 検証 0/1) とは独立した 2 軸目として使う
- 台帳 UI の「PIN」列を **4 状態** に分ける: 確認済 / 入力不一致 / 入力なし / 未確認
- 「担当」列は PIN 未検証時に `(自己申告) 〇〇` の prefix で、users テーブル紐付きでないことを明示
- 「状態」「解決」の列名を「同期状態」「管理者判断」に改名して紛れを減らす
- 手入力記録 (tableId/orderIds 空 または `manual_entry_without_context` 含む) に薄黄色バッジを付与

### 9.21.2 DB 変更

新規: `sql/migration-pwa4d-pin-entered-client.sql`

```sql
ALTER TABLE emergency_payments
  ADD COLUMN pin_entered_client TINYINT(1) DEFAULT NULL
  COMMENT 'クライアントが PIN 入力したか。NULL=旧データ/不明、0=入力なし、1=入力あり'
  AFTER staff_pin_verified;
```

**NOT NULL DEFAULT 0 にしない理由**: 既存行は「PIN 入力したが不一致」「旧仕様で必須だった」「カラム未追加時代」が混在しており、全て「入力なし」と表示すると意味を誤らせる。NULL = 旧データ / 不明 を明示。

**条件付き backfill (同 migration 内)**:
- `staff_pin_verified = 1` の行 → `pin_entered_client = 1`
- `conflict_reason LIKE '%pin_entered_but_not_verified%'` の行 → `pin_entered_client = 1`
- それ以外は NULL のまま

MySQL 5.7 の INFORMATION_SCHEMA + PROCEDURE で冪等 (2 回実行しても安全)。

### 9.21.3 API 変更

- `api/store/emergency-payments.php`: POST body の `pinEnteredClient` を 0/1/NULL で永続化。migration 未適用環境は `SELECT pin_entered_client FROM emergency_payments LIMIT 0` で事前判定して INSERT 文を分岐 (従来 SQL fallback)。
- `api/store/emergency-payment-ledger.php`: SELECT に `pin_entered_client` を動的追加。records に `pinEnteredClient` (true / false / null) を返す。レスポンスに `hasPinEnteredClientColumn` フラグ。

### 9.21.4 UI 変更 (emergency-payment-ledger.js)

- 一覧列見出し: 「状態」→「同期状態」/「解決」→「管理者判断」
- PIN セル (`_pinBadge`) が 4 状態を描画:
  - `staffPinVerified=true` → 「✓ 確認済」緑
  - `pinEnteredClient=true && !staffPinVerified` → 「入力不一致」オレンジ
  - `pinEnteredClient=false && !staffPinVerified` → 「入力なし」グレー
  - `pinEnteredClient=null && !staffPinVerified` → 「未確認」グレー
- 担当セル (`_staffCell`) が PIN 未検証時 `(自己申告) name` 表示
- 手入力記録判定 (`_isManualEntryRecord`) と「手入力」バッジ (薄黄色 `#fff3cd`) を日時列に添付 + 行背景薄黄色 (`#fffde7`)
- 詳細モーダルの「担当: xxx (PIN: 〇〇)」も 4 状態 + 自己申告 prefix
- cache buster: `?v=20260420-pwa4d-1`

### 9.21.5 変更しない範囲

- payments / orders / cash_log / table_sessions は一切変更しない
- register-report.php / payments-journal.php / refund-payment.php / receipt.php は変更しない
- process-payment.php は変更しない
- emergency-payment-transfer.php / emergency-payment-resolution.php は変更しない
- Service Worker は変更しない
- 顧客画面 / ハンディ / KDS 注文画面には混入しない

### 9.21.6 既知の残件 (Phase 4d-2 以降)

- Phase 4c-2 以前に作成された「PIN 不一致」行のうち `conflict_reason` が `manual_entry_without_context` のみだった行は backfill 対象外で `pinEnteredClient = NULL` のまま。新規記録からは 0/1 が確実に埋まる。
- `other_external` の分類 (Phase 4d-2 で `external_method_type` カラム追加予定)
- 手入力記録の売上反映 (Phase 4d-3)
- 転記取消 / 売上取消 (Phase 4d-4)

---

## 9.22 Phase 4d-2: other_external の外部分類

### 9.22.1 目的

緊急会計の `payment_method='other_external'` を「商品券 / 事前振込 / 売掛 / ポイント充当 / その他」に分類できるようにし、台帳 UI で何の外部決済かを機械可読に残す。**売上転記は Phase 4d-3 以降で別設計** — 4d-2 では記録・表示のみ。

### 9.22.2 DB 変更

- `sql/migration-pwa4d2a-emergency-external-method-type.sql`:
  `emergency_payments.external_method_type VARCHAR(30) DEFAULT NULL` を追加 (AFTER payment_method)
- `sql/migration-pwa4d2b-payments-external-method-type.sql`:
  `payments.external_method_type VARCHAR(30) DEFAULT NULL` を追加 (AFTER payment_method)

**2 本に分けた理由**: 緊急会計側 (記録用) と本体 payments 側 (将来転記用) は独立に apply / rollback できるよう分離。今回 2 本とも適用。

両方とも INFORMATION_SCHEMA + PROCEDURE で冪等。**backfill なし**: 既存 `other_external` 行の分類は NULL のまま残し、4d-3 以降で管理者が手動分類する土台を残す。

### 9.22.3 許可値

| slug | UI 表示 | 用途 |
|---|---|---|
| `voucher` | 商品券 | 紙 / 電子商品券、ギフト券、クーポン券 |
| `bank_transfer` | 事前振込 | 予約時振込、団体予約・ケータリング |
| `accounts_receivable` | 売掛 | 接待・固定客付け払い、法人請求 |
| `point` | ポイント充当 | 店舗独自ポイントで全額/一部相殺 |
| `other` | その他 | 上記に当てはまらない稀ケース (安全弁) |

**追加しない予約語**: `posla_pay` / `gift_card` / `coupon` / `cashback` / `crypto`。特に POSLA PAY は将来 `external_method_type='posla_pay'` or gateway_* 経由で別設計する余地を残す。

### 9.22.4 API 変更

- `api/store/emergency-payments.php`:
  - `$allowedExternalMethodTypes` allowlist 追加
  - `paymentMethod === 'other_external'` のときのみ `externalMethodType` を保存
  - `paymentMethod !== 'other_external'` では silent drop (NULL 保存)
  - 許可値以外は `400 VALIDATION`（silent に `other` に丸めない）
  - INSERT 文に external_method_type を動的注入 (migration 未適用環境は旧 SQL fallback)
- `api/store/emergency-payment-ledger.php`:
  - SELECT に `external_method_type` を動的追加
  - records に `externalMethodType: 'voucher'|...|null` を返却
  - レスポンスに `hasExternalMethodTypeColumn` フラグ

### 9.22.5 UI 変更

**`public/kds/js/emergency-register.js`**:
- `other_external` 選択時のみ「分類 select」表示 (`#cer-ext-method-row`)
- 初期値は `""` (選択してください)。**`voucher` などを既定にしない**（商品券でないのにそのまま登録する事故を防ぐため）
- 未選択で submit → `window.alert('その他外部決済の分類を選択してください')` → return
- IDB レコードに `externalMethodType`、同期 body には `paymentMethod==='other_external'` のときだけ送る
- `APP_VERSION` を `20260421-pwa4d-2-hotfix1` に更新

**`public/admin/js/emergency-payment-ledger.js`**:
- `_externalMethodLabel(type)` で slug→日本語変換
- `_methodLabel(paymentMethod, externalMethodType)` を拡張し、`other_external` のみ括弧内で分類を表示
  - 例: 「その他外部（商品券）」「その他外部（未分類）」
- 詳細モーダルに「外部分類:」行を追加 (`paymentMethod==='other_external'` のときのみ)
- 未分類はグレー表記（エラーの赤にはしない）
- cache buster: `?v=20260421-pwa4d-2-hotfix1`

### 9.22.6 変更しない範囲

- `emergency-payment-transfer.php` の `other_external` 409 挙動
- `payments.payment_method` enum
- `api/store/process-payment.php`
- `api/store/register-report.php`
- `api/store/payments-journal.php`
- `api/store/refund-payment.php`
- `api/store/receipt.php`
- Service Worker
- 顧客画面 / ハンディ / KDS 注文画面
- POSLA PAY

### 9.22.7 既知の残件 (Phase 4d-3 以降)

- 既存 `other_external` レコード (今回 backfill なし) の手動分類 UI
- `other_external` の payments への実転記（`payments.external_method_type` 列が 4d-2b で用意済み）
- register-report / payments-journal の分類別集計
- 手入力記録 (`manual_entry_without_context`) の売上反映（4d-3 で同時設計）

---

## 9.23 Phase 4d-3: other_external の転記解禁 + 外部分類編集 + レジ分析内訳

### 9.23.1 目的

Phase 4c-2 で拒否していた `payment_method='other_external'` の transfer を、**分類済み（external_method_type あり）のときだけ** 解禁する。未分類は 409 のまま。`manual_entry_without_context` (orderIds 空) と合流会計 (table_id 空) は今回もスコープ外で従来通り拒否。

### 9.23.2 変更した API

#### `api/store/emergency-payment-transfer.php`
- `other_external` の 409 分岐を「未分類時のみ」に縮小
- トランザクション外で `hasEmergencyExtCol` / `hasPaymentsExtCol` 事前チェック。どちらか欠けているなら 409 MIGRATION_REQUIRED 相当
- `external_method_type` が allowlist (`voucher/bank_transfer/accounts_receivable/point/other`) 外または NULL なら 409 `unclassified_other_external`
- mapping: `other_external → payments.payment_method='qr'` に寄せ、`payments.external_method_type` に分類 slug を INSERT（ENUM 拡張回避）
- `note` に `term=` / `slip=` / `appr=` / `ext_type=xxx` を追記
- `cash_log` は従来通り `methodForPayments==='cash'` のみ → other_external では作らない
- `orders.payment_method='qr'` で既存 ENUM 内

#### `api/store/emergency-payment-ledger.php`
- `transferable_c` 条件を `(payment_method<>'other_external' OR (='other_external' AND external_method_type IN (...5値)))` AND `table_id あり` AND `orderIds>0` に拡張
- `hasExternalMethodTypePreCheck` が false のときは other_external 側を `0=1` で除外（migration 未適用時は従来挙動）

#### 新規 `api/store/emergency-payment-external-method.php`
- POST only / manager|owner / FOR UPDATE
- `payment_method!='other_external'` → 409
- 既に `synced_payment_id` あり → 409
- allowlist 外 → 400
- 同値は idempotent
- 監査ログ (`emergency_external_method_set`)
- `external_method_type` と `updated_at` のみ UPDATE

#### `api/store/register-report.php`
- `externalMethodBreakdown` を追加返却（`payments.external_method_type` で GROUP BY）
- 既存 `paymentBreakdown` (orders 基準) は**不変**
- 残リスク: `paymentBreakdown` の `qr` に other_external 転記分が混ざる点は既存仕様。補完表示として内訳セクションを追加

### 9.23.3 UI 変更

- `public/admin/js/emergency-payment-ledger.js`:
  - `_renderTransferBlock` で other_external を 4 分岐:
    - 未分類 → 分類 select + 「分類を記録」ボタン
    - 分類済み → 既存の転記ブロックに合流（「売上へ転記する」表示）
  - 転記確認ダイアログの `_methodLabel(paymentMethod, externalMethodType)` 呼び出し
- `public/admin/js/admin-api.js`:
  - `updateEmergencyExternalMethod(emergencyPaymentId, externalMethodType)` 追加
- `public/admin/js/register-report.js`:
  - 「外部分類別 (緊急会計転記)」セクション追加
  - 注意文「上の『支払方法別』では QR 決済に合算されます」を明記

### 9.23.4 変更しない範囲

- `payments.payment_method` ENUM（未変更）
- `orders.payment_method` ENUM（既存 qr を使用）
- `process-payment.php` / `refund-payment.php` / `payments-journal.php` / `receipt.php`
- Service Worker
- 顧客画面 / ハンディ / KDS 注文画面

### 9.23.5 既知の残件

- `manual_entry_without_context`（orderIds 空）の売上反映は対象外（専用設計が必要）
- 合流会計（table_id 空）も対象外
- 転記取消 / 売上取消は対象外（Phase 4d-4 以降）
- `paymentBreakdown` の `qr` に other_external 転記分が混ざる点は既存仕様のまま。補完として `externalMethodBreakdown` を別セクションで出す

---

## 9.24 Phase 4d-4a: 手入力売上計上

### 9.24.1 目的

緊急会計台帳の手入力記録（`orderIds` 空 / 注文紐付けなし）を通常 `payments` に INSERT し、取引ジャーナル・レジ分析・レジ残高に反映する。

**重要（既存仕様との差）**:
- **`sales-report.php` には出ない**。orders ベース集計のため手入力売上は含まれない。4d-5 以降で sales-report を payments ベースに統合する際に解消予定。
- **`register-report.php` には反映**。現金は `cash_log.cash_sale`、外部分類は `payments.external_method_type` 経由で `externalMethodBreakdown` に出る。
- **`payments-journal.php` には反映**。payments 直接 SELECT なので自動的に出る。
- **`refund-payment.php` は構造上利用不可**。手入力売上は `gateway_name=NULL` / `gateway_status!=succeeded` なので既存の NO_GATEWAY 400 で弾かれる。現金は CASH_REFUND 400。4d-5 以降で専用 void API を用意する予定。

### 9.24.2 新規 API

`POST /api/store/emergency-payment-manual-transfer.php`

```
body: { store_id, emergency_payment_id, note? }
```

対象条件（全て AND）:
- `resolution_status='confirmed'`
- `synced_payment_id IS NULL`
- `status != 'failed'`
- `total_amount > 0`
- `order_ids_json NULL または JSON_LENGTH=0`
- `payment_method` は `cash/external_card/external_qr/other_external`
- `other_external` は `external_method_type` が allowlist（voucher/bank_transfer/accounts_receivable/point/other）

動作:
- TX + FOR UPDATE で対象 `emergency_payments` をロック
- `synced_payment_id` 既存なら `{idempotent:true}` で 200（INSERT/UPDATE なし）
- `payments` INSERT:
  - `order_ids='[]'` / `table_id=NULL` / `session_id=NULL` / `is_partial=0`
  - `payment_method`: cash→cash, external_card→card, external_qr→qr, **other_external→qr**
  - `external_method_type`: other_external 時のみ分類 slug
  - `gateway_name/external_payment_id/gateway_status` は NULL 維持
  - `paid_at`: `client_created_at → server_received_at → NOW` の順
  - `user_id`: PIN 検証済 `staff_user_id` が同 tenant なら staff_user_id、そうでなければ転記者
  - `note`: `emergency_manual_transfer src=<local_id>` + term/slip/appr/ext_type/任意 note を 200 文字内
- cash のみ `cash_log(type='cash_sale', created_at=$paidAt)` INSERT
- `emergency_payments.synced_payment_id` と `transferred_by_user_id/name/at/note` を更新
- 監査ログ (`emergency_manual_transfer`) を残す（`write_audit_log` 関数があれば）

やらないこと:
- `orders` / `order_items` / `tables` / `table_sessions` / `emergency_payment_orders` の変更
- `payments.payment_method` ENUM 拡張
- `cash_log.type` ENUM 拡張
- `process-payment.php` / `refund-payment.php` / `sales-report.php` / `receipt.php` の呼び出し
- `emergency-payment-transfer.php` の呼び出し

### 9.24.3 ledger.php 拡張

- summary に `manualTransferableCount` を追加
- 条件は上記 API の対象条件と同一
- 既存 `transferableCount`（注文紐付き専用）とは排他

### 9.24.4 UI 変更

#### 台帳一覧
- KPI タイル「手入力計上可能」を追加（`#6a1b9a` 紫系で「転記可能」と視覚的に区別）

#### 詳細モーダル
- 手入力モード（orderIds 空）の分岐を更新:
  - 未 confirmed → 既存注意文維持
  - confirmed かつ未計上 かつ other_external 未分類 → 分類を先に記録するよう促す注意文
  - confirmed かつ未計上 かつ対象 payment_method → **「売上として計上する（手入力）」ボタン**（紫 `#6a1b9a`）
  - 確認ダイアログに「売上レポートには出ません。取引ジャーナル・レジ分析・レジ残高に反映されます」「後日の取消は 4d-5 以降で設計予定」を明記
- 409 時はエラー表示 + 再読込誘導（他管理者が先行処理した可能性ヒント）

### 9.24.5 変更しないファイル

- `emergency-payment-transfer.php`（注文紐付き経路はそのまま）
- `process-payment.php` / `refund-payment.php` / `receipt.php` / `sales-report.php` / `register-report.php`
- `payments.payment_method` ENUM / `cash_log.type` ENUM
- Service Worker / 顧客画面 / ハンディ / KDS 注文画面

### 9.24.6 既知の残件（Phase 4d-5 以降）

- **売上取消 / 転記取消**: `payments.void_status` 論理取消 or reversal payment。cash_log 相殺行も込みで設計
- **sales-report の payments 統合**: 手入力売上を売上レポートにも出す
- **receipt での手入力明細対応**: 現状 `paid_items` があれば発行できるが、明細入力 UI は未整備
- **resolution 変更ロック**: 計上済み（`synced_payment_id` あり）の記録を rejected / duplicate に戻せてしまう既存挙動の整理（4d-4a UI 側は 409 になる想定だがサーバー側ガードはなし）

### 9.24.7 検証結果（2026-04-21 本番実行）

| シナリオ | 期待 | 実結果 |
|---|---|---|
| cash 手入力 confirm → manual-transfer | 200 / paymentId 発番 / cash_log.cash_sale 1 行 / external_method_type=NULL | ✅ paymentId=885396f3…, cash_log 220 円 |
| 同じ ID で再 POST | idempotent:true / 同じ paymentId | ✅ |
| other_external+voucher confirm → manual-transfer | 200 / payment_method='qr' / external_method_type='voucher' | ✅ paymentId=68d6ef70… / 330 円 |
| other_external 未分類 confirm → manual-transfer | 409 unclassified_other_external | ✅ |
| 既 synced (注文紐付き 4c-2 転記済み) を manual | idempotent:true で既存 paymentId | ✅ |
| payments-journal 反映 | 両行表示 | ✅ |
| register-report.cashLog 反映 | cash 手入力 1 行 | ✅ 220 円 |
| register-report.externalMethodBreakdown 反映 | voucher 1 件 / 330 円 | ✅ |
| sales-report 反映 | **出ない（仕様）** | ✅ 未表示を確認 |

---

## 9.25 Phase 4d-5a: 手入力計上の void (論理取消)

### 9.25.1 目的

Phase 4d-4a で作成した手入力計上 payments（`emergency-payment-manual-transfer.php` 由来）を、管理者が論理取消できるようにする。**通常会計 payments と注文紐付き emergency transfer は対象外**で、API 側で 409 `NOT_VOIDABLE_IN_4D5A` で拒否する。

### 9.25.2 DB 変更

新規: `sql/migration-pwa4d5a-payment-void.sql`

```sql
ALTER TABLE payments
  ADD COLUMN void_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER refunded_by,
  ADD COLUMN voided_at DATETIME DEFAULT NULL AFTER void_status,
  ADD COLUMN voided_by VARCHAR(36) DEFAULT NULL AFTER voided_at,
  ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL AFTER voided_by,
  ADD KEY idx_pay_void_status (store_id, void_status, paid_at);
```

- `void_status`: **VARCHAR(20)**（ENUM ではない）。4d-5b/c で状態追加しやすいため
- 既存行は `DEFAULT 'active'` で埋まる（backfill 不要）
- INFORMATION_SCHEMA + PROCEDURE で冪等

### 9.25.3 新規 API

`POST /api/store/emergency-payment-manual-void.php`

```
body: { store_id, emergency_payment_id, reason }
```

- POST only / manager|owner / TX + FOR UPDATE
- `reason` は必須（1〜255 文字）
- 対象条件: `synced_payment_id IS NOT NULL` AND `payments.order_ids='[]' 相当` AND `payments.note に emergency_manual_transfer を含む` AND `payments.void_status='active'`
- **二重ガード**: `order_ids=[]` AND `note LIKE '%emergency_manual_transfer%'` の両方で通常会計との誤取消を防止
- UPDATE: `payments.void_status='voided'` + `voided_at/by/reason`
- cash の場合のみ `cash_log(type='cash_out')` 相殺行を INSERT（ENUM 拡張不要、既存 `cash_out` を流用）
- `emergency_payments.synced_payment_id` は**残す**（履歴保持）
- `emergency_payments.transfer_note` に `[voided at YYYY-MM-DD HH:MM by xxx]` を追記
- idempotent: 既に voided なら `{idempotent:true}` で 200

### 9.25.4 既存 API 変更

| API | 変更 |
|---|---|
| `emergency-payment-ledger.php` | records に `paymentVoidStatus` / `paymentVoidedAt` / `paymentVoidReason` を bulk SELECT で追加（N+1 回避、store_id 境界二重チェック）。レスポンスに `hasPaymentVoidColumn` |
| `register-report.php` | `externalMethodBreakdown` の SQL に `AND void_status <> 'voided'` を動的追加（migration 未適用時は除外条件なし） |
| `payments-journal.php` | `void_status` / `voided_at` / `void_reason` を動的に SELECT に含めて返却 |
| `refund-payment.php` | **触らない**（4d-5a では手入力売上の refund 対応は元々不可のため、ALREADY_VOIDED チェック追加も不要）|
| `receipt.php` | **触らない**（既存の手入力 receipt 再印刷を壊さないため、void 状態チェックは入れない）|
| `sales-report.php` | **触らない**（手入力は元々 orders ベース集計に出ないため影響なし）|
| `process-payment.php` / `emergency-payment-transfer.php` / `emergency-payment-manual-transfer.php` | 変更なし |

### 9.25.5 UI 変更

`public/admin/js/emergency-payment-ledger.js`:
- 転記済み分岐内で分岐:
  - `paymentVoidStatus='voided'` → 「✗ 取消済み」赤バッジ + 取消日時 / 理由表示
  - 未 void かつ `orderIds.length === 0`（手入力計上） → 「**手入力計上を取り消す**」ボタン（赤 `#c62828`）+ 取消理由入力 textarea
  - 未 void かつ注文紐付き（transfer 経由） → ボタン非表示（4d-5b 以降）
- 一覧行の日時列に「取消済」バッジ追加（薄赤 `#ffebee`）
- 確認ダイアログに「payments は論理取消」「現金は cash_log に cash_out 相殺」「sales-report には元々出ない」「synced_payment_id は履歴として残る」を明記
- `admin-api.js` に `voidManualEmergencyPayment(id, reason)` 追加
- cache buster: `?v=20260421-pwa4d-5a`（admin-api.js / emergency-payment-ledger.js 両方）

### 9.25.6 変更しない範囲

- 通常会計 payments の void（4d-5c へ）
- 注文紐付き emergency transfer の void（4d-5b へ）
- `orders.status` の変更
- `table_sessions` の変更
- `refund-payment.php` / `receipt.php` / `sales-report.php` / `process-payment.php`
- `payments.payment_method` ENUM / `cash_log.type` ENUM
- `emergency_payments.synced_payment_id` の NULL 戻し
- 物理 DELETE
- SW / 顧客画面 / ハンディ / KDS 注文画面

### 9.25.7 検証結果（2026-04-21 本番実行）

| # | シナリオ | 期待 | 実結果 |
|---|---|---|---|
| 1 | reason 空 | 400 VALIDATION | ✅ |
| 2 | 存在しない ID | 404 NOT_FOUND | ✅ |
| 3 | 注文紐付き transfer | 409 NOT_VOIDABLE_IN_4D5A | ✅ |
| 4 | 手入力 cash void | voided=true + cash_log.cash_out 1 行 | ✅ `cashLogId=5242c748…` / `amount=220` |
| 5 | 二重 void | idempotent:true / cash_log 重複なし | ✅ |
| 6 | 手入力 voucher void | voided=true / cashLogId=null | ✅ |
| 7 | ledger レスポンス | `paymentVoidStatus='voided'` / `paymentVoidedAt` / `paymentVoidReason` 返却 | ✅ 2 件 |
| 8 | payments-journal レスポンス | `void_status='voided'` 返却 | ✅ 2 件 |
| 9 | register-report.externalMethodBreakdown | voided 除外で `[]` に戻る | ✅ |
| 10 | register-report.cashLog | cash_sale 手入力 1 行 + cash_out 相殺 1 行 | ✅（期待残高差分 0）|

### 9.25.8 既知の残件（Phase 4d-5b 以降）

- **注文紐付き emergency transfer の void**: `orders.status` を `cancelled` に戻すか、`voided_payment_id` で新カラム追加するか要設計
- **通常会計 payments の void**: `sales-report` の payments ベース統合と同時または後に実装
- `receipt.php` の void 済み発行可否判定（現状は発行可能、別途ポリシー検討）
- `refund-payment.php` との衝突防止条件追加（4d-5b で gateway 経由の refund を扱う際に）
- `cash_out` 相殺の「現金売上 KPI」からの引き算（現状は期待残高で相殺されるが `cashSales` KPI は純加算のまま）

---

## 9.26 Phase 4d-5b: 注文紐付き緊急会計転記の void (orders.status は維持)

### 9.26.1 目的

Phase 4c-2 の `emergency-payment-transfer.php` 経由で作成された注文紐付き payments を論理取消可能にする。**accounting void と operational reopen を分離**する設計：`payments.void_status='voided'` は記録するが、`orders.status='paid'` / `table_sessions` / `tables.session_token` は**戻さない**。

### 9.26.2 設計判断（A 案採用）

- **B 案（orders.status を戻す）は採用しない**：`api/kds/orders.php:119` (`status != 'paid'` で除外) / `api/store/tables-status.php:111` (`status NOT IN ('paid','cancelled')` で除外) / `api/store/process-payment.php:559` (分割会計判定) が orders.status='paid' を暗黙の前提にしているため、戻すと **KDS 再表示 / 卓状態復活 / 分割会計再流入** の運用事故が起きる
- **C 案（orders.voided_payment_id 列追加）も今回は不採用**：合流会計で 1 orders : N payments の関係になるため、4d-5c（通常会計 void）と同時に設計するのが合理的
- **A 案** = payments voided + orders.status は paid 維持。**残リスクとして sales-report.php / turnover-report.php に売上が残る**点を UI/docs で明示

### 9.26.3 新規 API

`POST /api/store/emergency-payment-transfer-void.php`

```
body: { store_id, emergency_payment_id, reason }
```

- POST only / manager|owner / TX + FOR UPDATE
- `reason` 必須（1〜255 文字）

**三重ガード**で対象を「4c-2 経路由来の注文紐付き payments のみ」に絞る:
1. `emergency_payments.synced_payment_id IS NOT NULL` AND `JSON_LENGTH(order_ids_json) > 0`
2. `payments.order_ids` の `JSON_LENGTH > 0`
3. `payments.note LIKE '%emergency_transfer src=%'` AND `payments.note NOT LIKE '%emergency_manual_transfer%'`

これに当たらない場合（手入力計上 / 通常会計 payments）は `409 NOT_VOIDABLE_IN_4D5B`。

**処理**:
- `payments.void_status='voided'` + `voided_at/by/reason` UPDATE
- `payment_method='cash'` のみ `cash_log.cash_out` 相殺行を INSERT（note='緊急会計転記取消 payment_void:<payment_id>'）
- `emergency_payments.transfer_note` に `[voided at YYYY-MM-DD HH:MM:SS by xxx]` 追記（255 文字制限内）
- `orders` / `table_sessions` / `tables.session_token` / `synced_payment_id` は**触らない**
- 監査ログ `emergency_transfer_void`（optional）

**idempotent**: 既 voided は `{idempotent:true}` 即返却。

### 9.26.4 既存 API 変更

| API | 変更 |
|---|---|
| `emergency-payment-ledger.php` | **不変**（4d-5a の `paymentVoidStatus/paymentVoidedAt/paymentVoidReason` が transfer 分にも自動的に効く） |
| `payments-journal.php` | **不変**（4d-5a で void_status 表示済み） |
| `register-report.php` | **不変**（4d-5a の `externalMethodBreakdown` 除外条件で transfer 分も対応） |
| その他 (process-payment / transfer / manual-transfer / refund / receipt / sales-report / turnover-report) | **すべて不変** |

### 9.26.5 UI 変更

`public/admin/js/emergency-payment-ledger.js`:
- 詳細モーダル「転記済み」分岐内で、`orderIds.length > 0 && !isManualOnly` の場合に「**緊急会計転記を取り消す**」ボタンを表示
- 配色: **オレンジ `#ef6c00`**（手入力 void の赤 `#c62828` と区別）
- 確認ダイアログに以下を**必ず明記**:
  - payments は論理取消される
  - 現金は cash_log で相殺される
  - **orders.status は paid のまま維持**
  - **売上レポート・回転率レポートには引き続き残る**
  - テーブルセッション/QR は戻らない
  - synced_payment_id は履歴として残る
  - 後から戻せない
- void 済みは既存の「✗ 取消済み」赤バッジを再利用
- 一覧行の「取消済」バッジも 4d-5a 既存を流用

`public/admin/js/admin-api.js`: `voidTransferEmergencyPayment(id, reason)` 追加
`public/admin/dashboard.html`: `?v=20260421-pwa4d-5b` × 2

### 9.26.6 変更しない範囲（responsibility 分離）

- `process-payment.php` / `emergency-payment-transfer.php` / `emergency-payment-manual-transfer.php`
- `emergency-payment-manual-void.php`（4d-5a の手入力 void、本 API とは独立）
- `refund-payment.php` / `receipt.php` / `sales-report.php` / `turnover-report.php`
- `payments.payment_method` ENUM / `cash_log.type` ENUM
- 新規 migration（4d-5a の `payments.void_status` カラム群を流用）
- `orders.status` / `orders.paid_at`
- `table_sessions.status` / `table_sessions.closed_at`
- `tables.session_token` / `tables.session_token_expires_at`
- `emergency_payments.synced_payment_id` の NULL 戻し
- 物理 DELETE
- SW / 顧客画面 / ハンディ / KDS 注文画面

### 9.26.7 検証結果（2026-04-21 本番実行）

| # | シナリオ | 期待 | 実結果 |
|---|---|---|---|
| 1 | 未認証 API | 401 | ✅ |
| 2 | reason 空 | 400 VALIDATION | ✅ |
| 3 | 存在しない ID | 404 NOT_FOUND | ✅ |
| 4 | 手入力計上 EP（orderIds=0） | 409 NOT_VOIDABLE_IN_4D5B | ✅ 4d-5a へ誘導メッセージ |
| 5 | 注文紐付き transfer cash void | voided=true / cash_log.cash_out 1 行 / `ordersStatusPreserved:true` | ✅ `cashLogId=d1f7d750…` |
| 6 | 二重 void | idempotent:true | ✅ |
| 7 | 注文紐付き transfer card void | voided=true / `cashLogId=null` | ✅ |
| 8 | orders.status 不変 | `status='paid'` のまま | ✅ 2 件とも維持 |
| 9 | table_sessions 不変 | 4c-2 で `paid` 化された行が残ったまま | ✅ |
| 10 | tables.session_token 不変 | 4c-2 で再生成された値が残ったまま | ✅ |
| 11 | ledger に paymentVoidStatus='voided' | manual 2 件 + transfer 2 件 = 計 4 件 | ✅ |
| 12 | register-report.cashLog | cash_out 相殺 1 行 (1850 円) | ✅ |
| 13 | register-report.externalMethodBreakdown | voucher は 4d-5a 経由で除外、card 系も void で除外 | ✅ |
| 14 | sales-report 残留 | revenue / order_count に残る（仕様） | ✅ |

### 9.26.8 既知の残リスク（Phase 4d-5c 以降の実装状況）

| 項目 | 状況 |
|---|---|
| `sales-report.php` / `turnover-report.php` に void 済み売上が残る | ✅ **4d-5c-a で解消**（§9.27）|
| `orders.payment_method` 別集計（register-report.paymentBreakdown）にも残る | ✅ **4d-5c-a で解消**（§9.27）|
| `receipt.php` で voided 分の再印刷可能（拒否ガード未追加） | ✅ **4d-5c-ba で POST 新規発行のみ 409 `VOIDED_PAYMENT`**、GET 再印刷は意図的に許可（§9.28）|
| `refund-payment.php` で ALREADY_VOIDED 拒否未追加 | ✅ **4d-5c-ba で 409 `ALREADY_VOIDED` を先頭ガードとして追加**（§9.28） |
| 通常会計 payments の void は対象外 | ✅ **4d-5c-ba (cash) + 4d-5c-bb-A (手動 card/qr) で実装**（§9.28 / §9.29） |

### 9.26.9 Phase 4d-5c 推奨スコープの実装状況

1. `sales-report.php` を「有効 payment が 1 件も無い orders は除外」ロジックで統合 → ✅ **4d-5c-a 実装済**（§9.27）
2. `turnover-report.php` も同様 → ✅ **4d-5c-a 実装済**（§9.27）
3. 通常会計 payments（`process-payment.php` 経由）の void API → ✅ **4d-5c-ba 実装済**（§9.28）
4. `refund-payment.php` に ALREADY_VOIDED ガード追加 → ✅ **4d-5c-ba 実装済**（§9.28）
5. `receipt.php` の voided 拒否ポリシー策定 → ✅ **4d-5c-ba 実装済**（POST 拒否 / GET 再印刷許可、§9.28）
6. `register-report.paymentBreakdown` の void 除外 → ✅ **4d-5c-a + 4d-5a 複合で対応済**（§9.27 / §9.25）

残スコープ（別スライス）:
- **分割会計 (is_partial=1) の void**（論点 B、未着手）
- **takeout 注文の void**（論点 C）— 4d-5c-bb-C で **POS cashier 経由の takeout (cash + no gateway + is_partial=0 + status='paid')** を void 可能に拡張済み（§9.31）。online takeout (status='served' / gateway 経由) は引き続き対象外
- **返金チェーン**（論点 D、実データ 0 件のためスコープ外で確定）

---

## 9.27 Phase 4d-5c-a: 売上系レポートから「全 voided 注文」を除外

### 9.27.1 目的

Phase 4d-5b 後も `sales-report.php` / `turnover-report.php` / `register-report.paymentBreakdown` は `orders.status='paid'` ベースのため、payments を全部 void しても売上に残っていた。本フェーズで **「注文に紐づく payments が 1 件も active でなければ、その orders を集計から除外する」** ガードを 3 API に追加する。

### 9.27.2 設計判断（A 案サブセット）

- **payments ベース統合 (B 案)** や **正規化 (C 案)** は影響範囲大のため 4d-5c-c 以降へ
- **A 案サブセット**で migration なし / UI 不変 / API 3 本だけの最小変更
- 分割会計の「一部 voided」は active が 1 件残れば集計に残す（**現状全額計上を維持**、運用影響を最小化）
- cooking analysis / table_sessions ベース回転率 / cashLog / externalMethodBreakdown は**触らない**

### 9.27.3 共通 SQL 句

```php
$voidExclude = $hasVoidCol
  ? ' AND NOT (
        EXISTS (SELECT 1 FROM payments p_any
                 WHERE p_any.store_id = o.store_id
                   AND JSON_CONTAINS(p_any.order_ids, JSON_QUOTE(o.id)))
        AND NOT EXISTS (SELECT 1 FROM payments p_act
                         WHERE p_act.store_id = o.store_id
                           AND JSON_CONTAINS(p_act.order_ids, JSON_QUOTE(o.id))
                           AND (p_act.void_status IS NULL OR p_act.void_status <> \'voided\')))'
  : '';
```

- **「有効 payment」判定**: `void_status IS NULL OR void_status <> 'voided'`（4d-5a migration 未適用環境 + 既存 active 行を全て active 扱い）
- **payments 未紐付 orders は除外しない**（緊急会計外の通常運用を壊さないため）
- migration-pwa4d5a 未適用環境では `$voidExclude=''` で従来通り

### 9.27.4 変更箇所

| ファイル | 変更 |
|---|---|
| `api/store/sales-report.php` | 基本集計 SQL + 全注文 SELECT に `o` エイリアス整備 + 共通 NOT EXISTS 句追加 |
| `api/store/turnover-report.php` | hourly / customer spend / priceBand の 3 SQL に同条件追加。**cooking analysis / table_sessions 回転率は触らない** |
| `api/store/register-report.php` | `paymentBreakdown` SQL に `o` エイリアス + 同条件追加。`cashLog` / `externalMethodBreakdown` は不変 |

### 9.27.5 変更しない範囲

- `process-payment.php` / `emergency-payment-transfer.php` / `emergency-payment-manual-transfer.php`
- `emergency-payment-manual-void.php` / `emergency-payment-transfer-void.php`
- `refund-payment.php` / `receipt.php` / `payments-journal.php`
- `customer/checkout-confirm.php` / `customer/takeout-payment.php`
- `cash_log.type` ENUM / `payments.payment_method` ENUM
- 新規 migration なし
- UI（`sales-report.js` / `turnover-report.js` / `register-report.js`）変更なし
- cache buster 不要
- SW / 顧客画面 / ハンディ / KDS

### 9.27.6 検証結果（2026-04-21 本番実測）

| # | シナリオ | 期待 | 実結果 |
|---|---|---|---|
| 1 | 未認証 | 401 | ✅ 全 3 API |
| 2 | 構文 | 全 OK | ✅ |
| 3 | **2026-04-20 の sales-report**（4d-5b で void 済 2 件のみ）| 全部 voided なので空 | ✅ orderCount=0 / totalRevenue=0 / itemRanking=[] / tableSales=[] |
| 4 | **2026-04-20 の turnover-report** | hourly=空 / priceBands すべて 0 | ✅ |
| 5 | **2026-04-20 の register-report** | paymentBreakdown=[] / externalMethodBreakdown=[] | ✅ |
| 6 | 2026-04-01〜04-21 範囲（active 多数 + voided 2 件）| 88 件 / ¥151,570（void 2 件除外後）| ✅ T01 72/¥124,100 など正常表示 |
| 7 | DB 整合性: `pay_active=0` の orders 数 vs API レスポンス | 一致 | ✅ DB visible_in_report=0、API orderCount=0 |

### 9.27.7 現時点で残るリスク（2026-04-21 時点）

| # | リスク | 対応予定 |
|---|---|---|
| 1 | **分割会計 (is_partial=1) の「一部 voided」は売上集計に全額残る**（active が 1 件でもあれば計上される現状仕様）| 論点 B（§9.29.2）で別途議論。4d-5c-c の分割 void 設計とセット |
| 2 | JSON_CONTAINS の性能（本番 112 行 → 大規模化時）| 4d-5c-c の `payment_order_links` 正規化テーブル導入で対応 |
| 3 | `itemRanking` の `paid_items` ベース明細と voided 注文の整合性（既発行レシート側の履歴）| receipt 新規発行は 4d-5c-ba で 409 `VOIDED_PAYMENT` に落ちるため、voided 注文から新規 `paid_items` エントリは作られない。既発行レシートの `paid_items` 参照は影響なし。運用上の現状問題なし |

### 9.27.8 4d-5c-b 系（→ 4d-5c-ba / 4d-5c-bb-A）で解消した旧リスク

以下は 4d-5c-a リリース時点では「残リスク」として扱われていたが、**すべて 4d-5c-ba 以降で解消済み**。

| 旧リスク項目 | 解消した時期 | 参照 |
|---|---|---|
| 通常会計 void API が存在しない | 4d-5c-ba で `api/store/payment-void.php` 新設 | §9.28 |
| `refund-payment.php` に voided 判定が未追加 | 4d-5c-ba で先頭 409 `ALREADY_VOIDED` 追加 | §9.28 |
| `receipt.php` の voided 発行ポリシーが未策定 | 4d-5c-ba で策定（POST 新規発行は 409 `VOIDED_PAYMENT` / GET 再印刷は許可） | §9.28 |
| emergency void 系と通常会計 void の境界が曖昧 | 4d-5c-ba のガード (`note NOT LIKE 'emergency_%'` / `synced_payment_id IS NULL`) で確定 | §9.28 |
| 通常会計 void が cash 限定 | 4d-5c-bb-A で `payment_method ∈ {'cash','card','qr'}` に拡張（gateway 非通過のみ） | §9.29 |

### 9.27.9 未着手の別スライス（残スコープ確定）

- **論点 B: 分割会計 void** — 同一 order に複数 payments 行。個別/一括 void の意味論と orders.status の扱いを要合意
- **論点 C: takeout 注文 void** — 前提バグ（`takeout-payment.php` の `payment_method='online'` ENUM mismatch）は 2026-04-21 4d-5c-bb-A-post1 で解消済み（§9.30）。本体も 2026-04-21 4d-5c-bb-C で POS cashier 経由 takeout cash/no-gateway/paid の範囲を実装済み（§9.31）。online takeout (status='served') は別スライス
- **論点 D: 返金チェーン** — 実データ 0 件のためスコープ外で確定（仕様先行を避ける）

---

## 9.28 Phase 4d-5c-ba 通常会計 void（cash + 注文紐付き + 三重ガード + 補正(2)）

### 9.28.1 目的

`process-payment.php` 経由で作成された通常会計 `payments` のうち、**cash + gateway_name IS NULL + is_partial=0 + dine_in/handy** の組合せだけを論理取消できる新規 API `payment-void.php` を追加する。**A 案踏襲**: payments voided のみ、`orders.status='paid'` / `table_sessions` / `tables.session_token` は触らない（KDS 再表示・卓復活の事故回避）。

### 9.28.2 新規 API

`POST /api/store/payment-void.php`

- manager / owner / reason（1-255 文字）必須
- body: `{ store_id, payment_id, reason }`
- 409 エラーコード（抜粋）:
  - `NOT_VOIDABLE_IN_4D5C_BA` (order_ids 空 / emergency_transfer / emergency_manual_transfer / synced_payment_id あり)
  - `NOT_VOIDABLE_REFUNDED` (refund_status != none)
  - `NOT_VOIDABLE_METHOD` (cash 以外 — **4d-5c-bb-A で削除**、§9.29)
  - `NOT_VOIDABLE_GATEWAY` (gateway_name != '')
  - `NOT_VOIDABLE_PARTIAL` (is_partial=1)
  - `NOT_VOIDABLE_ORDER_MISSING` / `NOT_VOIDABLE_ORDER_STATUS` / `NOT_VOIDABLE_ORDER_TYPE`
- idempotent: 既に voided 済は 200 で `voided:false, idempotent:true`

### 9.28.3 三重ガード + 補正(2)

| ガード | 目的 |
|---|---|
| `order_ids JSON_LENGTH > 0` | 注文紐付きの通常会計のみ対象（手入力計上除外） |
| `note NOT LIKE 'emergency_%'` | 緊急会計転記系を除外（4d-5a/5b へ誘導） |
| `synced_payment_id IS NULL` | 緊急会計連携 payments を除外 |
| 補正(2): 紐付き orders 全件が `status='paid'` かつ `order_type IN ('dine_in','handy')` | takeout / pending / cancelled を除外（別スライス） |

### 9.28.4 連動ガード（同時追加）

| ファイル | 変更 |
|---|---|
| `refund-payment.php` | voided payments に対する refund は**先頭で** 409 `ALREADY_VOIDED`（既存の NO_GATEWAY / CASH_REFUND より前に遮断） |
| `receipt.php` (POST) | voided payments への新規発行は 409 `VOIDED_PAYMENT`、refunded は 409 `REFUNDED_PAYMENT` |
| `receipt.php` (GET detail=1) | 既発行レシートの**再印刷は引き続き可能**（void_status を見ない。意図的）|

### 9.28.5 処理フロー

1. TX + `SELECT ... FOR UPDATE` で payments 対象行をロック（store_id 境界二重チェック）
2. 三重ガード + 補正(2) を順次適用（失敗時は 409 ROLLBACK）
3. `UPDATE payments SET void_status='voided', voided_at=NOW(), voided_by=?, void_reason=?`
4. `payment_method='cash'` の場合のみ `cash_log` に `cash_out` 相殺行を INSERT（note=`通常会計取消 payment_void:<id>`）
5. 監査ログ（`write_audit_log` が存在すれば optional）
6. COMMIT → JSON レスポンス（`paymentId`, `voided`, `idempotent`, `cashLogId`, `ordersStatusPreserved:true`）

### 9.28.6 UI

- `public/kds/js/cashier-app.js` の取引ジャーナルに **「会計取消」ボタン** + サマリ KPI `取消済: N 件 / -¥N` + **netTotal = total − refundTotal − voidedTotal**
- `canVoid` 条件（4d-5c-ba 時点）: `method==='cash' && !gatewayName && !isPartial && !isRefunded && !isVoided`
- voided 行は `✗ 取消済` 赤バッジ + `.ca-journal__item--voided` クラス
- `public/admin/js/admin-api.js` に `voidNormalPayment(paymentId, reason)` 追加
- cache buster: `?v=20260421-pwa4d-5c-ba`（`cashier.html` / `dashboard.html`）

### 9.28.7 変更しない範囲（4d-5c-ba 時点）

- `process-payment.php` / `emergency-payment-*.php` / `customer/takeout-payment.php`
- `orders.status` / `table_sessions` / `tables.session_token`（accounting void の責務外）
- `payments.payment_method` ENUM / `cash_log.type` ENUM
- 新規 migration なし（4d-5a の `void_status` カラム群を流用）

### 9.28.8 本番検証結果（2026-04-21 実測）

| # | シナリオ | 期待 | 実結果 |
|---|---|---|---|
| 1 | 未認証 | 401 | ✅ |
| 2 | cache-buster `v=20260421-pwa4d-5c-ba` | 200 + 新タグ | ✅ |
| 3 | card | 409 NOT_VOIDABLE_METHOD | ✅（※ 4d-5c-bb-A で条件変わる、§9.29）|
| 4 | is_partial=1 cash | 409 NOT_VOIDABLE_PARTIAL | ✅ |
| 5 | takeout cash | 409 NOT_VOIDABLE_ORDER_TYPE | ✅ |
| 6 | emergency note | 409 NOT_VOIDABLE_IN_4D5C_BA | ✅ |
| 7 | 正常 cash void（payment=5506a551, ¥900）| 200, voided=true, cashLogId 発行 | ✅ |
| 8 | refund on voided | 409 ALREADY_VOIDED | ✅ |
| 9 | receipt POST on voided | 409 VOIDED_PAYMENT | ✅ |
| 10 | receipt GET detail=1 | 200 | ✅ |
| 11 | sales-report / turnover-report / register-report 除外 | voided の 900 円が集計から落ちる | ✅ |

### 9.28.9 ロールバック

- `/home/odah/www/eat-posla/_backup_20260421e/*.bak` を 7 ファイル戻し
- 新規 `api/store/payment-void.php` を削除（バックアップなし＝新規）
- DB 改変なし（テスト中に u-test-001 の `cashier_pin_hash` を一時差し替えたが元 hash に復元済み）
- 既に voided の payments は DB 上に残るが、4d-5c-a のレポート除外で整合

---

## 9.29 Phase 4d-5c-bb-A 手動 card / 手動 qr の void 拡張

### 9.29.1 目的

4d-5c-ba の範囲を **`payment_method='cash' のみ` → `payment_method ∈ {'cash','card','qr'}`（ENUM 全値）**に拡張する最小スライス。対象は **gateway_name IS NULL（= 手動入力 card / qr、ゲートウェイ非通過）** のみ。Stripe Connect などゲートウェイ経由の決済は引き続き対象外で、返金（refund-payment）経路を使う。

### 9.29.2 スコープ判断（論点 A/B/C/D のうち A のみ着手）

- 論点 A: 手動 card / qr（gateway 非通過）→ **本スライス**
- 論点 B: 分割会計 (is_partial=1) → 未着手（同一 order に複数 payments 行、個別/一括 void の意味論要合意）
- 論点 C: takeout → POS cashier 経由の cash takeout は 4d-5c-bb-C で対応（§9.31）。online takeout (status='served'/gateway) は別スライス
- 論点 D: 返金チェーン → **スコープ外で確定**（本番 `refund_status != 'none'` は 0 件）

### 9.29.3 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/store/payment-void.php` | `NOT_VOIDABLE_METHOD` ブロック削除（`payment_method !== 'cash'` で 409 返す分岐）。`payments.payment_method` ENUM('cash','card','qr') で値は既に限定されているので追加ガードは不要。後続の `gateway_name` ガードでゲートウェイ経由は引き続き弾く |
| `public/kds/js/cashier-app.js` | 取引ジャーナルの `canVoid` 条件から `method==='cash'` を除去: `canVoid = !isRefunded && !isVoided && !gatewayName && !isPartial` |
| `public/kds/cashier.html` | cache buster `?v=20260421-pwa4d-5c-ba` → `?v=20260421-pwa4d-5c-bb-A` |

### 9.29.4 不変な動作

- 三重ガード + 補正(2)（`order_ids` / `note` / `synced_payment_id` / `order_type='dine_in|handy'` 等）→ **そのまま活用**
- gateway_name ガード → **そのまま残す**（stripe_connect など経由は 409 `NOT_VOIDABLE_GATEWAY` のまま）
- is_partial ガード → そのまま
- order_type ガード → そのまま
- `cash_log` INSERT は既に `$paymentMethodOnPayments === 'cash'` 条件で守られているため、手動 card / qr では発火しない（card/qr は元々 cash_log に計上しない現運用と整合）
- `refund-payment.php` / `receipt.php` の連動ガードは 4d-5c-ba で追加済み、そのまま適用

### 9.29.5 本番検証結果（2026-04-21 実測）

| # | シナリオ | 期待 | 実結果 |
|---|---|---|---|
| 1 | 未認証 | 401 | ✅ |
| 2 | cache-buster `v=20260421-pwa4d-5c-bb-A` | 200 + 新タグ | ✅ |
| 3 | card + stripe_connect | **409 NOT_VOIDABLE_GATEWAY**（4d-5c-ba では NOT_VOIDABLE_METHOD だった）| ✅ method ガード削除・gateway ガード活性の証跡 |
| 4 | is_partial=1 cash | 409 NOT_VOIDABLE_PARTIAL | ✅ |
| 5 | takeout cash | 409 NOT_VOIDABLE_ORDER_TYPE | ✅ |
| 6 | emergency note | 409 NOT_VOIDABLE_IN_4D5C_BA | ✅ |
| 7 | 手動 card 正常 void（payment=4b398179, ¥3150）| 200, voided=true, **cashLogId=null** | ✅ |
| 8 | orders.status 保全 | ef5b380b paid/dine_in 維持 | ✅ |
| 9 | cash_log に card の相殺行が入らない | 0 件 | ✅（設計通り） |
| 10 | refund on voided | 409 ALREADY_VOIDED | ✅（4d-5c-ba 由来） |
| 11 | receipt POST on voided | 409 VOIDED_PAYMENT | ✅（4d-5c-ba 由来） |
| 12 | sales-report / register-report / turnover-report 除外 | ¥3150 が集計から落ちる | ✅（sales orderCount -1, totalRevenue -3150） |

### 9.29.6 手動 qr の扱い

本番 DB に「qr + null gateway + 正常条件」のデータは 0 件（1 件存在する qr+null は `emergency_manual_transfer` note で order_ids=0 のため先の層で弾かれる）。コードは method 分岐を持たず `cash_log INSERT` も `=== 'cash'` 条件に閉じているため、手動 card で検証した非 cash 経路の動作（200, cashLogId=null）がそのまま手動 qr にも適用される設計。発生時に再確認する扱い。

### 9.29.7 ロールバック

- `/home/odah/www/eat-posla/_backup_20260421f/*.bak` から 3 ファイル（`payment-void.php.bak` / `cashier-app.js.bak` / `cashier.html.bak`）を戻す
- DB 改変なし（PIN 一時差し替えは元 hash に復元済み）
- 戻すと手動 card / qr の `canVoid` ボタンが消え、API でも 409 `NOT_VOIDABLE_METHOD` に戻る

---

## 9.30 Phase 4d-5c-bb-A-post1 takeout online payment ENUM mismatch 修正

### 9.30.1 目的

takeout の Stripe Checkout 決済完了時、`api/customer/takeout-payment.php` が `payments.payment_method='online'` を INSERT 試行していたが、`payments.payment_method` は `ENUM('cash','card','qr')` で `'online'` は含まれない。MySQL の `STRICT_TRANS_TABLES` sql_mode 下で `Data truncated for column 'payment_method'` PDOException が発生し、try/catch で握りつぶされていたため **オンライン takeout 決済の payments 行が永続的に欠落**していた。

論点 C（takeout void）の前提を壊す別バグとして先行修正する。

### 9.30.2 現物証跡

- `sql/schema.sql:453` / `sql/migration-c4-payments.sql:17` の `payment_method ENUM('cash','card','qr')` を確認
- 本番 DB `@@sql_mode = STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION`
- 本番 payments テーブルに `payment_method='online'` の行は **0 件**
- Stripe session memo を持つ takeout 注文 2 件（1 件 `pending_payment` 未完了、1 件 `paid` だが紐付き payments は POS 手動 cash）を確認。online 決済からの payments 行は一切残っていない
- 既存 `api/customer/checkout-confirm.php:276` も Stripe online 決済を `$paymentMethod = 'card'` で記録しており、同じ表現に揃う

### 9.30.3 変更内容（最小スライス）

| ファイル | 変更 |
|---|---|
| `api/customer/takeout-payment.php` | INSERT の `payment_method` リテラルを `"online"` → `"card"` に変更。冒頭に経緯コメント追加 |

schema 変更なし、migration なし、他ファイル無変更。

### 9.30.4 反映先と反映しない先

| 対象 | 反映 |
|---|---|
| takeout-payment.php の Stripe Connect / Direct 検証 | ❌ **不変**（verify_stripe_checkout、metadata 照合、gateway_name / external_payment_id / gateway_status の収集はそのまま）|
| orders.status='pending_payment' → 'pending' | ❌ **不変** |
| payments INSERT | ✅ `payment_method='card'` で成功。既存 card+gateway_name パターンと整合 |
| `takeout-orders.php` の `'online'` 判定（`ONLINE_PAYMENT_REQUIRED` など）| ❌ **不変**。アプリ層の受注フラグとして維持、ENUM には書き込まない |
| 既存 takeout paid 6 件（cash+null）| ❌ 影響なし。本修正は新規 INSERT のみに作用 |
| refund-payment.php | ❌ **不変**（`gateway_name='stripe_connect_terminal'` 分岐のみが Connect 専用路。`stripe_connect`（非 terminal）takeout の refund は Direct 経路にフォールスルーする既存挙動。**本スライスでは実証していない、別論点として切り出す**）|
| receipt.php / sales-report / turnover-report / register-report | ❌ **不変**。card + gateway_name は既存集計で扱える |
| 4d-5c-ba / 4d-5c-bb-A | ❌ 影響なし。新規 takeout card payment は `gateway_name` が付くため payment-void.php では `NOT_VOIDABLE_GATEWAY` で 409（論点 C 待ち、設計通り）|

### 9.30.5 本番検証結果（2026-04-21 実測）

| # | シナリオ | 期待 | 実結果 |
|---|---|---|---|
| 1 | `php -l` | 構文 OK | ✅ |
| 2 | simulated INSERT (`payment_method='card'` + `gateway_name='stripe_connect'`) → ROLLBACK | INSERT 成功 / SELECT でヒット / ROLLBACK 後 0 件 | ✅ |
| 3 | simulated INSERT (`payment_method='online'`) | ERROR 1265 Data truncated | ✅ 旧仕様の失敗を再現 |
| 4 | takeout-payment.php GET without order_id | 400 MISSING_ORDER | ✅ |
| 5 | takeout-payment.php GET 存在しない order_id | 404 NOT_FOUND | ✅ |
| 6 | takeout-payment.php GET 既に paid の既存 takeout order | 200 `payment_confirmed:true, status:"paid"` | ✅ |
| 7 | takeout-orders.php POST without body | 400 MISSING_STORE (`'online'` フラグ処理が不変) | ✅ |
| 8 | `php_errors.log` 直近の `Data truncated for column 'payment_method'` | 新規発生なし | ✅ 本修正後のログは clean |

### 9.30.6 未実証・別論点として切り出すもの

- **Stripe Connect takeout payment（`gateway_name='stripe_connect'`）の refund**: post1 時点では `refund-payment.php:185` の Connect 分岐が `stripe_connect_terminal` のみ特別扱いで、非 terminal Connect はフォールスルーで Direct 経路（tenant の `$gwConfig['token']`）を使う不整合があり **post1 では未実証**。→ 2026-04-21 **4d-5c-bb-D で解消済**（§9.32）。新 Pattern C (`create_stripe_destination_refund`) で Platform context + `reverse_transfer=true` + `refund_application_fee=true` の canonical destination charges refund 経路を追加し、takeout Connect Checkout で refund 成立を本番 test mode で実証
- takeout void 本体（論点 C）: 本前提バグ解消後も別スライスで検討

### 9.30.7 ロールバック

- `/home/odah/www/eat-posla/_backup_20260421h/takeout-payment.php.bak` から 1 ファイル戻し
- DB 改変なし
- 戻すと新規 takeout online 決済で再び payments 行が欠落するのみ（既存 payments は影響なし）

---

## 9.31 Phase 4d-5c-bb-C POS cashier 経由 takeout の void 拡張

### 9.31.1 目的

4d-5c-ba / 4d-5c-bb-A で対応した通常会計 void の対象を **`order_type='takeout'`** にも拡張する（論点 C の最小スライス）。ただし**混ぜるな危険**の原則で対象を明示的に絞り、online/gateway takeout には踏み込まない。

### 9.31.2 スコープ判断（責務境界）

| 論点 | 現物判定 | 本スライス |
|---|---|---|
| **A. POS cashier 経由 takeout** (`cash + null gateway + is_partial=0 + status='paid'`) | 本番 DB に 6 件実在、dine_in/handy と同じライフサイクル | ✅ **対応** |
| B. online takeout | post1 以降 `card + stripe/stripe_connect`、`status='served'` 終端（`takeout-management.php:87-92` の allowed 遷移に 'paid' なし）| ❌ status ガードで引き続き 409 |
| C. Stripe Connect 非 terminal refund | `refund-payment.php:185` は `stripe_connect_terminal` のみ Connect 特別扱い | ❌ 本スライス対象外 → **4d-5c-bb-D で解消** (§9.32) |
| D. receipt ポリシー | 4d-5c-ba で策定済み、takeout にも自動適用 | ❌ 変更なし |
| E. reports / journal | 4d-5c-a の NOT EXISTS 句が `JSON_CONTAINS(p.order_ids, JSON_QUOTE(o.id))` で takeout も自動対応 | ❌ 変更なし |

### 9.31.3 変更ファイル

| ファイル | 変更 |
|---|---|
| `api/store/payment-void.php` | `:310` の order_type ガードを `'dine_in'\|'handy'` → `'dine_in'\|'handy'\|'takeout'` に緩和。`:314` の 409 メッセージも更新。冒頭コメント `:14-35` / `:59` / `:275-280` を追随更新 |
| `public/kds/js/cashier-app.js` | `:1687-1698` の canVoid コメントに takeout を追加。**実行ロジックは不変**（`canVoid = !isRefunded && !isVoided && !gatewayName && !isPartial` は 4d-5c-bb-A のまま）|
| `public/kds/cashier.html` | cache buster `?v=20260421-pwa4d-5c-bb-A` → `?v=20260421-pwa4d-5c-bb-C` |

### 9.31.4 不変な動作（takeout にも 4d-5c-ba 原則をそのまま適用）

- 三重ガード（`order_ids JSON_LENGTH>0` / `note NOT LIKE 'emergency_%'` / `synced_payment_id IS NULL`）
- `void_status` 以外のカラム（`orders.status='paid'` / `paid_at` / `payment_method`）は**触らない**
- `table_sessions` / `tables.session_token` は**触らない**
- `payment_method='cash'` のときのみ `cash_log.cash_out` 相殺行 INSERT
- gateway ガード (`gateway_name != ''` → 409) は残るので online takeout は引き続き弾く
- is_partial ガードは残るので分割 takeout も引き続き弾く
- refund / receipt の 4d-5c-ba 連動ガード (ALREADY_VOIDED / VOIDED_PAYMENT) は takeout でも自動適用

### 9.31.5 本番検証結果（2026-04-21 実測）

| # | シナリオ | 期待 | 実結果 |
|---|---|---|---|
| 1 | `php -l` / `node --check` | OK | ✅ |
| 2 | 未認証 POST | 401 | ✅ |
| 3 | cache-buster `v=20260421-pwa4d-5c-bb-C` | 200 + 新タグ | ✅ |
| 4 | 正常系: takeout cash paid void (payment=53cfd0e2, ¥950, order=7ff021cd) | 200, voided=true, cashLogId 発行 | ✅ cashLogId=b5f723e1 |
| 5 | orders 保全 | order 7ff021cd takeout/paid 維持 | ✅ |
| 6 | cash_log 相殺行 | cash_out ¥950 が note='通常会計取消 payment_void:53cfd0e2...' で INSERT | ✅ |
| 7 | 異常系: card + stripe_connect | 409 NOT_VOIDABLE_GATEWAY | ✅ |
| 8 | 異常系: is_partial=1 cash | 409 NOT_VOIDABLE_PARTIAL | ✅ |
| 9 | 異常系: takeout に一時 'served' 差し替えで void 実行 | 409 NOT_VOIDABLE_ORDER_STATUS | ✅（DB flip → test → 復元済み） |
| 10 | idempotent 再叩き | 200 voided:false idempotent:true | ✅ |
| 11 | 連動: refund on voided takeout | 409 ALREADY_VOIDED (E6027) | ✅ |
| 12 | 連動: receipt POST on voided takeout | 409 VOIDED_PAYMENT (E6025) | ✅ |
| 13 | sales-report 2026-04-18 除外確認 | voided takeout ¥950 集計除外、残 dine_in ¥1000 のみ | ✅ orderCount=1 / totalRevenue=1000 |
| 14 | register-report 2026-04-21 cashLog | cash_out ¥950 エントリ出現 | ✅ |
| 15 | turnover-report 2026-04-18 priceBands | 除外反映 | ✅ over1000: 1件/¥1000 / mid: 0件/¥0 |

### 9.31.6 DB 一時改変と復元

- 異常系 #9 で `order.id=50bc9dfb-6a10-49c4-b6ea-5ad8d047043f` を一時 `status='paid' → 'served'` に差し替え → テスト後 `'paid'` に復元（本来は別の takeout 注文を検証に使い、本スライス対象外）
- 連動 API 検証のため `u-test-001.cashier_pin_hash` を PIN `987654` の hash に一時差し替え → 元 hash `$2y$10$YP96p/kH3PgFCQ8iEchXCuoI88C5dfA2SCQYIKGuDVyzjgKAYwZou` に復元
- いずれも復元済み（現物確認済み）

### 9.31.7 残永続痕跡（意図）

- `payment 53cfd0e2-c303-41cb-bee2-7f4425616a5e` — `void_status='voided'`, `voided_at='2026-04-21 14:58:50'`, `voided_by=u-owner-001`
- `cash_log b5f723e1-dea4-448b-8720-28a28ae0d3e9` — `cash_out ¥950` 相殺行
- これらは実動作証跡として残置。紐付き `order 7ff021cd-d088-49cf-9c9e-c8d7bb3edc04` は `status='paid'` 維持（accounting void 原則）

### 9.31.8 未着手（別スライス、本回対象外）

- online takeout (`card + stripe/stripe_connect`) の void（`status='served'` 終端を考慮した lifecycle 設計が必要）
- Stripe Connect 非 terminal refund 経路の実証 → **4d-5c-bb-D で実証済** (§9.32)
- ~~論点 B: 分割会計 (is_partial=1) の void~~ → 2026-04-21 **意味論未確定のため実装前停止、docs 固定**（§9.36）
- 論点 D: 返金チェーン（実データ 0 件でスコープ外確定）

### 9.31.9 ロールバック

- `/home/odah/www/eat-posla/_backup_20260421i/*.bak` から 3 ファイル（`payment-void.php.bak` / `cashier-app.js.bak` / `cashier.html.bak`）戻し
- 既に voided の takeout payment は DB 上に残るが、4d-5c-a のレポート除外はそのまま継続（追加被害なし）
- 戻すと takeout の void ボタンは UI に残るが API で `NOT_VOIDABLE_ORDER_TYPE` 409

---

## 9.32 Phase 4d-5c-bb-D Connect Checkout takeout の refund 経路整備

### 9.32.1 目的

4d-5c-bb-C までで takeout の POS cashier 経由 void は対応済だが、**Stripe Connect Checkout 経由の online takeout（`gateway_name='stripe_connect'`）の refund 経路が壊れていた**。post1 時点で「未実証論点」として切り出していたものを本スライスで解消する。payment-void.php には online/gateway takeout を**通さない**原則（4d-5c-bb-C で確定）は堅持したまま、refund 側で正しい destination charges refund 経路を提供する。

### 9.32.2 判明した preparatory bug（4d-5c-bb-D 着手時点）

本命の refund 経路修正の前に、前提を成立させるための 3 件の既存バグが順次露見した。いずれも本スライス内で修正:

| # | バグ | 現物箇所 | 影響 |
|---|---|---|---|
| 1 | success URL 構築 | `takeout-orders.php` が runtime path で `/customer/takeout.html` を指す | Stripe Checkout success redirect が正しく画面到達すること |
| 2 | verify context 不一致 | `takeout-payment.php:63` が `verify_stripe_checkout(..., $connectAccount)` で `Stripe-Account:` header 付きで session retrieve。create 側 (`create_stripe_connect_checkout_session`) は destination charges で **Platform account 側**に session 作成するため Connect account context では 404 | 決済成功後も PAYMENT_NOT_CONFIRMED 402 で payments 行欠落 |
| 3 | gateway_status 不整合 | `takeout-payment.php:77/111` が Stripe Checkout の `payment_status='paid'` をそのまま保存。`refund-payment.php:152` は `'succeeded'` を要求 | refund 試行時に INVALID_STATUS 400 で弾かれ Pattern A フォールスルーに到達しない |

上記 3 件を順次修正した証跡:

- URL 修正後: success URL が `/customer/takeout.html?...` になり、画面到達を実証
- verify context 修正後: payments 行が `gateway_name='stripe_connect' + external_payment_id=pi_xxx` で記録されることを実証
- gateway_status 正規化前の一時 DB override (`'paid'→'succeeded'`) で Pattern A 到達 → **GATEWAY_NOT_CONFIGURED 500** を観測し、routing 不一致を確定

### 9.32.3 本命実装 D-fix1 / D-fix2 / D-fix3

| # | 対象 | 内容 |
|---|---|---|
| D-fix1 | `takeout-payment.php:77`, `:111` | `$gatewayStatus = $result['payment_status'] ?: 'paid'` → `$gatewayStatus = 'succeeded'` にハードコード正規化。`checkout-confirm.php:77` と揃う |
| D-fix2 (helper) | `payment-gateway.php` 末尾近く | 新関数 `create_stripe_destination_refund($platformSecretKey, $paymentIntentId, $amountYen, $reason)` 追加。`Stripe-Account` ヘッダなし（Platform context）+ `reverse_transfer=true` + `refund_application_fee=true` で canonical destination charges refund |
| D-fix2 (routing) | `refund-payment.php:185` 周辺 | 従来の 2 分岐 (`stripe_connect_terminal` vs else=Direct) を **3 分岐**に拡張: (1) `stripe_connect_terminal` → 既存 `create_stripe_connect_refund` (Pattern B), (2) `stripe_connect` → 新 `create_stripe_destination_refund` (Pattern C), (3) else → `create_stripe_refund` (Pattern A) |
| D-fix3 | `refund-payment.php` 全体構造 | **claim 前 preflight** を徹底。経路判定 + config 取得 (platform key / connect account / tenant direct key) を `SELECT FOR UPDATE` の直後、`UPDATE refund_status='pending'` の前に移動。config 不足時は `rollBack + json_error` で claim に立てない。claim 後の失敗 exit は `refund_failed revert` パス 1 箇所に統一 |

### 9.32.4 D-fix3 の claim/revert 構造原則

```
BEGIN TX
  SELECT FOR UPDATE payments
  (1) 属性バリデーション   — void_status / cash / gateway_name / refund_status / gateway_status
  (2) 経路判定 + config 取得 (preflight)
      ├── 不足あれば rollBack + json_error (CONNECT_NOT_CONFIGURED / PLATFORM_KEY_MISSING / GATEWAY_NOT_CONFIGURED)
      └── 成功したら $refundPlan に格納
  (3) UPDATE refund_status='pending' (claim)
      └── rowCount!=1 なら rollBack + json_error (ALREADY_REFUNDED)
  COMMIT
--- TX 外 ---
call helper($refundPlan)    — 単一の refund 呼び出しポイント
if (!success):
    UPDATE refund_status='none' (revert)
    json_error('REFUND_FAILED')   — claim 後の唯一の失敗 exit
if (success):
    BEGIN TX; UPDATE refund_status='full'; COMMIT
```

claim 後に json_error は 1 箇所のみ、そこに revert を必ず挟む。旧コードで `GATEWAY_NOT_CONFIGURED` など claim 後に early json_error する経路が存在し `refund_status='pending'` が残る既存バグも同時解消。

### 9.32.5 3 Pattern の対応表

| gateway_name | Pattern | helper | Stripe-Account ヘッダ | 特記 |
|---|---|---|---|---|
| `stripe_connect_terminal` | **B** | `create_stripe_connect_refund` | **あり** (Connect account) | POS Stripe Terminal Connect direct charges。既存挙動を保持 |
| `stripe_connect` | **C (新)** | `create_stripe_destination_refund` | **なし** (Platform) | Checkout Session + `transfer_data[destination]` の destination charges。`reverse_transfer=true` + `refund_application_fee=true` 付与 |
| その他 (`stripe` / `stripe_terminal` / 未知値) | **A** | `create_stripe_refund` | なし | tenant `tenants.stripe_secret_key` 必須 |

### 9.32.6 本番検証結果（2026-04-21 test mode 実測）

- 対象: t-momonoya-001 / s-ikebukuro-001 / test card `4242...`
- 新規 takeout online order: `4a1136aa-855e-4144-8859-bf8711616f5d` / payment `a90993ddb899c7fe6592c6c38b80c8bf`
- refund 実行結果:

| # | 項目 | 値 |
|---|---|---|
| 1 | decision 成功後の DB | orders.status='pending', payments.gateway_status='succeeded' (D-fix1 効果) ✓ |
| 2 | refund API レスポンス (reason=`requested_by_customer`) | HTTP 200 `{refunded:true, refund_id:"re_3TOYeSIYcOWtrFXd2Vpiscix", amount:300}` |
| 3 | payments 最終状態 | refund_status='full', refund_amount=300, refund_id=re_...2Vpiscix, refunded_by=u-momo-staff-001 |
| 4 | Stripe API 側 Refund object | status='succeeded', amount=300, currency=jpy, **transfer_reversal=`trr_1TOYgoIYcOWtrFXdO3Y1PZX6`** ← destination charges の Connect account への transfer が正しく reverse されている |
| 5 | D-fix3 revert 実運用証跡 | 初回試行で自由文 reason → Stripe 側で `Invalid reason` REFUND_FAILED 502、直後に DB で `refund_status='none'` / `refunded_at=NULL` / `refunded_by=NULL` 自動復元を確認 |

### 9.32.7 5 件目 follow-up（→ 4d-5c-bb-E で解消済）

4d-5c-bb-D 時点では「Stripe `/v1/refunds` の `reason` が enum 制約 (`duplicate` / `fraudulent` / `requested_by_customer` の 3 値のみ) で自由文を渡すと REFUND_FAILED 502 になる」既知不具合として切り出していた。→ 2026-04-21 **4d-5c-bb-E で helper 内 Stripe API 送信時に canonical enum 正規化**することで解消（§9.33）。DB `payments.refund_reason` / 監査ログ側の自由文保持は不変。

### 9.32.8 変更しない範囲

- `payment-void.php` は**不変**（online/gateway takeout は引き続き `NOT_VOIDABLE_GATEWAY` 409 で弾く。void と refund の責務分離を堅持）
- `checkout-confirm.php:70` も**触らない**（dine_in online の既存 13 件は 4d-5c-bb-D 範囲外、別スライス）
- `stripe_connect_terminal` (Pattern B) の挙動不変（実データ 0 件、未実証）
- Direct Pattern A の挙動不変（実データ 0 件）

### 9.32.9 ロールバック

- `/home/odah/www/eat-posla/_backup_20260421j/takeout-orders.php.bak`（URL 構築 fix 前）
- `/home/odah/www/eat-posla/_backup_20260421k/takeout-payment.php.bak`（verify context fix 前）
- `/home/odah/www/eat-posla/_backup_20260421m/{payment-gateway.php,takeout-payment.php,refund-payment.php}.bak`（D-fix 前）
- 戻すと: takeout online は payments 欠落 / takeout `stripe_connect` refund は不整合経路に戻る（追加被害なし、以前の既知バグ状態に復帰するのみ）

### 9.32.10 本番 DB に残る permanent artifacts

- payment `a90993ddb899c7fe6592c6c38b80c8bf`: refund_status='full' / refund_id='re_3TOYeSIYcOWtrFXd2Vpiscix' — **本命 E2E 成功証跡**
- order `4a1136aa-...`: status='pending'（online takeout の既知 lifecycle、'paid' に乗らない設計）
- 失敗証跡（意図的に残置）: `c5ac215d-` / `64b53d92-` / `26a91037-`（URL 構築 / verify context のそれぞれの時点）、 `0c2993c7-` + `97ee896ec...`（verify context 修正後 & Q phase 証跡）

---

## 9.33 Phase 4d-5c-bb-E Stripe refund reason enum 正規化

### 9.33.1 目的

4d-5c-bb-D の follow-up として切り出していた「Stripe `/v1/refunds` の `reason` が enum 制約 (`duplicate` / `fraudulent` / `requested_by_customer`) で、自由文 reason を渡すと `REFUND_FAILED 502` になる」既知不具合を解消する。ただし DB `payments.refund_reason` / 監査ログへの自由文保持という現運用の価値は堅持する（スタッフが現場で「誤会計、再調理のため」等の自由文を残すユースケース）。

### 9.33.2 判断根拠（期待シナリオ 1: helper 層だけで閉じる）

- 自由文の入口は `refund-payment.php:27` の `$reason = $data['reason'] ?? 'requested_by_customer'` の 1 箇所
- 自由文の出口は (a) Stripe API helper への引数、(b) DB UPDATE `refund_reason = ?`、(c) `write_audit_log(..., $reason)`
- enum 制約を課すべきなのは (a) のみ。(b)(c) は自由文のままで良い
- → 最小修正は **helper 内で Stripe API 送信時に正規化**

### 9.33.3 変更ファイル（1 件）

| ファイル | 変更 |
|---|---|
| `api/lib/payment-gateway.php` | (1) 新 private helper `_normalize_stripe_refund_reason($reason)` を追加（`duplicate` / `fraudulent` / `requested_by_customer` 以外は `'requested_by_customer'` に fallback、enum 値は素通し）。(2) 既存 3 refund helper (`create_stripe_refund` / `create_stripe_connect_refund` / `create_stripe_destination_refund`) の `'reason' => $reason ?: 'requested_by_customer'` を `'reason' => _normalize_stripe_refund_reason($reason)` に差し替え（3 箇所、各 1 行）|

### 9.33.4 不変な動作

- `refund-payment.php` の API インターフェース（リクエスト body `reason`、レスポンス形式）: **不変**
- DB `payments.refund_reason` への自由文保存: **不変**（`refund-payment.php:303/320/322` はそのまま、200 文字まで自由文保存）
- 監査ログ（`write_audit_log`）への自由文記録: **不変**（line 338/341）
- 4d-5c-bb-D の 3 Pattern routing / preflight / claim-revert 構造: **不変**
- `payment-void.php` / `takeout-orders.php` / `takeout-payment.php` / `refund-payment.php` 本体: **不変**
- schema / migration: **なし**

### 9.33.5 本番検証結果（2026-04-21 test mode 実測、E2E）

| # | 項目 | 値 |
|---|---|---|
| 1 | 新規 takeout Connect Checkout order | `48f09085-3a5c-4805-be98-0245e37ac933`, pickup=2026-04-21 17:40, ¥300 |
| 2 | 決済完了後 DB | orders.status=pending, payments.gateway_name='stripe_connect', gateway_status='succeeded' |
| 3 | refund リクエスト body の reason | 自由文日本語 107 文字「`4d-5c-bb-E E2E 検証: Stripe reason enum 正規化の動作確認 / 自由文でも refund 成功すること、DB の refund_reason にはこの自由文がそのまま保存されること`」 |
| 4 | refund-payment.php レスポンス | **HTTP 200** `{refunded:true, refund_id:"re_3TOaEWIYcOWtrFXd1P7Su27T", amount:300}` |
| 5 | Stripe API 側 Refund object の reason | **`"requested_by_customer"`**（正規化の証跡）|
| 6 | Stripe 側 Refund status / transfer_reversal | status=succeeded, transfer_reversal=`trr_1TOaFwIYcOWtrFXd25MlePBJ`（destination charges refund 成立）|
| 7 | POSLA DB payments.refund_status | **`full`** |
| 8 | POSLA DB payments.refund_reason の文字数 | **107**（送信文字数と完全一致）|
| 9 | POSLA DB payments.refund_reason の中身 | 送信した自由文日本語がそのまま保存（ENUM に丸められていない）|
| 10 | 既存 4d-5c-bb-D 成功証跡 `a90993dd...` | refund_status='full' / refund_id='re_3TOYeSIYcOWtrFXd2Vpiscix' 不変 ✓ |

### 9.33.6 ロールバック

- `/home/odah/www/eat-posla/_backup_20260421n/payment-gateway.php.bak` から 1 ファイル戻す
- DB 改変なし
- 戻すと 4d-5c-bb-E 以前の「自由文 reason で REFUND_FAILED 502」状態に戻る（既存 Phase 4d-5c-bb-D の routing は無影響）

### 9.33.7 未対応（残 follow-up）

- ~~`takeout-payment.php:188` の INSERT silent-fail 設計（別原因 DB 失敗時にも握りつぶされる余地）~~ → 2026-04-21 **4d-5c-bb-F で解消済み**（§9.34）
- `checkout-confirm.php:70` の verify_stripe_checkout context bug（dine_in online で同じパターン、実データ 13 件あり、慎重な別スライス）
- POS Stripe Terminal refund (`stripe_connect_terminal` / `stripe_terminal`) の実データ実証
- Direct stripe refund の実データ実証（tenant の stripe_secret_key 未設定でこれまで未運用）
- 論点 B: 分割会計 (is_partial=1) void
- 論点 D: 返金チェーン（実データ 0 件、スコープ外確定）

---

## 9.34 Phase 4d-5c-bb-F takeout-payment silent-fail 是正 + customer-facing false-positive 解消

### 9.34.1 目的

`api/customer/takeout-payment.php` の既存設計で、Stripe verification 成功後の DB 永続化（`orders.status` UPDATE + `payments` INSERT）が失敗しても catch でログだけ出して `json_response({payment_confirmed:true})` を返していた silent-fail 設計を是正する。あわせて `public/customer/js/takeout-app.js` の `.catch` 分岐で発生していた「いかなる決済確認失敗でも『ご注文を受け付けました』完了画面に遷移する」customer-facing false positive も解消する。

### 9.34.2 判明していた false positive の実害

| レイヤ | 従来挙動 | 問題 |
|---|---|---|
| API | verify 成功 + INSERT 失敗 → 200 `{payment_confirmed:true}` | 売上漏れ / refund 不可 / receipt 不可 / KDS 混乱 |
| UI | 決済確認 API 失敗（verify/STRIPE_MISMATCH/通信失敗 等）→ 完了画面に遷移 "ご注文を受け付けました" | お客様が受付成功と誤認 |
| UI (polling) | `startStatusPolling` が後追いで orders.status='pending_payment' を拾い「決済待ち」表示 | 完了画面に着地した後なので誤認が残る |

### 9.34.3 最小安全スライス設計

**判断原則**:
- verify と永続化の責務を分離: verify 成功と DB 記録成功は別事象
- API は自分の成否を正直に返す: 永続化失敗は 5xx
- UI はエラーとキャンセルを分けて表示: false positive を消す
- pi_id 等 Stripe 内部識別子は customer-facing response に出さず、log のみに残す（運営リカバリ手掛かり）

### 9.34.4 変更ファイル（4 件）

| ファイル | 変更 |
|---|---|
| `api/customer/takeout-payment.php` | 決済成功後の `orders.status` UPDATE + `payments` INSERT を**単一 TX** にまとめ、失敗時は `rollBack` + `json_error('PAYMENT_RECORD_FAILED', 汎用メッセージ, 500)`。catch は `Throwable` で `PDOException` + `RuntimeException` 両方を捕捉。`external_payment_id` / `gateway_name` / `order_id` / `err->getMessage()` は `php_errors.log` のみに記録し、response には**汎用文言のみ**返す。冪等性早期 return (L34-39) は不変 |
| `public/customer/js/takeout-app.js` | `handlePaymentReturn()` の success 分岐の `.catch` を「section 5 完了画面に遷移」から「赤字『決済確認に失敗しました』+ 店舗連絡案内 + 注文番号表示 + 新規注文ボタン」に差し替え。cancel 分岐（オレンジ）とは視覚的に区別 |
| `public/customer/takeout.html` | cache buster `?v=20260418online` → `?v=20260421-pwa4d-5c-bb-F` |
| `scripts/generate_error_catalog.py` | `PINNED_NUMBERS` に `PAYMENT_RECORD_FAILED => ('E6', '決済・返金・Stripe・サブスク', 6028)` を追加。`api/lib/error-codes.php` / `scripts/output/error-codes.json` / `scripts/output/error-audit.tsv` / docs catalog も再生成 |

### 9.34.5 不変な動作

- 正常成功経路 (`verify 成功 + 永続化成功`) → 従来どおり 200 `{order_id, payment_confirmed:true, status:"pending"}`、UI は section 5 完了画面
- verify 失敗 (PAYMENT_NOT_CONFIRMED / STRIPE_MISMATCH 等) → 既存の 402/403 レスポンス不変（ただし UI は `.catch` 経由で新しい失敗画面表示に変わる、副次改善）
- cancel URL → 既存のキャンセル画面（オレンジ）表示不変
- 冪等性早期 return (`orders.status !== 'pending_payment'` なら L34 で即 return) → 不変
- refund-payment.php / payment-void.php / create_stripe_destination_refund / 4d-5c-bb-A〜E の成果 → **完全不変**
- schema / migration → なし

### 9.34.6 新 error code

| code | errorNo | http | message | 用途 |
|---|---|---|---|---|
| `PAYMENT_RECORD_FAILED` | E6028 | 500 | 決済は完了しましたが注文の記録に失敗しました。お手数ですが店舗へ直接お問い合わせください。 | Stripe 側 decision 成功、POSLA 側 DB 永続化失敗時 |

### 9.34.7 運用リカバリ手順（新設）

`PAYMENT_RECORD_FAILED` 発生時、運営は:
1. `/home/odah/log/php_errors.log` で `[4d-5c-bb-F][takeout-payment] persistence_failed order=... pi=... gw=... err=...` のエントリを検索
2. 該当 order の DB 状態を確認 (`orders.status='pending_payment'` / `payments` にその `pi_...` 行がない)
3. Stripe dashboard で該当 PaymentIntent 成功を確認
4. 手動で payments 行を INSERT して orders.status を pending に進めるか、Stripe 側 refund でお客様に返金

### 9.34.8 本番検証（2026-04-21 test mode, E2E）

- 正常系: 新規 takeout Connect Checkout → decision → 完了画面表示（従来通り）→ DB `orders.status='pending'` + `payments` 行作成 → 既存 4d-5c-bb-D/E 成功経路の回帰確認
- 障害注入: 本番 code/DB に影響を与えず INSERT 失敗を起こす安全な手段がないため**実行せず**（UNIQUE 制約なし / PRIMARY KEY は random_bytes 生成）。代わりに**コードレベル実証**（リモートの現物コード上で catch → `json_error('PAYMENT_RECORD_FAILED', ..., 500)` 構造確認）+ **UI レベル実証**（takeout.html キャッシュバスター到達 + takeout-app.js の `.catch` 分岐に完了画面遷移ロジックが無いこと）で代替

### 9.34.9 ロールバック

- `/home/odah/www/eat-posla/_backup_20260421o/{takeout-payment.php, takeout-app.js, takeout.html}.bak` から 3 ファイル戻す
- `api/lib/error-codes.php` は generator 再生成産物、戻さなくても互換（E6028 が宣言されているだけ）
- DB 改変なし

### 9.34.10 残 follow-up

- ~~`checkout-confirm.php:70` の verify_stripe_checkout context bug（dine_in online 13 件あり、慎重な別スライス）~~ → 2026-04-21 **4d-5c-bb-G で解消済**（§9.35）
- POS Stripe Terminal refund 実証（実データ 0 件）
- Direct stripe refund 実証（実データ 0 件）
- 論点 B: 分割会計 (is_partial=1) void
- 論点 D: 返金チェーン（スコープ外確定）

---

## 9.35 Phase 4d-5c-bb-G checkout-confirm.php (dine_in online) の verify context bug 是正

### 9.35.1 目的

4d-5c-bb-D で `takeout-payment.php:63` について解消した「Connect Checkout session の retrieve context 不一致 (destination charges で session は Platform 側にあるのに Stripe-Account header 付きで Connect account 側を検索して 404)」と**完全同型の bug** が `api/customer/checkout-confirm.php:70` にも残っていたことを test mode で実証し、解消する。

### 9.35.2 bug の構造と共通性

| 観点 | `takeout-payment.php` (bb-D で解消済) | `checkout-confirm.php` (本スライス対象) |
|---|---|---|
| create 側 | `takeout-orders.php:431` `create_stripe_connect_checkout_session` → destination charges、session は **Platform** | `checkout-session.php:139` `create_stripe_connect_checkout_session` → **同じ**（destination charges、session は **Platform**） |
| 旧 retrieve | `verify_stripe_checkout(..., $connectAccountId)` → Stripe-Account header 付き → Connect account 側で 404 | `verify_stripe_checkout(..., $connectInfo['stripe_connect_account_id'])` → **同じ** → 同じ症状 |
| 症状 | PAYMENT_NOT_CONFIRMED 402 | **PAYMENT_GATEWAY_ERROR 502** (Connect verify 失敗 → Direct fallback → tenant Direct key NULL) |
| 修正 | 第 3 引数削除 | **同じく第 3 引数削除** |

### 9.35.3 既存データとの関係（13 件）

- 本番 DB に `dine_in + gateway_name='stripe_connect'` の既存 13 件が存在
- 作成時期: 2026-04-05 〜 2026-04-14（最新から今日 04-21 までの 7 日間、新規決済なし）
- 仮説 A（過去コードの遺産）が有力で、本スライス調査で test mode 実証により**仮説 A 確定** - 既存 13 件は触らない（本スライスは新規 decision のみに影響）

### 9.35.4 test mode 実証（修正前／修正後）

**Phase 2a: 現行コード失敗証跡**

- 対象: t-momonoya-001 / s-ikebukuro-001 / test order `44f31d9d-3d6e-11f1-9b2a-73957d6c1f84` (¥300) / session `cs_test_a1z7Nb...`
- ユーザー画面: 「Stripe設定が見つかりません」赤字エラー
- server 直叩き: **HTTP 502** `{"code":"PAYMENT_GATEWAY_ERROR","message":"Stripe設定が見つかりません","errorNo":"E6014"}`
- DB: orders.status='pending' 維持 / payments 0 件
- Stripe A/B retrieve:
  - Platform context (header なし): HTTP 200 / status=complete / payment_status=paid / payment_intent=pi_3TObj0IYcOWtrFXd1bdyWtb0
  - Connect context (header あり、旧コード): **HTTP 404** `No such checkout.session`

**Phase 2b: 修正**

- `api/customer/checkout-confirm.php:70` の第 3 引数 `$connectInfo['stripe_connect_account_id']` を削除 (1 行)
- `verify_stripe_checkout($platformConfig['secret_key'], $stripeSessionId)` に変更

**Phase 2c: 修正後成功証跡**

- 同じ session_id で checkout-confirm.php 再叩き
- **HTTP 200** `{"ok":true,"data":{"payment_id":"3bac1c88-f6c5-4f7a-8e27-edf8d6cee690","total":300,"payment_method":"card","gateway_name":"stripe_connect","paid_at":"2026-04-21 19:43:34"}}`
- DB 最終状態: orders.status='paid' / payments 行 `3bac1c88-...` (card + stripe_connect + pi_3TObj0... + gateway_status='succeeded' + void_status='active' + refund_status='none')

### 9.35.5 変更ファイル（1 件）

| ファイル | 変更 |
|---|---|
| `api/customer/checkout-confirm.php` | `:70` の `verify_stripe_checkout($platformConfig['secret_key'], $stripeSessionId, $connectInfo['stripe_connect_account_id'])` から第 3 引数削除。冒頭に経緯コメント追加 |

### 9.35.6 不変な動作

- Direct Stripe 経路（`if (!$verified)` ブロック）: 不変
- `checkout-session.php` (create 側): 不変
- `takeout-payment.php` / `takeout-orders.php` / 4d-5c-bb-A〜F: 不変
- refund-payment.php / payment-void.php: 不変
- menu.html / customer UI: 不変
- schema / migration: なし
- 既存 dine_in + stripe_connect 13 件: **触らない**

### 9.35.7 ロールバック

- `/home/odah/www/eat-posla/_backup_20260421p/checkout-confirm.php.bak` から 1 ファイル戻す
- DB 改変なし（test data として残置）
- 戻すと dine_in online Connect Checkout の verify が再び Stripe-Account header 付き → 404 → 502 に戻る（旧既知 bug 状態に復帰、追加被害なし）

### 9.35.8 本番 DB 永続 artifacts（実証跡、意図的残置）

- order `44f31d9d-3d6e-11f1-9b2a-73957d6c1f84`: status='paid', total=¥300 — **Phase 2c 成功証跡**
- payment `3bac1c88-f6c5-4f7a-8e27-edf8d6cee690`: card + stripe_connect + pi_3TObj0IYcOWtrFXd1bdyWtb0 + gateway_status='succeeded'
- 旧 `d735b9d7-97f6-42d9-a62d-7a15c1fbd32f`: **恒久 cancelled**。4d-5c-bb-G 実施時に退避した test 注文として残置、復旧予定なし。memo に経緯記録あり（`[4d-5c-bb-G tmp cancel for test 2026-04-21]`）。momonoya は test tenant 扱い（CLAUDE.md grandfather 不要）のため実運用影響なし。同一 T01 / 同一 session_token 上で `44f31d9d-...` (paid) を成功証跡として残す方が監査性が高いため、pending 復旧は行わない

### 9.35.9 残 follow-up

- POS Stripe Terminal refund 実証（実データ 0 件）
- Direct stripe refund 実証（実データ 0 件）
- ~~論点 B: 分割会計 (is_partial=1) void~~ → 2026-04-21 **意味論未確定のため実装前停止、docs 固定**（§9.36）
- 論点 D: 返金チェーン（スコープ外確定）

---

## 9.36 論点 B（分割会計 void）— 意味論未確定のため実装前停止（2026-04-21）

### 9.36.1 本節の位置付け

本節は **コード変更を伴わない判断記録**。split payment (`is_partial=1`) の void は現物調査の結果「コード未実装」ではなく **業務意味論が未確定** で停止した。再着手時に再調査コストをかけず、論点・分岐・決めるべき業務ルールを固定しておく。

### 9.36.2 現物 DB 分布（2026-04-21 時点）

| 項目 | 値 |
|---|---|
| `is_partial=1` active payments | **35 件** |
| payment_method / gateway_name | **100% `cash` + `null`**（ゲートウェイ経由 split はゼロ） |
| order_type | **100% `dine_in`** |
| tenant | **100% `t-matsunoya-001`**（test tenant）|
| 紐付く distinct order | 11 |
| 同一 order の partial 件数分布 | 1〜5 / order |
| void 実例 | **0 件**（現 `payment-void.php:269-279` の `NOT_VOIDABLE_PARTIAL` 409 で全弾き） |

### 9.36.3 現物 11 orders の整合性（test 痕跡と実運用の混在）

| 分類 | 典型例 | 説明 |
|---|---|---|
| 完全 split（orders.total_amount と partial sum 一致）| `f13c551e` (5×¥1000=¥5000, order ¥5000) | 正規の 5 人割り勘 |
| 不完全 split + 最終 non-partial | `387c8a67` (4×cash sum ¥4100 + 1×card ¥5000, order ¥5000) | partial 合計 + non-partial 合計が order_total を**超過**（overpayment のテスト痕跡） |
| partial sum > order_total | `1520fb3f` (5×partial ¥4400 vs order ¥300) | 異常データ（test 叩き痕跡） |
| partial ありで order_status=cancelled | `0d5f749e` (3×partial ¥5330 vs order ¥1480 / status=cancelled) | キャンセル後の残骸 |

**実運用由来の安定 split 運用データは極めて少ない**。回帰テストの基盤として使えるデータがないのが stop 判断の裏付け。

### 9.36.4 切り分けた 5 論点（A/B/C/D/E）

| 論点 | 内容 | 本回判断 |
|---|---|---|
| **A** | 個別 payment 単位 void（1 split 行のみ void） | ❌ 単独実装不可（orders.status 遷移 / reports 金額補正 / 合流波及が未定） |
| **B** | order 全体 void（同一 order の全 active split を一括 void） | ⚠ 要業務合意（「全員分返金やり直し」運用の需要有無） |
| **C** | cash + no-gateway 限定 | ✅ 自動確定（現物 100% cash のため別フィルタ不要） |
| **D** | gateway 付き split は対象外 | ✅ スコープ外確定（実データ 0 件、refund 論点に踏み込まない） |
| **E** | reports 意味論（partial void 後に売上をどう表現するか） | ⚠ **最大論点**。4d-5c-c（sales-report の payments ベース統合）と一体で設計すべき |

### 9.36.5 コード上の未確定ポイント

1. **orders.status の paid 化契機が不明**: `process-payment.php:503` は `!$isPartial` 時のみ `orders.status='paid'` に更新するはずだが、完全 split orders 7 件が非 partial payment なしで既に `paid` 状態。別経路（`kds/close-table.php`、`kds/update-status.php`、手動 UPDATE）で paid 化された可能性があり、**void 時の status 遷移ルールが定義できない**
2. **4d-5c-a `$voidExclude` NOT EXISTS 句の仕様**: 「active payment が 1 件でも残れば sales-report から除外しない」= split 一部 void では売上金額が減らない。これを変えるなら payments ベース統合（4d-5c-c）に踏み込む必要あり
3. **合流会計との相互作用**: 同一 payment が複数 order に紐付く実例あり（bb-C の DB 調査で確認済）。1 payment void の波及範囲が未定
4. **`order_items.payment_id` 割当て**: 40782 中 44 件のみ割当て。`process-payment.php:491-500` で partial 時のみ実行される。void 時にこの紐付けを残す / NULL に戻す / `order_items.status='cancelled'` のどれにするかが未定

### 9.36.6 再着手のために決めるべき業務ルール（5 項目）

1. **split void のスコープ**: 個別 payment 単位（A）か order 全体一括（B）か
   - 推奨: **B**（1 人分だけ返金は会計上例外的、全員分返金してやり直しの方が整合性が取れる）
2. **void 後の orders.status の扱い**: `paid` 維持（accounting void 原則）か `pending_payment` に戻すか
   - 推奨: **`paid` 維持**（4d-5c-ba の bb-C 等と揃える）
3. **reports の金額反映**: 部分 void を売上から減算するか、しないか
   - 推奨案 α: **減算しない**（4d-5c-c 統合まで待つ、運用で把握）
   - 推奨案 β: 4d-5c-c 先行（payments ベース統合で自動反映）
4. **合流会計 void の波及範囲**: 1 payment に紐付く全 order に対して、active payment が 1 件もなくなった時点で sales-report から除外（4d-5c-a 挙動と整合）
5. **運用需要の有無**: そもそも split void が業務で発生するか、発生頻度はどの程度か
   - **この合意なしには着手しない**。現物 void 実例 0 件 = 運用ニーズ未確認

### 9.36.7 再着手トリガー（docs 的な再エントリ条件）

以下のいずれかが満たされたら、本 stop 判断を解除して最小スライス実装に進める:

- 業務側から「split void 運用ニーズあり」が報告され、上記 5 項目のうち 1/2/3/5 が合意された
- 4d-5c-c（sales-report の payments ベース統合）が先行完了し、論点 E が自動解消された
- 本番で split void の手動リカバリ需要が発生し、業務フローが試行錯誤で固まった

### 9.36.8 現時点で**実装しない**ものの明確化

- `payment-void.php:269-279` の `NOT_VOIDABLE_PARTIAL` 409 は**そのまま維持**
- 新 API / 新エラーコード / 新 UI は追加しない
- schema / migration 無変更
- 本番既存 35 split payments には一切触らない（read-only 調査のみ実施済）
- 4d-5c-ba〜G の既存成果は完全不変

---

## 9.37 Phase 4d-5c-c（reports payments ベース統合）— 単独実装不可判断（2026-04-21）

### 9.37.1 本節の位置付け

本節は **コード変更を伴わない判断記録**。`sales-report.php` / `turnover-report.php` / `register-report.php` の money/count metrics を payments ベースへ寄せる案（4d-5c-c）を現物調査した結果、**単独では意味論が閉じない**ことが確定したため、コード変更ゼロで理由と前提不足を docs に固定し、再調査コストを下げる。

### 9.37.2 決定的な現物事実（2026-04-21 時点）

**paid orders 7,546 件のうち 7,430 件 (98.5%) が payments 行 0 件**:

| 区分 | 件数 |
|---|---|
| `orders.status='paid'` 総数 | **7,546** |
| 紐付く active payments あり | 111 |
| **payments 行 0 件**（緊急会計由来でもない）| **7,430** |

0 件 paid orders の実例は `order_type='handy'` が大多数（handy-order.php 経由 / デモデータ流入で `orders.status='paid'` が直接立ち、`payments` INSERT が走らない経路）。

**orders ベースと payments ベースの revenue が一致しない**:

| 集計軸（matsunoya shibuya 2026-04-01〜22）| 件数 | 金額 |
|---|---|---|
| orders ベース | 90 | ¥154,270 |
| payments ベース（active、非 emergency） | 85 | **¥166,530** |
| **差** | +5 | **+¥12,260** |

### 9.37.3 1:0 / 1:N / N:1 / N:M 共存の実測

| パターン | 件数 | 典型 |
|---|---|---|
| 1 order : 0 payment | 7,430 | handy paid orders（payments バイパス） |
| 1 order : 1 payment | ~116 | 通常 POS cashier 経由 |
| 1 order : N payments (`is_partial=1`、split) | 35 payments / 11 orders | `f13c551e` (5×¥1000=¥5000) 等 |
| N orders : 1 payment (merged) | 24 payments / 82 orders | `e24cadb6` (1 payment ¥17,790 × 10 orders)、`311a3585` (¥900 × 3 orders = ¥4,580)|

**merged payment では orders SUM と payments SUM が一致しない**。例: `311a3585` 1 payment ¥900 が 3 orders を覆う → orders 合計 ¥4,580、payments 合計 ¥900 → **¥3,680 差**。金額分配ルールが未定義なので「payments 合計」がそのまま売上にはならない。

### 9.37.4 5 つの設計問いへの回答

| 問い | 答え | 根拠 |
|---|---|---|
| **Q1** `sales-report.totalRevenue` を active payments 合計に寄せる？ | **❌ 不可** | paid orders 7,430 件が payments 行なしで存在、売上の大半が消える |
| **Q2** `register-report.paymentBreakdown` を payments 正本にすべき？ | **❌ 不可**（paymentBreakdown は）<br>**✅ cash_log は既に cash_log 正本で正しい**<br>**✅ externalMethodBreakdown は既に payments 正本で正しい** | handy cash/card の売上が消える / 既存の payments ベース部分は維持 |
| **Q3** `turnover-report.priceBands` を payments 金額ベースに寄せる？ | **❌ 壊れる** | priceBands は「**注文の価格帯**」が意味論。split で ¥5000 の order が ¥1000×5 件 partial に分解されると 5 件全部 mid band に落ち、本来 over1000 band の 1 件がゼロになる |
| **Q4** `sales-report.orderCount` を payments ベースに寄せる？ | **❌ 重複/欠落** | split で 1 order が 5 payments に膨らむ / merged で 10 orders が 1 payment に縮む |
| **Q5** merged payment を 4d-5c-c だけで扱える？ | **❌ 不可** | 1 payment × N orders の金額分配ルール（按分比例 / 均等割 / 先着割り当てのどれか）が業務ルールで未定義 |

### 9.37.5 money / count 密結合の証拠

| metric | 現基盤 | payments ベース化の判定 |
|---|---|---|
| `sales-report.totalRevenue` | orders SUM | ❌ |
| `sales-report.orderCount` | orders COUNT | ❌ |
| `sales-report.avgOrderValue` | totalRevenue / orderCount | ❌（両方寄せても意味変質） |
| `sales-report.itemRanking` | orders.items JSON | ❌（payments に items なし） |
| `sales-report.hourly.revenue` / `tableSales.revenue` | orders SUM | ❌ |
| `register-report.paymentBreakdown` | orders GROUP BY payment_method | ❌（orders.payment_method は粗 ENUM、handy 消失）|
| `register-report.externalMethodBreakdown` | payments GROUP BY external_method_type | ✅ **既に payments 正本、維持** |
| `register-report.cashLog / cashSales / expectedBalance` | cash_log | ✅ **既に cash_log 正本、維持** |
| `turnover-report.mealPeriods / hourlyEfficiency.revenue` | orders SUM | ❌ |
| `turnover-report.priceBands` | orders 分布 | ❌ 意味論崩壊 |
| `turnover-report.guestGroups.totalSpend` | orders table_id マッチ | ❌ |

### 9.37.6 既に payments / cash_log ベースで正しい部分

以下は 4d-5c-c の名の下で再度触る必要はない:

- `register-report.externalMethodBreakdown`（payments GROUP BY + `void_status <> 'voided'`、4d-5a で整備）
- `register-report.cashLog` / `cashSales` / `cashIn` / `cashOut` / `expectedBalance`（cash_log テーブル、4d-5a 期待残高相殺で正常化済み）
- `payment-void.php` の cash_log.cash_out 相殺（4d-5c-ba/bb-C で整備、takeout cash void 含む）
- `refund-payment.php` の 3 Pattern routing（4d-5c-bb-D で整備）

### 9.37.7 本来必要な前提作業（4d-5c-c より先にやるべき順序）

```
(今ここ) 4d-5c-c 単独実装不可判断 §9.37
    ↓
(前提作業 4d-6) paid orders ↔ payments の 1:0 解消
    ├── handy / POS 旧 flow の paid orders に payments INSERT を担保する
    │   (or 該当 paid orders を「payments 行なし」のまま受け入れる業務合意)
    └── test データ / デモデータ / 実運用データの切り分け
         - 本番 7,430 件が実運用か test 流入かを tenant と確定
    ↓
(前提作業 4d-7) merged payment の金額分配ルール定義
    ├── 按分比例 / 均等割 / 先着割り当て / その他 のどれを採用するか
    └── reports 集計が依存する共通 SQL を決める
    ↓
(構造改修 4e) payment_order_links 正規化テーブル導入
    ├── orders ↔ payments の N:M 関係を明示的に表現
    ├── JSON_CONTAINS(p.order_ids, ...) を正規化リンクに置き換え
    └── split / merged を関係表現で扱える
    ↓
(論点 B) split void 本体 §9.36
    └── voided 部分の金額反映が payment_order_links ベースで可能に
    ↓
(やっと 4d-5c-c) reports 意味論を payments ベースへ寄せる議論が実装可能に
```

### 9.37.8 再着手トリガー（docs 再エントリ条件）

以下のいずれかが満たされたら、本 stop 判断を解除して再調査可能:

- 4d-6（handy paid orders の payments 記録 or 業務合意で受け入れ）が完了
- 4d-7（merged payment の金額分配ルール）が合意済み
- 4e（payment_order_links 正規化）が本番反映済み
- 業務側から「reports 金額の部分 void 反映が急務」との明示要求、かつ上記前提がセットで合意

### 9.37.9 現時点で**実装しない**ものの明確化

- `sales-report.php` / `turnover-report.php` / `register-report.php` の既存集計ロジックはそのまま維持
- 新 API / 新エラーコード / 新 UI / schema / migration はゼロ
- 本番既存データ（7,546 paid orders / 35 split payments / 24 merged payments）には一切触らない（read-only 調査のみ実施済）
- 4d-5c-ba〜G / 論点 B §9.36 の成果は完全不変

---

## 9.38 Phase 4d-6 paid write-path 安全性検証 — 止血不要判定（2026-04-21）

### 9.38.1 本節の位置付け

§9.37 で「4d-5c-c 着手の前提」として切り出した **4d-6（handy paid orders の payments 記録担保）** を現物で確定させる調査記録。結論は **「現行 write-path は clean、7,430 件は demo 由来、コード変更不要で停止」**。 §9.37 の前提ツリーのうち 4d-6 を「担保済み」でクローズする根拠を docs に固定し、再調査コストを下げる。

### 9.38.2 live write-path 全列挙と payments 同期性判定

`orders.status='paid'` を書き得る全コード経路を grep + 直接 read で確認した結果:

| 経路 | 直接 UPDATE 実行 | payments INSERT 同一 TX | 実呼び出し元 | 判定 |
|---|---|---|---|---|
| `api/store/process-payment.php` | ✅ UPDATE→'paid' @ L567 | ✅ あり | `cashier-app.js`（POS 会計 UI） | **安全** |
| `api/store/handy-order.php` | INSERT は `status='pending'` のみ / PATCH は paid/cancelled 拒否 | — | `handy-app.js` | **paid 化しない** |
| `api/kds/update-status.php` | 🛈 `'paid'` を valid 値に含み PATCH で UPDATE 可能 / payments INSERT なし | ❌ | `kds-renderer.js`（data-status="paid" が HTML 側に一切存在しない）/ `voice-commander.js`（KDS 経由のみ） | **UI が送らない経路、死蔵** |
| `api/kds/update-item-status.php` | item→order 昇格は preparing/ready/served のみ（line 115-123）、paid にはならない | — | `kds-renderer.js`（品目単位操作） | **paid 化しない** |
| `api/kds/close-table.php` | ✅ UPDATE→'paid' @ L101-105 / payments INSERT なし / cash_log INSERT は cash のときのみ（deprecated @ `// @deprecated 2026-04-02`） | ❌ | `accounting-renderer.js:148`（←どの HTML にも `<script>` タグなし、dead code） | **dead code / UI 未結線** |

追加確認:
- `grep -n 'AccountingRenderer\\|accounting-renderer' public/` の結果、`public/kds/js/accounting-renderer.js` 自己定義 1 箇所のみ。`public/kds/*.html` および `public/admin/*.html` に `<script>` 参照なし → `accounting-renderer.js` は **現 UI から一切ロードされていない**
- `grep -n 'data-status=.paid.' public/kds/` → 0 件。KDS のアクションボタンが直接 `paid` を送る箇所は存在しない（pending / preparing / ready / served / cancelled のみ）
- `voice-commander.js` は `KdsRenderer.handleAction(orderId, newStatus, storeId)` を経由する二次呼び出し。一次は KDS の DOM action のみ

結論: **「paid 化と payments INSERT を同一 TX に閉じ込める」ルールは、現行の live 経路である `process-payment.php` では既に担保されている**。`update-status.php` と `close-table.php` は「理論上は paid を書ける」が、UI 側が送らない / 結線されていないため、現行 UI 経由では live に paid-without-payments を生成できない。

### 9.38.3 7,430 件 payments-less paid orders の実体特定

#### tenant × 月次分布（全期間）

| tenant | 月 | 件数 |
|---|---|---|
| **t-torimaru-001**（Hiro test tenant）| 2026-03 | **5,671** |
| **t-torimaru-001** | 2026-04 | **1,733** |
| t-matsunoya-001 | 2026-03 | 26 |

`t-torimaru-001` が **7,404 件（99.65%）** を占める。

#### torimaru 2026-04 分の日次分布

| 日 | cash | card |
|---|---|---|
| 2026-04-01 | 156 | 104 |
| 2026-04-02 | 129 | 101 |
| 2026-04-03 | 135 | 113 |
| 2026-04-04 | 139 | 113 |
| 2026-04-05 | 132 | 107 |
| 2026-04-06 | 138 | 106 |
| 2026-04-07 | 145 | 96 |
| 2026-04-08 | 10  | 9   |

2026-04-01〜07 は 230〜250 件/日で「人為的に均一」、2026-04-08 で急落、2026-04-09 以降ゼロ。**実運用でこの形状は出ない**。

#### 決定的な発生元特定

`scripts/p1-27-generate-torimaru-sample-orders.php` 内に直接 INSERT:

```
"INSERT INTO orders
  (id, store_id, table_id, items, total_amount, status, order_type, payment_method, staff_id,
   session_token, created_at, prepared_at, ready_at, served_at, paid_at)
 VALUES
  (?, ?, ?, ?, ?, 'paid', 'handy', ?, ?, ?, ?, ?, ?, ?, ?)"
```

- 直接 `status='paid'` で INSERT、`process-payment.php` を経由せず、`payments` INSERT も走らない
- 生成量: 日次ランダム × 5 店舗 × 30 日ループ → 7,404 件と整合
- 2026-04-08 急落は最終実行日、以降の再投入なしと整合
- CLAUDE.md 記載の「matsunoya / momonoya / torimaru は全て Hiro のテストデータのみ → grandfather 不要」方針とも整合

結論: **7,430 件は demo seeder 由来の legacy artifact であり、live UI 経由では発生していない。**

### 9.38.4 §9.37 で掲示した 5 問への差分回答

| 問い | 4d-6 後 |
|---|---|
| **Q1** live 経路で paid-without-payments を生成しているか？ | **No.**（`process-payment.php` は payments INSERT を同一 TX で担保、`close-table.php`・`update-status.php` は UI 未結線） |
| **Q2** 発生元は `handy-order.php` か `process-payment.php` か？ | **どちらでもない。**`scripts/p1-27-generate-torimaru-sample-orders.php` の demo 直接 INSERT |
| **Q3** future-write 止血は必要か？ | **不要。**live UI 経由で発生しない。§9.38.5 に defense-in-depth 候補を別論点として分離 |
| **Q4** 履歴 7,430 backfill と分離できるか？ | **完全分離可能。**demo 由来なので backfill も「business-as-usual 補正」ではなく「demo データ整形」扱い。今回は一切触らない |
| **Q5** 4d-7 / 4e / 4d-5c-c の前提として 4d-6 は閉じたか？ | **閉じた。**「現行コードは payments INSERT を担保している」という §9.37 の前提が事実であると確認済。残存 7,430 は demo 隔離で reports 設計から切り出せる |

### 9.38.5 本回はスコープ外（別論点として切り出し）

以下は 4d-6 の責務ではないため今回は一切変更しない:

- `api/kds/close-table.php` の **物理削除 or 404 化**: dead code だが endpoint として生きている。将来の誤用（外部から直接叩かれる）防御は別スライス
- `api/kds/update-status.php` の **`'paid'` 値 reject 化**: 現 UI が送らないので実害なし、防御は別スライス
- `scripts/p1-27-generate-torimaru-sample-orders.php` の **seeder 書き換え（payments INSERT 追加）**: demo スクリプトなので本番運用と切り離せる、別スライス
- 7,430 件の **backfill / 削除 / フィルタ**: §9.37 の 4d-5c-c 側で「demo tenant を reports から除外する」または「payments を artificial に生成する」運用をとるか決める、別スライス（今回は選択のみ）

### 9.38.6 §9.37 の前提ツリーへの反映

§9.37.7 の前提作業順:

```
(前提作業 4d-6) paid orders ↔ payments の 1:0 解消
    ├── handy / POS 旧 flow の paid orders に payments INSERT を担保する
    │   (or 該当 paid orders を「payments 行なし」のまま受け入れる業務合意)
    └── test データ / デモデータ / 実運用データの切り分け
         - 本番 7,430 件が実運用か test 流入かを tenant と確定
```

4d-6 結論で上記 2 箇条は **両方とも「現行 clean / 7,430 は demo 切り分け完了」で満たされた**。§9.37.8 の再着手トリガー「4d-6 が完了」は **本節をもって成立**。次の block（4d-7 merged payment 金額分配ルール、4e payment_order_links 正規化、論点 B §9.36）が未消化なので、4d-5c-c 着手可否は依然として No のまま。

### 9.38.7 現時点で**実装しない**ものの明確化

- PHP / JS / HTML の**コード変更は一切なし**
- schema / migration なし
- 本番データ（7,430 件）に**一切触らない**（read-only 調査のみ、DB ダンプ 3 本で確認済: `/tmp/4d6_dist.sql` / `/tmp/4d6_tenant.sql` / `/tmp/4d6_tenant_all.sql` / `/tmp/4d6_torimaru_days.sql`）
- 新 API / 新 error code / 新 UI なし
- 4d-5c-ba〜G / §9.36 / §9.37 の成果は完全不変

---

## 9.39 Phase 4d-7 merged payment 金額分配ルール — 按分不要判定（2026-04-21）

### 9.39.1 本節の位置付け

§9.37 の前提作業で切り出した **4d-7（merged payment の金額分配ルール）** を現物で確定させる調査記録。結論は **「按分ルールは問題設定そのものが ill-posed / 現行実装で全 consumer が既に整合、コード変更不要で停止」**。併せて §9.37.3 で `311a3585` を「merged payment の金額乖離例」と記述していた**事実誤認を訂正**し、当該パターンは論点 B §9.36 の「partial split over multi-order」扱いであることを明確化する。

### 9.39.2 merged payment の真の分布（現物確定）

`payments.order_ids` JSON_LENGTH >= 2 を満たす payments 全件を `is_partial` で分類した結果:

| パターン | 件数 | 総 order coverage | payment.total vs SUM(orders.total) |
|---|---|---|---|
| **merged (`is_partial=0`)** | **13** | 43 orders | **全件 diff=0（100% 整合）**|
| **partial_multi (`is_partial=1`)** | **10** | 39 orders | 乖離（partial 分割金額）|

両者合計 **23 payments / 82 orders** で §9.37 時点の「24 payments / 82 orders」概要と整合（細かい 1 件は count 境界）。

#### merged (非 partial) 13 件の payment.total vs SUM(orders.total) 突合

全 13 件で diff=0。代表例:

| payment_id | pay_total | n_orders | SUM(orders.total) | diff | session |
|---|---|---|---|---|---|
| `e24cadb6` | 17,790 | 10 | 17,790 | **0** | NULL |
| `e4a9ff2b` | 11,220 | 7 | 11,220 | **0** | NULL |
| `dc1941f2` | 3,750 | 4 | 3,750 | **0** | あり（plan_id=NULL）|
| `55305884` | 3,430 | 3 | 3,430 | **0** | NULL |
| `5b054ad1` | 2,460 | 3 | 2,460 | **0** | あり |
| `dfb385e8` | 2,100 | 2 | 2,100 | **0** | NULL |
| ほか 7 件 (2 orders) | 1,150〜6,200 | 2 | 同額 | **0** | 混在 |

13 件は全て `merged_table_ids` / `merged_session_ids` が NULL、かつ session の plan_id も NULL。つまり **「合流会計 UI」経由でも「プラン会計」経由でもない**。実態は「同一 table で複数 orders を通常会計したとき、process-payment.php が order_ids 配列を 1 payment にまとめた」結果。

#### UI 合流会計（merged_table_ids）実使用件数

```
SELECT COUNT(*) FROM payments WHERE JSON_LENGTH(COALESCE(merged_table_ids,'[]')) > 0;
→ 0 件
```

**本番データで「UI の合流会計ボタン」は 1 度も使われていない**。`cashier-app.js:1448` の `body.merged_table_ids = mergedTableIds` 経路は存在するが live 実績ゼロ。

### 9.39.3 §9.37 の事実誤認訂正

§9.37.3 「1:0 / 1:N / N:1 / N:M 共存」と §9.37 更新履歴 (37) で**merged payment の金額乖離例**として挙げていた `311a3585 (¥900) × 3 orders ¥4,580 → 差 ¥3,680` は **merged ではなく `is_partial=1` の partial_multi（分割会計）**。正しい分類:

| 主張 | 実測 | 判定 |
|---|---|---|
| §9.37.3「merged payment では orders SUM と payments SUM が一致しない」 | merged 13 件は全件 diff=0 | **誤り** |
| §9.37.3「`311a3585` は merged payment の乖離例」 | `is_partial=1` の split payment である | **誤分類** |
| §9.37.3「`e24cadb6` は 1 payment ¥17,790 × 10 orders」 | 事実、ただし SUM(orders) も ¥17,790 で乖離なし | 記述は正しいが **merged は乖離しない** |

`311a3585` が属する「1 table / 複数 orders / `is_partial=1` の分割会計」は **論点 B §9.36 の範疇**であって、merged payment の按分問題ではない。partial_multi 10 件全てが `distinct_tables = 1` で単一 table 内の分割会計パターン（同一 table の複数 orders の一部品目を 1 partial payment にまとめる）。

### 9.39.4 全 consumer の merged 対応確認

`process-payment.php` が payment.total = SUM(orders.total) で書いているため、現行の全 consumer はすでに merged を整合的に扱っている:

| consumer | merged 扱い | 根拠 |
|---|---|---|
| `process-payment.php:283` (write) | `$totalAmount = $orderTotal` で SUM 一致 | `$orderTotal += $order['total_amount']` をループ積算 |
| `payment-void.php:279-328` | order_ids 全件 status='paid' 要求、payment 1 件単位で void、cash_out 1 行で payment.total_amount 相殺 | is_partial=1 は 409 `NOT_VOIDABLE_PARTIAL` で弾く（§9.36）|
| `refund-payment.php:249` | `$totalAmount = (int)$payment['total_amount']` で全額のみ、orders は触らず | 部分返金は「将来拡張」として明示保留 |
| `receipt.php:65-74` | order_ids 全件から order_items を集約、1 枚の領収書を生成 | paid_items フォールバックで integrity 保持 |
| `payments-journal.php` | 1 payment = 1 行で表示、order_ids JSON はそのまま返却 | 集計せず表示のみ |
| `sales-report.php:39-47` / `register-report.php` / `turnover-report.php` | orders.total_amount ベース SUM + `$voidExclude` で「active payment 1 件以上紐付く orders」のみ集計 | merged payment が voided されれば紐づく全 orders が同時除外 |

**E（全 order 一体 void/refund/receipt）は既に成立**。`sales-report` 等で「merged を再現」する必要はなく、orders 正本 SUM は payment.total と一致している。

### 9.39.5 5 つの切り分けルールに対する判定

| ルール | 判定 | 根拠 |
|---|---|---|
| **A 按分しない**（reports は order 正本維持） | **✅ 既に成立** | payment.total = SUM(orders.total)。各 order は自身の total_amount を独立に保持、reports はそれで集計 |
| **B 比例按分**（order.total_amount 比例割）| **❌ 不要** | 既に SUM 一致しており比例割付する対象量がない |
| **C 均等按分**（参加 order 数等分） | **❌ 不要** | 同上 |
| **D UI 起点の明示配分**（入力が必要）| **❌ 不要** | 明示なしで SUM 一致 |
| **E 全 order 一体 void/refund**（1 payment にぶら下がる orders を一体扱い） | **✅ 既に成立** | `payment-void.php:330-344` と `refund-payment.php:301-323` が payment 単位で atomic。cash_out / Stripe refund は payment.total_amount 全額 |

**4d-7 は「按分ルール」という problem statement 自体が ill-posed だったと確定**。問題は merged ではなく partial_multi（split 分割会計）の方で、こちらは §9.36 論点 B の業務ルール合意が未決。

### 9.39.6 4e payment_order_links 正規化の必要性再評価

§9.37.7 の前提ツリーで 4d-7 の次に「4e payment_order_links 正規化」を置いていたが、本節の結論を踏まえて再評価:

| 4e の想定効用 | 4d-7 結論後の評価 |
|---|---|
| orders ↔ payments の N:M 関係を明示的に表現 | **merged は 1 payment : N orders の 1:N で十分**、N:M は partial_multi でのみ発生 |
| `JSON_CONTAINS(p.order_ids, ...)` を正規化リンクに置き換え | 現行の JSON_CONTAINS で void_status 連動含め動いている |
| split / merged を関係表現で扱える | **merged は関係表現不要**、split の意味論（§9.36）が業務未決のため 4e だけ入れても解けない |

**4e 単独では何も解決しない**。先に §9.36 論点 B の業務ルールを合意する必要があり、その合意内容次第では 4e 正規化は不要（既存 JSON 配列 + order_items.payment_id で閉じる可能性）。

### 9.39.7 §9.37 前提ツリーの再マッピング

§9.37.7 の前提ツリーは以下に更新:

```
(今ここ) 4d-5c-c 単独実装不可判断 §9.37（維持、ただし merged 乖離記述は §9.39.3 で訂正）
    ↓
[✅ 完了] 4d-6 paid write-path 安全性 §9.38
    ├── live 経路 clean
    └── 7,430 件は demo artifact
    ↓
[✅ 完了] 4d-7 merged payment 按分ルール §9.39 (本節)
    ├── merged 13 件は全件 SUM 一致 → 按分不要
    ├── §9.37.3 の §311a3585 例は partial_multi 誤分類
    └── 全 consumer が merged を既に整合的に処理
    ↓
[❌ 未決] 論点 B §9.36 split（partial_multi）業務ルール合意
    └── partial_multi 10 件の 1 table / N orders 分割金額をどう集計するか
    ↓
[❓ 再評価] 4e payment_order_links 正規化
    └── §9.36 合意内容次第では不要になる可能性大
    ↓
(やっと) 4d-5c-c reports payments ベース統合
```

**4d-5c-c 着手可否: 依然 No**。残る唯一の必須前提は **論点 B §9.36 の業務ルール合意**（特に「partial_multi 1 payment が複数 orders の一部品目をカバーするとき、reports 集計では partial payment をどう扱うか」）。

### 9.39.8 本回はスコープ外（別論点として切り出し）

- **§9.37.3 本文の直接書き換え**: 「merged は乖離する」の誤記は履歴保存のためそのまま残し、§9.39.3 で訂正する形にとどめる（過去 stop 判断は snapshot として保全）
- **merged_table_ids UI ボタン（合流会計）の撤去/restrict**: live 実績ゼロだが既存機能は触らない（§9.39.2 で 0 件を明記する以上）
- **partial_multi の業務ルール合意**: 論点 B §9.36 の範疇で本回スコープ外
- **4e 正規化の実装**: 先に §9.36 合意が必要、再評価は §9.39.6 のみ

### 9.39.9 現時点で**実装しない**ものの明確化

- PHP / JS / HTML の**コード変更は一切なし**
- schema / migration なし
- 本番データ（23 payments / 82 orders / merged 13 件の read-only 突合）に**一切触らない**
- 新 API / 新 error code / 新 UI なし
- 4d-5c-ba〜G / §9.36 / §9.37 / §9.38 の成果は完全不変

### 9.39.10 実証跡（DB ダンプ）

read-only 調査のみ。以下の SQL を `/tmp/` に配置して本番 DB で実行:

- `/tmp/4d7_merged_detail.sql` — merged (non-partial) 13 件の全情報
- `/tmp/4d7_all_merged.sql` — pattern 別件数（partial_multi 10 / merged 13）
- `/tmp/4d7_amount_reconcile.sql` — merged 13 件の payment.total vs SUM(orders.total) 突合 → 全件 diff=0
- `/tmp/4d7_311a.sql` — §9.37 例の `311a3585` を `is_partial=1` として同定
- `/tmp/4d7_order_sum_all.sql` — merged_table_ids UI 使用回数 0 件
- `/tmp/4d7_partial_check.sql` — partial_multi 10 件全件 distinct_tables=1（単一 table 内 split）

---

## 9.40 論点 B partial_multi 業務ルール合意メモ — A 案（void 不可維持）で固定（2026-04-21）

### 9.40.1 本節の位置付け

§9.36 は `is_partial=1` 全 35 件を対象とした**意味論未確定の実装前停止**。§9.39 で 4d-7 を閉じた段階で、残る唯一の必須前提は「partial_multi（`is_partial=1` ∧ `JSON_LENGTH(order_ids) >= 2`）の業務ルール合意」になった。本節は partial_multi **10 件 subset** に範囲を絞った上で、**A 案（引き続き void 不可、`NOT_VOIDABLE_PARTIAL` 409 を業務ルール側からも fix する）** を正式合意として docs に固定する記録。コード変更・schema 変更・DB 変更はなし。

### 9.40.2 partial_multi 10 件の現物（2026-04-21 時点）

| 項目 | 値 |
|---|---|
| payments 件数 | **10** |
| 紐付く distinct orders | **11**（orders 合計 39 参加、重複あり） |
| tenant / store / table | **100% `t-matsunoya-001` / `s-shibuya-001` / `tbl-shib-01`**（単一 test 環境）|
| payment_method / gateway | **100% `cash` + `null`**（4d-7 から継承）|
| `distinct_tables` per payment | **全件 1**（単一 table 内 split） |
| void 実例 | **0 件** |
| refund 実例 | **0 件**（cash のため `refund-payment.php:144` `CASH_REFUND` で弾かれる）|

### 9.40.3 3 つの代表パターン（全 10 件を漏れなく分類）

| パターン | 件数 | 特徴 | 代表 payment_id | partial sum | orders SUM | orders.status |
|---|---|---|---|---|---|---|
| **α 完全分割（5 人割り勘）** | 5 | 同じ 5 orders を 5 partial が完全分割（合計 ¥4,400）| `ea7d8f9b` `2d6da224` `f225f3e0` `3139470a` `6e295d85` | 300+950+1,200+950+1,000 = **¥4,400** | ¥4,400 | 全 paid |
| **α 完全分割（2 人割り勘）** | 2 | 同じ 2 orders を 2 partial が完全分割（合計 ¥3,700）| `c24283ca` `13884b5d` | 2,600+1,100 = **¥3,700** | ¥3,700 | 全 paid |
| **β cancelled 残骸** | 3 | 紐付く全 orders が status=cancelled、partial 合計が orders SUM と整合しない | `3e9cfb22` `4ff7ad58` `311a3585` | 750+3,680+900 = **¥5,330** | 2 セット混在（¥5,330 / ¥4,580）| 全 cancelled |

- **α 7 件**は **「割り勘を正常に完遂した終端状態」**。`process-payment.php:554-585` の「全 item に payment_id 付与 → 自動クローズ」で既に orders.status='paid' に閉じている。業務観点で**触る必要がない**
- **β 3 件**は **「order cancel 後に残った partial 払込」**。order cancel 時に payments を連動クリーンアップしていないための残骸で、本論点 B の守備範囲ではない（別論点: order cancel policy）

### 9.40.4 現行 consumer による partial_multi の扱い（触らずに確認）

| consumer | 現行挙動 | operator 見え方 | 判定 |
|---|---|---|---|
| `process-payment.php:491-500` | is_partial=1 時に `order_items.payment_id` を UPDATE | — | 正常 |
| `process-payment.php:554-585` | 全 item に payment_id 付与完了時に orders 自動 paid 化 | — | 正常 |
| `payment-void.php:267-277` | `is_partial=1` は 409 `NOT_VOIDABLE_PARTIAL` | 会計取消ボタン自体が出ない | 正常 |
| `refund-payment.php:144` | cash は 400 `CASH_REFUND` | 返金ボタン自体が出ない | 正常 |
| `receipt.php` POST | 通常どおり receipt 発行可（partial でも可） | 個別領収書発行可能 | 正常 |
| `payments-journal.php:67` | `p.is_partial` を返却 | — | 正常 |
| `cashier-app.js:1679-1698` | `canVoid = !isPartial && ...` / `canRefund = gateway && method!=='cash'` | partial 行には両ボタンとも非表示、`*` 印のみ | 正常 |
| `sales-report.php:39-47` / `register-report` / `turnover-report` | orders 正本 + `$voidExclude`（JSON_CONTAINS 連動）| 売上は orders.total ベースで積算 | 正常 |

**全 consumer で「partial_multi は触れない」が一貫して表現されている**。UI の「会計取消／返金」ボタンは最初から出さず、API も 409/400 で弾く。operator の視認 (`*` 印) と操作可否が整合している。

### 9.40.5 A/B/C/D/E 切り分けと判定

| 選択肢 | 判定 | 根拠 |
|---|---|---|
| **A. partial_multi 引き続き void 不可（`NOT_VOIDABLE_PARTIAL` を業務ルール確定として固定）** | **✅ 採用** | 現物 void 実例 0、α 7 件は既に paid 終端、β 3 件は order cancel policy 側論点、全 consumer が整合済 |
| B. order 全体やり直しのみ可（全 partial 一括 void） | ❌ 棄却 | 現物需要 0、§9.36.5 の未決 4 点（status 遷移 / voidExclude / 合流波及 / order_items.payment_id）を解く必要があり overhead 過大 |
| C. 1 payment 単位 void 可 | ❌ 棄却 | §9.36 で「単独実装不可」結論済、4d-5c-c 統合前提の意味論定義が必要 |
| D. システム非対応・運営手動リカバリ | ❌ 実質 A と同義 | 運営手動対応は「機能化しない」を意味するだけで、A が既に業務ルールとしてカバー |
| E. 4e `payment_order_links` 正規化先行必須 | ❌ 棄却 | A 採用下では 4e の導入動機がない（JSON_CONTAINS + order_items.payment_id で consumer 整合済）|

### 9.40.6 Q1〜Q5 への回答

| 問い | 回答 | 根拠 |
|---|---|---|
| **Q1** 1 payment 単位 void したいか、order 全体やり直しだけ認めるか | **どちらも不要** | void 実例 0、完全分割 α 7 件は paid 終端、手動 refund 対応で例外処理可能 |
| **Q2** void 許す場合 orders.status は paid 維持か pending_payment 戻しか | **適用せず**（A 採用で void を許さない）| — |
| **Q3** reports は partial_multi void を売上減算するか | **しない（減算議題は発生しない）** | A 採用 = void が発生しない。現行 order 正本維持は §9.39 で確定 |
| **Q4** receipt / journal / cashier UI は operator にどう説明すべきか | **現行の `*` 印 + ボタン非表示で十分、追加表記なし** | 「partial は触れない」が視覚的に伝達済 |
| **Q5** 4e `payment_order_links` は本合意の後でも必要か | **不要** | A 採用下で merged も partial_multi も関係テーブルを要求しない |

### 9.40.7 4e 正規化の最終判定

§9.37.7 の前提ツリーで「4e payment_order_links 正規化」は「論点 B 合意後に必要になり得る」と保留していた。本節の A 案合意により:

- **merged 13 件**: JSON 配列 + JSON_CONTAINS で完全整合（§9.39）
- **partial_multi 10 件**: A 案 = void 不可、reports は orders 正本維持、order_items.payment_id で item 単位紐付けは既に存在
- **single-order split 25 件**: 同じく A 案延長、single-order のため関係表現問題そのものが発生しない

→ **4e `payment_order_links` は導入動機なし、不要確定**。業務需要発生時に再評価する。

### 9.40.8 再着手トリガー（本 A 案合意を覆す条件）

以下のいずれか 2 つ以上が揃った時のみ、本合意を解除して B/C 方向の再設計に進める:

1. 業務側から「partial void が本番業務で必要」との具体的要求が報告される（test 環境ではなく本番テナントから）
2. 運用上「全員分返金 → 再会計」(refund + 再 process-payment) で回避できないケースが発生する
3. 4d-5c-c（reports payments ベース統合）が先行完了し、partial void 後の金額表現が自動定義される

**1+2 が発生するまでは A 案固定**。

### 9.40.9 §9.36 との関係

§9.36 は full-split（35 件）の意味論未確定の stop 判断。本節 §9.40 は partial_multi（10 件、full-split の subset）に限って **A 案で業務ルールとして fix** したことを記録する。§9.36 の本文は履歴保全のためそのまま維持し、§9.40 が partial_multi subset に対して最終判定を追加する構造。

single-order split（25 件 = 35 − 10）についても同じ A 案論理が延長適用可能（単一 order 単一 table / void 実例 0 / `NOT_VOIDABLE_PARTIAL` で弾かれ続ける）で、運用上は「partial 会計は全て A 案で扱う」と総括できる。

### 9.40.10 現時点で**実装しない**ものの明確化

- `payment-void.php:267-277` の `NOT_VOIDABLE_PARTIAL` 409 は**そのまま維持**（業務ルール確定として意図固定）
- 新 API / 新 error code / 新 UI は追加しない
- schema / migration 無変更
- 本番既存 partial_multi 10 件には**一切触らない**（read-only 調査のみ、SQL ダンプ `/tmp/b1_detail.sql` で実施）
- α 7 件の paid 終端は業務閉じ、β 3 件の cancelled 残骸は order cancel policy 論点に引き渡し
- 4d-5c-ba〜G / §9.36 / §9.37 / §9.38 / §9.39 の成果は完全不変

### 9.40.11 §9.37 前提ツリーの最終ステータス

```
4d-5c-c 単独実装不可 §9.37（維持、§9.39.3 で訂正記録）
    ↓
[✅ 完了] 4d-6 paid write-path 安全性 §9.38
[✅ 完了] 4d-7 merged 按分不要 §9.39
[✅ 完了] 論点 B partial_multi 業務ルール合意 §9.40（本節、A 案固定）
[❌ 不要] 4e payment_order_links 正規化（§9.40.7 で不要確定）
    ↓
4d-5c-c reports payments ベース統合
   ↑ 依然着手不可。基盤不整合（§9.37 の 4,430 差 / priceBands 意味論 / orders ベース強依存）は
     本論点 B 合意では解消しない。reports は orders 正本維持を継続
```

**4d-5c-c 自体の stop 判断は §9.37 が引き続き有効**。論点 B が閉じたことで「前提未決」は全て消化したが、§9.37 の本丸論点（revenue 実測差 / priceBands 意味論 / itemRanking の payments 非存在）は残存するため、4d-5c-c 着手可否は依然 No。残るのは業務側からの明示的需要のみ。

---

## 9.X 更新履歴

- **2026-04-21 (40) 論点 B partial_multi 業務ルール合意 — A 案（void 不可維持）で固定**: §9.36 で意味論未確定のまま停止していた split void のうち、§9.39 で 4d-7 を閉じた段階で残った唯一の必須前提「partial_multi（`is_partial=1` ∧ `JSON_LENGTH(order_ids)>=2`）の業務ルール」を現物 10 件で確定。全 10 件が `t-matsunoya-001 / s-shibuya-001 / tbl-shib-01` 単一 test 環境に集中、`distinct_tables=1`、100% cash + null gateway、void/refund 実例ゼロ。3 パターン分類: **α 完全分割（5 人割り勘 5 件 ¥4,400 + 2 人割り勘 2 件 ¥3,700、合計 7 件、全 orders.status=paid）**は `process-payment.php:554-585` の自動クローズで既に終端、**β cancelled 残骸 3 件**は order cancel policy 側論点。全 consumer（process-payment / payment-void / refund-payment / receipt / payments-journal / cashier-app / sales/register/turnover-report）で「partial は触れない」が一貫表現済、canVoid=!isPartial / canRefund=cash 不可 / 409 `NOT_VOIDABLE_PARTIAL` / 400 `CASH_REFUND` / `*` 印 UI 表示で operator 側にも視覚的伝達済。A/B/C/D/E 判定: **A 採用**（現行維持）/ B 棄却（需要 0 + §9.36.5 の未決 4 点が重すぎる）/ C 棄却（§9.36 単独実装不可確定）/ D 実質 A と同義 / E 棄却（4e 正規化の導入動機なし）。Q1〜Q5 全て「A 採用下で問題成立せず」。**4e `payment_order_links` 正規化も本合意で不要確定**（merged JSON_CONTAINS / partial_multi void 不可 / single-order split 25 件も同論理延長、関係テーブル必要場面なし）。§9.37 前提ツリー最終ステータス: 4d-6 ✅ / 4d-7 ✅ / 論点 B §9.40 ✅（A 案固定）/ 4e ❌ 不要、**4d-5c-c 着手可否は依然 No**（§9.37 本丸論点の revenue 差 / priceBands 意味論 / itemRanking payments 非存在は本合意では解消せず、残るのは業務側明示需要のみ）。再着手トリガー: 本番テナント需要発生 + refund 再会計で回避不能ケース、の 2 つ以上が揃った時のみ本合意解除。コード変更ゼロ / schema 変更ゼロ / 本番データ無変更（read-only SQL `/tmp/b1_detail.sql` のみ）/ §9.36 本文は履歴保全のため維持、§9.40 が subset 最終判定を追加する構造。4d-5c-ba〜G と §9.36〜§9.39 の成果は完全不変。詳細は §9.40。
- **2026-04-21 (39) Phase 4d-7 merged payment 金額分配ルール — 按分不要判定**: §9.37 の前提作業に切り出した **4d-7（merged payment の金額分配ルール）** を現物で確定。`JSON_LENGTH(order_ids) >= 2` を `is_partial` で分類し、**merged（非 partial）13 件 / partial_multi（split）10 件 / 合計 23 payments / 82 orders** と整理。merged 13 件の payment.total vs SUM(orders.total) 突合で **全件 diff=0（100% 整合）**、`merged_table_ids` 使用回数は 0（UI 合流会計ボタン live 実績ゼロ）。§9.37.3 で merged payment の金額乖離例として挙げていた `311a3585 ¥900 × 3 orders ¥4,580` は **`is_partial=1` の partial_multi 誤分類**、partial_multi 10 件は全て distinct_tables=1（単一 table 内分割会計）で **論点 B §9.36 の範疇**だったと訂正。全 consumer（process-payment.php write / payment-void.php / refund-payment.php / receipt.php / payments-journal.php / sales-report.php / register-report.php / turnover-report.php）が merged を既に整合的に処理済、4d-5c-a の `$voidExclude` も JSON_CONTAINS ベースで merged voided → 紐づく全 orders 同時除外を実現。5 切り分けルール判定: **A 按分しない✅既に成立 / B 比例按分❌不要 / C 均等按分❌不要 / D UI 明示❌不要 / E 全 order 一体 void/refund✅既に成立**。4d-7「merged 按分ルール」は problem statement 自体が ill-posed と確定、コード変更ゼロで停止。4e payment_order_links 正規化も単独では何も解決せず、先に §9.36 業務ルール合意が必要、その合意内容次第で 4e 自体が不要になる可能性大と再評価。§9.37.7 前提ツリー更新: 4d-6 ✅ / 4d-7 ✅ / 論点 B §9.36 ❌ 未決 / 4e ❓ 再評価、**4d-5c-c 着手可否は依然 No**、残る唯一の必須前提は §9.36 業務ルール合意。§9.37.3 本文の直接書き換えは履歴保全のため行わず、§9.39.3 に訂正記録を固定。コード変更ゼロ / schema 変更ゼロ / 本番データ無変更（read-only SQL 6 本で確認）/ 4d-5c-ba〜G と §9.36・§9.37・§9.38 の成果は完全不変。詳細は §9.39。
- **2026-04-21 (38) Phase 4d-6 paid write-path 安全性検証 — 止血不要判定**: §9.37 が前提作業に切り出した「handy paid orders の payments 記録担保（4d-6）」を現物で確定。全 live write-path を列挙（`process-payment.php` / `handy-order.php` / `update-status.php` / `update-item-status.php` / `close-table.php`）し、**paid を書けるのは `process-payment.php` のみで既に payments INSERT を同一 TX で担保**、`close-table.php` は deprecated かつ唯一の caller `accounting-renderer.js` が**どの HTML にも `<script>` 参照なし**で dead code、`update-status.php` は `'paid'` を valid 値に含むが KDS UI に `data-status="paid"` が存在せず UI 経由で送られない、と確認。7,430 件 payments-less paid orders は tenant 分布で **99.65% が `t-torimaru-001`**（2026-03: 5,671、2026-04: 1,733）+ matsunoya 26 件の demo 由来。torimaru 2026-04 の日次分布は 230〜250 件/日で均一 + 2026-04-08 急落後ゼロという「人為的形状」、発生元は `scripts/p1-27-generate-torimaru-sample-orders.php` の直接 `INSERT INTO orders VALUES ('paid','handy',...)`（process-payment.php バイパス）と特定。結論は **live 経路は clean / 7,430 は legacy demo artifact / コード変更不要で停止**。§9.37 の再着手トリガー「4d-6 完了」は本節で成立、ただし 4d-7（merged payment 金額分配ルール）/ 4e（payment_order_links 正規化）/ 論点 B §9.36 は未消化のため 4d-5c-c 着手可否は依然 No。**コード変更ゼロ / schema 変更ゼロ / 本番データ無変更 / 4d-5c-ba〜G と §9.36・§9.37 の成果は完全不変**。defense-in-depth 候補（close-table.php 404 化 / update-status.php 'paid' reject / demo seeder 書き換え）は別スライスに切り出し、今回スコープ外。詳細は §9.38。
- **2026-04-21 (37) Phase 4d-5c-c（reports payments ベース統合）— 単独実装不可判断**: 3 reports (sales/turnover/register) を payments ベースに寄せる案を現物調査し、**paid orders 7,546 件中 7,430 件 (98.5%) が payments 行 0 件**（handy / 旧 POS 経路）という基盤不整合が存在することを確定。orders と payments の 1:0 / 1:N / N:1 / N:M が共存し、merged payment では orders SUM（例 ¥4,580）と payments SUM（例 ¥900）が乖離する。revenue 実測（matsunoya shibuya 2026-04 21 日間 90 vs 85 / ¥154,270 vs ¥166,530、差 ¥12,260）で基盤不整合を数値実証。5 つの設計問い（totalRevenue / paymentBreakdown / priceBands / orderCount / merged 対応）全て NO、4d-5c-c 単独では閉じないと確定。既に payments/cash_log 正本で正しい部分（externalMethodBreakdown / cashLog / expectedBalance / cash_log.cash_out 相殺）は維持、残りは orders 正本の壁で崩せない。次の前提作業は **4d-6 (handy paid の payments 記録担保)** → **4d-7 (merged payment 金額分配ルール)** → **4e (payment_order_links 正規化)** → 論点 B §9.36 → 4d-5c-c、の順。コード変更ゼロ / schema 変更ゼロ / 本番データ無変更 / 4d-5c-ba〜G と §9.36 の成果は完全不変。詳細は §9.37。
- **2026-04-21 (36) 論点 B（分割会計 void）実装前停止記録**: 現物調査で `is_partial=1` 35 件（100% cash + null gateway + matsunoya + dine_in、void 実例 0、test 痕跡と実運用混在）を確認、コード上の未確定ポイント 4 件（orders.status paid 化契機 / 4d-5c-a `$voidExclude` 仕様 / 合流会計波及 / order_items.payment_id 扱い）と業務ルール未決 5 項目を切り分けて **意味論未確定を理由に実装前停止**。コード変更なし、schema 変更なし、本番データ無変更、既存 4d-5c-ba〜G の成果完全不変。再着手トリガー（業務需要合意 / 4d-5c-c 統合先行 / 試行錯誤で業務フロー確定）を docs に固定し、次回再調査コストを下げる。`payment-void.php:269-279` の `NOT_VOIDABLE_PARTIAL` 409 はそのまま維持。詳細は §9.36。
- **2026-04-21 (35) Phase 4d-5c-bb-G checkout-confirm.php verify context bug 是正**: 4d-5c-bb-D takeout-payment.php で解消した「Connect Checkout destination charges の session retrieve context 不一致」と**完全同型の bug** が `api/customer/checkout-confirm.php:70` に残っていたため、同じく第 3 引数 `$connectInfo['stripe_connect_account_id']` を削除 (1 行)。create 側 `checkout-session.php:139` は `create_stripe_connect_checkout_session` で destination charges を作り、session が Platform account 側に存在するため、retrieve も Platform context で叩く必要があった。本番 test mode で実証: Phase 2a (修正前) で新 test order `44f31d9d-...` + session `cs_test_a1z7Nb...` を使い、Stripe A/B retrieve で Platform=200/paid / Connect=404/No such session を確認、checkout-confirm.php が **HTTP 502 PAYMENT_GATEWAY_ERROR** (E6014) を返すこと、menu.html に「Stripe設定が見つかりません」エラー表示が出ることを実測。Phase 2b で 1 行修正 deploy。Phase 2c で同 session_id 再叩き → **HTTP 200**、payment_id=3bac1c88-... / gateway_name=stripe_connect / external_payment_id=pi_3TObj0IYcOWtrFXd1bdyWtb0 / gateway_status='succeeded' / orders.status='paid' の成功証跡取得。既存 dine_in + stripe_connect 13 件 (2026-04-05〜04-14 作成、過去コードの産物仮説 A 確定) には触らず、新規 dine_in online の正常経路のみを回復。`takeout-payment.php` / refund-payment.php / payment-void.php / 4d-5c-bb-A〜F は全て不変。schema / migration なし。バックアップ `_backup_20260421p/`。詳細は §9.35。
- **2026-04-21 (34) Phase 4d-5c-bb-F takeout-payment silent-fail 是正 + customer-facing false-positive 解消**: `api/customer/takeout-payment.php` の Stripe verification 後の DB 永続化 (`orders.status` UPDATE + `payments` INSERT) を単一 TX にまとめ、失敗時は `rollBack + json_error('PAYMENT_RECORD_FAILED', 汎用メッセージ, 500)` に変更。従来は catch でログだけ出して `payment_confirmed:true` を返す silent-fail 設計で、payments 欠落 + orders.status='pending' の DB 不整合が永続化する危険があった。`external_payment_id` 等の Stripe 内部識別子は `php_errors.log` のみに記録し customer-facing response には露出させない。あわせて `public/customer/js/takeout-app.js` の `handlePaymentReturn` success 分岐の `.catch` を「section 5 完了画面」から「赤字『決済確認に失敗しました』+ 店舗連絡案内 + 注文番号表示」に差し替え、cancel 分岐 (オレンジ) と視覚的に分離。これにより verify 失敗 / STRIPE_MISMATCH / 永続化失敗 / 通信失敗 の全エラー系で「ご注文を受け付けました」の false positive が出なくなる。`public/customer/takeout.html` の cache buster を `?v=20260421-pwa4d-5c-bb-F` に bump。新 error code `PAYMENT_RECORD_FAILED (E6028)` を `scripts/generate_error_catalog.py` の PINNED_NUMBERS に追加、`api/lib/error-codes.php` と `scripts/output/{error-audit.tsv, error-codes.json}` + catalog MD を再生成。正常成功経路 / 冪等性早期 return / refund-payment.php / payment-void.php / 4d-5c-bb-A〜E の成果は完全不変、schema 変更なし。本番検証: 正常系 E2E で takeout Connect decision → 完了画面表示 → `orders.status='pending'` + payments 行作成 回帰確認、障害注入は本番安全性のため skip (安全な INSERT 失敗手段なし、UNIQUE 制約なし + PRIMARY KEY random 生成)、コード/UI レベル実証で代替。バックアップ `_backup_20260421o/`。詳細は §9.34。
- **2026-04-21 (33) Phase 4d-5c-bb-E Stripe refund reason enum 正規化**: 4d-5c-bb-D の follow-up #5 を最小 slice で解消。`api/lib/payment-gateway.php` に新 private helper `_normalize_stripe_refund_reason($reason)` 追加 (`duplicate` / `fraudulent` / `requested_by_customer` 以外は `'requested_by_customer'` に fallback、enum 値は素通し) し、3 refund helper (`create_stripe_refund` / `create_stripe_connect_refund` / `create_stripe_destination_refund`) の `'reason' => $reason ?: 'requested_by_customer'` を `'reason' => _normalize_stripe_refund_reason($reason)` に差し替え (3 箇所 1 行ずつ)。**refund-payment.php / DB `payments.refund_reason` / 監査ログの自由文保持は不変**、routing / preflight / claim-revert 構造も不変。本番 test mode E2E: 新規 takeout Connect order `48f09085-...` 決済後、自由文日本語 107 文字 reason で refund → HTTP 200 / refund_id=`re_3TOaEWIYcOWtrFXd1P7Su27T` / Stripe 側 Refund.reason=`requested_by_customer` (正規化確認) / transfer_reversal=`trr_1TOaFwIYcOWtrFXd25MlePBJ` (destination charges 成立) / POSLA DB refund_status='full' / refund_reason 自由文 107 文字完全保持。4d-5c-bb-D 既存成功証跡 `a90993dd...` 不変。バックアップ `_backup_20260421n/`。詳細は §9.33。
- **2026-04-21 (32) Phase 4d-5c-bb-D Connect Checkout takeout の refund 経路整備**: 4d-5c-bb-D post1 時点で「未実証論点」として切り出していた **Stripe Connect (`gateway_name='stripe_connect'`) takeout refund** を解消。preparatory 3 件 (URL 構築 `takeout-orders.php:398` / verify context `takeout-payment.php:63` / gateway_status 正規化 `takeout-payment.php:77,111`) を個別 slice で順次修正し、最後に D-fix1/2/3 統合パッチで本命整備: (D-fix1) `gateway_status='succeeded'` ハードコード (`checkout-confirm.php:77` と揃え、refund-payment.php:152 INVALID_STATUS を解消)、(D-fix2) 新 helper `create_stripe_destination_refund($platformSecretKey, $paymentIntentId, $amountYen, $reason)` を `payment-gateway.php` に追加 (Stripe-Account なし / Platform context / `reverse_transfer=true` / `refund_application_fee=true` の canonical destination charges refund)、`refund-payment.php:185` の Connect 分岐を **3 分岐化** (Pattern B `stripe_connect_terminal` / Pattern C `stripe_connect` / Pattern A else)、(D-fix3) claim 前 preflight を徹底 (経路判定 + config 取得を `UPDATE refund_status='pending'` の前へ移動、config 不足は claim に立てず `rollBack + json_error`、claim 後の失敗 exit は `refund_failed revert` パス 1 箇所に統一)。本番 test mode 実証: payment `a90993ddb899c7fe6592c6c38b80c8bf` (takeout Connect, ¥300) に対し refund 成功 `re_3TOYeSIYcOWtrFXd2Vpiscix` / **transfer_reversal `trr_1TOYgoIYcOWtrFXdO3Y1PZX6`** 発行で destination charges の Connect account transfer 引き戻しまで Stripe 側で確定。D-fix3 revert は初回試行時に Stripe 自由文 reason が enum 制約で失敗 REFUND_FAILED 502 になった副次機会で実運用条件下で実証 (refund_status='pending' → 'none' / refunded_at=NULL 自動復元)。`payment-void.php` は**不変** (online/gateway takeout は引き続き NOT_VOIDABLE_GATEWAY 409 で弾く、void と refund の責務分離堅持)。`checkout-confirm.php:70` も**触らない** (dine_in online 既存 13 件は別スライス)。バックアップ `_backup_20260421j/` (URL) / `_backup_20260421k/` (verify) / `_backup_20260421m/` (D-fix)。5 件目 follow-up として **Stripe `reason` enum 制約** (3 値 `duplicate`/`fraudulent`/`requested_by_customer` 限定) を明示 (今回スコープ外、別スライス候補、詳細 §9.32.7)。詳細は §9.32。
- **2026-04-21 (31) Phase 4d-5c-bb-C POS cashier 経由 takeout の void 拡張**: `api/store/payment-void.php:310` の `order_type` ガードを `'dine_in'\|'handy'` から `'dine_in'\|'handy'\|'takeout'` に緩和。冒頭コメントブロック (`:14-35` / `:59` / `:275-280`) と 409 メッセージを追随更新。`public/kds/js/cashier-app.js:1687-1698` は canVoid コメントのみ更新（実行ロジック不変）。`public/kds/cashier.html` の cache buster を `?v=20260421-pwa4d-5c-bb-C` に。**他の判定は全て不変**: 三重ガード / `orders.status='paid'` / `gateway_name=''` / `is_partial=0` / cash_log INSERT は `payment_method==='cash'` 条件 / orders / table_sessions / session_token は触らない / refund / receipt / reports の連動ガードは 4d-5c-ba から自動適用。**対象**: POS cashier 経由 takeout の cash/no-gateway/status='paid' のみ。**対象外**: online takeout (`card+stripe/stripe_connect`, `status='served'` 終端 — `takeout-management.php` の allowed 遷移に 'paid' なし)、Stripe Connect 非 terminal refund は引き続き未実証。本番実測: 未認証 401 / cache-buster 200 / 正常 takeout cash void 200 (payment=53cfd0e2 ¥950, order=7ff021cd, cashLogId=b5f723e1) / orders.status=paid 維持 / cash_log.cash_out ¥950 INSERT / gateway 409 / partial 409 / served takeout は一時 DB 差し替えで 409 NOT_VOIDABLE_ORDER_STATUS 確認→復元 / idempotent 200 / refund 409 ALREADY_VOIDED / receipt POST 409 VOIDED_PAYMENT / sales-report 2026-04-18 orderCount=1 totalRevenue=¥1000 (voided ¥950 除外) / register-report cashLog cash_out ¥950 / turnover-report priceBands 反映。バックアップ `_backup_20260421i/`。DB 一時改変 (order status flip / PIN hash swap) は全て復元済み。残スコープ: online takeout + Connect 非 terminal refund (別論点) / 論点 B 分割 / 論点 D 返金チェーン。詳細は §9.31。
- **2026-04-21 (30) Phase 4d-5c-bb-A-post1 takeout online payment ENUM mismatch 修正**: `api/customer/takeout-payment.php` の payments INSERT で `payment_method="online"` を使っていたが、`payments.payment_method` は `ENUM('cash','card','qr')` で `'online'` は未収録。`STRICT_TRANS_TABLES` 下で ERROR 1265 Data truncated が発生し try/catch で握りつぶされていたため、オンライン takeout 決済の payments 行が永続的に欠落していた。既存 `checkout-confirm.php:276` と同じ `payment_method='card'` に揃えて解消（gateway_name と external_payment_id で「オンライン由来」を識別、UI / 集計は既存 card+gateway パターンで扱える）。schema 変更なし、migration なし、1 ファイル 1 箇所の最小スライス。本番検証: `php -l` OK / simulated INSERT card 成功・online 失敗を再現 / 未認証 400 / 不在 404 / 既存 paid 200 / takeout-orders.php の `'online'` アプリ層フラグ不変 / php_errors.log に新規 Data truncated なし。**未実証・別論点**: Stripe Connect takeout の refund は `refund-payment.php:185` が `stripe_connect_terminal` のみ特別扱いで、非 terminal Connect は Direct 経路へフォールスルーする既存挙動。本スライスでは refund まで踏み込まない。バックアップ `_backup_20260421h/`。論点 C (takeout void 本体) は引き続き別スライス。詳細は §9.30。
- **2026-04-21 (29) Phase 4d-5c-bb-A 手動 card / 手動 qr の void 拡張**: `api/store/payment-void.php` の `NOT_VOIDABLE_METHOD` ブロックを削除し、`payment_method ∈ {'cash','card','qr'}`（ENUM 全値）を許可。後続の `gateway_name` ガードはそのままなので Stripe Connect など経由の card/qr は引き続き 409 `NOT_VOIDABLE_GATEWAY` で弾く。`public/kds/js/cashier-app.js` の `canVoid` 条件から `method==='cash'` を除去し `!isRefunded && !isVoided && !gatewayName && !isPartial` に緩和。`cashier.html` の cache buster を `?v=20260421-pwa4d-5c-bb-A` に更新。**`cash_log` INSERT ロジックは既に `=== 'cash'` 条件で守られているので手動 card / qr では発火しない**（cashLogId=null）。三重ガード / 補正(2) / refund / receipt 連動ガードは 4d-5c-ba のものをそのまま活用、新規 migration なし。本番実測: card+stripe_connect が 409 NOT_VOIDABLE_METHOD → **409 NOT_VOIDABLE_GATEWAY** に変わり（method ガード削除・gateway ガード活性の実証跡）、手動 card payment=4b398179 (¥3150) の void 成功 / cashLogId=null / orders.status=paid 維持 / sales-report 除外 ✅。バックアップ `_backup_20260421f/`。残スコープ: 論点 B 分割 (未着手) / 論点 C takeout (別バグ先行) / 論点 D 返金チェーン (実データ 0 件でスコープ外確定)。詳細は §9.29。
- **2026-04-21 (28) Phase 4d-5c-ba 通常会計 void (cash + 注文紐付き)**: 新規 API `api/store/payment-void.php` (POST only / manager|owner / TX+FOR UPDATE / reason 必須)。対象は `process-payment.php` 経由通常会計の `cash + gateway_name IS NULL + is_partial=0 + order_type IN ('dine_in','handy')`。三重ガード (`order_ids JSON_LENGTH>0` / `note NOT LIKE 'emergency_%'` / `synced_payment_id IS NULL`) + 補正(2) で緊急会計系 / takeout / 分割を 409 で区別。**A 案踏襲**: `payments.void_status='voided'` のみ、`orders.status='paid'` / `paid_at` / `payment_method` / `table_sessions` / `tables.session_token` は**触らない**。cash のみ `cash_log.cash_out` 相殺行 INSERT (note=`通常会計取消 payment_void:<id>`、ENUM 拡張なし)。連動ガードとして `refund-payment.php` に先頭 409 `ALREADY_VOIDED`、`receipt.php` POST に 409 `VOIDED_PAYMENT` / `REFUNDED_PAYMENT` を追加（GET detail=1 再印刷は許可）。`payments-journal.php` は既存 4d-5a の void_status 返却を流用。`public/admin/js/admin-api.js` に `voidNormalPayment` 追加、`public/kds/js/cashier-app.js` 取引ジャーナルに「会計取消」ボタン + netTotal (total − refund − void) + 「取消済」KPI + voided 行赤バッジ。cache buster `?v=20260421-pwa4d-5c-ba` (`cashier.html` / `dashboard.html`)。**`process-payment.php` / emergency 系 / `customer/takeout-payment.php` / `payments.payment_method` ENUM / `cash_log.type` ENUM / SW / 顧客画面 / 新規 migration は一切なし**。本番実測: card 409 NOT_VOIDABLE_METHOD / partial 409 NOT_VOIDABLE_PARTIAL / takeout 409 NOT_VOIDABLE_ORDER_TYPE / emergency 409 NOT_VOIDABLE_IN_4D5C_BA / 正常 cash void ¥900 成功 (cashLogId 発行 / orders.status=paid 維持) / voided 後 refund 409 ALREADY_VOIDED / receipt POST 409 VOIDED_PAYMENT / receipt GET detail=1 200 / sales-report 除外 ✅。バックアップ `_backup_20260421e/` (7 ファイル、payment-void.php は新規)。残スコープ: 論点 A 手動 card/qr (→ 4d-5c-bb-A) / B 分割 / C takeout / D 返金チェーン。詳細は §9.28。
- **2026-04-21 (27) Phase 4d-5c-a 売上系レポートの全 voided 注文除外**: `sales-report.php` / `turnover-report.php` / `register-report.paymentBreakdown` に共通 NOT EXISTS 句を追加し、「注文に紐づく payments が 1 件も active でなければ集計から除外」する。**A 案サブセット**: orders ベース集計を維持しつつ、有効 payment 判定 (`void_status IS NULL OR <> 'voided'`) でフィルタ。分割会計の「一部 voided」は active が 1 件残れば全額計上を維持 (現状仕様)。`cooking analysis` / `table_sessions 回転率` / `cashLog` / `externalMethodBreakdown` は触らない。migration-pwa4d5a 未適用環境は動的フォールバック。本番実測: 2026-04-20 の void 済 2 件 (T01 1850 + T03 850) が sales/turnover/register から完全除外、active 88 件 (¥151,570) は不変。**新規 migration なし、UI 不変、cache buster 不要、process-payment / refund-payment / receipt / customer 系 / SW / 顧客画面は一切変更なし**。残件: 通常会計 void (4d-5c-b) / payments ベース統合 (4d-5c-c) / 分割会計一部 voided / JSON_CONTAINS 性能。詳細は §9.27。
- **2026-04-21 (26) Phase 4d-5b 注文紐付き緊急会計転記 void**: 新規 API `api/store/emergency-payment-transfer-void.php` (POST only / manager|owner / TX+FOR UPDATE / reason 必須)。三重ガード (synced_payment_id+JSON_LENGTH>0 / payments.order_ids JSON_LENGTH>0 / note LIKE '%emergency_transfer src=%' AND NOT LIKE '%emergency_manual_transfer%') で 4c-2 経路のみに絞る。手入力計上は 409 NOT_VOIDABLE_IN_4D5B (4d-5a 誘導)、通常会計も 409 (4d-5c 待ち)。**A 案採用**: payments voided のみ、orders.status='paid' 維持、table_sessions / session_token は戻さない (KDS 再表示・卓状態復活の事故回避)。cash 相殺のみ cash_log.cash_out 自動追加 (note='緊急会計転記取消')。emergency_payments.synced_payment_id は履歴保持、transfer_note に [voided at ...] 追記。`admin-api.js` に voidTransferEmergencyPayment 追加。UI: 注文紐付き未 void 行に「緊急会計転記を取り消す」ボタン (オレンジ #ef6c00、手入力 void の赤と区別) + reason 必須 + 確認ダイアログで「sales-report に残る」「テーブルセッション/QR 戻らない」明記。既存 `paymentVoidStatus` バッジ (4d-5a) を流用。cache buster `?v=20260421-pwa4d-5b` (admin-api.js / emergency-payment-ledger.js)。**新規 migration なし、orders / table_sessions / tables / refund-payment / receipt / sales-report / turnover-report / register-report.paymentBreakdown / process-payment / SW / 顧客画面は一切変更なし**。残リスク: sales-report / turnover-report / paymentBreakdown には残る (4d-5c で sales-report 統合時に解消予定)。詳細は §9.26。
- **2026-04-21 (25) Phase 4d-5a 手入力計上 void**: 新規 migration `sql/migration-pwa4d5a-payment-void.sql` で payments に void_status VARCHAR(20) DEFAULT 'active' + voided_at/by + void_reason + idx_pay_void_status 追加 (PROCEDURE 冪等)。新規 API `api/store/emergency-payment-manual-void.php` (POST only / manager|owner / TX+FOR UPDATE / reason 必須)。対象: order_ids=[] AND note に emergency_manual_transfer を含む payments のみ (二重ガード)。通常会計 / 注文紐付き transfer は 409 NOT_VOIDABLE_IN_4D5A で拒否。cash の場合のみ cash_log.cash_out 相殺行 INSERT (ENUM 拡張なし、既存 'cash_out' 流用)。synced_payment_id は履歴保持、transfer_note に void 情報追記。`ledger.php` が bulk SELECT で payments の void 状態を返却 (N+1 回避、store_id 二重境界)。`register-report.php` の externalMethodBreakdown に `void_status<>'voided'` 条件追加 (動的)。`payments-journal.php` は void 状態を返却。UI: 詳細モーダルに「手入力計上を取り消す」ボタン (赤 #c62828) + 理由入力必須、voided 行に「取消済み」バッジ (日時列 + 詳細)。cache buster `?v=20260421-pwa4d-5a` (admin-api.js / emergency-payment-ledger.js)。**refund-payment.php / receipt.php / sales-report.php / process-payment.php / transfer.php / manual-transfer.php / orders.status / table_sessions / payments.payment_method ENUM / cash_log.type ENUM / SW / 顧客画面は一切変更なし**。残件: 注文紐付き transfer の void (4d-5b) / 通常会計 void (4d-5c) / sales-report 統合。詳細は §9.25。
- **2026-04-21 (24b) Phase 4d-4a hotfix1**: `emergency-payment-ledger.js` 手入力 other_external 未分類分岐に分類フォーム (`epl-ext-method-sel` + `epl-ext-method-save`) を表示するよう修正 (旧データの後追い分類導線が無かった欠陥の解消)。確認ダイアログ文言を「手入力**認**として」→「手入力**売上**として」に修正 (誤字)。cache buster `?v=20260421-pwa4d-4a-hotfix1`。挙動ロジックは `_submitExternalMethod` 既存を再利用、新規 API 追加なし。
- **2026-04-21 (24) Phase 4d-4a 手入力売上計上**: 新規 `api/store/emergency-payment-manual-transfer.php` (POST only / manager|owner / TX+FOR UPDATE / synced_payment_id 流用で idempotent)。対象: resolution_status=confirmed AND orderIds 空 AND payment_method 許可 4 種 AND other_external は分類済み。INSERT 内容は transfer.php と同方針で payments.order_ids='[]' / table_id=NULL。cash のみ cash_log.cash_sale INSERT (created_at=$paidAt)。`emergency-payment-ledger.php` の summary に `manualTransferableCount` 追加。UI: 手入力モードに「売上として計上する (手入力)」ボタン (#6a1b9a) + KPI タイル追加、確認ダイアログに「売上レポートには出ない」明記。admin-api に `manualTransferEmergencyPayment` 追加。cache buster `?v=20260421-pwa4d-4` (admin-api.js / emergency-payment-ledger.js 両方)。**sales-report.php / register-report.php / receipt.php / refund-payment.php / process-payment.php / transfer.php / payments.payment_method ENUM / cash_log.type ENUM / SW / 顧客画面は一切変更なし**。残件: 売上取消 / sales-report 統合 / receipt 明細入力は 4d-5 以降。詳細は §9.24。
- **2026-04-21 (23) Phase 4d-3 other_external 転記解禁 + 分類編集 + レジ分析内訳**: `emergency-payment-transfer.php` の other_external 409 分岐を「未分類時のみ」に縮小。`payments.payment_method='qr'` に寄せ、`payments.external_method_type` に分類を保存。`ledger.php` の `transferable_c` 条件拡張、`register-report.php` に `externalMethodBreakdown` 追加。新規 `emergency-payment-external-method.php` (manager/owner 限定、payment_method!=other_external or synced_payment_id 既存なら 409、allowlist 外は 400、同値 idempotent)。UI は台帳に未分類→分類保存フォーム / 分類済み→転記ボタン、register-report.js に外部分類別セクション。cache buster `?v=20260421-pwa4d-3`。**`payments.payment_method` ENUM / process-payment / refund-payment / payments-journal / receipt / SW / 顧客画面は一切変更なし**。残件: orderIds 空の手入力、table_id 空の合流、転記取消は引き続き対象外。詳細は §9.23。
- **2026-04-21 (22) Phase 4d-2 other_external 外部分類**: 新規 migration `sql/migration-pwa4d2a-emergency-external-method-type.sql` / `sql/migration-pwa4d2b-payments-external-method-type.sql` で `emergency_payments` / `payments` 両方に `external_method_type VARCHAR(30) DEFAULT NULL` 追加 (PROCEDURE 冪等、backfill なし)。API: `emergency-payments.php` に allowlist (voucher/bank_transfer/accounts_receivable/point/other) + paymentMethod=other_external 時のみ保存 + 不正値は 400 VALIDATION + silent drop ルール + INSERT 動的注入。`emergency-payment-ledger.php` が SELECT/レスポンス拡張 + `hasExternalMethodTypeColumn`。UI: emergency-register.js で other_external 選択時のみ分類 select 表示 (初期値 "" + 未選択 alert)。台帳 `_methodLabel` 拡張 + 詳細モーダルに外部分類行。hotfix1 で `accounts_receivable` の表示を「売掛」に修正し、cache buster を `20260421-pwa4d-2-hotfix1` に更新。**`emergency-payment-transfer.php` / payments.payment_method enum / process-payment.php / register-report / payments-journal / refund-payment / receipt / SW / 顧客画面は一切変更なし**。POSLA PAY slug は予約のみで今回追加せず。詳細は §9.22。
- **2026-04-20 (21) Phase 4d-1 PIN 入力有無の永続化 + 台帳 UX**: 新規 `sql/migration-pwa4d-pin-entered-client.sql` で `emergency_payments.pin_entered_client TINYINT(1) DEFAULT NULL` 追加 (PROCEDURE 冪等)。条件付き backfill は (a) `staff_pin_verified=1` / (b) `conflict_reason LIKE '%pin_entered_but_not_verified%'` のみ 1 を埋め、それ以外は NULL 残置。API: `emergency-payments.php` POST が `pinEnteredClient` を 0/1/NULL で永続化 (migration 未適用環境は事前チェックで旧 INSERT にフォールバック)。`emergency-payment-ledger.php` が records に `pinEnteredClient` を追加、レスポンスに `hasPinEnteredClientColumn`。UI: 列「状態/解決」を「同期状態/管理者判断」に改名、PIN セルを **4 状態** (確認済 / 入力不一致 / 入力なし / 未確認)、担当セルに PIN 未検証時 `(自己申告)` prefix、手入力記録に薄黄色バッジ + 行背景。cache buster `?v=20260420-pwa4d-1`。**payments / orders / cash_log / レポート系 API / process-payment.php / refund-payment.php / SW / 顧客画面は一切変更なし**。詳細は §9.21。
- **2026-04-19**: 初版 (Q1=A スコープ別カラー / Q2=B 全 staff HTML 対象)
- **2026-04-20 (1)**: 標準環境ポリシー追加 (§9.10)。Android Chrome を業務端末標準環境に確定。`standard-env-banner.js` を追加し KDS/cashier/handy/pos-register に配置。iOS Safari は補助・サポート対象外として明記。
- **2026-04-20 (2)**: PWA インストール案内バナー (§9.11) 実装。`install-prompt-banner.js` を `admin/dashboard.html` と `admin/owner-dashboard.html` に配置 (StandardEnvBanner からの差し替え)。`beforeinstallprompt` を使った能動的インストール促進と iOS フォールバックを実装。
- **2026-04-20 (3)**: SW VERSION の真実は `sw.js` 内 `var VERSION` 変数に統一 (ヘッダコメントからバージョン表記を削除)。本ドキュメント §9.5 のキャッシュ名リストを `<VERSION>` 表記に更新し、現行版を併記。
- **2026-04-20 (4) Phase 1**: `pwa-update-manager.js` (更新バナー + キャッシュリセット) を 6 画面に配置、`wake-lock-helper.js` を KDS のみに配置。SW VERSION バンプ (admin v4→v5 / kds v2→v3 / handy v2→v3) + message handler 追加 (SKIP_WAITING / GET_VERSION)。詳細は §9.12。
- **2026-04-20 (5) Phase 1 修正**: 真の手動更新方式に統一。3 SW の install ハンドラから `self.skipWaiting()` を削除、3 pwa-register.js から `reg.waiting.postMessage({type:'SKIP_WAITING'})` の自動送信を削除。新版 SW は waiting に留まり PWAUpdateManager の「更新する」ボタンでのみ activate される。SW VERSION 再バンプ (admin v5→v6 / kds v3→v4 / handy v3→v4)。KDS Wake Lock を 3 経路 (起動ボタン / 店舗選択 / mt_kds_started skip) に拡大 + ステータス要素クリックで手動再取得。キャッシュリセット confirm 文を「入力途中の内容は失われる場合があります」に修正。§9.5 / §9.12 / §9.12.4 を新仕様に合わせて全面改訂。
- **2026-04-20 (6) Phase 2a**: Web Push 購読土台 + バッジ管理を追加。`push_subscriptions` テーブル新設、`api/push/{config,subscribe,unsubscribe}.php` 新設、`api/lib/push.php` (送信関数スタブ)、`public/shared/js/{push-subscription-manager,badge-manager}.js` 新設。3 SW に push / notificationclick / message ハンドラ追加 (VERSION admin v6→v7 / kds v4→v5 / handy v4→v5)。admin/owner にフル UI 設定パネル、KDS/handy にヘッダ簡易表示 + バッジ更新を配置。**実送信は Phase 2b へ分離** (Composer 未導入により VAPID JWT + AES-GCM 暗号化を安全に追加できないため)。詳細は §9.13。
- **2026-04-20 (7) Phase 2b**: Web Push 実送信を純 PHP で実装 (Composer / vendor 不使用)。新規 5 ファイル: `scripts/generate-vapid-keys.php` (VAPID 鍵生成 CLI) / `api/lib/web-push-encrypt.php` (RFC 8291 aes128gcm) / `api/lib/web-push-vapid.php` (VAPID JWT ES256 + DER→raw) / `api/lib/web-push-sender.php` (cURL POST + 410/404 で soft delete) / `api/push/test.php` (テスト送信 self/store)。修正 3 ファイル: `api/lib/push.php` (no-op スタブ → 実送信)、`api/kds/call-alerts.php` (kitchen_call で `push_send_to_store('call_alert')`)、`api/customer/satisfaction-rating.php` (rating ≤ 2 で `push_send_to_roles(['manager','owner'], 'low_rating')`)。VAPID 鍵を `posla_settings` (`web_push_vapid_public` 87 char / `web_push_vapid_private_pem` 241 char) に投入。fail-open 三重防御 (sender / push.php / 業務 API の各層で例外握りつぶし)。デプロイ手順書・鍵管理ルール・トラブルシュート・ロールバック手順を §9.14 に詳細記載。
- **2026-04-20 (20) Phase 4c-2 payments 転記**: 新規 `sql/migration-pwa4c2-emergency-payment-transfer.sql` で emergency_payments に transferred_* 4 カラム + `idx_emergency_synced_payment` 追加 (PROCEDURE で再実行安全)。新規 `api/store/emergency-payment-transfer.php` (POST only) — TX + FOR UPDATE + orders rowCount 検証で二重売上を絶対防止。synced_payment_id ある場合は早期 idempotent return。confirmed 以外 / failed / total_amount<=0 / other_external / orderIds 空 / 既存 payments 重複 / orders.paid or cancelled は 409 CONFLICT。payments INSERT は payment_method を 'cash'/'card'/'qr' に mapping、gateway_name に emergency.paymentMethod を保存、paid_at は client_created_at > server_received_at の順で決定 (NOW() を第一選択にしない)。tax split は itemSnapshot.taxRate から再集計、不明なら 10% 寄せ + approximated マーク。order_items / table_sessions は触らない (Phase 4d)。ledger API 拡張: summary に transferableCount/transferredCount、records に transferred_* 4 フィールド、hasTransferColumns レスポンス、migration 事前チェックで fallback 設計。UI: KPI タイル 10 個 (転記可能/転記済み追加)、詳細モーダルに「売上転記」ブロック (状態別表示分岐 / confirm ダイアログで金額・控え確認要求 / 二重クリック防止 / 409 専用トースト)。**process-payment.php / payments schema / orders.status ENUM / order_items は一切変更なし**。詳細は §9.20。
- **2026-04-20 (19) Phase 4c-1 管理者解決フロー**: 新規 migration `sql/migration-pwa4c-emergency-payment-resolution.sql` で emergency_payments に resolution 5 カラム + 2 INDEX 追加 (INFORMATION_SCHEMA + PROCEDURE で再実行安全)。新規 `api/store/emergency-payment-resolution.php` (POST) で manager/owner が「有効確認 / 重複扱い / 無効扱い / 保留」を記録。state machine: 同値 idempotent / 終結状態 → 異なる値は 409 CONFLICT。TX + FOR UPDATE で単一レコード順序保証。duplicate/reject は note 必須。`emergency-payment-ledger.php` を拡張して summary 5 KPI + records 5 フィールドを追加、migration 未適用時は SQLSTATE 42S22 を catch して legacy SELECT にフォールバック (`hasResolutionColumn=false` を返却)。`admin-api.js` に `resolveEmergencyPayment` 追加。`emergency-payment-ledger.js` UI に解決列 + 4 KPI 追加 + 詳細モーダルに 4 操作ボタン + 二重クリック防止 + 409 ハンドリング + migration 未適用時の警告表示。**payments 転記・売上反映・orders/order_items 変更は一切しない (Phase 4c-2 以降)**。詳細は §9.19。
- **2026-04-20 (18) Phase 4b-1 / 4b-2 子テーブル + 管理画面台帳**: 新規 `emergency_payment_orders` テーブル (UNIQUE (store_id, order_id, status='active') で二重登録を DB レベル封じる)。`emergency-payments.php` POST に子 INSERT 追加 (同一トランザクション、ER_DUP_ENTRY → status='conflict' / 42S02 → status='pending_review' に UPDATE)。新規 `api/store/emergency-payment-ledger.php` 管理画面向け GET API (manager/owner 限定、期間 + status フィルタ、KPI 4 種)。`admin-api.js` に `getEmergencyPaymentLedger` 追加。新規 `public/admin/js/emergency-payment-ledger.js`。dashboard.html レポートグループに「緊急会計台帳」タブ追加 (読み取り専用、操作ボタンなし)。**payments 転記 / 売上反映 / 承認 / 取消はすべて Phase 4c 以降に分離**。詳細は §9.18。
- **2026-04-20 (17) Phase 4 レビュー指摘対応 (3回目)**: `api/store/emergency-payments.php` の `$isManualEntry` 判定を **`$orderIds` パース後**に移動。旧実装では `empty($orderIds)` がパース前の未定義変数を評価して常に true となり、`tableId=null` の payload (合流会計など) が誤って `manual_entry_without_context` で `pending_review` に落ちる問題があった。コードに「必ず orderIds パース後に行う」旨の恒久コメントを追加して再発防止。
- **2026-04-20 (16) Phase 4 レビュー指摘対応 (2回目)**: (#1) 手入力モード確実に `pending_review` へ — IDB の `hasTableContext` を POST body に送信するよう変更、サーバー側で `hasTableContext=false` または (`tableId` 空 AND `orderIds` 空) を `isManualEntry` 判定し `conflict_reason='manual_entry_without_context'` で pending_review 化。subtotal 補正による金額差異すり抜けの抜け道を塞ぐ。(#2) 同時同期の race を防止 — orderIds がある場合、status 判定から INSERT までを **単一トランザクション**で実行、先頭で `SELECT id FROM orders WHERE id IN (?) FOR UPDATE` で対象注文をロック。別端末の同時 POST は先行トランザクションのコミット完了まで待機し、次に SELECT したときには emergency_payments に前の記録が見えるため `emergency_duplicate_order` で `conflict` 化される。idempotent 既存 + ER_DUP_ENTRY fallback も commit/rollback でクリーンアップ。Phase 4b で `emergency_payment_orders` 子テーブル + UNIQUE(store_id, order_id) によるさらに厳格な重複防止を検討課題として明記。
- **2026-04-20 (15) Phase 4 レビュー指摘対応**: (#1) emergency_payments 内での `orderId` 重複検出追加 — 別 localId で同じ注文を緊急会計すると `conflict` (`emergency_duplicate_order`)。(#2) `CashierApp.getEmergencyContext()` がテーブル/品目なしでも store-level snapshot (hasTableContext=false) を返すよう変更。手入力モードが動作するように。例外時も storeId フォールバック。(#3) POST API で `tableId` / `orderIds` の実在 + 所属店舗確認を追加、不存在なら `pending_review` (`table_not_found_or_wrong_store` / `order_not_found_or_wrong_store`)。(#4) `pinEnteredClient` 非機密フラグを IDB / 送信 body に追加。初回記録で PIN 入力したが再試行で PIN 未送信のケースを `pending_review` (`pin_entered_but_not_verified`) に落として管理者が区別できるように。(#5) `FORBIDDEN_FIELD=E3040` / `FORBIDDEN_SCOPE=E3041` を `api/lib/error-codes.php` + `scripts/output/error-audit.tsv` に登録。(#6) バッジ件数を `countNeedsAttention` に拡張 — 未同期 + 要確認 (conflict/pending_review) を合算。要確認ありなら `!N` 形式で表示、tooltip に内訳。既存 `countUnsynced` は後方互換ラッパとして維持。§9.17.5 判定ロジック / §9.17.7 / §9.17.7b を全面更新。
- **2026-04-20 (14) Phase 4 緊急レジモード (会計記録台帳)**: レジ画面専用に「緊急レジ」機能を追加。新規 4 ファイル (migration + API + IndexedDB ラッパ + UI) + 修正 2 (cashier-app に 1 public method、cashier.html に script 追加)。**通信障害・API 障害時でも会計事実を失わず IndexedDB に保存 → 復帰後にサーバー台帳へ idempotent 同期**。支払方法は `cash / external_card / external_qr / other_external` の 4 種。カード番号 / 有効期限 / CVV は UI・IDB・API の三重で受け付けない (外部端末で処理済みの控え番号のみ任意記録)。UNIQUE (store_id, local_emergency_payment_id) で二重登録防止。orderIds 既存 payments 重複 → conflict、金額差異 → pending_review。emergency_payments → payments 自動変換は **Phase 4b に分離**。SW 変更なし。詳細は §9.17。
- **2026-04-20 (13) Phase 3 音声経路レビュー指摘対応**: (#1) KDS の `handleAction` / `handleItemAction` / `advancePhase` が offline/stale 時に **`Promise.resolve({ok:false})`** を返していたため、voice-commander の `.then(_beepSuccess)` が成功音 + ✓ を出す誤作動があった。**`Promise.reject(new Error('offline_or_stale'))`** に変更。併せて voice-commander 側も `_isResultSuccess(json)` で `json.ok === true` のみを成功扱い、既存 fetch catch の undefined resolve でも誤成功しないように二重防御。`public/kds/index.html` の click ハンドラ 3 箇所に `.catch(function(){})` を追加 (reject 化による unhandled rejection 回避)。(#2) voice-commander の **スタッフ呼び出し POST** (`call-alerts.php`) と **品切れトグル PATCH** (`sold-out.php`) も `_voiceIsOfflineOrStale()` で冒頭ガード。§9.15.2 に「KdsRenderer の戻り値は reject」節を新設、挙動表を最新仕様に更新。
- **2026-04-20 (12) Phase 3 レビュー指摘対応**: (#1) KDS の `handleAction` / `handleItemAction` / `advancePhase` 各関数冒頭に `_guardOfflineOrStale()` を追加 — DOM disabled だけでなく関数側で楽観更新 + API 呼び出しを両方止める (voice-commander 直接呼び出し経路をカバー)。(#2) cashier-app `_fetchOrders({ failClosed })` オプション追加。`_submitPayment` で `failClosed:true` で呼び、失敗時は toast 出して中断 — 古いデータで会計続行しないように。(#3) handy-app に `_guardOfflineOrStale()` 共通ヘルパ新設、状態変更 7 箇所 (予約着席 / no-show / テーブル開放 / 着席 click / メモ保存 click / 着席取消 / サブセッション発行 click) にガード追加。(#4) cashier.html の `offline-detector.js` を `offline-state-banner.js` より前にロードするよう並び替え — online 復帰時の自動リトライが効くように。(#5) handy-app の `.includes()` 2 箇所を `.indexOf() !== -1` に置換 (ES5 互換)。`OfflineStateBanner.isOfflineOrStale()` 共通判定を新設 (offline / stale どちらでも true)。Cashier / Handy / PosRegister の既存 OfflineDetector ガードもこの共通判定に統一。
- **2026-04-20 (11) Phase 3 通信不安定時の表示耐性**: 新規 `public/shared/js/offline-snapshot.js` (localStorage、TTL 12h、1.5MB 超 skip、quota 握りつぶし) + `public/shared/js/offline-state-banner.js` (黄/緑バナー、再取得ボタン)。修正 11 ファイル — KDS (polling-data-source / kds-renderer / kds-alert / cashier-app / index.html / cashier.html) + ハンディ (handy-app / handy-alert / pos-register / index.html / pos-register.html)。各画面で「成功時 snapshot 保存 + markFresh」「失敗+初回のみ snapshot 復元 + markStale」「失敗+既存表示ありなら表示維持で markStale」を実装。オフライン中の注文送信 / 会計 / 決済 / 返金 / 領収書発行 / ack PATCH は全て **キューせず toast でリターン** (fail-noisy)。SW は変更なし (API 非キャッシュ方針維持)。詳細は §9.15。
- **2026-04-20 (10) Phase 2b 拡張レビュー指摘対応**: (#1) §9.13.2 / §5.13c の秘密鍵キー名を `web_push_vapid_private` → `web_push_vapid_private_pem` に統一 (実装と整合)。(#2) §9.14 冒頭の「Phase 2b でやらない」リストを「初期未実装 → 拡張 (9) で実装済み」と「引き続きやらない」の 2 ブロックに再構成し、読者混乱を解消。(#3) レート制限 tag の粒度を見直し — `call_alert` を `kitchen_call_<alertId>` / `low_rating` を `low_rating_<ratingId>` に変更 (同 user に別イベントが 60 秒抑制される問題を修正)。`call_staff` は既に `call_staff_<tableCode>` / `important_error` は意図的に固定 tag で 5 分重複抑制。§9.14.5 トリガー一覧を新 tag に更新、§9.14.11 に「tag の粒度設計」節を新設。
- **2026-04-20 (9) Phase 2b 拡張**: (B) 送信ログ + レート制限 — `sql/migration-pwa2b-push-log.sql` 新規、`web_push_send_one/batch` に `type`/`tag` 引数追加 + `_web_push_insert_send_log` で push_send_log に INSERT、`_push_filter_subs_by_rate_limit` で直近 60 秒内 2xx 済みの user+type+tag を除外 (tag 空ならスキップしない)。(C) important_error 自動トリガー — `monitor-health.php` Stripe Webhook 失敗を tenant 別集計 → `_pushImportantError()` で tenant 代表店舗の manager/owner に push (5 分重複抑制)。POSLA 運営向け通知は現行では Google Chat / OP に集約。(D) POSLA 管理画面に「PWA/Push」タブ追加 — `api/posla/push-vapid.php` で VAPID 状態 / 購読統計 / 24h 送信統計を読み取り専用表示。秘密鍵ダウンロード・ローテーション・テスト送信は **意図的に実装しない** (誤操作リスク / 独自認証系のため)。秘密鍵は `~/Desktop/posla-secrets/vapid-backup-20260420.txt` (chmod 600) にダウンロード済 + リモート `/tmp` クリーンアップ済 (金庫保管はユーザー手動作業)。§9.14.1 ファイル一覧に 7 エントリ追加、§9.14.8 に保管記録、§9.14.11 / §9.14.12 / §9.14.13 を新設。
- **2026-04-20 (8) Phase 2b レビュー指摘修正**: (#1) KDS / handy / admin の購読に store_id が入らず店舗通知が届かない問題を修正 — `PushSubscriptionManager.setStoreId()` API 追加 + 各画面で `KdsAuth.getStoreId()` / `localStorage('mt_handy_store')` / `getCurrentStoreId()` をポーリングして反映。(#2) `push_send_to_*()` に `tenant_id = ?` を追加 (stores/users から内部解決) しマルチテナント境界を二重化。(#3) `api/customer/call-staff.php` に顧客スタッフ呼び出し Push (`call_staff`) を接続 — これが Phase 2 で期待されていた「呼び出し通知」の本命。(#4) `String.prototype.repeat()` を ES5 互換の手動ループに置換。(#5) 通知 URL を SW 許可 prefix `/public/handy/` `/public/admin/` に統一。(#6) scope=owner を非 owner ロールでも許容していた権限漏れを塞ぐ (subscribe.php で `scope='owner' && role!='owner'` を 403 FORBIDDEN_SCOPE)。(#7) `push_send_to_roles()` は `$roles` に owner が含まれるとき、同 tenant の `store_id IS NULL AND role='owner' AND scope='owner'` 購読を OR 条件で追加取得 — owner-dashboard で全店通知を希望した owner にも低評価通知が届くように。§9.14.5 トリガー一覧に call_staff 追加、§9.14.9 トラブルシュートに 4 行追加、§9.14.10 実装上の注意点に 6 行追加。
- **2026-04-20 (4)**: `public/admin/manifest.webmanifest` の `start_url` を `/public/admin/dashboard.html` → `/public/admin/index.html` に変更。owner/manager どちらがインストールしてもログイン画面 `index.html` のロール判定経由でロール正の画面 (`owner-dashboard.html` or `dashboard.html`) に振り分けられるようにした。既インストール済み PWA は旧 `start_url` を保持するため、再インストールで新 start_url を反映。同時に admin SW VERSION を v3→v4 にバンプして manifest を再フェッチ。
