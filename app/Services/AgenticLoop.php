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
    private LLMClient $claude;
    private int $maxIterations;
    private bool $debug;

    public function __construct(int $maxIterations = 15, bool $debug = false)
    {
        $this->claude = new LLMClient();
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
            $tools = $context->toolRegistry
                ? $context->toolRegistry->definitions()
                : AgentTools::definitions();
        }

        $messages = [
            ['role' => 'user', 'content' => $userMessage],
        ];

        $totalToolCalls = 0;
        $toolsUsed = [];

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $this->compactMessages($messages, $context);

            // Pass chat ID for CLI tool injection (WhatsApp file delivery)
            $this->claude->currentChatId = $context->from ?? null;

            $response = $this->claude->chatWithToolUse($messages, $model, $systemPrompt, $tools);

            if (!$response) {
                Log::warning('AgenticLoop: null response from Claude, forcing final summary', ['iteration' => $iteration]);
                $this->debugLog($context, "NULL RESPONSE at iteration {$iteration} - forcing summary", []);

                // API failed (likely context too large or invalid input) — try one last call without tools
                // Strip tool_use/tool_result from messages to reduce size and avoid invalid input errors
                $summaryMessages = [
                    ['role' => 'user', 'content' => $userMessage],
                    ['role' => 'assistant', 'content' => 'J\'ai effectue des recherches sur le sujet. Voici les outils que j\'ai utilises : ' . implode(', ', array_unique($toolsUsed))],
                    ['role' => 'user', 'content' => 'Donne ta reponse finale avec TOUTES les informations que tu as collectees. Structure ta reponse avec des listes.'],
                ];

                $finalResponse = $this->claude->chatWithToolUse($summaryMessages, $model, $systemPrompt, []);
                $finalText = '';
                if ($finalResponse) {
                    foreach (($finalResponse['content'] ?? []) as $block) {
                        if ($block['type'] === 'text') {
                            $finalText .= $block['text'];
                        }
                    }
                }

                return new AgenticLoopResult(
                    reply: $finalText ?: 'Recherche terminee (' . count($toolsUsed) . ' appels). Le contexte est devenu trop volumineux pour generer un resume.',
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
            if (empty($toolUseBlocks)) {
                $finalReply = implode("\n", $textParts);

                $this->debugLog($context, "EXIT no_tool_use iter={$iteration} stop={$stopReason} text_parts=" . count($textParts) . " reply_len=" . strlen($finalReply), [
                    'reply_preview' => mb_substr($finalReply, 0, 500),
                    'content_blocks_raw' => json_encode($contentBlocks, JSON_UNESCAPED_UNICODE),
                ]);

                return new AgenticLoopResult(
                    reply: $finalReply ?: null,
                    toolsUsed: $toolsUsed,
                    iterations: $iteration + 1,
                    model: $model,
                );
            }

            // If end_turn with tool_use but also text → execute tools and return text
            if ($stopReason === 'end_turn' && !empty($textParts)) {
                $finalReply = implode("\n", $textParts);

                foreach ($toolUseBlocks as $toolBlock) {
                    $this->executeToolQuietly($toolBlock, $context, $toolsUsed);
                    $totalToolCalls++;
                }

                return new AgenticLoopResult(
                    reply: $finalReply,
                    toolsUsed: $toolsUsed,
                    iterations: $iteration + 1,
                    model: $model,
                );
            }

            // end_turn with tool_use but NO text → execute tools and continue loop
            // so Claude can produce a final text response

            // We have tool_use blocks → execute them and loop
            // Sanitize content blocks: ensure tool_use.input is always an object (not null or array)
            $sanitizedBlocks = array_map(function ($block) {
                if ($block['type'] === 'tool_use') {
                    $block['input'] = !empty($block['input']) && is_array($block['input'])
                        ? (object) $block['input']
                        : (object) [];
                }
                return $block;
            }, $contentBlocks);
            $messages[] = ['role' => 'assistant', 'content' => $sanitizedBlocks];

            // Execute each tool and build tool_result blocks
            $toolResults = [];
            foreach ($toolUseBlocks as $toolBlock) {
                $toolName = $toolBlock['name'];
                $toolInput = $toolBlock['input'] ?? [];
                $toolUseId = $toolBlock['id'];

                $this->debugLog($context, "Tool call: {$toolName}", $toolInput);

                $isError = false;
                try {
                    $result = $context->toolRegistry
                        ? $context->toolRegistry->execute($toolName, $toolInput, $context)
                        : AgentTools::execute($toolName, $toolInput, $context);
                } catch (\Throwable $e) {
                    // Re-report tool errors to LLM so it can adapt its strategy
                    $result = json_encode([
                        'error' => true,
                        'tool' => $toolName,
                        'message' => $e->getMessage(),
                        'hint' => 'L\'outil a echoue. Adapte ta strategie: essaie un autre outil ou reformule ta requete.',
                    ]);
                    $isError = true;
                    Log::warning("AgenticLoop: tool {$toolName} threw exception", ['error' => $e->getMessage()]);
                }
                $totalToolCalls++;
                $toolsUsed[] = $toolName;

                $this->debugLog($context, "Tool result: {$toolName}" . ($isError ? ' [ERROR]' : ''), ['result' => mb_substr($result, 0, 200)]);

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content' => $result,
                    ...($isError ? ['is_error' => true] : []),
                ];
            }

            // Add tool results to messages
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        // Max iterations reached — make one final call WITHOUT tools to force a text summary
        Log::warning('AgenticLoop: max iterations reached, forcing final summary', [
            'max' => $this->maxIterations,
            'tools_used' => $toolsUsed,
        ]);

        $messages[] = ['role' => 'user', 'content' => 'Tu as atteint la limite d\'iterations. Donne maintenant ta reponse finale avec TOUTES les informations que tu as collectees. Ne demande pas d\'outils supplementaires.'];

        $finalResponse = $this->claude->chatWithToolUse($messages, $model, $systemPrompt, []);
        $finalText = '';
        if ($finalResponse) {
            foreach (($finalResponse['content'] ?? []) as $block) {
                if ($block['type'] === 'text') {
                    $finalText .= $block['text'];
                }
            }
        }

        return new AgenticLoopResult(
            reply: $finalText ?: 'Recherche terminee mais aucun resume disponible. Outils utilises : ' . implode(', ', array_unique($toolsUsed)),
            toolsUsed: $toolsUsed,
            iterations: $this->maxIterations + 1,
            model: $model,
        );
    }

    private function executeToolQuietly(array $toolBlock, AgentContext $context, array &$toolsUsed): void
    {
        try {
            $result = $context->toolRegistry
                ? $context->toolRegistry->execute($toolBlock['name'], $toolBlock['input'] ?? [], $context)
                : AgentTools::execute($toolBlock['name'], $toolBlock['input'] ?? [], $context);
            $toolsUsed[] = $toolBlock['name'];
        } catch (\Exception $e) {
            Log::warning('AgenticLoop: quiet tool execution failed', [
                'tool' => $toolBlock['name'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compact messages when approaching context limits (D1.3).
     * Summarizes older tool results to reduce token count.
     */
    private function compactMessages(array &$messages, AgentContext $context): void
    {
        // Estimate token count (rough: 4 chars ≈ 1 token)
        $totalChars = 0;
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            $totalChars += is_string($content) ? strlen($content) : strlen(json_encode($content));
        }
        $estimatedTokens = $totalChars / 4;

        // Compact if approaching 80% of context window (128K for Claude = ~100K usable)
        if ($estimatedTokens < 80000) {
            return;
        }

        Log::info('AgenticLoop: compacting messages', [
            'estimated_tokens' => $estimatedTokens,
            'message_count' => count($messages),
        ]);

        // Keep first message (user query) and last 4 messages (recent context)
        // Summarize everything in between
        if (count($messages) <= 6) {
            return;
        }

        $first = $messages[0]; // Original user message
        $last4 = array_slice($messages, -4);
        $middle = array_slice($messages, 1, count($messages) - 5);

        // Build summary of middle messages
        $toolSummary = [];
        foreach ($middle as $msg) {
            $content = $msg['content'] ?? '';
            if ($msg['role'] === 'user' && is_array($content)) {
                // tool_result messages
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                        $result = $block['content'] ?? '';
                        $toolSummary[] = mb_substr(is_string($result) ? $result : json_encode($result), 0, 200);
                    }
                }
            } elseif ($msg['role'] === 'assistant' && is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                        $toolSummary[] = "[Tool: {$block['name']}]";
                    }
                }
            }
        }

        $summaryText = "CONTEXT COMPACTE (iterations precedentes):\n" . implode("\n", array_slice($toolSummary, -20));

        $messages = [
            $first,
            ['role' => 'assistant', 'content' => $summaryText],
            ['role' => 'user', 'content' => 'Continue avec les informations collectees.'],
            ...$last4,
        ];

        Log::info('AgenticLoop: compacted', [
            'new_count' => count($messages),
            'summary_len' => strlen($summaryText),
        ]);
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
