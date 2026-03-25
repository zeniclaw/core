#!/usr/bin/env bash
# ZeniClaw — WhatsApp/WAHA Diagnostic Script (v3)
# Usage: bash debug-whatsapp.sh [--live-test]
#   --live-test  Send a real test message via WAHA and verify round-trip

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

ISSUES=()
ACTIONS=()
LIVE_TEST=false
[ "${1:-}" = "--live-test" ] && LIVE_TEST=true

WAHA_BASE="http://waha:3000"
WAHA_KEY="zeniclaw-waha-2026"
WAHA_CURL="docker exec zeniclaw_app curl -sf"
WAHA_HEADERS="-H X-Api-Key:${WAHA_KEY}"

echo -e "\n${BOLD}${CYAN}════════════════════════════════════════════${NC}"
echo -e "${BOLD}${CYAN}  ZeniClaw WhatsApp Diagnostic v3${NC}"
echo -e "${BOLD}${CYAN}════════════════════════════════════════════${NC}\n"

# ── 1. Container status ──────────────────────────────────────────
header "1/12" "Container Status"

APP_STATUS=$(docker inspect zeniclaw_app --format '{{.State.Status}}' 2>/dev/null || echo "not_found")
WAHA_STATUS=$(docker inspect zeniclaw_waha --format '{{.State.Status}}' 2>/dev/null || echo "not_found")

if [ "$APP_STATUS" = "running" ]; then
    APP_UPTIME=$(docker inspect zeniclaw_app --format '{{.State.StartedAt}}' 2>/dev/null)
    ok "zeniclaw_app: running (since $APP_UPTIME)"
else
    fail "zeniclaw_app: $APP_STATUS"
    ISSUES+=("App container is not running")
    ACTIONS+=("docker compose up -d app")
fi

if [ "$WAHA_STATUS" = "running" ]; then
    WAHA_UPTIME=$(docker inspect zeniclaw_waha --format '{{.State.StartedAt}}' 2>/dev/null)
    ok "zeniclaw_waha: running (since $WAHA_UPTIME)"
else
    fail "zeniclaw_waha: $WAHA_STATUS"
    ISSUES+=("WAHA container is not running")
    ACTIONS+=("docker compose up -d waha")
fi

# ── 2. WAHA API & Version ────────────────────────────────────────
header "2/12" "WAHA API & Version"

WAHA_HEALTH=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{http_code}" -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/sessions/default 2>/dev/null || echo "000")

if [ "$WAHA_HEALTH" = "200" ]; then
    ok "WAHA API reachable (HTTP 200)"
elif [ "$WAHA_HEALTH" = "401" ]; then
    fail "WAHA API: HTTP 401 (wrong API key)"
    ISSUES+=("WAHA API key mismatch")
    ACTIONS+=("Check WAHA_API_KEY in docker-compose.yml matches the app")
elif [ "$WAHA_HEALTH" = "000" ]; then
    fail "WAHA API: unreachable (connection refused)"
    ISSUES+=("App cannot reach WAHA — network issue")
    ACTIONS+=("docker restart zeniclaw_waha")
else
    warn "WAHA API: HTTP $WAHA_HEALTH"
fi

# WAHA version & engine
WAHA_VERSION=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/version 2>/dev/null || echo "{}")
WAHA_VER=$(echo "$WAHA_VERSION" | grep -oP '"version"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
WAHA_ENGINE=$(echo "$WAHA_VERSION" | grep -oP '"engine"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
info "WAHA version: $WAHA_VER (engine: $WAHA_ENGINE)"

# WAHA environment / server info
WAHA_ENV=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" ${WAHA_BASE}/api/server/environment 2>/dev/null || echo "{}")
if [ "$WAHA_ENV" != "{}" ] && [ -n "$WAHA_ENV" ]; then
    WAHA_TIER=$(echo "$WAHA_ENV" | grep -oP '"tier"\s*:\s*"\K[^"]+' | head -1 || echo "unknown")
    info "WAHA tier: $WAHA_TIER"
fi

# ── 3. WhatsApp session status ───────────────────────────────────
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
        ISSUES+=("WhatsApp session needs QR scan")
        ACTIONS+=("Go to Settings page and scan QR code")
        ;;
    STOPPED|FAILED)
        fail "Session: $SESSION_STATUS"
        ISSUES+=("WhatsApp session is $SESSION_STATUS")
        ACTIONS+=("Restart session: docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: ${WAHA_KEY}' -H 'Content-Type: application/json' -d '{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\"]}]}}' ${WAHA_BASE}/api/sessions/start")
        ;;
    *)
        warn "Session: $SESSION_STATUS"
        ;;
