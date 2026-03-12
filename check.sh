#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# ZeniClaw — Diagnostic & Health Check
# Verifie que tous les composants sont correctement configures.
# Usage: ./check.sh
# ============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
DIM='\033[2m'
BOLD='\033[1m'
NC='\033[0m'

PASS=0
WARN=0
FAIL=0

pass() { echo -e "  ${GREEN}✓${NC} $1"; ((PASS++)); }
fail() { echo -e "  ${RED}✗${NC} $1"; ((FAIL++)); }
skip() { echo -e "  ${YELLOW}~${NC} $1"; ((WARN++)); }
header() { echo -e "\n${BOLD}${CYAN}[$1]${NC}"; }

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_DIR"

echo -e "\n${BOLD}${CYAN}=== ZeniClaw — Diagnostic ===${NC}"
echo -e "${DIM}$(date '+%Y-%m-%d %H:%M:%S')${NC}\n"

# ── 1. Container Runtime ────────────────────────────────────────────────────
header "Container Runtime"

CONTAINER_CMD=""
if command -v podman &>/dev/null && podman info &>/dev/null 2>&1; then
    CONTAINER_CMD="podman"
    VER=$(podman --version 2>/dev/null | awk '{print $NF}')
    pass "Podman $VER"
elif command -v docker &>/dev/null; then
    CONTAINER_CMD="docker"
    VER=$(docker --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)
    pass "Docker $VER"
else
    fail "Aucun runtime (podman/docker)"
fi

# Check rootless vs root
if [ -n "$CONTAINER_CMD" ] && [ "$(id -u)" = "0" ]; then
    skip "Running as root — si vos containers sont rootless, relancez sans sudo"
fi

# ── 2. Containers ───────────────────────────────────────────────────────────
header "Containers"

check_container() {
    local name="$1"
    local required="$2"
    if [ -z "$CONTAINER_CMD" ]; then
        fail "$name — pas de runtime"
        return
    fi
    local status
    status=$($CONTAINER_CMD inspect --format '{{.State.Status}}' "$name" 2>/dev/null || echo "not_found")
    if [ "$status" = "running" ]; then
        # Get uptime
        local started
        started=$($CONTAINER_CMD inspect --format '{{.State.StartedAt}}' "$name" 2>/dev/null | cut -d. -f1 || true)
        pass "$name (running, started: ${started:-unknown})"
    elif [ "$status" = "not_found" ]; then
        if [ "$required" = "required" ]; then
            fail "$name — container introuvable"
        else
            skip "$name — non installe (optionnel)"
        fi
    else
        fail "$name — status: $status"
    fi
}

check_container "zeniclaw_app"    "required"
check_container "zeniclaw_db"     "required"
check_container "zeniclaw_redis"  "required"
check_container "zeniclaw_waha"   "required"
check_container "zeniclaw_ollama" "optional"

# ── 3. Network ──────────────────────────────────────────────────────────────
header "Network"

if [ -n "$CONTAINER_CMD" ]; then
    NETS=$($CONTAINER_CMD network ls --format '{{.Name}}' 2>/dev/null | grep -i zeniclaw || true)
    if [ -n "$NETS" ]; then
        pass "Reseau: $NETS"

        # Check all running containers are on the same network
        EXPECTED_NET=$(echo "$NETS" | head -1)
        for cname in zeniclaw_app zeniclaw_db zeniclaw_redis zeniclaw_ollama; do
            if $CONTAINER_CMD inspect "$cname" &>/dev/null 2>&1; then
                CNET=$($CONTAINER_CMD inspect --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$cname" 2>/dev/null || true)
                if echo "$CNET" | grep -q "$EXPECTED_NET"; then
                    pass "$cname -> $EXPECTED_NET"
                else
                    fail "$cname sur reseau '$CNET' (attendu: $EXPECTED_NET)"
                fi
            fi
        done
    else
        fail "Aucun reseau zeniclaw trouve"
    fi
fi

# ── 4. Database ─────────────────────────────────────────────────────────────
header "Database"

if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
    # Connection
    DB_OK=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
        try { \DB::connection()->getPdo(); echo 'ok'; } catch(\Exception \$e) { echo 'fail:' . \$e->getMessage(); }
    " 2>/dev/null || echo "fail:container error")
    if [ "$DB_OK" = "ok" ]; then
        pass "Connexion PostgreSQL"
    else
        fail "Connexion PostgreSQL — ${DB_OK#fail:}"
    fi

    # Migrations
    PENDING=$($CONTAINER_CMD exec zeniclaw_app php artisan migrate:status --no-ansi 2>/dev/null | grep -c "No" || echo "0")
    if [ "$PENDING" = "0" ]; then
        pass "Migrations: toutes appliquees"
    else
        fail "Migrations: $PENDING en attente"
    fi

    # Users
    USER_COUNT=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null || echo "?")
    if [ "$USER_COUNT" -gt 0 ] 2>/dev/null; then
        pass "Utilisateurs: $USER_COUNT"
    else
        fail "Aucun utilisateur (db:seed manquant ?)"
    fi

    # Agents
    AGENT_COUNT=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\Agent::count();" 2>/dev/null || echo "?")
    if [ "$AGENT_COUNT" -gt 0 ] 2>/dev/null; then
        pass "Agents: $AGENT_COUNT"
    else
        fail "Aucun agent configure"
    fi
