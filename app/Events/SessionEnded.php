<?php

namespace App\Events;

use App\Models\AgentSession;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AgentSession $session,
        public readonly string $reason,
    ) {}
}
