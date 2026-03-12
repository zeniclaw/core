#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# ZeniClaw — Ollama On-Prem Setup
# Installe, configure et demarre Ollama avec un premier modele.
# Gere automatiquement le proxy si configure.
# Usage: ./setup-ollama.sh   (sans sudo — meme user que les autres containers)
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

echo -e "\n${BOLD}${CYAN}=== ZeniClaw — Ollama On-Prem Setup ===${NC}\n"

# --- Warn if running as root when containers are rootless --------------------
if [ "$(id -u)" = "0" ]; then
    # Check if zeniclaw_app exists in rootless (run by the real user)
    REAL_USER="${SUDO_USER:-}"
    if [ -n "$REAL_USER" ]; then
        # Check if app container exists in the calling user's podman
        if sudo -u "$REAL_USER" podman inspect zeniclaw_app &>/dev/null 2>&1; then
            warn "Les containers ZeniClaw tournent en rootless (user: $REAL_USER)."
            warn "Relancez SANS sudo:  ./setup-ollama.sh"
            echo ""
            read -rp "Continuer quand meme en root ? (o/N) : " FORCE_ROOT
            if [[ ! "$FORCE_ROOT" =~ ^[oOyY]$ ]]; then
                echo -e "${CYAN}Relancez: ./setup-ollama.sh${NC}"
                exit 0
            fi
        fi
    fi
fi

# --- Detect container runtime ------------------------------------------------
CONTAINER_CMD=""
COMPOSE=""

if command -v podman &>/dev/null && podman info &>/dev/null 2>&1; then
    CONTAINER_CMD="podman"
    if command -v podman-compose &>/dev/null; then
        COMPOSE="podman-compose"
    elif podman compose version &>/dev/null 2>&1; then
        COMPOSE="podman compose"
    fi
elif command -v docker &>/dev/null; then
    CONTAINER_CMD="docker"
    if docker compose version &>/dev/null 2>&1; then
        COMPOSE="docker compose"
    elif command -v docker-compose &>/dev/null; then
        COMPOSE="docker-compose"
    fi
fi

[ -z "$CONTAINER_CMD" ] && error "Aucun runtime (podman/docker) detecte."

echo -e "${DIM}Runtime: $CONTAINER_CMD | Compose: ${COMPOSE:-direct}${NC}\n"

# --- Proxy detection ---------------------------------------------------------
PROXY_HTTP="${HTTP_PROXY:-}"
PROXY_HTTPS="${HTTPS_PROXY:-}"
PROXY_NO="${NO_PROXY:-}"

# Load from .env
if [ -f .env ]; then
    [ -z "$PROXY_HTTP" ]  && PROXY_HTTP=$(grep -oP '^HTTP_PROXY=\K.*' .env 2>/dev/null || true)
    [ -z "$PROXY_HTTPS" ] && PROXY_HTTPS=$(grep -oP '^HTTPS_PROXY=\K.*' .env 2>/dev/null || true)
    [ -z "$PROXY_NO" ]    && PROXY_NO=$(grep -oP '^NO_PROXY=\K.*' .env 2>/dev/null || true)
fi

# Load from app DB
if [ -z "$PROXY_HTTP" ] && [ -z "$PROXY_HTTPS" ] && $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
    DB_HTTP=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('proxy_http') ?? '';" 2>/dev/null || true)
    DB_HTTPS=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('proxy_https') ?? '';" 2>/dev/null || true)
    DB_NO=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo App\Models\AppSetting::get('proxy_no_proxy') ?? '';" 2>/dev/null || true)
    [ -n "$DB_HTTP" ]  && PROXY_HTTP="$DB_HTTP"
    [ -n "$DB_HTTPS" ] && PROXY_HTTPS="$DB_HTTPS"
    [ -n "$DB_NO" ]    && PROXY_NO="$DB_NO"
fi

