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

# Container runtime command — supports Podman and Docker
CONTAINER_CMD=""
COMPOSE_CMD=""

detect_runtime() {
    # Prefer podman, fallback to docker
    if command -v podman &>/dev/null && podman info &>/dev/null 2>&1; then
        CONTAINER_CMD="podman"
    elif command -v docker &>/dev/null; then
        if docker info &>/dev/null; then
            CONTAINER_CMD="docker"
        elif sudo docker info &>/dev/null; then
            CONTAINER_CMD="sudo docker"
            warn "Using 'sudo docker' (add your user to the docker group to avoid sudo)"
        fi
    fi

    # Detect compose command
    if [[ -n "$CONTAINER_CMD" ]]; then
        if [[ "$CONTAINER_CMD" == *"podman"* ]]; then
            if podman compose version &>/dev/null 2>&1; then
                COMPOSE_CMD="podman compose"
            elif command -v podman-compose &>/dev/null; then
                COMPOSE_CMD="podman-compose"
            fi
        else
            if $CONTAINER_CMD compose version &>/dev/null 2>&1; then
                COMPOSE_CMD="$CONTAINER_CMD compose"
            elif command -v docker-compose &>/dev/null; then
                if [[ "$CONTAINER_CMD" == "sudo docker" ]]; then
                    COMPOSE_CMD="sudo docker-compose"
                else
                    COMPOSE_CMD="docker-compose"
                fi
            fi
        fi
    fi
}

# Wrapper for compose commands
dcompose() {
    $COMPOSE_CMD "$@"
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
    # Use sudo -E to preserve proxy env vars (http_proxy, https_proxy, etc.)
    case "$mgr" in
        apt)    sudo -E apt-get update -qq && sudo -E apt-get install -y -qq "$pkg" ;;
        dnf)    sudo -E dnf install -y "$pkg" ;;
        yum)    sudo -E yum install -y "$pkg" ;;
        pacman) sudo -E pacman -Sy --noconfirm "$pkg" ;;
        apk)    sudo -E apk add --quiet "$pkg" ;;
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

# --- Container runtime installer (Podman or Docker) -------------------------

install_container_runtime() {
    warn "No container runtime found (Podman or Docker)."
    echo -ne "${PURPLE}🔹 Install Podman (recommended) or Docker? ${DIM}[podman/docker]${NC}: "
    read -r choice
    choice="${choice,,}"
    [[ -z "$choice" ]] && choice="podman"

    if ! check_command curl; then
        info "curl is needed for installation. Installing curl first..."
        install_package curl || { error "Cannot install curl."; exit 1; }
    fi

    if [[ "$choice" == "docker" ]]; then
        info "Installing Docker via official script (https://get.docker.com)..."
        echo ""
        if curl -fsSL https://get.docker.com | sudo -E sh; then
            if check_command docker; then
                success "Docker installed successfully!"
                if [[ $EUID -ne 0 ]]; then
                    sudo usermod -aG docker "$USER" 2>/dev/null || true
                    warn "You were added to the 'docker' group. You may need to log out/in."
                fi
            else
                error "Docker installation failed. Install manually: https://docs.docker.com/engine/install/"
                exit 1
            fi
        else
            error "Docker installation failed."
            exit 1
        fi
    else
        info "Installing Podman..."
        if install_package podman; then
            success "Podman installed!"
            # Install podman-compose
            if check_command pip3; then
                info "Installing podman-compose..."
                sudo -E pip3 install podman-compose 2>/dev/null || pip3 install --user podman-compose 2>/dev/null || true
            elif check_command pip; then
                sudo -E pip install podman-compose 2>/dev/null || true
            fi
            if ! command -v podman-compose &>/dev/null && ! podman compose version &>/dev/null 2>&1; then
                warn "podman-compose not available. Install it: pip3 install podman-compose"
            fi
        else
            error "Podman installation failed. Install manually: https://podman.io/docs/installation"
            exit 1
        fi
    fi
}

# --- Container runtime daemon check ----------------------------------------

