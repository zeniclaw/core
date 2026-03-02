#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# ZeniClaw — Interactive Installation Script
# ============================================================================

# --- Colors & Styles --------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m' # No Color

# --- Helper Functions -------------------------------------------------------

info()    { echo -e "${BLUE}ℹ️  $1${NC}"; }
success() { echo -e "${GREEN}✅ $1${NC}"; }
error()   { echo -e "${RED}❌ $1${NC}"; }
warn()    { echo -e "${YELLOW}⚠️  $1${NC}"; }
step()    { echo -e "\n${CYAN}${BOLD}── $1 ──${NC}\n"; }

ask() {
    local prompt="$1"
    local default="${2:-}"
    local var_name="$3"
    if [[ -n "$default" ]]; then
        echo -ne "${PURPLE}🔹 ${prompt} ${DIM}[${default}]${NC}: "
    else
        echo -ne "${PURPLE}🔹 ${prompt}${NC}: "
    fi
    read -r input
    eval "$var_name=\"${input:-$default}\""
}

ask_secret() {
    local prompt="$1"
    local default="${2:-}"
    local var_name="$3"
    if [[ -n "$default" ]]; then
        echo -ne "${PURPLE}🔒 ${prompt} ${DIM}[${default}]${NC}: "
    else
        echo -ne "${PURPLE}🔒 ${prompt}${NC}: "
    fi
    read -rs input
    echo ""
    eval "$var_name=\"${input:-$default}\""
}

ask_optional() {
    local prompt="$1"
    local var_name="$2"
    echo -ne "${PURPLE}🔹 ${prompt} ${DIM}(leave empty to skip)${NC}: "
    read -r input
    eval "$var_name=\"${input}\""
}

spinner() {
    local pid=$1
    local msg="${2:-Working...}"
    local chars="⣾⣽⣻⢿⡿⣟⣯⣷"
    local i=0
    while kill -0 "$pid" 2>/dev/null; do
        echo -ne "\r${BLUE}  ${chars:i++%${#chars}:1} ${msg}${NC}"
        sleep 0.1
    done
    echo -ne "\r\033[K"
}

check_command() {
    command -v "$1" &>/dev/null
}

generate_password() {
    openssl rand -base64 24 2>/dev/null | tr -d '/+=' | head -c 24 || \
    head -c 32 /dev/urandom | base64 | tr -d '/+=' | head -c 24
}

generate_app_key() {
    echo "base64:$(openssl rand -base64 32)"
}

check_port() {
    local port=$1
    if ss -tlnp 2>/dev/null | grep -q ":${port} " || \
       netstat -tlnp 2>/dev/null | grep -q ":${port} "; then
        return 1
    fi
    return 0
}

# --- Banner -----------------------------------------------------------------

show_banner() {
    echo -e "${CYAN}${BOLD}"
    cat << 'BANNER'

    ╔═══════════════════════════════════════════════════════╗
    ║                                                       ║
    ║   ███████╗███████╗███╗   ██╗██╗ ██████╗██╗      █║
    ║   ╚══███╔╝██╔════╝████╗  ██║██║██╔════╝██║     ██║   ║
    ║     ███╔╝ █████╗  ██╔██╗ ██║██║██║     ██║    ███║   ║
    ║    ███╔╝  ██╔══╝  ██║╚██╗██║██║██║     ██║   ████║   ║
    ║   ███████╗███████╗██║ ╚████║██║╚██████╗█████╗█████║  ║
    ║   ╚══════╝╚══════╝╚═╝  ╚═══╝╚═╝ ╚═════╝╚════╝╚════╝  ║
    ║                                                       ║
    ║          AI-Powered WhatsApp CRM Platform             ║
    ║                                                       ║
    ╚═══════════════════════════════════════════════════════╝

BANNER
    echo -e "${NC}"
    echo -e "    ${DIM}Interactive Installation Assistant${NC}"
    echo -e "    ${DIM}──────────────────────────────────${NC}\n"
}

# --- Pre-flight Checks ------------------------------------------------------

