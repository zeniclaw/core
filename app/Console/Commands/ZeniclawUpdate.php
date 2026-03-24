<?php

namespace App\Console\Commands;

use App\Models\AgentLog;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ZeniclawUpdate extends Command
{
    protected $signature = 'zeniclaw:update {--token= : GitLab access token (overrides DB setting)}';
    protected $description = 'Pull latest code from GitLab, rebuild and restart the container';

    public function handle(): int
    {
        // Get token: CLI option > DB setting > empty
        $token = $this->option('token') ?? '';
        if (empty($token)) {
            try {
                $token = \App\Models\AppSetting::get('gitlab_access_token') ?? '';
            } catch (\Exception $e) {
                $this->warn('⚠ Cannot read DB (running outside Docker?), trying without token...');
            }
        }

        // Detect if running inside container (has sudo helper) or on host
        $insideContainer = file_exists('/usr/local/bin/zeniclaw-update');
        $repoPath = $insideContainer ? '/opt/zeniclaw-repo' : base_path();

        if ($insideContainer) {
            return $this->updateViaHelper($token, $repoPath);
        }

        return $this->updateDirect($token, $repoPath);
    }

    private function updateViaHelper(string $token, string $repoPath): int
    {
        $this->info('▶ Pulling latest code from GitLab...');
        $process = new Process(['sudo', '/usr/local/bin/zeniclaw-update', $token], $repoPath);
        $process->setTimeout(120);
        $process->run(fn($type, $buf) => $this->getOutput()->write($buf));

        if (!$process->isSuccessful()) {
            $this->error('✗ Update helper failed');
            $this->error($process->getErrorOutput());
            return self::FAILURE;
        }

        $output = $process->getOutput();
        preg_match('/VERSION=(.+)/', $output, $m);
        $newVersion = trim($m[1] ?? 'unknown');

        return $this->postUpdate($newVersion);
    }

    private function updateDirect(string $token, string $repoPath): int
    {
        // Running on host — do git pull directly, then container rebuild
        $this->info('▶ Pulling latest code (host mode)...');

        $pullCmd = !empty($token)
            ? ['git', '-c', "url.https://oauth2:{$token}@gitlab.com/.insteadOf=https://gitlab.com/", 'pull', 'origin', 'main']
            : ['git', 'pull', 'origin', 'main'];

        $process = new Process($pullCmd, $repoPath);
        $process->setTimeout(120);
        $process->run(fn($type, $buf) => $this->getOutput()->write($buf));

        if (!$process->isSuccessful()) {
            $this->error('✗ git pull failed');
            $this->error($process->getErrorOutput());
            return self::FAILURE;
        }
        $this->info('✓ Code updated');

        // Read version
        $dockerfile = file_get_contents($repoPath . '/Dockerfile');
        preg_match('/echo "([^"]+)" > storage\/app\/version\.txt/', $dockerfile, $m);
        $newVersion = $m[1] ?? 'unknown';
        $this->info("▶ New version: v{$newVersion}");

        // Detect container runtime — prefer podman, fallback to docker
        $runtime = 'docker';
        $composeCmd = 'docker compose';
        $pruneCmd = 'sudo docker builder prune -f 2>&1';

        if (trim(shell_exec('command -v podman 2>/dev/null') ?? '') !== '') {
            $runtime = 'podman';
            if (trim(shell_exec('podman compose version 2>/dev/null') ?? '') !== '') {
                $composeCmd = 'podman compose';
            } elseif (trim(shell_exec('command -v podman-compose 2>/dev/null') ?? '') !== '') {
                $composeCmd = 'podman-compose';
            }
            $pruneCmd = 'podman system prune -f 2>&1';
        } else {
            if (trim(shell_exec('docker compose version 2>/dev/null') ?? '') === '') {
                $composeCmd = 'docker-compose';
            }
        }

        $this->info("▶ Rebuilding containers ({$runtime})...");

        // Prune builder cache to avoid stale snapshot errors
        $prune = Process::fromShellCommandline("sudo {$pruneCmd}", $repoPath);
        $prune->setTimeout(60);
        $prune->run(fn($type, $buf) => $this->getOutput()->write($buf));

        // Build and recreate in one step — old container stays up until build succeeds
        $rebuild = Process::fromShellCommandline(
            "sudo {$composeCmd} up -d --build --force-recreate app 2>&1",
            $repoPath
        );
        $rebuild->setTimeout(600);
        $rebuild->run(fn($type, $buf) => $this->getOutput()->write($buf));

        if (!$rebuild->isSuccessful()) {
            $this->warn('⚠ Container rebuild may have failed — check logs');
            // Ensure the app container is at least running with the old image
            $fallback = Process::fromShellCommandline("sudo {$composeCmd} up -d app 2>&1", $repoPath);
            $fallback->setTimeout(60);
            $fallback->run(fn($type, $buf) => $this->getOutput()->write($buf));
        }

        $this->info("✅ Update v{$newVersion} complete.");
        return self::SUCCESS;
    }

    private function postUpdate(string $newVersion): int
    {
        $this->info("▶ New version: v{$newVersion}");

        // Migrations
        $this->info('▶ Running migrations...');
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            $this->info('✓ Migrations done');
        } catch (\Exception $e) {
            $this->warn('⚠ Migrations skipped: ' . $e->getMessage());
        }

        // Update version file
        try {
            file_put_contents(storage_path('app/version.txt'), $newVersion);
        } catch (\Exception $e) {
            // ignore
        }

        // Clear ALL caches (config, routes, views, OPcache, PHP-FPM)
        $this->info('▶ Clearing all caches...');
        $cacheScript = base_path('clear-cache.sh');
        if (file_exists($cacheScript)) {
            $cacheProcess = new Process(['bash', $cacheScript]);
            $cacheProcess->setTimeout(30);
            $cacheProcess->run(fn($type, $buf) => $this->getOutput()->write($buf));
            $this->info('✓ All caches cleared (via clear-cache.sh)');
        } else {
            try {
                \Illuminate\Support\Facades\Artisan::call('config:cache');
                \Illuminate\Support\Facades\Artisan::call('route:cache');
                \Illuminate\Support\Facades\Artisan::call('view:cache');
                $this->info('✓ Caches cleared (fallback)');
            } catch (\Exception $e) {
                $this->warn('⚠ Cache clear skipped');
            }
        }

        // Fix permissions (in case update ran as root)
        $fixScript = base_path('fix-permissions.sh');
        if (file_exists($fixScript)) {
            $this->info('▶ Fixing permissions...');
            $fixProcess = new Process(['bash', $fixScript]);
            $fixProcess->setTimeout(30);
            $fixProcess->run(fn($type, $buf) => $this->getOutput()->write($buf));
            $this->info('✓ Permissions fixed');
        }

        // Log
        try {
            $firstAgent = \App\Models\Agent::first();
            if ($firstAgent) {
                AgentLog::create([
                    'agent_id' => $firstAgent->id,
                    'level' => 'info',
                    'message' => "ZeniClaw updated to v{$newVersion}",
                    'context' => ['command' => 'zeniclaw:update'],
                ]);
            }
        } catch (\Exception $e) {
            // non-fatal
        }

        $this->info("✅ Update v{$newVersion} — rebuild started, container will restart.");
        return self::SUCCESS;
    }
}
