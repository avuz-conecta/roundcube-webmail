#!/bin/bash
# Run a command inside a running container via the Portainer Docker API proxy —
# no SSH to the host. Reads scripts/deploy.env (PORTAINER_URL, PORTAINER_TOKEN,
# PORTAINER_INSECURE). Finds the container across all Portainer environments by
# name, then execs with a TTY so output comes back raw (no stream framing).
#
# Ported from avuz-conecta/avuz-server (same Portainer, same token model).
#
# Usage:
#   ./scripts/portainer-exec.sh <container> <cmd> [args...]
#
# Examples:
#   ./scripts/portainer-exec.sh grupo-vidalar-roundcube-1 php -v
#   ./scripts/portainer-exec.sh grupo-vidalar-roundcube-1 sh -c 'tail -50 /var/www/roundcube/logs/errors.log'
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Environment-scoped config: default staging (deploy.env). Set PORTAINER_ENV_FILE
# (e.g. to deploy.prod.env) to target another environment.
CONFIG_FILE="${PORTAINER_ENV_FILE:-$SCRIPT_DIR/deploy.env}"

die() { echo "error: $*" >&2; exit 1; }

command -v jq >/dev/null || die "jq is required (brew install jq)"
[ -f "$CONFIG_FILE" ] || die "no $CONFIG_FILE — see deploy.env.example"
# shellcheck disable=SC1090
set -a; . "$CONFIG_FILE"; set +a
[ -n "${PORTAINER_URL:-}" ]   || die "PORTAINER_URL not set in $CONFIG_FILE"
[ -n "${PORTAINER_TOKEN:-}" ] || die "PORTAINER_TOKEN not set in $CONFIG_FILE"
PORTAINER_URL="${PORTAINER_URL%/}"

CURL_OPTS=()
[ "${PORTAINER_INSECURE:-0}" = "1" ] && CURL_OPTS+=(-k)
api() { curl -fsS "${CURL_OPTS[@]}" -H "X-API-Key: $PORTAINER_TOKEN" "$@"; }

# Optional -u/--user to run the exec as a specific user (e.g. www-data — the
# roundcube logs/temp are owned by www-data, so reads may need it).
EXEC_USER=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    -u|--user) EXEC_USER="${2:?-u needs a username}"; shift 2 ;;
    *) break ;;
  esac
done

[ "$#" -ge 2 ] || die "usage: $0 [-u user] <container> <cmd> [args...]"
CONTAINER="$1"; shift
# Build the Cmd JSON array from the remaining args. Each arg goes through --arg
# so dashes (php -i, grep -c, --flag) are never parsed as jq options.
CMD_JSON="$(for a in "$@"; do jq -Rn --arg x "$a" '$x'; done | jq -sc .)"

# Find which environment (endpoint) holds the container, and its id.
ENDPOINT=""; CID=""
for eid in $(api "$PORTAINER_URL/api/endpoints" | jq -r '.[].Id'); do
  cid="$(api "$PORTAINER_URL/api/endpoints/$eid/docker/containers/json?all=1" \
    | jq -r --arg n "$CONTAINER" '.[] | select(.Names[] | ltrimstr("/") == $n) | .Id' | head -1)"
  if [ -n "$cid" ]; then ENDPOINT="$eid"; CID="$cid"; break; fi
done
[ -n "$CID" ] || die "container '$CONTAINER' not found in any Portainer environment"

# Create the exec instance (TTY => raw, unmultiplexed output).
EXEC_ID="$(api -X POST -H 'Content-Type: application/json' \
  -d "$(jq -n --argjson cmd "$CMD_JSON" --arg user "$EXEC_USER" \
        '{AttachStdout:true, AttachStderr:true, Tty:true, Cmd:$cmd}
         + (if $user == "" then {} else {User:$user} end)')" \
  "$PORTAINER_URL/api/endpoints/$ENDPOINT/docker/containers/$CID/exec" | jq -r '.Id')"
[ -n "$EXEC_ID" ] && [ "$EXEC_ID" != "null" ] || die "could not create exec instance"

# Start it and stream the raw output back.
api -X POST -H 'Content-Type: application/json' \
  -d '{"Detach":false,"Tty":true}' \
  "$PORTAINER_URL/api/endpoints/$ENDPOINT/docker/exec/$EXEC_ID/start"
