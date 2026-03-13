#!/usr/bin/env bash
# No set -e — we want to continue on errors
set +e

# ============================================================================
# ZeniClaw — Full Diagnostic & Health Check
# Verifie containers, reseau, DB, permissions, proxy, connectivite, etc.
# Usage: ./check.sh   (meme user que vos containers — pas sudo si rootless)
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

pass() { echo -e "  ${GREEN}✓${NC} $1"; PASS=$((PASS+1)); }
fail() { echo -e "  ${RED}✗${NC} $1"; FAIL=$((FAIL+1)); }
skip() { echo -e "  ${YELLOW}~${NC} $1"; WARN=$((WARN+1)); }
detail() { echo -e "    ${DIM}$1${NC}"; }
header() { echo -e "\n${BOLD}${CYAN}[$1]${NC}"; }

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_DIR"

echo -e "\n${BOLD}${CYAN}=== ZeniClaw — Diagnostic Complet ===${NC}"
echo -e "${DIM}$(date '+%Y-%m-%d %H:%M:%S') | User: $(whoami) | UID: $(id -u)${NC}"

# ── 1. Environnement Systeme ────────────────────────────────────────────────
header "Systeme"

# OS
OS_INFO=$(cat /etc/os-release 2>/dev/null | grep -oP '^PRETTY_NAME="\K[^"]+' || uname -s)
pass "OS: $OS_INFO"

# Kernel
KERNEL=$(uname -r 2>/dev/null || echo "?")
pass "Kernel: $KERNEL"

# Architecture
ARCH=$(uname -m 2>/dev/null || echo "?")
pass "Architecture: $ARCH"

# RAM
TOTAL_RAM=$(free -h 2>/dev/null | awk '/^Mem:/{print $2}' || echo "?")
USED_RAM=$(free -h 2>/dev/null | awk '/^Mem:/{print $3}' || echo "?")
AVAIL_RAM=$(free -h 2>/dev/null | awk '/^Mem:/{print $7}' || echo "?")
pass "RAM: $USED_RAM utilises / $TOTAL_RAM total ($AVAIL_RAM disponible)"

# Swap
SWAP=$(free -h 2>/dev/null | awk '/^Swap:/{print $3 " / " $2}' || echo "?")
pass "Swap: $SWAP"

# CPU
CPU_CORES=$(nproc 2>/dev/null || echo "?")
CPU_MODEL=$(grep -m1 'model name' /proc/cpuinfo 2>/dev/null | cut -d: -f2 | xargs || echo "?")
pass "CPU: $CPU_CORES cores ($CPU_MODEL)"

# Disk
DISK_USAGE=$(df -h "$REPO_DIR" 2>/dev/null | tail -1 | awk '{print $5 " utilise, " $4 " libre sur " $2 " (" $1 ")"}')
if [ -n "$DISK_USAGE" ]; then
    DISK_PCT=$(df "$REPO_DIR" 2>/dev/null | tail -1 | awk '{gsub(/%/,"",$5); print $5}')
    if [ "$DISK_PCT" -gt 90 ] 2>/dev/null; then
        fail "Disque: $DISK_USAGE (CRITIQUE >90%)"
    elif [ "$DISK_PCT" -gt 80 ] 2>/dev/null; then
        skip "Disque: $DISK_USAGE (attention >80%)"
    else
        pass "Disque: $DISK_USAGE"
    fi
fi

# ── 2. Permissions & Droits ─────────────────────────────────────────────────
header "Permissions & Droits"

# Current user
CURRENT_USER=$(whoami)
CURRENT_UID=$(id -u)
CURRENT_GROUPS=$(id -Gn 2>/dev/null | tr ' ' ', ')
pass "User: $CURRENT_USER (uid=$CURRENT_UID, groups=$CURRENT_GROUPS)"

# Check if user can run container commands
CONTAINER_CMD=""
if command -v podman &>/dev/null; then
    if podman info &>/dev/null 2>&1; then
        CONTAINER_CMD="podman"
        pass "Podman accessible ($(podman --version 2>/dev/null | awk '{print $NF}'))"
    else
        fail "Podman installe mais non accessible (droits ?)"
        detail "Essayez: systemctl --user start podman.socket"
    fi
elif command -v docker &>/dev/null; then
    if docker info &>/dev/null 2>&1; then
        CONTAINER_CMD="docker"
        pass "Docker accessible ($(docker --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1))"
    else
        fail "Docker installe mais non accessible"
        detail "Essayez: sudo usermod -aG docker $CURRENT_USER && newgrp docker"
    fi
else
    fail "Aucun runtime container installe"
fi

# Rootless vs root detection
if [ -n "$CONTAINER_CMD" ]; then
    if [ "$CURRENT_UID" = "0" ]; then
        skip "Execution en ROOT — les containers rootless ne seront pas visibles"
        # Check if there's a SUDO_USER whose containers we should see
        if [ -n "${SUDO_USER:-}" ]; then
            ROOTLESS_CONTAINERS=$(sudo -u "$SUDO_USER" $CONTAINER_CMD ps -a --format '{{.Names}}' 2>/dev/null | grep -c zeniclaw 2>/dev/null || echo "0")
            ROOTLESS_CONTAINERS=$(echo "$ROOTLESS_CONTAINERS" | tr -d '[:space:]')
            if [ "$ROOTLESS_CONTAINERS" -gt 0 ] 2>/dev/null; then
                fail "Il y a $ROOTLESS_CONTAINERS containers zeniclaw chez l'user $SUDO_USER — relancez SANS sudo"
                detail "Faites: exit puis ./check.sh (sans sudo)"
            fi
        fi
    else
        pass "Execution rootless (user: $CURRENT_USER)"
    fi
fi

# Socket permissions
SOCKET_PATH=""
for sock in /var/run/docker.sock /run/podman/podman.sock /run/user/$(id -u)/podman/podman.sock; do
    if [ -S "$sock" ]; then
        SOCKET_PATH="$sock"
        break
    fi
done
if [ -n "$SOCKET_PATH" ]; then
    SOCK_PERMS=$(stat -c '%A %U:%G' "$SOCKET_PATH" 2>/dev/null || echo "?")
    if [ -r "$SOCKET_PATH" ] && [ -w "$SOCKET_PATH" ]; then
        pass "Socket: $SOCKET_PATH ($SOCK_PERMS)"
    else
        fail "Socket: $SOCKET_PATH ($SOCK_PERMS) — pas d'acces r/w"
        detail "Essayez: sudo chmod 666 $SOCKET_PATH"
    fi
