#!/usr/bin/env bash
# ZeniClaw — WhatsApp/WAHA Diagnostic & Repair Script (v4)
# Usage: bash debug-whatsapp.sh [--auto-fix]
#   --auto-fix   Apply all fixes without asking (non-interactive)
#   (default)    Interactive mode: diagnose, then offer to fix each issue

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
DIM='\033[2m'
BOLD='\033[1m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; }
info() { echo -e "  ${CYAN}→${NC} $1"; }
header() { echo -e "\n${BOLD}[$1] $2${NC}"; }

ask_fix() {
    # Usage: ask_fix "Description" "command" — returns 0 if user says yes or --auto-fix
    local desc="$1"
    if [ "$AUTO_FIX" = true ]; then
        echo -e "  ${CYAN}⚡ Auto-fixing:${NC} $desc"
        return 0
    fi
    echo ""
    echo -ne "  ${YELLOW}Fix: ${desc}${NC} [y/N] "
    read -r answer
    [[ "$answer" =~ ^[yYoO] ]]
}

ISSUES=()
AUTO_FIX=false
[ "${1:-}" = "--auto-fix" ] && AUTO_FIX=true

WAHA_BASE="http://waha:3000"
WAHA_KEY="zeniclaw-waha-2026"
FIXES_APPLIED=0

echo -e "\n${BOLD}${CYAN}════════════════════════════════════════════${NC}"
echo -e "${BOLD}${CYAN}  ZeniClaw WhatsApp Diagnostic v4${NC}"
echo -e "${BOLD}${CYAN}════════════════════════════════════════════${NC}\n"

# ═══════════════════════════════════════════════════════════════════
#  PHASE 1 — DIAGNOSTIC
# ═══════════════════════════════════════════════════════════════════

# ── 1. Container status ──────────────────────────────────────────
header "1/12" "Container Status"

APP_STATUS=$(docker inspect zeniclaw_app --format '{{.State.Status}}' 2>/dev/null || echo "not_found")
WAHA_STATUS=$(docker inspect zeniclaw_waha --format '{{.State.Status}}' 2>/dev/null || echo "not_found")

if [ "$APP_STATUS" = "running" ]; then
    APP_UPTIME=$(docker inspect zeniclaw_app --format '{{.State.StartedAt}}' 2>/dev/null)
    ok "zeniclaw_app: running (since $APP_UPTIME)"
else
    fail "zeniclaw_app: $APP_STATUS"
    ISSUES+=("APP_DOWN")
    if ask_fix "Start app container"; then
        docker compose up -d app
        sleep 3
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
        ok "App container started"
    fi
fi

if [ "$WAHA_STATUS" = "running" ]; then
    WAHA_UPTIME=$(docker inspect zeniclaw_waha --format '{{.State.StartedAt}}' 2>/dev/null)
    ok "zeniclaw_waha: running (since $WAHA_UPTIME)"
else
    fail "zeniclaw_waha: $WAHA_STATUS"
    ISSUES+=("WAHA_DOWN")
    if ask_fix "Start WAHA container"; then
        docker compose up -d waha
        sleep 3
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
        ok "WAHA container started"
    fi
fi

# ── 2. WAHA API & Version ────────────────────────────────────────
header "2/12" "WAHA API & Version"

WAHA_HEALTH=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{http_code}" -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default 2>/dev/null || echo "000")

if [ "$WAHA_HEALTH" = "200" ]; then
    ok "WAHA API reachable (HTTP 200)"
elif [ "$WAHA_HEALTH" = "401" ]; then
    fail "WAHA API: HTTP 401 (wrong API key)"
    ISSUES+=("WAHA_AUTH")
elif [ "$WAHA_HEALTH" = "000" ]; then
    fail "WAHA API: unreachable"
    ISSUES+=("WAHA_UNREACHABLE")
    if ask_fix "Restart WAHA container"; then
        docker restart zeniclaw_waha
        sleep 5
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
    fi
else
    warn "WAHA API: HTTP $WAHA_HEALTH"
fi

