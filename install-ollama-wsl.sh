#!/usr/bin/env bash
# ============================================================================
# ZeniClaw — Ollama Native WSL2 Installer (GPU-accelerated)
# Usage: bash install-ollama-wsl.sh [command]
#
# Commands:
#   install       Install Ollama + GPU drivers + download model (full setup)
#   update        Update Ollama to latest version
#   list          List all available models from Ollama registry
#   download      Download and configure a model for ZeniClaw
#   start         Start Ollama server (port 11435)
#   stop          Stop Ollama server
#   status        Show Ollama status, loaded models, GPU info
#   detectgpu     Detect and install GPU drivers if needed
# ============================================================================

set -e

OLLAMA_PORT="${OLLAMA_WSL_PORT:-11435}"
OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}"
OLLAMA_URL="http://${OLLAMA_HOST}"
OLLAMA_PID_FILE="/tmp/ollama-wsl.pid"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC} $*"; }
ok()    { echo -e "${GREEN}[OK]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
err()   { echo -e "${RED}[ERROR]${NC} $*"; }
step()  { echo -e "\n${BOLD}==> $*${NC}"; }

# ── GPU Detection & Auto-Install ──────────────────────────────────────────

# Returns gpu type via echo: nvidia, amd, none
_detect_gpu_type() {
    # NVIDIA
    if [ -e /usr/lib/wsl/lib/libcuda.so.1 ] || [ -e /dev/nvidia0 ]; then
        echo "nvidia"
        return
    fi

    # AMD — check multiple signals
    if [ -e /dev/kfd ]; then
        echo "amd"
        return
    fi
    if [ -d /usr/lib/wsl/lib ] && ls /usr/lib/wsl/lib/libdx*.so* &>/dev/null; then
        # WSL2 with DirectX — check CPU vendor for AMD
        if grep -qi "AMD" /proc/cpuinfo 2>/dev/null; then
            echo "amd"
            return
        fi
        # Could be Intel or NVIDIA via DirectX
        if lspci 2>/dev/null | grep -qi "NVIDIA"; then
            echo "nvidia"
            return
        fi
    fi
    if lspci 2>/dev/null | grep -qi "AMD.*VGA\|AMD.*Display\|Radeon"; then
        echo "amd"
        return
    fi

    echo "none"
}

_is_nvidia_ready() {
    command -v nvidia-smi &>/dev/null && nvidia-smi &>/dev/null
}

_is_rocm_ready() {
    command -v rocminfo &>/dev/null
}

_install_nvidia_drivers() {
    step "Installing NVIDIA CUDA toolkit for WSL2..."

    sudo apt-get update -qq
    sudo apt-get install -y -qq nvidia-cuda-toolkit 2>/dev/null || {
        # Fallback: install just nvidia-utils to get nvidia-smi
        warn "Full CUDA toolkit not available, installing minimal drivers..."
        # Try to find the right nvidia-utils version
        local pkg
        pkg=$(apt-cache search nvidia-utils 2>/dev/null | grep -oP 'nvidia-utils-\d+' | sort -V | tail -1)
        if [ -n "$pkg" ]; then
            sudo apt-get install -y -qq "$pkg"
        fi
    }

    # Ensure WSL CUDA lib is in path
    if [ -d /usr/lib/wsl/lib ] && ! echo "$LD_LIBRARY_PATH" | grep -q "/usr/lib/wsl/lib"; then
        echo 'export LD_LIBRARY_PATH=/usr/lib/wsl/lib:$LD_LIBRARY_PATH' | sudo tee /etc/profile.d/wsl-gpu.sh >/dev/null
        export LD_LIBRARY_PATH=/usr/lib/wsl/lib:${LD_LIBRARY_PATH}
        ok "Added /usr/lib/wsl/lib to LD_LIBRARY_PATH"
    fi
}

_install_amd_rocm() {
    step "Installing AMD ROCm for WSL2..."

    # Check Ubuntu version
    local codename
    codename=$(lsb_release -cs 2>/dev/null || echo "jammy")
    local version
    version=$(lsb_release -rs 2>/dev/null || echo "22.04")

    info "Detected: Ubuntu ${version} (${codename})"

    # Method 1: Try amdgpu-install (official installer)
    if ! command -v amdgpu-install &>/dev/null; then
        info "Adding AMD ROCm repository..."

        # Install prerequisites
        sudo apt-get update -qq
        sudo apt-get install -y -qq wget gnupg2

        # Add AMD GPG key and repo
        sudo mkdir -p /etc/apt/keyrings
        wget -q -O - https://repo.radeon.com/rocm/rocm.gpg.key | \
            gpg --dearmor | sudo tee /etc/apt/keyrings/rocm.gpg >/dev/null

        # Add ROCm repo (try latest stable)
        local rocm_version="6.4"
        echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/rocm.gpg] https://repo.radeon.com/rocm/apt/${rocm_version} ${codename} main" | \
            sudo tee /etc/apt/sources.list.d/rocm.list >/dev/null

        # Pin priority
        echo -e "Package: *\nPin: release o=repo.radeon.com\nPin-Priority: 600" | \
            sudo tee /etc/apt/preferences.d/rocm-pin-600 >/dev/null

        sudo apt-get update -qq
    fi

    # Install ROCm libraries (minimal set for Ollama)
    info "Installing ROCm libraries (this may take a few minutes)..."
    sudo apt-get install -y -qq rocm-hip-runtime rocm-hip-sdk 2>/dev/null || {
        warn "Full ROCm SDK not available, trying minimal install..."
        sudo apt-get install -y -qq rocm-hip-runtime 2>/dev/null || {
            warn "ROCm hip runtime not available, trying rocminfo only..."
            sudo apt-get install -y -qq rocminfo 2>/dev/null || true
        }
    }

    # Add user to render and video groups
    sudo usermod -aG render,video "$(whoami)" 2>/dev/null || true

    # Set HSA override for WSL2 (needed for some AMD iGPUs)
    if ! grep -q "HSA_OVERRIDE_GFX_VERSION" /etc/environment 2>/dev/null; then
        # Detect gfx version from the GPU
        local gfx_ver
        gfx_ver=$(rocminfo 2>/dev/null | grep -oP 'gfx\d+' | head -1 || true)
        if [ -n "$gfx_ver" ]; then
            info "Detected GPU arch: ${gfx_ver}"
        else
            # For Ryzen AI PRO (RDNA 3.5 / Phoenix/Hawk Point), use gfx1103
            gfx_ver="gfx1103"
            info "Assuming GPU arch: ${gfx_ver} (Ryzen AI PRO / RDNA 3.5)"
        fi

        # Convert gfx1103 → 11.0.3
        local major minor patch
        major=$(echo "$gfx_ver" | grep -oP '\d' | head -1)
        minor=$(echo "$gfx_ver" | grep -oP '\d' | sed -n '2p')
        patch=$(echo "$gfx_ver" | grep -oP '\d' | sed -n '3p')
        if [ -n "$major" ] && [ -n "$minor" ]; then
            local hsa_ver="${major}${minor}.0.${patch:-0}"
            echo "HSA_OVERRIDE_GFX_VERSION=${hsa_ver}" | sudo tee -a /etc/environment >/dev/null
            export HSA_OVERRIDE_GFX_VERSION="${hsa_ver}"
            ok "Set HSA_OVERRIDE_GFX_VERSION=${hsa_ver}"
        fi
    fi

    # Ensure /dev/kfd is accessible
    if [ -e /dev/kfd ]; then
        sudo chmod 666 /dev/kfd 2>/dev/null || true
    fi
}