else
    skip "Aucun socket container detecte"
fi

# Repo directory permissions
REPO_OWNER=$(stat -c '%U:%G' "$REPO_DIR" 2>/dev/null || echo "?")
REPO_PERMS=$(stat -c '%A' "$REPO_DIR" 2>/dev/null || echo "?")
if [ -w "$REPO_DIR" ]; then
    pass "Repertoire projet: $REPO_DIR ($REPO_PERMS $REPO_OWNER)"
else
    fail "Repertoire projet non writable: $REPO_DIR ($REPO_PERMS $REPO_OWNER)"
fi

# .env permissions
if [ -f .env ]; then
    ENV_PERMS=$(stat -c '%A %U:%G' .env 2>/dev/null || echo "?")
    if [ -r .env ]; then
        pass ".env: $ENV_PERMS"
    else
        fail ".env non lisible ($ENV_PERMS)"
    fi
else
    skip ".env absent (sera cree au besoin)"
fi

# Git permissions
if git -C "$REPO_DIR" status &>/dev/null; then
    pass "Git: acces OK"
else
    fail "Git: pas d'acces au repo"
    detail "Essayez: git config --global --add safe.directory $REPO_DIR"
fi

# ── 3. Containers ───────────────────────────────────────────────────────────
header "Containers"

APP_OK=false
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
        local started img
        started=$($CONTAINER_CMD inspect --format '{{.State.StartedAt}}' "$name" 2>/dev/null | cut -dT -f1 || true)
        img=$($CONTAINER_CMD inspect --format '{{.Config.Image}}' "$name" 2>/dev/null | sed 's|docker.io/||' || true)
        local restarts
        restarts=$($CONTAINER_CMD inspect --format '{{.RestartCount}}' "$name" 2>/dev/null || echo "0")
        pass "$name: running (image: ${img:-?}, since: ${started:-?}, restarts: ${restarts:-0})"
        [ "$name" = "zeniclaw_app" ] && APP_OK=true
    elif [ "$status" = "not_found" ]; then
        if [ "$required" = "required" ]; then
            fail "$name: INTROUVABLE"
        else
            skip "$name: non installe (optionnel)"
        fi
    elif [ "$status" = "exited" ]; then
        local exit_code
        exit_code=$($CONTAINER_CMD inspect --format '{{.State.ExitCode}}' "$name" 2>/dev/null || echo "?")
        fail "$name: STOPPED (exit code: $exit_code)"
        detail "Logs: $CONTAINER_CMD logs --tail 5 $name"
        $CONTAINER_CMD logs --tail 3 "$name" 2>&1 | while read -r line; do detail "$line"; done
    else
        fail "$name: $status"
    fi
}

check_container "zeniclaw_app"    "required"
check_container "zeniclaw_db"     "required"
check_container "zeniclaw_redis"  "required"
check_container "zeniclaw_waha"   "required"
check_container "zeniclaw_ollama" "optional"

# ── 4. Network ──────────────────────────────────────────────────────────────
header "Reseau"