ensure_runtime_running() {
    detect_runtime

    if [[ -n "$CONTAINER_CMD" ]]; then
        success "Container runtime found: $CONTAINER_CMD"
    else
        # Nothing running — try to start or install
        if check_command podman; then
            # Podman is daemonless, just needs to be available
            CONTAINER_CMD="podman"
            success "Podman is available (daemonless)"
            # Ensure Docker Hub is configured as default registry (Podman requires this)
            if ! grep -q 'docker.io' /etc/containers/registries.conf 2>/dev/null && \
               ! grep -q 'docker.io' /etc/containers/registries.conf.d/*.conf 2>/dev/null; then
                info "Configuring Docker Hub as default registry for Podman..."
                sudo mkdir -p /etc/containers/registries.conf.d
                echo 'unqualified-search-registries = ["docker.io"]' | sudo tee /etc/containers/registries.conf.d/docker-hub.conf > /dev/null
                success "Docker Hub registry configured"
            fi
            detect_runtime
            return 0
        elif check_command docker; then
            warn "Docker daemon is not running."
            echo -ne "${PURPLE}🔹 Start Docker daemon now? ${DIM}[Y/n]${NC}: "
            read -r answer
            if [[ "${answer,,}" == "n" ]]; then
                error "Container runtime must be running. Aborting."
                exit 1
            fi
            if check_command systemctl; then
                info "Starting Docker via systemctl..."
                sudo systemctl start docker && sleep 2
            elif check_command service; then
                info "Starting Docker via service..."
                sudo service docker start && sleep 2
            fi
            detect_runtime
            if [[ -n "$CONTAINER_CMD" ]]; then
                success "Docker daemon started"
                sudo systemctl enable docker 2>/dev/null || true
                return 0
            fi
            error "Could not start Docker daemon. Try: sudo systemctl start docker"
            exit 1
        else
            install_container_runtime
            detect_runtime
        fi
    fi

    # Check compose — auto-install if missing
    if [[ -z "$COMPOSE_CMD" ]]; then
        warn "No compose command found."
        if [[ "$CONTAINER_CMD" == *"podman"* ]]; then
            info "Installing podman-compose..."
            local compose_installed=false

            # 1. Try distro package (works on Fedora/Ubuntu, not RHEL)
            if install_package podman-compose 2>/dev/null; then
                compose_installed=true
            fi

            # 2. Try pip3 / python3 -m pip
            if ! $compose_installed; then
                # Ensure pip is available
                if ! check_command pip3 && ! python3 -m pip --version &>/dev/null 2>&1; then
                    info "Installing python3-pip..."
                    install_package python3-pip 2>/dev/null || true
                fi
                # Install via pip
                if check_command pip3; then
                    info "Installing podman-compose via pip3..."
                    sudo -E pip3 install podman-compose 2>&1 && compose_installed=true
                elif python3 -m pip --version &>/dev/null 2>&1; then
                    info "Installing podman-compose via python3 -m pip..."
                    sudo -E python3 -m pip install podman-compose 2>&1 && compose_installed=true
                fi
            fi

            # Ensure pip install path is in PATH (pip installs to /usr/local/bin which may not be in PATH)
            if [[ ":$PATH:" != *":/usr/local/bin:"* ]]; then
                export PATH="/usr/local/bin:$PATH"
            fi

            # Re-detect after install
            detect_runtime
            if [[ -z "$COMPOSE_CMD" ]]; then
                error "Could not install podman-compose. Install manually: pip3 install podman-compose"
                exit 1
            fi
        else
            error "Install Docker Compose: https://docs.docker.com/compose/install/"
            exit 1
        fi
    fi
    success "Compose command: $COMPOSE_CMD"
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
            curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
            sudo -E apt-get install -y nodejs
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
            sudo -E npm install -g @anthropic-ai/claude-code 2>&1 | tail -3
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

    # Check container runtime (Podman or Docker)
    info "Checking container runtime (Podman/Docker)..."
    ensure_runtime_running

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

    # Public AI Chat
    echo ""
    info "Public AI Chat page — expose a standalone chat widget on a dedicated port"
    ask "Chat page port (0 to disable)" "8888" CONF_CHAT_PORT
    CONF_CHAT_API_KEY=""
    if [[ "$CONF_CHAT_PORT" != "0" ]]; then
        local generated_chat_key
        generated_chat_key=$(generate_password)
        ask_secret "Chat API key (Enter for random)" "$generated_chat_key" CONF_CHAT_API_KEY
    fi

    # Ollama (local LLMs)
    echo ""
    echo -ne "${PURPLE}🔹 Install Ollama for local LLMs? (requires ~8GB disk) ${DIM}[y/N]${NC}: "
    read -r answer
    CONF_OLLAMA="false"
    if [[ "${answer,,}" == "y" ]]; then
        CONF_OLLAMA="true"
    fi

    # Container storage path (Podman stores images in /var/lib/containers by default)
    CONF_STORAGE_PATH=""
    if [[ "$CONTAINER_CMD" == *"podman"* ]]; then
        echo ""
        info "Podman stores images in /var/lib/containers (on / partition by default)."
        info "If your / partition is small, point to a larger partition (e.g. /home/containers)."
        ask_optional "Container storage path (leave empty for default)" CONF_STORAGE_PATH
    fi

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
    [[ "$CONF_CHAT_PORT" != "0" ]] && echo -e "  │ ${CYAN}Chat Port:${NC} $CONF_CHAT_PORT"
    [[ "$CONF_OLLAMA" == "true" ]] && echo -e "  │ ${CYAN}Ollama:${NC}    ${DIM}enabled (local LLMs)${NC}" || echo -e "  │ ${CYAN}Ollama:${NC}    ${DIM}disabled${NC}"
    [[ -n "$CONF_STORAGE_PATH" ]]  && echo -e "  │ ${CYAN}Storage:${NC}   ${DIM}$CONF_STORAGE_PATH${NC}"
    [[ -n "$CONF_HTTP_PROXY" ]]    && echo -e "  │ ${CYAN}Proxy:${NC}     ${DIM}$CONF_HTTP_PROXY${NC}"
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

    # Append public chat config
    if [[ "$CONF_CHAT_PORT" != "0" ]]; then
        echo "" >> "$ENV_FILE"
        echo "# --- Public AI Chat ---" >> "$ENV_FILE"
        echo "CHAT_PORT=${CONF_CHAT_PORT}" >> "$ENV_FILE"
        [[ -n "$CONF_CHAT_API_KEY" ]] && echo "CHAT_API_KEY=${CONF_CHAT_API_KEY}" >> "$ENV_FILE"
    fi

    # Append proxy config
    if [[ -n "$CONF_HTTP_PROXY" ]]; then
        echo "" >> "$ENV_FILE"
        echo "# --- Enterprise Proxy ---" >> "$ENV_FILE"
        echo "HTTP_PROXY=${CONF_HTTP_PROXY}" >> "$ENV_FILE"
        [[ -n "$CONF_HTTPS_PROXY" ]] && echo "HTTPS_PROXY=${CONF_HTTPS_PROXY}" >> "$ENV_FILE"
        [[ -n "$CONF_NO_PROXY" ]]    && echo "NO_PROXY=${CONF_NO_PROXY}" >> "$ENV_FILE"
    fi

    # Container runtime socket (Podman or Docker)
    if [[ "$CONTAINER_CMD" == *"podman"* ]]; then
        echo "" >> "$ENV_FILE"
        echo "# --- Container Runtime ---" >> "$ENV_FILE"
        echo "CONTAINER_SOCKET=/run/podman/podman.sock" >> "$ENV_FILE"
    fi

    success ".env file generated"
}

# --- Build & Start -----------------------------------------------------------

build_and_start() {
    step "4/6 — Building & Starting Services"

    # Setup custom container storage path if configured
    if [[ -n "$CONF_STORAGE_PATH" ]]; then
        info "Configuring container storage at: $CONF_STORAGE_PATH"
        sudo mkdir -p "$CONF_STORAGE_PATH"

        if [[ "$CONTAINER_CMD" == *"podman"* ]]; then
            # Podman: configure via storage.conf
            sudo mkdir -p /etc/containers
            local need_reset=false
            if [[ ! -f /etc/containers/storage.conf ]] || ! grep -q "graphroot.*$CONF_STORAGE_PATH" /etc/containers/storage.conf 2>/dev/null; then
                # Stop all containers first
                $CONTAINER_CMD stop -a 2>/dev/null || true
                sudo tee /etc/containers/storage.conf > /dev/null << STOREOF
[storage]
driver = "overlay"
graphroot = "$CONF_STORAGE_PATH"
STOREOF
                # Reset Podman storage to apply new path
                info "Applying new storage path (podman system reset)..."
                $CONTAINER_CMD system reset --force 2>/dev/null || sudo podman system reset --force 2>/dev/null || true
                success "Podman storage configured at $CONF_STORAGE_PATH"
            else
                success "Podman storage already set to $CONF_STORAGE_PATH"
            fi
        else
            # Docker: configure via daemon.json
            sudo mkdir -p /etc/docker
            if [[ ! -f /etc/docker/daemon.json ]]; then
                echo "{\"data-root\": \"$CONF_STORAGE_PATH\"}" | sudo tee /etc/docker/daemon.json > /dev/null
                sudo systemctl restart docker 2>/dev/null || sudo service docker restart 2>/dev/null || true
                success "Docker storage configured at $CONF_STORAGE_PATH"
            else
                warn "daemon.json already exists — update data-root manually if needed"
            fi
        fi
    fi

    # Ollama: only start if user opted in (uses docker-compose profiles)
    if [[ "$CONF_OLLAMA" == "true" ]]; then
        OLLAMA_PROFILE="--profile ollama"
        info "Ollama enabled — will pull ollama image (~7 GB)"
    else
        OLLAMA_PROFILE=""
        info "Ollama disabled — skipping local LLM container"
    fi

    # Pull remote images one by one with clear progress
    local images_to_pull=("db:docker.io/library/postgres:16-alpine:~80MB" "redis:docker.io/library/redis:7-alpine:~15MB")
    if [[ -n "$OLLAMA_PROFILE" ]]; then
        images_to_pull+=("ollama:docker.io/ollama/ollama:latest:~1.5GB")
    fi

    local total=${#images_to_pull[@]}
    local current=0

    for img_entry in "${images_to_pull[@]}"; do
        IFS=':' read -r svc_name img_repo img_tag img_size <<< "$img_entry"
        current=$((current + 1))
        local full_image="${img_repo}:${img_tag}"
        info "[${current}/${total}] Pulling ${svc_name} (${full_image} ~${img_size})..."

        if $CONTAINER_CMD pull "${full_image}" 2>&1 | while IFS= read -r line; do
            # Show only meaningful lines (not blob hashes)
            if echo "$line" | grep -qE "Copying|Writing|already exists|Pulling|Trying|manifest"; then
                echo -ne "\r    ${DIM}${line}${NC}                    "
            fi
        done; then
            echo ""
            success "[${current}/${total}] ${svc_name} ready"
        else
            echo ""
            warn "[${current}/${total}] Failed to pull ${svc_name} — will retry on start"
        fi
    done
    echo ""
    success "All remote images pulled"

    # Build custom images (app + waha)
    info "Building container images (this may take a few minutes on first run)..."
    echo ""
    local build_log
    build_log=$(mktemp)
    if ! dcompose $OLLAMA_PROFILE build --pull 2>&1 | tee "$build_log" | while IFS= read -r line; do
        echo -e "    ${DIM}${line}${NC}"
    done; then
        # Double-check with the log file (pipefail + while can mask exit codes)
        if grep -qi "error\|failed\|fatal" "$build_log" 2>/dev/null; then
            error "Container build failed. Check the output above for details."
            rm -f "$build_log"
            exit 1
        fi
        warn "Build exited with non-zero code but no errors found — continuing..."
    fi
    rm -f "$build_log"
    echo ""
    success "Container images built successfully"

    # Ensure Podman registries are configured (must be before compose up)
    if [[ "$CONTAINER_CMD" == *"podman"* ]]; then
        if ! grep -rq 'docker.io' /etc/containers/registries.conf /etc/containers/registries.conf.d/ 2>/dev/null; then
            info "Configuring Docker Hub as default registry for Podman..."
            sudo mkdir -p /etc/containers/registries.conf.d
            echo 'unqualified-search-registries = ["docker.io"]' | sudo tee /etc/containers/registries.conf.d/docker-hub.conf > /dev/null
            success "Docker Hub registry configured"
        fi
    fi

    # Start
    info "Starting services..."
    if ! dcompose $OLLAMA_PROFILE up -d 2>&1; then
        error "Failed to start services. Checking image availability..."
        echo ""
        # Show which images are available to help debug
        $CONTAINER_CMD images --format "table {{.Repository}}:{{.Tag}}\t{{.Size}}" 2>/dev/null | grep -E "zeniclaw|postgres|redis|ollama" || true
        echo ""
        error "Try running: $COMPOSE_CMD pull && $COMPOSE_CMD up -d"
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
        db_health=$($CONTAINER_CMD inspect --format='{{.State.Health.Status}}' zeniclaw_db 2>/dev/null || echo "starting")
        redis_health=$($CONTAINER_CMD inspect --format='{{.State.Health.Status}}' zeniclaw_redis 2>/dev/null || echo "starting")
        app_health=$($CONTAINER_CMD inspect --format='{{.State.Status}}' zeniclaw_app 2>/dev/null || echo "starting")

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
    if [[ "${CONF_CHAT_PORT:-0}" != "0" ]]; then
        echo -e "  │  ${CYAN}AI Chat:${NC}      ${BOLD}http://localhost:${CONF_CHAT_PORT}/chat${NC}"
    fi
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
    if [[ "${CONF_OLLAMA:-}" == "true" ]]; then
        services+=("zeniclaw_ollama")
        labels+=("Ollama   ")
    fi

    for i in "${!services[@]}"; do
        local status
        status=$($CONTAINER_CMD inspect --format='{{.State.Status}}' "${services[$i]}" 2>/dev/null || echo "not found")
        local health
        health=$($CONTAINER_CMD inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}n/a{{end}}' "${services[$i]}" 2>/dev/null || echo "n/a")

        if [[ "$status" == "running" ]]; then
            echo -e "  │  ${GREEN}●${NC} ${labels[$i]}  ${DIM}running${NC} ${DIM}(${health})${NC}"
        else
            echo -e "  │  ${RED}●${NC} ${labels[$i]}  ${DIM}${status}${NC}"
        fi
    done

    if [[ -n "${CONF_STORAGE_PATH:-}" ]]; then
        echo -e "  │"
        echo -e "  │  ${CYAN}Storage:${NC}  ${DIM}$CONF_STORAGE_PATH${NC}"
    fi

    echo -e "  └──────────────────────────────────────────────────"
    echo ""
    echo -e "  ${BOLD}Useful commands:${NC}"
    echo -e "  ${DIM}  $COMPOSE_CMD logs -f        ${NC}# View live logs"
    echo -e "  ${DIM}  $COMPOSE_CMD ps              ${NC}# Service status"
    echo -e "  ${DIM}  $COMPOSE_CMD down            ${NC}# Stop all services"
    echo -e "  ${DIM}  $COMPOSE_CMD up -d           ${NC}# Restart services"
    echo ""
}

# --- Early Proxy Config (before any downloads) --------------------------------

ask_proxy_early() {
    echo ""
    info "Si vous etes derriere un proxy d'entreprise, configurez-le maintenant."
    info "Sinon, appuyez sur Entree pour passer."
    ask_optional "HTTP Proxy (e.g. http://proxy:8080)" CONF_HTTP_PROXY

    CONF_HTTPS_PROXY=""
    CONF_NO_PROXY=""
    if [[ -n "$CONF_HTTP_PROXY" ]]; then
        ask "HTTPS Proxy" "$CONF_HTTP_PROXY" CONF_HTTPS_PROXY
        ask "No-Proxy exclusions" "localhost,127.0.0.1,db,redis,waha,ollama,app" CONF_NO_PROXY

        # Export immediately so curl/apt/git/npm use the proxy
        export http_proxy="$CONF_HTTP_PROXY"
        export https_proxy="$CONF_HTTPS_PROXY"
        export HTTP_PROXY="$CONF_HTTP_PROXY"
        export HTTPS_PROXY="$CONF_HTTPS_PROXY"
        export no_proxy="$CONF_NO_PROXY"
        export NO_PROXY="$CONF_NO_PROXY"

        # Configure git proxy (workaround for older libcurl that chokes on proxy URLs)
        git config --global http.proxy "$CONF_HTTP_PROXY"
        git config --global https.proxy "$CONF_HTTPS_PROXY"

        success "Proxy configure: $CONF_HTTP_PROXY"
    fi
}

# --- Clone or Update Repository ----------------------------------------------

REPO_URL="https://github.com/zeniclaw/core.git"
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
        sudo -E mkdir -p "$(dirname "$INSTALL_DIR")"
        sudo -E git clone --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
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

    # Ask for proxy FIRST — needed before any downloads (git pull, dnf, curl...)
    ask_proxy_early

    if [[ -f "$SCRIPT_DIR/docker-compose.yml" ]]; then
        # Running from inside the repo — git pull latest first
        cd "$SCRIPT_DIR"
        info "Running from project directory: $SCRIPT_DIR"
        if [[ -d ".git" ]]; then
            step "0/6 — Updating Source Code"
            info "Pulling latest code..."
            if git pull origin main 2>&1; then
                success "Code updated to latest version"
            else
                warn "Git pull failed (may need manual merge). Continuing with current code..."
            fi
        fi
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
