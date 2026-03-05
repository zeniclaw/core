<?php

namespace App\Services;

use App\Models\AgentLog;
use Illuminate\Support\Facades\Log;

/**
 * Agentic Loop — chains tool calls autonomously until the LLM responds with text.
 *
 * Inspired by OpenClaw's architecture: the LLM decides which tools to use,
 * executes them, receives results, and loops until the task is complete.
 */
class AgenticLoop
{
    private AnthropicClient $claude;
    private int $maxIterations;
    private bool $debug;

    public function __construct(int $maxIterations = 10, bool $debug = false)
    {
        $this->claude = new AnthropicClient();
        $this->maxIterations = $maxIterations;
        $this->debug = $debug;
    }

    /**
     * Run the agentic loop.
     *
     * @param string|array $userMessage The user's message (text or multimodal content blocks)
     * @param string $systemPrompt System prompt for the LLM
     * @param string $model Claude model to use
     * @param AgentContext $context Agent context for tool execution
     * @param array $tools Tool definitions for the Anthropic API
     * @return AgenticLoopResult
     */
    public function run(
        string|array $userMessage,
        string $systemPrompt,
        string $model,
        AgentContext $context,
        array $tools = []
    ): AgenticLoopResult {
        if (empty($tools)) {
            $tools = AgentTools::definitions();
        }

        $messages = [
            ['role' => 'user', 'content' => $userMessage],
        ];

        $totalToolCalls = 0;
        $toolsUsed = [];

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $response = $this->claude->chatWithToolUse($messages, $model, $systemPrompt, $tools);

            if (!$response) {
                Log::warning('AgenticLoop: null response from Claude', ['iteration' => $iteration]);
                return new AgenticLoopResult(
                    reply: null,
                    toolsUsed: $toolsUsed,
                    iterations: $iteration + 1,
                    model: $model,
                );
            }

            $stopReason = $response['stop_reason'] ?? 'end_turn';
            $contentBlocks = $response['content'] ?? [];

            // Extract text blocks for the final reply
            $textParts = [];
            $toolUseBlocks = [];

            foreach ($contentBlocks as $block) {
                if ($block['type'] === 'text') {
                    $textParts[] = $block['text'];
                } elseif ($block['type'] === 'tool_use') {
                    $toolUseBlocks[] = $block;
                }
            }

            // If no tool_use → we're done, return the text reply
            if (empty($toolUseBlocks) || $stopReason === 'end_turn') {
                $finalReply = implode("\n", $textParts);

                // If we got tool_use with end_turn, still execute them but return text
                if (!empty($toolUseBlocks) && $stopReason === 'end_turn') {
                    foreach ($toolUseBlocks as $toolBlock) {
                        $this->executeToolQuietly($toolBlock, $context, $toolsUsed);
                        $totalToolCalls++;
                    }
                }

                return new AgenticLoopResult(
                    reply: $finalReply ?: null,
                    toolsUsed: $toolsUsed,
                    iterations: $iteration + 1,
                    model: $model,
                );
            }

            // We have tool_use blocks → execute them and loop
            // Add the assistant's response to messages
            $messages[] = ['role' => 'assistant', 'content' => $contentBlocks];

            // Execute each tool and build tool_result blocks
            $toolResults = [];
            foreach ($toolUseBlocks as $toolBlock) {
                $toolName = $toolBlock['name'];
                $toolInput = $toolBlock['input'] ?? [];
                $toolUseId = $toolBlock['id'];

                $this->debugLog($context, "Tool call: {$toolName}", $toolInput);

                $result = AgentTools::execute($toolName, $toolInput, $context);
                $totalToolCalls++;
                $toolsUsed[] = $toolName;

                $this->debugLog($context, "Tool result: {$toolName}", ['result' => mb_substr($result, 0, 200)]);

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content' => $result,
                ];
            }

            // Add tool results to messages
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        // Max iterations reached — get whatever text we have
        Log::warning('AgenticLoop: max iterations reached', [
            'max' => $this->maxIterations,
            'tools_used' => $toolsUsed,
        ]);

        return new AgenticLoopResult(
            reply: 'J\'ai atteint la limite d\'iterations. Voici ce que j\'ai fait : ' . implode(', ', array_unique($toolsUsed)),
            toolsUsed: $toolsUsed,
            iterations: $this->maxIterations,
            model: $model,
        );
    }

    private function executeToolQuietly(array $toolBlock, AgentContext $context, array &$toolsUsed): void
    {
        try {
            $result = AgentTools::execute($toolBlock['name'], $toolBlock['input'] ?? [], $context);
            $toolsUsed[] = $toolBlock['name'];
        } catch (\Exception $e) {
            Log::warning('AgenticLoop: quiet tool execution failed', [
                'tool' => $toolBlock['name'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function debugLog(AgentContext $context, string $message, array $data = []): void
    {
        if (!$this->debug) return;

        AgentLog::create([
            'agent_id' => $context->agent->id,
            'level' => 'debug',
            'message' => "[AgenticLoop] {$message}",
            'context' => array_merge(['from' => $context->from], $data),
        ]);
    }
}

/**
 * Result of an agentic loop execution.
 */
class AgenticLoopResult
{
    public function __construct(
        public readonly ?string $reply,
        public readonly array $toolsUsed,
        public readonly int $iterations,
        public readonly string $model,
    ) {}

    public function usedTools(): bool
    {
        return !empty($this->toolsUsed);
    }
}
