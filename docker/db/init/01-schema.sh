#!/bin/bash
# ============================================================
# POSLA — schema + マイグレーション一括ロード（ローカル開発専用）
# ============================================================
# /docker-entrypoint-initdb.d/ 内の *.sh は MySQL 公式イメージが
# 初回起動時（データディレクトリが空のとき）のみ自動実行する。
#
# /var/www/html/sql ではなく /sql にマウントしている：
# docker-compose.yml で ./sql:/docker-entrypoint-initdb.d/sql:ro を
# 共有する代わりに、ここでは init コンテナの読み込み専用パスを使う。
# 実際のマウントは docker-compose.yml の volumes で /sql_src として渡す。
# ============================================================

set -euo pipefail

DB_NAME="${MYSQL_DATABASE:-odah_eat-posla}"
SQL_DIR="/sql_src"

echo "[posla-init] Loading schema + migrations from ${SQL_DIR} into ${DB_NAME}"

if [ ! -d "${SQL_DIR}" ]; then
    echo "[posla-init] ERROR: ${SQL_DIR} not found. Did you mount ./sql:/sql_src?"
    exit 1
fi

# 1) schema.sql （本番ダンプ）
if [ -f "${SQL_DIR}/schema.sql" ]; then
    echo "[posla-init] -> schema.sql"
    mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${DB_NAME}" < "${SQL_DIR}/schema.sql"
else
    echo "[posla-init] WARN: schema.sql not found, skipping"
fi

# 2) マイグレーション群（migration-*.sql）
#    schema.sql にすでに反映済みのものも多いが、IF NOT EXISTS / ON DUPLICATE KEY 等で
#    冪等に書かれているものが大半。失敗しても警告だけ出して続行する。
echo "[posla-init] -> applying migrations"
shopt -s nullglob
for f in $(ls "${SQL_DIR}"/migration-*.sql 2>/dev/null | sort); do
    name="$(basename "$f")"
    echo "[posla-init]    apply ${name}"
    if ! mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${DB_NAME}" < "$f" 2> /tmp/migration_err; then
        echo "[posla-init]    WARN: ${name} returned an error (likely already applied via schema.sql):"
        sed 's/^/[posla-init]      /' /tmp/migration_err | head -5
    fi
done

# 3) 任意の seed-*.sql （optional, fail-soft）
for f in $(ls "${SQL_DIR}"/seed-*.sql 2>/dev/null | sort); do
    name="$(basename "$f")"
    # seed.sql 本体は読み込まない（matsunoya デモは 03-seed.sql で再構成）
    case "$name" in
        seed-tenant-momonoya.sql) ;;  # 任意。重複防止のためスキップ
        *) echo "[posla-init]    skip optional ${name}" ;;
    esac
done

echo "[posla-init] schema + migrations done"
