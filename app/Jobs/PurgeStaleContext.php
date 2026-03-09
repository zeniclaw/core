<?php

namespace App\Jobs;

use App\Services\ContextMemoryBridge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PurgeStaleContext implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $bridge = ContextMemoryBridge::getInstance();
        $purged = $bridge->purgeStale(86400); // 24 hours

        if ($purged > 0) {
            Log::info("PurgeStaleContext: purged {$purged} stale context entries.");
        }
    }
}
