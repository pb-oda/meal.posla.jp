#!/bin/sh
set -eu

echo "[posla-cron] scheduler started"

tick=0
while true
do
  tick=$((tick + 1))
  started_at="$(date '+%Y-%m-%d %H:%M:%S')"
  echo "[posla-cron] every-5-minutes start ${started_at}"
  php /var/www/html/scripts/cloudrun/cron-runner.php every-5-minutes || true

  if [ $((tick % 12)) -eq 0 ]; then
    echo "[posla-cron] hourly start $(date '+%Y-%m-%d %H:%M:%S')"
    php /var/www/html/scripts/cloudrun/cron-runner.php hourly || true
  fi

  sleep 300
done
