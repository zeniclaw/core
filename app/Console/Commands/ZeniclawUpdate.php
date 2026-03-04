<?php

namespace App\Console\Commands;

use App\Models\AgentLog;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ZeniclawUpdate extends Command
{
    protected $signature = 'zeniclaw:update';
    protected $description = 'Pull latest code from GitLab, rebuild and restart the container';

    /**
     * The host repo is mounted at /opt/zeniclaw-repo (via docker-compose volume).
     * Docker socket is mounted at /var/run/docker.sock.
     * This allows us to git pull + docker-compose build/up from inside the container.
     */
    private string $repoPath = '/opt/zeniclaw-repo';

    public function handle(): int
    {
        // Step 1: Git pull on the mounted host repo
        if (!$this->runStep(['git', 'pull', 'origin', 'main'], 'git pull origin main', $this->repoPath)) {
            return self::FAILURE;
        }

        // Step 2: Read new version from Dockerfile
        $dockerfile = file_get_contents($this->repoPath . '/Dockerfile');
        preg_match('/echo "([^"]+)" > storage\/app\/version\.txt/', $dockerfile, $m);
        $newVersion = $m[1] ?? 'unknown';
        $this->info("▶ New version: v{$newVersion}");

        // Step 3: Run migrations with the current code (in case new migrations were pulled)
        $this->info('▶ Running migrations...');
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $this->info('✓ Migrations done');

        // Step 4: Update version file immediately
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

        // Step 6: Docker rebuild + restart (this will kill the current container)
        $this->info('▶ Rebuilding Docker image...');
        $this->info('  ⚠ The container will restart — this is expected.');

        // Use nohup so the rebuild survives this process dying
        $script = "cd {$this->repoPath} && docker-compose build app && docker-compose up -d app";
        $process = Process::fromShellCommandline("nohup bash -c '{$script}' > {$this->repoPath}/storage/app/update-rebuild.log 2>&1 &");
        $process->setTimeout(0);
        $process->run();

        $this->info("✅ Update v{$newVersion} — rebuild launched, container will restart shortly.");

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
