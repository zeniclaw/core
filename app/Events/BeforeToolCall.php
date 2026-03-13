<?php

namespace App\Events;

use App\Services\AgentContext;
use Illuminate\Foundation\Events\Dispatchable;

class BeforeToolCall
{
    use Dispatchable;

    public function __construct(
        public readonly string $toolName,
        public readonly array $input,
        public readonly AgentContext $context,
    ) {}
}
