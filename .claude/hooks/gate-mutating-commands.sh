#!/usr/bin/env bash
# PreToolUse/Bash gate: force a permission prompt for anything that reaches
# deployed infrastructure or publishes, regardless of how it's spelled.
#
# Permission rules match a command by prefix, so they miss env-var prefixes
# (PORTAINER_ENV_FILE=... ./scripts/x.sh), pipelines, and `sh -c` wrappers.
# This inspects the whole command string instead.
#
# Emits nothing when the command is safe — a silent hook means "no opinion",
# and normal permission rules apply.
set -uo pipefail

cmd=$(jq -r '.tool_input.command // ""' 2>/dev/null) || exit 0

# Anything that talks to Portainer, deploys, publishes an image, pushes commits,
# or mutates a container. `docker run` is included: it executes arbitrary images.
gate_pattern='portainer-exec|scripts/logs\.sh|scripts/deploy\.sh|build-push\.sh|build-base\.sh|build-all\.sh'
gate_pattern+='|git[[:space:]]+push|docker[[:space:]]+(exec|run|rm|push|kill|stop|start|restart|cp)'
# Flags may sit between `compose` and the subcommand: docker compose -f x.yml up -d
gate_pattern+='|docker[[:space:]]+compose[[:space:]]+(.*[[:space:]])?(up|down|restart|stop|start)([[:space:]]|$)'
gate_pattern+='|docker[[:space:]]+buildx|gh[[:space:]]+(pr|release|api[[:space:]]+.*-X[[:space:]]*(POST|PUT|PATCH|DELETE))'

if printf '%s' "$cmd" | grep -qE "$gate_pattern"; then
  printf '%s' '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask","permissionDecisionReason":"Reaches deployed infrastructure, publishes, or mutates a container - explicit approval required."}}'
fi

exit 0