preflight_checks() {
    step "1/6 — Pre-flight Checks"

    # Check OS
    info "Checking operating system..."
    if [[ "$(uname -s)" != "Linux" ]]; then
        error "This script requires Linux. Detected: $(uname -s)"
        exit 1
    fi
    success "Linux detected ($(uname -r))"

    # Check Docker
    info "Checking Docker..."
    if ! check_command docker; then
        warn "Docker is not installed."
        echo -ne "${PURPLE}🔹 Install Docker now? ${DIM}[Y/n]${NC}: "
        read -r install_docker
        if [[ "${install_docker,,}" != "n" ]]; then
            info "Installing Docker via official script..."
            curl -fsSL https://get.docker.com | sh &
            spinner $! "Installing Docker..."
            wait $!
            if check_command docker; then
                success "Docker installed successfully!"
                # Add current user to docker group if not root
                if [[ $EUID -ne 0 ]]; then
                    sudo usermod -aG docker "$USER" 2>/dev/null || true
                    warn "You may need to log out and back in for Docker permissions to take effect."
                fi
            else
                error "Docker installation failed. Please install manually: https://docs.docker.com/engine/install/"
                exit 1
            fi
        else
            error "Docker is required. Aborting."
            exit 1
        fi
    else
        success "Docker found ($(docker --version | head -1))"
    fi

    # Check Docker is running
    if ! docker info &>/dev/null; then
        warn "Docker daemon is not running."
        info "Attempting to start Docker..."
        sudo systemctl start docker 2>/dev/null || sudo service docker start 2>/dev/null || true
        sleep 2
        if ! docker info &>/dev/null; then
            error "Could not start Docker daemon. Please start it manually and re-run this script."
            exit 1
        fi
        success "Docker daemon started."
    fi

    # Check Docker Compose
    info "Checking Docker Compose..."
    if docker compose version &>/dev/null; then
        success "Docker Compose found ($(docker compose version --short 2>/dev/null || echo 'v2+'))"
    elif check_command docker-compose; then
        success "Docker Compose (standalone) found ($(docker-compose --version | head -1))"
        warn "Consider upgrading to Docker Compose v2 (docker compose plugin)."
        # Create alias for this script's session
        docker() {
            if [[ "$1" == "compose" ]]; then
                shift
                command docker-compose "$@"
            else
                command docker "$@"
            fi
        }
    else
        error "Docker Compose not found. Please install the Docker Compose plugin."
        echo -e "    ${DIM}See: https://docs.docker.com/compose/install/${NC}"
        exit 1
    fi

    # Check ports
    local APP_PORT_CHECK="${CONF_APP_PORT:-8080}"
    info "Checking port availability..."

    if ! check_port "$APP_PORT_CHECK"; then
        warn "Port $APP_PORT_CHECK is already in use."
    else
        success "Port $APP_PORT_CHECK is available"
    fi

    if ! check_port 3000; then
        warn "Port 3000 (WAHA/WhatsApp) is already in use."
    else
        success "Port 3000 is available (WAHA/WhatsApp)"
    fi
}

# --- Interactive Configuration -----------------------------------------------

collect_configuration() {
    step "2/6 — Configuration"

    echo -e "  ${DIM}Configure your ZeniClaw instance. Press Enter to accept defaults.${NC}\n"

    # App Port
    ask "Application port" "8080" CONF_APP_PORT

    # App URL
    local default_url="http://localhost:${CONF_APP_PORT}"
    ask "Application URL (full domain or localhost)" "$default_url" CONF_APP_URL

    # Database password
    local generated_pw
    generated_pw=$(generate_password)
    ask_secret "Database password (Enter for random)" "$generated_pw" CONF_DB_PASSWORD

    # LLM API Keys
    echo ""
    info "Optional: Configure AI/LLM API keys (can also be set later in Settings)"
    ask_optional "Anthropic API key (sk-ant-...)" CONF_ANTHROPIC_KEY
    ask_optional "OpenAI API key (sk-...)" CONF_OPENAI_KEY

    # Summary
    echo ""
    echo -e "  ${BOLD}Configuration Summary:${NC}"
    echo -e "  ┌──────────────────────────────────────────────────"
    echo -e "  │ ${CYAN}URL:${NC}       $CONF_APP_URL"
    echo -e "  │ ${CYAN}Port:${NC}      $CONF_APP_PORT"
    echo -e "  │ ${CYAN}DB Pass:${NC}   ${DIM}$(echo "$CONF_DB_PASSWORD" | head -c 4)****${NC}"
    [[ -n "$CONF_ANTHROPIC_KEY" ]] && echo -e "  │ ${CYAN}Anthropic:${NC} ${DIM}configured${NC}"
    [[ -n "$CONF_OPENAI_KEY" ]]    && echo -e "  │ ${CYAN}OpenAI:${NC}    ${DIM}configured${NC}"
    echo -e "  └──────────────────────────────────────────────────"
    echo ""

    echo -ne "${PURPLE}🔹 Proceed with this configuration? ${DIM}[Y/n]${NC}: "
    read -r confirm
    if [[ "${confirm,,}" == "n" ]]; then
        warn "Installation cancelled by user."
        exit 0
    fi
}