detect_gpu() {
    step "GPU Detection & Setup"

    local gpu_type
    gpu_type=$(_detect_gpu_type)

    case "$gpu_type" in
        nvidia)
            ok "NVIDIA GPU detected"
            if _is_nvidia_ready; then
                ok "NVIDIA drivers ready"
                nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader 2>/dev/null || true
            else
                warn "NVIDIA drivers not fully configured"
                _install_nvidia_drivers
                if _is_nvidia_ready; then
                    ok "NVIDIA drivers installed and working"
                    nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader 2>/dev/null || true
                else
                    warn "NVIDIA drivers installed but nvidia-smi not working"
                    info "Ollama may still use GPU via WSL2 CUDA passthrough"
                fi
            fi
            ;;
        amd)
            ok "AMD GPU detected ($(grep -m1 "model name" /proc/cpuinfo 2>/dev/null | sed 's/.*: //' || echo 'unknown'))"
            if _is_rocm_ready; then
                ok "AMD ROCm ready"
                rocminfo 2>/dev/null | grep -E "Marketing Name" | head -3 || true
            else
                warn "AMD ROCm not installed — installing now..."
                _install_amd_rocm
                if _is_rocm_ready; then
                    ok "AMD ROCm installed and working"
                    rocminfo 2>/dev/null | grep -E "Marketing Name" | head -3 || true
                else
                    warn "ROCm installed but rocminfo not working"
                    info "Ollama may still detect the GPU at startup"
                    info "Try: sudo reboot (WSL2) then retry"
                fi
            fi
            ;;
        none)
            warn "No GPU detected"
            info "Ollama will run on CPU (slower)"
            info "For WSL2, ensure GPU passthrough is enabled:"
            echo "  1. Windows: GPU driver installed (NVIDIA/AMD)"
            echo "  2. .wslconfig: [wsl2] gpuSupport=true"
            echo "  3. Restart WSL: wsl --shutdown"
            ;;
    esac

    # Vulkan check (bonus)
    if ! command -v vulkaninfo &>/dev/null; then
        info "Installing Vulkan tools..."
        sudo apt-get install -y -qq mesa-vulkan-drivers vulkan-tools 2>/dev/null || true
    fi
    if command -v vulkaninfo &>/dev/null; then
        local vk_gpu
        vk_gpu=$(vulkaninfo --summary 2>/dev/null | grep "deviceName" | head -1 | sed 's/.*= //' || true)
        if [ -n "$vk_gpu" ]; then
            ok "Vulkan GPU: ${vk_gpu}"
        fi
    fi

    echo ""
    return 0
}