esac

# Webhook URL check
if [ -n "$WEBHOOK_URL" ]; then
    if [ "$WEBHOOK_URL" = "http://app:80/webhook/whatsapp/1" ]; then
        ok "Webhook URL: $WEBHOOK_URL"
    else
        warn "Webhook URL: $WEBHOOK_URL (expected http://app:80/webhook/whatsapp/1)"
        ISSUES+=("Webhook URL is not pointing to the app")
        ACTIONS+=("Recreate session with correct webhook URL")
    fi
else
    fail "No webhook URL configured"
    ISSUES+=("No webhook URL — WAHA won't forward messages to the app")
    ACTIONS+=("Recreate session with webhook: docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: ${WAHA_KEY}' -H 'Content-Type: application/json' -d '{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\"]}]}}' ${WAHA_BASE}/api/sessions/start")
fi

# Webhook events check
WEBHOOK_EVENTS=$(echo "$SESSION_JSON" | grep -oP '"events"\s*:\s*\[\K[^\]]*' | head -1 || echo "")
if [ -n "$WEBHOOK_EVENTS" ]; then
    info "Webhook events: [$WEBHOOK_EVENTS]"
    if echo "$WEBHOOK_EVENTS" | grep -q "message"; then
        ok "Subscribed to 'message' event"
    else
        fail "Missing 'message' event subscription!"
        ISSUES+=("Webhook events don't include 'message'")
        ACTIONS+=("Recreate session with message event")
    fi
    # Check if "message.any" is included (needed for group messages in some WAHA versions)
    if echo "$WEBHOOK_EVENTS" | grep -q "message.any"; then
        ok "Subscribed to 'message.any' (includes group messages)"
    else
        warn "Not subscribed to 'message.any' — group messages may not trigger webhooks"
        ISSUES+=("Webhook not subscribed to 'message.any' — group messages may be missed")
        ACTIONS+=("Recreate session with 'message.any' event:\n  docker exec zeniclaw_app curl -sf -X PUT -H 'X-Api-Key: ${WAHA_KEY}' -H 'Content-Type: application/json' -d '{\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\",\"message.reaction\"]}]}}' ${WAHA_BASE}/api/sessions/default")
    fi
else
    if [ -n "$WEBHOOK_URL" ]; then
        warn "Webhook events: not specified (WAHA may default to all events)"
    fi
fi

# ── 4. Connection Stability ──────────────────────────────────────
header "4/12" "Connection Stability"

RECENT_LOGS=$(docker logs zeniclaw_waha --since=60s 2>&1 || echo "")
DISCONNECT_COUNT=$(echo "$RECENT_LOGS" | grep -c "Connection closed" 2>/dev/null || true)
DISCONNECT_COUNT=${DISCONNECT_COUNT:-0}
RECONNECT_COUNT=$(echo "$RECENT_LOGS" | grep -c "Reconnecting" 2>/dev/null || true)
RECONNECT_COUNT=${RECONNECT_COUNT:-0}

