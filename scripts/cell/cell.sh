#!/usr/bin/env sh
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

usage() {
  cat <<'USAGE'
Usage:
  scripts/cell/cell.sh init <cell-id> <tenant-slug> <domain> [http-port] [db-port]
  scripts/cell/cell.sh registry
  scripts/cell/cell.sh <cell-id> <command> [args]

Commands:
  init     Create cells/<cell-id>/ env files and append cells/registry.tsv
  registry Show local cell registry
  config   Validate docker compose config for the cell
  build    Stamp current Git version, then build/tag the PHP image artifact
  backup   Create DB/env backup for this cell
  backups  List backups for this cell
  up       Start or update the cell using an existing image artifact
  deploy   Backup, validate config, update the cell, then run monitor ping
  migrate  Apply one SQL file to this cell database only
  register-db Upsert this cell metadata into posla_cell_registry
  sync-registry Sync cells/registry.tsv from the cell DB registry row
  rollback-plan [backup|latest] Show rollback plan for a backup
  restore-env <backup|latest> Restore app/db/cell env files from a backup
  restore-db <backup|latest> Restore DB from a backup db.sql
  rollback <backup|latest> Restore env/DB from backup, then deploy this cell
  smoke    Run app / DB / cell metadata smoke checks for this cell
  onboard-tenant <tenant-slug> <tenant-name> <store-name> <owner-username> [owner-display-name] [owner-email]
           Create the first tenant / store / owner / manager / staff / device inside this cell DB
  ensure-ops-users [tenant-slug]
           Add missing manager / staff / device accounts for an existing cell tenant
  down     Stop the cell
  ps       Show cell containers
  logs     Show recent php logs
  ping     Call the cell monitor ping endpoint

Environment:
  POSLA_CELL_HTTP_PORT  Host HTTP port for this cell (default: read from cells/<cell-id>/cell.env or 8081)
  POSLA_CELL_DB_PORT    Host DB port for this cell (default: read from cells/<cell-id>/cell.env or 3306)
  POSLA_OPS_DB_READONLY_USER     DB read-only user for OP monitoring created during cell DB init
  POSLA_OPS_DB_READONLY_PASSWORD DB read-only password for OP monitoring; init auto-generates when omitted
  POSLA_OPS_DB_READONLY_HOST     MySQL host part for the OP read-only user (default: %)
  POSLA_PHP_IMAGE        PHP image artifact to run for this cell; build auto-generates when omitted
  POSLA_DEPLOY_VERSION   Deploy version recorded in app.env; build auto-generates from Git when omitted
  POSLA_CELL_BACKUP_UPLOADS=1 archives uploads during backup
  POSLA_CELL_INIT_OVERWRITE=1 allows init to overwrite existing env files
  POSLA_CELL_RESTORE_CONFIRM=<cell-id> required for restore-env / restore-db
  POSLA_CELL_SMOKE_STRICT=1 treats missing ledger / registry metadata as failure
  POSLA_OWNER_PASSWORD  Required by onboard-tenant unless POSLA_OWNER_PASSWORD_HASH is set
  POSLA_OWNER_PASSWORD_HASH Existing password_hash() output for onboard-tenant
  POSLA_OPS_USER_PASSWORD Required by ensure-ops-users; POSLA_OWNER_PASSWORD is accepted as fallback
  POSLA_STORE_SLUG      Store slug for onboard-tenant (default: default)
  POSLA_SUBSCRIPTION_STATUS  Tenant subscription status (default: trialing)
USAGE
}

if [ "$#" -lt 1 ]; then
  usage
  exit 2
fi

COMMAND="$1"
CELL_ID=""

case "$COMMAND" in
  init|registry) ;;
  *)
    if [ "$#" -lt 2 ]; then
      usage
      exit 2
    fi
    CELL_ID="$1"
    COMMAND="$2"
    ;;
esac

if [ "$COMMAND" = "init" ]; then
  if [ "$#" -lt 4 ]; then
    usage
    exit 2
  fi
  CELL_ID="$2"
fi

validate_cell_id() {
  case "$1" in
    *[!a-zA-Z0-9_-]*|'')
      echo "Invalid cell id: $1" >&2
      exit 2
      ;;
  esac
}

sql_escape() {
  printf '%s' "$1" | sed "s/'/''/g"
}

env_escape() {
  printf '%s' "$1" | sed 's/[\/&]/\\&/g'
}

sql_nullable() {
  if [ -z "$1" ]; then
    printf 'NULL'
  else
    printf "'%s'" "$(sql_escape "$1")"
  fi
}

set_env_value() {
  file="$1"
  key="$2"
  value="$3"
  escaped="$(env_escape "$value")"
  if grep -q "^${key}=" "$file"; then
    sed -i.bak "s/^${key}=.*/${key}=${escaped}/" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
  rm -f "$file.bak"
}

host_from_domain() {
  printf '%s' "$1" | sed 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##; s#/.*$##'
}

db_key_from_slug() {
  printf '%s' "$1" | sed 's/[^A-Za-z0-9_]/_/g'
}

generate_hex_id() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 18
    return 0
  fi
  if command -v od >/dev/null 2>&1; then
    od -An -N18 -tx1 /dev/urandom | tr -d ' \n'
    printf '\n'
    return 0
  fi
  echo "No random id generator found. Install openssl or od." >&2
  exit 1
}

validate_slug() {
  label="$1"
  value="$2"
  case "$value" in
    *[!a-z0-9-]*|'')
      echo "Invalid $label: $value" >&2
      echo "$label must contain only lowercase letters, numbers, and hyphen." >&2
      exit 2
      ;;
  esac
  if [ "${#value}" -gt 50 ]; then
    echo "$label is too long: $value" >&2
    exit 2
  fi
}

validate_username() {
  case "$1" in
    *[!a-zA-Z0-9_-]*|'')
      echo "Invalid username: $1" >&2
      exit 2
      ;;
  esac
  if [ "${#1}" -lt 3 ] || [ "${#1}" -gt 50 ]; then
    echo "Username must be 3-50 chars." >&2
    exit 2
  fi
}

registry_file="$ROOT_DIR/cells/registry.tsv"
registry_template="$ROOT_DIR/cells/registry.example.tsv"

ensure_registry_file() {
  if [ ! -f "$registry_file" ]; then
    cp "$registry_template" "$registry_file"
  fi
}

print_registry() {
  if [ ! -f "$registry_file" ]; then
    echo "Local registry not found: $registry_file"
    echo "Run: scripts/cell/cell.sh init <cell-id> <tenant-slug> <domain> [http-port] [db-port]"
    return 0
  fi
  if command -v column >/dev/null 2>&1; then
    column -t -s "$(printf '\t')" "$registry_file"
  else
    cat "$registry_file"
  fi
}

local_registry_field() {
  cell_id="$1"
  field_no="$2"
  if [ ! -f "$registry_file" ]; then
    return 0
  fi
  awk -F '\t' -v id="$cell_id" -v n="$field_no" 'NR > 1 && $1 == id { print $n; exit }' "$registry_file"
}

upsert_registry() {
  ensure_registry_file
  cell_id="$1"
  tenant_slug="$2"
  tenant_id="$3"
  domain="$4"
  app_url="$5"
  health_url="$6"
  environment="$7"
  status="$8"
  http_port="$9"
  db_port="${10}"
  db_name="${11}"
  php_image="${12}"
  deploy_version="${13}"
  cron_enabled="${14}"
  updated_at="${15}"

  tmp_file="${registry_file}.tmp.$$"
  awk -F '\t' -v OFS='\t' \
    -v cell_id="$cell_id" \
    -v tenant_slug="$tenant_slug" \
    -v tenant_id="$tenant_id" \
    -v domain="$domain" \
    -v app_url="$app_url" \
    -v health_url="$health_url" \
    -v environment="$environment" \
    -v status="$status" \
    -v http_port="$http_port" \
    -v db_port="$db_port" \
    -v db_name="$db_name" \
    -v php_image="$php_image" \
    -v deploy_version="$deploy_version" \
    -v cron_enabled="$cron_enabled" \
    -v updated_at="$updated_at" '
      NR == 1 { print; next }
      $1 == cell_id {
        print cell_id, tenant_slug, tenant_id, domain, app_url, health_url, environment, status, http_port, db_port, db_name, php_image, deploy_version, cron_enabled, updated_at
        found = 1
        next
      }
      { print }
      END {
        if (!found) {
          print cell_id, tenant_slug, tenant_id, domain, app_url, health_url, environment, status, http_port, db_port, db_name, php_image, deploy_version, cron_enabled, updated_at
        }
      }
    ' "$registry_file" > "$tmp_file"
  mv "$tmp_file" "$registry_file"
}

