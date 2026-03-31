#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# ZeniClaw — Start Script
# Lance tous les services (app, db, redis, waha)
# Supporte Podman (prefere) et Docker
# Usage: ./start.sh [--ollama]
# ============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

info()    { echo -e "${CYAN}>> $1${NC}"; }
success() { echo -e "${GREEN}OK $1${NC}"; }
error()   { echo -e "${RED}!! $1${NC}"; exit 1; }
warn()    { echo -e "${YELLOW}-- $1${NC}"; }

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_DIR"

# --- Parse arguments --------------------------------------------------------
PROFILES=""
for arg in "$@"; do
    case "$arg" in
        --ollama) PROFILES="--profile ollama" ;;
        *) warn "Option inconnue: $arg" ;;
    esac
done

# --- Pre-flight checks ------------------------------------------------------
if [ ! -f .env ]; then
    error "Fichier .env manquant. Lance d'abord: ./install.sh"
fi

# --- Detect container runtime ------------------------------------------------
CONTAINER_CMD=""
COMPOSE=""

detect_runtime() {
    export PATH="$PATH:/usr/local/bin:/usr/bin:/usr/libexec/docker/cli-plugins"

    if command -v podman &>/dev/null && podman info &>/dev/null 2>&1; then
        CONTAINER_CMD="podman"
        if command -v podman-compose &>/dev/null; then
            COMPOSE="podman-compose"
        elif podman compose version &>/dev/null 2>&1; then
            COMPOSE="podman compose"
        fi
    elif command -v docker &>/dev/null && docker info &>/dev/null 2>&1; then
        CONTAINER_CMD="docker"
        if docker compose version &>/dev/null 2>&1; then
            COMPOSE="docker compose"
        fi
    fi

    if [ -z "$CONTAINER_CMD" ]; then
        error "Aucun runtime conteneur trouve (podman ou docker requis)"
    fi

    if [ -z "$COMPOSE" ]; then
        error "Aucun outil compose trouve (podman-compose ou docker compose requis)"
    fi
}

detect_runtime
info "Runtime: ${BOLD}$CONTAINER_CMD${NC} | Compose: ${BOLD}$COMPOSE${NC}"

# --- Build & Start -----------------------------------------------------------
echo ""
info "Construction des images..."
$COMPOSE -f docker-compose.yml build $PROFILES

echo ""
info "Demarrage des services..."
$COMPOSE -f docker-compose.yml up -d $PROFILES

# --- Wait for health ---------------------------------------------------------
echo ""
info "Attente du demarrage des services..."

wait_for_container() {
    local name="$1"
    local max_wait=60
    local elapsed=0

    # Check if the container exists
    if ! $CONTAINER_CMD ps --format '{{.Names}}' 2>/dev/null | grep -q "^${name}$"; then
        return 0
    fi

    while [ $elapsed -lt $max_wait ]; do
        local health
        health=$($CONTAINER_CMD inspect --format '{{.State.Health.Status}}' "$name" 2>/dev/null || echo "none")
        case "$health" in
            healthy) return 0 ;;
            none)    return 0 ;;  # No healthcheck defined
            *)       sleep 2; elapsed=$((elapsed + 2)) ;;
        esac
    done
    warn "$name n'est pas encore healthy apres ${max_wait}s"
    return 1
}

wait_for_container "zeniclaw_db"
wait_for_container "zeniclaw_redis"
wait_for_container "zeniclaw_app"
wait_for_container "zeniclaw_waha"

# --- Status ------------------------------------------------------------------
echo ""
echo -e "${BOLD}${GREEN}=== ZeniClaw demarre ===${NC}"
echo ""
$COMPOSE -f docker-compose.yml ps
echo ""

APP_PORT=$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2 || echo "8080")
APP_PORT="${APP_PORT:-8080}"
CHAT_PORT=$(grep -E '^CHAT_PORT=' .env 2>/dev/null | cut -d= -f2 || echo "8888")
CHAT_PORT="${CHAT_PORT:-8888}"

echo -e "  ${CYAN}App:${NC}       http://localhost:${APP_PORT}"
echo -e "  ${CYAN}Chat:${NC}      http://localhost:${CHAT_PORT}"
echo -e "  ${CYAN}WhatsApp:${NC}  http://localhost:3000"
echo ""
echo -e "  ${CYAN}Logs:${NC}      $COMPOSE -f docker-compose.yml logs -f"
echo -e "  ${CYAN}Stop:${NC}      $COMPOSE -f docker-compose.yml down"
echo ""
