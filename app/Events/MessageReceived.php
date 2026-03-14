<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $channel,
        public readonly string $from,
        public readonly ?string $body,
        public readonly bool $hasMedia,
    ) {}
}
