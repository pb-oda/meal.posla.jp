# L-15 スマレジ連携 — トラブルシューティング記録

実施日: 2026-04-05

## 概要

OAuth接続テストで6つの問題が連続して発生し、すべて解決した。
根本原因はスマレジPlatform APIの仕様確認不足（スコープ、URL形式、開発者設定）。

---

## 問題1: invalid_client

**症状:** 「スマレジと連携する」押下後、スマレジ側で「ページが見つかりません — invalid_client」

**原因:** redirect_uri のドメインが間違っていた
- 誤: `https://eat-posla.jp/api/smaregi/callback.php`（ハイフン）
- 正: `https://eat.posla.jp/api/smaregi/callback.php`（ドット）

**修正ファイル:**
- `api/smaregi/auth.php` — L36
- `api/smaregi/callback.php` — L68

**教訓:** ClaudeCodeがドメイン名を誤変換した。プロンプトにredirect_uriを明示すべきだった。

---

## 問題2: スマレジ設定不備（Client Secret未設定）

**症状:** スマレジ認可後、owner-dashboardに戻って「スマレジ設定不足」表示

**原因:** `posla_settings` テーブルの `smaregi_client_secret` が NULL のままだった。
マイグレーションSQLで `NULL` として INSERT し、POSLA管理画面にスマレジの入力欄が存在しなかった。

**修正ファイル:**
- `public/posla-admin/dashboard.html` — スマレジ Client ID / Client Secret 入力欄を追加
- `public/posla-admin/js/posla-app.js` — ステータス表示・保存・クリアにスマレジ項目を追加

**対処:** POSLA管理画面から Client Secret を手動入力して保存。

**教訓:** プロンプト2でバックエンドAPIのみ作り、管理画面UIの更新を含めていなかった。

---

## 問題3: 契約IDが取得できませんでした

**症状:** スマレジ認可→トークン交換成功後、userinfoエンドポイントからcontract_idが取得できない

**原因:** OAuth認可リクエストのスコープに `openid` が含まれていなかった。
userinfoエンドポイントで contract.id を返すには `openid` スコープが必須。

**修正ファイル:**
- `api/smaregi/auth.php` — L35: scope に `openid` を追加

**補足修正:** callback.php の contract_id 取得ロジックも強化（3パターン対応 + error_log追加）
- `api/smaregi/callback.php` — contract_id 取得を contract.id / contract_id / sub の3パターンに拡張

---

## 問題4: リフレッシュトークンがありません

**症状:** 接続成功後、店舗マッピングセクションで「リフレッシュトークンがありません」

**原因:** OAuth認可リクエストのスコープに `offline_access` が含まれていなかった。

**修正ファイル:**
- `api/smaregi/auth.php` — L35: scope に `offline_access` を追加

**最終的なスコープ:**
```
openid offline_access pos.products:read pos.transactions:read pos.transactions:write pos.stores:read
```

---

## 問題5: スマレジAPI呼び出し失敗（refresh_token_if_needed ロジック不備）

**症状:** 店舗マッピングで「スマレジAPI呼び出し失敗」

**原因:** `refresh_token_if_needed()` がリフレッシュトークンの有無を**先に**チェックしていた。
トークンがまだ有効でも、リフレッシュトークンがないとエラーを返す実装だった。

**修正ファイル:**
- `api/lib/smaregi-client.php` — refresh_token_if_needed() のロジック順序を修正。
  有効期限チェックを先に行い、トークンがまだ有効ならリフレッシュトークンの有無に関係なくパスする。

---

## 問題6: Insufficient Scope（pos.stores:read）

**症状:** `HTTP=403 — Insufficient Scope — scope: pos.stores:read`

**原因:** スマレジ開発者ダッシュボードのアプリ設定で、APIスコープが有効化されていなかった。
OAuth認可リクエストでスコープを指定しても、開発者ダッシュボードで有効にしていないスコープはサイレントに無視される。

**対処:** スマレジ開発者ダッシュボード → アプリ → パブリックアプリ → POSLA → スコープタブ → スマレジタブ で以下を有効化:
- `pos.stores:read`
- `pos.products:read`
- `pos.transactions:read`
- `pos.transactions:write`

その後、連携解除 → 再接続で新しいスコープ付きトークンを取得。

**教訓:** スマレジではOAuthリクエストのスコープと開発者ダッシュボードの両方で設定が必要。

---

## 問題7: メニューインポート — store_id パラメータ非対応

**症状:** メニューインポート実行時「スマレジ商品取得失敗 (HTTP=400)」