else
    skip "App non disponible — tests DB ignores"
fi

# ── 5. Redis ────────────────────────────────────────────────────────────────
header "Redis"

if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_redis &>/dev/null 2>&1; then
    REDIS_PING=$($CONTAINER_CMD exec zeniclaw_redis redis-cli ping 2>/dev/null || echo "fail")
    if [ "$REDIS_PING" = "PONG" ]; then
        pass "Redis: PONG"
    else
        fail "Redis ne repond pas"
    fi

    # Queue workers
    if $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
        QUEUE_SIZE=$($CONTAINER_CMD exec zeniclaw_redis redis-cli llen queues:default 2>/dev/null || echo "?")
        pass "Queue default: $QUEUE_SIZE jobs en attente"
    fi
else
    skip "Redis non disponible"
fi

# ── 6. Queue Workers ───────────────────────────────────────────────────────
header "Queue Workers"

if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
    WORKERS=$($CONTAINER_CMD exec zeniclaw_app ps aux 2>/dev/null | grep -c "[q]ueue:work" || echo "0")
    if [ "$WORKERS" -gt 0 ]; then
        pass "Workers actifs: $WORKERS"
    else
        fail "Aucun queue worker actif"
    fi

    # Scheduler
    SCHEDULER=$($CONTAINER_CMD exec zeniclaw_app ps aux 2>/dev/null | grep -c "[s]chedule:work" || echo "0")
    if [ "$SCHEDULER" -gt 0 ]; then
        pass "Scheduler actif"
    else
        fail "Scheduler non actif"
    fi
else
    skip "App non disponible"
fi

# ── 7. WhatsApp (WAHA) ─────────────────────────────────────────────────────
header "WhatsApp (WAHA)"

if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_waha &>/dev/null 2>&1; then
    # API accessible from app
    WAHA_STATUS=$($CONTAINER_CMD exec zeniclaw_app curl -sf -H "X-Api-Key: zeniclaw-waha-2026" http://waha:3000/api/sessions/default 2>/dev/null || echo "fail")
    if [ "$WAHA_STATUS" != "fail" ]; then
        SESSION_STATUS=$(echo "$WAHA_STATUS" | grep -oP '"status"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
        case "$SESSION_STATUS" in
            WORKING)  pass "Session WhatsApp: WORKING (connecte)" ;;
            SCAN_QR*) skip "Session WhatsApp: QR code en attente" ;;
            STARTING) skip "Session WhatsApp: en cours de demarrage" ;;
            STOPPED)  fail "Session WhatsApp: STOPPED" ;;
            FAILED)   fail "Session WhatsApp: FAILED" ;;
            *)        skip "Session WhatsApp: $SESSION_STATUS" ;;
        esac
    else
        fail "WAHA API non accessible depuis l'app"
    fi
else
    skip "WAHA non disponible"
fi

# ── 8. Ollama (On-Prem) ────────────────────────────────────────────────────
header "Ollama (On-Prem)"

