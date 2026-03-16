#!/bin/bash
# Helper script run as root from the update command
# Usage: /usr/local/bin/zeniclaw-update [gitlab-token]
# Supports both Podman and Docker runtimes.
set -e

REPO="/opt/zeniclaw-repo"
TOKEN="${1:-}"

cd "$REPO"

# Git pull
if [ -n "$TOKEN" ]; then
    git -c "url.https://oauth2:${TOKEN}@gitlab.com/.insteadOf=https://gitlab.com/" pull origin main
else
    git pull origin main
fi

# Clean untracked files that may have been generated locally (e.g. by auto-improve)
git clean -fd --exclude=.env --exclude=storage/ --exclude=node_modules/ 2>/dev/null || true
git checkout -- . 2>/dev/null || true

# Read version from Dockerfile
VERSION=$(grep -oP 'echo "\K[^"]+(?=" > storage/app/version\.txt)' Dockerfile || echo "unknown")
echo "VERSION=$VERSION"

# Detect container runtime
CONTAINER_CMD=""
if command -v podman &>/dev/null && podman info &>/dev/null 2>&1; then
    CONTAINER_CMD="podman"
elif command -v docker &>/dev/null; then
    CONTAINER_CMD="docker"
fi

if [ -z "$CONTAINER_CMD" ]; then
    echo "ERROR: No container runtime found"
    exit 1
fi

# Resolve the host path of the repo
HOST_REPO=$($CONTAINER_CMD inspect zeniclaw_app --format '{{ range .Mounts }}{{ if eq .Destination "/opt/zeniclaw-repo" }}{{ .Source }}{{ end }}{{ end }}' 2>/dev/null || echo "")
if [ -z "$HOST_REPO" ]; then
    echo "ERROR: Cannot resolve host repo path"
    exit 1
fi

LOG="$REPO/storage/app/update-rebuild.log"
> "$LOG"

# Detect compose command
COMPOSE_CMD=""
if [ "$CONTAINER_CMD" = "podman" ]; then
    if podman compose version &>/dev/null 2>&1; then
        COMPOSE_CMD="podman compose"
    elif command -v podman-compose &>/dev/null; then
        COMPOSE_CMD="podman-compose"
    fi
else
    if docker compose version &>/dev/null 2>&1; then
        COMPOSE_CMD="docker compose"
    elif command -v docker-compose &>/dev/null; then
        COMPOSE_CMD="docker-compose"
    fi
fi

# Detect socket path
SOCKET_PATH=""
if [ -S "/run/podman/podman.sock" ]; then
    SOCKET_PATH="/run/podman/podman.sock"
elif [ -S "/var/run/docker.sock" ]; then
    SOCKET_PATH="/var/run/docker.sock"
fi

# Spawn an independent container to rebuild the app.
# This container runs on the host's container runtime and survives the
# app container being removed/recreated during the rebuild.
# IMPORTANT: We do NOT remove the old container before building.
# compose up --build will only replace it after a successful build.
# If the build fails, the old container keeps running.
if [ "$CONTAINER_CMD" = "podman" ]; then
    # Podman: run rebuild directly (no need for docker:cli image)
    (
        cd "${HOST_REPO}"
        echo 'Started rebuild...' > "$LOG"
        $COMPOSE_CMD up -d --build --force-recreate app >> "$LOG" 2>&1 && \
            echo 'Successfully built app' >> "$LOG" && \
            echo 'Starting all services (including new ones)...' >> "$LOG" && \
            $COMPOSE_CMD up -d >> "$LOG" 2>&1 && \
            echo 'Started' >> "$LOG" \
            || { echo 'ERROR: rebuild failed' >> "$LOG"; \
                 echo 'Ensuring app container is running...' >> "$LOG"; \
                 $COMPOSE_CMD up -d app >> "$LOG" 2>&1; }
    ) &
    disown
else
    # Docker: use docker:cli container
    docker run --rm -d \
        --name zeniclaw_updater \
        -v "${SOCKET_PATH}:/var/run/docker.sock" \
        -v "${HOST_REPO}:${HOST_REPO}" \
        -w "${HOST_REPO}" \
        -v "${HOST_REPO}/storage/app/update-rebuild.log:/tmp/rebuild.log" \
        docker:cli sh -c "\
            echo 'Started rebuild...' > /tmp/rebuild.log && \
            docker builder prune -f >> /tmp/rebuild.log 2>&1; \
            docker compose up -d --build --force-recreate app >> /tmp/rebuild.log 2>&1 && \
            echo 'Successfully built app' >> /tmp/rebuild.log && \
            echo 'Starting all services (including new ones)...' >> /tmp/rebuild.log && \
            docker compose up -d >> /tmp/rebuild.log 2>&1 && \
            echo 'Started' >> /tmp/rebuild.log \
            || { echo 'ERROR: rebuild failed' >> /tmp/rebuild.log; \
                 echo 'Ensuring app container is running...' >> /tmp/rebuild.log; \
                 docker compose up -d app >> /tmp/rebuild.log 2>&1; }"
fi

echo "REBUILD_STARTED"
