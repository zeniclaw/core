<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HealthWatchdogCommand extends Command
{
    protected $signature = 'zeniclaw:watchdog';
    protected $description = 'Auto-healing watchdog: checks webhook, queue, scheduler, WAHA every minute';

    private string $logFile;

    public function handle(): int
    {
        $this->logFile = storage_path('app/watchdog.log');
        $this->log('--- Watchdog run started ---');

        $allOk = true;

        $allOk = $this->checkWebhook() && $allOk;
        $allOk = $this->checkQueueWorker() && $allOk;
        $allOk = $this->checkScheduler() && $allOk;
        $allOk = $this->checkWaha() && $allOk;

        if ($allOk) {
            $this->log('All checks OK');
            $this->info('All checks OK');
        } else {
            $this->log('Some checks required intervention');
            $this->warn('Some checks required intervention — see watchdog.log');
        }

        return self::SUCCESS;
    }

    private function checkWebhook(): bool
    {
        $this->log('[webhook] Checking...');

        $httpCode = trim(shell_exec(
            'curl -s -o /dev/null -w "%{http_code}" -X POST '
            . '-H "Content-Type: application/json" -d "{}" '
            . 'http://localhost:80/webhook/whatsapp/1 2>/dev/null'
        ) ?: '000');

        if ($httpCode === '500' || $httpCode === '000') {
            $this->log("[webhook] FAIL (HTTP $httpCode) — attempting cache clear...");

            shell_exec('cd /var/www/html && php artisan config:clear 2>&1');
            shell_exec('cd /var/www/html && php artisan route:clear 2>&1');
            shell_exec('cd /var/www/html && php artisan optimize:clear 2>&1');

            // Re-check
            $httpCode2 = trim(shell_exec(
                'curl -s -o /dev/null -w "%{http_code}" -X POST '
                . '-H "Content-Type: application/json" -d "{}" '
                . 'http://localhost:80/webhook/whatsapp/1 2>/dev/null'
            ) ?: '000');

            if ($httpCode2 === '500' || $httpCode2 === '000') {
                $this->log("[webhook] Still failing (HTTP $httpCode2) — triggering rebuild...");
                $this->triggerRebuild();
                return false;
            }

            $this->log("[webhook] Fixed after cache clear (HTTP $httpCode2)");
            return true;
        }

        $this->log("[webhook] OK (HTTP $httpCode)");
        return true;
    }

    private function checkQueueWorker(): bool
    {
        $this->log('[queue] Checking...');

        $ok = true;

        // Check each queue worker independently
        $defaultPids = trim(shell_exec('pgrep -f "queue:work.*--queue=default" 2>/dev/null') ?: '');
        $lowPids = trim(shell_exec('pgrep -f "queue:work.*--queue=low" 2>/dev/null') ?: '');

        if (empty($defaultPids)) {
            $this->log('[queue] DEFAULT worker DEAD — relaunching...');
            shell_exec(
                'su -s /bin/bash www-data -c '
                . '"php /var/www/html/artisan queue:work redis --queue=default --tries=1 --timeout=660 --sleep=3" '
                . '>/dev/null 2>&1 &'
            );
            $ok = false;
        }

        if (empty($lowPids)) {
            $this->log('[queue] LOW worker DEAD — relaunching...');
            shell_exec(
                'su -s /bin/bash www-data -c '
                . '"php /var/www/html/artisan queue:work redis --queue=low --tries=1 --timeout=120 --sleep=5" '
                . '>/dev/null 2>&1 &'
            );
            $ok = false;
        }

        if ($ok) {
            $this->log('[queue] OK (default + low)');
        } else {
            $this->log('[queue] Workers relaunched');
        }

        return $ok;
    }

    private function checkScheduler(): bool
    {
        $this->log('[scheduler] Checking...');

        $pids = trim(shell_exec('pgrep -f "schedule:work" 2>/dev/null') ?: '');

        if (empty($pids)) {
            $this->log('[scheduler] DEAD — relaunching...');

            shell_exec(
                'su -s /bin/bash www-data -c '
                . '"php /var/www/html/artisan schedule:work --no-interaction" '
                . '>/dev/null 2>&1 &'
            );

            $this->log('[scheduler] Relaunched');
            return false;
        }

        $this->log('[scheduler] OK');
        return true;
    }

    private function checkWaha(): bool
    {
        $wahaKey = env('WAHA_API_KEY', 'zeniclaw-waha-2026');
        $wahaHost = env('WAHA_HOST', 'waha');
        $wahaUrl = "http://{$wahaHost}:3000";

        // Step 1: Always check session status (lightweight, every minute)
        $response = shell_exec(
            "curl -sf --max-time 5 -H 'X-Api-Key: $wahaKey' '$wahaUrl/api/sessions/default' 2>/dev/null"
        );

        if (!$response) {
            $this->log('[waha] Unreachable — skipping');
            return true;
        }

        $status = '';
        if (preg_match('/"status"\s*:\s*"([^"]+)"/', $response, $m)) {
            $status = $m[1];
        }

        if ($status !== 'WORKING') {
            $this->log("[waha] Status: $status — restarting session...");
            $this->restartWahaSession($wahaKey, $wahaUrl);
            return false;
        }

        // Step 2: Deep check (sendText) only every 15 minutes
        // sendText is the only reliable test but is heavy — don't spam it every minute
        $lastDeepFile = storage_path('app/watchdog_waha_lastdeep.txt');
        $lastDeep = (int) @file_get_contents($lastDeepFile);
        $now = time();

        if (($now - $lastDeep) < 900) { // 15 minutes
            $this->log('[waha] OK (WORKING, deep check in ' . (900 - ($now - $lastDeep)) . 's)');
            return true;
        }

        $this->log('[waha] Deep check — testing sendText...');

        // Get bot's own ID for self-ping
        $meId = '';
        if (preg_match('/"id"\s*:\s*"([^"]+)"/', $response, $m)) {
            $meId = $m[1];
        }

        if (!$meId) {
            $this->log('[waha] No me.id — skipping deep check');
            @file_put_contents($lastDeepFile, (string) $now);
            return true;
        }

        $sendResult = shell_exec(
            "curl -s --max-time 15 -w '\\nHTTP_CODE:%{http_code}' -X POST "
            . "-H 'X-Api-Key: $wahaKey' -H 'Content-Type: application/json' "
            . "-d '{\"session\":\"default\",\"chatId\":\"$meId\",\"text\":\".\"}' "
            . "'$wahaUrl/api/sendText' 2>/dev/null"
        );

        $sendHttpCode = '000';
        if ($sendResult && preg_match('/HTTP_CODE:(\d{3})/', $sendResult, $m)) {
            $sendHttpCode = $m[1];
        }

        if ($sendHttpCode === '000' || $sendHttpCode === '500') {
            // Read fail counter — only restart after 3 consecutive deep-check failures (45 min)
            // Avoids corrupting WAHA session with aggressive restarts
            $failFile = storage_path('app/watchdog_waha_fails.txt');
            $fails = (int) @file_get_contents($failFile);
            $fails++;
            @file_put_contents($failFile, (string) $fails);

            if ($fails >= 3) {
                $this->log("[waha] sendText FAILED x$fails (HTTP $sendHttpCode) — restarting WAHA container...");
                $this->restartWahaContainer();
                @file_put_contents($failFile, '0');
            } else {
                $this->log("[waha] sendText FAILED x$fails (HTTP $sendHttpCode) — will retry in 15min");
            }
            // Don't update lastDeep — retry on next 15min cycle
            return false;
        }

        // Reset fail counter on success
        @file_put_contents(storage_path('app/watchdog_waha_fails.txt'), '0');

        @file_put_contents($lastDeepFile, (string) $now);
        $this->log("[waha] OK (WORKING + sendText=$sendHttpCode)");
        return true;
    }

    private function restartWahaSession(string $wahaKey, string $wahaUrl): void
    {
        shell_exec(
            "curl -sf --max-time 5 -X POST -H 'X-Api-Key: $wahaKey' -H 'Content-Type: application/json' "
            . "-d '{}' '$wahaUrl/api/sessions/default/start' 2>/dev/null"
        );
        $this->log('[waha] Session restart command sent');
    }

    private function restartWahaContainer(): void
    {
        // Use Docker API via unix socket to restart the WAHA container
        $result = shell_exec(
            "curl -sf --max-time 30 --unix-socket /var/run/docker.sock "
            . "-X POST 'http://localhost/containers/zeniclaw_waha/restart?t=5' 2>&1"
        );
        $this->log('[waha] Container restart sent via Docker API');

        // Wait for WAHA to come back up and start session
        sleep(10);

        $wahaKey = env('WAHA_API_KEY', 'zeniclaw-waha-2026');
        $wahaHost = env('WAHA_HOST', 'waha');
        $wahaUrl = "http://{$wahaHost}:3000";

        // Try to start session (WAHA may auto-start it, but just in case)
        shell_exec(
            "curl -sf --max-time 5 -X POST -H 'X-Api-Key: $wahaKey' -H 'Content-Type: application/json' "
            . "-d '{\"name\":\"default\"}' '$wahaUrl/api/sessions/start' 2>/dev/null"
        );
        $this->log('[waha] Session start sent after container restart');
    }

    private function triggerRebuild(): void
    {
        $repoPath = '/opt/zeniclaw-repo';

        if (!is_dir($repoPath)) {
            $this->log("[rebuild] No repo at $repoPath — cannot rebuild");
            return;
        }

        $this->log('[rebuild] Starting rebuild from repo...');

        // Pull latest
        $pullOutput = shell_exec("cd $repoPath && git pull 2>&1") ?: '';
        $this->log("[rebuild] git pull: " . trim($pullOutput));

        // Copy source files
        shell_exec("rsync -a --delete --exclude='.env' --exclude='storage' --exclude='vendor' --exclude='node_modules' $repoPath/ /var/www/html/ 2>&1");

        // Reinstall dependencies
        shell_exec('cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction 2>&1');

        // Re-optimize
        shell_exec('cd /var/www/html && php artisan config:cache --no-interaction 2>&1');
        shell_exec('cd /var/www/html && php artisan route:cache --no-interaction 2>&1');
        shell_exec('cd /var/www/html && php artisan view:cache --no-interaction 2>&1');
        shell_exec('cd /var/www/html && php artisan migrate --force --no-interaction 2>&1');

        // Fix permissions
        shell_exec('chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>&1');

        $this->log('[rebuild] Rebuild complete');
    }

    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] $message\n";

        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
