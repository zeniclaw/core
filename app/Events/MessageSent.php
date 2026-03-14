<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $channel,
        public readonly string $to,
        public readonly string $message,
        public readonly ?string $agentName,
    ) {}
}
