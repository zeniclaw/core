<?php

namespace App\Services\Agents;

use App\Services\AgentContext;

interface AgentInterface
{
    public function name(): string;

    public function canHandle(AgentContext $context): bool;

    public function handle(AgentContext $context): AgentResult;
}
