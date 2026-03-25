#!/bin/bash
set -e

echo "🚀 ZeniClaw entrypoint starting..."

# Generate APP_KEY if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:CHANGE_ME" ]; then
    echo "⚠️  Generating APP_KEY..."
    export APP_KEY=$(php artisan key:generate --show --no-ansi 2>/dev/null || echo "base64:$(openssl rand -base64 32)")
fi

# Write .env from environment
cat > /var/www/html/.env << EOF
APP_NAME=ZeniClaw
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL:-http://localhost:8080}

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-5432}
DB_DATABASE=${DB_DATABASE:-zeniclaw}
DB_USERNAME=${DB_USERNAME:-zeniclaw}
DB_PASSWORD=${DB_PASSWORD:-secret}

REDIS_HOST=${REDIS_HOST:-redis}
REDIS_PORT=${REDIS_PORT:-6379}

CACHE_DRIVER=${CACHE_DRIVER:-redis}
SESSION_DRIVER=${SESSION_DRIVER:-redis}
QUEUE_CONNECTION=${QUEUE_CONNECTION:-redis}
EOF

# Append CHAT_API_KEY if set
if [ -n "${CHAT_API_KEY:-}" ]; then
    echo "" >> /var/www/html/.env
    echo "CHAT_API_KEY=${CHAT_API_KEY}" >> /var/www/html/.env
fi

# Append proxy config if set (for outgoing HTTP calls from PHP)
if [ -n "${HTTP_PROXY:-}" ] || [ -n "${HTTPS_PROXY:-}" ]; then
    echo "" >> /var/www/html/.env
    echo "# Proxy" >> /var/www/html/.env
    [ -n "${HTTP_PROXY:-}" ]  && echo "HTTP_PROXY=${HTTP_PROXY}" >> /var/www/html/.env
    [ -n "${HTTPS_PROXY:-}" ] && echo "HTTPS_PROXY=${HTTPS_PROXY}" >> /var/www/html/.env
    [ -n "${NO_PROXY:-}" ]    && echo "NO_PROXY=${NO_PROXY}" >> /var/www/html/.env
fi

# Mark mounted repo as safe for git (root + www-data)
# Detect repo mount dynamically
REPO_DIR=""
for CANDIDATE in /opt/zeniclaw-repo /opt/zeniclaw /home/zeniclaw; do
    [ -d "$CANDIDATE/.git" ] && REPO_DIR="$CANDIDATE" && break
done
if [ -n "$REPO_DIR" ]; then
    git config --global --add safe.directory "$REPO_DIR"
    mkdir -p /var/www/.config/git
    echo '[safe]' > /var/www/.config/git/config
    echo "    directory = $REPO_DIR" >> /var/www/.config/git/config
    chown -R www-data:www-data /var/www/.config
fi

# Give www-data access to container runtime socket for self-update
# Supports both Docker (/var/run/docker.sock) and Podman (/run/podman/podman.sock)
SOCKET_PATH=""
if [ -S "/var/run/docker.sock" ]; then
    SOCKET_PATH="/var/run/docker.sock"
elif [ -S "/run/podman/podman.sock" ]; then
    SOCKET_PATH="/run/podman/podman.sock"
fi

if [ -n "$SOCKET_PATH" ]; then
    SOCKET_GID=$(stat -c '%g' "$SOCKET_PATH" 2>/dev/null || echo "")
    if [ -n "$SOCKET_GID" ] && [ "$SOCKET_GID" != "0" ]; then
        groupadd -g "$SOCKET_GID" container-host 2>/dev/null || true
        usermod -aG "$SOCKET_GID" www-data 2>/dev/null || true
    fi
    # Fallback: make socket world-readable if group add fails
    chmod 666 "$SOCKET_PATH" 2>/dev/null || true
fi

cd /var/www/html

# Health check before start
echo "🏥 Running health check..."
php artisan zeniclaw:health || echo "⚠️  Health check warnings (continuing)"

# Run migrations
echo "📦 Running migrations..."
php artisan migrate --force --no-interaction

# Seed on first install (empty DB = no users)
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null || echo "0")
if [ "$USER_COUNT" = "0" ]; then
    echo "🌱 First install detected — seeding database..."
    php artisan db:seed --force --no-interaction
fi

# Write version file (must be done at runtime because the storage volume
# is mounted AFTER build, overriding the Dockerfile's version.txt)
VERSION_FROM_BUILD=$(cat /tmp/.zeniclaw-version 2>/dev/null || echo "unknown")
echo "$VERSION_FROM_BUILD" > storage/app/version.txt
echo "📌 Version: $VERSION_FROM_BUILD"

# Optimize (clear first — volume persists old caches across rebuilds)
echo "⚡ Optimizing..."
php artisan view:clear --no-interaction   || true
php artisan config:cache --no-interaction || true
php artisan route:cache --no-interaction  || true
php artisan view:cache --no-interaction   || true

