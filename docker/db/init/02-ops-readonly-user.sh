#!/bin/bash
# ============================================================
# POSLA — optional OP read-only DB user bootstrap
# ============================================================

set -euo pipefail

LOG_PREFIX="[posla-init]"
DB_NAME="${MYSQL_DATABASE:-odah_eat-posla}"
OPS_USER="${POSLA_OPS_DB_READONLY_USER:-posla_ops_ro}"
OPS_HOST="${POSLA_OPS_DB_READONLY_HOST:-%}"
OPS_PASSWORD="${POSLA_OPS_DB_READONLY_PASSWORD:-}"

if [ -z "${OPS_PASSWORD}" ]; then
    echo "${LOG_PREFIX} -> ops read-only DB user not configured, skipping"
    exit 0
fi

case "${OPS_PASSWORD}" in
    __REPLACE*|"<"*)
        echo "${LOG_PREFIX} ERROR: POSLA_OPS_DB_READONLY_PASSWORD is still a placeholder"
        exit 1
        ;;
esac

if [ -z "${OPS_USER}" ] || [ -z "${OPS_HOST}" ]; then
    echo "${LOG_PREFIX} ERROR: POSLA_OPS_DB_READONLY_USER and POSLA_OPS_DB_READONLY_HOST are required"
    exit 1
fi

sql_string() {
    printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/''/g"
}

sql_identifier() {
    printf '%s' "$1" | sed 's/`/``/g'
}

USER_SQL="$(sql_string "${OPS_USER}")"
HOST_SQL="$(sql_string "${OPS_HOST}")"
PASSWORD_SQL="$(sql_string "${OPS_PASSWORD}")"
DB_SQL="$(sql_identifier "${DB_NAME}")"

echo "${LOG_PREFIX} -> ensuring ops read-only DB user ${OPS_USER}@${OPS_HOST}"
mysql --default-character-set=utf8mb4 -uroot -p"${MYSQL_ROOT_PASSWORD}" mysql <<SQL
GRANT USAGE ON *.* TO '${USER_SQL}'@'${HOST_SQL}' IDENTIFIED BY '${PASSWORD_SQL}';
GRANT SELECT ON \`${DB_SQL}\`.* TO '${USER_SQL}'@'${HOST_SQL}';
FLUSH PRIVILEGES;
SQL

echo "${LOG_PREFIX} ops read-only DB user ready"
