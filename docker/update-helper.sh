#!/bin/bash
# Helper script run as root from the update command
# Usage: /usr/local/bin/zeniclaw-update [gitlab-token]
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

# Read version from Dockerfile
VERSION=$(grep -oP 'echo "\K[^"]+(?=" > storage/app/version\.txt)' Dockerfile || echo "unknown")
echo "VERSION=$VERSION"

# Resolve the host path of the repo (docker inspect the mount)
HOST_REPO=$(docker inspect zeniclaw_app --format '{{ range .Mounts }}{{ if eq .Destination "/opt/zeniclaw-repo" }}{{ .Source }}{{ end }}{{ end }}' 2>/dev/null || echo "")
if [ -z "$HOST_REPO" ]; then
    echo "ERROR: Cannot resolve host repo path"
    exit 1
fi

LOG="$REPO/storage/app/update-rebuild.log"
> "$LOG"

# Spawn an independent Docker container to rebuild the app.
# This container runs on the host's Docker daemon and survives the
# app container being removed/recreated during the rebuild.
# IMPORTANT: We do NOT remove the old container before building.
# docker compose up --build will only replace it after a successful build.
# If the build fails, the old container keeps running.
docker run --rm -d \
    --name zeniclaw_updater \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "${HOST_REPO}:${HOST_REPO}" \
    -w "${HOST_REPO}" \
    -v "${HOST_REPO}/storage/app/update-rebuild.log:/tmp/rebuild.log" \
    docker:cli sh -c "\
        echo 'Started rebuild...' > /tmp/rebuild.log && \
        docker builder prune -f >> /tmp/rebuild.log 2>&1; \
        docker compose up -d --build --force-recreate app >> /tmp/rebuild.log 2>&1 && \
        echo 'Successfully built app' >> /tmp/rebuild.log && \
        echo 'Started' >> /tmp/rebuild.log \
        || { echo 'ERROR: rebuild failed' >> /tmp/rebuild.log; \
             echo 'Ensuring app container is running...' >> /tmp/rebuild.log; \
             docker compose up -d app >> /tmp/rebuild.log 2>&1; }"

echo "REBUILD_STARTED"
