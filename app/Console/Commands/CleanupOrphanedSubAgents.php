<?php

namespace App\Console\Commands;

use App\Jobs\RunSubAgentJob;
use App\Models\SubAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CleanupOrphanedSubAgents extends Command
{
    protected $signature = 'subagents:cleanup';
    protected $description = 'Cleanup orphaned SubAgents after container restart and re-queue them';

    public function handle(): void
    {
        // Clear ALL WithoutOverlapping locks (they use cache with prefix "laravel-queue-overlap:")
        $prefix = config('database.redis.options.prefix', 'laravel_database_');
        $cachePrefix = config('cache.prefix', 'laravel_cache');
        $keys = Redis::connection()->keys("*");
        foreach ($keys as $key) {
            if (str_contains($key, 'overlap') || str_contains($key, 'subagent-global')) {
                $cleanKey = str_replace($prefix, '', $key);
                Redis::del($cleanKey);
                $this->info("Cleared stale lock: {$key}");
            }
        }

        // Also clear via Cache directly
        Cache::forget('laravel-queue-overlap:subagent-global');
        Cache::forget('laravel-queue-overlap:subagent-global:lock');
        Cache::forget('laravel-queue-overlap:subagent-global:owner');

        // Find SubAgents stuck in running/queued with no live process
        $orphans = SubAgent::whereIn('status', ['running', 'queued'])->get();

        foreach ($orphans as $sa) {
            $pid = $sa->pid;
            $processAlive = $pid && file_exists("/proc/{$pid}");

            if ($processAlive) {
                $this->info("SubAgent #{$sa->id} still has live process (PID {$pid}), skipping.");
                continue;
            }

            $this->warn("SubAgent #{$sa->id} is orphaned (status={$sa->status}, pid={$pid}). Re-queuing...");

            // Reset and re-queue
            $sa->update([
                'status' => 'queued',
                'started_at' => null,
                'pid' => null,
                'output_log' => null,
                'error_message' => null,
            ]);
            $sa->appendLog('[RECOVERY] Re-queue automatique apres redemarrage container');

            $sa->project->update(['status' => 'in_progress']);

            RunSubAgentJob::dispatch($sa);

            $this->info("SubAgent #{$sa->id} re-dispatched.");
        }

        if ($orphans->isEmpty()) {
            $this->info('No orphaned SubAgents found.');
        }
    }
}
