<?php

namespace App\Events;

use App\Services\AgentContext;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AfterRouting
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AgentContext $context,
        public readonly array $routing,
    ) {}
}
