#!/bin/bash
# Per-action latency percentiles (default) or prefetch-concurrency split, from a
# container's nginx-perf.log.
#   ./scripts/perf-report.sh <container> [actions|concurrency] [tail-lines]
# staging:  PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/perf-report.sh avuz-mail-roundcube-2-roundcube-1
#           ... avuz-mail-roundcube-2-roundcube-1 concurrency
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="${1:?usage: perf-report.sh <container> [actions|concurrency] [tail-lines]}"
MODE="${2:-actions}"
LINES="${3:-100000}"
case "$MODE" in
  actions)     AWK_FILE="perf-percentiles.awk" ;;
  concurrency) AWK_FILE="perf-concurrency.awk" ;;
  *) echo "mode must be 'actions' or 'concurrency'" >&2; exit 2 ;;
esac
# base64 the awk body so quotes/apostrophes in it never collide with the remote
# sh -c quoting. The container decodes it to a temp file and runs awk -f.
AWK_B64="$(base64 < "$SCRIPT_DIR/$AWK_FILE" | tr -d '\n')"
PORTAINER_ENV_FILE="${PORTAINER_ENV_FILE:-$SCRIPT_DIR/deploy.env}" \
PORTAINER_ENDPOINT="${PORTAINER_ENDPOINT:-}" \
  "$SCRIPT_DIR/portainer-exec.sh" -u www-data "$CONTAINER" \
  sh -c "echo $AWK_B64 | base64 -d > /tmp/perf.awk && tail -n $LINES /var/www/roundcube/logs/nginx-perf.log | awk -f /tmp/perf.awk"