**原因:** `/pos/products?store_id=X` は非対応。スマレジPOS APIの商品一覧エンドポイントは `store_id` クエリパラメータをサポートしていない。

**修正ファイル:**
- `api/smaregi/import-menu.php` — `store_id` パラメータを削除し `/pos/products?limit=1000` に変更

---

## 問題8: 注文同期 — エンドポイントとリクエスト形式の誤り

**症状:** 注文確定後「スマレジAPI送信失敗 (HTTP=400) — transactionHead,transactionDetails はサポートされていません」

**原因:** 2つの問題が重なっていた。

### 8a: エンドポイントが違う
- 誤: `POST /pos/transactions`（取引登録）
- 正: `POST /pos/transactions/temporaries`（仮販売登録）

### 8b: リクエストボディの構造が違う
- 誤: ネスト構造 `{ transactionHead: {...}, transactionDetails: [...] }`
- 正: フラット構造（トップレベルにフィールドを直接配置、明細は `details` 配列）

### 8c: 値の型が違う
- 誤: 数値型（`"salesPrice": 900`）
- 正: 全フィールド文字列型（`"salesPrice": "900"`）

**修正ファイル:**
- `api/smaregi/sync-order.php` — エンドポイント・ボディ構造・型を全面修正

---

## 問題9: デバッグコードの残存（クリーンアップ）

テスト中に追加したデバッグコードを削除。

**修正ファイル:**
- `api/customer/orders.php` — `_smaregi_debug` レスポンスフィールドを削除
- `public/admin/js/owner-app.js` — `console.log('[POSLA-Smaregi]...')` 4箇所を削除

---

## スマレジ仮販売登録API仕様（`POST /pos/transactions/temporaries`）

### エンドポイント
- サンドボックス: `https://api.smaregi.dev/{contract_id}/pos/transactions/temporaries`
- 本番: `https://api.smaregi.jp/{contract_id}/pos/transactions/temporaries`

### 認可
- `AppAccessToken` or `UserAccessToken` — スコープ: `pos.transactions:write`

### リクエストボディ（フラット構造・全値文字列型）

```json
{
  "transactionHeadDivision": "1",
  "status": "0",
  "storeId": "1",
  "terminalId": "1",
  "terminalTranId": "posla-0001",
  "terminalTranDateTime": "2026-04-05T10:45:45+09:00",
  "subtotal": "1000",
  "total": "1000",
  "taxInclude": "90",
  "taxExclude": "0",
  "roundingDivision": "00",
  "roundingPrice": "0",
  "memo": "POSLA Order #xxx",
  "details": [
    {
      "transactionDetailId": "1",
      "transactionDetailDivision": "1",
      "productId": "12345",
      "salesPrice": "1000",
      "quantity": "1"
    }
  ]
}
```

### 必須フィールド（ヘッダ部）

| フィールド | 値 | 説明 |
|-----------|---|------|
| transactionHeadDivision | "1" | 仮販売APIは "1"（通常）のみ |
| status | "0" | 仮販売APIは "0"（通常）のみ |
| storeId | string | スマレジ店舗ID |
| terminalId | string | 端末ID（存在しなくても可） |
| terminalTranId | string | 端末取引ID（10文字以内、ユニーク） |
| terminalTranDateTime | ISO 8601 | 端末取引日時 `YYYY-MM-DDThh:mm:ssTZD` |
| subtotal | string | 明細合計（明細の salesPrice×quantity の合計と一致必須） |
| total | string | 合計 = subtotal - 値引き + 外税 - 免税 |

### 必須フィールド（明細 details[]）

| フィールド | 値 | 説明 |
|-----------|---|------|
| transactionDetailId | string | 明細ID（1〜999、取引内ユニーク） |
| transactionDetailDivision | "1" | 1:通常、2:返品、3:部門売り |
| salesPrice | string | 販売単価 |
| quantity | string | 数量 |

### ユニークキー制約
`storeId` + `terminalId` + `terminalTranDateTime` + `terminalTranId` の組み合わせが重複するとHTTP 400。

### レスポンス（200 成功）
`transactionHeadId` が仮販売取引IDとして返される。

### 金額計算メモ（POSLA実装）
- POSLA商品は税込価格 → `sellDivision` 省略（デフォルト "0" = 内税販売）
- 内税額 = `floor(subtotal / 110 * 10)` （10%税率時）
- `terminalTranId` は POSLA注文IDの先頭10文字を使用

---

