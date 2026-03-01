<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ZeniclawCompactLogs extends Command
{
    protected $signature = 'zeniclaw:compact-logs {--threshold=1000 : Max logs per agent before archiving}';
    protected $description = 'Archive old agent logs when count exceeds threshold';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');
        $this->info("🗜️  Compacting logs (threshold: {$threshold} per agent)...");

        $agents = Agent::withCount('logs')->having('logs_count', '>', $threshold)->get();

        if ($agents->isEmpty()) {
            $this->info('✅ No agents need compaction.');
            return self::SUCCESS;
        }

        foreach ($agents as $agent) {
            $keep = $threshold;
            $total = $agent->logs_count;
            $toArchive = $total - $keep;

            $this->info("  Agent #{$agent->id} ({$agent->name}): {$total} logs → archiving {$toArchive}");

            // Get IDs of oldest logs to archive
            $ids = AgentLog::where('agent_id', $agent->id)
                ->orderBy('id', 'asc')
                ->limit($toArchive)
                ->pluck('id');

            // Copy to archive table
            DB::table('agent_logs_archive')->insertUsing(
                ['original_id', 'agent_id', 'level', 'message', 'context', 'created_at', 'archived_at'],
                AgentLog::whereIn('id', $ids)
                    ->select('id as original_id', 'agent_id', 'level', 'message', 'context', 'created_at', DB::raw('NOW() as archived_at'))
            );

            // Delete archived
            AgentLog::whereIn('id', $ids)->delete();

            $this->info("  ✅ Archived {$ids->count()} logs for agent #{$agent->id}");
        }

        $this->info('✅ Log compaction complete.');
        return self::SUCCESS;
    }
}
