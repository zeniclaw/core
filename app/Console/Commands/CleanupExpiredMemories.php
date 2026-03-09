<?php

namespace App\Console\Commands;

use App\Models\ConversationMemory;
use Illuminate\Console\Command;

class CleanupExpiredMemories extends Command
{
    protected $signature = 'memories:cleanup';
    protected $description = 'Archive expired conversation memories (older than 30 days or past expires_at)';

    public function handle(): int
    {
        $expiredByDate = ConversationMemory::where('status', 'active')
            ->where('expires_at', '<', now())
            ->whereNotNull('expires_at')
            ->update(['status' => 'archived']);

        $expiredByAge = ConversationMemory::where('status', 'active')
            ->where('created_at', '<', now()->subDays(30))
            ->whereNull('expires_at')
            ->update(['status' => 'archived']);

        // Hard-delete archived memories older than 90 days
        $deleted = ConversationMemory::where('status', 'archived')
            ->where('updated_at', '<', now()->subDays(90))
            ->delete();

        $this->info("Archived: {$expiredByDate} expired + {$expiredByAge} old. Deleted: {$deleted} ancient.");

        return self::SUCCESS;
    }
}
