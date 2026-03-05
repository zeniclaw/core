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

    public function handle(): int
    {
        // Step 1: Git pull + docker rebuild via root helper script
        $token = AppSetting::get('gitlab_access_token') ?? '';

        $this->info('▶ Pulling latest code from GitLab...');
        $process = new Process(
            ['sudo', '/usr/local/bin/zeniclaw-update', $token],
            '/opt/zeniclaw-repo'
        );
        $process->setTimeout(120);
        $process->run(fn($type, $buf) => $this->getOutput()->write($buf));

        if (!$process->isSuccessful()) {
            $this->error('✗ Update helper failed');
            $this->error($process->getErrorOutput());
            return self::FAILURE;
        }

        $output = $process->getOutput();

        // Extract version from helper output
        preg_match('/VERSION=(.+)/', $output, $m);
        $newVersion = trim($m[1] ?? 'unknown');
        $this->info("▶ New version: v{$newVersion}");

        // Step 2: Run migrations
        $this->info('▶ Running migrations...');
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $this->info('✓ Migrations done');

        // Step 3: Update version file
        file_put_contents(storage_path('app/version.txt'), $newVersion);

        // Step 4: Clear caches
        $this->info('▶ Clearing caches...');
        \Illuminate\Support\Facades\Artisan::call('config:cache');
        \Illuminate\Support\Facades\Artisan::call('route:cache');
        \Illuminate\Support\Facades\Artisan::call('view:cache');
        $this->info('✓ Caches cleared');

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

        $this->info("✅ Update v{$newVersion} — rebuild started, container will restart.");

        return self::SUCCESS;
    }
}