append_registry() {
  ensure_registry_file
  cell_id="$1"
  tenant_slug="$2"
  domain="$3"
  app_url="$4"
  health_url="$5"
  environment="$6"
  status="$7"
  http_port="$8"
  db_port="$9"
  db_name="${10}"
  php_image="${11}"
  deploy_version="${12}"
  cron_enabled="${13}"
  updated_at="${14}"

  if awk -F '\t' -v id="$cell_id" 'NR > 1 && $1 == id { found = 1 } END { exit found ? 0 : 1 }' "$registry_file"; then
    echo "Cell already exists in local registry: $cell_id" >&2
    echo "Edit $registry_file manually if this is an intentional change." >&2
    exit 1
  fi

  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$cell_id" "$tenant_slug" "-" "$domain" "$app_url" "$health_url" \
    "$environment" "$status" "$http_port" "$db_port" "$db_name" \
    "$php_image" "$deploy_version" "$cron_enabled" "$updated_at" >> "$registry_file"
}

init_cell() {
  cell_id="$1"
  tenant_slug="$2"
  domain_input="$3"
  http_port="${4:-18081}"
  db_port="${5:-13306}"

  validate_cell_id "$cell_id"

  cell_dir="$ROOT_DIR/cells/$cell_id"
  if [ -e "$cell_dir/app.env" ] || [ -e "$cell_dir/db.env" ] || [ -e "$cell_dir/cell.env" ]; then
    if [ "${POSLA_CELL_INIT_OVERWRITE:-0}" != "1" ]; then
      echo "Cell files already exist under $cell_dir" >&2
      echo "Set POSLA_CELL_INIT_OVERWRITE=1 only if you intentionally want to overwrite them." >&2
      exit 1
    fi
  fi

  mkdir -p "$cell_dir/uploads"
  cp "$ROOT_DIR/cells/example/app.env.example" "$cell_dir/app.env"
  cp "$ROOT_DIR/cells/example/db.env.example" "$cell_dir/db.env"
  cp "$ROOT_DIR/cells/example/cell.env.example" "$cell_dir/cell.env"

  case "$domain_input" in
    http://*|https://*) app_url="$domain_input" ;;
    *) app_url="https://$domain_input" ;;
  esac
  domain_host="$(host_from_domain "$app_url")"
  tenant_key="$(db_key_from_slug "$tenant_slug")"
  db_name="${POSLA_CELL_DB_NAME:-posla_${tenant_key}}"
  db_user="${POSLA_CELL_DB_USER:-posla_app}"
  db_password="${POSLA_CELL_DB_PASSWORD:-__REPLACE_DB_PASSWORD__}"
  db_root_password="${POSLA_CELL_DB_ROOT_PASSWORD:-__REPLACE_ROOT_PASSWORD__}"
  ops_db_readonly_user="${POSLA_OPS_DB_READONLY_USER:-posla_ops_ro}"
  ops_db_readonly_host="${POSLA_OPS_DB_READONLY_HOST:-%}"
  ops_db_readonly_password="${POSLA_OPS_DB_READONLY_PASSWORD:-$(generate_hex_id)}"
  environment="${POSLA_ENVIRONMENT:-production}"
  deploy_version="${POSLA_DEPLOY_VERSION:-dev}"
  php_image="${POSLA_PHP_IMAGE:-posla_php_cell:dev}"

  set_env_value "$cell_dir/app.env" "POSLA_DB_NAME" "$db_name"
  set_env_value "$cell_dir/app.env" "POSLA_DB_USER" "$db_user"
  set_env_value "$cell_dir/app.env" "POSLA_DB_PASS" "$db_password"
  set_env_value "$cell_dir/app.env" "POSLA_ENV" "$environment"
  set_env_value "$cell_dir/app.env" "POSLA_APP_BASE_URL" "$app_url"
  set_env_value "$cell_dir/app.env" "POSLA_FROM_EMAIL" "noreply@$domain_host"
  set_env_value "$cell_dir/app.env" "POSLA_SUPPORT_EMAIL" "info@$domain_host"
  set_env_value "$cell_dir/app.env" "POSLA_ALLOWED_ORIGINS" "$app_url"
  set_env_value "$cell_dir/app.env" "POSLA_ALLOWED_HOSTS" "$domain_host"
  set_env_value "$cell_dir/app.env" "POSLA_CELL_ID" "$cell_id"
  set_env_value "$cell_dir/app.env" "POSLA_ENVIRONMENT" "$environment"
  set_env_value "$cell_dir/app.env" "POSLA_DEPLOY_VERSION" "$deploy_version"
  set_env_value "$cell_dir/app.env" "POSLA_CRON_ENABLED" "1"

  set_env_value "$cell_dir/db.env" "MYSQL_ROOT_PASSWORD" "$db_root_password"
  set_env_value "$cell_dir/db.env" "MYSQL_DATABASE" "$db_name"
  set_env_value "$cell_dir/db.env" "MYSQL_USER" "$db_user"
  set_env_value "$cell_dir/db.env" "MYSQL_PASSWORD" "$db_password"
  set_env_value "$cell_dir/db.env" "POSLA_OPS_DB_READONLY_USER" "$ops_db_readonly_user"
  set_env_value "$cell_dir/db.env" "POSLA_OPS_DB_READONLY_PASSWORD" "$ops_db_readonly_password"
  set_env_value "$cell_dir/db.env" "POSLA_OPS_DB_READONLY_HOST" "$ops_db_readonly_host"

  set_env_value "$cell_dir/cell.env" "POSLA_CELL_HTTP_PORT" "$http_port"
  set_env_value "$cell_dir/cell.env" "POSLA_CELL_DB_PORT" "$db_port"
  set_env_value "$cell_dir/cell.env" "POSLA_PHP_IMAGE" "$php_image"

  updated_at="$(date '+%Y-%m-%dT%H:%M:%S%z')"
  append_registry "$cell_id" "$tenant_slug" "$domain_host" "$app_url" \
    "$app_url/api/monitor/ping.php" "$environment" "planned" \
    "$http_port" "$db_port" "$db_name" "$php_image" "$deploy_version" "1" "$updated_at"

  echo "Created cell: $cell_id"
  echo "Edit secrets in: $cell_dir/app.env and $cell_dir/db.env"
  echo "Validate with: scripts/cell/cell.sh $cell_id config"
}

if [ "$COMMAND" = "registry" ]; then
  print_registry
  exit 0
fi

if [ "$COMMAND" = "init" ]; then
  init_cell "$CELL_ID" "$3" "$4" "${5:-18081}" "${6:-13306}"
  exit 0
fi

validate_cell_id "$CELL_ID"

CELL_DIR="$ROOT_DIR/cells/$CELL_ID"
CELL_ENV="$CELL_DIR/cell.env"
REQUESTED_POSLA_DEPLOY_VERSION="${POSLA_DEPLOY_VERSION:-}"
REQUESTED_POSLA_PHP_IMAGE="${POSLA_PHP_IMAGE:-}"

if [ ! -f "$CELL_DIR/app.env" ] || [ ! -f "$CELL_DIR/db.env" ]; then
  echo "Cell env files are missing: $CELL_DIR/app.env and/or $CELL_DIR/db.env" >&2
  echo "Create them with scripts/cell/cell.sh init first." >&2
  exit 1
fi

if [ -f "$CELL_ENV" ]; then
  # shellcheck disable=SC1090
  . "$CELL_ENV"
fi

export POSLA_CELL_ID="$CELL_ID"
export POSLA_CELL_HTTP_PORT="${POSLA_CELL_HTTP_PORT:-8081}"
export POSLA_CELL_DB_PORT="${POSLA_CELL_DB_PORT:-3306}"
export POSLA_PHP_IMAGE="${POSLA_PHP_IMAGE:-posla_php_cell:dev}"
export POSLA_DEPLOY_VERSION="${POSLA_DEPLOY_VERSION:-dev}"

