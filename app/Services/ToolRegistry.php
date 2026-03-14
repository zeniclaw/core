<?php

namespace App\Services;

use App\Services\Agents\ToolProviderInterface;
use Illuminate\Support\Facades\Log;

class ToolRegistry
{
    /** @var ToolProviderInterface[] */
    private array $providers = [];

    /** @var array Context filters: tool_name => callable(AgentContext): bool */
    private array $contextFilters = [];

    /**
     * Tools that require user approval before execution (D2.4/D7.5).
     * These are sensitive operations that should be confirmed.
     */
    private static array $approvalRequired = [
        'run_code' => 'Execution de code dans un sandbox',
        'create_image' => 'Generation d\'image (utilise des credits API)',
    ];

    /**
     * Tools explicitly approved by user in the current session.
     * @var array<string, bool>
     */
    private array $approvedTools = [];

    /**
     * Mark a tool as requiring approval.
     */
    public function requireApproval(string $toolName, string $reason): void
    {
        self::$approvalRequired[$toolName] = $reason;
    }

    /**
     * Pre-approve a tool (skip approval gate).
     */
    public function approveToolForSession(string $toolName): void
    {
        $this->approvedTools[$toolName] = true;
    }

    /**
     * Check if a tool requires approval and hasn't been approved yet.
     * Returns the reason string if approval needed, null if OK to proceed.
     */
    public function needsApproval(string $toolName): ?string
    {
        if (isset($this->approvedTools[$toolName])) return null;
        return self::$approvalRequired[$toolName] ?? null;
    }

    public function register(ToolProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Register a contextual filter for a tool.
     * The filter receives AgentContext and returns true to INCLUDE the tool.
     */
    public function addContextFilter(string $toolName, callable $filter): void
    {
        $this->contextFilters[$toolName] = $filter;
    }

    /**
     * Collect all tool definitions from all registered providers.
     * Falls back to legacy AgentTools for not-yet-migrated tools.
     * Applies contextual filters when context is provided.
     */
    public function definitions(?AgentContext $context = null): array
    {
        $allTools = [];
        $seen = [];

        // Dynamic tools from agents implementing ToolProviderInterface
        foreach ($this->providers as $provider) {
            foreach ($provider->tools() as $tool) {
                $name = $tool['name'] ?? '';
                if ($name && !isset($seen[$name])) {
                    // Apply contextual filter if exists
                    if ($context && isset($this->contextFilters[$name])) {
                        if (!($this->contextFilters[$name])($context)) {
                            continue; // Tool filtered out for this context
                        }
                    }

                    // Default contextual filtering: remove spawn_subagent at depth >= 2
                    if ($context && $name === 'spawn_subagent' && ($context->currentDepth ?? 0) >= 2) {
                        continue;
                    }

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
     * Validate tool input against its JSON schema.
     * Returns null if valid, error string if invalid.
     */
    private function validateInput(string $toolName, array $input, array $tools): ?string
    {
        foreach ($tools as $tool) {
            if (($tool['name'] ?? '') !== $toolName) continue;

            $schema = $tool['input_schema'] ?? null;
            if (!$schema) return null;

            $required = $schema['required'] ?? [];
            foreach ($required as $field) {
                if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
                    return "Missing required field: {$field}";
                }
            }

            // Type validation for known properties
            $properties = $schema['properties'] ?? [];
            foreach ($input as $key => $value) {
                if (!isset($properties[$key])) continue;
                $expectedType = $properties[$key]['type'] ?? null;
                if (!$expectedType) continue;

                $valid = match ($expectedType) {
                    'string' => is_string($value),
                    'integer', 'number' => is_numeric($value),
                    'boolean' => is_bool($value),
                    'array' => is_array($value),
                    'object' => is_array($value) || is_object($value),
                    default => true,
                };

                if (!$valid) {
                    return "Field '{$key}' expected type '{$expectedType}', got " . gettype($value);
                }
            }

            return null;
        }

        return null; // Tool not found in definitions = skip validation
    }

    /**
     * Execute a tool by finding its owner provider.
     * Falls back to legacy AgentTools for not-yet-migrated tools.
     * Fires BeforeToolCall/AfterToolCall lifecycle events.
     * Includes schema validation and error re-reporting.
     */
    public function execute(string $toolName, array $input, AgentContext $context): string
    {
        // Schema validation before execution
        $validationError = $this->validateInput($toolName, $input, $this->definitions($context));
        if ($validationError) {
            Log::warning("ToolRegistry: schema validation failed for {$toolName}", [
                'error' => $validationError,
                'input' => $input,
            ]);
            return json_encode([
                'error' => true,
                'validation_error' => $validationError,
                'hint' => 'Verifie les parametres requis et leurs types.',
            ]);
        }

        \App\Events\BeforeToolCall::dispatch($toolName, $input, $context);
        $start = microtime(true);

        // Try dynamic providers first
        $result = null;
        try {
            foreach ($this->providers as $provider) {
                $result = $provider->executeTool($toolName, $input, $context);
                if ($result !== null) {
                    break;
                }
            }

            // Fallback to legacy AgentTools
            if ($result === null) {
                $result = AgentTools::execute($toolName, $input, $context);
            }
        } catch (\Throwable $e) {
            // Re-report error to LLM via tool result
            Log::error("ToolRegistry: tool {$toolName} threw exception", [
                'error' => $e->getMessage(),
                'input' => $input,
            ]);
            $result = json_encode([
                'error' => true,
                'tool' => $toolName,
                'message' => $e->getMessage(),
                'hint' => 'L\'outil a echoue. Essaie une approche differente.',
            ]);
        }

        $durationMs = (microtime(true) - $start) * 1000;
        \App\Events\AfterToolCall::dispatch($toolName, $input, $result, $context, $durationMs);

        return $result;
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
