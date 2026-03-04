<?php

namespace App\Console\Commands;

use App\Models\AgentLog;
use App\Models\AppSetting;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ZeniclawUpdate extends Command
{
    protected $signature = 'zeniclaw:update';
    protected $description = 'Pull latest code from GitLab, rebuild and restart the container';

    private string $repoPath = '/opt/zeniclaw-repo';

    public function handle(): int
    {
        // Step 1: Git pull using token-authenticated URL
        $token = AppSetting::get('gitlab_access_token');
        $pullCmd = $token
            ? ['git', '-c', "url.https://oauth2:{$token}@gitlab.com/.insteadOf=https://gitlab.com/", 'pull', 'origin', 'main']
            : ['git', 'pull', 'origin', 'main'];

        if (!$this->runStep($pullCmd, 'git pull origin main', $this->repoPath)) {
            return self::FAILURE;
        }

        // Step 2: Read new version from Dockerfile
        $dockerfile = file_get_contents($this->repoPath . '/Dockerfile');
        preg_match('/echo "([^"]+)" > storage\/app\/version\.txt/', $dockerfile, $m);
        $newVersion = $m[1] ?? 'unknown';
        $this->info("▶ New version: v{$newVersion}");

        // Step 3: Run migrations
        $this->info('▶ Running migrations...');
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $this->info('✓ Migrations done');

        // Step 4: Update version file
        file_put_contents(storage_path('app/version.txt'), $newVersion);

        // Step 5: Log the update
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

        // Step 6: Docker rebuild + restart (nohup survives container death)
        $this->info('▶ Rebuilding Docker image...');
        $this->info('  ⚠ Le container va redémarrer — c\'est normal.');

        $script = "cd {$this->repoPath} && docker-compose build app 2>&1 && docker-compose up -d app 2>&1";
        $logFile = "{$this->repoPath}/storage/app/update-rebuild.log";
        $process = Process::fromShellCommandline("nohup bash -c '{$script}' > {$logFile} 2>&1 &");
        $process->setTimeout(0);
        $process->run();

        $this->info("✅ Update v{$newVersion} — rebuild lancé, le container va redémarrer.");

        return self::SUCCESS;
    }

    private function runStep(array $cmd, string $label, string $cwd = null): bool
    {
        $this->info("▶ {$label}...");
        $process = new Process($cmd, $cwd ?? base_path());
        $process->setTimeout(120);
        $process->run(fn($type, $buf) => $this->getOutput()->write($buf));

        if (!$process->isSuccessful()) {
            $this->error("✗ Failed: {$label}");
            $this->error($process->getErrorOutput());
            return false;
        }

        $this->info("✓ {$label}");
        return true;
    }
}