COMPOSE_PROJECT_NAME="posla_$CELL_ID"
export COMPOSE_PROJECT_NAME

case "$CELL_ID" in
  *[!a-zA-Z0-9_-]*|'')
    echo "Invalid cell id: $CELL_ID" >&2
    exit 2
    ;;
esac

compose() {
  docker compose -f "$ROOT_DIR/docker-compose.cell.yml" "$@"
}

wait_ping() {
  i=0
  while [ "$i" -lt 30 ]; do
    if curl -sf "http://127.0.0.1:${POSLA_CELL_HTTP_PORT}/api/monitor/ping.php"; then
      printf '\n'
      return 0
    fi
    i=$((i + 1))
    sleep 1
  done
  curl -sf "http://127.0.0.1:${POSLA_CELL_HTTP_PORT}/api/monitor/ping.php"
  printf '\n'
}

git_deploy_version() {
  sha="$(git -C "$ROOT_DIR" rev-parse --short=12 HEAD 2>/dev/null || true)"
  if [ -n "$sha" ]; then
    if [ -n "$(git -C "$ROOT_DIR" status --porcelain --untracked-files=no -- api public scripts sql docker docker-compose.cell.yml composer.json composer.lock 2>/dev/null || true)" ]; then
      printf '%s-dirty' "$sha"
    else
      printf '%s' "$sha"
    fi
    return 0
  fi
  date '+%Y%m%d%H%M%S'
}

image_tag_from_version() {
  printf '%s' "$1" | sed 's/[^A-Za-z0-9_.-]/_/g'
}

stamp_deploy_metadata() {
  deploy_version="$REQUESTED_POSLA_DEPLOY_VERSION"
  php_image="$REQUESTED_POSLA_PHP_IMAGE"
  if [ -z "$deploy_version" ]; then
    deploy_version="$(git_deploy_version)"
  fi
  if [ -z "$php_image" ]; then
    php_image="posla_php_cell:$(image_tag_from_version "$deploy_version")"
  fi

  set_env_value "$CELL_DIR/app.env" "POSLA_DEPLOY_VERSION" "$deploy_version"
  set_env_value "$CELL_DIR/cell.env" "POSLA_PHP_IMAGE" "$php_image"
  POSLA_DEPLOY_VERSION="$deploy_version"
  POSLA_PHP_IMAGE="$php_image"
  export POSLA_DEPLOY_VERSION POSLA_PHP_IMAGE
  echo "Stamped deploy version: $POSLA_DEPLOY_VERSION"
  echo "Stamped PHP image: $POSLA_PHP_IMAGE"
}

checksum_file() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{ print $1 }'
  else
    shasum -a 256 "$1" | awk '{ print $1 }'
  fi
}

schema_migrations_exists() {
  count="$(compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '\''schema_migrations'\''" "$MYSQL_DATABASE"' 2>/dev/null || printf '0')"
  [ "$count" = "1" ]
}

table_exists() {
  table_name="$1"
  escaped_table="$(sql_escape "$table_name")"
  count="$(compose exec -T db sh -c "mysql --default-character-set=utf8mb4 -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" -Nse \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${escaped_table}'\" \"\$MYSQL_DATABASE\"" 2>/dev/null || printf '0')"
  [ "$count" = "1" ]
}

column_exists() {
  table_name="$1"
  column_name="$2"
  escaped_table="$(sql_escape "$table_name")"
  escaped_column="$(sql_escape "$column_name")"
  count="$(compose exec -T db sh -c "mysql --default-character-set=utf8mb4 -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" -Nse \"SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '${escaped_table}' AND column_name = '${escaped_column}'\" \"\$MYSQL_DATABASE\"" 2>/dev/null || printf '0')"
  [ "$count" = "1" ]
}

mysql_scalar() {
  sql="$1"
  compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -Nse "$1" "$MYSQL_DATABASE"' sh "$sql"
}

db_mysql_ready() {
  compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -Nse "SELECT 1" "$MYSQL_DATABASE"' >/dev/null 2>&1
}

schema_migration_applied() {
  key="$1"
  escaped_key="$(sql_escape "$key")"
  count="$(compose exec -T db sh -c "mysql --default-character-set=utf8mb4 -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" -Nse \"SELECT COUNT(*) FROM schema_migrations WHERE migration_key = '${escaped_key}' AND status = 'applied'\" \"\$MYSQL_DATABASE\"" 2>/dev/null || printf '0')"
  [ "$count" != "0" ]
}

record_schema_migration() {
  key="$1"
  checksum="$2"
  escaped_key="$(sql_escape "$key")"
  escaped_checksum="$(sql_escape "$checksum")"
  escaped_cell="$(sql_escape "$CELL_ID")"
  escaped_version="$(sql_escape "$(current_deploy_version)")"
  escaped_user="$(sql_escape "${USER:-cell.sh}")"
  printf "INSERT INTO schema_migrations (migration_key, checksum_sha256, status, cell_id, deploy_version, applied_by, notes) VALUES ('%s', '%s', 'applied', '%s', '%s', '%s', 'scripts/cell/cell.sh migrate') ON DUPLICATE KEY UPDATE checksum_sha256 = VALUES(checksum_sha256), status = VALUES(status), cell_id = VALUES(cell_id), deploy_version = VALUES(deploy_version), applied_by = VALUES(applied_by), notes = VALUES(notes), applied_at = CURRENT_TIMESTAMP;\n" \
    "$escaped_key" "$escaped_checksum" "$escaped_cell" "$escaped_version" "$escaped_user" \
    | compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
}

env_file_value() {
  file="$1"
  key="$2"
  value="$(grep "^${key}=" "$file" | tail -n 1 | sed "s/^${key}=//" || true)"
  printf '%s' "$value"
}

current_deploy_version() {
  value="$(env_file_value "$CELL_DIR/app.env" POSLA_DEPLOY_VERSION)"
  if [ -n "$value" ]; then
    printf '%s' "$value"
  else
    printf '%s' "$POSLA_DEPLOY_VERSION"
  fi
}

reload_runtime_env() {
  if [ -f "$CELL_ENV" ]; then
    # shellcheck disable=SC1090
    . "$CELL_ENV"
  fi
  export POSLA_CELL_ID="$CELL_ID"
  export POSLA_CELL_HTTP_PORT="${POSLA_CELL_HTTP_PORT:-8081}"
  export POSLA_CELL_DB_PORT="${POSLA_CELL_DB_PORT:-3306}"
  export POSLA_PHP_IMAGE="${POSLA_PHP_IMAGE:-posla_php_cell:dev}"
  POSLA_DEPLOY_VERSION="$(current_deploy_version)"
  export POSLA_DEPLOY_VERSION
}

