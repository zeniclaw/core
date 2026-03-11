<?php

namespace App\Services\Agents;

use App\Services\AgentContext;

interface ToolProviderInterface
{
    /**
     * Return tool definitions for the Anthropic API.
     * Each tool: ['name' => '...', 'description' => '...', 'input_schema' => [...]]
     */
    public function tools(): array;

    /**
     * Execute a named tool. Return JSON string result, or null if this agent
     * does not own the tool (chain-of-responsibility pattern).
     */
    public function executeTool(string $name, array $input, AgentContext $context): ?string;
}
