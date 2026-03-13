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
PROXY_NO=""
PROXY_BASE=""

# Load base URL from .env (stored WITHOUT credentials)
if [ -f .env ]; then
    PROXY_BASE=$(grep -oP '^PROXY_BASE_URL=\K.*' .env 2>/dev/null || true)
    PROXY_NO=$(grep -oP '^NO_PROXY=\K.*' .env 2>/dev/null || true)
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
    echo -e "${YELLOW}Etes-vous derriere un proxy ?${NC}"
    echo -e "${DIM}Necessaire pour telecharger l'image Ollama et les modeles.${NC}"
    echo -e "${DIM}Exemple: http://proxy.entreprise.com:8080${NC}"
    read -rp "Proxy URL (vide = pas de proxy) : " PROXY_BASE
fi

# Always ask for credentials (never stored in .env)
if [ -n "$PROXY_BASE" ]; then
    echo ""
    echo -e "${CYAN}Proxy: $PROXY_BASE${NC}"
    echo -e "${YELLOW}Login proxy (vide = pas d'authentification) :${NC}"
    read -rp "Login : " PROXY_USER
    if [ -n "$PROXY_USER" ]; then
        read -rsp "Mot de passe : " PROXY_PASS
        echo ""
        # URL-encode user and password to handle special chars (@, :, #, etc.)
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

# Persist proxy to .env (base URL without creds + full URLs for containers)
if [ -n "$PROXY_BASE" ]; then
    touch .env
    for VAR_NAME in HTTP_PROXY HTTPS_PROXY NO_PROXY PROXY_BASE_URL; do
        sed -i "/^${VAR_NAME}=/d" .env
    done
    echo "PROXY_BASE_URL=${PROXY_BASE}" >> .env
    echo "HTTP_PROXY=${PROXY_HTTP}" >> .env
    echo "HTTPS_PROXY=${PROXY_HTTPS}" >> .env
    echo "NO_PROXY=${PROXY_NO}" >> .env

    export HTTP_PROXY="$PROXY_HTTP" HTTPS_PROXY="$PROXY_HTTPS"
    export http_proxy="$PROXY_HTTP" https_proxy="$PROXY_HTTPS"
    success "Proxy: $PROXY_BASE"
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
    -e "OLLAMA_KEEP_ALIVE=-1" \
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

# --- Step 3: Check for offline model files to import -----------------------
MODELS_DIR="$REPO_DIR/models"
GGUF_FILES=""
TARGZ_FILES=""
if [ -d "$MODELS_DIR" ]; then
    GGUF_FILES=$(find "$MODELS_DIR" -name "*.gguf" -type f 2>/dev/null || true)
    TARGZ_FILES=$(find "$MODELS_DIR" -name "ollama-*.tar.gz" -type f 2>/dev/null || true)
fi