sync_local_registry_from_db() {
  fallback_status="${1:-active}"
  app_env="$CELL_DIR/app.env"
  app_base_url="$(env_file_value "$app_env" POSLA_APP_BASE_URL)"
  environment="$(env_file_value "$app_env" POSLA_ENVIRONMENT)"
  deploy_version="$(env_file_value "$app_env" POSLA_DEPLOY_VERSION)"
  db_name="$(env_file_value "$app_env" POSLA_DB_NAME)"
  cron_enabled="$(env_file_value "$app_env" POSLA_CRON_ENABLED)"
  health_url="${app_base_url%/}/api/monitor/ping.php"
  domain_host="$(host_from_domain "$app_base_url")"
  updated_at="$(date '+%Y-%m-%dT%H:%M:%S%z')"

  tenant_slug="$(local_registry_field "$CELL_ID" 2)"
  tenant_id="$(local_registry_field "$CELL_ID" 3)"
  registry_status="$fallback_status"

  if db_mysql_ready && table_exists "posla_cell_registry"; then
    escaped_cell="$(sql_escape "$CELL_ID")"
    registry_row="$(compose exec -T db sh -c "mysql --default-character-set=utf8mb4 -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" --batch --skip-column-names \"\$MYSQL_DATABASE\" -e \"SELECT CONCAT_WS('|', COALESCE(NULLIF(tenant_slug, ''), '-'), COALESCE(NULLIF(tenant_id, ''), '-'), COALESCE(NULLIF(environment, ''), '-'), COALESCE(NULLIF(status, ''), '-'), COALESCE(NULLIF(deploy_version, ''), '-'), COALESCE(cron_enabled, 1)) FROM posla_cell_registry WHERE cell_id = '${escaped_cell}' LIMIT 1\"" 2>/dev/null || true)"
    if [ -n "$registry_row" ]; then
      old_ifs="$IFS"
      IFS="|"
      # shellcheck disable=SC2086
      set -- $registry_row
      IFS="$old_ifs"
      tenant_slug="${1:-$tenant_slug}"
      tenant_id="${2:-$tenant_id}"
      environment="${3:-$environment}"
      registry_status="${4:-$registry_status}"
      deploy_version="${5:-$deploy_version}"
      cron_enabled="${6:-$cron_enabled}"
    fi
  fi

  if [ "$environment" = "-" ]; then environment=""; fi
  if [ "$registry_status" = "-" ]; then registry_status=""; fi
  if [ "$deploy_version" = "-" ]; then deploy_version=""; fi
  if [ -z "$tenant_slug" ]; then tenant_slug="-"; fi
  if [ -z "$tenant_id" ]; then tenant_id="-"; fi
  if [ -z "$environment" ]; then environment="production"; fi
  if [ -z "$registry_status" ]; then registry_status="$fallback_status"; fi
  if [ -z "$deploy_version" ]; then deploy_version="$(current_deploy_version)"; fi
  if [ -z "$cron_enabled" ]; then cron_enabled="1"; fi

  upsert_registry "$CELL_ID" "$tenant_slug" "$tenant_id" "$domain_host" "$app_base_url" "$health_url" \
    "$environment" "$registry_status" "$POSLA_CELL_HTTP_PORT" "$POSLA_CELL_DB_PORT" "$db_name" \
    "$POSLA_PHP_IMAGE" "$deploy_version" "$cron_enabled" "$updated_at"
}

register_cell_db() {
  registry_status="${1:-active}"
  app_env="$CELL_DIR/app.env"
  db_host="$(env_file_value "$app_env" POSLA_DB_HOST)"
  db_name="$(env_file_value "$app_env" POSLA_DB_NAME)"
  db_user="$(env_file_value "$app_env" POSLA_DB_USER)"
  app_base_url="$(env_file_value "$app_env" POSLA_APP_BASE_URL)"
  environment="$(env_file_value "$app_env" POSLA_ENVIRONMENT)"
  deploy_version="$(env_file_value "$app_env" POSLA_DEPLOY_VERSION)"
  cron_enabled="$(env_file_value "$app_env" POSLA_CRON_ENABLED)"
  health_url="${app_base_url%/}/api/monitor/ping.php"
  uploads_path="cells/$CELL_ID/uploads"

  escaped_cell="$(sql_escape "$CELL_ID")"
  escaped_status="$(sql_escape "$registry_status")"
  escaped_environment="$(sql_escape "${environment:-production}")"
  escaped_app_base_url="$(sql_escape "$app_base_url")"
  escaped_health_url="$(sql_escape "$health_url")"
  escaped_db_host="$(sql_escape "$db_host")"
  escaped_db_name="$(sql_escape "$db_name")"
  escaped_db_user="$(sql_escape "$db_user")"
  escaped_uploads_path="$(sql_escape "$uploads_path")"
  escaped_php_image="$(sql_escape "$POSLA_PHP_IMAGE")"
  escaped_deploy_version="$(sql_escape "${deploy_version:-$(current_deploy_version)}")"
  if [ "${cron_enabled:-1}" = "0" ]; then
    cron_value="0"
  else
    cron_value="1"
  fi

  printf "INSERT INTO posla_cell_registry (cell_id, environment, status, app_base_url, health_url, db_host, db_name, db_user, uploads_path, php_image, deploy_version, cron_enabled) VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %s) ON DUPLICATE KEY UPDATE environment = VALUES(environment), status = VALUES(status), app_base_url = VALUES(app_base_url), health_url = VALUES(health_url), db_host = VALUES(db_host), db_name = VALUES(db_name), db_user = VALUES(db_user), uploads_path = VALUES(uploads_path), php_image = VALUES(php_image), deploy_version = VALUES(deploy_version), cron_enabled = VALUES(cron_enabled), updated_at = CURRENT_TIMESTAMP;\n" \
    "$escaped_cell" "$escaped_environment" "$escaped_status" "$escaped_app_base_url" "$escaped_health_url" \
    "$escaped_db_host" "$escaped_db_name" "$escaped_db_user" "$escaped_uploads_path" \
    "$escaped_php_image" "$escaped_deploy_version" "$cron_value" \
    | compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
  sync_local_registry_from_db "$registry_status" || true
}

record_deployment() {
  deploy_status="$1"
  notes="$2"
  if ! db_mysql_ready; then
    echo "DB is not ready; deployment history skipped: $deploy_status" >&2
    return 0
  fi
  if ! table_exists "posla_cell_deployments"; then
    echo "posla_cell_deployments table not found; deployment history skipped: $deploy_status" >&2
    return 0
  fi
  escaped_cell="$(sql_escape "$CELL_ID")"
  escaped_version="$(sql_escape "$(current_deploy_version)")"
  escaped_image="$(sql_escape "$POSLA_PHP_IMAGE")"
  escaped_status="$(sql_escape "$deploy_status")"
  escaped_user="$(sql_escape "${USER:-cell.sh}")"
  escaped_notes="$(sql_escape "$notes")"
  printf "INSERT INTO posla_cell_deployments (cell_id, deploy_version, php_image, status, deployed_by, notes) VALUES ('%s', '%s', '%s', '%s', '%s', '%s');\n" \
    "$escaped_cell" "$escaped_version" "$escaped_image" "$escaped_status" "$escaped_user" "$escaped_notes" \
    | compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
}

password_hash_from_php() {
  password="$1"
  printf '%s' "$password" | compose exec -T php php -r '$p = stream_get_contents(STDIN); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'
}