if [ -n "$CONTAINER_CMD" ]; then
    NETS=$($CONTAINER_CMD network ls --format '{{.Name}}' 2>/dev/null | grep -i zeniclaw || true)
    if [ -n "$NETS" ]; then
        for net in $NETS; do
            DRIVER=$($CONTAINER_CMD network inspect "$net" --format '{{.Driver}}' 2>/dev/null || echo "?")
            pass "Reseau: $net (driver: $DRIVER)"
        done

        # Check containers are on the same network
        EXPECTED_NET=$(echo "$NETS" | head -1)
        for cname in zeniclaw_app zeniclaw_db zeniclaw_redis zeniclaw_waha zeniclaw_ollama; do
            if $CONTAINER_CMD inspect "$cname" &>/dev/null 2>&1; then
                CNET=$($CONTAINER_CMD inspect --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$cname" 2>/dev/null || true)
                if echo "$CNET" | grep -q "$EXPECTED_NET" 2>/dev/null; then
                    pass "$cname -> $EXPECTED_NET"
                else
                    fail "$cname sur '$CNET' (attendu: $EXPECTED_NET)"
                    detail "Fix: $CONTAINER_CMD network connect $EXPECTED_NET $cname"
                fi
            fi
        done
    else
        fail "Aucun reseau zeniclaw"
    fi

    # Also check if ollama exists in root but not in current user
    if [ "$CURRENT_UID" != "0" ] && command -v sudo &>/dev/null; then
        ROOT_OLLAMA=$(sudo $CONTAINER_CMD inspect zeniclaw_ollama --format '{{.State.Status}}' 2>/dev/null || echo "none")
        if [ "$ROOT_OLLAMA" = "running" ] && ! $CONTAINER_CMD inspect zeniclaw_ollama &>/dev/null 2>&1; then
            fail "zeniclaw_ollama tourne en ROOT mais vos containers sont rootless — ils ne se voient pas!"
            detail "Fix: sudo $CONTAINER_CMD stop zeniclaw_ollama && sudo $CONTAINER_CMD rm zeniclaw_ollama"
            detail "Puis: ./setup-ollama.sh (sans sudo)"
        fi
    fi
fi

# ── 5. Inter-container Connectivity ─────────────────────────────────────────
header "Connectivite Inter-containers"

if [ "$APP_OK" = true ]; then
    # App -> DB
    DB_CONN=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
        try { \DB::connection()->getPdo(); echo 'ok'; } catch(\Exception \$e) { echo 'fail'; }
    " 2>/dev/null || echo "fail")
    if [ "$DB_CONN" = "ok" ]; then
        pass "app -> db (PostgreSQL)"
    else
        fail "app -> db (PostgreSQL)"
    fi

    # App -> Redis
    REDIS_CONN=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
        try { \Illuminate\Support\Facades\Redis::ping(); echo 'ok'; } catch(\Exception \$e) { echo 'fail'; }
    " 2>/dev/null || echo "fail")
    if [ "$REDIS_CONN" = "ok" ]; then
        pass "app -> redis"
    else
        fail "app -> redis"
    fi

    # App -> WAHA
    WAHA_CONN=$($CONTAINER_CMD exec zeniclaw_app curl -sf -m 5 -H "X-Api-Key: zeniclaw-waha-2026" http://waha:3000/api/server/status 2>/dev/null && echo "ok" || echo "fail")
    if [ "$WAHA_CONN" != "fail" ]; then
        pass "app -> waha (HTTP)"
    else
        fail "app -> waha (HTTP)"
    fi

    # App -> Ollama
    if $CONTAINER_CMD inspect zeniclaw_ollama &>/dev/null 2>&1; then
        OLLAMA_CONN=$($CONTAINER_CMD exec zeniclaw_app curl -sf -m 5 http://ollama:11434/api/tags 2>/dev/null && echo "ok" || echo "fail")
        if [ "$OLLAMA_CONN" != "fail" ]; then
            pass "app -> ollama (HTTP)"
        else
            fail "app -> ollama (HTTP) — pas sur le meme reseau ?"
        fi
    fi

    # DNS resolution inside app
    for host in db redis waha ollama; do
        DNS_OK=$($CONTAINER_CMD exec zeniclaw_app getent hosts "$host" 2>/dev/null && echo "ok" || echo "fail")
        if [ "$DNS_OK" != "fail" ]; then
            IP=$($CONTAINER_CMD exec zeniclaw_app getent hosts "$host" 2>/dev/null | awk '{print $1}')
            pass "DNS: $host -> $IP"
        else
            if [ "$host" = "ollama" ] && ! $CONTAINER_CMD inspect zeniclaw_ollama &>/dev/null 2>&1; then
                skip "DNS: $host (container non installe)"
            else
                fail "DNS: $host non resolu"
            fi
        fi
    done
else
    skip "App non disponible — tests connectivite ignores"
fi

# ── 6. Database ─────────────────────────────────────────────────────────────
header "Database"

if [ "$APP_OK" = true ]; then
    # DB version
    DB_VER=$($CONTAINER_CMD exec zeniclaw_db psql -U zeniclaw -t -c "SELECT version();" 2>/dev/null | head -1 | xargs || echo "?")
    pass "PostgreSQL: $DB_VER"

    # DB size
    DB_SIZE=$($CONTAINER_CMD exec zeniclaw_db psql -U zeniclaw -t -c "SELECT pg_size_pretty(pg_database_size('zeniclaw'));" 2>/dev/null | xargs || echo "?")
    pass "Taille DB: $DB_SIZE"

    # Migrations
    PENDING=$($CONTAINER_CMD exec zeniclaw_app php artisan migrate:status --no-ansi 2>/dev/null | grep -c "Pending" 2>/dev/null || echo "0")
    PENDING=$(echo "$PENDING" | tr -d '[:space:]')
    if [ "$PENDING" = "0" ]; then
        pass "Migrations: toutes appliquees"
    else
        fail "Migrations: $PENDING en attente"
        detail "Fix: $CONTAINER_CMD exec zeniclaw_app php artisan migrate --force"
    fi

    # Users
    USER_COUNT=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null || echo "0")
    if [ "$USER_COUNT" -gt 0 ] 2>/dev/null; then
        USERS=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
            \App\Models\User::all()->each(fn(\$u) => print(\$u->name . ' <' . \$u->email . '> [' . \$u->role . ']\n'));
        " 2>/dev/null || true)
        pass "Utilisateurs: $USER_COUNT"
        echo "$USERS" | while read -r line; do [ -n "$line" ] && detail "$line"; done
    else
        fail "Aucun utilisateur — lancez: $CONTAINER_CMD exec zeniclaw_app php artisan db:seed --force"
    fi

    # Agents
    AGENT_COUNT=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\Agent::count();" 2>/dev/null || echo "0")
    if [ "$AGENT_COUNT" -gt 0 ] 2>/dev/null; then
        pass "Agents: $AGENT_COUNT"
    else
        fail "Aucun agent — lancez db:seed"
    fi
else
    skip "App non disponible — tests DB ignores"
fi

# ── 7. Redis ────────────────────────────────────────────────────────────────
header "Redis"

if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_redis &>/dev/null 2>&1; then
    REDIS_PING=$($CONTAINER_CMD exec zeniclaw_redis redis-cli ping 2>/dev/null || echo "fail")
    if [ "$REDIS_PING" = "PONG" ]; then
        pass "Redis: PONG"
    else
        fail "Redis ne repond pas"
    fi

    REDIS_MEM=$($CONTAINER_CMD exec zeniclaw_redis redis-cli info memory 2>/dev/null | grep "used_memory_human" | cut -d: -f2 | tr -d '\r' || echo "?")
    pass "Memoire Redis: $REDIS_MEM"

    QUEUE_DEFAULT=$($CONTAINER_CMD exec zeniclaw_redis redis-cli llen queues:default 2>/dev/null || echo "?")
    QUEUE_IMPROVE=$($CONTAINER_CMD exec zeniclaw_redis redis-cli llen queues:improvements 2>/dev/null || echo "?")
    pass "Queues: default=$QUEUE_DEFAULT, improvements=$QUEUE_IMPROVE"
else
    skip "Redis non disponible"
fi

# ── 8. Queue Workers & Scheduler ───────────────────────────────────────────
header "Workers & Scheduler"

if [ "$APP_OK" = true ]; then
    # Supervisor processes
    SUPERVISOR=$($CONTAINER_CMD exec zeniclaw_app supervisorctl status 2>/dev/null || echo "fail")
    if [ "$SUPERVISOR" != "fail" ]; then
        pass "Supervisor:"
        echo "$SUPERVISOR" | while read -r line; do
            if echo "$line" | grep -q "RUNNING"; then
                detail "${GREEN}$line${NC}"
            elif echo "$line" | grep -q "FATAL\|BACKOFF\|STOPPED"; then
                detail "${RED}$line${NC}"
                FAIL=$((FAIL+1))
            else
                detail "$line"
            fi
        done
    else
        fail "Supervisor non accessible"
    fi

    # Worker count
    WORKERS=$($CONTAINER_CMD exec zeniclaw_app ps aux 2>/dev/null | grep -c "[q]ueue:work" || echo "0")
    if [ "$WORKERS" -gt 0 ]; then
        pass "Queue workers actifs: $WORKERS"
    else
        fail "Aucun queue worker"
    fi

    # Scheduler
    SCHEDULER=$($CONTAINER_CMD exec zeniclaw_app ps aux 2>/dev/null | grep -c "[s]chedule:work" || echo "0")
    if [ "$SCHEDULER" -gt 0 ]; then
        pass "Scheduler: actif"
    else
        fail "Scheduler: inactif"
    fi

    # Cron
    CRON=$($CONTAINER_CMD exec zeniclaw_app cat /etc/cron.d/zeniclaw-watchdog 2>/dev/null || echo "none")
    if [ "$CRON" != "none" ]; then
        pass "Watchdog cron: installe"
    else
        skip "Watchdog cron: absent"
    fi
else
    skip "App non disponible"
fi

# ── 9. WhatsApp (WAHA) ─────────────────────────────────────────────────────
header "WhatsApp (WAHA)"

if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_waha &>/dev/null 2>&1; then
    if [ "$APP_OK" = true ]; then
        WAHA_STATUS=$($CONTAINER_CMD exec zeniclaw_app curl -sf -H "X-Api-Key: zeniclaw-waha-2026" http://waha:3000/api/sessions/default 2>/dev/null || echo "fail")
        if [ "$WAHA_STATUS" != "fail" ]; then
            SESSION_STATUS=$(echo "$WAHA_STATUS" | grep -oP '"status"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
            case "$SESSION_STATUS" in
                WORKING)  pass "Session: WORKING (WhatsApp connecte)" ;;
                SCAN_QR*) skip "Session: QR code en attente — scannez le QR dans l'UI" ;;
                STARTING) skip "Session: en cours de demarrage..." ;;
                STOPPED)  fail "Session: STOPPED" ;;
                FAILED)   fail "Session: FAILED" ;;
                *)        skip "Session: $SESSION_STATUS" ;;
            esac
        else
            fail "WAHA API non accessible"
        fi
    else
        skip "App non disponible — test WAHA ignore"
    fi
