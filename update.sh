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

# --- Proxy detection: .env file > environment > ask user ----------------------

# URL-encode a string (for proxy user/pass with special chars)
urlencode() {
    local string="$1"
    local length=${#string}
    local encoded=""
    for (( i = 0; i < length; i++ )); do
        local c="${string:$i:1}"
        case "$c" in
            [a-zA-Z0-9.~_-]) encoded+="$c" ;;
            *) encoded+=$(printf '%%%02X' "'$c") ;;
        esac
    done
    echo "$encoded"
}

# Strip credentials from proxy URL to get base URL
proxy_base_url() {
    echo "$1" | sed 's|://[^@]*@|://|'
}

PROXY_HTTP=""
PROXY_HTTPS=""
PROXY_NO="${NO_PROXY:-}"
PROXY_BASE=""

# Load base URL from .env (stored WITHOUT credentials)
if [ -f .env ]; then
    PROXY_BASE=$(grep -oP '^PROXY_BASE_URL=\K.*' .env 2>/dev/null || true)
    [ -z "$PROXY_NO" ] && PROXY_NO=$(grep -oP '^NO_PROXY=\K.*' .env 2>/dev/null || true)
fi

# Load from app DB
if [ -z "$PROXY_BASE" ] && $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
    DB_HTTP=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('proxy_http') ?? '';" 2>/dev/null || true)
    [ -n "$DB_HTTP" ] && PROXY_BASE=$(proxy_base_url "$DB_HTTP")
    DB_NO=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('proxy_no_proxy') ?? '';" 2>/dev/null || true)
    [ -n "$DB_NO" ] && PROXY_NO="$DB_NO"
fi

# Load from environment (strip creds)
if [ -z "$PROXY_BASE" ] && [ -n "${HTTP_PROXY:-}" ]; then
    PROXY_BASE=$(proxy_base_url "$HTTP_PROXY")
fi

# Ask user for proxy URL if not found
if [ -z "$PROXY_BASE" ]; then
    echo ""
    echo -e "${YELLOW}Etes-vous derriere un proxy ? (pour telecharger les images Docker)${NC}"
    echo -e "${DIM}Exemple: http://proxy.entreprise.com:8080${NC}"
    read -rp "Proxy URL (vide = pas de proxy) : " PROXY_BASE
fi

# Always ask for credentials (never stored in .env for security)
if [ -n "$PROXY_BASE" ]; then
    echo ""
    echo -e "${CYAN}Proxy: $PROXY_BASE${NC}"
    echo -e "${YELLOW}Login proxy (vide = pas d'authentification) :${NC}"
    read -rp "Login : " PROXY_USER
    if [ -n "$PROXY_USER" ]; then
        read -rsp "Mot de passe : " PROXY_PASS
        echo ""
        ENC_USER=$(urlencode "$PROXY_USER")
        ENC_PASS=$(urlencode "$PROXY_PASS")
        PROXY_HTTP=$(echo "$PROXY_BASE" | sed "s|://|://${ENC_USER}:${ENC_PASS}@|")
        PROXY_HTTPS="$PROXY_HTTP"
        success "Proxy avec authentification configure"
    else
        PROXY_HTTP="$PROXY_BASE"
        PROXY_HTTPS="$PROXY_BASE"
        info "Proxy sans authentification"
    fi

    [ -z "$PROXY_NO" ] && PROXY_NO="localhost,127.0.0.1,db,redis,waha,ollama,app"
fi

# Persist proxy to .env and export for this session
BUILD_ARGS=""
if [ -n "$PROXY_BASE" ]; then
    touch .env
    for VAR_NAME in HTTP_PROXY HTTPS_PROXY NO_PROXY PROXY_BASE_URL; do
        sed -i "/^${VAR_NAME}=/d" .env
    done
    echo "PROXY_BASE_URL=${PROXY_BASE}" >> .env
    echo "HTTP_PROXY=${PROXY_HTTP}" >> .env
    echo "HTTPS_PROXY=${PROXY_HTTPS}" >> .env
    echo "NO_PROXY=${PROXY_NO}" >> .env

    export HTTP_PROXY="$PROXY_HTTP" HTTPS_PROXY="$PROXY_HTTPS" NO_PROXY="$PROXY_NO"
    export http_proxy="$PROXY_HTTP" https_proxy="$PROXY_HTTPS" no_proxy="$PROXY_NO"
    BUILD_ARGS="--build-arg HTTP_PROXY=$PROXY_HTTP --build-arg HTTPS_PROXY=$PROXY_HTTPS --build-arg NO_PROXY=$PROXY_NO"
    success "Proxy: $PROXY_BASE"
else
    info "Pas de proxy"
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

# Clean untracked files that may have been generated locally (e.g. by auto-improve)
# This prevents rogue files from breaking the app after update
info "Cleaning untracked files..."
git clean -fd --exclude=.env --exclude=storage/ --exclude=node_modules/ 2>/dev/null || true
git checkout -- . 2>/dev/null || true
success "Code updated and cleaned"

# 2. Read new version (supports both old and new version location in Dockerfile)
VERSION=$(grep -oP 'echo "\K[^"]+(?=" > /tmp/\.zeniclaw-version)' Dockerfile 2>/dev/null || \
          grep -oP 'echo "\K[^"]+(?=" > storage/app/version\.txt)' Dockerfile 2>/dev/null || \
          echo "unknown")
info "New version: v${VERSION}"

# 3. Clean up old images/build cache to free disk space
info "Cleaning up old images and build cache..."
$CONTAINER_CMD image prune -f 2>/dev/null || true
$CONTAINER_CMD builder prune -f 2>/dev/null || true
DISK_FREE=$(df -h / | awk 'NR==2 {print $4}')
info "Disk free: ${DISK_FREE}"

# 4. Rebuild and restart
info "Rebuilding app container..."
$COMPOSE build $BUILD_ARGS app 2>&1 | tail -5
success "Image built"

info "Restarting app container..."
$COMPOSE up -d --force-recreate app 2>&1
success "Container restarted"

# Sync proxy back to app DB (in case user entered it manually above)
if [ -n "$PROXY_HTTP" ] || [ -n "$PROXY_HTTPS" ]; then
    $CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
        App\Models\AppSetting::set('proxy_http', '${PROXY_HTTP}');
        App\Models\AppSetting::set('proxy_https', '${PROXY_HTTPS}');
        App\Models\AppSetting::set('proxy_no_proxy', '${PROXY_NO:-localhost,127.0.0.1,db,redis,waha,ollama,app}');
        echo 'Proxy saved to DB';
    " 2>/dev/null || true
fi

# Start any new services added in this update (e.g. ollama)
info "Starting all services (including new ones)..."
$COMPOSE up -d 2>&1
success "All services up"

# 5. Run migrations
info "Running migrations..."
sleep 3  # wait for container to be ready
$CONTAINER_CMD exec zeniclaw_app php artisan migrate --force --no-interaction 2>&1 || warn "Migrations skipped"

# 6. Clear caches
info "Clearing caches..."
$CONTAINER_CMD exec zeniclaw_app php artisan config:cache 2>/dev/null || true
$CONTAINER_CMD exec zeniclaw_app php artisan route:cache 2>/dev/null || true
$CONTAINER_CMD exec zeniclaw_app php artisan view:cache 2>/dev/null || true

echo -e "\n${GREEN}${BOLD}=== Update complete: v${VERSION} ===${NC}\n"
