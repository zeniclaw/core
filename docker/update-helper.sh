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

# Detect docker compose command (v2 plugin or standalone)
if docker compose version &>/dev/null; then
    COMPOSE_CMD="docker compose"
elif command -v docker-compose &>/dev/null; then
    COMPOSE_CMD="docker-compose"
else
    echo "ERROR: No docker compose found"
    exit 1
fi

# Docker rebuild in background (survives container restart)
nohup bash -c "cd $REPO && $COMPOSE_CMD build app 2>&1 && $COMPOSE_CMD up -d app 2>&1" > "$REPO/storage/app/update-rebuild.log" 2>&1 &

echo "REBUILD_STARTED"
