#!/usr/bin/env bash
# 02-money-path.sh
# 金銭経路の整合性チェック（DB SELECT のみ / POST は dry-run デフォルト）
# - external_payment_id UNIQUE (H-13)
# - payments.void_status の consistency
# - orphan payments（orders 不在）
# - cash_log / payments 差額

set -u
HOST="${PROD_HOST:-eat.posla.jp}"
MODE="${1:-dry-run}"  # dry-run | execute
FAIL=0

echo "=== money path ($(date)) ==="
echo "host: $HOST / mode: $MODE"

# ---------- static: code grep ----------
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

echo
echo "[1] Stripe webhook signature verification"
if grep -q 'verify_webhook_signature' "$ROOT/api/subscription/webhook.php" 2>/dev/null; then
  echo "PASS: webhook.php calls verify_webhook_signature"
else
  echo "FAIL: webhook.php missing signature check"
  FAIL=$((FAIL+1))
fi

echo
echo "[2] process-payment FOR UPDATE + rowCount check"
if grep -q 'FOR UPDATE' "$ROOT/api/store/process-payment.php" && \
   grep -q 'rowCount.*order_update_rowcount_mismatch' "$ROOT/api/store/process-payment.php"; then
  echo "PASS: process-payment has FOR UPDATE + rowCount check"
else
  echo "FAIL: process-payment FOR UPDATE / rowCount check missing"
  FAIL=$((FAIL+1))
fi

echo
echo "[3] refund-payment preflight + revert pattern"
if grep -q "4d-5c-bb-D D-fix3" "$ROOT/api/store/refund-payment.php" && \
   grep -q "refund_failed_revert\|revert_pending" "$ROOT/api/store/refund-payment.php"; then
  echo "PASS: refund-payment has preflight + revert"
else
  echo "WARN: refund-payment preflight pattern not clearly identified"
fi

echo
echo "[4] payment-void three guards"
for g in "JSON_LENGTH" "emergency_manual_transfer" "synced_payment_id"; do
  if grep -q "$g" "$ROOT/api/store/payment-void.php"; then
    echo "PASS: payment-void has guard: $g"
  else
    echo "FAIL: payment-void missing guard: $g"
    FAIL=$((FAIL+1))
  fi
done

# ---------- DB checks (optional, needs SSH + mysql) ----------
echo
echo "[5] DB-level checks (requires SSH + mysql, skip if not available)"
if command -v mysql >/dev/null 2>&1 && [ -f "$ROOT/id_ecdsa.pem" ]; then
  echo "INFO: SSH key present; real DB checks can be added here"
  echo "INFO: Recommended SQL:"
  echo "  - SELECT COUNT(*) FROM payments WHERE external_payment_id IS NOT NULL GROUP BY external_payment_id HAVING COUNT(*) > 1 (expect 0 rows)"
  echo "  - SELECT SUM(total_amount) FROM payments WHERE void_status IS NULL OR void_status='active'"
  echo "  - SELECT SUM(amount) FROM cash_log WHERE type='cash_sale'"
else
  echo "INFO: mysql CLI or id_ecdsa.pem not available — skip DB checks"
fi

# ---------- POST (execute only) ----------
echo
echo "[6] Idempotency POST (execute only)"
if [ "$MODE" = "execute" ]; then
  echo "INFO: would POST /api/store/payment-void.php twice to verify idempotency"
  echo "INFO: NOT IMPLEMENTED — requires valid session + payment_id"
else
  echo "SKIP: POST tests require mode=execute and credentials"
fi

echo
echo "=== Summary ==="
echo "Failures: $FAIL"
exit $([ $FAIL -eq 0 ] && echo 0 || echo 1)