else
    skip "WAHA non installe"
fi

# ── 10. Ollama (On-Prem) ───────────────────────────────────────────────────
header "Ollama (On-Prem)"

OLLAMA_RUNNING=false
if [ -n "$CONTAINER_CMD" ] && $CONTAINER_CMD inspect zeniclaw_ollama &>/dev/null 2>&1; then
    OLLAMA_STATUS=$($CONTAINER_CMD inspect --format '{{.State.Status}}' zeniclaw_ollama 2>/dev/null || echo "stopped")
    if [ "$OLLAMA_STATUS" = "running" ]; then
        OLLAMA_RUNNING=true
        pass "Container: running"

        # Check API via tcp then ollama list
        OLLAMA_API_OK=false
        if $CONTAINER_CMD exec zeniclaw_ollama bash -c 'echo > /dev/tcp/localhost/11434' &>/dev/null; then
            OLLAMA_API_OK=true
        elif $CONTAINER_CMD exec zeniclaw_ollama wget -qO /dev/null http://localhost:11434/api/tags &>/dev/null; then
            OLLAMA_API_OK=true
        elif $CONTAINER_CMD exec -e OLLAMA_HOST=http://127.0.0.1:11434 zeniclaw_ollama ollama list &>/dev/null; then
            OLLAMA_API_OK=true
        fi

        if [ "$OLLAMA_API_OK" = true ]; then
            pass "API: accessible"

            MODELS=$($CONTAINER_CMD exec -e OLLAMA_HOST=http://127.0.0.1:11434 zeniclaw_ollama ollama list 2>/dev/null | tail -n +2 || true)
            if [ -n "$MODELS" ]; then
                MODEL_COUNT=$(echo "$MODELS" | wc -l)
                pass "Modeles installes: $MODEL_COUNT"
                echo "$MODELS" | while read -r line; do detail "$line"; done
            else
                skip "Aucun modele — ./setup-ollama.sh pour en ajouter"
            fi
        else
            fail "API Ollama non accessible dans le container"
        fi

        # Memory
        OLLAMA_MEM=$($CONTAINER_CMD stats --no-stream --format '{{.MemUsage}}' zeniclaw_ollama 2>/dev/null || true)
        [ -n "$OLLAMA_MEM" ] && pass "Memoire: $OLLAMA_MEM"
    else
        fail "Container Ollama: $OLLAMA_STATUS"
    fi
else
    skip "Ollama non installe (./setup-ollama.sh)"
fi

# ── 11. API & Configuration ────────────────────────────────────────────────
header "Configuration App"

if [ "$APP_OK" = true ]; then
    # Version
    VERSION=$($CONTAINER_CMD exec zeniclaw_app cat /var/www/html/storage/app/version.txt 2>/dev/null || echo "?")
    pass "Version: v$VERSION"

    # Anthropic API
    API_KEY=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\AppSetting::get('anthropic_api_key') ? 'set' : 'missing';" 2>/dev/null || echo "?")
    if [ "$API_KEY" = "set" ]; then
        pass "Cle API Anthropic: configuree"
    else
        skip "Cle API Anthropic: non configuree (Settings > API)"
    fi

    # Model roles
    MODELS=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
        \$r = \App\Services\ModelResolver::current();
        echo 'fast=' . \$r['fast'] . ' | balanced=' . \$r['balanced'] . ' | powerful=' . \$r['powerful'];
    " 2>/dev/null || echo "?")
    pass "Modeles: $MODELS"

    # Ollama URL in settings
    OLLAMA_URL=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\AppSetting::get('onprem_api_url') ?? 'non configure';" 2>/dev/null || echo "?")
    if [ "$OLLAMA_URL" != "non configure" ] && [ "$OLLAMA_URL" != "?" ]; then
        pass "URL Ollama (Settings): $OLLAMA_URL"
    else
        if [ "$OLLAMA_RUNNING" = true ]; then
            fail "Ollama tourne mais URL non configuree dans Settings"
            detail "Fix: $CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute=\"\\App\\Models\\AppSetting::set('onprem_api_url','http://ollama:11434');\""
        else
            skip "URL Ollama: non configuree"
        fi
    fi

    # App URL
    APP_URL=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo config('app.url');" 2>/dev/null || echo "?")
    pass "APP_URL: $APP_URL"

    # Storage writable
    STORAGE_OK=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
        echo is_writable(storage_path()) ? 'ok' : 'fail';
    " 2>/dev/null || echo "fail")
    if [ "$STORAGE_OK" = "ok" ]; then
        pass "Storage writable"
    else
        fail "Storage non writable dans le container"
        detail "Fix: $CONTAINER_CMD exec zeniclaw_app chown -R www-data:www-data /var/www/html/storage"
    fi

    # Log errors (last 5)
    RECENT_ERRORS=$($CONTAINER_CMD exec zeniclaw_app tail -20 /var/www/html/storage/logs/laravel.log 2>/dev/null | grep -c "\\.ERROR" || echo "0")
    if [ "$RECENT_ERRORS" = "0" ]; then
        pass "Logs recents: pas d'erreur"
    else
        skip "Logs recents: $RECENT_ERRORS erreurs (tail storage/logs/laravel.log)"
    fi
else
    skip "App non disponible"
fi

# ── 12. Test Chat LLM ─────────────────────────────────────────────────────
header "Test Chat LLM"

if [ "$APP_OK" = true ]; then
    # Get configured models for each role
    FAST_MODEL=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Services\ModelResolver::fast();" 2>/dev/null || echo "?")
    BALANCED_MODEL=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Services\ModelResolver::balanced();" 2>/dev/null || echo "?")
    POWERFUL_MODEL=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Services\ModelResolver::powerful();" 2>/dev/null || echo "?")

    detail "Modeles configures:"
    detail "  fast     = $FAST_MODEL"
    detail "  balanced = $BALANCED_MODEL"
    detail "  powerful = $POWERFUL_MODEL"

    # Warn if all tiers use the same model
    if [ "$FAST_MODEL" = "$BALANCED_MODEL" ] && [ "$BALANCED_MODEL" = "$POWERFUL_MODEL" ] && [ "$FAST_MODEL" != "?" ]; then
        skip "Les 3 tiers utilisent le MEME modele — configurez des modeles differents pour de meilleures performances"
        IS_ONPREM_MODEL=false
        if ! echo "$FAST_MODEL" | grep -q "^claude-"; then IS_ONPREM_MODEL=true; fi
        if [ "$IS_ONPREM_MODEL" = true ]; then
            detail "Suggestion: fast=petit modele (rapide), balanced=moyen, powerful=gros (qualite)"
            detail "Ex: fast=qwen2.5:0.5b, balanced=qwen2.5:3b, powerful=qwen2.5:7b"
        fi
    fi

    # ── GPU & Hardware Detection ──
    detail ""
    detail "--- Hardware IA ---"

    # Check GPU inside Ollama container (most relevant)
    if [ "$OLLAMA_RUNNING" = true ]; then
        GPU_INFO=$($CONTAINER_CMD exec zeniclaw_ollama nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free,utilization.gpu --format=csv,noheader,nounits 2>/dev/null || echo "")
        if [ -n "$GPU_INFO" ]; then
            GPU_NAME=$(echo "$GPU_INFO" | cut -d',' -f1 | xargs)
            GPU_TOTAL=$(echo "$GPU_INFO" | cut -d',' -f2 | xargs)
            GPU_USED=$(echo "$GPU_INFO" | cut -d',' -f3 | xargs)
            GPU_FREE=$(echo "$GPU_INFO" | cut -d',' -f4 | xargs)
            GPU_UTIL=$(echo "$GPU_INFO" | cut -d',' -f5 | xargs)
            pass "GPU: $GPU_NAME | VRAM: ${GPU_USED}/${GPU_TOTAL} MiB (${GPU_FREE} MiB libre) | Utilisation: ${GPU_UTIL}%"
        else
            # Try lspci as fallback
            GPU_PCI=$($CONTAINER_CMD exec zeniclaw_ollama lspci 2>/dev/null | grep -i "vga\|3d\|display" || true)
            if [ -n "$GPU_PCI" ]; then
                skip "GPU detecte mais nvidia-smi absent: $(echo "$GPU_PCI" | head -1)"
            else
                skip "Pas de GPU detecte — Ollama tourne sur CPU (lent)"
                detail "Pour un modele 0.5B: ~5-15 tok/s sur CPU, ~50-100 tok/s sur GPU"
            fi
        fi

        # Ollama container resources
        OLLAMA_STATS=$($CONTAINER_CMD stats --no-stream --format '{{.CPUPerc}} | RAM: {{.MemUsage}}' zeniclaw_ollama 2>/dev/null || echo "?")
        pass "Ollama container: CPU: $OLLAMA_STATS"
    else
        skip "Ollama non running — pas de diagnostic hardware IA"
    fi

    # ── Ollama Models Detail ──
    if [ "$OLLAMA_RUNNING" = true ]; then
        OLLAMA_URL_DIAG=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\AppSetting::get('onprem_api_url') ?? '';" 2>/dev/null || echo "")
        if [ -n "$OLLAMA_URL_DIAG" ]; then
            detail ""
            detail "--- Modeles Ollama ---"

            # List installed models with sizes
            RAW_TAGS=$($CONTAINER_CMD exec zeniclaw_app curl -sf -m 5 "${OLLAMA_URL_DIAG}/api/tags" 2>/dev/null || echo "fail")
            if [ "$RAW_TAGS" != "fail" ]; then
                # Parse each model: name, size, quantization, parameter_size, family
                MODEL_DETAILS=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
                    \$tags = json_decode('$(echo "$RAW_TAGS" | sed "s/'/\\\\'/g")', true);
                    foreach ((\$tags['models'] ?? []) as \$m) {
                        \$name = \$m['name'] ?? '?';
                        \$size = round((\$m['size'] ?? 0) / 1024 / 1024 / 1024, 1);
                        \$family = \$m['details']['family'] ?? '?';
                        \$params = \$m['details']['parameter_size'] ?? '?';
                        \$quant = \$m['details']['quantization_level'] ?? '?';
                        echo \"{$name}|{$size}GB|{$params}|{$family}|{$quant}\n\";
                    }
                " 2>/dev/null || echo "")

                if [ -n "$MODEL_DETAILS" ]; then
                    echo "$MODEL_DETAILS" | while IFS='|' read -r MNAME MSIZE MPARAMS MFAMILY MQUANT; do
                        [ -z "$MNAME" ] && continue
                        pass "  $MNAME — ${MSIZE} sur disque, ${MPARAMS} params, famille: $MFAMILY, quant: $MQUANT"
                    done
                fi
            fi

            # Currently loaded models (in VRAM/RAM)
            RAW_PS=$($CONTAINER_CMD exec zeniclaw_app curl -sf -m 5 "${OLLAMA_URL_DIAG}/api/ps" 2>/dev/null || echo "fail")
            if [ "$RAW_PS" != "fail" ]; then
                LOADED_MODELS=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
                    \$ps = json_decode('$(echo "$RAW_PS" | sed "s/'/\\\\'/g")', true);
                    \$models = \$ps['models'] ?? [];
                    if (empty(\$models)) { echo 'aucun'; return; }
                    foreach (\$models as \$m) {
                        \$name = \$m['name'] ?? '?';
                        \$size = round((\$m['size'] ?? 0) / 1024 / 1024, 0);
                        \$vram = round((\$m['size_vram'] ?? 0) / 1024 / 1024, 0);
                        \$proc = \$m['details']['quantization_level'] ?? '?';
                        \$expires = \$m['expires_at'] ?? '';
                        echo \"{$name}|{$size}MB RAM|{$vram}MB VRAM|expire: {$expires}\n\";
                    }
                " 2>/dev/null || echo "aucun")

                if [ "$LOADED_MODELS" = "aucun" ]; then
                    detail "Modeles charges en memoire: aucun (cold start au prochain appel)"
                else
                    detail "Modeles charges en memoire:"
                    echo "$LOADED_MODELS" | while IFS='|' read -r LNAME LRAM LVRAM LEXP; do
                        [ -z "$LNAME" ] && continue
                        detail "  $LNAME — $LRAM, $LVRAM, $LEXP"
                    done
                fi
            fi
        fi
    fi

    # ── Chat Tests per Model ──
    detail ""
    detail "--- Tests de chat ---"

    TESTED=""
    for ROLE_INFO in "fast:$FAST_MODEL" "balanced:$BALANCED_MODEL" "powerful:$POWERFUL_MODEL"; do
        ROLE="${ROLE_INFO%%:*}"
        MODEL="${ROLE_INFO#*:}"
        if [ "$MODEL" = "?" ]; then continue; fi
        # Skip if already tested this model
        if echo "$TESTED" | grep -qF "|$MODEL|"; then
            detail "$ROLE ($MODEL) — deja teste ci-dessus"
            continue
        fi
        TESTED="${TESTED}|${MODEL}|"

        IS_CLOUD=false
        if echo "$MODEL" | grep -q "^claude-"; then IS_CLOUD=true; fi

        detail ""
        detail "--- Test: $ROLE -> $MODEL $([ "$IS_CLOUD" = true ] && echo '[cloud]' || echo '[on-prem]') ---"

        # Run the actual chat test with extended debug (tokens, timing breakdown)
        CHAT_RESULT=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
            \$model = '$MODEL';
            \$client = new \App\Services\AnthropicClient();
            \$isOnPrem = \$client->isOnPremModel(\$model);

            \$start = microtime(true);
            try {
                if (\$isOnPrem) {
                    // For on-prem: use raw API to get full response with usage stats
                    \$url = \App\Models\AppSetting::get('onprem_api_url');
                    \$resp = \Illuminate\Support\Facades\Http::timeout(120)->post(\"{$url}/v1/chat/completions\", [
                        'model' => \$model,
                        'messages' => [['role' => 'user', 'content' => 'Reponds juste OK en un mot.']],
                        'max_tokens' => 50,
                        'stream' => false,
                    ]);
                    \$elapsed = round((microtime(true) - \$start) * 1000);

                    if (\$resp->successful()) {
                        \$data = \$resp->json();
                        \$content = \$data['choices'][0]['message']['content'] ?? '';
                        \$promptTok = \$data['usage']['prompt_tokens'] ?? 0;
                        \$completionTok = \$data['usage']['completion_tokens'] ?? 0;
                        \$totalTok = \$data['usage']['total_tokens'] ?? 0;
                        \$tokPerSec = \$elapsed > 0 ? round(\$completionTok / (\$elapsed / 1000), 1) : 0;
                        \$finishReason = \$data['choices'][0]['finish_reason'] ?? '?';

                        echo \"SUCCESS|{\$elapsed}ms|\" . substr(trim(\$content), 0, 100) . \"|prompt={\$promptTok},completion={\$completionTok},total={\$totalTok}|{\$tokPerSec} tok/s|finish={\$finishReason}\";
                    } else {
                        echo \"ERROR|{\$elapsed}ms|HTTP \" . \$resp->status() . ': ' . substr(\$resp->body(), 0, 200) . '|||';
                    }
                } else {
                    \$response = \$client->chat('Reponds juste OK en un mot.', \$model, 'Tu es un assistant de test. Reponds en un seul mot.');
                    \$elapsed = round((microtime(true) - \$start) * 1000);
                    if (\$response) {
                        echo \"SUCCESS|{\$elapsed}ms|\" . substr(trim(\$response), 0, 100) . '|cloud||';
                    } else {
                        echo \"NULL|{\$elapsed}ms|reponse vide (null)|cloud||';
                    }
                }
            } catch (\Throwable \$e) {
                \$elapsed = round((microtime(true) - \$start) * 1000);
                echo \"ERROR|{\$elapsed}ms|\" . substr(\$e->getMessage(), 0, 200) . '|||';
            }
        " 2>/dev/null || echo "ERROR|0ms|impossible d'executer le tinker|||")

        CHAT_STATUS="${CHAT_RESULT%%|*}"
        CHAT_REST="${CHAT_RESULT#*|}"
        CHAT_TIME="${CHAT_REST%%|*}"
        CHAT_REST2="${CHAT_REST#*|}"
        CHAT_MSG="${CHAT_REST2%%|*}"
        CHAT_REST3="${CHAT_REST2#*|}"
        CHAT_TOKENS="${CHAT_REST3%%|*}"
        CHAT_REST4="${CHAT_REST3#*|}"
        CHAT_TOKPS="${CHAT_REST4%%|*}"
        CHAT_FINISH="${CHAT_REST4#*|}"

        if [ "$CHAT_STATUS" = "SUCCESS" ]; then
            pass "Chat $ROLE ($MODEL): OK ($CHAT_TIME)"
            detail "Reponse: $CHAT_MSG"
            if [ -n "$CHAT_TOKENS" ] && [ "$CHAT_TOKENS" != "cloud" ]; then
                detail "Tokens: $CHAT_TOKENS"

                # Evaluate speed
                if [ -n "$CHAT_TOKPS" ]; then
                    TOKPS_NUM=$(echo "$CHAT_TOKPS" | grep -oP '[\d.]+' || echo "0")
                    detail "Debit: $CHAT_TOKPS"
                    # Speed assessment
                    if [ -n "$TOKPS_NUM" ]; then
                        TOKPS_INT=$(echo "$TOKPS_NUM" | cut -d. -f1)
                        if [ "$TOKPS_INT" -lt 2 ] 2>/dev/null; then
                            skip "  -> TRES LENT (<2 tok/s) — modele trop gros pour ce hardware ?"
                        elif [ "$TOKPS_INT" -lt 5 ] 2>/dev/null; then
                            skip "  -> LENT (2-5 tok/s) — acceptable pour du batch, lent pour du chat"
                        elif [ "$TOKPS_INT" -lt 20 ] 2>/dev/null; then
                            pass "  -> CORRECT (5-20 tok/s) — utilisable pour du chat"
                        elif [ "$TOKPS_INT" -lt 50 ] 2>/dev/null; then
                            pass "  -> RAPIDE (20-50 tok/s) — bonne experience utilisateur"
                        else
                            pass "  -> TRES RAPIDE (>50 tok/s)"
                        fi
                    fi
                fi

                if [ -n "$CHAT_FINISH" ]; then
                    detail "$CHAT_FINISH"
                fi
            fi

            # Timing assessment
            TIME_NUM=$(echo "$CHAT_TIME" | grep -oP '\d+' || echo "0")
            if [ "$TIME_NUM" -gt 30000 ] 2>/dev/null; then
                skip "  -> Temps total ${CHAT_TIME} — possible cold start (le modele n'etait pas en memoire)"
                detail "  Le 2eme appel sera beaucoup plus rapide (modele deja charge)"
            elif [ "$TIME_NUM" -gt 10000 ] 2>/dev/null; then
                skip "  -> Temps total ${CHAT_TIME} — un peu lent"
            fi

            # Second call to measure warm performance (if first was slow)
            if [ "$TIME_NUM" -gt 10000 ] 2>/dev/null && [ "$IS_CLOUD" = false ]; then
                detail ""
                detail "  2eme appel (modele deja charge)..."
                WARM_RESULT=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="
                    \$url = \App\Models\AppSetting::get('onprem_api_url');
                    \$start = microtime(true);
                    \$resp = \Illuminate\Support\Facades\Http::timeout(120)->post(\"\$url/v1/chat/completions\", [
                        'model' => '$MODEL',
                        'messages' => [['role' => 'user', 'content' => 'Dis OK.']],
                        'max_tokens' => 10,
                        'stream' => false,
                    ]);
                    \$elapsed = round((microtime(true) - \$start) * 1000);
                    if (\$resp->successful()) {
                        \$d = \$resp->json();
                        \$ct = \$d['usage']['completion_tokens'] ?? 0;
                        \$tps = \$elapsed > 0 ? round(\$ct / (\$elapsed / 1000), 1) : 0;
                        echo \"{\$elapsed}ms|{\$tps} tok/s|\" . substr(\$d['choices'][0]['message']['content'] ?? '', 0, 50);
                    } else {
                        echo 'ECHEC|0|' . \$resp->status();
                    }
                " 2>/dev/null || echo "ECHEC|0|tinker error")
                WARM_TIME="${WARM_RESULT%%|*}"
                WARM_REST="${WARM_RESULT#*|}"
                WARM_TOKPS="${WARM_REST%%|*}"
                WARM_MSG="${WARM_REST#*|}"
                if [ "$WARM_TIME" != "ECHEC" ]; then
                    pass "  2eme appel (warm): $WARM_TIME ($WARM_TOKPS) — \"$WARM_MSG\""
                else
                    fail "  2eme appel: ECHEC"
                fi
            fi
        else
            fail "Chat $ROLE ($MODEL): ECHEC ($CHAT_TIME)"
            detail "Erreur: $CHAT_MSG"

            # Extra debug for on-prem models
            if [ "$IS_CLOUD" = false ]; then
                detail ""
                detail "--- Debug On-Prem ---"
                OLLAMA_MEM=$($CONTAINER_CMD stats --no-stream --format 'CPU: {{.CPUPerc}} | RAM: {{.MemUsage}}' zeniclaw_ollama 2>/dev/null || echo "?")
                detail "Ressources Ollama: $OLLAMA_MEM"

                OLLAMA_URL=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\AppSetting::get('onprem_api_url') ?? 'non configure';" 2>/dev/null || echo "?")
                detail "URL Ollama: $OLLAMA_URL"

                if [ "$OLLAMA_URL" != "non configure" ] && [ "$OLLAMA_URL" != "?" ]; then
                    # Check if model exists
                    RAW_TAGS=$($CONTAINER_CMD exec zeniclaw_app curl -sf -m 5 "${OLLAMA_URL}/api/tags" 2>/dev/null || echo "fail")
                    if [ "$RAW_TAGS" != "fail" ]; then
                        if ! echo "$RAW_TAGS" | grep -q "$MODEL"; then
                            fail "Le modele '$MODEL' n'est PAS installe dans Ollama !"
                            INSTALLED=$(echo "$RAW_TAGS" | grep -oP '"name"\s*:\s*"\K[^"]+' | tr '\n' ', ')
                            detail "Modeles disponibles: ${INSTALLED:-aucun}"
                            detail "Fix: ollama pull $MODEL"
                        fi
                    else
                        fail "Ollama API injoignable: ${OLLAMA_URL}/api/tags"
                    fi
                fi

                OLLAMA_LOGS=$($CONTAINER_CMD logs --tail 5 zeniclaw_ollama 2>&1 | tail -5 || echo "pas de logs")
                detail "Derniers logs Ollama:"
                echo "$OLLAMA_LOGS" | while read -r line; do detail "  $line"; done
            else
                # Cloud model debug
                HAS_KEY=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\AppSetting::get('anthropic_api_key') ? 'oui' : 'non';" 2>/dev/null || echo "?")
                detail "Cle API Anthropic: $HAS_KEY"
                if [ "$HAS_KEY" = "non" ]; then
                    detail "Fix: configurez la cle API dans Settings > API"
                fi
            fi
        fi
    done