# --- Generate .env -----------------------------------------------------------

generate_env() {
    step "3/6 — Generating Configuration"

    local ENV_FILE=".env"
    local APP_KEY

    # Back up existing .env
    if [[ -f "$ENV_FILE" ]]; then
        local backup=".env.backup.$(date +%Y%m%d_%H%M%S)"
        cp "$ENV_FILE" "$backup"
        warn "Existing .env backed up to $backup"
    fi

    # Generate APP_KEY
    info "Generating secure application key..."
    APP_KEY=$(generate_app_key)
    success "Application key generated"

    # Write .env
    info "Writing .env file..."
    cat > "$ENV_FILE" << ENVEOF
# ============================================================================
# ZeniClaw — Environment Configuration
# Generated by install.sh on $(date -u '+%Y-%m-%d %H:%M:%S UTC')
# ============================================================================

APP_NAME=ZeniClaw
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=${CONF_APP_URL}
APP_PORT=${CONF_APP_PORT}

LOG_CHANNEL=stderr
LOG_LEVEL=info

# --- Database (PostgreSQL) ---
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=zeniclaw
DB_USERNAME=zeniclaw
DB_PASSWORD=${CONF_DB_PASSWORD}

# --- Cache & Sessions (Redis) ---
REDIS_HOST=redis
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# --- WAHA WhatsApp ---
WAHA_URL=http://waha:3000
ENVEOF

    # Append optional LLM keys
    if [[ -n "$CONF_ANTHROPIC_KEY" ]] || [[ -n "$CONF_OPENAI_KEY" ]]; then
        echo "" >> "$ENV_FILE"
        echo "# --- LLM API Keys ---" >> "$ENV_FILE"
        [[ -n "$CONF_ANTHROPIC_KEY" ]] && echo "ANTHROPIC_API_KEY=${CONF_ANTHROPIC_KEY}" >> "$ENV_FILE"
        [[ -n "$CONF_OPENAI_KEY" ]]    && echo "OPENAI_API_KEY=${CONF_OPENAI_KEY}" >> "$ENV_FILE"
    fi

    success ".env file generated"
}

# --- Build & Start -----------------------------------------------------------

build_and_start() {
    step "4/6 — Building & Starting Services"

    # Build
    info "Building Docker images (this may take a few minutes on first run)..."
    echo ""
    if ! docker compose build 2>&1 | while IFS= read -r line; do
        echo -e "    ${DIM}${line}${NC}"
    done; then
        error "Docker build failed. Check the output above for details."
        exit 1
    fi
    echo ""
    success "Docker images built successfully"

    # Start
    info "Starting services..."
    if ! docker compose up -d 2>&1; then
        error "Failed to start services."
        exit 1
    fi
    success "Services started"

    # Wait for healthy
    info "Waiting for services to become healthy..."
    echo ""

    local max_wait=120
    local elapsed=0
    local all_healthy=false

    while [[ $elapsed -lt $max_wait ]]; do
        local db_health app_health redis_health
        db_health=$(docker inspect --format='{{.State.Health.Status}}' zeniclaw_db 2>/dev/null || echo "starting")
        redis_health=$(docker inspect --format='{{.State.Health.Status}}' zeniclaw_redis 2>/dev/null || echo "starting")
        app_health=$(docker inspect --format='{{.State.Status}}' zeniclaw_app 2>/dev/null || echo "starting")

        local status_line="    "
        [[ "$db_health" == "healthy" ]]  && status_line+="${GREEN}● DB${NC}  "    || status_line+="${YELLOW}○ DB${NC}  "
        [[ "$redis_health" == "healthy" ]] && status_line+="${GREEN}● Redis${NC}  " || status_line+="${YELLOW}○ Redis${NC}  "
        [[ "$app_health" == "running" ]]  && status_line+="${GREEN}● App${NC}  "    || status_line+="${YELLOW}○ App${NC}  "

        echo -ne "\r${status_line} ${DIM}(${elapsed}s)${NC}  "

        if [[ "$db_health" == "healthy" && "$redis_health" == "healthy" && "$app_health" == "running" ]]; then
            all_healthy=true
            break
        fi

        sleep 2
        elapsed=$((elapsed + 2))
    done

    echo ""
    echo ""

    if $all_healthy; then
        success "All services are running!"
    else
        warn "Some services may still be starting up. Continuing..."
    fi
}