if [ "$DISCONNECT_COUNT" -gt 5 ]; then
    fail "Reconnect loop detected: $DISCONNECT_COUNT disconnects in last 60s"
    ISSUES+=("WAHA is in a reconnect loop (status 440) — session is corrupted")
    ACTIONS+=("Reset session:\n  docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: ${WAHA_KEY}' ${WAHA_BASE}/api/sessions/default/stop\n  docker exec zeniclaw_app curl -sf -X DELETE -H 'X-Api-Key: ${WAHA_KEY}' ${WAHA_BASE}/api/sessions/default\n  docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: ${WAHA_KEY}' -H 'Content-Type: application/json' -d '{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\"]}]}}' ${WAHA_BASE}/api/sessions/start\n  Then scan QR code on Settings page")
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
    if [ "$DISCONNECT_COUNT" -gt 5 ]; then
        info "Likely caused by WAHA reconnect loop (see above)"
    else
        ISSUES+=("No webhook traffic — messages may not be forwarded")
        ACTIONS+=("Send a test message on WhatsApp and check again, or run: bash debug-whatsapp.sh --live-test")
    fi
fi

if [ "$MEDIA_WARNINGS" -gt 0 ]; then
    fail "Media resolution failures: $MEDIA_WARNINGS"
    ISSUES+=("Media URL resolution failing — images not being processed")
    echo ""
    info "Recent media errors:"
    echo "$APP_LOGS" | grep -i "media.*fail\|could not resolve mediaUrl\|resolveMediaUrl" | tail -5 | while read -r line; do
        echo -e "    ${DIM}$line${NC}"
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
    ISSUES+=("Nginx is not running inside zeniclaw_app")
    ACTIONS+=("docker exec zeniclaw_app supervisorctl restart nginx")
fi

# PHP-FPM
FPM_STATUS=$(docker exec zeniclaw_app supervisorctl status php-fpm 2>/dev/null | head -1 || echo "unknown")
if echo "$FPM_STATUS" | grep -q "RUNNING"; then
    ok "PHP-FPM: running"
else
    fail "PHP-FPM: $FPM_STATUS"
    ISSUES+=("PHP-FPM is not running inside zeniclaw_app")
    ACTIONS+=("docker exec zeniclaw_app supervisorctl restart php-fpm")
fi

# App self-check
APP_SELF_HTTP=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{http_code}" --max-time 5 http://localhost:80/health 2>/dev/null || echo "000")
if [ "$APP_SELF_HTTP" = "200" ]; then
    ok "App self-check: HTTP 200 (localhost:80/health)"
else
    fail "App self-check: HTTP $APP_SELF_HTTP (localhost:80/health)"
    ISSUES+=("App is not responding on port 80 internally — nginx or PHP-FPM may be down")
    ACTIONS+=("docker exec zeniclaw_app supervisorctl restart nginx php-fpm")
fi

# DNS resolution from WAHA → app
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
    ISSUES+=("WAHA cannot resolve 'app' hostname — Docker DNS broken")
    ACTIONS+=("docker compose down && docker compose up -d")
fi

# App response time
APP_RESPONSE_TIME=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{time_total}" --max-time 10 http://localhost:80/health 2>/dev/null || echo "timeout")
if [ "$APP_RESPONSE_TIME" != "timeout" ]; then
    SLOW=$(echo "$APP_RESPONSE_TIME" | awk '{print ($1 > 3.0) ? "yes" : "no"}')
    if [ "$SLOW" = "yes" ]; then
        warn "App response time: ${APP_RESPONSE_TIME}s (slow — WAHA may timeout)"
        ISSUES+=("App response time is slow (${APP_RESPONSE_TIME}s) — WAHA webhooks may timeout")
        ACTIONS+=("Check PHP-FPM processes and database connections")
    else
        ok "App response time: ${APP_RESPONSE_TIME}s"
    fi
fi

