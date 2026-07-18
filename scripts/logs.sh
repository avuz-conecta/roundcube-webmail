#!/bin/bash
# Fetch a Roundcube log from a running container via Portainer (no SSH).
# Thin wrapper over portainer-exec.sh. Logs live in the roundcube_logs volume
# at /var/www/roundcube/logs/. Reads as www-data (log files are www-data-owned).
#
# Usage:
#   ./scripts/logs.sh <container> [log] [lines]
#   ./scripts/logs.sh <container> <log> grep <pattern>
#
#   <log>   one of: errors (default), imap, smtp, sql, console  — or a full path
#   [lines] tail count (default 100)
#
# Examples:
#   ./scripts/logs.sh grupo-vidalar-roundcube-1                      # tail 100 errors.log
#   ./scripts/logs.sh grupo-vidalar-roundcube-1 errors 200
#   ./scripts/logs.sh grupo-vidalar-roundcube-1 errors grep temp_dir
# For prod: PORTAINER_ENV_FILE=scripts/deploy.prod.env ./scripts/logs.sh ...
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="/var/www/roundcube/logs"

die() { echo "error: $*" >&2; exit 1; }
[ "$#" -ge 1 ] || die "usage: $0 <container> [log] [lines] | <container> <log> grep <pattern>"

CONTAINER="$1"; shift
LOG="${1:-errors}"; [ "$#" -ge 1 ] && shift

# Resolve a short name to a path; pass an absolute path through untouched.
case "$LOG" in
  /*) FILE="$LOG" ;;
  *)  FILE="$LOG_DIR/$LOG.log" ;;
esac

if [ "${1:-}" = "grep" ]; then
  PATTERN="${2:?grep needs a pattern}"
  # Single-quote the pattern for the remote shell; escape embedded single quotes.
  Q="'${PATTERN//\'/\'\\\'\'}'"
  CMD="grep -iE -- $Q \"$FILE\" 2>/dev/null | tail -100 || echo '(no matches / no log yet)'"
else
  LINES="${1:-100}"
  CMD="tail -n $LINES \"$FILE\" 2>/dev/null || echo '(no log file yet: $FILE)'"
fi

exec "$SCRIPT_DIR/portainer-exec.sh" -u www-data "$CONTAINER" sh -c "$CMD"