# --- Database Initialization -------------------------------------------------

initialize_database() {
    step "5/6 — Database Initialization"

    # Wait a moment for the app container to finish its entrypoint
    info "Waiting for application to finish initializing..."
    local max_wait=60
    local elapsed=0

    while [[ $elapsed -lt $max_wait ]]; do
        # Check if migrations have completed by testing if artisan responds
        if docker compose exec -T app php artisan --version &>/dev/null; then
            break
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done

    # The entrypoint.sh already runs migrations, but we run seed explicitly
    info "Running database seeder..."
    if docker compose exec -T app php artisan db:seed --force --no-interaction 2>&1 | while IFS= read -r line; do
        echo -e "    ${DIM}${line}${NC}"
    done; then
        success "Database seeded successfully"
    else
        warn "Seeding returned a non-zero exit code (may be OK if already seeded)"
    fi
}

# --- Final Summary -----------------------------------------------------------

show_summary() {
    step "6/6 — Installation Complete!"

    echo -e "${GREEN}${BOLD}"
    cat << 'DONE'
    ╔═══════════════════════════════════════════════════╗
    ║                                                   ║
    ║      🎉  ZeniClaw is up and running!  🎉         ║
    ║                                                   ║
    ╚═══════════════════════════════════════════════════╝
DONE
    echo -e "${NC}"

    echo -e "  ${BOLD}Access your instance:${NC}"
    echo -e "  ┌──────────────────────────────────────────────────"
    echo -e "  │"
    echo -e "  │  ${CYAN}URL:${NC}       ${BOLD}${CONF_APP_URL}${NC}"
    echo -e "  │"
    echo -e "  │  ${CYAN}Email:${NC}     admin@zeniclaw.io"
    echo -e "  │  ${CYAN}Password:${NC}  password"
    echo -e "  │"
    echo -e "  └──────────────────────────────────────────────────"

    echo ""
    echo -e "  ${YELLOW}${BOLD}⚠️  IMPORTANT: Change the default password after your first login!${NC}"
    echo ""

    # Show service status
    echo -e "  ${BOLD}Service Status:${NC}"
    echo -e "  ┌──────────────────────────────────────────────────"

    local services=("zeniclaw_app" "zeniclaw_db" "zeniclaw_redis" "zeniclaw_waha")
    local labels=("App     " "Database" "Redis   " "WAHA    ")

    for i in "${!services[@]}"; do
        local status
        status=$(docker inspect --format='{{.State.Status}}' "${services[$i]}" 2>/dev/null || echo "not found")
        local health
        health=$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}n/a{{end}}' "${services[$i]}" 2>/dev/null || echo "n/a")

        if [[ "$status" == "running" ]]; then
            echo -e "  │  ${GREEN}●${NC} ${labels[$i]}  ${DIM}running${NC} ${DIM}(${health})${NC}"
        else
            echo -e "  │  ${RED}●${NC} ${labels[$i]}  ${DIM}${status}${NC}"
        fi
    done

    echo -e "  └──────────────────────────────────────────────────"

    echo ""
    echo -e "  ${BOLD}Useful commands:${NC}"
    echo -e "  ${DIM}  docker compose logs -f        ${NC}# View live logs"
    echo -e "  ${DIM}  docker compose ps              ${NC}# Service status"
    echo -e "  ${DIM}  docker compose down            ${NC}# Stop all services"
    echo -e "  ${DIM}  docker compose up -d           ${NC}# Restart services"
    echo ""
}

# --- Main Execution ----------------------------------------------------------

main() {
    # Ensure we're in the project directory
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    cd "$SCRIPT_DIR"

    # Check docker-compose.yml exists
    if [[ ! -f "docker-compose.yml" ]]; then
        error "docker-compose.yml not found in $SCRIPT_DIR"
        error "Please run this script from the ZeniClaw project root."
        exit 1
    fi

    show_banner
    preflight_checks
    collect_configuration
    generate_env
    build_and_start
    initialize_database
    show_summary
}

# Run
main "$@"