# ── Install ────────────────────────────────────────────────────────────────

do_install() {
    echo ""
    info "=========================================="
    info "  ZeniClaw — Ollama WSL2 Full Setup"
    info "=========================================="
    echo ""

    # Step 1: System dependencies
    step "[1/5] System dependencies"
    sudo apt-get update -qq
    sudo apt-get install -y -qq curl wget pciutils lsb-release zstd 2>/dev/null || true
    ok "System dependencies installed"

    # Step 2: GPU detection & driver install
    step "[2/5] GPU detection & driver installation"
    detect_gpu

    # Step 3: Install Ollama
    step "[3/5] Installing Ollama"
    if command -v ollama &>/dev/null; then
        local ver
        ver=$(ollama --version 2>/dev/null | grep -oP '[\d.]+' | head -1)
        ok "Ollama already installed (${ver})"
    else
        info "Downloading Ollama..."
        curl -fsSL https://ollama.com/install.sh | sh
        ok "Ollama installed: $(ollama --version 2>&1)"
    fi

    # Disable systemd auto-service (we manage manually on custom port)
    if systemctl is-active ollama &>/dev/null 2>&1; then
        info "Disabling systemd Ollama service (using custom port ${OLLAMA_PORT})..."
        sudo systemctl stop ollama 2>/dev/null || true
        sudo systemctl disable ollama 2>/dev/null || true
    fi

    # Step 4: Start Ollama
    step "[4/5] Starting Ollama on port ${OLLAMA_PORT}"
    do_start

    # Verify GPU is being used
    sleep 2
    local ps_data
    ps_data=$(curl -sf "${OLLAMA_URL}/api/version" 2>/dev/null || true)
    if [ -n "$ps_data" ]; then
        ok "Ollama server running"
    fi

    # Step 5: Download a model
    step "[5/5] Download a model"
    echo ""
    echo "  Recommended models:"
    echo "    1) qwen2.5:7b         — Fast, function calling (4.5 GB)"
    echo "    2) mistral-nemo:12b   — Balanced, French (7.4 GB)"
    echo "    3) llama3.1:8b        — General purpose (4.7 GB)"
    echo "    4) Skip for now"
    echo ""
    read -rp "  Choice [1-4]: " choice
    case "$choice" in
        1) do_download "qwen2.5:7b" ;;
        2) do_download "mistral-nemo:12b" ;;
        3) do_download "llama3.1:8b" ;;
        *) info "Skipped. Download later: bash $0 download" ;;
    esac

    # Final summary
    echo ""
    info "=========================================="
    ok "  Setup complete!"
    info "=========================================="
    echo ""
    echo "  Ollama running on: ${OLLAMA_URL}"
    echo ""
    echo "  Commands:"
    echo "    bash $0 status      — Check everything"
    echo "    bash $0 download    — Add more models"
    echo "    bash $0 stop        — Stop Ollama"
    echo "    bash $0 start       — Start Ollama"
    echo ""

    # Check if ZeniClaw needs reconfiguring
    local current_url
    current_url=$(podman exec zeniclaw_app php /var/www/html/artisan tinker --execute="echo App\Models\AppSetting::get('onprem_api_url');" 2>/dev/null || true)
    if [ -n "$current_url" ] && [ "$current_url" != "${OLLAMA_URL}" ]; then
        warn "ZeniClaw still points to: ${current_url}"
        echo "  Update via download command or manually set onprem_api_url to ${OLLAMA_URL}"
    fi
}