# WAHA version
WAHA_VER_JSON=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/version 2>/dev/null || echo "{}")
WAHA_VER=$(echo "$WAHA_VER_JSON" | grep -oP '"version"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
info "WAHA version: $WAHA_VER"

# ── 3. WhatsApp session ──────────────────────────────────────────
header "3/12" "WhatsApp Session"

SESSION_JSON=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default 2>/dev/null || echo "{}")
SESSION_STATUS=$(echo "$SESSION_JSON" | grep -oP '"status"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
SESSION_PHONE=$(echo "$SESSION_JSON" | grep -oP '"id"\s*:\s*"\K[^":]+' | head -1 || echo "")
SESSION_NAME=$(echo "$SESSION_JSON" | grep -oP '"pushName"\s*:\s*"\K[^"]+' | head -1 || echo "")
WEBHOOK_URL=$(echo "$SESSION_JSON" | grep -oP '"url"\s*:\s*"\K[^"]+' | head -1 || echo "")

case "$SESSION_STATUS" in
    WORKING)
        ok "Session: WORKING"
        [ -n "$SESSION_PHONE" ] && info "Phone: +$SESSION_PHONE ($SESSION_NAME)"
        ;;
    SCAN_QR_CODE)
        warn "Session: SCAN_QR_CODE — waiting for QR scan"
        ISSUES+=("QR_NEEDED")
        info "Go to Settings page and scan QR code"
        ;;
    STOPPED|FAILED)
        fail "Session: $SESSION_STATUS"
        ISSUES+=("SESSION_${SESSION_STATUS}")
        if ask_fix "Restart WAHA session with webhook config"; then
            docker exec zeniclaw_app curl -sf -X POST \
                -H "X-Api-Key: ${WAHA_KEY}" -H "Content-Type: application/json" \
                -d "{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\",\"message.reaction\"]}]}}" \
                ${WAHA_BASE}/api/sessions/start >/dev/null 2>&1
            FIXES_APPLIED=$((FIXES_APPLIED + 1))
            ok "Session restart triggered"
        fi
        ;;
    *)
        warn "Session: $SESSION_STATUS"
        ;;
esac

# Webhook URL
if [ -n "$WEBHOOK_URL" ]; then
    if [ "$WEBHOOK_URL" = "http://app:80/webhook/whatsapp/1" ]; then
        ok "Webhook URL: $WEBHOOK_URL"
    else
        warn "Webhook URL: $WEBHOOK_URL (expected http://app:80/webhook/whatsapp/1)"
        ISSUES+=("WEBHOOK_URL_WRONG")
    fi
else
    fail "No webhook URL configured"
    ISSUES+=("NO_WEBHOOK")
fi

# Webhook events
WEBHOOK_EVENTS=$(echo "$SESSION_JSON" | grep -oP '"events"\s*:\s*\[\K[^\]]*' | head -1 || echo "")
HAS_MESSAGE=false
HAS_MESSAGE_ANY=false
if [ -n "$WEBHOOK_EVENTS" ]; then
    info "Webhook events: [$WEBHOOK_EVENTS]"
    echo "$WEBHOOK_EVENTS" | grep -q '"message"' && HAS_MESSAGE=true
    echo "$WEBHOOK_EVENTS" | grep -q '"message.any"' && HAS_MESSAGE_ANY=true

    $HAS_MESSAGE && ok "Subscribed to 'message'" || fail "Missing 'message' event"
    $HAS_MESSAGE_ANY && ok "Subscribed to 'message.any' (groups)" || warn "Missing 'message.any' — group messages may be missed"
else
    if [ -n "$WEBHOOK_URL" ]; then
        warn "Webhook events: not specified (may default to all)"
    fi
fi

# Auto-fix: update webhook events if missing message.any
if [ -n "$WEBHOOK_URL" ] && ! $HAS_MESSAGE_ANY; then
    ISSUES+=("NO_MESSAGE_ANY")
    if ask_fix "Add 'message.any' event to webhook subscription"; then
        docker exec zeniclaw_app curl -sf -X PUT \
            -H "X-Api-Key: ${WAHA_KEY}" -H "Content-Type: application/json" \
            -d "{\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\",\"message.reaction\"]}]}}" \
            ${WAHA_BASE}/api/sessions/default >/dev/null 2>&1 || true
        # Verify
        sleep 1
        VERIFY=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default 2>/dev/null || echo "{}")
        if echo "$VERIFY" | grep -q "message.any"; then
            ok "message.any added successfully"
            HAS_MESSAGE_ANY=true
            FIXES_APPLIED=$((FIXES_APPLIED + 1))
        else
            fail "Could not add message.any (PUT may not be supported)"
            info "Will try session restart later if needed"
        fi
    fi
fi

