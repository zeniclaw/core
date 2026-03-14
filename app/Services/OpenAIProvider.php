<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI provider (D5.4) — alternative cloud provider for LLM calls.
 * Supports GPT-4o, GPT-4o-mini as fallback when Anthropic is down.
 */
class OpenAIProvider
{
    private string $baseUrl = 'https://api.openai.com/v1';

    /**
     * Check if OpenAI is configured and available.
     */
    public static function isAvailable(): bool
    {
        return (bool) AppSetting::get('openai_api_key');
    }

    /**
     * Send a chat completion request to OpenAI.
     *
     * @param string|array $userMessage
     * @param string $model OpenAI model ID (gpt-4o, gpt-4o-mini)
     * @param string $systemPrompt
     * @param int $maxTokens
     * @return string|null The assistant's reply text
     */
    public function chat(string|array $userMessage, string $model = 'gpt-4o-mini', string $systemPrompt = '', int $maxTokens = 2048): ?string
    {
        $apiKey = AppSetting::get('openai_api_key');
        if (!$apiKey) {
            return null;
        }

        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $content = is_array($userMessage) ? $userMessage : $userMessage;
        $messages[] = ['role' => 'user', 'content' => $content];

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.7,
                ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            }

            Log::warning("OpenAI API error: HTTP {$response->status()}", [
                'body' => substr($response->body(), 0, 500),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('OpenAI API call failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Chat with tool use (function calling) via OpenAI.
     */
    public function chatWithToolUse(array $messages, string $model, string $systemPrompt, array $tools = []): ?array
    {
        $apiKey = AppSetting::get('openai_api_key');
        if (!$apiKey) return null;

        $openaiMessages = [];
        if ($systemPrompt) {
            $openaiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Convert Anthropic-style messages to OpenAI format
        foreach ($messages as $msg) {
            $role = $msg['role'];
            $content = $msg['content'];

            if ($role === 'user' && is_array($content)) {
                // Check for tool_result blocks
                $hasToolResult = false;
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                        $hasToolResult = true;
                        $openaiMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $block['tool_use_id'],
                            'content' => $block['content'] ?? '',
                        ];
                    }
                }
                if (!$hasToolResult) {
                    $openaiMessages[] = ['role' => 'user', 'content' => is_string($content) ? $content : json_encode($content)];
                }
            } elseif ($role === 'assistant' && is_array($content)) {
                // Convert tool_use blocks to tool_calls
                $textParts = [];
                $toolCalls = [];
                foreach ($content as $block) {
                    if (is_array($block)) {
                        if (($block['type'] ?? '') === 'text') {
                            $textParts[] = $block['text'];
                        } elseif (($block['type'] ?? '') === 'tool_use') {
                            $toolCalls[] = [
                                'id' => $block['id'],
                                'type' => 'function',
                                'function' => [
                                    'name' => $block['name'],
                                    'arguments' => json_encode($block['input'] ?? []),
                                ],
                            ];
                        }
                    }
                }
                $assistantMsg = ['role' => 'assistant', 'content' => implode("\n", $textParts) ?: null];
                if (!empty($toolCalls)) {
                    $assistantMsg['tool_calls'] = $toolCalls;
                }
                $openaiMessages[] = $assistantMsg;
            } else {
                $openaiMessages[] = ['role' => $role, 'content' => is_string($content) ? $content : json_encode($content)];
            }
        }

        // Convert Anthropic tool definitions to OpenAI function format
        $openaiTools = [];
        foreach ($tools as $tool) {
            $openaiTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }

        try {
            $body = [
                'model' => $model,
                'messages' => $openaiMessages,
                'max_tokens' => 4096,
                'temperature' => 0.7,
            ];
            if (!empty($openaiTools)) {
                $body['tools'] = $openaiTools;
            }

            $response = Http::timeout(90)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/chat/completions", $body);

            if (!$response->successful()) {
                Log::warning("OpenAI tool use API error: HTTP {$response->status()}");
                return null;
            }

            $choice = $response->json('choices.0');
            $message = $choice['message'] ?? [];

            // Convert OpenAI response back to Anthropic format
            $contentBlocks = [];
            if (!empty($message['content'])) {
                $contentBlocks[] = ['type' => 'text', 'text' => $message['content']];
            }
            foreach ($message['tool_calls'] ?? [] as $toolCall) {
                $contentBlocks[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall['id'],
                    'name' => $toolCall['function']['name'],
                    'input' => json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
                ];
            }

            $stopReason = !empty($message['tool_calls']) ? 'tool_use' : 'end_turn';

            return [
                'content' => $contentBlocks,
                'stop_reason' => $stopReason,
                'model' => $model,
                'usage' => $response->json('usage'),
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI tool use call failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Map Anthropic model tiers to OpenAI models.
     */
    public static function mapModel(string $anthropicModel): string
    {
        return match (true) {
            str_contains($anthropicModel, 'haiku') => 'gpt-4o-mini',
            str_contains($anthropicModel, 'sonnet') => 'gpt-4o',
            str_contains($anthropicModel, 'opus') => 'gpt-4o',
            default => 'gpt-4o-mini',
        };
    }

    /**
     * Health check for OpenAI provider.
     */
    public static function healthCheck(): bool
    {
        if (!self::isAvailable()) return false;

        try {
            $apiKey = AppSetting::get('openai_api_key');
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->get('https://api.openai.com/v1/models');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
