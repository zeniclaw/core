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

LOG="$REPO/storage/app/update-rebuild.log"
APP_LOG="/var/www/html/storage/app/update-rebuild.log"

# Docker rebuild in background (survives container restart)
# Remove old container by ID to avoid name conflict, then build and recreate
# Write to both repo dir (host) and app dir (container readable)
nohup bash -c "cd $REPO && docker rm -f \$(docker ps -aq --filter name=zeniclaw_app) 2>/dev/null; $COMPOSE_CMD build app 2>&1 | tee $APP_LOG && $COMPOSE_CMD up -d --force-recreate app 2>&1 | tee -a $APP_LOG" > "$LOG" 2>&1 &

echo "REBUILD_STARTED"