# Ask user if no proxy found
if [ -z "$PROXY_HTTP" ] && [ -z "$PROXY_HTTPS" ]; then
    echo -e "${YELLOW}Etes-vous derriere un proxy ?${NC}"
    echo -e "${DIM}Necessaire pour telecharger l'image Ollama et les modeles.${NC}"
    echo -e "${DIM}Exemple: http://proxy.entreprise.com:8080${NC}"
    read -rp "Proxy HTTP (vide = pas de proxy) : " PROXY_HTTP
    if [ -n "$PROXY_HTTP" ]; then
        read -rp "Proxy HTTPS (vide = meme que HTTP) : " PROXY_HTTPS
        [ -z "$PROXY_HTTPS" ] && PROXY_HTTPS="$PROXY_HTTP"
        read -rp "No proxy (vide = defaut) : " PROXY_NO
        [ -z "$PROXY_NO" ] && PROXY_NO="localhost,127.0.0.1,db,redis,waha,ollama,app"
    fi
fi

# Persist proxy to .env
if [ -n "$PROXY_HTTP" ] || [ -n "$PROXY_HTTPS" ]; then
    touch .env
    for VAR_NAME in HTTP_PROXY HTTPS_PROXY NO_PROXY; do
        sed -i "/^${VAR_NAME}=/d" .env
    done
    [ -n "$PROXY_HTTP" ]  && echo "HTTP_PROXY=${PROXY_HTTP}" >> .env
    [ -n "$PROXY_HTTPS" ] && echo "HTTPS_PROXY=${PROXY_HTTPS}" >> .env
    [ -n "$PROXY_NO" ]    && echo "NO_PROXY=${PROXY_NO}" >> .env
    [ -z "$PROXY_NO" ]    && echo "NO_PROXY=localhost,127.0.0.1,db,redis,waha,ollama,app" >> .env

    export HTTP_PROXY="$PROXY_HTTP" HTTPS_PROXY="$PROXY_HTTPS"
    export http_proxy="$PROXY_HTTP" https_proxy="$PROXY_HTTPS"
    success "Proxy: ${PROXY_HTTP}"
else
    info "Pas de proxy"
fi

# --- Step 1: Pull Ollama image -----------------------------------------------
info "Telechargement de l'image Ollama..."
if $CONTAINER_CMD pull docker.io/ollama/ollama:latest 2>&1; then
    success "Image ollama/ollama:latest telechargee"
else
    error "Impossible de telecharger l'image Ollama. Verifiez votre connexion/proxy."
fi

# --- Step 2: Start Ollama container ------------------------------------------
info "Demarrage du container Ollama..."

# Detect the network name used by zeniclaw
NETWORK_NAME=""
for net in zeniclaw_zeniclaw zeniclaw_default; do
    if $CONTAINER_CMD network inspect "$net" &>/dev/null 2>&1; then
        NETWORK_NAME="$net"
        break
    fi
done
if [ -z "$NETWORK_NAME" ]; then
    NETWORK_NAME=$($CONTAINER_CMD network ls --format '{{.Name}}' 2>/dev/null | grep -i zeniclaw | head -1 || true)
fi

# Create network if it doesn't exist (fresh install)
if [ -z "$NETWORK_NAME" ]; then
    warn "Reseau zeniclaw introuvable, creation..."
    $CONTAINER_CMD network create zeniclaw_zeniclaw 2>/dev/null || true
    NETWORK_NAME="zeniclaw_zeniclaw"
fi

info "Reseau: $NETWORK_NAME"

# Stop existing container if present
if $CONTAINER_CMD inspect zeniclaw_ollama &>/dev/null 2>&1; then
    info "Arret du container existant..."
    $CONTAINER_CMD stop zeniclaw_ollama 2>/dev/null || true
    $CONTAINER_CMD rm zeniclaw_ollama 2>/dev/null || true
fi

# Build a CA bundle for the container (enterprise proxies do SSL interception)
CA_MOUNTS=""
CA_BUNDLE_FILE="$REPO_DIR/.ollama-ca-bundle.pem"

