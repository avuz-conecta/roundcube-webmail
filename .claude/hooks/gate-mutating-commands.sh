#!/usr/bin/env bash
# PreToolUse/Bash gate. Three tiers, checked in order:
#
#   1. PROD  -> deny   (hard block, all permission modes, including bypass)
#   2. STAGING infra + read-only Portainer -> allow (no prompt)
#   3. Other publish/mutate/self-escalation -> ask (prompt)
#
# Permission rules match a command by prefix, so they miss env-var prefixes
# (PORTAINER_ENV_FILE=... ./scripts/x.sh), pipelines, and `sh -c` wrappers.
# This inspects the whole command string instead.
set -uo pipefail

emit() {
  printf '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"%s","permissionDecisionReason":"%s"}}' "$1" "$2"
  exit 0
}

# Fails OPEN (exit 0, no opinion) if jq is missing or stdin is malformed, so a
# broken environment cannot block every Bash call. Normal permission rules still
# apply then. The prod deny below is a guardrail, not the only control: prod
# credentials live only in deploy.prod.env and are never present in an agent's
# command text.
cmd=$(jq -r '.tool_input.command // ""' 2>/dev/null) || exit 0

# ---- Tier 1: PROD is off-limits. Hard deny, regardless of permission mode. ----
# Reaching prod Portainer REQUIRES deploy.prod.env (its URL+token); a prod image
# push REQUIRES `build-push.sh <ver> prod`; endpoint 5 IS prod. Any of these is a
# production action and must never run from inside the agent.
prod_pattern='deploy\.prod\.env|deploy-prod'
prod_pattern+='|PORTAINER_ENDPOINT=["'\''[:space:]]*0*5([^0-9]|$)'
prod_pattern+='|build-push\.(sh)?[[:space:]]+[^;&|]*[[:space:]]prod([[:space:]]|$)'
# A raw `docker push` of a prod image is a production action too: prod pulls
# :latest for these images from the registry, so overwriting a tag stages a prod
# change and can break the rollback anchor. Denied even though other docker verbs
# are allowed below.
prod_pattern+='|docker[[:space:]]+push[[:space:]]+[^;&|]*(avuz-roundcube|avuz-imapproxy|avuz-password-broker)'
if printf '%s' "$cmd" | grep -qE "$prod_pattern"; then
  emit deny "Production action (prod env/endpoint, prod image push). Blocked for agents - run prod deploys yourself, outside the agent."
fi

# ---- Always prompts, even amid the allowed dev commands below. ----
# Two classes that are NOT "reversible dev commands":
#  - self-escalation: a redirect could rewrite this hook or the permission rules
#    that invoke it (checked before any allow, so nothing auto-approves a change
#    to the guardrails);
#  - host root / disk destroyers: sudo (host privilege escalation) and dd (raw
#    device writes) are not git-recoverable and are never part of this workflow.
escalation_pattern='>[[:space:]]*[^[:space:];&|]*\.claude/'
escalation_pattern+='|(tee|cp|ln|mv)[[:space:]]+[^;&|]*\.claude/'
escalation_pattern+='|find[[:space:]]+[^;&|]*(-delete|-exec|-execdir|-ok)'
escalation_pattern+='|awk[[:space:]]+[^;&|]*(print|printf)[^;&|]*>'
escalation_pattern+='|awk[[:space:]]+[^;&|]*(system\(|close\()'
escalation_pattern+='|sed[[:space:]]+[^;&|]*[^a-zA-Z]w[[:space:]]+[^[:space:]]'
escalation_pattern+='|(^|[;&|[:space:]])(sudo|dd)[[:space:]]'
if printf '%s' "$cmd" | grep -qE "$escalation_pattern"; then
  emit ask "Modifies guardrails, or is host-root / a disk destroyer (sudo/dd) - explicit approval required."
fi

# ---- Tier 2: STAGING infra + read-only ops -> allow (no prompt). ----
# Prod is already denied above, so anything reaching here is non-prod. These are
# the staging build/deploy/inspect commands the plan's verification step runs
# dozens of times. Staging is recreatable, so auto-approving mutation there is
# acceptable per the operator's explicit instruction.
staging_pattern='scripts/portainer-exec\.sh|scripts/logs\.sh'
staging_pattern+='|build-push\.(sh)?[[:space:]]+[^;&|]*[[:space:]]staging([[:space:]]|$)'
staging_pattern+='|scripts/deploy\.sh[[:space:]]+[^;&|]*avuz-mail-roundcube-2'
# Reversible dev-loop commands the operator asked to allow: git/gh publish (a
# push is force-reversible and does not reach prod infra), and local docker run/
# build/container lifecycle. Prod image pushes are denied in Tier 1 above, and
# sudo/dd are gated above, so those never reach here.
staging_pattern+='|git[[:space:]]+push|gh[[:space:]]+(pr|release)'
staging_pattern+='|docker[[:space:]]+(run|build|buildx|exec|create|start|stop|restart|kill|rm|cp|tag|logs|pull)'
staging_pattern+='|docker[[:space:]]+compose[[:space:]]+(.*[[:space:]])?(up|down|restart|stop|start|build|logs)([[:space:]]|$)'
if printf '%s' "$cmd" | grep -qE "$staging_pattern"; then
  emit allow "Staging/local, reversible (non-prod, git-recoverable or recreatable)."
fi

# ---- Tier 3: remaining publish/mutate that is neither clearly prod nor clearly
# reversible dev-loop -> ask. ----
gate_pattern='scripts/deploy\.sh|build-base\.sh|build-all\.sh'
gate_pattern+='|docker[[:space:]]+push'
gate_pattern+='|gh[[:space:]]+api[[:space:]]+.*-X[[:space:]]*(POST|PUT|PATCH|DELETE)'
gate_pattern+='|(^|[;&|[:space:]])(rm|mv|chown|tee)[[:space:]]'
gate_pattern+='|curl[[:space:]].*(-o|-O|--output)[[:space:]]'
gate_pattern+='|>[[:space:]]*/(etc|usr|bin|sbin|var|opt|Library|System)/'
if printf '%s' "$cmd" | grep -qE "$gate_pattern"; then
  emit ask "Publishes, mutates infra, or writes/deletes files - explicit approval required."
fi

exit 0