# If webhook URL is wrong or missing events, offer full session recreate
if [[ " ${ISSUES[*]} " =~ " WEBHOOK_URL_WRONG " ]] || [[ " ${ISSUES[*]} " =~ " NO_WEBHOOK " ]] || (! $HAS_MESSAGE_ANY && [[ " ${ISSUES[*]} " =~ " NO_MESSAGE_ANY " ]]); then
    if ask_fix "Recreate session with correct webhook + events (stop → start)"; then
        info "Stopping session..."
        docker exec zeniclaw_app curl -sf -X POST -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default/stop >/dev/null 2>&1 || true
        sleep 2
        info "Starting session with full config..."
        docker exec zeniclaw_app curl -sf -X POST \
            -H "X-Api-Key: ${WAHA_KEY}" -H "Content-Type: application/json" \
            -d "{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\",\"message.reaction\"]}]}}" \
            ${WAHA_BASE}/api/sessions/start >/dev/null 2>&1
        sleep 3
        # Re-read session
        SESSION_JSON=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default 2>/dev/null || echo "{}")
        SESSION_STATUS=$(echo "$SESSION_JSON" | grep -oP '"status"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
        ok "Session recreated (status: $SESSION_STATUS)"
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
        if [ "$SESSION_STATUS" = "SCAN_QR_CODE" ]; then
            warn "QR scan required — go to Settings page"
        fi
    fi
fi

# ── 4. Connection Stability ──────────────────────────────────────
header "4/12" "Connection Stability"

RECENT_LOGS=$(docker logs zeniclaw_waha --since=60s 2>&1 || echo "")
DISCONNECT_COUNT=$(echo "$RECENT_LOGS" | grep -c "Connection closed" 2>/dev/null || true)
DISCONNECT_COUNT=${DISCONNECT_COUNT:-0}

if [ "$DISCONNECT_COUNT" -gt 5 ]; then
    fail "Reconnect loop: $DISCONNECT_COUNT disconnects in last 60s"
    ISSUES+=("RECONNECT_LOOP")
    if ask_fix "Reset session (stop → delete → recreate) — will need QR re-scan"; then
        info "Stopping..."
        docker exec zeniclaw_app curl -sf -X POST -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default/stop >/dev/null 2>&1 || true
        sleep 1
        info "Deleting..."
        docker exec zeniclaw_app curl -sf -X DELETE -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default >/dev/null 2>&1 || true
        sleep 1
        info "Recreating..."
        docker exec zeniclaw_app curl -sf -X POST \
            -H "X-Api-Key: ${WAHA_KEY}" -H "Content-Type: application/json" \
            -d "{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\",\"message.reaction\"]}]}}" \
            ${WAHA_BASE}/api/sessions/start >/dev/null 2>&1
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
        warn "Session recreated — scan QR code on Settings page"
    fi
elif [ "$DISCONNECT_COUNT" -gt 0 ]; then
    warn "Some disconnects: $DISCONNECT_COUNT in last 60s (may be transient)"
else
    ok "No disconnects in last 60s"
fi

# ── 5. Webhook delivery history ──────────────────────────────────
header "5/12" "Webhook Delivery"

APP_LOGS=$(docker logs zeniclaw_app --since=300s 2>&1 || echo "")
WEBHOOK_HITS=$(echo "$APP_LOGS" | grep -c "webhook/whatsapp" 2>/dev/null || true)
WEBHOOK_HITS=${WEBHOOK_HITS:-0}
MEDIA_WARNINGS=$(echo "$APP_LOGS" | grep -ci "media.*fail\|could not resolve mediaUrl\|resolveMediaUrl" 2>/dev/null || true)
MEDIA_WARNINGS=${MEDIA_WARNINGS:-0}

if [ "$WEBHOOK_HITS" -gt 0 ]; then
    ok "Webhooks received: $WEBHOOK_HITS in last 5 min"
else
    warn "No webhooks received in last 5 min"
    ISSUES+=("NO_WEBHOOKS")
fi

if [ "$MEDIA_WARNINGS" -gt 0 ]; then
    fail "Media resolution failures: $MEDIA_WARNINGS"
    ISSUES+=("MEDIA_FAIL")
    echo "$APP_LOGS" | grep -i "media.*fail\|could not resolve mediaUrl\|resolveMediaUrl" | tail -3 | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
fi

# ── 6. Internal connectivity ─────────────────────────────────────
header "6/12" "Internal Connectivity"

# Nginx
NGINX_STATUS=$(docker exec zeniclaw_app supervisorctl status nginx 2>/dev/null | head -1 || echo "unknown")
if echo "$NGINX_STATUS" | grep -q "RUNNING"; then
    ok "Nginx: running"
else
    fail "Nginx: $NGINX_STATUS"
    ISSUES+=("NGINX_DOWN")
    if ask_fix "Restart nginx"; then
        docker exec zeniclaw_app supervisorctl restart nginx
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
    fi
fi

# PHP-FPM
FPM_STATUS=$(docker exec zeniclaw_app supervisorctl status php-fpm 2>/dev/null | head -1 || echo "unknown")
if echo "$FPM_STATUS" | grep -q "RUNNING"; then
    ok "PHP-FPM: running"
else
    fail "PHP-FPM: $FPM_STATUS"
    ISSUES+=("FPM_DOWN")
    if ask_fix "Restart php-fpm"; then
        docker exec zeniclaw_app supervisorctl restart php-fpm
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
    fi
fi

# App self-check
APP_SELF_HTTP=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{http_code}" --max-time 5 http://localhost:80/health 2>/dev/null || echo "000")
if [ "$APP_SELF_HTTP" = "200" ]; then
    ok "App self-check: HTTP 200"
else
    fail "App self-check: HTTP $APP_SELF_HTTP"
    ISSUES+=("APP_NOT_RESPONDING")
    if ask_fix "Restart nginx + php-fpm"; then
        docker exec zeniclaw_app supervisorctl restart nginx php-fpm
        sleep 2
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
    fi
fi

# DNS from WAHA
WAHA_DNS=$(docker exec zeniclaw_waha node -e "
    const dns = require('dns');
    dns.lookup('app', (err, addr) => {
        if (err) process.stdout.write('fail:' + err.code);
        else process.stdout.write(addr);
    });
" 2>/dev/null || echo "unknown")
if echo "$WAHA_DNS" | grep -qP '^\d+\.\d+\.\d+\.\d+$'; then
    ok "DNS: app → $WAHA_DNS"
else
    fail "DNS resolution failed: $WAHA_DNS"
    ISSUES+=("DNS_BROKEN")
fi

# Response time
APP_RESPONSE_TIME=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{time_total}" --max-time 10 http://localhost:80/health 2>/dev/null || echo "timeout")
if [ "$APP_RESPONSE_TIME" != "timeout" ]; then
    SLOW=$(echo "$APP_RESPONSE_TIME" | awk '{print ($1 > 3.0) ? "yes" : "no"}')
    if [ "$SLOW" = "yes" ]; then
        warn "App response time: ${APP_RESPONSE_TIME}s (slow)"
        ISSUES+=("SLOW_APP")
    else
        ok "App response time: ${APP_RESPONSE_TIME}s"
    fi
fi

# WAHA → App HTTP
WAHA_TO_APP=$(docker exec zeniclaw_waha node -e "
    const http = require('http');
    const start = Date.now();
    let done = false;
    const req = http.get('http://app:80/health', {timeout: 5000}, (res) => {
        if (done) return; done = true;
        process.stdout.write(res.statusCode + ':' + (Date.now() - start) + 'ms');
        process.exit(0);
    });
    req.on('error', (e) => { if (!done) { done = true; process.stdout.write('err:' + e.code); process.exit(1); } });
    req.on('timeout', () => { if (!done) { done = true; req.destroy(); process.stdout.write('timeout'); process.exit(1); } });
" 2>/dev/null || echo "node_fail")

if echo "$WAHA_TO_APP" | grep -q "^200:"; then
    ok "WAHA → App: reachable ($WAHA_TO_APP)"
elif [ "$WAHA_TO_APP" = "timeout" ]; then
    fail "WAHA → App: timeout"
    ISSUES+=("WAHA_TO_APP_TIMEOUT")
elif echo "$WAHA_TO_APP" | grep -q "err:"; then
    fail "WAHA → App: $WAHA_TO_APP"
    ISSUES+=("WAHA_TO_APP_ERROR")
elif [ "$WAHA_TO_APP" = "node_fail" ]; then
    warn "WAHA → App: could not test"
else
    warn "WAHA → App: $WAHA_TO_APP"
fi

# Webhook route test
WEBHOOK_AGENT_ID=$(echo "$WEBHOOK_URL" | grep -oP '/webhook/whatsapp/\K\d+' || echo "1")
WEBHOOK_TEST=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{http_code}" --max-time 10 \
    -X POST -H "Content-Type: application/json" \
    -d '{"event":"message","payload":{"body":"diag-ping","from":"diag@test","fromMe":true}}' \
    "http://localhost:80/webhook/whatsapp/${WEBHOOK_AGENT_ID}" 2>/dev/null || echo "000")
if [ "$WEBHOOK_TEST" = "200" ]; then
    ok "Webhook route: /webhook/whatsapp/${WEBHOOK_AGENT_ID} (HTTP 200)"
elif [ "$WEBHOOK_TEST" = "404" ]; then
    fail "Webhook route: HTTP 404 — Agent ID ${WEBHOOK_AGENT_ID} not found!"
    ISSUES+=("AGENT_NOT_FOUND")
elif [ "$WEBHOOK_TEST" = "500" ]; then
    fail "Webhook route: HTTP 500"
    ISSUES+=("WEBHOOK_500")
elif [ "$WEBHOOK_TEST" = "000" ]; then
    fail "Webhook route: timeout"
    ISSUES+=("WEBHOOK_TIMEOUT")
else
    warn "Webhook route: HTTP $WEBHOOK_TEST"
fi

# ── 7. App Health ─────────────────────────────────────────────────
header "7/12" "App Health"

APP_ERRORS=$(echo "$APP_LOGS" | grep -c "production.ERROR" 2>/dev/null || true)
APP_ERRORS=${APP_ERRORS:-0}
QUEUE_RUNNING=$(docker exec zeniclaw_app supervisorctl status queue-default:queue-default_00 2>/dev/null | grep -c "RUNNING" || true)
QUEUE_RUNNING=${QUEUE_RUNNING:-0}

if [ "$QUEUE_RUNNING" -gt 0 ]; then
    ok "Queue workers: running"
else
    fail "Queue workers: not running"
    ISSUES+=("QUEUE_DOWN")
    if ask_fix "Restart queue workers"; then
        docker exec zeniclaw_app supervisorctl restart queue-default:queue-default_00 queue-default:queue-default_01 queue-default:queue-default_02 queue-low
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
    fi
fi

# DB
DB_CHECK=$(docker exec zeniclaw_app php -r "
try {
    \$pdo = new PDO('pgsql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
    echo 'ok';
} catch (Exception \$e) {
    echo 'fail: '.\$e->getMessage();
}
" 2>/dev/null || echo "fail: php error")
if echo "$DB_CHECK" | grep -q "^ok"; then
    ok "Database: reachable"
else
    fail "Database: $DB_CHECK"
    ISSUES+=("DB_DOWN")
    if ask_fix "Start DB container"; then
        docker compose up -d db && sleep 5
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
    fi
fi

# Redis
REDIS_CHECK=$(docker exec zeniclaw_app php -r "
try {
    \$r = new Redis(); \$r->connect(getenv('REDIS_HOST') ?: 'redis', 6379);
    echo 'ok';
} catch (Exception \$e) {
    echo 'fail: '.\$e->getMessage();
}
" 2>/dev/null || echo "fail: php error")
if echo "$REDIS_CHECK" | grep -q "^ok"; then
    ok "Redis: reachable"
else
    fail "Redis: $REDIS_CHECK"
    ISSUES+=("REDIS_DOWN")
    if ask_fix "Start Redis container"; then
        docker compose up -d redis && sleep 3
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
    fi
fi

# Supervisord
info "Supervisord:"
docker exec zeniclaw_app supervisorctl status 2>/dev/null | while read -r line; do
    if echo "$line" | grep -q "RUNNING"; then
        echo -e "    ${GREEN}✓${NC} $line"
    elif echo "$line" | grep -q "STOPPED\|FATAL\|EXITED"; then
        echo -e "    ${RED}✗${NC} $line"
    else
        echo -e "    ${YELLOW}?${NC} $line"
    fi
done

if [ "$APP_ERRORS" -gt 0 ]; then
    warn "App errors in last 5 min: $APP_ERRORS"
    echo "$APP_LOGS" | grep "production.ERROR" | tail -3 | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
else
    ok "No app errors in last 5 min"
fi

# ── 8. Docker Network ────────────────────────────────────────────
header "8/12" "Docker Network"

APP_NETWORKS=$(docker inspect zeniclaw_app --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' 2>/dev/null || echo "none")
WAHA_NETWORKS=$(docker inspect zeniclaw_waha --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' 2>/dev/null || echo "none")
APP_IP=$(docker inspect zeniclaw_app --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "?")
WAHA_IP=$(docker inspect zeniclaw_waha --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "?")

info "App:  $APP_NETWORKS (IP: $APP_IP)"
info "WAHA: $WAHA_NETWORKS (IP: $WAHA_IP)"

SHARED=false
for net in $APP_NETWORKS; do
    echo "$WAHA_NETWORKS" | grep -q "$net" && SHARED=true && break
done

if $SHARED; then
    ok "Containers share a network"
else
    fail "Containers on different networks!"
    ISSUES+=("NETWORK_SPLIT")
    if ask_fix "Recreate containers on same network"; then
        docker compose down && docker compose up -d
        sleep 5
        FIXES_APPLIED=$((FIXES_APPLIED + 1))
    fi
fi

# ── 9. WAHA Chats ────────────────────────────────────────────────
header "9/12" "WAHA Chats & Groups"

CHATS_JSON=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" "${WAHA_BASE}/api/default/chats?limit=10&sortBy=lastMessageAt&sortOrder=desc" 2>/dev/null || echo "[]")
CHAT_COUNT=$(echo "$CHATS_JSON" | grep -oP '"id"\s*:' | wc -l 2>/dev/null || true)
CHAT_COUNT=$(echo "${CHAT_COUNT:-0}" | tr -d '[:space:]')

if [ "$CHAT_COUNT" -gt 0 ]; then
    ok "WAHA sees $CHAT_COUNT recent chats"
    echo ""
    echo "$CHATS_JSON" | python3 -c "
import sys, json
try:
    chats = json.load(sys.stdin)
    if isinstance(chats, list):
        for c in chats[:10]:
            cid = c.get('id','?')
            name = c.get('name') or c.get('pushName') or '(no name)'
            is_group = '@g.us' in cid
            ctype = 'GROUP' if is_group else 'DM'
            print(f'    {ctype:6s} | {name[:30]:30s} | {cid}')
except: pass
" 2>/dev/null || info "Could not parse chats"
else
    warn "WAHA reports no recent chats (session may be stale)"
    ISSUES+=("NO_CHATS")
fi

# ── 10. WAHA Logs ────────────────────────────────────────────────
header "10/12" "WAHA Logs (last 10 min)"

WAHA_RECENT=$(docker logs zeniclaw_waha --since=600s 2>&1 || echo "")
WAHA_MSG_COUNT=$(echo "$WAHA_RECENT" | grep -ci "message\|webhook\|event\|received" 2>/dev/null || true)
WAHA_MSG_COUNT=${WAHA_MSG_COUNT:-0}
WAHA_ERR_COUNT=$(echo "$WAHA_RECENT" | grep -ci "error\|fail\|exception" 2>/dev/null || true)
WAHA_ERR_COUNT=${WAHA_ERR_COUNT:-0}
WAHA_TOTAL=$(echo "$WAHA_RECENT" | wc -l 2>/dev/null || true)
WAHA_TOTAL=$(echo "${WAHA_TOTAL:-0}" | tr -d '[:space:]')

info "Total lines: $WAHA_TOTAL | Activity: $WAHA_MSG_COUNT | Errors: $WAHA_ERR_COUNT"

if [ "$WAHA_MSG_COUNT" -gt 0 ]; then
    ok "WAHA message activity detected"
    echo "$WAHA_RECENT" | grep -i "message\|webhook\|event\|received" | tail -5 | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
fi

if [ "$WAHA_ERR_COUNT" -gt 0 ]; then
    fail "WAHA errors found"
    echo "$WAHA_RECENT" | grep -i "error\|fail\|exception" | tail -5 | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done

    WH_FAILS=$(echo "$WAHA_RECENT" | grep -c "Webhook delivery failed" 2>/dev/null || true)
    WH_FAILS=${WH_FAILS:-0}
    [ "$WH_FAILS" -gt 0 ] && ISSUES+=("WEBHOOK_DELIVERY_FAIL")

    TIMEOUTS=$(echo "$WAHA_RECENT" | grep -c "aborted due to timeout" 2>/dev/null || true)
    TIMEOUTS=${TIMEOUTS:-0}
    [ "$TIMEOUTS" -gt 0 ] && ISSUES+=("WAHA_TIMEOUT")
else
    ok "No WAHA errors"
fi

# Last 15 raw lines
echo ""
info "Last 15 raw WAHA log lines:"
echo "$WAHA_RECENT" | tail -15 | while read -r line; do
    echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
done

# ── 11. App Logs ─────────────────────────────────────────────────
header "11/12" "App Logs (last 10 min)"

APP_LOGS_FULL=$(docker logs zeniclaw_app --since=600s 2>&1 || echo "")
APP_WH_LINES=$(echo "$APP_LOGS_FULL" | grep -i "webhook\|whatsapp\|message.received\|channel" 2>/dev/null | tail -10 || true)
APP_ERR_LINES=$(echo "$APP_LOGS_FULL" | grep -i "production.ERROR\|Exception\|CRITICAL" 2>/dev/null | tail -5 || true)
APP_QUEUE_LINES=$(echo "$APP_LOGS_FULL" | grep -i "queue\|job\|RunTask\|processed" 2>/dev/null | tail -5 || true)

if [ -n "$APP_WH_LINES" ]; then
    ok "Webhook activity found"
    echo "$APP_WH_LINES" | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
else
    warn "No webhook lines in app logs"
fi

if [ -n "$APP_ERR_LINES" ]; then
    fail "App errors:"
    echo "$APP_ERR_LINES" | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
else
    ok "No errors in app logs"
fi

if [ -n "$APP_QUEUE_LINES" ]; then
    info "Queue activity:"
    echo "$APP_QUEUE_LINES" | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
fi

# ══════════════════════════════════════════════════════════════════
#  PHASE 2 — LIVE TEST
# ══════════════════════════════════════════════════════════════════
header "12/12" "Live Message Test"

# Re-read session after fixes
if [ "$FIXES_APPLIED" -gt 0 ]; then
    SESSION_JSON=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default 2>/dev/null || echo "{}")
    SESSION_STATUS=$(echo "$SESSION_JSON" | grep -oP '"status"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
    SESSION_PHONE=$(echo "$SESSION_JSON" | grep -oP '"id"\s*:\s*"\K[^":]+' | head -1 || echo "")
fi

RUN_LIVE=false
if [ "$AUTO_FIX" = true ]; then
    RUN_LIVE=true
elif [ "$SESSION_STATUS" = "WORKING" ] && [ -n "$SESSION_PHONE" ]; then
    echo ""
    echo -ne "  ${YELLOW}Run live test? Sends a WhatsApp message to self to test round-trip${NC} [y/N] "
    read -r answer
    [[ "$answer" =~ ^[yYoO] ]] && RUN_LIVE=true
fi

if [ "$RUN_LIVE" = true ] && [ "$SESSION_STATUS" = "WORKING" ] && [ -n "$SESSION_PHONE" ]; then
    SELF_JID="${SESSION_PHONE}@s.whatsapp.net"
    TEST_TS=$(date +%s)
    TEST_BODY="[ZeniClaw Diag] ping-${TEST_TS}"

    info "Sending test message to self (+${SESSION_PHONE})..."

    SEND_RESULT=$(docker exec zeniclaw_app curl -sf -w "\n%{http_code}" \
        -X POST -H "X-Api-Key: ${WAHA_KEY}" -H "Content-Type: application/json" \
        -d "{\"chatId\":\"${SELF_JID}\",\"text\":\"${TEST_BODY}\",\"session\":\"default\"}" \
        "${WAHA_BASE}/api/sendText" 2>/dev/null || echo -e "\n000")
    SEND_HTTP=$(echo "$SEND_RESULT" | tail -1)

    if [ "$SEND_HTTP" = "201" ] || [ "$SEND_HTTP" = "200" ]; then
        ok "Message sent (HTTP $SEND_HTTP)"

        # Wait for webhook
        info "Waiting for webhook round-trip (max 20s)..."
        FOUND=false
        for i in $(seq 1 20); do
            sleep 1
            CHECK_LOGS=$(docker logs zeniclaw_app --since=25s 2>&1 || echo "")
            if echo "$CHECK_LOGS" | grep -q "ping-${TEST_TS}\|webhook/whatsapp"; then
                FOUND=true
                ok "Webhook received after ${i}s! Round-trip works!"
                break
            fi
            printf "  ${DIM}  ...%ds${NC}\r" "$i"
        done
        echo ""

        if [ "$FOUND" = false ]; then
            fail "Webhook NOT received after 20s"

            # Check WAHA side
            info "Checking WAHA delivery logs..."
            WAHA_TEST_LOGS=$(docker logs zeniclaw_waha --since=30s 2>&1 || echo "")
            WAHA_DELIVERY=$(echo "$WAHA_TEST_LOGS" | grep -i "webhook\|delivery\|ping-${TEST_TS}" || true)

            if [ -n "$WAHA_DELIVERY" ]; then
                info "WAHA delivery attempt:"
                echo "$WAHA_DELIVERY" | while read -r line; do
                    echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
                done
                if echo "$WAHA_DELIVERY" | grep -q "failed\|timeout\|error"; then
                    ISSUES+=("LIVE_DELIVERY_FAILED")
                fi
            else
                fail "WAHA did not even attempt delivery"
                ISSUES+=("LIVE_NO_DELIVERY")
                info "This means WAHA received the message but didn't fire a webhook event"
                info "Likely cause: event subscription issue"

                if ask_fix "Full session reset (stop → delete → recreate) — QR scan needed"; then
                    docker exec zeniclaw_app curl -sf -X POST -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default/stop >/dev/null 2>&1 || true
                    sleep 1
                    docker exec zeniclaw_app curl -sf -X DELETE -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default >/dev/null 2>&1 || true
                    sleep 1
                    docker exec zeniclaw_app curl -sf -X POST \
                        -H "X-Api-Key: ${WAHA_KEY}" -H "Content-Type: application/json" \
                        -d "{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\",\"message.reaction\"]}]}}" \
                        ${WAHA_BASE}/api/sessions/start >/dev/null 2>&1
                    FIXES_APPLIED=$((FIXES_APPLIED + 1))
                    warn "Session recreated — go to Settings page to scan QR code"
                    info "Then run this script again to verify"
                fi
            fi

            # Also check app-side for errors
            APP_RECENT=$(docker logs zeniclaw_app --since=30s 2>&1 || echo "")
            APP_RECENT_ERRS=$(echo "$APP_RECENT" | grep -i "error\|exception\|500" || true)
            if [ -n "$APP_RECENT_ERRS" ]; then
                fail "App errors during live test:"
                echo "$APP_RECENT_ERRS" | tail -3 | while read -r line; do
                    echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
                done
            fi
        fi
    else
        fail "Could not send test message (HTTP $SEND_HTTP)"
        ISSUES+=("LIVE_SEND_FAILED")
        info "WAHA may not be able to send messages — check session status"
    fi

elif [ "$SESSION_STATUS" != "WORKING" ]; then
    warn "Cannot run live test — session is $SESSION_STATUS"
elif [ -z "$SESSION_PHONE" ]; then
    warn "Cannot run live test — no phone number"
else
    info "Live test skipped"
fi

# ═══════════════════════════════════════════════════════════════════
#  SESSION JSON DUMP
# ═══════════════════════════════════════════════════════════════════
echo -e "\n${BOLD}Full WAHA Session Config:${NC}"
# Re-read after all fixes
SESSION_JSON=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default 2>/dev/null || echo "{}")
echo "$SESSION_JSON" | python3 -m json.tool 2>/dev/null || echo "$SESSION_JSON"

# ═══════════════════════════════════════════════════════════════════
#  SUMMARY
# ═══════════════════════════════════════════════════════════════════
echo -e "\n${BOLD}${CYAN}════════════════════════════════════════════${NC}"
echo -e "${BOLD}${CYAN}  Diagnostic Summary${NC}"
echo -e "${BOLD}${CYAN}════════════════════════════════════════════${NC}\n"

if [ "$FIXES_APPLIED" -gt 0 ]; then
    echo -e "  ${GREEN}${BOLD}Applied $FIXES_APPLIED fix(es) during diagnostic${NC}\n"
fi

if [ ${#ISSUES[@]} -eq 0 ]; then
    echo -e "  ${GREEN}${BOLD}All checks passed! WhatsApp should be working.${NC}"
else
    # Deduplicate issues
    UNIQUE_ISSUES=($(printf '%s\n' "${ISSUES[@]}" | sort -u))
    echo -e "  ${RED}${BOLD}Issues detected: ${#UNIQUE_ISSUES[@]}${NC}\n"
    for issue in "${UNIQUE_ISSUES[@]}"; do
        case "$issue" in
            APP_DOWN)           echo -e "  ${RED}•${NC} App container not running" ;;
            WAHA_DOWN)          echo -e "  ${RED}•${NC} WAHA container not running" ;;
            WAHA_AUTH)          echo -e "  ${RED}•${NC} WAHA API key mismatch" ;;
            WAHA_UNREACHABLE)   echo -e "  ${RED}•${NC} WAHA API unreachable" ;;
            QR_NEEDED)          echo -e "  ${YELLOW}•${NC} WhatsApp needs QR scan (Settings page)" ;;
            SESSION_*)          echo -e "  ${RED}•${NC} WhatsApp session: $issue" ;;
            WEBHOOK_URL_WRONG)  echo -e "  ${RED}•${NC} Webhook URL misconfigured" ;;
            NO_WEBHOOK)         echo -e "  ${RED}•${NC} No webhook URL configured" ;;
            NO_MESSAGE_ANY)     echo -e "  ${YELLOW}•${NC} Missing 'message.any' event (group messages)" ;;
            RECONNECT_LOOP)     echo -e "  ${RED}•${NC} WAHA reconnect loop — session corrupted" ;;
            NO_WEBHOOKS)        echo -e "  ${YELLOW}•${NC} No webhook traffic in last 5 min" ;;
            MEDIA_FAIL)         echo -e "  ${RED}•${NC} Media URL resolution failures" ;;
            NGINX_DOWN)         echo -e "  ${RED}•${NC} Nginx down in app container" ;;
            FPM_DOWN)           echo -e "  ${RED}•${NC} PHP-FPM down in app container" ;;
            APP_NOT_RESPONDING) echo -e "  ${RED}•${NC} App not responding on port 80" ;;
            DNS_BROKEN)         echo -e "  ${RED}•${NC} Docker DNS broken" ;;
            SLOW_APP)           echo -e "  ${YELLOW}•${NC} App responding slowly" ;;
            WAHA_TO_APP_*)      echo -e "  ${RED}•${NC} WAHA cannot reach app" ;;
            AGENT_NOT_FOUND)    echo -e "  ${RED}•${NC} Agent ID not found (webhook 404)" ;;
            WEBHOOK_500)        echo -e "  ${RED}•${NC} Webhook route returns 500" ;;
            WEBHOOK_TIMEOUT)    echo -e "  ${RED}•${NC} Webhook route timeout" ;;
            QUEUE_DOWN)         echo -e "  ${RED}•${NC} Queue workers down" ;;
            DB_DOWN)            echo -e "  ${RED}•${NC} Database unreachable" ;;
            REDIS_DOWN)         echo -e "  ${RED}•${NC} Redis unreachable" ;;
            NETWORK_SPLIT)      echo -e "  ${RED}•${NC} Containers on different networks" ;;
            NO_CHATS)           echo -e "  ${YELLOW}•${NC} WAHA sees no chats (stale session?)" ;;
            WEBHOOK_DELIVERY_FAIL) echo -e "  ${RED}•${NC} WAHA webhook delivery failures" ;;
            WAHA_TIMEOUT)       echo -e "  ${RED}•${NC} WAHA → App timeout" ;;
            LIVE_DELIVERY_FAILED) echo -e "  ${RED}•${NC} Live test: WAHA delivery failed" ;;
            LIVE_NO_DELIVERY)   echo -e "  ${RED}•${NC} Live test: WAHA didn't fire webhook" ;;
            LIVE_SEND_FAILED)   echo -e "  ${RED}•${NC} Live test: could not send message" ;;
            *)                  echo -e "  ${RED}•${NC} $issue" ;;
        esac
    done

    if [ "$FIXES_APPLIED" -gt 0 ]; then
        echo -e "\n  ${CYAN}Run the script again to verify fixes took effect.${NC}"
    fi
fi

echo ""