# --- 3a: Import tar.gz exports (from ZeniClaw /models mirror) ---------------
if [ -n "$TARGZ_FILES" ]; then
    info "Archives Ollama trouvees dans models/ :"
    echo "$TARGZ_FILES" | while read -r f; do
        SIZE=$(du -h "$f" 2>/dev/null | awk '{print $1}')
        echo -e "  ${GREEN}•${NC} $(basename "$f") ($SIZE)"
    done
    echo ""
    echo -e "${YELLOW}Importer ces modeles dans Ollama ?${NC}"
    echo -e "${DIM}(Format export ZeniClaw — contient blobs + manifeste)${NC}"
    read -rp "Importer ? (O/n) : " IMPORT_TARGZ
    if [[ ! "$IMPORT_TARGZ" =~ ^[nN]$ ]]; then
        echo "$TARGZ_FILES" | while read -r tgz_file; do
            TGZ_BASENAME=$(basename "$tgz_file")
            info "Import de $TGZ_BASENAME..."

            # Extract to temp dir
            TMPDIR_EXTRACT=$(mktemp -d)
            if ! tar xzf "$tgz_file" -C "$TMPDIR_EXTRACT" 2>&1; then
                warn "Echec extraction de $TGZ_BASENAME"
                rm -rf "$TMPDIR_EXTRACT"
                continue
            fi

            # Copy blobs into Ollama data dir
            if [ -d "$TMPDIR_EXTRACT/blobs" ]; then
                BLOB_COUNT=$(ls "$TMPDIR_EXTRACT/blobs/" | wc -l)
                info "Copie de $BLOB_COUNT blobs..."
                $CONTAINER_CMD exec zeniclaw_ollama mkdir -p /root/.ollama/models/blobs 2>/dev/null || true
                for blob in "$TMPDIR_EXTRACT/blobs/"*; do
                    BLOB_NAME=$(basename "$blob")
                    $CONTAINER_CMD cp "$blob" "zeniclaw_ollama:/root/.ollama/models/blobs/$BLOB_NAME" 2>&1 || true
                done
            fi

            # Copy manifests into Ollama data dir
            if [ -d "$TMPDIR_EXTRACT/manifests" ]; then
                info "Copie des manifestes..."
                # Walk the manifest tree and recreate dirs in container
                find "$TMPDIR_EXTRACT/manifests" -type f 2>/dev/null | while read -r manifest_file; do
                    REL_PATH="${manifest_file#$TMPDIR_EXTRACT/}"
                    DEST_DIR="/root/.ollama/models/$(dirname "$REL_PATH")"
                    $CONTAINER_CMD exec zeniclaw_ollama mkdir -p "$DEST_DIR" 2>/dev/null || true
                    $CONTAINER_CMD cp "$manifest_file" "zeniclaw_ollama:/root/.ollama/models/$REL_PATH" 2>&1 || true
                done
            fi

            # Detect imported model name from manifest path
            MODEL_TAG=$(find "$TMPDIR_EXTRACT" -path "*/manifests/registry.ollama.ai/library/*/*" -type f 2>/dev/null | head -1 | sed 's|.*/library/||' | tr '/' ':' || true)

            rm -rf "$TMPDIR_EXTRACT"
            success "Modele $TGZ_BASENAME importe! ${MODEL_TAG:+(${MODEL_TAG})}"
        done
    fi
fi

# --- 3b: Import GGUF files -------------------------------------------------

if [ -n "$GGUF_FILES" ]; then
    info "Fichiers GGUF trouves dans models/ :"
    echo "$GGUF_FILES" | while read -r f; do
        SIZE=$(du -h "$f" 2>/dev/null | awk '{print $1}')
        echo -e "  ${GREEN}•${NC} $(basename "$f") ($SIZE)"
    done
    echo ""
    echo -e "${YELLOW}Importer ces modeles dans Ollama ?${NC}"
    read -rp "Importer ? (O/n) : " IMPORT_CHOICE
    if [[ ! "$IMPORT_CHOICE" =~ ^[nN]$ ]]; then
        echo "$GGUF_FILES" | while read -r gguf_file; do
            GGUF_BASENAME=$(basename "$gguf_file")
            # Ollama model names: only lowercase letters, numbers and hyphens
            GGUF_NAME=$(echo "${GGUF_BASENAME%.gguf}" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9-]/-/g' | sed 's/--*/-/g' | sed 's/^-//;s/-$//')
            info "Import de $GGUF_NAME..."

            # Create /models dir in container
            $CONTAINER_CMD exec zeniclaw_ollama mkdir -p /models 2>&1 || true

            # Copy GGUF into container
            info "Copie de $GGUF_BASENAME dans le container..."
            $CONTAINER_CMD cp "$gguf_file" "zeniclaw_ollama:/models/$GGUF_BASENAME" 2>&1 || true

            # Verify file is in container
            FILE_SIZE=$($CONTAINER_CMD exec zeniclaw_ollama ls -lh "/models/$GGUF_BASENAME" 2>/dev/null | awk '{print $5}' || echo "absent")
            info "Fichier dans container: /models/$GGUF_BASENAME ($FILE_SIZE)"

            if [ "$FILE_SIZE" = "absent" ]; then
                warn "Le fichier n'a pas ete copie dans le container"
                continue
            fi

            # Create Modelfile inside the container
            $CONTAINER_CMD exec zeniclaw_ollama sh -c "printf 'FROM /models/$GGUF_BASENAME\n' > /tmp/Modelfile" 2>&1

            # Create the model
            info "Creation du modele $GGUF_NAME..."
            if $CONTAINER_CMD exec -e OLLAMA_HOST=http://127.0.0.1:11434 zeniclaw_ollama ollama create "$GGUF_NAME" -f /tmp/Modelfile 2>&1; then
                success "Modele $GGUF_NAME importe!"
            else
                warn "Echec import de $GGUF_NAME"
                $CONTAINER_CMD logs --tail 5 zeniclaw_ollama 2>&1 | grep -i "error\|warn\|fail" | tail -3
            fi
        done
    fi
