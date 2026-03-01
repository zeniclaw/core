<?php

namespace App\Console\Commands;

use App\Models\AgentLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class ZeniclawUpdate extends Command
{
    protected $signature = 'zeniclaw:update';
    protected $description = 'Pull latest code from GitLab and run migrations';

    public function handle(): int
    {
        $basePath = base_path();
        $steps = [
            ['git', 'pull', 'origin', 'main'],
            ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'],
            ['php', 'artisan', 'migrate', '--force'],
            ['php', 'artisan', 'config:cache'],
            ['php', 'artisan', 'route:cache'],
            ['php', 'artisan', 'view:cache'],
        ];

        foreach ($steps as $cmd) {
            $label = implode(' ', $cmd);
            $this->info("▶ Running: {$label}");

            $process = new Process($cmd, $basePath);
            $process->setTimeout(300);
            $process->run(function ($type, $buffer) {
                $this->getOutput()->write($buffer);
            });

            if (!$process->isSuccessful()) {
                $this->error("✗ Failed: {$label}");
                $this->error($process->getErrorOutput());
                return self::FAILURE;
            }

            $this->info("✓ Done: {$label}");
        }

        // Fetch latest tag for version
        $newVersion = '1.0.0';
        try {
            $resp = Http::timeout(8)->get('https://gitlab.com/api/v4/projects/zenibiz%2Fzeniclaw/repository/tags');
            if ($resp->successful() && count($resp->json()) > 0) {
                $newVersion = ltrim($resp->json()[0]['name'], 'v');
            }
        } catch (\Exception $e) {
            // keep default
        }

        file_put_contents(storage_path('app/version.txt'), $newVersion);
        $this->info("✅ Version updated to {$newVersion}");

        // Log to agent_logs if any agent exists
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

        return self::SUCCESS;
    }
}
