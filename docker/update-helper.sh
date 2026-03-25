#!/bin/bash
# Helper script run as root from the update command
# Usage: /usr/local/bin/zeniclaw-update [gitlab-token]
# Supports both Podman and Docker runtimes.
set -e

# Auto-detect repo path dynamically
# This script is installed at /usr/local/bin/zeniclaw-update but the repo
# is bind-mounted somewhere. Use find to locate the .git directory.
REPO=""
for CANDIDATE in /opt/zeniclaw-repo /opt/zeniclaw /home/zeniclaw /var/www/html; do
    [ -d "$CANDIDATE/.git" ] && REPO="$CANDIDATE" && break
done
# Last resort: search common mount points
if [ -z "$REPO" ]; then
    REPO=$(find /opt /home /srv -maxdepth 2 -name ".git" -type d 2>/dev/null | head -1 | sed 's|/.git$||' || true)
fi
if [ -z "$REPO" ]; then
    echo "ERROR: Cannot find ZeniClaw repo (.git directory not found)"
    exit 1
fi

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

# Read version from Dockerfile (supports both old and new version location)
VERSION=$(grep -oP 'echo "\K[^"]+(?=" > /tmp/\.zeniclaw-version)' Dockerfile 2>/dev/null || \
          grep -oP 'echo "\K[^"]+(?=" > storage/app/version\.txt)' Dockerfile 2>/dev/null || \
          echo "unknown")
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

# Resolve the host path of the repo from container bind mounts
# Try the detected $REPO first, then scan all bind mounts for one containing docker-compose.yml
HOST_REPO=$($CONTAINER_CMD inspect zeniclaw_app --format "{{ range .Mounts }}{{ if eq .Destination \"${REPO}\" }}{{ .Source }}{{ end }}{{ end }}" 2>/dev/null || echo "")
if [ -z "$HOST_REPO" ]; then
    # Fallback: find any bind mount whose Source contains a docker-compose.yml
    HOST_REPO=$($CONTAINER_CMD inspect zeniclaw_app --format '{{ range .Mounts }}{{ if eq .Type "bind" }}{{ .Source }}{{"\n"}}{{ end }}{{ end }}' 2>/dev/null \
        | while read -r src; do
            [ -n "$src" ] && [ -f "$src/docker-compose.yml" ] && echo "$src" && break
        done || true)
fi
if [ -z "$HOST_REPO" ]; then
    echo "ERROR: Cannot resolve host repo path from container mounts"
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