fi

# --- Step 4: Check existing models ------------------------------------------
info "Modeles installes :"
MODELS=$($CONTAINER_CMD exec -e OLLAMA_HOST=http://127.0.0.1:11434 zeniclaw_ollama ollama list 2>/dev/null || true)
if [ -n "$MODELS" ] && [ "$(echo "$MODELS" | wc -l)" -gt 1 ]; then
    echo "$MODELS"
else
    echo -e "${DIM}  (aucun modele installe)${NC}"
fi

# --- Step 5: Propose model download -----------------------------------------
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
if [ "$TOTAL_RAM_MB" -gt 0 ] 2>/dev/null; then
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
    if $CONTAINER_CMD exec -e OLLAMA_HOST=http://127.0.0.1:11434 zeniclaw_ollama ollama pull "$MODEL_NAME" 2>&1; then
        success "Modele ${MODEL_NAME} installe!"
    else
        warn "Echec du telechargement automatique."
        echo ""
        echo -e "${BOLD}${YELLOW}=== Telechargement manuel ===${NC}"
        echo -e "Le proxy bloque probablement le telechargement. Voici comment faire manuellement :"
        echo ""
        echo -e "${BOLD}Option A: Telecharger le fichier GGUF depuis un PC sans proxy${NC}"

        # Map model names to HuggingFace GGUF URLs
        case "$MODEL_NAME" in
            qwen2.5:0.5b)   HF_URL="https://huggingface.co/Qwen/Qwen2.5-0.5B-Instruct-GGUF/resolve/main/qwen2.5-0.5b-instruct-q4_k_m.gguf" ;;
            qwen2.5:1.5b)   HF_URL="https://huggingface.co/Qwen/Qwen2.5-1.5B-Instruct-GGUF/resolve/main/qwen2.5-1.5b-instruct-q4_k_m.gguf" ;;
            qwen2.5:3b)     HF_URL="https://huggingface.co/Qwen/Qwen2.5-3B-Instruct-GGUF/resolve/main/qwen2.5-3b-instruct-q4_k_m.gguf" ;;
            qwen2.5:7b)     HF_URL="https://huggingface.co/Qwen/Qwen2.5-7B-Instruct-GGUF/resolve/main/qwen2.5-7b-instruct-q4_k_m.gguf" ;;
            qwen2.5:14b)    HF_URL="https://huggingface.co/Qwen/Qwen2.5-14B-Instruct-GGUF/resolve/main/qwen2.5-14b-instruct-q4_k_m.gguf" ;;
            gemma2:2b)      HF_URL="https://huggingface.co/google/gemma-2-2b-it-GGUF/resolve/main/gemma-2-2b-it.Q4_K_M.gguf" ;;
            llama3.2:3b)    HF_URL="https://huggingface.co/bartowski/Llama-3.2-3B-Instruct-GGUF/resolve/main/Llama-3.2-3B-Instruct-Q4_K_M.gguf" ;;
            phi3:mini)      HF_URL="https://huggingface.co/microsoft/Phi-3-mini-4k-instruct-gguf/resolve/main/Phi-3-mini-4k-instruct-q4.gguf" ;;
            qwen2.5-coder:7b) HF_URL="https://huggingface.co/Qwen/Qwen2.5-Coder-7B-Instruct-GGUF/resolve/main/qwen2.5-coder-7b-instruct-q4_k_m.gguf" ;;
            *) HF_URL="" ;;
        esac

        echo ""
        echo -e "  1. Telechargez le modele depuis un poste sans restriction :"
        if [ -n "$HF_URL" ]; then
            echo -e "     ${CYAN}${HF_URL}${NC}"
        else
            echo -e "     ${CYAN}https://huggingface.co/models?search=${MODEL_NAME%%:*}+GGUF${NC}"
        fi
        echo ""
        echo -e "  2. Copiez le fichier .gguf sur ce serveur dans le dossier models/ :"
        echo -e "     ${DIM}mkdir -p $REPO_DIR/models${NC}"
        echo -e "     ${DIM}scp modele.gguf user@serveur:$REPO_DIR/models/${NC}"
        echo ""
        echo -e "  3. Relancez ce script — il detectera et importera le fichier automatiquement :"
        echo -e "     ${DIM}sudo bash setup-ollama.sh${NC}"
        echo ""
        echo -e "${BOLD}Option B: Importer maintenant un fichier GGUF deja present${NC}"
        echo ""
        read -rp "Chemin vers un fichier .gguf (vide = passer) : " GGUF_PATH
        if [ -n "$GGUF_PATH" ] && [ -f "$GGUF_PATH" ]; then
            IMPORT_NAME=$(basename "$GGUF_PATH" .gguf | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9._-]/-/g')
            IMPORT_BASENAME=$(basename "$GGUF_PATH")
            info "Import de $IMPORT_NAME depuis $GGUF_PATH..."
            $CONTAINER_CMD exec zeniclaw_ollama mkdir -p /models 2>&1 || true
            $CONTAINER_CMD cp "$GGUF_PATH" "zeniclaw_ollama:/models/$IMPORT_BASENAME" 2>&1
            $CONTAINER_CMD exec zeniclaw_ollama bash -c "echo 'FROM /models/$IMPORT_BASENAME' > /tmp/Modelfile" 2>&1
            if $CONTAINER_CMD exec -e OLLAMA_HOST=http://127.0.0.1:11434 zeniclaw_ollama ollama create "$IMPORT_NAME" -f /tmp/Modelfile 2>&1; then
                success "Modele $IMPORT_NAME importe!"
                MODEL_NAME="$IMPORT_NAME"
            else
                warn "Echec de l'import"
                echo -e "${DIM}Logs:${NC}"
                $CONTAINER_CMD logs --tail 10 zeniclaw_ollama 2>&1 | tail -5
            fi
        elif [ -n "$GGUF_PATH" ]; then
            warn "Fichier introuvable: $GGUF_PATH"
        fi

        echo ""
        echo -e "${BOLD}Option C: Telecharger depuis un serveur ZeniClaw (/models)${NC}"
        echo -e "${DIM}Si un autre serveur ZeniClaw a le modele, telechargez l'archive depuis sa page /models.${NC}"
        echo ""
        echo -e "  1. Telechargez l'archive .tar.gz depuis ${CYAN}http://SERVEUR:8080/models${NC}"
        echo -e "  2. Placez-la dans ${DIM}$REPO_DIR/models/${NC}"
        echo -e "  3. Relancez ce script — il detectera et importera l'archive automatiquement"
        echo ""
        echo -e "${DIM}Ou importez maintenant :${NC}"
        read -rp "Chemin vers un fichier ollama-*.tar.gz (vide = passer) : " TARGZ_PATH
        if [ -n "$TARGZ_PATH" ] && [ -f "$TARGZ_PATH" ]; then
            info "Import de $(basename "$TARGZ_PATH")..."
            TMPDIR_IMPORT=$(mktemp -d)
            if tar xzf "$TARGZ_PATH" -C "$TMPDIR_IMPORT" 2>&1; then
                # Copy blobs
                if [ -d "$TMPDIR_IMPORT/blobs" ]; then
                    BLOB_COUNT=$(ls "$TMPDIR_IMPORT/blobs/" | wc -l)
                    info "Copie de $BLOB_COUNT blobs..."
                    $CONTAINER_CMD exec zeniclaw_ollama mkdir -p /root/.ollama/models/blobs 2>/dev/null || true
                    for blob in "$TMPDIR_IMPORT/blobs/"*; do
                        BLOB_NAME=$(basename "$blob")
                        $CONTAINER_CMD cp "$blob" "zeniclaw_ollama:/root/.ollama/models/blobs/$BLOB_NAME" 2>&1 || true
                    done
                fi
                # Copy manifests
                if [ -d "$TMPDIR_IMPORT/manifests" ]; then
                    info "Copie des manifestes..."
                    find "$TMPDIR_IMPORT/manifests" -type f 2>/dev/null | while read -r mf; do
                        REL_P="${mf#$TMPDIR_IMPORT/}"
                        DEST_D="/root/.ollama/models/$(dirname "$REL_P")"
                        $CONTAINER_CMD exec zeniclaw_ollama mkdir -p "$DEST_D" 2>/dev/null || true
                        $CONTAINER_CMD cp "$mf" "zeniclaw_ollama:/root/.ollama/models/$REL_P" 2>&1 || true
                    done
                fi
                IMPORTED_TAG=$(find "$TMPDIR_IMPORT" -path "*/library/*/*" -type f 2>/dev/null | head -1 | sed 's|.*/library/||' | tr '/' ':' || true)
                success "Modele importe! ${IMPORTED_TAG:+(${IMPORTED_TAG})}"
                [ -n "$IMPORTED_TAG" ] && MODEL_NAME="$IMPORTED_TAG"
            else
                warn "Echec extraction de $(basename "$TARGZ_PATH")"
            fi
            rm -rf "$TMPDIR_IMPORT"
        elif [ -n "$TARGZ_PATH" ]; then
            warn "Fichier introuvable: $TARGZ_PATH"
        fi
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