if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_ollama &>/dev/null 2>&1; then
    OLLAMA_STATUS=$($CONTAINER_CMD inspect --format '{{.State.Status}}' zeniclaw_ollama 2>/dev/null || echo "stopped")
    if [ "$OLLAMA_STATUS" = "running" ]; then
        pass "Container Ollama running"

        # API check via ollama list
        if $CONTAINER_CMD exec zeniclaw_ollama ollama list &>/dev/null; then
            pass "API Ollama accessible"

            # Models
            MODELS=$($CONTAINER_CMD exec zeniclaw_ollama ollama list 2>/dev/null | tail -n +2 || true)
            if [ -n "$MODELS" ]; then
                MODEL_COUNT=$(echo "$MODELS" | wc -l)
                pass "Modeles installes: $MODEL_COUNT"
                echo "$MODELS" | while read -r line; do
                    echo -e "    ${DIM}$line${NC}"
                done
            else
                skip "Aucun modele installe (./setup-ollama.sh pour en ajouter)"
            fi
        else
            fail "API Ollama non accessible"
        fi

        # Connectivity from app
        if $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
            APP_TO_OLLAMA=$($CONTAINER_CMD exec zeniclaw_app curl -sf -m 5 http://ollama:11434/api/tags 2>/dev/null && echo "ok" || echo "fail")
            if [ "$APP_TO_OLLAMA" = "ok" ]; then
                pass "Connectivite app -> ollama"
            else
                fail "App ne peut pas joindre ollama (reseau different ? root vs rootless ?)"
            fi
        fi

        # RAM usage
        OLLAMA_MEM=$($CONTAINER_CMD stats --no-stream --format '{{.MemUsage}}' zeniclaw_ollama 2>/dev/null || true)
        [ -n "$OLLAMA_MEM" ] && pass "Memoire Ollama: $OLLAMA_MEM"
    else
        fail "Container Ollama: $OLLAMA_STATUS"
    fi
else
    skip "Ollama non installe (optionnel — ./setup-ollama.sh pour installer)"
fi

# ── 9. Anthropic API ───────────────────────────────────────────────────────
header "API Claude (Anthropic)"

if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
    API_KEY=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\AppSetting::get('anthropic_api_key') ? 'set' : 'missing';" 2>/dev/null || echo "?")
    if [ "$API_KEY" = "set" ]; then
        pass "Cle API Anthropic configuree"

        # Model roles
        MODELS=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
            \$r = \App\Services\ModelResolver::current();
            echo 'fast=' . \$r['fast'] . ' balanced=' . \$r['balanced'] . ' powerful=' . \$r['powerful'];
        " 2>/dev/null || echo "?")
        pass "Modeles: $MODELS"
    else
        skip "Cle API Anthropic non configuree (Settings > API)"
    fi
else
    skip "App non disponible"
fi

# ── 10. Proxy ──────────────────────────────────────────────────────────────
header "Proxy"

PROXY_HTTP=""
if [ -f .env ]; then
    PROXY_HTTP=$(grep -oP '^HTTP_PROXY=\K.*' .env 2>/dev/null || true)
    PROXY_HTTPS=$(grep -oP '^HTTPS_PROXY=\K.*' .env 2>/dev/null || true)
fi

if [ -n "$PROXY_HTTP" ]; then
    pass "HTTP_PROXY: $PROXY_HTTP"
    [ -n "$PROXY_HTTPS" ] && pass "HTTPS_PROXY: $PROXY_HTTPS"

    # Check proxy reachable
    PROXY_HOST=$(echo "$PROXY_HTTP" | sed 's|https\?://||' | cut -d: -f1)
    PROXY_PORT=$(echo "$PROXY_HTTP" | sed 's|https\?://||' | cut -d: -f2)
    if timeout 5 bash -c "echo > /dev/tcp/$PROXY_HOST/$PROXY_PORT" 2>/dev/null; then
        pass "Proxy joignable ($PROXY_HOST:$PROXY_PORT)"
    else
        fail "Proxy injoignable ($PROXY_HOST:$PROXY_PORT)"
    fi
else
    skip "Pas de proxy configure"
fi

# ── 11. Disk & Version ─────────────────────────────────────────────────────
header "Systeme"

# Version
if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_app &>/dev/null 2>&1; then
    VERSION=$($CONTAINER_CMD exec zeniclaw_app cat /var/www/html/storage/app/version.txt 2>/dev/null || echo "?")
    pass "Version: v$VERSION"
fi

# Disk
DISK_USAGE=$(df -h "$REPO_DIR" 2>/dev/null | tail -1 | awk '{print $5 " utilise sur " $2 " (dispo: " $4 ")"}')
[ -n "$DISK_USAGE" ] && pass "Disque: $DISK_USAGE"

# RAM
TOTAL_RAM=$(free -h 2>/dev/null | awk '/^Mem:/{print $2}' || echo "?")
USED_RAM=$(free -h 2>/dev/null | awk '/^Mem:/{print $3}' || echo "?")
pass "RAM: $USED_RAM / $TOTAL_RAM"

# Git
GIT_BRANCH=$(git -C "$REPO_DIR" branch --show-current 2>/dev/null || echo "?")
GIT_COMMIT=$(git -C "$REPO_DIR" log -1 --format='%h %s' 2>/dev/null || echo "?")
pass "Git: $GIT_BRANCH ($GIT_COMMIT)"

# ── Summary ─────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}=== Resultat ===${NC}"
echo -e "  ${GREEN}✓ $PASS passes${NC}  ${YELLOW}~ $WARN warnings${NC}  ${RED}✗ $FAIL echecs${NC}"

if [ "$FAIL" -gt 0 ]; then
    echo -e "\n${RED}${BOLD}Des problemes ont ete detectes.${NC}"
    exit 1
elif [ "$WARN" -gt 0 ]; then
    echo -e "\n${YELLOW}${BOLD}Quelques points a verifier.${NC}"
    exit 0
else
    echo -e "\n${GREEN}${BOLD}Tout est OK!${NC}"
    exit 0
fi
