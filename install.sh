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

# Docker command — set after daemon check (docker or sudo docker)
DOCKER_CMD="docker"
DOCKER_COMPOSE_STANDALONE=false

set_docker_cmd() {
    if docker info &>/dev/null; then
        DOCKER_CMD="docker"
    elif sudo docker info &>/dev/null; then
        DOCKER_CMD="sudo docker"
        warn "Using 'sudo docker' (add your user to the docker group to avoid sudo)"
    fi
}

# Wrapper: runs "docker compose ..." or "docker-compose ..." with correct sudo
dcompose() {
    if [[ "$DOCKER_COMPOSE_STANDALONE" == "true" ]]; then
        if [[ "$DOCKER_CMD" == "sudo docker" ]]; then
            sudo docker-compose "$@"
        else
            docker-compose "$@"
        fi
    else
        $DOCKER_CMD compose "$@"
    fi
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

# --- Package installer helper ------------------------------------------------

detect_pkg_manager() {
    if check_command apt-get;  then echo "apt"; return; fi
    if check_command dnf;      then echo "dnf"; return; fi
    if check_command yum;      then echo "yum"; return; fi
    if check_command pacman;   then echo "pacman"; return; fi
    if check_command apk;      then echo "apk"; return; fi
    echo "unknown"
}

install_package() {
    local pkg="$1"
    local mgr
    mgr=$(detect_pkg_manager)

    info "Installing ${pkg} via ${mgr}..."
    case "$mgr" in
        apt)    sudo apt-get update -qq && sudo apt-get install -y -qq "$pkg" ;;
        dnf)    sudo dnf install -y -q "$pkg" ;;
        yum)    sudo yum install -y -q "$pkg" ;;
        pacman) sudo pacman -Sy --noconfirm "$pkg" ;;
        apk)    sudo apk add --quiet "$pkg" ;;
        *)
            error "Unknown package manager. Please install '${pkg}' manually."
            return 1
            ;;
    esac
}

offer_install() {
    local binary="$1"
    local package="${2:-$1}"
    local required="${3:-true}"

    if check_command "$binary"; then
        success "${binary} found ($(${binary} --version 2>&1 | head -1))"
        return 0
    fi

    warn "${binary} is not installed."
    echo -ne "${PURPLE}🔹 Install ${binary} now? ${DIM}[Y/n]${NC}: "
    read -r answer
    if [[ "${answer,,}" != "n" ]]; then
        if install_package "$package"; then
            if check_command "$binary"; then
                success "${binary} installed successfully!"
                return 0
            fi
        fi
        error "Failed to install ${binary}."
        if [[ "$required" == "true" ]]; then
            error "${binary} is required. Aborting."
            exit 1
        fi
        return 1
    else
        if [[ "$required" == "true" ]]; then
            error "${binary} is required. Aborting."
            exit 1
        fi
        warn "Skipping ${binary} (optional)."
        return 1
    fi
}

# --- Docker installer -------------------------------------------------------

install_docker() {
    warn "Docker is not installed."
    echo -ne "${PURPLE}🔹 Install Docker now? ${DIM}[Y/n]${NC}: "
    read -r answer
    if [[ "${answer,,}" == "n" ]]; then
        error "Docker is required. Aborting."
        exit 1
    fi

    if ! check_command curl; then
        info "curl is needed to install Docker. Installing curl first..."
        install_package curl || { error "Cannot install curl. Please install Docker manually."; exit 1; }
    fi

    info "Installing Docker via official script (https://get.docker.com)..."
    echo ""
    if curl -fsSL https://get.docker.com | sudo sh; then
        echo ""
        if check_command docker; then
            success "Docker installed successfully!"
            # Add current user to docker group if not root
            if [[ $EUID -ne 0 ]]; then
                sudo usermod -aG docker "$USER" 2>/dev/null || true
                warn "You were added to the 'docker' group. You may need to log out/in for it to take effect."
            fi
        else
            error "Docker installation failed. Please install manually: https://docs.docker.com/engine/install/"
            exit 1
        fi
    else
        echo ""
        error "Docker installation script failed. Please install manually: https://docs.docker.com/engine/install/"
        exit 1
    fi
}

# --- Docker daemon check ----------------------------------------------------

docker_is_running() {
    # Try without sudo first, then with sudo (user may not be in docker group yet)
    docker info &>/dev/null || sudo docker info &>/dev/null
}

