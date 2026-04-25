#!/usr/bin/env bash
# 06-pseudo-prod-release.sh
# 擬似本番 Docker (127.0.0.1:8081) のリリース前スモーク。
# read-only チェックのみ。DB やファイルは変更しない。

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE_URL="${BASE_URL:-http://127.0.0.1:8081}"
OWNER_USER="${OWNER_USER:-test-01-owner}"
OWNER_PASS="${OWNER_PASS:-Demo1234}"
POSLA_EMAIL="${POSLA_EMAIL:-oda@plusbelief.co.jp}"
POSLA_PASS="${POSLA_PASS:-Demo1234}"
FAIL=0

cd "${ROOT}" || exit 1

log()  { echo "[$1] $2"; }
pass() { log PASS "$*"; }
warn() { log WARN "$*"; }
fail() { log FAIL "$*"; FAIL=$((FAIL+1)); }

check_http_200() {
  local path="$1"
  local code

  code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}${path}")
  if [ "$code" = "200" ]; then
    pass "GET ${path} -> 200"
  else
    fail "GET ${path} -> ${code}"
  fi
}

check_json_ok() {
  local label="$1"
  local url="$2"
  local body

  body=$(curl -s "$url")
  if echo "$body" | grep -q '"ok":true'; then
    pass "${label} -> ok:true"
  else
    fail "${label} -> unexpected response: ${body}"
  fi
}

echo "=== pseudo-prod release smoke ($(date)) ==="
echo "root: ${ROOT}"
echo "base: ${BASE_URL}"

echo
echo "[1] container / heartbeat"
if docker compose -f "${ROOT}/docker-compose.yml" ps | grep -q 'posla_php'; then
  pass "docker compose ps"
else
  fail "docker compose ps: php container not found"
fi
check_json_ok "/api/monitor/ping.php" "${BASE_URL}/api/monitor/ping.php"

echo
echo "[2] public routes"
check_http_200 "/"
check_http_200 "/admin/"
check_http_200 "/admin/dashboard.html"
check_http_200 "/admin/owner-dashboard.html"
check_http_200 "/admin/device-setup.html"
check_http_200 "/customer/menu.html"
check_http_200 "/customer/reserve.html"
check_http_200 "/customer/reserve-detail.html"
check_http_200 "/customer/takeout.html"
check_http_200 "/handy/index.html"
check_http_200 "/handy/pos-register.html"
check_http_200 "/kds/index.html"
check_http_200 "/kds/cashier.html"
check_http_200 "/live/index.html"
check_http_200 "/posla-admin/dashboard.html"
check_http_200 "/docs-tenant/"
check_http_200 "/docs-internal/"

echo
echo "[3] auth smoke"
ownerResp=$(curl -s -H 'Content-Type: application/json' \
  -d "{\"username\":\"${OWNER_USER}\",\"password\":\"${OWNER_PASS}\"}" \
  "${BASE_URL}/api/auth/login.php")
if echo "$ownerResp" | grep -q '"ok":true'; then
  pass "owner login"
else
  fail "owner login failed: ${ownerResp}"
fi

poslaResp=$(curl -s -H 'Content-Type: application/json' \
  -d "{\"email\":\"${POSLA_EMAIL}\",\"password\":\"${POSLA_PASS}\"}" \
  "${BASE_URL}/api/posla/login.php")
if echo "$poslaResp" | grep -q '"ok":true'; then
  pass "posla admin login"
else
  fail "posla admin login failed: ${poslaResp}"
fi

echo
echo "[4] syntax"
if find "${ROOT}/api" -name '*.php' -print0 | xargs -0 -n1 php -l >/tmp/posla_release_php_lint.txt; then
  pass "php -l all api/*.php"
else
  fail "php -l failed"
fi

if find "${ROOT}/public" -name '*.js' -print0 | xargs -0 -n1 node --check >/tmp/posla_release_js_check.txt; then
  pass "node --check all public/*.js"
else
  fail "node --check failed"
fi

echo
echo "[5] runtime url hygiene"
if rg -n 'eat\.posla\.jp' api public docker \
  --glob '!public/docs-internal/**' \
  --glob '!public/docs-tenant/**' \
  --glob '!public/live/**' >/tmp/posla_release_runtime_eat.txt; then
  fail "runtime code still contains eat.posla.jp"
  cat /tmp/posla_release_runtime_eat.txt
else
  pass "runtime code has no eat.posla.jp"
fi

echo
echo "[6] docs hygiene"
if [ -d "${ROOT}/docs/manual/node_modules" ]; then
  warn "docs/manual/node_modules exists"
else
  pass "docs/manual/node_modules absent"
fi

if [ -d "${ROOT}/docs/manual/.vitepress/dist" ]; then
  warn "docs/manual/.vitepress/dist exists"
else
  pass "docs/manual/.vitepress/dist absent"
fi

echo
echo "=== Summary ==="
echo "Failures: ${FAIL}"
exit $([ "${FAIL}" -eq 0 ] && echo 0 || echo 1)