# ── Update ─────────────────────────────────────────────────────────────────

do_update() {
    step "Updating Ollama"

    if ! command -v ollama &>/dev/null; then
        err "Ollama not installed. Run: bash $0 install"
        return 1
    fi

    local old_ver
    old_ver=$(ollama --version 2>/dev/null | grep -oP '[\d.]+' | head -1)
    info "Current version: ${old_ver}"

    local was_running=false
    if _ollama_running; then
        was_running=true
        do_stop
    fi

    curl -fsSL https://ollama.com/install.sh | sh

    local new_ver
    new_ver=$(ollama --version 2>/dev/null | grep -oP '[\d.]+' | head -1)
    ok "Updated: ${old_ver} → ${new_ver}"

    if $was_running; then
        do_start
    fi
}

# ── List Models ────────────────────────────────────────────────────────────

do_list() {
    step "Available Models"
    echo ""
    printf "  ${BOLD}%-30s %-10s %s${NC}\n" "MODEL" "SIZE" "NOTES"
    printf "  %-30s %-10s %s\n" "-----" "----" "-----"
    printf "  %-30s %-10s %s\n" "qwen2.5:7b" "~4.5 GB" "Fast, function calling, recommended"
    printf "  %-30s %-10s %s\n" "mistral-nemo:12b" "~7.4 GB" "Balanced, French, function calling"
    printf "  %-30s %-10s %s\n" "llama3.1:8b" "~4.7 GB" "Meta, general purpose"
    printf "  %-30s %-10s %s\n" "command-r:7b" "~4.0 GB" "Function calling, RAG"
    printf "  %-30s %-10s %s\n" "mistral-small:22b" "~13 GB" "Powerful, needs 16GB+ VRAM"
    printf "  %-30s %-10s %s\n" "qwen2.5:32b" "~19 GB" "Very powerful, needs 24GB+ VRAM"
    echo ""
    echo "  Vision:"
    printf "  %-30s %-10s %s\n" "llava:7b" "~4.5 GB" "Vision, basic"
    printf "  %-30s %-10s %s\n" "llama3.2-vision:11b" "~7.9 GB" "Vision + OCR"
    printf "  %-30s %-10s %s\n" "minicpm-v" "~5.5 GB" "Vision + OCR, multilingual"
    echo ""

    if _ollama_running; then
        info "Installed locally:"
        OLLAMA_HOST="$OLLAMA_HOST" ollama list 2>/dev/null || echo "  (none)"
        echo ""
    fi

    echo "  Browse all: https://ollama.com/library"
    echo "  Download:   bash $0 download <model_name>"
    echo ""
}

# ── Download Model ─────────────────────────────────────────────────────────

do_download() {
    local model="$1"

    if [ -z "$model" ]; then
        echo ""
        echo "  Which model?"
        echo "    1) qwen2.5:7b         (fast, 4.5 GB)"
        echo "    2) mistral-nemo:12b   (balanced, 7.4 GB)"
        echo "    3) llama3.1:8b        (general, 4.7 GB)"
        echo "    4) qwen2.5:32b        (powerful, 19 GB)"
        echo "    5) Custom model name"
        echo ""
        read -rp "  Choice [1-5 or model name]: " choice
        case "$choice" in
            1) model="qwen2.5:7b" ;;
            2) model="mistral-nemo:12b" ;;
            3) model="llama3.1:8b" ;;
            4) model="qwen2.5:32b" ;;
            *) model="$choice" ;;
        esac
    fi

    [ -z "$model" ] && { err "No model specified"; return 1; }

    if ! _ollama_running; then
        info "Starting Ollama first..."
        do_start
        sleep 2
    fi

    info "Downloading ${model}..."
    OLLAMA_HOST="$OLLAMA_HOST" ollama pull "$model"

    if [ $? -eq 0 ]; then
        ok "Model ${model} downloaded!"

        # Warm up the model (load into GPU/RAM)
        info "Loading model into memory..."
        curl -sf "${OLLAMA_URL}/api/generate" \
            -d "{\"model\":\"${model}\",\"prompt\":\"\",\"keep_alive\":-1}" >/dev/null 2>&1 &

        # Check VRAM usage after loading
        sleep 3
        local vram
        vram=$(curl -sf "${OLLAMA_URL}/api/ps" 2>/dev/null | python3 -c "
import sys,json
d=json.load(sys.stdin)
for m in d.get('models',[]):
    if m['name']=='${model}':
        v=m.get('size_vram',0)
        print(f'{v/1e9:.1f} GB' if v>0 else 'CPU only')
        break
" 2>/dev/null || echo "unknown")
        info "Model VRAM usage: ${vram}"

        echo ""
        read -rp "  Configure as ZeniClaw model role? [fast/balanced/powerful/skip]: " role
        if [[ "$role" =~ ^(fast|balanced|powerful)$ ]]; then
            _configure_zeniclaw "$model" "$role"
        else
            info "Skipped ZeniClaw config"
        fi
    fi
}

