# POSLA — ローカル開発用 Docker 環境

スモークテスト指摘 **High #4「ローカル作業ツリーで本番相当の API 再現ができない」** を解消するための、開発専用のスタックです。

> 本セットアップは **ローカル開発専用** です。
> 本番 (Sakura) DB / サーバーには一切影響しません。
> 本番への変更は従来どおり SSH + scp + 手動 SQL 実行で行ってください。

---

## 構成

| サービス | イメージ | ポート | 役割 |
|---|---|---|---|
| `db`  | `mysql:5.7` (linux/amd64) | `3306:3306` | 本番と同じ MySQL 5.7。utf8mb4_unicode_ci |
| `php` | `php:8.2-apache` (Dockerfile ビルド) | `8080:80` | mod_php + mod_rewrite。pdo_mysql / mbstring / intl / zip / bcmath / opcache |

DocumentRoot は `/var/www/html`。
- `http://localhost:8080/api/...` → `./api/` に直結
- `http://localhost:8080/public/...` → `./public/` に直結
- `http://localhost:8080/` → `/public/index.html` に 302 リダイレクト

---

## セットアップ手順

### 1. Docker Desktop を起動
macOS の場合: Docker Desktop を起動して通知エリアでクジラのアイコンが安定するのを待つ。

### 2. 起動
```bash
cd /Users/odahiroki/Desktop/matsunoya-mt
docker compose up -d
```

初回は MySQL イメージ pull + PHP イメージのビルド + `schema.sql` + 全マイグレーション + シード投入で **3〜10 分** かかります。

### 3. 起動確認
```bash
# DB のスキーマがロードされ終わるまで待つ（healthy になれば OK）
docker compose ps

# ping
curl http://localhost:8080/api/monitor/ping.php
# → {"ok":true,"time":"...","db":"ok",...}
```

ブラウザで `http://localhost:8080/public/index.html` にアクセスしてログイン画面が出ればセットアップ完了。

---

## ログイン情報（ローカル限定）

すべて **パスワード: `Demo1234`**

| 用途 | URL | username | role |
|---|---|---|---|
| オーナー本部 | `/public/admin/owner-dashboard.html` | `owner`   | owner |
| 店舗運営    | `/public/admin/dashboard.html`       | `manager` | manager |
| スタッフ    | `/public/admin/dashboard.html`       | `staff`   | staff |
| KDS / レジ | `/public/kds/index.html` 等           | `kds01`   | device |
| POSLA 管理 | `/public/posla-admin/`               | email: `admin@posla.local` |

テナント: `matsunoya`（ローカル ID `t-local-matsu-001`）
店舗: 渋谷店 1 店舗（`s-local-shibuya-001`）+ テーブル T01〜T04
メニュー: ロースかつ定食 / ヒレかつ定食 / カツ丼 / ウーロン茶

---

## よく使うコマンド

```bash
# 停止（データは残る）
docker compose down

# 完全リセット（DB ボリュームも消す → 次回起動時に schema + migration + seed が再走）
docker compose down -v && docker compose up -d

# PHP のログを追う
docker compose logs -f php

# DB のログを追う
docker compose logs -f db

# PHP コンテナに入る
docker compose exec php bash

# DB コンテナに入って mysql コマンド
docker compose exec db mysql -uodah_eat-posla -podah_eat-posla odah_eat-posla

# ホストから mysql クライアントで直接接続（要 mysql-client インストール）
mysql -h 127.0.0.1 -P 3306 -uodah_eat-posla -podah_eat-posla odah_eat-posla
```

---

## smoke test 例

```bash
# ヘルスチェック
curl -s http://localhost:8080/api/monitor/ping.php | jq

# ログイン（owner）
curl -s -c /tmp/posla.cookie -X POST http://localhost:8080/api/auth/login.php \
    -H 'Content-Type: application/json' \
    -d '{"username":"owner","password":"Demo1234"}'

# ログイン後の自分情報
curl -s -b /tmp/posla.cookie http://localhost:8080/api/auth/me.php
```

---

## トラブルシューティング

### `db` が unhealthy のまま
- 初回ビルドは時間がかかる。`docker compose logs db` で `[posla-init] schema + migrations done` が出るのを待つ
- 出ないまま止まる場合: `docker compose down -v` で完全リセット

### `php` から DB に繋がらない (`SQLSTATE[HY000] [2002]`)
- compose の起動順 (`depends_on: condition: service_healthy`) で待っているはず
- 手動で確認: `docker compose exec php php -r 'new PDO("mysql:host=db;dbname=odah_eat-posla","odah_eat-posla","odah_eat-posla");echo "OK\n";'`

### Apple Silicon (M1/M2/M3) で遅い
- `mysql:5.7` は arm64 ネイティブイメージがないため `platform: linux/amd64` で QEMU エミュレーション動作
- 体感速度は実用範囲（DB 初期化が数分かかる程度）
- どうしても遅い場合は `mysql:8.0` に切り替える選択肢あり（要 schema 互換性確認）

### `01-schema.sh` の実行権限
- MySQL 公式イメージの `docker-entrypoint.sh` は `.sh` を `source` するため、**実行ビット (chmod +x) は不要**
- もし「permission denied」が出る場合のみ `chmod +x docker/db/init/01-schema.sh` を実行してください

### マイグレーションでエラーが大量に出る
- schema.sql は本番ダンプ（マイグレーション適用済みの最終形）。
  そこに同じ migration-*.sql を再適用すると `Duplicate column` 等のエラーが出るが、
  `01-schema.sh` は WARN として握りつぶす設計なので無視して OK。
- 新規 migration を追加した場合は schema.sql 未反映なら正常に流れる。

---

## 本番への影響について

- このセットアップで作成される DB は Docker ボリューム `posla_db_data` 内の MySQL のみ
- 本番 Sakura DB (`mysql80.odah.sakura.ne.jp`) には一切接続しない
- `api/config/database.php` は環境変数 `POSLA_DB_HOST` 等を見るので、
  Docker 環境では `db` (compose 内 DNS) を、本番では `.htaccess` の SetEnv を見る
- `.gitignore` で `docker/db/data/` 等のローカルデータは除外済み
