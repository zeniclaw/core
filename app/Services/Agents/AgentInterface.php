<?php

namespace App\Services\Agents;

use App\Services\AgentContext;

interface AgentInterface
{
    public function name(): string;

    /**
     * Human-readable description for the router.
     */
    public function description(): string;

    /**
     * Keywords and trigger phrases the router uses to identify this agent.
     */
    public function keywords(): array;

    /**
     * Semantic version of this agent (bumped on each update).
     */
    public function version(): string;

    public function canHandle(AgentContext $context): bool;

    public function handle(AgentContext $context): AgentResult;
}
