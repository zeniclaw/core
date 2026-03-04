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
        // Step 1: Configure git credentials from GitLab token
        $token = AppSetting::get('gitlab_access_token');
        if ($token) {
            $this->configureGitCredentials($token);
        }

        // Step 2: Git pull on the mounted host repo
        if (!$this->runStep(['git', 'pull', 'origin', 'main'], 'git pull origin main', $this->repoPath)) {
            return self::FAILURE;
        }

        // Step 3: Read new version from Dockerfile
        $dockerfile = file_get_contents($this->repoPath . '/Dockerfile');
        preg_match('/echo "([^"]+)" > storage\/app\/version\.txt/', $dockerfile, $m);
        $newVersion = $m[1] ?? 'unknown';
        $this->info("▶ New version: v{$newVersion}");

        // Step 4: Run migrations
        $this->info('▶ Running migrations...');
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $this->info('✓ Migrations done');

        // Step 5: Update version file
        file_put_contents(storage_path('app/version.txt'), $newVersion);

        // Step 6: Log the update
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

        // Step 7: Docker rebuild + restart (nohup survives container death)
        $this->info('▶ Rebuilding Docker image...');
        $this->info('  ⚠ The container will restart — this is expected.');

        $script = "cd {$this->repoPath} && docker-compose build app && docker-compose up -d app";
        $process = Process::fromShellCommandline("nohup bash -c '{$script}' > {$this->repoPath}/storage/app/update-rebuild.log 2>&1 &");
        $process->setTimeout(0);
        $process->run();

        $this->info("✅ Update v{$newVersion} — rebuild launched, container will restart shortly.");

        return self::SUCCESS;
    }

    private function configureGitCredentials(string $token): void
    {
        // Write credentials file so git pull can authenticate
        $credFile = '/tmp/.git-credentials';
        file_put_contents($credFile, "https://oauth2:{$token}@gitlab.com\n");
        chmod($credFile, 0600);

        // Configure git to use it
        (new Process(['git', 'config', '--global', 'credential.helper', "store --file={$credFile}"]))->run();
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