onboard_tenant() {
  if [ "$#" -lt 4 ]; then
    echo "onboard-tenant requires: <tenant-slug> <tenant-name> <store-name> <owner-username> [owner-display-name] [owner-email]" >&2
    usage
    exit 2
  fi

  tenant_slug="$1"
  tenant_name="$2"
  store_name="$3"
  owner_username="$4"
  owner_display_name="${5:-$owner_username}"
  owner_email="${6:-}"
  store_slug="${POSLA_STORE_SLUG:-default}"
  subscription_status="${POSLA_SUBSCRIPTION_STATUS:-trialing}"
  hq_menu_broadcast="${POSLA_HQ_MENU_BROADCAST:-0}"
  owner_password="${POSLA_OWNER_PASSWORD:-}"
  owner_password_hash="${POSLA_OWNER_PASSWORD_HASH:-}"
  manager_username="${POSLA_MANAGER_USERNAME:-${tenant_slug}-manager}"
  staff_username="${POSLA_STAFF_USERNAME:-${tenant_slug}-staff}"
  device_username="${POSLA_DEVICE_USERNAME:-${tenant_slug}-kds01}"
  manager_display_name="${POSLA_MANAGER_DISPLAY_NAME:-${tenant_name} Manager}"
  staff_display_name="${POSLA_STAFF_DISPLAY_NAME:-${tenant_name} Staff}"
  device_display_name="${POSLA_DEVICE_DISPLAY_NAME:-${tenant_name} KDS}"

  validate_slug "tenant slug" "$tenant_slug"
  validate_slug "store slug" "$store_slug"
  validate_username "$owner_username"
  validate_username "$manager_username"
  validate_username "$staff_username"
  validate_username "$device_username"

  case "$subscription_status" in
    none|active|past_due|canceled|trialing) ;;
    *)
      echo "Invalid POSLA_SUBSCRIPTION_STATUS: $subscription_status" >&2
      exit 2
      ;;
  esac

  if [ "$hq_menu_broadcast" = "1" ]; then
    hq_value="1"
  else
    hq_value="0"
  fi

  if [ -z "$owner_password" ] && [ -z "$owner_password_hash" ]; then
    echo "POSLA_OWNER_PASSWORD or POSLA_OWNER_PASSWORD_HASH is required for onboard-tenant." >&2
    exit 2
  fi
  if [ -z "$tenant_name" ] || [ -z "$store_name" ]; then
    echo "Tenant name and store name are required." >&2
    exit 2
  fi
  if ! db_mysql_ready; then
    echo "DB is not ready for $CELL_ID. Deploy or start the cell before onboard-tenant." >&2
    exit 1
  fi
  for required_table in tenants stores users user_stores; do
    if ! table_exists "$required_table"; then
      echo "Required table missing: $required_table" >&2
      exit 1
    fi
  done

  escaped_tenant_slug="$(sql_escape "$tenant_slug")"
  tenant_count="$(mysql_scalar "SELECT COUNT(*) FROM tenants WHERE slug = '${escaped_tenant_slug}'" 2>/dev/null || printf '0')"
  if [ "$tenant_count" != "0" ]; then
    echo "Tenant slug already exists in $CELL_ID: $tenant_slug" >&2
    exit 1
  fi
  for bootstrap_username in "$owner_username" "$manager_username" "$staff_username" "$device_username"; do
    escaped_bootstrap_username="$(sql_escape "$bootstrap_username")"
    user_count="$(mysql_scalar "SELECT COUNT(*) FROM users WHERE username = '${escaped_bootstrap_username}'" 2>/dev/null || printf '0')"
    if [ "$user_count" != "0" ]; then
      echo "Bootstrap username already exists in $CELL_ID: $bootstrap_username" >&2
      exit 1
    fi
  done
  if [ -n "$owner_email" ]; then
    escaped_owner_email="$(sql_escape "$owner_email")"
    email_count="$(mysql_scalar "SELECT COUNT(*) FROM users WHERE email = '${escaped_owner_email}'" 2>/dev/null || printf '0')"
    if [ "$email_count" != "0" ]; then
      echo "Owner email already exists in $CELL_ID: $owner_email" >&2
      exit 1
    fi
  fi

  tenant_id="${POSLA_TENANT_ID:-$(generate_hex_id)}"
  store_id="${POSLA_STORE_ID:-$(generate_hex_id)}"
  owner_user_id="${POSLA_OWNER_USER_ID:-$(generate_hex_id)}"
  manager_user_id="${POSLA_MANAGER_USER_ID:-$(generate_hex_id)}"
  staff_user_id="${POSLA_STAFF_USER_ID:-$(generate_hex_id)}"
  device_user_id="${POSLA_DEVICE_USER_ID:-$(generate_hex_id)}"
  if [ -n "$owner_password_hash" ]; then
    password_hash="$owner_password_hash"
  else
    password_hash="$(password_hash_from_php "$owner_password")"
  fi

  escaped_tenant_id="$(sql_escape "$tenant_id")"
  escaped_store_id="$(sql_escape "$store_id")"
  escaped_owner_user_id="$(sql_escape "$owner_user_id")"
  escaped_manager_user_id="$(sql_escape "$manager_user_id")"
  escaped_staff_user_id="$(sql_escape "$staff_user_id")"
  escaped_device_user_id="$(sql_escape "$device_user_id")"
  escaped_tenant_name="$(sql_escape "$tenant_name")"
  escaped_store_slug="$(sql_escape "$store_slug")"
  escaped_store_name="$(sql_escape "$store_name")"
  escaped_owner_display_name="$(sql_escape "$owner_display_name")"
  escaped_manager_display_name="$(sql_escape "$manager_display_name")"
  escaped_staff_display_name="$(sql_escape "$staff_display_name")"
  escaped_device_display_name="$(sql_escape "$device_display_name")"
  escaped_owner_username="$(sql_escape "$owner_username")"
  escaped_manager_username="$(sql_escape "$manager_username")"
  escaped_staff_username="$(sql_escape "$staff_username")"
  escaped_device_username="$(sql_escape "$device_username")"
  escaped_password_hash="$(sql_escape "$password_hash")"
  owner_email_sql="$(sql_nullable "$owner_email")"
  escaped_subscription_status="$(sql_escape "$subscription_status")"
  has_visible_tools=0
  if column_exists "user_stores" "visible_tools"; then
    has_visible_tools=1
  fi

  {
    printf 'START TRANSACTION;\n'
    printf "INSERT INTO tenants (id, slug, name, subscription_status, hq_menu_broadcast, is_active, created_at, updated_at) VALUES ('%s', '%s', '%s', '%s', %s, 1, NOW(), NOW());\n" \
      "$escaped_tenant_id" "$escaped_tenant_slug" "$escaped_tenant_name" "$escaped_subscription_status" "$hq_value"
    printf "INSERT INTO stores (id, tenant_id, slug, name, timezone, is_active, created_at, updated_at) VALUES ('%s', '%s', '%s', '%s', 'Asia/Tokyo', 1, NOW(), NOW());\n" \
      "$escaped_store_id" "$escaped_tenant_id" "$escaped_store_slug" "$escaped_store_name"
    if table_exists "store_settings"; then
      printf "INSERT INTO store_settings (store_id, receipt_store_name, created_at, updated_at) VALUES ('%s', '%s', NOW(), NOW());\n" \
        "$escaped_store_id" "$escaped_store_name"
    fi
    printf "INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active, created_at, updated_at) VALUES ('%s', '%s', %s, '%s', '%s', '%s', 'owner', 1, NOW(), NOW());\n" \
      "$escaped_owner_user_id" "$escaped_tenant_id" "$owner_email_sql" "$escaped_owner_username" "$escaped_password_hash" "$escaped_owner_display_name"
    printf "INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active, created_at, updated_at) VALUES ('%s', '%s', NULL, '%s', '%s', '%s', 'manager', 1, NOW(), NOW());\n" \
      "$escaped_manager_user_id" "$escaped_tenant_id" "$escaped_manager_username" "$escaped_password_hash" "$escaped_manager_display_name"
    printf "INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active, created_at, updated_at) VALUES ('%s', '%s', NULL, '%s', '%s', '%s', 'staff', 1, NOW(), NOW());\n" \
      "$escaped_staff_user_id" "$escaped_tenant_id" "$escaped_staff_username" "$escaped_password_hash" "$escaped_staff_display_name"
    printf "INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active, created_at, updated_at) VALUES ('%s', '%s', NULL, '%s', '%s', '%s', 'device', 1, NOW(), NOW());\n" \
      "$escaped_device_user_id" "$escaped_tenant_id" "$escaped_device_username" "$escaped_password_hash" "$escaped_device_display_name"
    if [ "$has_visible_tools" = "1" ]; then
      printf "INSERT INTO user_stores (user_id, store_id, visible_tools) VALUES ('%s', '%s', NULL), ('%s', '%s', NULL), ('%s', '%s', NULL), ('%s', '%s', 'kds,register');\n" \
        "$escaped_owner_user_id" "$escaped_store_id" "$escaped_manager_user_id" "$escaped_store_id" "$escaped_staff_user_id" "$escaped_store_id" "$escaped_device_user_id" "$escaped_store_id"
    else
      printf "INSERT INTO user_stores (user_id, store_id) VALUES ('%s', '%s'), ('%s', '%s'), ('%s', '%s'), ('%s', '%s');\n" \
        "$escaped_owner_user_id" "$escaped_store_id" "$escaped_manager_user_id" "$escaped_store_id" "$escaped_staff_user_id" "$escaped_store_id" "$escaped_device_user_id" "$escaped_store_id"
    fi
    printf 'COMMIT;\n'
  } | compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'

  if table_exists "posla_cell_registry"; then
    register_cell_db "active" || true
    printf "UPDATE posla_cell_registry SET tenant_id = '%s', tenant_slug = '%s', tenant_name = '%s', updated_at = CURRENT_TIMESTAMP WHERE cell_id = '%s';\n" \
      "$escaped_tenant_id" "$escaped_tenant_slug" "$escaped_tenant_name" "$(sql_escape "$CELL_ID")" \
      | compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
  fi
  if table_exists "posla_tenant_onboarding_requests"; then
    printf "UPDATE posla_tenant_onboarding_requests SET status = 'active', cell_id = '%s', provisioned_at = COALESCE(provisioned_at, NOW()), activated_at = COALESCE(activated_at, NOW()), notes = 'Tenant onboarded by scripts/cell/cell.sh', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = '%s' OR tenant_slug = '%s';\n" \
      "$(sql_escape "$CELL_ID")" "$escaped_tenant_id" "$escaped_tenant_slug" \
      | compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
  fi
  sync_local_registry_from_db "active" || true

  echo "Onboarded tenant in $CELL_ID"
  echo "tenant_id=$tenant_id"
  echo "tenant_slug=$tenant_slug"
  echo "store_id=$store_id"
  echo "store_slug=$store_slug"
  echo "owner_user_id=$owner_user_id"
  echo "owner_username=$owner_username"
  echo "manager_user_id=$manager_user_id"
  echo "manager_username=$manager_username"
  echo "staff_user_id=$staff_user_id"
  echo "staff_username=$staff_username"
  echo "device_user_id=$device_user_id"
  echo "device_username=$device_username"
}