# Storage link
php artisan storage:link --no-interaction 2>/dev/null || true

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Re-queue orphaned SubAgents (don't clear queues — preserves pending improvement jobs)
echo "🧹 Cleaning up orphaned SubAgents + stuck improvements..."
php artisan subagents:cleanup --no-interaction

# Sync improvement statuses with their sub-agents + re-queue stuck ones
php artisan tinker --execute='
$improvements = \App\Models\SelfImprovement::whereIn("status", ["in_progress", "approved"])
    ->whereNotNull("sub_agent_id")
    ->get();
foreach ($improvements as $si) {
    $sa = $si->subAgent;
    if (!$sa) continue;
    if ($sa->status === "failed") {
        $si->update(["status" => "failed"]);
        echo "Synced improvement #{$si->id} to failed (sub-agent failed)\n";
    } elseif ($sa->status === "completed") {
        $si->update(["status" => "completed"]);
        echo "Synced improvement #{$si->id} to completed\n";
    } elseif (in_array($sa->status, ["queued", "running"]) && !$sa->pid) {
        $sa->update(["status" => "queued", "pid" => null]);
        \App\Jobs\RunSelfImprovementJob::dispatch($si, $sa);
        echo "Re-queued improvement #{$si->id}: {$si->improvement_title}\n";
    }
}
' 2>/dev/null || true

# Auto-configure Ollama URL if ollama container is reachable
if curl -sf http://ollama:11434/api/tags >/dev/null 2>&1; then
    echo "🖥️ Ollama detected, setting on-prem URL..."
    php artisan tinker --execute="\App\Models\AppSetting::set('onprem_api_url', 'http://ollama:11434');" 2>/dev/null || true

    # Preload first available model into memory (avoids cold start on first chat)
    FIRST_MODEL=$(curl -sf http://ollama:11434/api/tags 2>/dev/null | grep -oP '"name"\s*:\s*"\K[^"]+' | head -1)
    if [ -n "$FIRST_MODEL" ]; then
        echo "🧠 Preloading model $FIRST_MODEL into Ollama RAM..."
        curl -sf -X POST http://ollama:11434/api/generate \
            -H "Content-Type: application/json" \
            -d "{\"model\":\"$FIRST_MODEL\",\"prompt\":\"\",\"keep_alive\":-1}" >/dev/null 2>&1 &
        echo "🧠 Preload started in background"
    fi
fi

# Auto-start WAHA WhatsApp session in background
(
    set +e
    WAHA_KEY="${WAHA_API_KEY:-zeniclaw-waha-2026}"
    WAHA_URL="http://${WAHA_HOST:-waha}:3000"

    # Wait for WAHA API to be available
    echo "📱 Waiting for WAHA to be ready..."
    for i in $(seq 1 30); do
        curl -sf -H "X-Api-Key: $WAHA_KEY" "$WAHA_URL/api/server/status" >/dev/null 2>&1 && break
        sleep 2
    done

    # Keep trying to get session to WORKING state
    echo "📱 Starting WhatsApp session watchdog..."
    for attempt in $(seq 1 12); do
        SESSION=$(curl -sf -H "X-Api-Key: $WAHA_KEY" "$WAHA_URL/api/sessions/default" 2>/dev/null)
        SESSION_STATUS=$(echo "$SESSION" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4)

        WEBHOOK_CONFIG='{"name":"default","config":{"webhooks":[{"url":"http://app:80/webhook/whatsapp/1","events":["message"]}]}}'

        case "$SESSION_STATUS" in
            WORKING)
                echo "📱 WhatsApp connected!"
                break
                ;;
            SCAN_QR_CODE)
                echo "📱 Waiting for QR scan..."
                break
                ;;
            STARTING)
                echo "📱 Session starting, waiting... ($attempt/12)"
                ;;
            STOPPED|FAILED)
                echo "📱 Session $SESSION_STATUS, restarting... ($attempt/12)"
                curl -sf -X POST -H "X-Api-Key: $WAHA_KEY" -H "Content-Type: application/json" \
                    -d "$WEBHOOK_CONFIG" "$WAHA_URL/api/sessions/start" 2>/dev/null || true
                ;;
            *)
                echo "📱 No session found, creating with webhook... ($attempt/12)"
                curl -sf -X POST -H "X-Api-Key: $WAHA_KEY" -H "Content-Type: application/json" \
                    -d "$WEBHOOK_CONFIG" "$WAHA_URL/api/sessions/start" 2>/dev/null || true
                ;;
        esac
        sleep 5
    done
) &

# HealthWatchdog cron (independent of Laravel scheduler — double safety)
mkdir -p /etc/cron.d
echo "* * * * * www-data cd /var/www/html && php artisan zeniclaw:watchdog >> storage/app/watchdog.log 2>&1" > /etc/cron.d/zeniclaw-watchdog
chmod 0644 /etc/cron.d/zeniclaw-watchdog
service cron start || true

# Start all services via supervisor (nginx, php-fpm, queue workers, scheduler)
# Supervisor auto-restarts workers if they crash (OOM, timeout, etc.)
echo "✅ Starting all services via supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