## 修正ファイル一覧（全問題の合計）

| # | ファイル | 修正内容 |
|---|---------|---------|
| 1 | api/smaregi/auth.php | redirect_uri ドメイン修正 + scope に openid, offline_access 追加 |
| 2 | api/smaregi/callback.php | redirect_uri ドメイン修正 + contract_id 取得ロジック強化 + ログ追加 |
| 3 | api/smaregi/stores.php | エラーレスポンスに詳細情報追加（デバッグ用） |
| 4 | api/lib/smaregi-client.php | refresh_token_if_needed() ロジック修正 + API URL修正 + ログ追加 |
| 5 | public/posla-admin/dashboard.html | スマレジ Client ID / Secret 入力欄追加 |
| 6 | public/posla-admin/js/posla-app.js | スマレジ設定のステータス表示・保存・クリア追加 |
| 7 | api/smaregi/import-menu.php | store_id クエリパラメータ削除 + エラー詳細追加 |
| 8 | api/smaregi/sync-order.php | エンドポイント・ボディ構造・型を全面修正（仮販売API仕様準拠） |
| 9 | api/customer/orders.php | デバッグ用 _smaregi_debug フィールド削除 |
| 10 | public/admin/js/owner-app.js | console.log デバッグ行削除 |

## なぜPOSLA管理画面にスマレジClient ID / Client Secretがあるのか

スマレジ連携はOAuth 2.0 Authorization Code Grantで動作する。この仕組みでは3者が登場する：

1. **スマレジ**（認可サーバー+リソースサーバー）— 店舗・商品・取引データを持つ
2. **POSLA**（クライアントアプリケーション）— スマレジのデータにアクセスしたい側
3. **テナント（飲食店オーナー）**（リソースオーナー）— 自分のスマレジデータへのアクセスを許可する人

Client ID と Client Secret は「POSLAというアプリケーション自体の身分証明」である。テナントの認証情報ではない。

```
テナント（オーナー）が「スマレジと連携する」ボタンを押す
  │
  ▼
POSLA が Client ID を使ってスマレジ認可画面にリダイレクト
  │ 「POSLAというアプリがあなたのデータにアクセスしたい」と表示される
  ▼
テナントがスマレジにログインして許可
  │
  ▼
スマレジが認可コードを POSLA の callback URL に返す
  │
  ▼
POSLA が Client ID + Client Secret + 認可コードでアクセストークンを取得
  │ ← ここで Client Secret が必要（アプリの正当性を証明）
  ▼
トークンは tenants テーブルにテナント単位で保存
```

つまり：
- **Client ID / Client Secret** = POSLA運営（プラスビリーフ）がスマレジ開発者ダッシュボードで取得する、アプリ共通の認証情報。全テナントで同じ値を使う
- **Access Token / Refresh Token** = テナントごとに異なる、そのテナントのスマレジデータにアクセスするためのトークン

Client ID / Secret はPOSLA運営が管理するものなので、POSLA管理画面（`/public/posla-admin/`）で設定し、`posla_settings` テーブルに保存する。テナントのowner-dashboardには置かない。

## スマレジ開発者ダッシュボード側の設定

| 設定項目 | 値 |
|---------|---|
| Client ID | 142a88853213ca386b90d56c2d385ac3 |
| Redirect URI（開発環境・本番環境とも） | https://eat.posla.jp/api/smaregi/callback.php |
| スコープ | pos.stores:read, pos.products:read, pos.transactions:read, pos.transactions:write |

---

## 本番移行手順

サンドボックス（`.dev`）から本番（`.jp`）への移行は、コード変更 → スマレジ側設定 → POSLA側設定 → テナント再接続の順番で行う。

### ステップ1: コード変更（3ファイル・5箇所）

すべての `.dev` ドメインを `.jp` に書き換える。

| # | ファイル | 行 | 変更前 | 変更後 |
|---|---------|---|--------|--------|
| 1 | `api/smaregi/auth.php` | L40 | `id.smaregi.dev` | `id.smaregi.jp` |
| 2 | `api/smaregi/callback.php` | L64 | `id.smaregi.dev` | `id.smaregi.jp` |
| 3 | `api/smaregi/callback.php` | L114 | `id.smaregi.dev` | `id.smaregi.jp` |
| 4 | `api/lib/smaregi-client.php` | L112 | `id.smaregi.dev` | `id.smaregi.jp` |
| 5 | `api/lib/smaregi-client.php` | L207 | `api.smaregi.dev` | `api.smaregi.jp` |

