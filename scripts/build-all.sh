#!/bin/bash
# Build base + app/broker/sidecar in one shot, under a single Docker lifecycle.
# On macOS: starts Docker Desktop if needed, then quits it when done (or on
# failure) — so Docker isn't left idling on battery after the build.
#
# Usage: ./scripts/build-all.sh [version] [local|staging|prod]
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib-docker.sh"

VERSION=${1:-latest}
ENV=${2:-local}

# Manage Docker here for the whole run so the sub-scripts don't start/stop it
# between base and push.
export DOCKER_MANAGED_EXTERNALLY=1
ensure_docker
trap cleanup_docker EXIT

"$SCRIPT_DIR/build-base.sh" "$VERSION" "$ENV"
"$SCRIPT_DIR/build-push.sh" "$VERSION" "$ENV"

echo "==========================================="
echo "✓ All images built: ${VERSION} / ${ENV}"
echo "==========================================="