else
    skip "App non disponible — test chat ignore"
fi

# ── 13. Proxy ──────────────────────────────────────────────────────────────
header "Proxy"

PROXY_HTTP=""
PROXY_HTTPS=""
if [ -f .env ]; then
    PROXY_HTTP=$(grep -oP '^HTTP_PROXY=\K.*' .env 2>/dev/null || true)
    PROXY_HTTPS=$(grep -oP '^HTTPS_PROXY=\K.*' .env 2>/dev/null || true)
    PROXY_NO=$(grep -oP '^NO_PROXY=\K.*' .env 2>/dev/null || true)
fi

if [ -n "$PROXY_HTTP" ] || [ -n "$PROXY_HTTPS" ]; then
    pass "HTTP_PROXY: ${PROXY_HTTP:-non defini}"
    pass "HTTPS_PROXY: ${PROXY_HTTPS:-non defini}"
    [ -n "$PROXY_NO" ] && pass "NO_PROXY: $PROXY_NO"

    # Test proxy reachability
    PROXY_URL="${PROXY_HTTP:-$PROXY_HTTPS}"
    PROXY_HOST=$(echo "$PROXY_URL" | sed 's|https\?://||' | cut -d: -f1)
    PROXY_PORT=$(echo "$PROXY_URL" | sed 's|https\?://||' | cut -d: -f2)
    if timeout 5 bash -c "echo > /dev/tcp/$PROXY_HOST/$PROXY_PORT" 2>/dev/null; then
        pass "Proxy joignable: $PROXY_HOST:$PROXY_PORT"
    else
        fail "Proxy injoignable: $PROXY_HOST:$PROXY_PORT"
    fi

    # Check proxy in DB matches .env
    if [ "$APP_OK" = true ]; then
        DB_PROXY=$($CONTAINER_CMD exec zeniclaw_app php artisan tinker --execute="echo \App\Models\AppSetting::get('proxy_http') ?? '';" 2>/dev/null || echo "")
        if [ "$DB_PROXY" = "$PROXY_HTTP" ]; then
            pass "Proxy DB sync: OK"
        elif [ -z "$DB_PROXY" ]; then
            skip "Proxy non sauvegarde en DB (Settings > Proxy)"
        else
            skip "Proxy .env ($PROXY_HTTP) != DB ($DB_PROXY)"
        fi
    fi

    # Check ollama has proxy env
    if [ "$OLLAMA_RUNNING" = true ]; then
        OLLAMA_PROXY=$($CONTAINER_CMD inspect zeniclaw_ollama --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | grep "HTTP_PROXY" | head -1 || true)
        if [ -n "$OLLAMA_PROXY" ]; then
            pass "Proxy Ollama: $OLLAMA_PROXY"
        else
            fail "Ollama n'a pas le proxy — relancez setup-ollama.sh"
        fi
    fi