if [ -n "$PROXY_HTTP" ] || [ -n "$PROXY_HTTPS" ]; then
    info "Detection des certificats CA (proxy SSL)..."

    # Start with system CA bundle
    SYS_CA=""
    for ca_path in \
        /etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem \
        /etc/pki/tls/certs/ca-bundle.crt \
        /etc/ssl/certs/ca-certificates.crt \
        /etc/ca-certificates/extracted/tls-ca-bundle.pem; do
        if [ -f "$ca_path" ]; then
            SYS_CA="$ca_path"
            break
        fi
    done

    # Extract proxy's CA certificate automatically via openssl
    PROXY_CA=""
    PROXY_URL="${PROXY_HTTP:-$PROXY_HTTPS}"
    PROXY_HOST=$(echo "$PROXY_URL" | sed 's|https\?://||' | cut -d: -f1)
    PROXY_PORT=$(echo "$PROXY_URL" | sed 's|https\?://||' | cut -d: -f2)

    if command -v openssl &>/dev/null; then
        info "Extraction du certificat CA du proxy via ollama.com..."
        # Connect through proxy to ollama.com and grab the CA chain
        PROXY_CERTS=$(echo | openssl s_client -proxy "$PROXY_HOST:$PROXY_PORT" \
            -connect registry.ollama.ai:443 -showcerts 2>/dev/null || true)

        if echo "$PROXY_CERTS" | grep -q "BEGIN CERTIFICATE"; then
            # Extract all certificates (the last one is usually the root CA)
            PROXY_CA=$(echo "$PROXY_CERTS" | awk '/BEGIN CERTIFICATE/,/END CERTIFICATE/{print}')
            CERT_COUNT=$(echo "$PROXY_CA" | grep -c "BEGIN CERTIFICATE" || echo "0")
            success "Certificats extraits du proxy: $CERT_COUNT"
        else
            warn "Impossible d'extraire les certificats via le proxy"
        fi
    else
        warn "openssl non installe — impossible d'extraire le CA du proxy"
    fi

    # Build combined CA bundle
    if [ -n "$SYS_CA" ] || [ -n "$PROXY_CA" ]; then
        rm -f "$CA_BUNDLE_FILE"
        [ -n "$SYS_CA" ] && cat "$SYS_CA" > "$CA_BUNDLE_FILE"
        if [ -n "$PROXY_CA" ]; then
            echo "" >> "$CA_BUNDLE_FILE"
            echo "# === Proxy CA certificates (auto-extracted) ===" >> "$CA_BUNDLE_FILE"
            echo "$PROXY_CA" >> "$CA_BUNDLE_FILE"
        fi
        CA_MOUNTS="-v ${CA_BUNDLE_FILE}:/etc/ssl/certs/ca-certificates.crt:ro"
        success "Bundle CA construit: $(wc -l < "$CA_BUNDLE_FILE") lignes"
    fi

    if [ -z "$CA_MOUNTS" ]; then
        warn "Pas de certificats CA — les telechargements via proxy SSL risquent d'echouer"
    fi
fi

# Start Ollama (--network-alias ollama so app can reach it via http://ollama:11434)
$CONTAINER_CMD run -d \
    --name zeniclaw_ollama \
    --hostname ollama \
    --network "$NETWORK_NAME" \
    --network-alias ollama \
    --restart unless-stopped \
    -e "HTTP_PROXY=${PROXY_HTTP:-}" \
    -e "HTTPS_PROXY=${PROXY_HTTPS:-}" \
    -e "http_proxy=${PROXY_HTTP:-}" \
    -e "https_proxy=${PROXY_HTTPS:-}" \
    -e "NO_PROXY=${PROXY_NO:-localhost,127.0.0.1,db,redis,waha,ollama,app}" \
    -e "no_proxy=${PROXY_NO:-localhost,127.0.0.1,db,redis,waha,ollama,app}" \
    $CA_MOUNTS \
    -v zeniclaw_ollama_data:/root/.ollama \
    docker.io/ollama/ollama:latest 2>&1

# Verify container is actually running
sleep 2
CONTAINER_STATUS=$($CONTAINER_CMD inspect --format '{{.State.Status}}' zeniclaw_ollama 2>/dev/null || echo "not_found")
if [ "$CONTAINER_STATUS" != "running" ]; then
    warn "Container status: $CONTAINER_STATUS"
    echo -e "${DIM}Logs:${NC}"
    $CONTAINER_CMD logs zeniclaw_ollama 2>&1 | tail -20 || true
    error "Le container Ollama n'a pas demarre. Voir les logs ci-dessus."
fi

# Wait for Ollama API to be ready
info "Attente du demarrage d'Ollama..."
OLLAMA_READY=false
for i in $(seq 1 30); do
    # Try multiple methods: bash tcp check, then wget, then ollama list
    if $CONTAINER_CMD exec zeniclaw_ollama bash -c 'echo > /dev/tcp/localhost/11434' &>/dev/null; then
        OLLAMA_READY=true
        break
    elif $CONTAINER_CMD exec zeniclaw_ollama wget -qO- http://localhost:11434/api/tags &>/dev/null; then
        OLLAMA_READY=true
        break
    fi
    sleep 2