# --- Step 5b: Warm-up model (pre-load in memory) ---------------------------
if [ -n "$MODEL_NAME" ]; then
    info "Pre-chargement du modele en memoire (OLLAMA_KEEP_ALIVE=-1)..."
    $CONTAINER_CMD exec zeniclaw_ollama curl -sf http://localhost:11434/api/generate \
        -d "{\"model\": \"${MODEL_NAME}\", \"keep_alive\": -1, \"prompt\": \"hi\"}" \
        > /dev/null 2>&1 &
    WARMUP_PID=$!
    # Wait up to 30s for warm-up
    for i in $(seq 1 15); do
        if ! kill -0 $WARMUP_PID 2>/dev/null; then break; fi
        sleep 2
    done
    kill $WARMUP_PID 2>/dev/null || true
    success "Modele pre-charge — plus de cold start"
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
if $CONTAINER_CMD exec -e OLLAMA_HOST=http://127.0.0.1:11434 zeniclaw_ollama ollama list &>/dev/null; then
    success "API Ollama: accessible"
else
    warn "API Ollama: non accessible"
fi

# Check models
INSTALLED=$($CONTAINER_CMD exec -e OLLAMA_HOST=http://127.0.0.1:11434 zeniclaw_ollama ollama list 2>/dev/null | tail -n +2 || true)
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