# WAHA → App HTTP check (node — WAHA has no curl/wget)
WAHA_TO_APP=$(docker exec zeniclaw_waha node -e "
    const http = require('http');
    const start = Date.now();
    let done = false;
    const req = http.get('http://app:80/health', {timeout: 5000}, (res) => {
        if (done) return;
        done = true;
        const ms = Date.now() - start;
        process.stdout.write(res.statusCode + ':' + ms + 'ms');
        process.exit(0);
    });
    req.on('error', (e) => { if (!done) { done = true; process.stdout.write('err:' + e.code); process.exit(1); } });
    req.on('timeout', () => { if (!done) { done = true; req.destroy(); process.stdout.write('timeout'); process.exit(1); } });
" 2>/dev/null || echo "node_fail")

if echo "$WAHA_TO_APP" | grep -q "^200:"; then
    ok "WAHA → App: reachable ($WAHA_TO_APP)"
elif [ "$WAHA_TO_APP" = "timeout" ]; then
    fail "WAHA → App: timeout (5s)"
    ISSUES+=("WAHA cannot reach app on port 80 (timeout) — webhook delivery will fail")
    ACTIONS+=("docker exec zeniclaw_app supervisorctl restart nginx php-fpm")
elif echo "$WAHA_TO_APP" | grep -q "err:"; then
    fail "WAHA → App: $WAHA_TO_APP"
    ISSUES+=("WAHA cannot connect to app ($WAHA_TO_APP)")
    ACTIONS+=("docker compose down && docker compose up -d")
elif [ "$WAHA_TO_APP" = "node_fail" ]; then
    warn "WAHA → App: could not test (node not available)"
else
    warn "WAHA → App: $WAHA_TO_APP"
fi

# Webhook route test (fromMe:true = ignored by app, safe for testing)
WEBHOOK_AGENT_ID=$(echo "$WEBHOOK_URL" | grep -oP '/webhook/whatsapp/\K\d+' || echo "1")
WEBHOOK_TEST=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{http_code}" --max-time 10 \
    -X POST -H "Content-Type: application/json" \
    -d '{"event":"message","payload":{"body":"diag-ping","from":"diag@test","fromMe":true}}' \
    "http://localhost:80/webhook/whatsapp/${WEBHOOK_AGENT_ID}" 2>/dev/null || echo "000")
if [ "$WEBHOOK_TEST" = "200" ]; then
    ok "Webhook route: /webhook/whatsapp/${WEBHOOK_AGENT_ID} responds (HTTP 200)"
elif [ "$WEBHOOK_TEST" = "404" ]; then
    fail "Webhook route: HTTP 404 — Agent ID ${WEBHOOK_AGENT_ID} not found!"
    ISSUES+=("Agent ID ${WEBHOOK_AGENT_ID} does not exist — webhook will 404")
    ACTIONS+=("Check agent ID: docker exec zeniclaw_app php artisan tinker --execute=\"echo App\\\\Models\\\\Agent::pluck('id','name');\"")
elif [ "$WEBHOOK_TEST" = "500" ]; then
    fail "Webhook route: HTTP 500 — server error"
    ISSUES+=("Webhook route returns 500 — check app error logs")
    ACTIONS+=("docker logs zeniclaw_app --since=60s 2>&1 | grep -i error | tail -10")
elif [ "$WEBHOOK_TEST" = "000" ]; then
    fail "Webhook route: timeout or unreachable"
    ISSUES+=("Webhook route did not respond in 10s")
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
    ISSUES+=("Queue workers are down — messages won't be processed")
    ACTIONS+=("docker exec zeniclaw_app supervisorctl restart queue-default:* queue-low")
fi

# DB connectivity
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
    ISSUES+=("Database connection failed — app cannot process messages")
    ACTIONS+=("Check DB container: docker compose up -d db")
fi

# Redis connectivity
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
    ISSUES+=("Redis connection failed — queue and cache broken")
    ACTIONS+=("Check Redis container: docker compose up -d redis")
fi

# Supervisord full status
info "Supervisord services:"
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
    echo ""
    info "Recent errors:"
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

APP_IP=$(docker inspect zeniclaw_app --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "unknown")
WAHA_IP=$(docker inspect zeniclaw_waha --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "unknown")

info "App:  $APP_NETWORKS (IP: $APP_IP)"
info "WAHA: $WAHA_NETWORKS (IP: $WAHA_IP)"

SHARED=false
for net in $APP_NETWORKS; do
    if echo "$WAHA_NETWORKS" | grep -q "$net"; then
        SHARED=true
        break
    fi
done

if $SHARED; then
    ok "Containers share a network"
else
    fail "Containers are on different networks!"
    ISSUES+=("App and WAHA are not on the same Docker network")
    ACTIONS+=("docker compose down && docker compose up -d")
fi

# ── 9. WAHA Chats & Groups ───────────────────────────────────────
header "9/12" "WAHA Chats & Groups"

# List recent chats to verify WAHA sees conversations
CHATS_JSON=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: ${WAHA_KEY}" "${WAHA_BASE}/api/default/chats?limit=10&sortBy=lastMessageAt&sortOrder=desc" 2>/dev/null || echo "[]")
CHAT_COUNT=$(echo "$CHATS_JSON" | grep -oP '"id"\s*:' | wc -l 2>/dev/null || true)
CHAT_COUNT=$(echo "${CHAT_COUNT:-0}" | tr -d '[:space:]')

if [ "$CHAT_COUNT" -gt 0 ]; then
    ok "WAHA sees $CHAT_COUNT recent chats"
    echo ""
    # Show chat list with type (group vs DM)
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
            last = c.get('lastMessage',{}).get('timestamp','')
            print(f'    {ctype:6s} | {name[:30]:30s} | {cid}')
except: pass
" 2>/dev/null || info "Could not parse chats JSON"
else
    warn "WAHA reports no recent chats"
    ISSUES+=("WAHA has no chats — session may be stale or newly created")
fi

# ── 10. WAHA Logs ────────────────────────────────────────────────
header "10/12" "WAHA Logs (last 10 min)"

WAHA_RECENT=$(docker logs zeniclaw_waha --since=600s 2>&1 || echo "")
WAHA_MSG_COUNT=$(echo "$WAHA_RECENT" | grep -ci "message\|webhook\|event\|received" 2>/dev/null || true)
WAHA_MSG_COUNT=${WAHA_MSG_COUNT:-0}
WAHA_ERR_COUNT=$(echo "$WAHA_RECENT" | grep -ci "error\|fail\|exception" 2>/dev/null || true)
WAHA_ERR_COUNT=${WAHA_ERR_COUNT:-0}
WAHA_TOTAL_LINES=$(echo "$WAHA_RECENT" | wc -l || echo "0")

info "Total WAHA log lines (10 min): $WAHA_TOTAL_LINES"

if [ "$WAHA_MSG_COUNT" -gt 0 ]; then
    ok "WAHA activity: $WAHA_MSG_COUNT message/event lines"
    echo ""
    info "Recent WAHA message activity:"
    echo "$WAHA_RECENT" | grep -i "message\|webhook\|event\|received" | tail -10 | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
else
    warn "No message activity in WAHA logs (last 10 min)"
fi

if [ "$WAHA_ERR_COUNT" -gt 0 ]; then
    fail "WAHA errors: $WAHA_ERR_COUNT"
    echo ""
    info "Recent WAHA errors:"
    echo "$WAHA_RECENT" | grep -i "error\|fail\|exception" | tail -5 | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done

    # Detect specific known issues
    WEBHOOK_DELIVERY_FAILS=$(echo "$WAHA_RECENT" | grep -c "Webhook delivery failed" 2>/dev/null || true)
    WEBHOOK_DELIVERY_FAILS=${WEBHOOK_DELIVERY_FAILS:-0}
    if [ "$WEBHOOK_DELIVERY_FAILS" -gt 0 ]; then
        ISSUES+=("WAHA webhook delivery failed $WEBHOOK_DELIVERY_FAILS time(s) — app not responding to WAHA")
        ACTIONS+=("Check nginx/php-fpm: docker exec zeniclaw_app supervisorctl restart nginx php-fpm")
    fi

    TIMEOUT_ERRS=$(echo "$WAHA_RECENT" | grep -c "aborted due to timeout" 2>/dev/null || true)
    TIMEOUT_ERRS=${TIMEOUT_ERRS:-0}
    if [ "$TIMEOUT_ERRS" -gt 0 ]; then
        ISSUES+=("WAHA → App timeout ($TIMEOUT_ERRS time(s)) — app too slow or unreachable on port 80")
        ACTIONS+=("Restart app services: docker exec zeniclaw_app supervisorctl restart all")
    fi
else
    ok "No WAHA errors"
fi

# Show last 20 raw WAHA log lines for context
echo ""
info "Last 20 WAHA log lines:"
echo "$WAHA_RECENT" | tail -20 | while read -r line; do
    echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
done

# ── 11. App Logs ─────────────────────────────────────────────────
header "11/12" "App Logs (last 10 min)"

APP_LOGS_FULL=$(docker logs zeniclaw_app --since=600s 2>&1 || echo "")
APP_WH_LINES=$(echo "$APP_LOGS_FULL" | grep -i "webhook\|whatsapp\|message.received\|channel" 2>/dev/null | tail -10 || true)
APP_ERR_LINES=$(echo "$APP_LOGS_FULL" | grep -i "production.ERROR\|Exception\|CRITICAL" 2>/dev/null | tail -5 || true)
APP_QUEUE_LINES=$(echo "$APP_LOGS_FULL" | grep -i "queue\|job\|RunTask\|processed" 2>/dev/null | tail -5 || true)

if [ -n "$APP_WH_LINES" ]; then
    ok "App webhook/message activity found"
    echo ""
    info "Recent webhook/message lines:"
    echo "$APP_WH_LINES" | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
else
    warn "No webhook/message lines in app logs (last 10 min)"
fi

if [ -n "$APP_ERR_LINES" ]; then
    fail "App errors found"
    echo ""
    info "Recent errors:"
    echo "$APP_ERR_LINES" | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
else
    ok "No errors in app logs (last 10 min)"
fi

if [ -n "$APP_QUEUE_LINES" ]; then
    info "Queue/job activity:"
    echo "$APP_QUEUE_LINES" | while read -r line; do
        echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
    done
fi

# ── 12. Live Test (optional) ─────────────────────────────────────
header "12/12" "Live Message Test"

if [ "$LIVE_TEST" = true ] && [ "$SESSION_STATUS" = "WORKING" ] && [ -n "$SESSION_PHONE" ]; then
    SELF_JID="${SESSION_PHONE}@s.whatsapp.net"
    TEST_TS=$(date +%s)
    TEST_BODY="[ZeniClaw Diag] ping-${TEST_TS}"

    echo -e "  ${CYAN}Sending test message to self (+${SESSION_PHONE})...${NC}"

    SEND_RESULT=$(docker exec zeniclaw_app curl -sf -w "\n%{http_code}" \
        -X POST -H "X-Api-Key: ${WAHA_KEY}" -H "Content-Type: application/json" \
        -d "{\"chatId\":\"${SELF_JID}\",\"text\":\"${TEST_BODY}\",\"session\":\"default\"}" \
        "${WAHA_BASE}/api/sendText" 2>/dev/null || echo "000")
    SEND_HTTP=$(echo "$SEND_RESULT" | tail -1)
    SEND_BODY=$(echo "$SEND_RESULT" | head -n -1)

    if [ "$SEND_HTTP" = "201" ] || [ "$SEND_HTTP" = "200" ]; then
        ok "Message sent (HTTP $SEND_HTTP)"
        info "Body: $TEST_BODY"
    else
        fail "Send failed: HTTP $SEND_HTTP"
        info "Response: $(echo "$SEND_BODY" | cut -c1-200)"
        ISSUES+=("Could not send test message via WAHA")
    fi

    # Wait for webhook to arrive
    echo -e "  ${CYAN}Waiting for webhook (max 15s)...${NC}"
    FOUND=false
    for i in $(seq 1 15); do
        sleep 1
        CHECK_LOGS=$(docker logs zeniclaw_app --since=20s 2>&1 || echo "")
        if echo "$CHECK_LOGS" | grep -q "ping-${TEST_TS}\|diag-ping\|webhook/whatsapp"; then
            FOUND=true
            ok "Webhook received after ${i}s!"
            break
        fi
        echo -ne "  ${DIM}  ...${i}s${NC}\r"
    done
    echo ""

    if [ "$FOUND" = false ]; then
        fail "Webhook NOT received after 15s"
        ISSUES+=("Live test: message sent but webhook never arrived at app")

        # Check WAHA logs for the delivery attempt
        echo ""
        info "Checking WAHA delivery logs..."
        WAHA_TEST_LOGS=$(docker logs zeniclaw_waha --since=30s 2>&1 || echo "")
        WAHA_DELIVERY=$(echo "$WAHA_TEST_LOGS" | grep -i "webhook\|delivery\|ping-${TEST_TS}" || true)
        if [ -n "$WAHA_DELIVERY" ]; then
            info "WAHA webhook delivery attempt:"
            echo "$WAHA_DELIVERY" | while read -r line; do
                echo -e "    ${DIM}$(echo "$line" | cut -c1-200)${NC}"
            done
            if echo "$WAHA_DELIVERY" | grep -q "failed\|timeout\|error"; then
                ISSUES+=("WAHA tried to deliver webhook but failed (check logs above)")
                ACTIONS+=("Increase WAHA webhook timeout or check app responsiveness")
            fi
        else
            warn "WAHA has no delivery logs — message may not have triggered a webhook event"
            ISSUES+=("WAHA did not attempt webhook delivery — event subscription issue or WAHA bug")
            ACTIONS+=("Recreate session with events [\"message\",\"message.any\"]:\n  docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: ${WAHA_KEY}' ${WAHA_BASE}/api/sessions/default/stop\n  sleep 2\n  docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: ${WAHA_KEY}' -H 'Content-Type: application/json' -d '{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\",\"message.any\",\"message.reaction\"]}]}}' ${WAHA_BASE}/api/sessions/start")
        fi
    fi

elif [ "$LIVE_TEST" = true ]; then
    warn "Cannot run live test — session not WORKING or no phone"
else
    info "Skipped (run with --live-test to send a real test message)"
    info "Usage: bash debug-whatsapp.sh --live-test"
fi

# ── Session JSON dump ────────────────────────────────────────────
echo -e "\n${BOLD}Full WAHA Session Config:${NC}"
echo "$SESSION_JSON" | python3 -m json.tool 2>/dev/null || echo "$SESSION_JSON"

# ══ Summary ══════════════════════════════════════════════════════
echo -e "\n${BOLD}${CYAN}════════════════════════════════════════════${NC}"
echo -e "${BOLD}${CYAN}  Diagnostic Summary${NC}"
echo -e "${BOLD}${CYAN}════════════════════════════════════════════${NC}\n"

if [ ${#ISSUES[@]} -eq 0 ]; then
    echo -e "${GREEN}${BOLD}  All checks passed! WhatsApp should be working.${NC}"
    echo -e "${DIM}  If messages still don't arrive, run: bash debug-whatsapp.sh --live-test${NC}"
else
    echo -e "${RED}${BOLD}  Found ${#ISSUES[@]} issue(s):${NC}\n"
    for i in "${!ISSUES[@]}"; do
        echo -e "  ${RED}$((i+1)).${NC} ${ISSUES[$i]}"
    done

    echo -e "\n${YELLOW}${BOLD}  Suggested actions:${NC}\n"
    for i in "${!ACTIONS[@]}"; do
        echo -e "  ${CYAN}$((i+1)).${NC} ${ACTIONS[$i]}"
    done
fi

echo ""