_configure_zeniclaw() {
    local model="$1"
    local role="$2"

    info "Configuring ZeniClaw..."

    # Get host IP reachable from container
    local host_ip
    host_ip=$(ip route show default 2>/dev/null | awk '{print $3}')
    [ -z "$host_ip" ] && host_ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    [ -z "$host_ip" ] && host_ip="host.containers.internal"

    local api_url="http://${host_ip}:${OLLAMA_PORT}"

    podman exec zeniclaw_app php /var/www/html/artisan tinker --execute="
        App\Models\AppSetting::set('model_role_${role}', '${model}');
        App\Models\AppSetting::set('onprem_api_url', '${api_url}');
        echo 'OK';
    " 2>/dev/null | grep -q "OK" && {
        ok "model_role_${role} = ${model}"
        ok "onprem_api_url = ${api_url}"
    } || {
        warn "Could not configure ZeniClaw (container not running?)"
        echo "  Set manually: model_role_${role} = ${model}"
        echo "  Set manually: onprem_api_url = ${api_url}"
    }
}

# ── Start ──────────────────────────────────────────────────────────────────

do_start() {
    if _ollama_running; then
        ok "Ollama already running on ${OLLAMA_URL}"
        return 0
    fi

    if ! command -v ollama &>/dev/null; then
        err "Ollama not installed. Run: bash $0 install"
        return 1
    fi

    # Stop systemd service if running
    systemctl is-active ollama &>/dev/null 2>&1 && sudo systemctl stop ollama 2>/dev/null || true

    info "Starting Ollama on port ${OLLAMA_PORT}..."

    # Set GPU env vars
    local gpu_type
    gpu_type=$(_detect_gpu_type)
    local env_vars="OLLAMA_HOST=0.0.0.0:${OLLAMA_PORT}"

    if [ "$gpu_type" = "amd" ]; then
        # HSA override for AMD iGPU compatibility
        if [ -n "${HSA_OVERRIDE_GFX_VERSION:-}" ]; then
            env_vars="${env_vars} HSA_OVERRIDE_GFX_VERSION=${HSA_OVERRIDE_GFX_VERSION}"
        elif grep -q "HSA_OVERRIDE_GFX_VERSION" /etc/environment 2>/dev/null; then
            local hsa
            hsa=$(grep "HSA_OVERRIDE_GFX_VERSION" /etc/environment | cut -d= -f2)
            env_vars="${env_vars} HSA_OVERRIDE_GFX_VERSION=${hsa}"
        fi
    fi

    env $env_vars nohup ollama serve > /tmp/ollama-wsl.log 2>&1 &
    echo $! > "$OLLAMA_PID_FILE"

    # Wait for startup
    local retries=0
    while [ $retries -lt 20 ]; do
        if curl -sf "${OLLAMA_URL}/api/tags" &>/dev/null; then
            ok "Ollama running on ${OLLAMA_URL} (PID: $(cat "$OLLAMA_PID_FILE"))"

            # Check GPU acceleration
            sleep 1
            if [ "$gpu_type" != "none" ]; then
                # Load a tiny test to check VRAM
                info "GPU type: ${gpu_type}"
                grep "GPU" /tmp/ollama-wsl.log 2>/dev/null | head -3 || true
                grep "VRAM\|CUDA\|ROCm\|hip" /tmp/ollama-wsl.log 2>/dev/null | head -3 || true
            fi
            return 0
        fi
        sleep 1
        retries=$((retries + 1))
    done

    err "Ollama failed to start. Logs:"
    tail -20 /tmp/ollama-wsl.log
    return 1
}

