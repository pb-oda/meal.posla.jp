#!/bin/bash
# ============================================================
# POSLA — cell DB schema loader
# ============================================================
# This init directory is used by docker-compose.cell.yml.
# It intentionally loads schema + migrations only. Demo seed data must not be
# inserted into production-style cells because MVP uses 1 tenant / 1 cell.
# ============================================================

set -euo pipefail

DB_NAME="${MYSQL_DATABASE:-posla_cell}"
SQL_DIR="/sql_src"

echo "[posla-cell-init] Loading schema + migrations from ${SQL_DIR} into ${DB_NAME}"

if [ ! -d "${SQL_DIR}" ]; then
    echo "[posla-cell-init] ERROR: ${SQL_DIR} not found. Did you mount ./sql:/sql_src?"
    exit 1
fi

if [ -f "${SQL_DIR}/schema.sql" ]; then
    echo "[posla-cell-init] -> schema.sql"
    mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${DB_NAME}" < "${SQL_DIR}/schema.sql"
else
    echo "[posla-cell-init] WARN: schema.sql not found, skipping"
fi

echo "[posla-cell-init] -> applying migrations"
shopt -s nullglob
for f in $(ls "${SQL_DIR}"/migration-*.sql 2>/dev/null | sort); do
    name="$(basename "$f")"
    case "$name" in
        migration-demo-test-users.sql|migration-p1-27-torimaru-demo-tenant.sql)
            echo "[posla-cell-init]    skip data migration ${name}"
            continue
            ;;
    esac
    echo "[posla-cell-init]    apply ${name}"
    if ! mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${DB_NAME}" < "$f" 2> /tmp/migration_err; then
        echo "[posla-cell-init]    WARN: ${name} returned an error (likely already applied via schema.sql):"
        sed 's/^/[posla-cell-init]      /' /tmp/migration_err | head -5
    fi
done

echo "[posla-cell-init] schema + migrations done"
