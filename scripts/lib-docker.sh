#!/bin/bash
# Docker lifecycle helpers for the build scripts.
# macOS: auto-launch Docker Desktop for a build and quit it afterward — but only
# if THIS run started it (never kills a Docker you were already using).
# Linux: just require the daemon to be up (don't manage a server's Docker).

DOCKER_STARTED_BY_ME=0

ensure_docker() {
  if docker info >/dev/null 2>&1; then
    return 0
  fi

  if [ "$(uname -s)" != "Darwin" ]; then
    echo "✗ Docker daemon is not running. Start it and retry." >&2
    exit 1
  fi

  echo "Docker not running — launching Docker Desktop..."
  open -a Docker
  DOCKER_STARTED_BY_ME=1

  printf "Waiting for Docker to be ready"
  local waited=0
  until docker info >/dev/null 2>&1; do
    waited=$((waited + 2))
    if [ "$waited" -gt 180 ]; then
      echo ""; echo "✗ Docker did not become ready within 180s." >&2
      exit 1
    fi
    printf "."
    sleep 2
  done
  echo " ready."
}

cleanup_docker() {
  if [ "$DOCKER_STARTED_BY_ME" != "1" ] || [ "$(uname -s)" != "Darwin" ]; then
    return 0
  fi
  echo "Shutting down Docker Desktop (started by this build)..."
  # Prefer the official CLI (Docker Desktop 4.37+); fall back for older versions.
  docker desktop stop >/dev/null 2>&1 \
    || osascript -e 'quit app "Docker Desktop"' >/dev/null 2>&1 \
    || osascript -e 'quit app "Docker"' >/dev/null 2>&1 \
    || killall "Docker Desktop" >/dev/null 2>&1 \
    || killall Docker >/dev/null 2>&1 \
    || true
}
