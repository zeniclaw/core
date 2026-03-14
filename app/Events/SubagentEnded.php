<?php

namespace App\Events;

use App\Models\SubAgent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubagentEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SubAgent $subAgent,
        public readonly string $status,
        public readonly ?string $result,
    ) {}
}
