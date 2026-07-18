#!/bin/bash
# Redeploy one or more Portainer stacks via the Portainer CE API (pull latest
# image + recreate). Portainer CE has no stack webhooks (Business only), so this
# resolves each stack by name and re-applies its existing compose + env with
# pullImage=true — no config change, just a pull + recreate.
#
# Config in scripts/deploy.env (gitignored — the token is a secret):
#     PORTAINER_URL=https://portainer.avuz.app
#     PORTAINER_TOKEN=ptr_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
# Token: Portainer → My account → Access tokens → Add. Copy deploy.env.example.
#
# Usage:
#   ./scripts/deploy.sh <stack> [<stack> ...]   # redeploy these stacks
#   ./scripts/deploy.sh --list                  # list stacks Portainer knows
#   ./scripts/deploy.sh -y <stack>              # skip the confirmation prompt
#
# Build first (this script only deploys, never builds):
#   ./scripts/build-push.sh latest staging && ./scripts/deploy.sh avuz-mail-roundcube-2
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Environment-scoped config: default is staging (deploy.env). deploy-prod.sh
# sets PORTAINER_ENV_FILE to deploy.prod.env — separate URL + token per env.
CONFIG_FILE="${PORTAINER_ENV_FILE:-$SCRIPT_DIR/deploy.env}"

die() { echo "error: $*" >&2; exit 1; }

command -v jq >/dev/null || die "jq is required (brew install jq)"
[ -f "$CONFIG_FILE" ] || die "no $CONFIG_FILE — copy $(basename "$CONFIG_FILE").example and fill in PORTAINER_URL + PORTAINER_TOKEN"

# shellcheck disable=SC1090
set -a; . "$CONFIG_FILE"; set +a
[ -n "${PORTAINER_URL:-}" ]   || die "PORTAINER_URL not set in $CONFIG_FILE"
[ -n "${PORTAINER_TOKEN:-}" ] || die "PORTAINER_TOKEN not set in $CONFIG_FILE"
PORTAINER_URL="${PORTAINER_URL%/}" # strip trailing slash

# Portainer's default HTTPS port (9443) uses a self-signed cert. Set
# PORTAINER_INSECURE=1 in deploy.env to skip cert verification (fine for an
# internal IP:port with no DNS). Leave unset when a real cert is in place.
CURL_OPTS=()
[ "${PORTAINER_INSECURE:-0}" = "1" ] && CURL_OPTS+=(-k)

# GET helper — authenticated, fails on non-2xx.
api_get() { curl -fsS "${CURL_OPTS[@]}" -H "X-API-Key: $PORTAINER_TOKEN" "$PORTAINER_URL$1"; }

ASSUME_YES=0
STACKS=()
for arg in "$@"; do
  case "$arg" in
    -l|--list)
      # Same stack name can exist in multiple environments (endpoints); show the
      # endpoint + stack id so collisions are visible and can be targeted by id.
      { echo -e "ENDPOINT\tSTACK_ID\tNAME"
        api_get "/api/stacks" \
          | jq -r '.[] | "\(.EndpointId)\t\(.Id)\t\(.Name)"' | sort -k3
      } | column -t -s "$(printf '\t')" \
        || die "could not reach Portainer at $PORTAINER_URL"
      exit 0 ;;
    -y|--yes) ASSUME_YES=1 ;;
    -*)       die "unknown flag: $arg" ;;
    *)        STACKS+=("$arg") ;;
  esac
done

[ "${#STACKS[@]}" -gt 0 ] || die "no stack given. See: $0 --list"

# A target is either a numeric stack id (unambiguous — use when names collide
# across endpoints) or a stack name. This jq selector matches whichever.
SELECT='.[] | select(if ($t|test("^[0-9]+$")) then (.Id == ($t|tonumber)) else (.Name == $t) end)'

# Fetch the stack list once; resolve every target up front so a typo or an
# ambiguous name aborts before any deploy fires.
ALL_STACKS="$(api_get "/api/stacks")" || die "could not reach Portainer at $PORTAINER_URL"
for stack in "${STACKS[@]}"; do
  matches="$(printf '%s' "$ALL_STACKS" | jq --arg t "$stack" "[$SELECT] | length")"
  if [ "$matches" = "0" ]; then
    die "stack '$stack' not found. See: $0 --list"
  elif [ "$matches" != "1" ]; then
    cand="$(printf '%s' "$ALL_STACKS" | jq -r --arg t "$stack" "$SELECT | \"  endpoint \(.EndpointId)  id \(.Id)  \(.Name)\"")"
    die "'$stack' is ambiguous ($matches stacks share that name across endpoints). Target by id instead:
$cand"
  fi
done

echo "About to redeploy (pull + recreate) [$(basename "$CONFIG_FILE")]: ${STACKS[*]}"
if [ "$ASSUME_YES" -ne 1 ]; then
  [ -t 0 ] || die "non-interactive shell — pass -y to confirm"
  read -r -p "Proceed? [y/N] " reply
  case "$reply" in [yY]|[yY][eE][sS]) ;; *) echo "aborted"; exit 1 ;; esac
fi

redeploy_one() {
  local stack="$1" row id eid env file body
  row="$(printf '%s' "$ALL_STACKS" | jq -c --arg t "$stack" "$SELECT")"
  id="$(printf '%s' "$row" | jq -r '.Id')"
  eid="$(printf '%s' "$row" | jq -r '.EndpointId')"
  env="$(printf '%s' "$row" | jq -c '.Env // []')"
  # Re-send the stack's current compose file unchanged; pullImage forces a fresh
  # pull of the (mutable :latest) image, prune=false keeps other resources.
  file="$(api_get "/api/stacks/$id/file" | jq -r '.StackFileContent')"
  body="$(jq -n --arg f "$file" --argjson e "$env" \
    '{stackFileContent:$f, env:$e, prune:false, pullImage:true}')"
  curl -fsS "${CURL_OPTS[@]}" -X PUT \
    -H "X-API-Key: $PORTAINER_TOKEN" -H "Content-Type: application/json" \
    -d "$body" "$PORTAINER_URL/api/stacks/$id?endpointId=$eid" >/dev/null
}

FAILED=()
for stack in "${STACKS[@]}"; do
  echo -n "→ $stack ... "
  if redeploy_one "$stack"; then echo "redeployed"; else echo "FAILED"; FAILED+=("$stack"); fi
done

[ "${#FAILED[@]}" -eq 0 ] || die "redeploy failed for: ${FAILED[*]}"
echo "done"