ensure_ops_users() {
  tenant_slug_filter="${1:-}"
  ops_password="${POSLA_OPS_USER_PASSWORD:-${POSLA_OWNER_PASSWORD:-}}"

  if [ -n "$tenant_slug_filter" ]; then
    validate_slug "tenant slug" "$tenant_slug_filter"
  fi
  if [ -z "$ops_password" ]; then
    echo "POSLA_OPS_USER_PASSWORD is required for ensure-ops-users." >&2
    echo "POSLA_OWNER_PASSWORD is accepted as a fallback for local repair runs." >&2
    exit 2
  fi
  if ! db_mysql_ready; then
    echo "DB is not ready for $CELL_ID. Deploy or start the cell before ensure-ops-users." >&2
    exit 1
  fi
  for required_table in tenants stores users user_stores; do
    if ! table_exists "$required_table"; then
      echo "Required table missing: $required_table" >&2
      exit 1
    fi
  done

  if [ -n "$tenant_slug_filter" ]; then
    escaped_filter="$(sql_escape "$tenant_slug_filter")"
    tenant_count="$(mysql_scalar "SELECT COUNT(*) FROM tenants WHERE slug = '${escaped_filter}'" 2>/dev/null || printf '0')"
    tenant_where="slug = '${escaped_filter}'"
  else
    tenant_count="$(mysql_scalar "SELECT COUNT(*) FROM tenants" 2>/dev/null || printf '0')"
    tenant_where="1 = 1"
  fi

  if [ "$tenant_count" != "1" ]; then
    echo "ensure-ops-users requires exactly one target tenant. Found: $tenant_count" >&2
    echo "Pass tenant slug explicitly: scripts/cell/cell.sh $CELL_ID ensure-ops-users <tenant-slug>" >&2
    exit 1
  fi

  tenant_row="$(compose exec -T db sh -c "mysql --default-character-set=utf8mb4 -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" --batch --skip-column-names \"\$MYSQL_DATABASE\" -e \"SELECT CONCAT_WS('|', id, slug, REPLACE(name, '|', ' ')) FROM tenants WHERE ${tenant_where} LIMIT 1\"" 2>/dev/null || true)"
  if [ -z "$tenant_row" ]; then
    echo "Target tenant was not found." >&2
    exit 1
  fi
  old_ifs="$IFS"
  IFS="|"
  # shellcheck disable=SC2086
  set -- $tenant_row
  IFS="$old_ifs"
  tenant_id="$1"
  tenant_slug="$2"
  tenant_name="$3"

  escaped_tenant_id="$(sql_escape "$tenant_id")"
  store_row="$(compose exec -T db sh -c "mysql --default-character-set=utf8mb4 -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" --batch --skip-column-names \"\$MYSQL_DATABASE\" -e \"SELECT CONCAT_WS('|', id, slug, REPLACE(name, '|', ' ')) FROM stores WHERE tenant_id = '${escaped_tenant_id}' ORDER BY created_at ASC, id ASC LIMIT 1\"" 2>/dev/null || true)"
  if [ -z "$store_row" ]; then
    echo "Target tenant has no store. Create a store before ensure-ops-users." >&2
    exit 1
  fi
  old_ifs="$IFS"
  IFS="|"
  # shellcheck disable=SC2086
  set -- $store_row
  IFS="$old_ifs"
  store_id="$1"

  password_hash="$(password_hash_from_php "$ops_password")"
  escaped_password_hash="$(sql_escape "$password_hash")"
  escaped_store_id="$(sql_escape "$store_id")"
  has_visible_tools=0
  if column_exists "user_stores" "visible_tools"; then
    has_visible_tools=1
  fi

  manager_exists="$(mysql_scalar "SELECT COUNT(*) FROM users WHERE tenant_id = '${escaped_tenant_id}' AND role = 'manager' AND is_active = 1" 2>/dev/null || printf '0')"
  staff_exists="$(mysql_scalar "SELECT COUNT(*) FROM users WHERE tenant_id = '${escaped_tenant_id}' AND role = 'staff' AND is_active = 1" 2>/dev/null || printf '0')"
  device_exists="$(mysql_scalar "SELECT COUNT(*) FROM users WHERE tenant_id = '${escaped_tenant_id}' AND role = 'device' AND is_active = 1" 2>/dev/null || printf '0')"

  manager_username="${POSLA_MANAGER_USERNAME:-${tenant_slug}-manager}"
  staff_username="${POSLA_STAFF_USERNAME:-${tenant_slug}-staff}"
  device_username="${POSLA_DEVICE_USERNAME:-${tenant_slug}-kds01}"
  manager_display_name="${POSLA_MANAGER_DISPLAY_NAME:-${tenant_name} Manager}"
  staff_display_name="${POSLA_STAFF_DISPLAY_NAME:-${tenant_name} Staff}"
  device_display_name="${POSLA_DEVICE_DISPLAY_NAME:-${tenant_name} KDS}"
  manager_user_id="${POSLA_MANAGER_USER_ID:-$(generate_hex_id)}"
  staff_user_id="${POSLA_STAFF_USER_ID:-$(generate_hex_id)}"
  device_user_id="${POSLA_DEVICE_USER_ID:-$(generate_hex_id)}"

  if [ "$manager_exists" = "0" ]; then validate_username "$manager_username"; fi
  if [ "$staff_exists" = "0" ]; then validate_username "$staff_username"; fi
  if [ "$device_exists" = "0" ]; then validate_username "$device_username"; fi

  for role_username in "$manager_username" "$staff_username" "$device_username"; do
    escaped_role_username="$(sql_escape "$role_username")"
    username_count="$(mysql_scalar "SELECT COUNT(*) FROM users WHERE username = '${escaped_role_username}'" 2>/dev/null || printf '0')"
    if [ "$username_count" != "0" ]; then
      role_count="$(mysql_scalar "SELECT COUNT(*) FROM users WHERE username = '${escaped_role_username}' AND tenant_id = '${escaped_tenant_id}' AND role IN ('manager', 'staff', 'device')" 2>/dev/null || printf '0')"
      if [ "$role_count" = "0" ]; then
        echo "Username already exists and is not one of the target ops roles: $role_username" >&2
        exit 1
      fi
    fi
  done

  if [ "$manager_exists" != "0" ] && [ "$staff_exists" != "0" ] && [ "$device_exists" != "0" ]; then
    echo "Ops users already exist in $CELL_ID for tenant $tenant_slug"
    return 0
  fi

  escaped_manager_user_id="$(sql_escape "$manager_user_id")"
  escaped_staff_user_id="$(sql_escape "$staff_user_id")"
  escaped_device_user_id="$(sql_escape "$device_user_id")"
  escaped_manager_username="$(sql_escape "$manager_username")"
  escaped_staff_username="$(sql_escape "$staff_username")"
  escaped_device_username="$(sql_escape "$device_username")"
  escaped_manager_display_name="$(sql_escape "$manager_display_name")"
  escaped_staff_display_name="$(sql_escape "$staff_display_name")"
  escaped_device_display_name="$(sql_escape "$device_display_name")"

  {
    printf 'START TRANSACTION;\n'
    if [ "$manager_exists" = "0" ]; then
      printf "INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active, created_at, updated_at) VALUES ('%s', '%s', NULL, '%s', '%s', '%s', 'manager', 1, NOW(), NOW());\n" \
        "$escaped_manager_user_id" "$escaped_tenant_id" "$escaped_manager_username" "$escaped_password_hash" "$escaped_manager_display_name"
      if [ "$has_visible_tools" = "1" ]; then
        printf "INSERT INTO user_stores (user_id, store_id, visible_tools) VALUES ('%s', '%s', NULL);\n" "$escaped_manager_user_id" "$escaped_store_id"
      else
        printf "INSERT INTO user_stores (user_id, store_id) VALUES ('%s', '%s');\n" "$escaped_manager_user_id" "$escaped_store_id"
      fi
    fi
    if [ "$staff_exists" = "0" ]; then
      printf "INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active, created_at, updated_at) VALUES ('%s', '%s', NULL, '%s', '%s', '%s', 'staff', 1, NOW(), NOW());\n" \
        "$escaped_staff_user_id" "$escaped_tenant_id" "$escaped_staff_username" "$escaped_password_hash" "$escaped_staff_display_name"
      if [ "$has_visible_tools" = "1" ]; then
        printf "INSERT INTO user_stores (user_id, store_id, visible_tools) VALUES ('%s', '%s', NULL);\n" "$escaped_staff_user_id" "$escaped_store_id"
      else
        printf "INSERT INTO user_stores (user_id, store_id) VALUES ('%s', '%s');\n" "$escaped_staff_user_id" "$escaped_store_id"
      fi
    fi
    if [ "$device_exists" = "0" ]; then
      printf "INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active, created_at, updated_at) VALUES ('%s', '%s', NULL, '%s', '%s', '%s', 'device', 1, NOW(), NOW());\n" \
        "$escaped_device_user_id" "$escaped_tenant_id" "$escaped_device_username" "$escaped_password_hash" "$escaped_device_display_name"
      if [ "$has_visible_tools" = "1" ]; then
        printf "INSERT INTO user_stores (user_id, store_id, visible_tools) VALUES ('%s', '%s', 'kds,register');\n" "$escaped_device_user_id" "$escaped_store_id"
      else
        printf "INSERT INTO user_stores (user_id, store_id) VALUES ('%s', '%s');\n" "$escaped_device_user_id" "$escaped_store_id"
      fi
    fi
    printf 'COMMIT;\n'
  } | compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'

  echo "Ensured ops users in $CELL_ID for tenant $tenant_slug"
  if [ "$manager_exists" = "0" ]; then echo "manager_username=$manager_username"; fi
  if [ "$staff_exists" = "0" ]; then echo "staff_username=$staff_username"; fi
  if [ "$device_exists" = "0" ]; then echo "device_username=$device_username"; fi
}

