#!/bin/bash
# Helper script run as root from the update command
# Usage: /usr/local/bin/zeniclaw-update [github-token]
# Supports both Podman and Docker runtimes.
set -e

# Auto-detect repo path dynamically
REPO=""
for CANDIDATE in /opt/zeniclaw-repo /opt/zeniclaw /home/zeniclaw /var/www/html; do
    [ -d "$CANDIDATE/.git" ] && REPO="$CANDIDATE" && break
done
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
    git -c "url.https://x-access-token:${TOKEN}@github.com/.insteadOf=https://github.com/" pull origin main
else
    git pull origin main
fi

# Clean untracked files that may have been generated locally (e.g. by auto-improve)
git clean -fd --exclude=.env --exclude=storage/ --exclude=node_modules/ 2>/dev/null || true
git checkout -- . 2>/dev/null || true

# Read version from Dockerfile
VERSION=$(grep -oP 'echo "\K[^"]+(?=" > /tmp/\.zeniclaw-version)' Dockerfile 2>/dev/null || \
          grep -oP 'echo "\K[^"]+(?=" > storage/app/version\.txt)' Dockerfile 2>/dev/null || \
          echo "unknown")
echo "VERSION=$VERSION"

# Detect container runtime CLI and whether the server is Podman
CONTAINER_CMD=""
SERVER_IS_PODMAN=false
if command -v podman &>/dev/null && podman info &>/dev/null 2>&1; then
    CONTAINER_CMD="podman"
    SERVER_IS_PODMAN=true
elif command -v docker &>/dev/null; then
    CONTAINER_CMD="docker"
    if docker version 2>/dev/null | grep -qi "podman"; then
        SERVER_IS_PODMAN=true
    fi
fi

if [ -z "$CONTAINER_CMD" ]; then
    echo "ERROR: No container runtime found"
    exit 1
fi

# Resolve the host path of the repo
HOST_REPO="${HOST_REPO_PATH:-}"
if [ -z "$HOST_REPO" ]; then
    HOST_REPO=$($CONTAINER_CMD inspect zeniclaw_app --format "{{ range .Mounts }}{{ if eq .Destination \"${REPO}\" }}{{ .Source }}{{ end }}{{ end }}" 2>/dev/null || echo "")
fi
if [ -z "$HOST_REPO" ]; then
    HOST_REPO=$($CONTAINER_CMD inspect zeniclaw_app --format '{{ range .Mounts }}{{ if eq .Type "bind" }}{{ .Source }}{{"\n"}}{{ end }}{{ end }}' 2>/dev/null \
        | while read -r src; do
            [ -n "$src" ] && [ -f "$src/docker-compose.yml" ] && echo "$src" && break
        done || true)
fi
if [ -z "$HOST_REPO" ]; then
    echo "ERROR: Cannot resolve host repo path from container mounts"
    exit 1
fi

# Resolve the HOST path of the container socket
HOST_SOCKET_PATH=$($CONTAINER_CMD inspect zeniclaw_app --format '{{ range .Mounts }}{{ if eq .Destination "/var/run/docker.sock" }}{{ .Source }}{{ end }}{{ end }}' 2>/dev/null || echo "")
if [ -z "$HOST_SOCKET_PATH" ]; then
    if [ -S "/var/run/docker.sock" ]; then
        HOST_SOCKET_PATH="/var/run/docker.sock"
    elif [ -S "/run/podman/podman.sock" ]; then
        HOST_SOCKET_PATH="/run/podman/podman.sock"
    fi
fi

LOG="$REPO/storage/app/update-rebuild.log"
> "$LOG"

# Build and restart strategy depends on the container runtime.
# The build MUST run in background so the HTTP request returns quickly.

if [ "$SERVER_IS_PODMAN" = "true" ]; then
    # Podman (even via Docker CLI): build image in background, then signal completion.
    # The app container stays running during the build — no downtime.
    (
        echo 'Started rebuild...' > "$LOG"
        echo 'Building new image...' >> "$LOG"
        if $CONTAINER_CMD build -t zeniclaw_app -f "$REPO/Dockerfile" "$REPO" >> "$LOG" 2>&1; then
            echo 'Successfully built app' >> "$LOG"
            echo 'IMAGE_BUILT' >> "$LOG"
            echo "Run on host to apply: cd ${HOST_REPO} && podman compose up -d --force-recreate app" >> "$LOG"
            echo 'Started' >> "$LOG"
        else
            echo 'ERROR: rebuild failed' >> "$LOG"
        fi
    ) &
    disown
    echo "REBUILD_STARTED"

else
    # Native Docker: use docker:cli container (runs on host, survives app restart)
    docker run --rm -d \
        --name zeniclaw_updater \
        -v "${HOST_SOCKET_PATH}:/var/run/docker.sock" \
        -v "${HOST_REPO}:${HOST_REPO}" \
        -w "${HOST_REPO}" \
        -v "${HOST_REPO}/storage/app/update-rebuild.log:/tmp/rebuild.log" \
        docker:cli sh -c "\
            echo 'Started rebuild...' > /tmp/rebuild.log && \
            docker builder prune -f >> /tmp/rebuild.log 2>&1; \
            echo 'Removing orphan containers...' >> /tmp/rebuild.log && \
            docker container prune -f >> /tmp/rebuild.log 2>&1; \
            docker compose up -d --build --force-recreate app >> /tmp/rebuild.log 2>&1 && \
            echo 'Successfully built app' >> /tmp/rebuild.log && \
            echo 'Starting all services (including new ones)...' >> /tmp/rebuild.log && \
            docker compose up -d --remove-orphans >> /tmp/rebuild.log 2>&1 && \
            echo 'Started' >> /tmp/rebuild.log \
            || { echo 'ERROR: rebuild failed' >> /tmp/rebuild.log; \
                 echo 'Ensuring app container is running...' >> /tmp/rebuild.log; \
                 docker container prune -f >> /tmp/rebuild.log 2>&1; \
                 docker compose up -d app >> /tmp/rebuild.log 2>&1; }"

    echo "REBUILD_STARTED"
fi
