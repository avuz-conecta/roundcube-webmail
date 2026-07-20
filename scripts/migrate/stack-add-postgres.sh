#!/bin/bash
# Idempotently add an in-stack `postgres` service + `roundcube_pg` volume to a LIVE
# Portainer stack, and set ROUNDCUBE_PG_PASSWORD as a stack env var. Fetches the
# live compose and MERGES into it (preserves imapproxy et al) — never overwrites
# with a reference file.
#
# Does NOT touch ROUNDCUBE_DB_DSN: roundcube stays on its current DB until a
# separate flip after the data migration.
#
# Usage:  stack-add-postgres.sh <stack_name> <pg_password>
#   DRY_RUN=1 prints the resulting compose + env and exits WITHOUT changing anything.
#   PORTAINER_ENV_FILE selects staging (default) vs prod.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CFG="${PORTAINER_ENV_FILE:-$SCRIPT_DIR/../deploy.env}"
die(){ echo "error: $*" >&2; exit 1; }
[ "$#" -eq 2 ] || die "usage: $0 <stack_name> <pg_password>"
NAME="$1"; PW="$2"
[ -f "$CFG" ] || die "no $CFG"
# shellcheck disable=SC1090
set -a; . "$CFG"; set +a
PORTAINER_URL="${PORTAINER_URL%/}"
OPTS=(-fsS); [ "${PORTAINER_INSECURE:-0}" = "1" ] && OPTS+=(-k)
api(){ curl "${OPTS[@]}" -H "X-API-Key: $PORTAINER_TOKEN" "$@"; }

# A stack name can exist on multiple endpoints (e.g. avuz-mail-roundcube on 5 and 9).
# Disambiguate with ENDPOINT=<id> when that happens.
ROWS="$(api "$PORTAINER_URL/api/stacks" | jq -c --arg n "$NAME" '[.[]|select(.Name==$n)]')"
CNT="$(jq 'length' <<<"$ROWS")"
if [ "$CNT" = "0" ]; then die "stack '$NAME' not found"; fi
if [ "$CNT" -gt 1 ]; then
  [ -n "${ENDPOINT:-}" ] || die "stack '$NAME' exists on multiple endpoints ($(jq -r '[.[].EndpointId]|join(",")' <<<"$ROWS")); set ENDPOINT=<id>"
  ROW="$(jq -c --argjson e "$ENDPOINT" '.[]|select(.EndpointId==$e)' <<<"$ROWS")"
  [ -n "$ROW" ] || die "stack '$NAME' not on endpoint $ENDPOINT"
else
  ROW="$(jq -c '.[0]' <<<"$ROWS")"
fi
SID="$(jq -r '.Id' <<<"$ROW")"; EID="$(jq -r '.EndpointId' <<<"$ROW")"
FILE="$(api "$PORTAINER_URL/api/stacks/$SID/file" | jq -r '.StackFileContent')"
ENV="$(jq -c '.Env // []' <<<"$ROW")"

IFS= read -r -d '' PGBLOCK <<'YAML' || true
  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      - POSTGRES_USER=roundcube
      - POSTGRES_DB=roundcube
      - POSTGRES_PASSWORD=${ROUNDCUBE_PG_PASSWORD}
    volumes:
      - roundcube_pg:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U roundcube -d roundcube"]
      interval: 10s
      timeout: 5s
      retries: 5
YAML

# Inject the postgres service before top-level `volumes:` and add roundcube_pg,
# unless a postgres service is already present (idempotent).
if grep -qE '^  postgres:[[:space:]]*$' <<<"$FILE"; then
  echo "postgres service already present — leaving compose unchanged"
else
  FILE="$(COMPOSE="$FILE" PGBLOCK="$PGBLOCK" python3 -c '
import os,sys
txt=os.environ["COMPOSE"]; pg=os.environ["PGBLOCK"].rstrip("\n")+"\n"
out=[]; done=False
for l in txt.splitlines(keepends=True):
    if l.rstrip("\n")=="volumes:" and not done:
        out.append(pg); out.append(l); out.append("  roundcube_pg:\n"); done=True; continue
    out.append(l)
if not done:
    if out and not out[-1].endswith("\n"): out.append("\n")
    out.append("\n"+pg+"volumes:\n  roundcube_pg:\n")
sys.stdout.write("".join(out))
')"
fi

# Ensure ROUNDCUBE_PG_PASSWORD is in the stack env (replace if already there).
ENV="$(jq -c --arg v "$PW" 'map(select(.name!="ROUNDCUBE_PG_PASSWORD")) + [{name:"ROUNDCUBE_PG_PASSWORD",value:$v}]' <<<"$ENV")"

if [ "${DRY_RUN:-0}" = "1" ]; then
  echo "===== DRY RUN — resulting compose ====="; printf '%s\n' "$FILE"
  echo "===== env keys ====="; jq -r '.[].name' <<<"$ENV"
  echo "(no changes pushed; unset DRY_RUN to apply)"; exit 0
fi

# NO_PULL=1 keeps the current running images (don't pull :latest). Use during the
# rehearsal so adding Postgres does NOT prematurely deploy a new roundcube image.
PULL="true"; [ "${NO_PULL:-0}" = "1" ] && PULL="false"
BODY="$(jq -n --arg f "$FILE" --argjson e "$ENV" --argjson pull "$PULL" '{stackFileContent:$f, env:$e, prune:false, pullImage:$pull}')"
api -X PUT -H 'Content-Type: application/json' -d "$BODY" \
  "$PORTAINER_URL/api/stacks/$SID?endpointId=$EID" >/dev/null
echo "stack '$NAME' (id $SID) updated: postgres service + roundcube_pg volume + ROUNDCUBE_PG_PASSWORD env"