backup_cell() {
  timestamp="$(date '+%Y%m%d_%H%M%S')"
  deploy_version="$(current_deploy_version)"
  safe_version="$(printf '%s' "$deploy_version" | sed 's/[^A-Za-z0-9_.-]/_/g')"
  backup_dir="$CELL_DIR/backups/${timestamp}_${safe_version}"
  mkdir -p "$backup_dir"

  cp "$CELL_DIR/app.env" "$backup_dir/app.env"
  cp "$CELL_DIR/db.env" "$backup_dir/db.env"
  if [ -f "$CELL_DIR/cell.env" ]; then
    cp "$CELL_DIR/cell.env" "$backup_dir/cell.env"
  fi

  {
    printf 'cell_id=%s\n' "$CELL_ID"
    printf 'created_at=%s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')"
    printf 'deploy_version=%s\n' "$deploy_version"
    printf 'php_image=%s\n' "$POSLA_PHP_IMAGE"
    printf 'http_port=%s\n' "$POSLA_CELL_HTTP_PORT"
    printf 'db_port=%s\n' "$POSLA_CELL_DB_PORT"
  } > "$backup_dir/manifest.env"

  if db_mysql_ready; then
    if ! compose exec -T db sh -c 'mysqldump --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --single-transaction --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' > "$backup_dir/db.sql"; then
      rm -f "$backup_dir/db.sql"
      echo "DB backup failed: $backup_dir/db.sql" >&2
      return 1
    fi
  else
    printf 'DB was not running or not ready; db.sql was not created.\n' > "$backup_dir/db-not-backed-up.txt"
  fi

  if [ -d "$CELL_DIR/uploads" ]; then
    find "$CELL_DIR/uploads" -type f -print > "$backup_dir/uploads-files.txt"
    if [ "${POSLA_CELL_BACKUP_UPLOADS:-0}" = "1" ]; then
      tar -czf "$backup_dir/uploads.tar.gz" -C "$CELL_DIR" uploads
    fi
  else
    printf 'uploads directory not found.\n' > "$backup_dir/uploads-files.txt"
  fi

  printf '%s\n' "$backup_dir"
}

print_rollback_hint() {
  backup_dir="$1"
  echo "Rollback hint for $CELL_ID:" >&2
  echo "  1. Set POSLA_PHP_IMAGE / POSLA_DEPLOY_VERSION in $CELL_DIR/cell.env or app.env to the previous artifact." >&2
  echo "  2. Restore DB if needed: mysql < $backup_dir/db.sql" >&2
  echo "  3. Re-run: scripts/cell/cell.sh $CELL_ID deploy" >&2
}

backup_root() {
  printf '%s\n' "$CELL_DIR/backups"
}

list_backups() {
  root="$(backup_root)"
  if [ ! -d "$root" ]; then
    echo "No backups found for $CELL_ID"
    return 0
  fi
  find "$root" -mindepth 1 -maxdepth 1 -type d | sort
}

