<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicClient
{
    private string $baseUrl = 'https://api.anthropic.com/v1';

    /**
     * Check if a model is on-prem (non-Claude).
     */
    private function isOnPremModel(string $model): bool
    {
        return !str_starts_with($model, 'claude-');
    }

    /**
     * Auto-detect Ollama URL if not configured.
     */
    private function getOrDetectOnPremUrl(): ?string
    {
        $url = AppSetting::get('onprem_api_url');
        if ($url) {
            return $url;
        }

        foreach (['http://ollama:11434', 'http://localhost:11434'] as $candidate) {
            try {
                if (Http::timeout(3)->get($candidate . '/api/tags')->successful()) {
                    AppSetting::set('onprem_api_url', $candidate);
                    return $candidate;
                }
            } catch (\Exception $e) {}
        }

        return null;
    }

    /**
     * Check if a model is available on Ollama, return list of available models if not.
     */
    private function checkModelAvailable(string $baseUrl, string $model): array
    {
        try {
            $response = Http::timeout(5)
                ->get(rtrim($baseUrl, '/') . '/api/tags');

            if ($response->successful()) {
                $models = collect($response->json('models') ?? [])
                    ->pluck('name')
                    ->map(fn($n) => strtolower($n))
                    ->all();

                $modelLower = strtolower($model);
                // Check exact match or match without tag (e.g. "qwen2.5:7b" matches "qwen2.5:7b")
                $found = in_array($modelLower, $models)
                    || in_array($modelLower . ':latest', $models);

                return ['available' => $found, 'models' => $models];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to check Ollama models', ['error' => $e->getMessage()]);
        }

        return ['available' => false, 'models' => []];
    }

    /**
     * Route on-prem model calls to Ollama/OpenAI-compatible API.
     */
    private function chatOnPrem(string|array $message, string $model, string $systemPrompt = '', int $maxTokens = 0): ?string
    {
        $baseUrl = $this->getOrDetectOnPremUrl();
        if (!$baseUrl) {
            Log::warning('On-prem model requested but no Ollama instance found', ['model' => $model]);
            return null;
        }

        // Check if the model is actually available before attempting (avoids 120s timeout)
        $check = $this->checkModelAvailable($baseUrl, $model);
        if (!$check['available']) {
            $available = implode(', ', $check['models']);
            Log::warning('On-prem model not available on Ollama', [
                'model' => $model,
                'available' => $check['models'],
            ]);

            // If there are other models available, use the first one as fallback
            if (!empty($check['models'])) {
                $fallback = $check['models'][0];
                Log::info("Falling back to available Ollama model", ['from' => $model, 'to' => $fallback]);
                $model = $fallback;
            } else {
                return null;
            }
        }

        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $content = is_array($message) ? json_encode($message) : $message;
        $messages[] = ['role' => 'user', 'content' => $content];

        // Small on-prem models on CPU can't generate many tokens fast enough
        // Default to 512 to avoid Ollama's internal 2-minute timeout
        if ($maxTokens <= 0) {
            $maxTokens = 512;
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'stream' => false,
        ];

        $headers = ['content-type' => 'application/json'];
        $apiKey = AppSetting::get('onprem_api_key');
        if ($apiKey) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->post(rtrim($baseUrl, '/') . '/v1/chat/completions', $body);

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            }

            Log::error('OnPrem chat failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
                'model' => $model,
            ]);
        } catch (\Exception $e) {
            Log::error('OnPrem chat exception', [
                'model' => $model,
                'error' => $e->getMessage(),
                'base_url' => $baseUrl,
                'prompt_chars' => mb_strlen($systemPrompt),
                'message_chars' => mb_strlen($content),
            ]);
        }

        return null;
    }

    /**
     * @param string|array $message Text string or array of content blocks (multimodal)
     */
    public function chat(string|array $message, string $model = 'claude-haiku-4-5-20251001', string $systemPrompt = '', int $maxTokens = 0): ?string
    {
        if ($this->isOnPremModel($model)) {
            return $this->chatOnPrem($message, $model, $systemPrompt, $maxTokens);
        }

        $apiKey = AppSetting::get('anthropic_api_key');
        if (!$apiKey) {
            return null;
        }

        $isOAuth = str_starts_with($apiKey, 'sk-ant-oat01-');

        $headers = [
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];

        if ($isOAuth) {
            // OAuth token (Claude Max/Pro subscription) → Bearer auth + beta header
            $headers['Authorization'] = "Bearer {$apiKey}";
            $headers['anthropic-beta'] = 'oauth-2025-04-20';
        } else {
            // Standard API key → x-api-key header
            $headers['x-api-key'] = $apiKey;
        }

        // If array, it's multimodal content blocks — use directly and increase max_tokens
        $isMultimodal = is_array($message);
        $content = $isMultimodal ? $message : $message;

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens > 0 ? $maxTokens : ($isMultimodal ? 2048 : 1024),
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }

        // Loop to handle truncated responses (stop_reason = max_tokens)
        $fullText = '';
        $maxContinuations = 3;

        for ($cont = 0; $cont <= $maxContinuations; $cont++) {
            $timeoutSec = $isMultimodal ? 90 : ($maxTokens >= 4096 ? 120 : 60);
            $response = Http::timeout($timeoutSec)
                ->withHeaders($headers)
                ->post("{$this->baseUrl}/messages", $body);

            if (!$response->successful()) {
                Log::error('AnthropicClient::chat failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                    'model' => $model,
                ]);
                return $fullText ?: null;
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? '';
            $fullText .= $text;
            $stopReason = $data['stop_reason'] ?? 'end_turn';

            // If response completed normally, return
            if ($stopReason !== 'max_tokens') {
                return $fullText;
            }

            // Response truncated — continue by asking for the rest
            Log::info('AnthropicClient::chat continuation needed', [
                'continuation' => $cont + 1,
                'accumulated_length' => mb_strlen($fullText),
            ]);

            // Switch to multi-turn: send accumulated text as assistant, ask to continue
            $body = [
                'model' => $model,
                'max_tokens' => $body['max_tokens'],
                'messages' => [
                    ['role' => 'user', 'content' => is_array($message) ? $message : $message],
                    ['role' => 'assistant', 'content' => $fullText],
                    ['role' => 'user', 'content' => 'Continue exactement ou tu t\'es arrete. JSON UNIQUEMENT, pas de texte avant.'],
                ],
            ];
            if ($systemPrompt) {
                $body['system'] = $systemPrompt;
            }
        }

        return $fullText;
    }

    /**
     * Multi-turn conversation with tool use support (agentic loop).
     * Returns the raw API response array (content blocks + stop_reason).
     *
     * @param array $messages Messages array
     * @param string $model Claude model
     * @param string $systemPrompt System prompt
     * @param array $tools Tool definitions for the Anthropic API
     * @param int $maxTokens Max tokens
     * @return array|null Raw response with 'content' and 'stop_reason'
     */
    public function chatWithToolUse(array $messages, string $model, string $systemPrompt = '', array $tools = [], int $maxTokens = 4096): ?array
    {
        // On-prem models don't support tool_use — fallback to simple chat
        if ($this->isOnPremModel($model)) {
            $lastMessage = end($messages);
            $content = $lastMessage['content'] ?? '';
            if (is_array($content)) {
                $content = collect($content)->where('type', 'text')->pluck('text')->implode("\n");
            }
            $reply = $this->chatOnPrem($content, $model, $systemPrompt);
            if ($reply) {
                return [
                    'content' => [['type' => 'text', 'text' => $reply]],
                    'stop_reason' => 'end_turn',
                    'model' => $model,
                    'usage' => null,
                ];
            }
            return null;
        }

        $apiKey = AppSetting::get('anthropic_api_key');
        if (!$apiKey) {
            return null;
        }

        $isOAuth = str_starts_with($apiKey, 'sk-ant-oat01-');

        $headers = [
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];

        if ($isOAuth) {
            $headers['Authorization'] = "Bearer {$apiKey}";
            $headers['anthropic-beta'] = 'oauth-2025-04-20';
        } else {
            $headers['x-api-key'] = $apiKey;
        }

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $response = Http::timeout(120)
            ->withHeaders($headers)
            ->post("{$this->baseUrl}/messages", $body);

        if ($response->successful()) {
            return [
                'content' => $response->json('content') ?? [],
                'stop_reason' => $response->json('stop_reason') ?? 'end_turn',
                'model' => $response->json('model'),
                'usage' => $response->json('usage'),
            ];
        }

        Log::error('AnthropicClient::chatWithToolUse failed', [
            'status' => $response->status(),
            'body' => substr($response->body(), 0, 500),
            'model' => $model,
        ]);

        return null;
    }

    /**
     * Multi-turn conversation with full messages array.
     * Used by SubAgent for iterative code modification loops.
     *
     * @param array $messages Array of ['role' => 'user'|'assistant', 'content' => '...']
     * @param string $model Claude model to use
     * @param string $systemPrompt System prompt
     * @param int $maxTokens Maximum tokens in response
     * @return string|null
     */
    public function chatWithMessages(array $messages, string $model, string $systemPrompt = '', int $maxTokens = 4096): ?string
    {
        if ($this->isOnPremModel($model)) {
            $lastMessage = end($messages);
            $content = $lastMessage['content'] ?? '';
            return $this->chatOnPrem($content, $model, $systemPrompt);
        }

        $apiKey = AppSetting::get('anthropic_api_key');
        if (!$apiKey) {
            return null;
        }

        $isOAuth = str_starts_with($apiKey, 'sk-ant-oat01-');

        $headers = [
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];

        if ($isOAuth) {
            $headers['Authorization'] = "Bearer {$apiKey}";
            $headers['anthropic-beta'] = 'oauth-2025-04-20';
        } else {
            $headers['x-api-key'] = $apiKey;
        }

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }

        $response = Http::timeout(120)
            ->withHeaders($headers)
            ->post("{$this->baseUrl}/messages", $body);

        if ($response->successful()) {
            $data = $response->json('content');
            return $data[0]['text'] ?? null;
        }

        Log::error('AnthropicClient::chatWithMessages failed', [
            'status' => $response->status(),
            'body' => substr($response->body(), 0, 500),
            'model' => $model,
        ]);

        return null;
    }
}
