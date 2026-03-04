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

cd /var/www/html

# Health check before start
echo "🏥 Running health check..."
php artisan zeniclaw:health || echo "⚠️  Health check warnings (continuing)"

# Run migrations
echo "📦 Running migrations..."
php artisan migrate --force --no-interaction

# Optimize
echo "⚡ Optimizing..."
php artisan config:cache --no-interaction || true
php artisan route:cache --no-interaction  || true
php artisan view:cache --no-interaction   || true

# Storage link
php artisan storage:link --no-interaction 2>/dev/null || true

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Start nginx + php-fpm via supervisor
echo "✅ Starting nginx + php-fpm..."
service nginx start

# Re-queue orphaned SubAgents (don't clear queues — preserves pending improvement jobs)
echo "🧹 Cleaning up orphaned SubAgents + stuck improvements..."
php artisan subagents:cleanup --no-interaction

# Re-queue stuck self-improvements (in_progress with a queued/running SubAgent that lost its process)
php artisan tinker --execute='
$stuck = \App\Models\SelfImprovement::where("status", "in_progress")
    ->whereNotNull("sub_agent_id")
    ->get();
foreach ($stuck as $si) {
    $sa = $si->subAgent;
    if ($sa && in_array($sa->status, ["queued", "running"]) && !$sa->pid) {
        $sa->update(["status" => "queued", "pid" => null]);
        \App\Jobs\RunSelfImprovementJob::dispatch($si, $sa);
        echo "Re-queued improvement #{$si->id}: {$si->improvement_title}\n";
    }
}
' 2>/dev/null || true

# Start queue worker as www-data (Claude Code refuses --dangerously-skip-permissions as root)
echo "⚙️ Starting queue worker (www-data)..."
su -s /bin/bash www-data -c "php /var/www/html/artisan queue:work redis --queue=default --tries=1 --timeout=660 --sleep=3" &

# Start low-priority queue worker (self-improvement analysis — lightweight, non-blocking)
echo "🧠 Starting low-priority queue worker..."
su -s /bin/bash www-data -c "php /var/www/html/artisan queue:work redis --queue=low --tries=1 --timeout=120 --sleep=5" &

# Start Laravel scheduler (runs reminders:process every minute)
echo "⏰ Starting scheduler..."
su -s /bin/bash www-data -c "php /var/www/html/artisan schedule:work --no-interaction" &

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
                    -d '{}' "$WAHA_URL/api/sessions/default/start" 2>/dev/null || true
                ;;
            *)
                echo "📱 No session found, creating... ($attempt/12)"
                curl -sf -X POST -H "X-Api-Key: $WAHA_KEY" -H "Content-Type: application/json" \
                    -d '{"name":"default"}' "$WAHA_URL/api/sessions/start" 2>/dev/null || true
                ;;
        esac
        sleep 5
    done
) &

exec php-fpm
