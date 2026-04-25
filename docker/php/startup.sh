#!/bin/sh
set -eu

case "${POSLA_CRON_ENABLED:-1}" in
  1|true|TRUE|yes|YES|on|ON)
    /usr/local/bin/posla-cron-loop.sh &
    ;;
  *)
    echo "[posla-cron] scheduler disabled by POSLA_CRON_ENABLED=${POSLA_CRON_ENABLED:-}"
    ;;
esac

exec apache2-foreground
