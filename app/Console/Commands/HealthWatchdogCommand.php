<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HealthWatchdogCommand extends Command
{
    protected $signature = 'zeniclaw:watchdog';
    protected $description = 'Auto-healing watchdog: checks webhook, queue, scheduler every minute';

    private string $logFile;

    public function handle(): int
    {
        $this->logFile = storage_path('app/watchdog.log');
        $this->log('--- Watchdog run started ---');

        $allOk = true;

        $allOk = $this->checkWebhook() && $allOk;
        $allOk = $this->checkQueueWorker() && $allOk;
        $allOk = $this->checkScheduler() && $allOk;

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
