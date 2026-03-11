<?php

namespace App\Services;

use App\Services\Agents\ToolProviderInterface;
use Illuminate\Support\Facades\Log;

class ToolRegistry
{
    /** @var ToolProviderInterface[] */
    private array $providers = [];

    public function register(ToolProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Collect all tool definitions from all registered providers.
     * Falls back to legacy AgentTools for not-yet-migrated tools.
     */
    public function definitions(): array
    {
        $allTools = [];
        $seen = [];

        // Dynamic tools from agents implementing ToolProviderInterface
        foreach ($this->providers as $provider) {
            foreach ($provider->tools() as $tool) {
                $name = $tool['name'] ?? '';
                if ($name && !isset($seen[$name])) {
                    $seen[$name] = true;
                    $allTools[] = $tool;
                }
            }
        }

        // Legacy tools from AgentTools (for not-yet-migrated tools)
        foreach (AgentTools::definitions() as $tool) {
            $name = $tool['name'] ?? '';
            if ($name && !isset($seen[$name])) {
                $seen[$name] = true;
                $allTools[] = $tool;
            }
        }

        return $allTools;
    }

    /**
     * Execute a tool by finding its owner provider.
     * Falls back to legacy AgentTools for not-yet-migrated tools.
     */
    public function execute(string $toolName, array $input, AgentContext $context): string
    {
        // Try dynamic providers first
        foreach ($this->providers as $provider) {
            $result = $provider->executeTool($toolName, $input, $context);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback to legacy AgentTools
        return AgentTools::execute($toolName, $input, $context);
    }

    public function providerCount(): int
    {
        return count($this->providers);
    }

    public function toolCount(): int
    {
        return count($this->definitions());
    }
}
