#!/bin/bash
# Run a one-shot container on a Portainer endpoint: create -> start -> wait ->
# print logs -> remove. Used to run pgloader / psql against the in-stack postgres
# and the roundcube_temp volume without SSH.
#
# Usage: pg-oneshot.sh <endpoint_id> <network> <image> <volume|-> <sh_command>
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CFG="${PORTAINER_ENV_FILE:-$SCRIPT_DIR/../deploy.env}"
die(){ echo "error: $*" >&2; exit 1; }
[ -f "$CFG" ] || die "no $CFG"
# shellcheck disable=SC1090
set -a; . "$CFG"; set +a
PORTAINER_URL="${PORTAINER_URL%/}"
OPTS=(-fsS); [ "${PORTAINER_INSECURE:-0}" = "1" ] && OPTS+=(-k)
api(){ curl "${OPTS[@]}" -H "X-API-Key: $PORTAINER_TOKEN" "$@"; }

[ "$#" -eq 5 ] || die "usage: $0 <endpoint_id> <network> <image> <volume|-> <sh_command>"
EID="$1"; NET="$2"; IMAGE="$3"; VOL="$4"; CMD="$5"
BASE="$PORTAINER_URL/api/endpoints/$EID/docker"

# Pull the image (idempotent).
api -X POST "$BASE/images/create?fromImage=$IMAGE" >/dev/null || true

BINDS='[]'; [ "$VOL" != "-" ] && BINDS="$(jq -nc --arg v "$VOL" '[$v + ":/data:ro"]')"
# Tty:true => the logs endpoint returns raw bytes (no 8-byte multiplex frame
# headers), so psql/pgloader output comes back clean and parseable.
BODY="$(jq -n --arg img "$IMAGE" --arg net "$NET" --arg cmd "$CMD" --argjson binds "$BINDS" \
  '{Image:$img, Tty:true, Cmd:["sh","-lc",$cmd], HostConfig:{Binds:$binds, NetworkMode:$net, AutoRemove:false}}')"
CID="$(api -X POST -H 'Content-Type: application/json' -d "$BODY" "$BASE/containers/create?name=migrate-oneshot-$$" | jq -r '.Id')"
[ -n "$CID" ] && [ "$CID" != null ] || die "create failed"

cleanup(){ api -X DELETE "$BASE/containers/$CID?force=1" >/dev/null 2>&1 || true; }
trap cleanup EXIT

api -X POST "$BASE/containers/$CID/start" >/dev/null
# Wait for exit, capture status code.
CODE="$(api -X POST "$BASE/containers/$CID/wait" | jq -r '.StatusCode')"
# Raw logs (Tty:true => no frame headers). stderr is merged into stdout.
api "$BASE/containers/$CID/logs?stdout=1&stderr=1" || true
echo "[oneshot exit=$CODE]"
exit "${CODE:-1}"
