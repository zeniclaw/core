<?php

namespace App\Events;

use App\Services\AgentContext;
use App\Services\Agents\BaseAgent;
use Illuminate\Foundation\Events\Dispatchable;

class BeforeAgentHandle
{
    use Dispatchable;

    public function __construct(
        public readonly BaseAgent $agent,
        public readonly AgentContext $context,
        public readonly string $agentName,
    ) {}
}
