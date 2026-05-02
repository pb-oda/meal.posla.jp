#!/usr/bin/env bash
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE_URL="${BASE_URL:-http://127.0.0.1:8081}"
FAIL=0

cd "$ROOT" || exit 1

pass() { printf '[PASS] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*"; }
fail() { printf '[FAIL] %s\n' "$*"; FAIL=$((FAIL+1)); }

echo "=== POSLA Cloud Run pre-deploy check ($(date)) ==="
echo "root: $ROOT"
echo "base: $BASE_URL"
echo "head: $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

if [ -f docker/php/Dockerfile.cloudrun ]; then
  pass "Cloud Run Dockerfile exists"
else
  fail "docker/php/Dockerfile.cloudrun missing"
fi

if [ -f docker/php/startup-cloudrun.sh ] && [ -f docker/php/cron-job-cloudrun.sh ]; then
  pass "Cloud Run startup/job entrypoints exist"
else
  fail "Cloud Run startup/job entrypoint missing"
fi

if php -l scripts/cloudrun/cron-runner.php >/tmp/posla_cloudrun_cron_lint.txt; then
  pass "cron-runner.php syntax"
else
  fail "cron-runner.php syntax"
  cat /tmp/posla_cloudrun_cron_lint.txt
fi

if sh -n docker/php/startup-cloudrun.sh && sh -n docker/php/cron-job-cloudrun.sh; then
  pass "Cloud Run shell entrypoint syntax"
else
  fail "Cloud Run shell entrypoint syntax"
fi

for file in \
  sql/migration-p1-46-register-payment-detail.sql \
  sql/migration-p1-47-register-close-reconciliation.sql \
  sql/migration-p1-70-terminal-monitoring-policy.sql; do
  if [ -f "$file" ]; then
    pass "migration exists: $file"
  else
    fail "migration missing: $file"
  fi
done

for token in monitoring_enabled operational_status monitor_business_hours_only; do
  if grep -q "$token" sql/migration-p1-70-terminal-monitoring-policy.sql; then
    pass "migration-p1-70 includes $token"
  else
    fail "migration-p1-70 missing $token"
  fi
done

ping_body="$(curl -sS --retry 3 --retry-all-errors --connect-timeout 2 "$BASE_URL/api/monitor/ping.php" 2>/dev/null || true)"
if printf '%s' "$ping_body" | grep -q '"ok":true'; then
  pass "monitor ping ok"
else
  warn "monitor ping not reachable at $BASE_URL"
fi

snapshot="$(curl -sS --retry 3 --retry-all-errors --connect-timeout 2 "$BASE_URL/api/monitor/cell-snapshot.php" 2>/dev/null || true)"
if printf '%s' "$snapshot" | grep -q '"ok":true'; then
  pass "cell snapshot ok"
  for section in reservations shifts takeout menu_inventory customer_line external_integrations terminal_heartbeat; do
    if printf '%s' "$snapshot" | grep -q "\"$section\""; then
      pass "snapshot includes $section"
    else
      fail "snapshot missing $section"
    fi
  done
else
  warn "cell snapshot not reachable without production read secret; verify with OP read secret before cutover"
fi

echo "=== Summary ==="
echo "failures: $FAIL"
exit "$FAIL"
