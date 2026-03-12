#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# ZeniClaw — Host-side Update Script
# Run this from the project directory to update from ANY version.
# Supports both Podman and Docker.
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

# --- Detect container runtime (Podman preferred, Docker fallback) -----------
CONTAINER_CMD=""
COMPOSE=""

detect_runtime() {
    export PATH="$PATH:/usr/local/bin:/usr/bin:/usr/libexec/docker/cli-plugins"
    CONTAINER_CMD=""
    COMPOSE=""

    if command -v podman &>/dev/null && podman info &>/dev/null 2>&1; then
        CONTAINER_CMD="podman"
        # podman-compose (standalone Python package)
        if command -v podman-compose &>/dev/null; then
            COMPOSE="podman-compose"
        # podman compose (built-in since podman 4.7+)
        elif podman compose version &>/dev/null 2>&1; then
            COMPOSE="podman compose"
        fi
    elif command -v docker &>/dev/null; then
        CONTAINER_CMD="docker"
        if docker compose version &>/dev/null 2>&1; then
            COMPOSE="docker compose"
        elif command -v docker-compose &>/dev/null; then
            COMPOSE="docker-compose"
        elif [ -x /usr/libexec/docker/cli-plugins/docker-compose ]; then
            COMPOSE="docker compose"
        fi
    fi
}

detect_runtime

if [[ -z "$CONTAINER_CMD" ]]; then
    error "No container runtime found (podman or docker). Install: https://docs.docker.com/engine/install/"
fi
if [[ -z "$COMPOSE" ]]; then
    warn "No compose command found. Attempting auto-install..."
    if [[ "$CONTAINER_CMD" == "podman" ]]; then
        # Try pip install podman-compose, then apt
        if command -v pip3 &>/dev/null; then
            pip3 install podman-compose 2>/dev/null && detect_runtime
        fi
        if [[ -z "$COMPOSE" ]]; then
            apt-get update -qq 2>/dev/null && apt-get install -y -qq podman-compose 2>/dev/null && detect_runtime
        fi
    else
        apt-get update -qq 2>/dev/null && apt-get install -y -qq docker-compose-plugin 2>/dev/null && detect_runtime
    fi
    if [[ -z "$COMPOSE" ]]; then
        if [[ "$CONTAINER_CMD" == "podman" ]]; then
            error "No compose found. Install: pip3 install podman-compose (or apt install podman-compose)"
        else
            error "No compose found. Install: apt install docker-compose-plugin"
        fi
    fi
fi

echo -e "\n${BOLD}${CYAN}=== ZeniClaw Update ===${NC}"
echo -e "${DIM}Runtime: $CONTAINER_CMD | Compose: $COMPOSE${NC}\n"

# 1. Git pull
info "Pulling latest code from GitLab..."

# Try to get token from the running app container
TOKEN=""
if $CONTAINER_CMD inspect zeniclaw_app &>/dev/null; then
    TOKEN=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('gitlab_access_token');" 2>/dev/null || true)
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

# Sync proxy settings from app DB into .env (for Ollama downloads)
info "Syncing proxy config..."
PROXY_HTTP=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('proxy_http') ?? '';" 2>/dev/null || true)
PROXY_HTTPS=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('proxy_https') ?? '';" 2>/dev/null || true)
PROXY_NO=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('proxy_no_proxy') ?? '';" 2>/dev/null || true)

if [ -n "$PROXY_HTTP" ] || [ -n "$PROXY_HTTPS" ]; then
    # Update .env file with proxy (create if needed, update if exists)
    touch .env
    for VAR_NAME in HTTP_PROXY HTTPS_PROXY NO_PROXY; do
        sed -i "/^${VAR_NAME}=/d" .env
    done
    [ -n "$PROXY_HTTP" ]  && echo "HTTP_PROXY=${PROXY_HTTP}" >> .env
    [ -n "$PROXY_HTTPS" ] && echo "HTTPS_PROXY=${PROXY_HTTPS}" >> .env
    [ -n "$PROXY_NO" ]    && echo "NO_PROXY=${PROXY_NO}" >> .env || echo "NO_PROXY=localhost,127.0.0.1,db,redis,waha,ollama,app" >> .env
    success "Proxy synced to .env (HTTP_PROXY=${PROXY_HTTP:-none}, HTTPS_PROXY=${PROXY_HTTPS:-none})"
else
    info "No proxy configured in Settings, skipping"
fi

# Start any new services added in this update (e.g. ollama)
info "Starting all services (including new ones)..."
$COMPOSE up -d 2>&1
success "All services up"

# 4. Run migrations
info "Running migrations..."
sleep 3  # wait for container to be ready
$CONTAINER_CMD exec zeniclaw_app php artisan migrate --force --no-interaction 2>&1 || warn "Migrations skipped"

# 5. Clear caches
info "Clearing caches..."
$CONTAINER_CMD exec zeniclaw_app php artisan config:cache 2>/dev/null || true
$CONTAINER_CMD exec zeniclaw_app php artisan route:cache 2>/dev/null || true
$CONTAINER_CMD exec zeniclaw_app php artisan view:cache 2>/dev/null || true

echo -e "\n${GREEN}${BOLD}=== Update complete: v${VERSION} ===${NC}\n"