resolve_backup_dir() {
  backup_ref="${1:-latest}"
  root="$(backup_root)"
  if [ "$backup_ref" = "latest" ]; then
    if [ ! -d "$root" ]; then
      echo "No backups found for $CELL_ID" >&2
      return 1
    fi
    latest="$(find "$root" -mindepth 1 -maxdepth 1 -type d | sort | tail -n 1)"
    if [ -z "$latest" ]; then
      echo "No backups found for $CELL_ID" >&2
      return 1
    fi
    printf '%s\n' "$latest"
    return 0
  fi
  case "$backup_ref" in
    /*) backup_dir="$backup_ref" ;;
    *) backup_dir="$root/$backup_ref" ;;
  esac
  if [ ! -d "$backup_dir" ]; then
    echo "Backup not found: $backup_dir" >&2
    return 1
  fi
  printf '%s\n' "$backup_dir"
}

require_restore_confirm() {
  if [ "${POSLA_CELL_RESTORE_CONFIRM:-}" != "$CELL_ID" ]; then
    echo "Restore blocked for $CELL_ID." >&2
    echo "Set POSLA_CELL_RESTORE_CONFIRM=$CELL_ID to confirm this destructive operation." >&2
    exit 1
  fi
}

print_rollback_plan() {
  backup_dir="$(resolve_backup_dir "${1:-latest}")"
  echo "Rollback plan for $CELL_ID"
  echo "Backup: $backup_dir"
  if [ -f "$backup_dir/manifest.env" ]; then
    echo
    echo "Manifest:"
    sed 's/^/  /' "$backup_dir/manifest.env"
  fi
  echo
  echo "Available restore items:"
  [ -f "$backup_dir/app.env" ] && echo "  env: app.env"
  [ -f "$backup_dir/db.env" ] && echo "  env: db.env"
  [ -f "$backup_dir/cell.env" ] && echo "  env: cell.env"
  [ -s "$backup_dir/db.sql" ] && echo "  db:  db.sql"
  [ -f "$backup_dir/uploads.tar.gz" ] && echo "  uploads: uploads.tar.gz"
  echo
  echo "Commands:"
  echo "  POSLA_CELL_RESTORE_CONFIRM=$CELL_ID scripts/cell/cell.sh $CELL_ID restore-env '$backup_dir'"
  if [ -s "$backup_dir/db.sql" ]; then
    echo "  POSLA_CELL_RESTORE_CONFIRM=$CELL_ID scripts/cell/cell.sh $CELL_ID restore-db '$backup_dir'"
  fi
  echo "  scripts/cell/cell.sh $CELL_ID deploy"
}

restore_env() {
  require_restore_confirm
  backup_dir="$(resolve_backup_dir "${1:-latest}")"
  for f in app.env db.env cell.env; do
    if [ -f "$backup_dir/$f" ]; then
      cp "$backup_dir/$f" "$CELL_DIR/$f"
    fi
  done
  reload_runtime_env
  echo "Restored env files from: $backup_dir"
}

restore_db() {
  require_restore_confirm
  backup_dir="$(resolve_backup_dir "${1:-latest}")"
  if [ ! -s "$backup_dir/db.sql" ]; then
    echo "Backup db.sql not found or empty: $backup_dir/db.sql" >&2
    exit 1
  fi
  if ! db_mysql_ready; then
    echo "DB is not ready for $CELL_ID. Start the cell before restore-db." >&2
    exit 1
  fi
  compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < "$backup_dir/db.sql"
  record_deployment "rolled_back" "restore-db backup=$backup_dir"
  register_cell_db "active" || true
  echo "Restored DB from: $backup_dir/db.sql"
}

wait_db_ready() {
  i=0
  while [ "$i" -lt 30 ]; do
    if db_mysql_ready; then
      return 0
    fi
    i=$((i + 1))
    sleep 1
  done
  db_mysql_ready
}

rollback_cell() {
  require_restore_confirm
  backup_dir="$(resolve_backup_dir "${1:-latest}")"
  restore_env "$backup_dir"
  compose up -d db
  wait_db_ready
  if [ -s "$backup_dir/db.sql" ]; then
    restore_db "$backup_dir"
  else
    record_deployment "rolled_back" "restore-env only backup=$backup_dir"
  fi
  deploy_cell
}

smoke_ok() {
  echo "ok: $1"
}

smoke_warn() {
  echo "warn: $1" >&2
  if [ "${POSLA_CELL_SMOKE_STRICT:-0}" = "1" ]; then
    smoke_failed=1
  fi
}

smoke_error() {
  echo "error: $1" >&2
  smoke_failed=1
}

smoke_required_table() {
  table_name="$1"
  if table_exists "$table_name"; then
    smoke_ok "table exists: $table_name"
  else
    smoke_warn "table missing: $table_name"
  fi
}

smoke_cell() {
  smoke_failed=0
  ping_url="http://127.0.0.1:${POSLA_CELL_HTTP_PORT}/api/monitor/ping.php"
  expected_version="$(current_deploy_version)"

  echo "Smoke test for $CELL_ID"
  echo "Ping URL: $ping_url"

  if ping_json="$(curl -sf "$ping_url")"; then
    smoke_ok "app ping responded"
    printf '%s\n' "$ping_json"
    if printf '%s' "$ping_json" | grep -q '"ok":true'; then
      smoke_ok "app ping ok=true"
    else
      smoke_error "app ping did not return ok=true"
    fi
    if printf '%s' "$ping_json" | grep -q "\"cell_id\":\"$CELL_ID\""; then
      smoke_ok "cell_id matches: $CELL_ID"
    else
      smoke_error "cell_id mismatch in app ping"
    fi
    if [ -n "$expected_version" ] && printf '%s' "$ping_json" | grep -q "\"deploy_version\":\"$expected_version\""; then
      smoke_ok "deploy_version matches: $expected_version"
    elif [ -n "$expected_version" ]; then
      smoke_error "deploy_version mismatch; expected $expected_version"
    fi
  else
    smoke_error "app ping failed: $ping_url"
  fi

  if db_mysql_ready; then
    smoke_ok "DB connection ready"
    db_name="$(mysql_scalar 'SELECT DATABASE()' 2>/dev/null || true)"
    if [ -n "$db_name" ]; then
      smoke_ok "DB selected: $db_name"
    fi
  else
    smoke_error "DB connection failed"
  fi

  if db_mysql_ready; then
    smoke_required_table "schema_migrations"
    smoke_required_table "posla_cell_registry"
    smoke_required_table "posla_cell_deployments"

    if table_exists "posla_cell_registry"; then
      escaped_cell="$(sql_escape "$CELL_ID")"
      registry_count="$(mysql_scalar "SELECT COUNT(*) FROM posla_cell_registry WHERE cell_id = '${escaped_cell}'" 2>/dev/null || printf '0')"
      if [ "$registry_count" != "0" ]; then
        registry_status="$(mysql_scalar "SELECT status FROM posla_cell_registry WHERE cell_id = '${escaped_cell}' ORDER BY updated_at DESC LIMIT 1" 2>/dev/null || true)"
        smoke_ok "registry row exists: $CELL_ID status=${registry_status:-unknown}"
      else
        smoke_warn "registry row missing for cell_id=$CELL_ID"
      fi
    fi

    if table_exists "posla_cell_deployments"; then
      escaped_cell="$(sql_escape "$CELL_ID")"
      deployment_count="$(mysql_scalar "SELECT COUNT(*) FROM posla_cell_deployments WHERE cell_id = '${escaped_cell}'" 2>/dev/null || printf '0')"
      if [ "$deployment_count" != "0" ]; then
        smoke_ok "deployment history rows: $deployment_count"
      else
        smoke_warn "deployment history row missing for cell_id=$CELL_ID"
      fi
    fi
  fi

  if [ "$smoke_failed" != "0" ]; then
    echo "Smoke failed for $CELL_ID" >&2
    exit 1
  fi
  echo "Smoke passed for $CELL_ID"
}

deploy_cell() {
  mkdir -p "$CELL_DIR/uploads"
  compose config -q
  if ! backup_dir="$(backup_cell)"; then
    record_deployment "failed" "backup failed before deploy"
    echo "Backup failed; deploy aborted for $CELL_ID" >&2
    return 1
  fi
  echo "Backup created: $backup_dir"
  record_deployment "planned" "pre-deploy backup=$backup_dir"
  if compose up -d --no-build; then
    if wait_ping; then
      register_cell_db "active" || true
      record_deployment "deployed" "backup=$backup_dir"
      return 0
    fi
    record_deployment "failed" "ping failed; backup=$backup_dir"
    print_rollback_hint "$backup_dir"
    return 1
  fi
  record_deployment "failed" "compose up failed; backup=$backup_dir"
  print_rollback_hint "$backup_dir"
  return 1
}

case "$COMMAND" in
  config)
    compose config -q
    ;;
  build)
    stamp_deploy_metadata
    compose build php
    ;;
  backup)
    backup_cell
    ;;
  backups)
    list_backups
    ;;
  up)
    mkdir -p "$CELL_DIR/uploads"
    compose up -d --no-build
    ;;
  deploy)
    deploy_cell
    ;;
  migrate)
    if [ "$#" -lt 3 ]; then
      echo "SQL file is required for migrate." >&2
      usage
      exit 2
    fi
    MIGRATION_FILE="$3"
    case "$MIGRATION_FILE" in
      /*) ;;
      *) MIGRATION_FILE="$ROOT_DIR/$MIGRATION_FILE" ;;
    esac
    if [ ! -f "$MIGRATION_FILE" ]; then
      echo "SQL file not found: $MIGRATION_FILE" >&2
      exit 1
    fi
    MIGRATION_KEY="$(basename "$MIGRATION_FILE")"
    if schema_migrations_exists && schema_migration_applied "$MIGRATION_KEY"; then
      echo "Migration already applied in $CELL_ID: $MIGRATION_KEY"
      exit 0
    fi
    compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < "$MIGRATION_FILE"
    if schema_migrations_exists; then
      record_schema_migration "$MIGRATION_KEY" "$(checksum_file "$MIGRATION_FILE")"
    else
      echo "schema_migrations table not found after applying $MIGRATION_KEY; ledger record skipped" >&2
    fi
    ;;
  register-db)
    register_cell_db "active"
    ;;
  sync-registry)
    sync_local_registry_from_db "active"
    ;;
  rollback-plan)
    print_rollback_plan "${3:-latest}"
    ;;
  restore-env)
    restore_env "${3:-latest}"
    ;;
  restore-db)
    restore_db "${3:-latest}"
    ;;
  rollback)
    rollback_cell "${3:-latest}"
    ;;
  smoke)
    smoke_cell
    ;;
  onboard-tenant)
    shift 2
    onboard_tenant "$@"
    ;;
  ensure-ops-users)
    shift 2
    ensure_ops_users "$@"
    ;;
  down)
    compose down
    ;;
  ps)
    compose ps
    ;;
  logs)
    compose logs --tail=100 php
    ;;
  ping)
    curl -sf "http://127.0.0.1:${POSLA_CELL_HTTP_PORT}/api/monitor/ping.php"
    printf '\n'
    ;;
  *)
    usage
    exit 2
    ;;
esac
