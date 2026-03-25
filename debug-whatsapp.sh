#!/usr/bin/env bash
# ZeniClaw — WhatsApp/WAHA Diagnostic Script
# Usage: bash debug-whatsapp.sh

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

ISSUES=()
ACTIONS=()

echo -e "\n${BOLD}${CYAN}═══ ZeniClaw WhatsApp Diagnostic ═══${NC}\n"

# ── 1. Container status ──────────────────────────────────────────
echo -e "${BOLD}[1/7] Container Status${NC}"

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

# ── 2. WAHA API reachability ─────────────────────────────────────
echo -e "\n${BOLD}[2/7] WAHA API${NC}"

WAHA_HEALTH=$(docker exec zeniclaw_app curl -sf -o /dev/null -w "%{http_code}" -H "X-Api-Key: zeniclaw-waha-2026" http://waha:3000/api/sessions/default 2>/dev/null || echo "000")

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

# ── 3. WhatsApp session status ───────────────────────────────────
echo -e "\n${BOLD}[3/7] WhatsApp Session${NC}"

SESSION_JSON=$(docker exec zeniclaw_app curl -sf -H "X-Api-Key: zeniclaw-waha-2026" http://waha:3000/api/sessions/default 2>/dev/null || echo "{}")
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
        ACTIONS+=("Restart session: docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: zeniclaw-waha-2026' -H 'Content-Type: application/json' -d '{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\"]}]}}' http://waha:3000/api/sessions/start")
        ;;
    *)
        warn "Session: $SESSION_STATUS"
        ;;
esac

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
    ISSUES+=("No webhook URL")
fi

# ── 4. WAHA reconnect loop detection ────────────────────────────
echo -e "\n${BOLD}[4/7] Connection Stability${NC}"

RECENT_LOGS=$(docker logs zeniclaw_waha --since=60s 2>&1 || echo "")
DISCONNECT_COUNT=$(echo "$RECENT_LOGS" | grep -c "Connection closed" 2>/dev/null || echo "0")
RECONNECT_COUNT=$(echo "$RECENT_LOGS" | grep -c "Reconnecting" 2>/dev/null || echo "0")

if [ "$DISCONNECT_COUNT" -gt 5 ]; then
    fail "Reconnect loop detected: $DISCONNECT_COUNT disconnects in last 60s"
    ISSUES+=("WAHA is in a reconnect loop (status 440) — session is corrupted")
    ACTIONS+=("Reset session:\n  docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: zeniclaw-waha-2026' http://waha:3000/api/sessions/default/stop\n  docker exec zeniclaw_app curl -sf -X DELETE -H 'X-Api-Key: zeniclaw-waha-2026' http://waha:3000/api/sessions/default\n  docker exec zeniclaw_app curl -sf -X POST -H 'X-Api-Key: zeniclaw-waha-2026' -H 'Content-Type: application/json' -d '{\"name\":\"default\",\"config\":{\"webhooks\":[{\"url\":\"http://app:80/webhook/whatsapp/1\",\"events\":[\"message\"]}]}}' http://waha:3000/api/sessions/start\n  Then scan QR code on Settings page")
elif [ "$DISCONNECT_COUNT" -gt 0 ]; then
    warn "Some disconnects: $DISCONNECT_COUNT in last 60s (may be transient)"
else
    ok "No disconnects in last 60s"
fi

# ── 5. Webhook delivery ─────────────────────────────────────────
echo -e "\n${BOLD}[5/7] Webhook Delivery${NC}"

APP_LOGS=$(docker logs zeniclaw_app --since=300s 2>&1 || echo "")
WEBHOOK_HITS=$(echo "$APP_LOGS" | grep -c "webhook/whatsapp" 2>/dev/null || echo "0")
MEDIA_WARNINGS=$(echo "$APP_LOGS" | grep -ci "media.*fail\|could not resolve mediaUrl\|resolveMediaUrl" 2>/dev/null || echo "0")

if [ "$WEBHOOK_HITS" -gt 0 ]; then
    ok "Webhooks received: $WEBHOOK_HITS in last 5 min"
else
    warn "No webhooks received in last 5 min"
    if [ "$DISCONNECT_COUNT" -gt 5 ]; then
        info "Likely caused by WAHA reconnect loop (see above)"
    else
        ISSUES+=("No webhook traffic — messages may not be forwarded")
        ACTIONS+=("Send a test message on WhatsApp and check again")
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

# ── 6. App health ───────────────────────────────────────────────
echo -e "\n${BOLD}[6/7] App Health${NC}"

APP_ERRORS=$(echo "$APP_LOGS" | grep -c "production.ERROR" 2>/dev/null || echo "0")
QUEUE_RUNNING=$(docker exec zeniclaw_app supervisorctl status queue-default:queue-default_00 2>/dev/null | grep -c "RUNNING" || echo "0")

if [ "$QUEUE_RUNNING" -gt 0 ]; then
    ok "Queue workers: running"
else
    fail "Queue workers: not running"
    ISSUES+=("Queue workers are down — messages won't be processed")
    ACTIONS+=("docker exec zeniclaw_app supervisorctl restart queue-default:* queue-low")
fi

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

# ── 7. Network ──────────────────────────────────────────────────
echo -e "\n${BOLD}[7/7] Docker Network${NC}"

APP_NETWORKS=$(docker inspect zeniclaw_app --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' 2>/dev/null || echo "none")
WAHA_NETWORKS=$(docker inspect zeniclaw_waha --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' 2>/dev/null || echo "none")

info "App networks:  $APP_NETWORKS"
info "WAHA networks: $WAHA_NETWORKS"

# Check if they share at least one network
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

# ── Summary ─────────────────────────────────────────────────────
echo -e "\n${BOLD}${CYAN}═══ Diagnostic Summary ═══${NC}\n"

if [ ${#ISSUES[@]} -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All checks passed! WhatsApp should be working.${NC}"
    echo -e "${DIM}If messages still don't arrive, send a test and run this script again.${NC}"
else
    echo -e "${RED}${BOLD}Found ${#ISSUES[@]} issue(s):${NC}\n"
    for i in "${!ISSUES[@]}"; do
        echo -e "  ${RED}$((i+1)).${NC} ${ISSUES[$i]}"
    done

    echo -e "\n${YELLOW}${BOLD}Suggested actions:${NC}\n"
    for i in "${!ACTIONS[@]}"; do
        echo -e "  ${CYAN}$((i+1)).${NC} ${ACTIONS[$i]}"
    done
fi

echo ""