# ── Stop ───────────────────────────────────────────────────────────────────

do_stop() {
    if [ -f "$OLLAMA_PID_FILE" ]; then
        local pid
        pid=$(cat "$OLLAMA_PID_FILE")
        if kill -0 "$pid" 2>/dev/null; then
            info "Stopping Ollama (PID: ${pid})..."
            kill "$pid"
            sleep 1
            kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null
            rm -f "$OLLAMA_PID_FILE"
            ok "Ollama stopped"
            return 0
        fi
        rm -f "$OLLAMA_PID_FILE"
    fi

    if pgrep -f "ollama serve" &>/dev/null; then
        pkill -f "ollama serve"
        ok "Ollama stopped"
    else
        warn "Ollama not running"
    fi
}

# ── Status ─────────────────────────────────────────────────────────────────

do_status() {
    step "Ollama Status"

    # Installation
    if command -v ollama &>/dev/null; then
        ok "Installed: $(ollama --version 2>&1)"
    else
        err "Not installed — run: bash $0 install"
        return 1
    fi

    # GPU
    local gpu_type
    gpu_type=$(_detect_gpu_type)
    case "$gpu_type" in
        nvidia)
            if _is_nvidia_ready; then
                ok "GPU: NVIDIA ($(nvidia-smi --query-gpu=name --format=csv,noheader 2>/dev/null | head -1))"
            else
                warn "GPU: NVIDIA detected but drivers incomplete"
            fi ;;
        amd)
            if _is_rocm_ready; then
                ok "GPU: AMD ROCm ready"
            else
                warn "GPU: AMD detected but ROCm not installed"
            fi ;;
        *) warn "GPU: None detected (CPU mode)" ;;
    esac

    # Running
    if _ollama_running; then
        ok "Server: running on ${OLLAMA_URL}"

        # Loaded models
        echo ""
        info "Models in memory:"
        curl -sf "${OLLAMA_URL}/api/ps" 2>/dev/null | python3 -c "
import sys,json
d=json.load(sys.stdin)
ms=d.get('models',[])
if not ms: print('  (none)')
for m in ms:
    n=m.get('name','?'); s=m.get('size',0)/1e9; v=m.get('size_vram',0)/1e9
    gpu='GPU: {:.1f} GB'.format(v) if v>0 else 'CPU only'
    print(f'  {n:30s} {s:.1f} GB  {gpu}')
" 2>/dev/null || echo "  (error)"

        echo ""
        info "Downloaded models:"
        OLLAMA_HOST="$OLLAMA_HOST" ollama list 2>/dev/null || echo "  (none)"
    else
        warn "Server: not running"
        echo "  Start: bash $0 start"
    fi

    # Container conflict check
    echo ""
    if podman ps --filter name=zeniclaw_ollama --format '{{.Status}}' 2>/dev/null | grep -q "Up"; then
        warn "Container zeniclaw_ollama also running on port 11434"
        info "Native Ollama on ${OLLAMA_PORT} avoids conflict"
    fi

    # ZeniClaw config
    echo ""
    info "ZeniClaw config:"
    podman exec zeniclaw_app php /var/www/html/artisan tinker --execute="
        echo '  onprem_api_url: ' . (App\Models\AppSetting::get('onprem_api_url') ?? '(null)');
        echo PHP_EOL . '  model_role_fast: ' . (App\Models\AppSetting::get('model_role_fast') ?? '(null)');
        echo PHP_EOL . '  model_role_balanced: ' . (App\Models\AppSetting::get('model_role_balanced') ?? '(null)');
        echo PHP_EOL . '  model_role_powerful: ' . (App\Models\AppSetting::get('model_role_powerful') ?? '(null)');
    " 2>/dev/null || warn "  ZeniClaw container not accessible"
    echo ""
}

# ── Load Model into Memory ─────────────────────────────────────────────────

