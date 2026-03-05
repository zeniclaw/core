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

# Docker rebuild in background (survives container restart)
nohup bash -c "cd $REPO && docker-compose build app 2>&1 && docker-compose up -d app 2>&1" > "$REPO/storage/app/update-rebuild.log" 2>&1 &

echo "REBUILD_STARTED"
