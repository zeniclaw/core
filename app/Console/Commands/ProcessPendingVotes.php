<?php

namespace App\Console\Commands;

use App\Models\CollaborativeVote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessPendingVotes extends Command
{
    protected $signature = 'votes:process-pending';

    protected $description = 'Process expired pending votes (> 24h) and finalize their status';

    public function handle(): int
    {
        $expiredVotes = CollaborativeVote::expired(24)->get();

        if ($expiredVotes->isEmpty()) {
            $this->info('No expired votes to process.');
            return self::SUCCESS;
        }

        $this->info("Processing {$expiredVotes->count()} expired vote(s)...");

        foreach ($expiredVotes as $vote) {
            $this->processExpiredVote($vote);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function processExpiredVote(CollaborativeVote $vote): void
    {
        $totalVotes = $vote->getTotalVotes();

        if ($totalVotes === 0) {
            // No votes at all — reject
            $vote->reject();
            $this->notifyGroup($vote, "⏰ *Proposition #{$vote->id} expiree*\n\n📋 {$vote->task_description}\n\n❌ Aucun vote recu — Proposition rejetee automatiquement.");
            $this->line("  #{$vote->id}: Rejected (no votes)");
            return;
        }

        if ($vote->isQuorumReached()) {
            $vote->approve();
            $this->notifyGroup($vote, "⏰ *Vote #{$vote->id} finalise*\n\n📋 {$vote->task_description}\n\n✅ *APPROUVEE* — Quorum atteint\n{$vote->formatStatus()}");
            $this->line("  #{$vote->id}: Approved (quorum reached)");
        } else {
            $vote->reject();
            $this->notifyGroup($vote, "⏰ *Vote #{$vote->id} expire*\n\n📋 {$vote->task_description}\n\n❌ *REJETEE* — Quorum non atteint ({$vote->vote_quorum}% requis)\n{$vote->formatStatus()}");
            $this->line("  #{$vote->id}: Rejected (quorum not reached)");
        }

        Log::info('ProcessPendingVotes: vote finalized', [
            'vote_id' => $vote->id,
            'status' => $vote->status,
            'total_votes' => $totalVotes,
        ]);
    }

    private function notifyGroup(CollaborativeVote $vote, string $message): void
    {
        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => config('services.waha.api_key', 'zeniclaw-waha-2026')])
                ->post(config('services.waha.url', 'http://waha:3000') . '/api/sendText', [
                    'chatId' => $vote->message_group_id,
                    'text' => $message,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProcessPendingVotes: failed to notify group', [
                'vote_id' => $vote->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