do_load() {
    local model="$1"

    if ! _ollama_running; then
        err "Ollama not running. Run: bash $0 start"
        return 1
    fi

    if [ -z "$model" ]; then
        # Show downloaded models and let user pick
        echo ""
        info "Downloaded models:"
        local models
        models=$(OLLAMA_HOST="$OLLAMA_HOST" ollama list 2>/dev/null | tail -n +2 | awk '{print $1}')
        if [ -z "$models" ]; then
            err "No models downloaded. Run: bash $0 download"
            return 1
        fi

        local i=1
        declare -A model_map
        while IFS= read -r m; do
            echo "    ${i}) ${m}"
            model_map[$i]="$m"
            i=$((i + 1))
        done <<< "$models"

        echo ""
        read -rp "  Load which model? [1-$((i-1))]: " choice
        model="${model_map[$choice]}"
        [ -z "$model" ] && { err "Invalid choice"; return 1; }
    fi

    info "Loading ${model} into memory (keep_alive=-1)..."
    local start_ts
    start_ts=$(date +%s%N)

    curl -sf "${OLLAMA_URL}/api/generate" \
        -d "{\"model\":\"${model}\",\"prompt\":\"\",\"keep_alive\":-1}" >/dev/null 2>&1

    local end_ts
    end_ts=$(date +%s%N)
    local dur_ms=$(( (end_ts - start_ts) / 1000000 ))

    sleep 1

    # Show VRAM usage
    local vram_info
    vram_info=$(curl -sf "${OLLAMA_URL}/api/ps" 2>/dev/null | python3 -c "
import sys,json
d=json.load(sys.stdin)
for m in d.get('models',[]):
    if '${model}' in m.get('name',''):
        s=m.get('size',0)/1e9; v=m.get('size_vram',0)/1e9
        gpu='GPU: {:.1f} GB'.format(v) if v>0 else 'CPU only'
        print(f'{s:.1f} GB total, {gpu}')
        break
" 2>/dev/null || echo "unknown")

    ok "Model ${model} loaded in ${dur_ms}ms — ${vram_info}"
}

# ── Unload Model from Memory ──────────────────────────────────────────────

do_unload() {
    local model="$1"

    if ! _ollama_running; then
        err "Ollama not running"
        return 1
    fi

    if [ -z "$model" ]; then
        # Show loaded models
        echo ""
        info "Models in memory:"
        local models
        models=$(curl -sf "${OLLAMA_URL}/api/ps" 2>/dev/null | python3 -c "
import sys,json
d=json.load(sys.stdin)
for m in d.get('models',[]):
    print(m.get('name',''))
" 2>/dev/null)

        if [ -z "$models" ]; then
            warn "No models loaded in memory"
            return 0
        fi

        local i=1
        declare -A model_map
        while IFS= read -r m; do
            [ -z "$m" ] && continue
            echo "    ${i}) ${m}"
            model_map[$i]="$m"
            i=$((i + 1))
        done <<< "$models"

        echo "    a) Unload ALL"
        echo ""
        read -rp "  Unload which? [1-$((i-1)) or a]: " choice

        if [ "$choice" = "a" ]; then
            for key in "${!model_map[@]}"; do
                local m="${model_map[$key]}"
                info "Unloading ${m}..."
                curl -sf "${OLLAMA_URL}/api/generate" \
                    -d "{\"model\":\"${m}\",\"keep_alive\":0}" >/dev/null 2>&1
                ok "Unloaded ${m}"
            done
            return 0
        fi

        model="${model_map[$choice]}"
        [ -z "$model" ] && { err "Invalid choice"; return 1; }
    fi

    info "Unloading ${model} from memory..."
    curl -sf "${OLLAMA_URL}/api/generate" \
        -d "{\"model\":\"${model}\",\"keep_alive\":0}" >/dev/null 2>&1
    ok "Model ${model} unloaded"
}

# ── Test Model with Prompt ─────────────────────────────────────────────────

do_test() {
    local model="$1"
    local prompt="$2"

    if ! _ollama_running; then
        err "Ollama not running. Run: bash $0 start"
        return 1
    fi

    if [ -z "$model" ]; then
        # Pick from loaded or downloaded models
        local models
        models=$(OLLAMA_HOST="$OLLAMA_HOST" ollama list 2>/dev/null | tail -n +2 | awk '{print $1}')
        if [ -z "$models" ]; then
            err "No models available"
            return 1
        fi

        echo ""
        local i=1
        declare -A model_map
        while IFS= read -r m; do
            echo "    ${i}) ${m}"
            model_map[$i]="$m"
            i=$((i + 1))
        done <<< "$models"

        echo ""
        read -rp "  Test which model? [1-$((i-1))]: " choice
        model="${model_map[$choice]}"
        [ -z "$model" ] && { err "Invalid choice"; return 1; }
    fi

    if [ -z "$prompt" ]; then
        echo ""
        echo "  Test prompts:"
        echo "    1) Quick: \"Dis bonjour en une phrase\""
        echo "    2) French: \"Explique la TVA belge en 3 lignes\""
        echo "    3) Code: \"Ecris une fonction PHP qui calcule la TVA\""
        echo "    4) Custom prompt"
        echo ""
        read -rp "  Choice [1-4]: " pchoice
        case "$pchoice" in
            1) prompt="Dis bonjour en une phrase." ;;
            2) prompt="Explique la TVA belge en 3 lignes maximum." ;;
            3) prompt="Ecris une fonction PHP qui calcule le montant TTC a partir du HT et du taux de TVA. Code uniquement." ;;
            4) read -rp "  Prompt: " prompt ;;
        esac
    fi

    [ -z "$prompt" ] && { err "No prompt"; return 1; }

    echo ""
    info "Model: ${model}"
    info "Prompt: ${prompt}"
    echo ""
    echo -e "${BOLD}--- Response ---${NC}"

    local start_ts
    start_ts=$(date +%s%N)

    # Stream response
    curl -sf "${OLLAMA_URL}/api/generate" \
        -d "$(python3 -c "import json; print(json.dumps({'model':'${model}','prompt':'${prompt}','stream':True}))")" \
        2>/dev/null | while IFS= read -r line; do
        local token
        token=$(echo "$line" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('response',''),end='')" 2>/dev/null)
        echo -n "$token"
    done

    local end_ts
    end_ts=$(date +%s%N)
    local dur_ms=$(( (end_ts - start_ts) / 1000000 ))
    local dur_s=$(echo "scale=1; ${dur_ms}/1000" | bc 2>/dev/null || echo "${dur_ms}ms")

    echo ""
    echo -e "${BOLD}--- End ---${NC}"
    echo ""

    # Get stats from last response
    local stats
    stats=$(curl -sf "${OLLAMA_URL}/api/ps" 2>/dev/null | python3 -c "
import sys,json
d=json.load(sys.stdin)
for m in d.get('models',[]):
    if '${model}' in m.get('name',''):
        v=m.get('size_vram',0)/1e9
        s=m.get('size',0)/1e9
        gpu='GPU {:.1f}GB'.format(v) if v>0 else 'CPU'
        print(f'{gpu} | Model: {s:.1f}GB')
        break
" 2>/dev/null || echo "")

    ok "Time: ${dur_s}s | ${stats}"
    echo ""
}