**変更するのはドメインだけ。** パス・ロジック・リクエスト形式は一切変えない。

Redirect URIは `https://eat.posla.jp/api/smaregi/callback.php` のままで変更不要（POSLA側のURLであり、スマレジのドメインではない）。

### ステップ2: スマレジ開発者ダッシュボード（developers.smaregi.jp）

サンドボックスとは別に**本番用のアプリ登録が必要**。

1. `https://developers.smaregi.jp` にログイン
2. アプリ → 新規作成（またはサンドボックスアプリを本番に昇格）
3. 基本設定:
   - アプリ名: `POSLA`
   - Redirect URI: `https://eat.posla.jp/api/smaregi/callback.php`
4. **スコープタブ → スマレジタブ** で以下を**必ず有効化**:
   - `pos.stores:read`
   - `pos.products:read`
   - `pos.transactions:read`
   - `pos.transactions:write`
5. Client ID と Client Secret をメモ（サンドボックスとは異なる値になる）

**⚠️ 最重要注意:** OAuthリクエストのスコープ文字列だけでなく、**開発者ダッシュボードのアプリ設定画面でもスコープを有効にしないと `403 Insufficient Scope` になる**。サンドボックスでこの問題に当たった（問題6参照）。本番でも同じ設定が必要。

### ステップ3: POSLA管理画面でClient ID/Secret更新

1. `https://eat.posla.jp/public/posla-admin/dashboard.html` にログイン
2. 「API設定」タブを開く
3. スマレジ Client ID → 本番の値に変更
4. スマレジ Client Secret → 本番の値に変更
5. 「保存」

**なぜ必要か:** Client ID/Secretは「POSLAアプリの身分証明」。サンドボックスと本番で異なる値になるため、必ず差し替える。

### ステップ4: テナントのスマレジ再接続

本番の Client ID/Secret に切り替えたため、**既存テナントのOAuthトークンは無効になる**。各テナントで再接続が必要。

1. owner-dashboard.html → スマレジ連携タブ → 「連携解除」
2. 「スマレジと連携する」ボタン → OAuth認可（本番スマレジアカウントでログイン）
3. 接続完了を確認（接続済み + contract_id 表示）

### ステップ5: テナントの店舗マッピング＋メニューインポート

**サンドボックスのマッピングデータは本番では使えない**（店舗IDが異なるため）。

1. 店舗マッピング: POSLA店舗 ↔ スマレジ本番店舗を紐付け
2. メニューインポート: スマレジ本番の商品をPOSLAメニューに取り込み
   - ※既にPOSLAにメニューがある場合、インポートは不要（POSLAが真実の源）
3. 注文テスト: セルフメニューから注文 → スマレジの仮販売履歴に反映されることを確認

### ステップ6: サンドボックスデータのクリーンアップ（任意）

本番テナントの `tenants` テーブルに残っているサンドボックス時代のデータ:
- `smaregi_contract_id` が `sb_` プレフィックス → ステップ4の再接続で本番値に上書きされる
- `smaregi_store_mapping` / `smaregi_product_mapping` のサンドボックス時代の行 → ステップ5で上書きされる

通常はステップ4-5で自動的に上書きされるため、手動クリーンアップは不要。

---

## 注意事項・落とし穴まとめ

- **スコープは2箇所で設定が必要:** OAuthリクエスト文字列（auth.php）+ 開発者ダッシュボードのアプリ設定。片方だけだとサイレントに無視されるか403になる
- **リフレッシュトークン:** `offline_access` スコープで取得。アクセストークン有効期限は1時間、自動リフレッシュ対応済み
- **仮販売API制約:** `transactionHeadDivision` は "1"（通常）のみ、`status` は "0"（通常）のみ
- **ユニークキー:** `storeId + terminalId + terminalTranDateTime + terminalTranId` の組み合わせが重複すると400エラー
- **商品一覧API:** `/pos/products` に `store_id` クエリパラメータは使えない（全店舗の商品が返る）
- **contract_id:** サンドボックスは `sb_` プレフィックス付き。本番は異なる値。userinfoエンドポイント（`openid`スコープ必須）から自動取得される
- **Redirect URI のドメイン:** `eat.posla.jp`（ドット）。`eat-posla.jp`（ハイフン）ではない。サンドボックスでこの間違いに当たった（問題1参照）
- **全値文字列型:** 仮販売APIのリクエストボディは数値フィールドも全て文字列で送る（`"salesPrice": "900"` であって `"salesPrice": 900` ではない）