done

if [ "$OLLAMA_READY" = true ]; then
    success "Ollama est pret!"
else
    # Check if container is at least running
    CSTATUS=$($CONTAINER_CMD inspect --format '{{.State.Status}}' zeniclaw_ollama 2>/dev/null || echo "?")
    if [ "$CSTATUS" = "running" ]; then
        warn "Ollama tourne mais API lente au demarrage — on continue quand meme"
    else
        $CONTAINER_CMD logs zeniclaw_ollama 2>&1 | tail -10 || true
        error "Ollama n'a pas demarre (status: $CSTATUS)"
    fi
fi

# --- Step 3: Check existing models ------------------------------------------
info "Modeles installes :"
MODELS=$($CONTAINER_CMD exec zeniclaw_ollama ollama list 2>/dev/null || true)
if [ -n "$MODELS" ] && [ "$(echo "$MODELS" | wc -l)" -gt 1 ]; then
    echo "$MODELS"
else
    echo -e "${DIM}  (aucun modele installe)${NC}"
fi

# --- Step 4: Propose model download -----------------------------------------
echo ""
echo -e "${BOLD}Quel modele voulez-vous installer ?${NC}"
echo ""
echo -e "  ${GREEN}1${NC}) qwen2.5:0.5b   — Ultra-rapide, ~400 Mo RAM  ${DIM}(ideal petite machine)${NC}"
echo -e "  ${GREEN}2${NC}) qwen2.5:1.5b   — Rapide, ~1 Go RAM"
echo -e "  ${GREEN}3${NC}) qwen2.5:3b     — Leger, ~2 Go RAM"
echo -e "  ${GREEN}4${NC}) qwen2.5:7b     — Intelligent, ~4.7 Go RAM"
echo -e "  ${GREEN}5${NC}) qwen2.5:14b    — Puissant, ~9 Go RAM"
echo -e "  ${GREEN}6${NC}) gemma2:2b       — Google, ~1.6 Go RAM"
echo -e "  ${GREEN}7${NC}) llama3.2:3b     — Meta, ~2 Go RAM"
echo -e "  ${GREEN}8${NC}) phi3:mini       — Microsoft, ~2.3 Go RAM"
echo -e "  ${GREEN}9${NC}) qwen2.5-coder:7b — Code, ~4.7 Go RAM"
echo -e "  ${GREEN}0${NC}) Aucun (installer plus tard via Settings)"
echo ""

# Check available RAM to suggest
TOTAL_RAM_MB=$(free -m 2>/dev/null | awk '/^Mem:/{print $2}' || echo "0")
if [ "$TOTAL_RAM_MB" -gt 0 ]; then
    echo -e "${DIM}RAM totale: ~$((TOTAL_RAM_MB / 1024)) Go${NC}"
    if [ "$TOTAL_RAM_MB" -lt 2048 ]; then
        echo -e "${YELLOW}Recommande: qwen2.5:0.5b (option 1)${NC}"
    elif [ "$TOTAL_RAM_MB" -lt 4096 ]; then
        echo -e "${YELLOW}Recommande: qwen2.5:1.5b ou qwen2.5:3b (option 2-3)${NC}"
    elif [ "$TOTAL_RAM_MB" -lt 8192 ]; then
        echo -e "${YELLOW}Recommande: qwen2.5:7b (option 4)${NC}"
    else
        echo -e "${YELLOW}Recommande: qwen2.5:14b (option 5)${NC}"
    fi
    echo ""
fi

read -rp "Votre choix [1-9, 0=aucun] : " MODEL_CHOICE

MODEL_NAME=""
case "$MODEL_CHOICE" in
    1) MODEL_NAME="qwen2.5:0.5b" ;;
    2) MODEL_NAME="qwen2.5:1.5b" ;;
    3) MODEL_NAME="qwen2.5:3b" ;;
    4) MODEL_NAME="qwen2.5:7b" ;;
    5) MODEL_NAME="qwen2.5:14b" ;;
    6) MODEL_NAME="gemma2:2b" ;;
    7) MODEL_NAME="llama3.2:3b" ;;
    8) MODEL_NAME="phi3:mini" ;;
    9) MODEL_NAME="qwen2.5-coder:7b" ;;
    0|"") info "Aucun modele installe. Vous pourrez en telecharger via Settings > Modeles." ;;
    *) warn "Choix invalide, aucun modele installe." ;;