else
    skip "Pas de proxy configure (.env)"
fi

# ── 14. Internet / External Connectivity ───────────────────────────────────
header "Connectivite Externe"

check_url() {
    local label="$1" url="$2" required="${3:-true}"
    local http_code
    if [ "$APP_OK" = true ]; then
        http_code=$($CONTAINER_CMD exec zeniclaw_app curl -s -m 10 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null)
    else
        http_code=$(curl -s -m 10 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null)
        # Retry with proxy if direct fails
        if [ "$http_code" = "000" ] && [ -n "$PROXY_HTTP" ]; then
            http_code=$(curl -s -m 10 -x "$PROXY_HTTP" -o /dev/null -w '%{http_code}' "$url" 2>/dev/null)
        fi
    fi
    http_code=$(echo "$http_code" | tr -d '[:space:]')
    if [ -n "$http_code" ] && [ "$http_code" != "000" ]; then
        pass "$label (HTTP $http_code)"
    elif [ "$required" = "true" ]; then
        fail "$label: injoignable"
    else
        skip "$label: injoignable"
    fi
}

check_url "Internet (google.com)" "https://www.google.com" "true"
check_url "api.anthropic.com" "https://api.anthropic.com" "true"
check_url "gitlab.com" "https://gitlab.com" "true"
check_url "Docker Hub" "https://registry-1.docker.io/v2/" "false"

