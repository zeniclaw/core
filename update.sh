#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# ZeniClaw — Host-side Update Script
# Run this from the project directory to update from ANY version.
# Usage: ./update.sh
# ============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
DIM='\033[2m'
BOLD='\033[1m'
NC='\033[0m'

info()    { echo -e "${CYAN}>> $1${NC}"; }
success() { echo -e "${GREEN}OK $1${NC}"; }
error()   { echo -e "${RED}!! $1${NC}"; exit 1; }
warn()    { echo -e "${YELLOW}-- $1${NC}"; }

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_DIR"

# Detect docker compose command
if docker compose version &>/dev/null; then
    COMPOSE="docker compose"
elif command -v docker-compose &>/dev/null; then
    COMPOSE="docker-compose"
else
    error "Docker Compose not found"
fi

echo -e "\n${BOLD}${CYAN}=== ZeniClaw Update ===${NC}\n"

# 1. Git pull
info "Pulling latest code from GitLab..."

# Try to get token from the running app container
TOKEN=""
if docker inspect zeniclaw_app &>/dev/null; then
    TOKEN=$(docker exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('gitlab_access_token');" 2>/dev/null || true)
fi

if [ -n "$TOKEN" ]; then
    git -c "url.https://oauth2:${TOKEN}@gitlab.com/.insteadOf=https://gitlab.com/" pull origin main
else
    git pull origin main
fi
success "Code updated"

# 2. Read new version
VERSION=$(grep -oP 'echo "\K[^"]+(?=" > storage/app/version\.txt)' Dockerfile || echo "unknown")
info "New version: v${VERSION}"

# 3. Rebuild and restart
info "Rebuilding app container..."
$COMPOSE build app 2>&1 | tail -5
success "Image built"

info "Restarting app container..."
$COMPOSE up -d --force-recreate app 2>&1
success "Container restarted"

# 4. Run migrations
info "Running migrations..."
sleep 3  # wait for container to be ready
docker exec zeniclaw_app php artisan migrate --force --no-interaction 2>&1 || warn "Migrations skipped"

# 5. Clear caches
info "Clearing caches..."
docker exec zeniclaw_app php artisan config:cache 2>/dev/null || true
docker exec zeniclaw_app php artisan route:cache 2>/dev/null || true
docker exec zeniclaw_app php artisan view:cache 2>/dev/null || true

echo -e "\n${GREEN}${BOLD}=== Update complete: v${VERSION} ===${NC}\n"