esac

if [ -n "$MODEL_NAME" ]; then
    info "Telechargement de ${MODEL_NAME}... (peut prendre plusieurs minutes)"
    if $CONTAINER_CMD exec zeniclaw_ollama ollama pull "$MODEL_NAME" 2>&1; then
        success "Modele ${MODEL_NAME} installe!"
    else
        error "Echec du telechargement de ${MODEL_NAME}. Verifiez proxy/connexion."
    fi
fi

# --- Step 5: Configure app to use Ollama ------------------------------------
info "Configuration de ZeniClaw pour utiliser Ollama..."
if $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
    $CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
        \App\Models\AppSetting::set('onprem_api_url', 'http://ollama:11434');
        echo 'Ollama URL configured';
    " 2>/dev/null || true

    # Set the downloaded model as 'fast' role if one was chosen
    if [ -n "$MODEL_NAME" ]; then
        $CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
            \App\Models\AppSetting::set('model_role_fast', '${MODEL_NAME}');
            echo 'Model ${MODEL_NAME} set as fast role';
        " 2>/dev/null || true
    fi

    # Sync proxy to DB
    if [ -n "$PROXY_HTTP" ] || [ -n "$PROXY_HTTPS" ]; then
        $CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
            \App\Models\AppSetting::set('proxy_http', '${PROXY_HTTP}');
            \App\Models\AppSetting::set('proxy_https', '${PROXY_HTTPS}');
            \App\Models\AppSetting::set('proxy_no_proxy', '${PROXY_NO:-localhost,127.0.0.1,db,redis,waha,ollama,app}');
        " 2>/dev/null || true
    fi
    success "App configuree"
else
    warn "Container app non demarre — configurez manuellement dans Settings > Modeles"
fi

# --- Step 6: Verification ---------------------------------------------------
echo ""
echo -e "${BOLD}${CYAN}=== Verification ===${NC}\n"

# Check container
OLLAMA_STATUS=$($CONTAINER_CMD inspect --format '{{.State.Status}}' zeniclaw_ollama 2>/dev/null || echo "introuvable")
if [ "$OLLAMA_STATUS" = "running" ]; then
    success "Container: zeniclaw_ollama (running)"
else
    warn "Container: zeniclaw_ollama ($OLLAMA_STATUS)"
fi

# Check API via ollama list
if $CONTAINER_CMD exec zeniclaw_ollama ollama list &>/dev/null; then
    success "API Ollama: accessible"
else
    warn "API Ollama: non accessible"
fi

# Check models
INSTALLED=$($CONTAINER_CMD exec zeniclaw_ollama ollama list 2>/dev/null | tail -n +2 || true)
if [ -n "$INSTALLED" ]; then
    success "Modeles installes:"
    echo "$INSTALLED" | while read -r line; do
        echo -e "  ${GREEN}•${NC} $line"
    done
else
    warn "Aucun modele installe"
fi

# Check app connectivity (app container has curl)
if $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
    OLLAMA_REACHABLE=$($CONTAINER_CMD exec zeniclaw_app curl -sf http://ollama:11434/api/tags 2>/dev/null && echo "ok" || echo "fail")
    if [ "$OLLAMA_REACHABLE" = "ok" ]; then
        success "Connectivite app -> ollama: OK"
    else
        warn "Connectivite app -> ollama: ECHEC (verifiez que les containers sont sur le meme reseau)"
    fi
fi

# Proxy
if [ -n "$PROXY_HTTP" ] || [ -n "$PROXY_HTTPS" ]; then
    success "Proxy: ${PROXY_HTTP:-$PROXY_HTTPS}"
else
    info "Proxy: non configure"
fi

echo -e "\n${GREEN}${BOLD}=== Ollama On-Prem pret! ===${NC}"
echo -e "${DIM}Gerez vos modeles dans Settings > Modeles On-Prem${NC}\n"
