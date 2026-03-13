<?php

namespace App\Events;

use App\Services\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\BaseAgent;
use Illuminate\Foundation\Events\Dispatchable;

class AfterAgentHandle
{
    use Dispatchable;

    public function __construct(
        public readonly BaseAgent $agent,
        public readonly AgentContext $context,
        public readonly AgentResult $result,
        public readonly string $agentName,
        public readonly float $durationMs,
    ) {}
}
