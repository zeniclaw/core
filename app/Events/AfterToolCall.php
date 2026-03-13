<?php

namespace App\Events;

use App\Services\AgentContext;
use Illuminate\Foundation\Events\Dispatchable;

class AfterToolCall
{
    use Dispatchable;

    public function __construct(
        public readonly string $toolName,
        public readonly array $input,
        public readonly string $result,
        public readonly AgentContext $context,
        public readonly float $durationMs,
    ) {}
}