ensure_docker_running() {
    if docker_is_running; then
        success "Docker daemon is running"
        set_docker_cmd
        return 0
    fi

    warn "Docker daemon is not running."
    echo -ne "${PURPLE}🔹 Start Docker daemon now? ${DIM}[Y/n]${NC}: "
    read -r answer
    if [[ "${answer,,}" == "n" ]]; then
        error "Docker daemon must be running. Aborting."
        exit 1
    fi

    # Try systemctl first (systemd) — show errors so user sees what's wrong
    if check_command systemctl; then
        info "Starting Docker via systemctl..."
        if sudo systemctl start docker; then
            sleep 2
            if docker_is_running; then
                success "Docker daemon started (systemctl)"
                sudo systemctl enable docker 2>/dev/null || true
                info "Docker enabled on boot"
                set_docker_cmd
                return 0
            fi
        else
            warn "systemctl start docker failed (see above)"
        fi
    fi

    # Try service (SysVinit / upstart)
    if check_command service; then
        info "Starting Docker via service..."
        if sudo service docker start; then
            sleep 2
            if docker_is_running; then
                success "Docker daemon started (service)"
                set_docker_cmd
                return 0
            fi
        else
            warn "service docker start failed (see above)"
        fi
    fi

    # Try starting dockerd directly as last resort
    if check_command dockerd; then
        info "Starting dockerd directly (last resort)..."
        sudo dockerd > /tmp/dockerd.log 2>&1 &
        local dockerd_pid=$!
        # Wait up to 10 seconds for daemon to be ready
        local wait=0
        while [[ $wait -lt 10 ]]; do
            if docker_is_running; then
                success "Docker daemon started (dockerd, PID $dockerd_pid)"
                set_docker_cmd
                return 0
            fi
            sleep 1
            wait=$((wait + 1))
        done
        warn "dockerd started but daemon not responding. Log:"
        echo -e "    ${DIM}$(tail -5 /tmp/dockerd.log 2>/dev/null)${NC}"
        sudo kill "$dockerd_pid" 2>/dev/null || true
    fi

    echo ""
    error "Could not start Docker daemon."
    echo -e "    ${DIM}Try manually in another terminal:${NC}"
    echo -e "    ${DIM}  sudo systemctl start docker${NC}"
    echo -e "    ${DIM}  sudo service docker start${NC}"
    echo -e "    ${DIM}  sudo dockerd${NC}"
    echo -e ""
    echo -e "    ${DIM}Then re-run: ./install.sh${NC}"
    exit 1
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

    # Check required binaries
    info "Checking required tools..."
    offer_install "curl"    "curl"    "true"
    offer_install "openssl" "openssl" "true"
    offer_install "git"     "git"     "true"

    # Check Node.js (needed for Claude Code CLI inside container and on host)
    info "Checking Node.js..."
    if check_command node; then
        local node_ver
        node_ver=$(node --version 2>/dev/null || echo "unknown")
        success "Node.js found ($node_ver)"
    else
        warn "Node.js is not installed."
        echo -ne "${PURPLE}🔹 Install Node.js 20 now? ${DIM}[Y/n]${NC}: "
        read -r answer
        if [[ "${answer,,}" != "n" ]]; then
            info "Installing Node.js 20 via nodesource..."
            curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
            sudo apt-get install -y nodejs
            if check_command node; then
                success "Node.js installed ($(node --version))"
            else
                warn "Node.js installation failed. Claude Code CLI will not be available on host."
            fi
        else
            warn "Skipping Node.js (Claude Code CLI will not be available on host)."
        fi
    fi

    # Check Claude Code CLI
    info "Checking Claude Code CLI..."
    if check_command claude; then
        success "Claude Code CLI found ($(claude --version 2>/dev/null | head -1))"
    elif check_command node; then
        echo -ne "${PURPLE}🔹 Install Claude Code CLI globally? ${DIM}[Y/n]${NC}: "
        read -r answer
        if [[ "${answer,,}" != "n" ]]; then
            info "Installing @anthropic-ai/claude-code..."
            sudo npm install -g @anthropic-ai/claude-code 2>&1 | tail -3
            if check_command claude; then
                success "Claude Code CLI installed"
            else
                warn "Claude Code CLI installation failed. You can install it later: sudo npm i -g @anthropic-ai/claude-code"
            fi
        else
            warn "Skipping Claude Code CLI (optional, install later with: sudo npm i -g @anthropic-ai/claude-code)"
        fi
    else
        warn "Skipping Claude Code CLI (Node.js not available)"
    fi

    # Check Docker
    info "Checking Docker..."
    if ! check_command docker; then
        install_docker
    else
        success "Docker found ($(docker --version | head -1))"
    fi

    # Check Docker daemon is running
    info "Checking Docker daemon..."
    ensure_docker_running

    # Check Docker Compose
    info "Checking Docker Compose..."
    if $DOCKER_CMD compose version &>/dev/null; then
        success "Docker Compose found ($($DOCKER_CMD compose version --short 2>/dev/null || echo 'v2+'))"
    elif check_command docker-compose; then
        warn "Found docker-compose v1 (Python) which is incompatible with newer Docker engines."
        warn "This causes 'KeyError: ContainerConfig' errors. Upgrading to v2..."
        # Remove old v1 if installed via apt
        if dpkg -l docker-compose &>/dev/null 2>&1; then
            info "Removing old docker-compose v1 package..."
            sudo apt-get remove -y docker-compose &>/dev/null || true
        fi
        # Install v2 plugin
        info "Installing Docker Compose v2 plugin..."
        sudo mkdir -p /usr/local/lib/docker/cli-plugins
        local compose_url="https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)"
        if sudo curl -fsSL "$compose_url" -o /usr/local/lib/docker/cli-plugins/docker-compose && \
           sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose; then
            if $DOCKER_CMD compose version &>/dev/null; then
                success "Docker Compose v2 installed ($($DOCKER_CMD compose version --short 2>/dev/null))"
            else
                error "Docker Compose v2 installation failed. Please install manually."
                echo -e "    ${DIM}See: https://docs.docker.com/compose/install/${NC}"
                exit 1
            fi
        else
            error "Download failed. Please install Docker Compose v2 manually."
            echo -e "    ${DIM}See: https://docs.docker.com/compose/install/${NC}"
            exit 1
        fi
    else
        error "Docker Compose not found."
        echo -ne "${PURPLE}🔹 Install Docker Compose plugin now? ${DIM}[Y/n]${NC}: "
        read -r answer
        if [[ "${answer,,}" != "n" ]]; then
            info "Installing Docker Compose plugin..."
            sudo mkdir -p /usr/local/lib/docker/cli-plugins
            local compose_url="https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)"
            if sudo curl -fsSL "$compose_url" -o /usr/local/lib/docker/cli-plugins/docker-compose && \
               sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose; then
                if $DOCKER_CMD compose version &>/dev/null; then
                    success "Docker Compose plugin installed ($($DOCKER_CMD compose version --short 2>/dev/null))"
                else
                    error "Docker Compose installation failed. Please install manually."
                    echo -e "    ${DIM}See: https://docs.docker.com/compose/install/${NC}"
                    exit 1
                fi
            else
                error "Download failed. Please install Docker Compose manually."
                echo -e "    ${DIM}See: https://docs.docker.com/compose/install/${NC}"
                exit 1
            fi
        else
            error "Docker Compose is required. Aborting."
            exit 1
        fi
    fi

    # Check ports
    local APP_PORT_CHECK="${CONF_APP_PORT:-8080}"
    info "Checking port availability..."

    if ! check_port "$APP_PORT_CHECK"; then
        warn "Port $APP_PORT_CHECK is already in use. You can choose a different port in the next step."
    else
        success "Port $APP_PORT_CHECK is available"
    fi

    if ! check_port 3000; then
        warn "Port 3000 (WAHA/WhatsApp) is already in use."
    else
        success "Port 3000 is available (WAHA/WhatsApp)"
    fi

    echo ""
    success "All pre-flight checks passed!"
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
    if ! dcompose build 2>&1 | while IFS= read -r line; do
        echo -e "    ${DIM}${line}${NC}"
    done; then
        error "Docker build failed. Check the output above for details."
        exit 1
    fi
    echo ""
    success "Docker images built successfully"

    # Start
    info "Starting services..."
    if ! dcompose up -d 2>&1; then
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
        db_health=$($DOCKER_CMD inspect --format='{{.State.Health.Status}}' zeniclaw_db 2>/dev/null || echo "starting")
        redis_health=$($DOCKER_CMD inspect --format='{{.State.Health.Status}}' zeniclaw_redis 2>/dev/null || echo "starting")
        app_health=$($DOCKER_CMD inspect --format='{{.State.Status}}' zeniclaw_app 2>/dev/null || echo "starting")

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
        if dcompose exec -T app php artisan --version &>/dev/null; then
            break
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done

    # The entrypoint.sh already runs migrations, but we run seed explicitly
    info "Running database seeder..."
    if dcompose exec -T app php artisan db:seed --force --no-interaction 2>&1 | while IFS= read -r line; do
        echo -e "    ${DIM}${line}${NC}"
    done; then
        success "Database seeded successfully"
    else
        warn "Seeding returned a non-zero exit code (may be OK if already seeded)"
    fi

    # Setup Claude Code CLI token inside the container if Anthropic key is configured
    if [[ -n "${CONF_ANTHROPIC_KEY:-}" ]]; then
        info "Setting up Claude Code CLI token inside container..."
        if dcompose exec -T app bash -c "echo '$CONF_ANTHROPIC_KEY' | claude setup-token 2>/dev/null" &>/dev/null; then
            success "Claude Code CLI token configured in container"
        else
            warn "Claude Code CLI token setup skipped (will use ANTHROPIC_API_KEY env var)"
        fi

        # Also set it up on host if claude is available
        if check_command claude; then
            info "Setting up Claude Code CLI token on host..."
            echo "$CONF_ANTHROPIC_KEY" | claude setup-token 2>/dev/null || true
            success "Claude Code CLI token configured on host"
        fi
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
    echo -e "  │  ${CYAN}Dashboard:${NC}    ${BOLD}${CONF_APP_URL}${NC}"
    echo -e "  │  ${CYAN}WhatsApp QR:${NC}  ${BOLD}http://localhost:3000${NC}"
    echo -e "  │"
    echo -e "  │  ${CYAN}Email:${NC}        admin@zeniclaw.io"
    echo -e "  │  ${CYAN}Password:${NC}     password"
    echo -e "  │"
    echo -e "  └──────────────────────────────────────────────────"

    echo ""
    echo -e "  ${YELLOW}${BOLD}Next steps:${NC}"
    echo -e "  ${YELLOW}  1. Open http://localhost:3000 and scan the WhatsApp QR code${NC}"
    echo -e "  ${YELLOW}  2. Log in to the dashboard and change the default password${NC}"
    echo ""

    # Show service status
    echo -e "  ${BOLD}Service Status:${NC}"
    echo -e "  ┌──────────────────────────────────────────────────"

    local services=("zeniclaw_app" "zeniclaw_db" "zeniclaw_redis" "zeniclaw_waha")
    local labels=("App      " "Database " "Redis    " "WhatsApp ")

    for i in "${!services[@]}"; do
        local status
        status=$($DOCKER_CMD inspect --format='{{.State.Status}}' "${services[$i]}" 2>/dev/null || echo "not found")
        local health
        health=$($DOCKER_CMD inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}n/a{{end}}' "${services[$i]}" 2>/dev/null || echo "n/a")

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

# --- Clone or Update Repository ----------------------------------------------

REPO_URL="https://gitlab.com/zenidev/zeniclaw.git"
INSTALL_DIR="${ZENICLAW_DIR:-/opt/zeniclaw}"
BRANCH="${ZENICLAW_BRANCH:-main}"

clone_or_update_repo() {
    step "0/6 — Downloading ZeniClaw"

    if [[ -d "$INSTALL_DIR/.git" ]]; then
        info "Repository already exists at $INSTALL_DIR, pulling latest..."
        git -C "$INSTALL_DIR" fetch origin
        git -C "$INSTALL_DIR" reset --hard "origin/$BRANCH"
        success "Repository updated"
    else
        info "Cloning $REPO_URL into $INSTALL_DIR..."
        sudo mkdir -p "$(dirname "$INSTALL_DIR")"
        sudo git clone --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
        sudo chown -R "$(id -u):$(id -g)" "$INSTALL_DIR"
        success "Repository cloned"
    fi

    cd "$INSTALL_DIR"
}

# --- Main Execution ----------------------------------------------------------

main() {
    # Detect if running from inside the repo or standalone
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

    show_banner

    if [[ -f "$SCRIPT_DIR/docker-compose.yml" ]]; then
        # Running from inside the repo — use current directory
        cd "$SCRIPT_DIR"
        info "Running from project directory: $SCRIPT_DIR"
    else
        # Running standalone (e.g. curl | bash) — clone the repo first
        preflight_checks
        clone_or_update_repo
    fi

    # At this point we're in the project directory
    if [[ ! -f "docker-compose.yml" ]]; then
        error "docker-compose.yml not found. Something went wrong."
        exit 1
    fi

    preflight_checks
    collect_configuration
    generate_env
    build_and_start
    initialize_database
    show_summary
}

# Run
main "$@"