# ── 15. Git ────────────────────────────────────────────────────────────────
header "Git"

GIT_BRANCH=$(git -C "$REPO_DIR" branch --show-current 2>/dev/null || echo "?")
GIT_COMMIT=$(git -C "$REPO_DIR" log -1 --format='%h %s (%cr)' 2>/dev/null || echo "?")
GIT_REMOTE=$(git -C "$REPO_DIR" remote get-url origin 2>/dev/null || echo "?")
pass "Branche: $GIT_BRANCH"
pass "Dernier commit: $GIT_COMMIT"
pass "Remote: $GIT_REMOTE"

# Check for uncommitted changes
DIRTY=$(git -C "$REPO_DIR" status --porcelain 2>/dev/null | wc -l || echo "0")
if [ "$DIRTY" = "0" ]; then
    pass "Working tree: clean"
else
    skip "Working tree: $DIRTY fichiers modifies"
fi

# ── Summary ─────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}═══════════════════════════════════════${NC}"
echo -e "${BOLD}  RESULTAT${NC}"
echo -e "${BOLD}═══════════════════════════════════════${NC}"
echo -e "  ${GREEN}✓ $PASS passes${NC}"
echo -e "  ${YELLOW}~ $WARN warnings${NC}"
echo -e "  ${RED}✗ $FAIL echecs${NC}"
echo ""

if [ "$FAIL" -gt 0 ]; then
    echo -e "${RED}${BOLD}  Des problemes ont ete detectes!${NC}"
    echo -e "${DIM}  Corrigez les ${RED}✗${NC}${DIM} ci-dessus puis relancez ./check.sh${NC}"
    echo ""
    exit 1
elif [ "$WARN" -gt 0 ]; then
    echo -e "${YELLOW}${BOLD}  Quelques points a verifier.${NC}"
    echo ""
    exit 0
else
    echo -e "${GREEN}${BOLD}  Tout est OK!${NC}"
    echo ""
    exit 0
fi
