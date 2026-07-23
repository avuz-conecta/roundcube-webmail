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
if printf '%s' "$cmd" | grep -qE "$prod_pattern"; then
  emit deny "Touches production (deploy.prod.env, endpoint 5, or build-push prod). Blocked for agents - run prod deploys yourself, outside the agent."
fi

# ---- Self-escalation ALWAYS prompts, even for staging. ----
# The read-only allowlist (echo/printf/awk/find/sed -n) plus a redirect could
# rewrite this hook or the permission rules that invoke it. Checked BEFORE the
# staging allow so nothing auto-approves a change to the guardrails.
escalation_pattern='>[[:space:]]*[^[:space:];&|]*\.claude/'
escalation_pattern+='|(tee|cp|ln|mv)[[:space:]]+[^;&|]*\.claude/'
escalation_pattern+='|find[[:space:]]+[^;&|]*(-delete|-exec|-execdir|-ok)'
escalation_pattern+='|awk[[:space:]]+[^;&|]*(print|printf)[^;&|]*>'
escalation_pattern+='|awk[[:space:]]+[^;&|]*(system\(|close\()'
escalation_pattern+='|sed[[:space:]]+[^;&|]*[^a-zA-Z]w[[:space:]]+[^[:space:]]'
if printf '%s' "$cmd" | grep -qE "$escalation_pattern"; then
  emit ask "Could modify the agent's own guardrails or delete files - explicit approval required."
fi

# ---- Tier 2: STAGING infra + read-only ops -> allow (no prompt). ----
# Prod is already denied above, so anything reaching here is non-prod. These are
# the staging build/deploy/inspect commands the plan's verification step runs
# dozens of times. Staging is recreatable, so auto-approving mutation there is
# acceptable per the operator's explicit instruction.
staging_pattern='scripts/portainer-exec\.sh|scripts/logs\.sh'
staging_pattern+='|build-push\.(sh)?[[:space:]]+[^;&|]*[[:space:]]staging([[:space:]]|$)'
staging_pattern+='|scripts/deploy\.sh[[:space:]]+[^;&|]*avuz-mail-roundcube-2'
if printf '%s' "$cmd" | grep -qE "$staging_pattern"; then
  emit allow "Staging-only build/deploy/inspect (non-prod, recreatable)."
fi

# ---- Tier 3: everything else that publishes or mutates -> ask. ----
gate_pattern='scripts/deploy\.sh|build-base\.sh|build-all\.sh'
gate_pattern+='|git[[:space:]]+push|docker[[:space:]]+(exec|run|rm|push|kill|stop|start|restart|cp)'
gate_pattern+='|docker[[:space:]]+compose[[:space:]]+(.*[[:space:]])?(up|down|restart|stop|start)([[:space:]]|$)'
gate_pattern+='|docker[[:space:]]+buildx|gh[[:space:]]+(pr|release|api[[:space:]]+.*-X[[:space:]]*(POST|PUT|PATCH|DELETE))'
gate_pattern+='|(^|[;&|[:space:]])(sudo|rm|mv|chown|tee|dd)[[:space:]]'
gate_pattern+='|curl[[:space:]].*(-o|-O|--output)[[:space:]]'
gate_pattern+='|>[[:space:]]*/(etc|usr|bin|sbin|var|opt|Library|System)/'
if printf '%s' "$cmd" | grep -qE "$gate_pattern"; then
  emit ask "Reaches deployed infrastructure, publishes, or mutates a container - explicit approval required."
fi

exit 0