# ── Helpers ────────────────────────────────────────────────────────────────

_ollama_running() {
    curl -sf "${OLLAMA_URL}/api/tags" &>/dev/null
}

# ── Main ───────────────────────────────────────────────────────────────────

cmd="${1:-}"
shift 2>/dev/null || true

case "$cmd" in
    install)    do_install ;;
    update)     do_update ;;
    list)       do_list ;;
    download)   do_download "$@" ;;
    start)      do_start ;;
    stop)       do_stop ;;
    status)     do_status ;;
    detectgpu)  detect_gpu ;;
    load)       do_load "$@" ;;
    unload)     do_unload "$@" ;;
    test)       do_test "$@" ;;
    *)
        echo ""
        echo "  ${BOLD}ZeniClaw — Ollama Native WSL2 Manager (GPU-accelerated)${NC}"
        echo ""
        echo "  Usage: bash $0 <command>"
        echo ""
        echo "  Setup:"
        echo "    install       Full setup: GPU drivers + Ollama + model download"
        echo "    update        Update Ollama to latest version"
        echo "    detectgpu     Detect and install GPU drivers"
        echo ""
        echo "  Models:"
        echo "    list          List available and installed models"
        echo "    download      Download and configure a model"
        echo "    load          Load a model into GPU/RAM memory"
        echo "    unload        Unload a model from memory"
        echo "    test          Test a model with a prompt (streaming)"
        echo ""
        echo "  Server:"
        echo "    start         Start Ollama server (port ${OLLAMA_PORT})"
        echo "    stop          Stop Ollama server"
        echo "    status        Show status, models, GPU info"
        echo ""
        ;;
esac
