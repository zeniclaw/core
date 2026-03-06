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
        // Running on host — do git pull directly, then docker-compose rebuild
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

        // Docker rebuild — prefer docker compose v2
        $this->info('▶ Rebuilding Docker containers...');
        $composeCmd = trim(shell_exec('docker compose version 2>/dev/null && echo "docker compose" || echo "docker-compose"'));
        $composeCmd = str_contains($composeCmd, 'docker compose') ? 'docker compose' : 'docker-compose';

        // Prune builder cache to avoid stale snapshot errors
        $prune = Process::fromShellCommandline('sudo docker builder prune -f 2>&1', $repoPath);
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
            $this->warn('⚠ Docker rebuild may have failed — check logs');
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

        // Clear caches
        $this->info('▶ Clearing caches...');
        try {
            \Illuminate\Support\Facades\Artisan::call('config:cache');
            \Illuminate\Support\Facades\Artisan::call('route:cache');
            \Illuminate\Support\Facades\Artisan::call('view:cache');
            $this->info('✓ Caches cleared');
        } catch (\Exception $e) {
            $this->warn('⚠ Cache clear skipped');
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
