<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class AnthropicClient
{
    private string $baseUrl = 'https://api.anthropic.com/v1';

    // ── Circuit Breaker State ──────────────────────────────────
    private const CIRCUIT_BREAKER_THRESHOLD = 5; // failures before opening
    private const CIRCUIT_BREAKER_TIMEOUT = 300; // seconds to wait before half-open

    // ── Token Usage Tracking ──────────────────────────────────
    private static array $tokenUsage = [];

    /**
     * Classify an HTTP error for intelligent retry decisions.
     */
    public static function classifyError(int $status, ?string $body = null): array
    {
        return match (true) {
            $status === 429 => ['type' => 'rate_limit', 'retryable' => true, 'backoff' => 'exponential'],
            $status === 529 => ['type' => 'overloaded', 'retryable' => true, 'backoff' => 'exponential'],
            $status === 401 => ['type' => 'auth', 'retryable' => false, 'backoff' => null],
            $status === 403 => ['type' => 'forbidden', 'retryable' => false, 'backoff' => null],
            $status === 400 => ['type' => 'bad_request', 'retryable' => false, 'backoff' => null],
            $status >= 500 => ['type' => 'server_error', 'retryable' => true, 'backoff' => 'linear'],
            default => ['type' => 'unknown', 'retryable' => false, 'backoff' => null],
        };
    }

    /**
     * Get adaptive timeout based on model tier.
     */
    private function getAdaptiveTimeout(string $model, bool $isMultimodal = false, int $maxTokens = 0): int
    {
        if ($this->isOnPremModel($model)) {
            return 120;
        }

        $base = match (true) {
            str_contains($model, 'haiku') => 30,
            str_contains($model, 'sonnet') => 60,
            str_contains($model, 'opus') => 120,
            default => 60,
        };

        if ($isMultimodal) $base = max($base, 90);
        if ($maxTokens >= 4096) $base = max($base, 120);

        return $base;
    }

    /**
     * Check circuit breaker state for a provider.
     * Returns true if requests should be allowed.
     */
    private function isCircuitClosed(string $provider = 'anthropic'): bool
    {
        $key = "circuit_breaker:{$provider}";
        $state = Cache::get($key);

        if (!$state) return true; // No state = closed (healthy)

        if ($state['failures'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            // Circuit is open — check if timeout has elapsed
            if (time() - $state['opened_at'] > self::CIRCUIT_BREAKER_TIMEOUT) {
                // Half-open: allow one request
                return true;
            }
            return false; // Still open
        }

        return true;
    }

    /**
     * Record a failure for circuit breaker.
     */
    private function recordFailure(string $provider = 'anthropic'): void
    {
        $key = "circuit_breaker:{$provider}";
        $state = Cache::get($key, ['failures' => 0, 'opened_at' => 0]);
        $state['failures']++;
        if ($state['failures'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $state['opened_at'] = time();
        }
        Cache::put($key, $state, self::CIRCUIT_BREAKER_TIMEOUT * 2);
    }

    /**
     * Record a success — reset circuit breaker.
     */
    private function recordSuccess(string $provider = 'anthropic'): void
    {
        Cache::forget("circuit_breaker:{$provider}");
    }

    /**
     * Track token usage from API response.
     */
    private function trackTokenUsage(string $model, ?array $usage, string $from = 'unknown'): void
    {
        if (!$usage) return;

        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;

        // In-memory tracking for current request
        self::$tokenUsage[] = [
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'timestamp' => time(),
        ];

        // Persistent daily tracking via cache
        $dateKey = "tokens:" . date('Y-m-d');
        $daily = Cache::get($dateKey, ['input' => 0, 'output' => 0, 'calls' => 0]);
        $daily['input'] += $inputTokens;
        $daily['output'] += $outputTokens;
        $daily['calls']++;
        Cache::put($dateKey, $daily, 86400 * 2);

        // Per-user tracking if from is known
        if ($from !== 'unknown') {
            $userKey = "tokens:{$from}:" . date('Y-m-d');
            $userDaily = Cache::get($userKey, ['input' => 0, 'output' => 0, 'calls' => 0]);
            $userDaily['input'] += $inputTokens;
            $userDaily['output'] += $outputTokens;
            $userDaily['calls']++;
            Cache::put($userKey, $userDaily, 86400 * 2);
        }
    }

    /**
     * Get token usage stats for today.
     */
    public static function getTokenUsage(?string $from = null): array
    {
        $dateKey = "tokens:" . date('Y-m-d');
        if ($from) {
            $dateKey = "tokens:{$from}:" . date('Y-m-d');
        }
        return Cache::get($dateKey, ['input' => 0, 'output' => 0, 'calls' => 0]);
    }

    /**
     * Make an API request with intelligent retry and error classification.
     */
    private function requestWithRetry(string $url, array $body, array $headers, int $timeout, int $maxRetries = 2): ?\Illuminate\Http\Client\Response
    {
        $lastError = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($headers)
                    ->post($url, $body);

                if ($response->successful()) {
                    $this->recordSuccess();
                    return $response;
                }

                $error = self::classifyError($response->status(), $response->body());
                $lastError = $error;

                Log::warning('AnthropicClient request failed', [
                    'status' => $response->status(),
                    'error_type' => $error['type'],
                    'retryable' => $error['retryable'],
                    'attempt' => $attempt + 1,
                    'body' => substr($response->body(), 0, 300),
                ]);

                if (!$error['retryable'] || $attempt >= $maxRetries) {
                    $this->recordFailure();
                    // Cooldown this API key on rate limit (D5 key rotation)
                    if ($error['type'] === 'rate_limit') {
                        $retryAfterSec = (int) ($response->header('Retry-After') ?? 60);
                        $this->cooldownApiKey($headers['x-api-key'] ?? null, $retryAfterSec);
                    }
                    return $response;
                }

                // Backoff before retry
                $delay = match ($error['backoff']) {
                    'exponential' => min(pow(2, $attempt) * 1000000, 10000000), // 1s, 2s, 4s... max 10s
                    'linear' => ($attempt + 1) * 1000000, // 1s, 2s, 3s
                    default => 1000000,
                };

                // Check for Retry-After header
                $retryAfter = $response->header('Retry-After');
                if ($retryAfter && is_numeric($retryAfter)) {
                    $delay = min((int)$retryAfter * 1000000, 30000000);
                }

                usleep($delay);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::warning('AnthropicClient connection error', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt >= $maxRetries) {
                    $this->recordFailure();
                    return null;
                }

                usleep(($attempt + 1) * 1000000); // Linear backoff for timeouts
            }
        }

        return null;
    }

    /**
     * Get the fallback model when primary fails.
     */
    private function getFallbackModel(string $failedModel): ?string
    {
        // Anthropic model fallback chain
        $fallback = match (true) {
            str_contains($failedModel, 'opus') => ModelResolver::balanced(),
            str_contains($failedModel, 'sonnet') => ModelResolver::fast(),
            default => null,
        };

        // If no Anthropic fallback and OpenAI is available, map to equivalent (D5.4)
        if (!$fallback && OpenAIProvider::isAvailable()) {
            $fallback = 'openai:' . OpenAIProvider::mapModel($failedModel);
        }

        return $fallback;
    }

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
     * Supports tool_use for models that handle it (Qwen 2.5, Llama 3.2).
     */
    private function chatOnPrem(string|array $message, string $model, string $systemPrompt = '', int $maxTokens = 0, array $tools = []): ?string
    {
        $baseUrl = $this->getOrDetectOnPremUrl();
        if (!$baseUrl) {
            Log::warning('On-prem model requested but no Ollama instance found', ['model' => $model]);
            return null;
        }

        // Check if the model is actually available before attempting (avoids 120s timeout)
        $check = $this->checkModelAvailable($baseUrl, $model);
        if (!$check['available']) {
            Log::warning('On-prem model not available on Ollama', [
                'model' => $model,
                'available' => $check['models'],
            ]);

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

        if ($maxTokens <= 0) {
            $maxTokens = 512;
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'stream' => false,
        ];

        // On-prem tool_use: supported by Qwen 2.5, Llama 3.2, Mistral
        if (!empty($tools) && $this->supportsOnPremTools($model)) {
            $body['tools'] = $this->convertToolsToOpenAIFormat($tools);
        }

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
     * Stream a chat response from Ollama/OpenAI-compatible API.
     * Yields events compatible with chatStream() format.
     */
    private function chatStreamOnPrem(array $messages, string $model, string $systemPrompt = '', int $maxTokens = 4096): \Generator
    {
        $baseUrl = $this->getOrDetectOnPremUrl();
        if (!$baseUrl) {
            yield ['type' => 'error', 'data' => 'No Ollama instance found'];
            return;
        }

        $check = $this->checkModelAvailable($baseUrl, $model);
        if (!$check['available']) {
            if (!empty($check['models'])) {
                $model = $check['models'][0];
            } else {
                yield ['type' => 'error', 'data' => "Model {$model} not available on Ollama"];
                return;
            }
        }

        // Build OpenAI-compatible messages with system prompt
        $oaiMessages = [];
        if ($systemPrompt) {
            $oaiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($messages as $msg) {
            $oaiMessages[] = $msg;
        }

        $body = [
            'model' => $model,
            'messages' => $oaiMessages,
            'max_tokens' => $maxTokens,
            'stream' => true,
        ];

        $headers = ['Content-Type: application/json'];
        $apiKey = AppSetting::get('onprem_api_key');
        if ($apiKey) {
            $headers[] = "Authorization: Bearer {$apiKey}";
        }

        $url = rtrim($baseUrl, '/') . '/v1/chat/completions';

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => json_encode($body),
                    'timeout' => 120,
                    'ignore_errors' => true,
                ],
            ]);

            $stream = @fopen($url, 'r', false, $context);
            if (!$stream) {
                yield ['type' => 'error', 'data' => 'Failed to open on-prem stream'];
                return;
            }

            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false) break;
                $line = trim($line);

                if (empty($line) || !str_starts_with($line, 'data: ')) continue;

                $json = substr($line, 6);
                if ($json === '[DONE]') break;

                $event = json_decode($json, true);
                if (!$event) continue;

                $delta = $event['choices'][0]['delta'] ?? [];
                $finishReason = $event['choices'][0]['finish_reason'] ?? null;

                if (isset($delta['content']) && $delta['content'] !== '') {
                    yield ['type' => 'text_delta', 'data' => $delta['content']];
                }

                if ($finishReason) {
                    yield ['type' => 'done', 'data' => [
                        'stop_reason' => $finishReason === 'stop' ? 'end_turn' : $finishReason,
                        'usage' => $event['usage'] ?? null,
                    ]];
                }
            }

            fclose($stream);
        } catch (\Throwable $e) {
            Log::error('On-prem stream error', ['model' => $model, 'error' => $e->getMessage()]);
            yield ['type' => 'error', 'data' => $e->getMessage()];
        }
    }

    /**
     * Check if an on-prem model supports tool_use via OpenAI-compatible API.
     */
    private function supportsOnPremTools(string $model): bool
    {
        $modelLower = strtolower($model);
        $toolCapableModels = ['qwen2.5', 'llama3.2', 'llama3.1', 'mistral', 'command-r'];
        foreach ($toolCapableModels as $prefix) {
            if (str_contains($modelLower, $prefix)) return true;
        }
        return false;
    }

    /**
     * Convert Anthropic tool format to OpenAI format for Ollama.
     */
    private function convertToolsToOpenAIFormat(array $tools): array
    {
        return array_map(function ($tool) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => (object)[]],
                ],
            ];
        }, $tools);
    }

    /**
     * Check if current API key is an OAuth token (requires Claude CLI proxy).
     */
    private function isOAuthToken(?string $apiKey = null): bool
    {
        $apiKey = $apiKey ?? AppSetting::get('anthropic_api_key');
        return $apiKey && str_starts_with($apiKey, 'sk-ant-oat');
    }

    /**
     * Map model names to Claude CLI model slugs.
     */
    private function cliModelSlug(string $model): string
    {
        if (str_contains($model, 'opus')) return 'opus';
        if (str_contains($model, 'haiku')) return 'haiku';
        return 'sonnet'; // default
    }

    /**
     * Route a chat call through Claude CLI (for OAuth tokens).
     * Uses Claude Code with full capabilities: web search, multi-turn, session persistence.
     */
    private function chatViaCli(string|array $message, string $model, string $systemPrompt = '', int $maxTokens = 0): ?string
    {
        $result = $this->executeClaudeCli($message, $model, $systemPrompt);
        return $result['text'] ?? null;
    }

    /**
     * Route a chatWithToolUse call through Claude CLI (for OAuth tokens).
     * Full Claude Code capabilities: web search, web fetch, multi-turn reasoning.
     * Sessions are persisted per conversation for context continuity.
     */
    private function chatWithToolUseViaCli(array $messages, string $model, string $systemPrompt = '', array $tools = [], int $maxTokens = 4096): ?array
    {
        // Extract the user message (last user message in the chain)
        $userMessage = '';
        foreach (array_reverse($messages) as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $content = $msg['content'] ?? '';
                if (is_array($content)) {
                    $userMessage = collect($content)
                        ->map(fn($b) => match($b['type'] ?? '') {
                            'text' => $b['text'] ?? '',
                            'tool_result' => $b['content'] ?? '',
                            default => '',
                        })
                        ->filter()
                        ->implode("\n");
                } else {
                    $userMessage = $content;
                }
                break;
            }
        }

        if (!$userMessage) return null;

        $result = $this->executeClaudeCli($userMessage, $model, $systemPrompt);

        if (!$result) return null;

        return [
            'content' => [['type' => 'text', 'text' => $result['text'] ?? '']],
            'stop_reason' => $result['stop_reason'] ?? 'end_turn',
            'model' => $result['model'] ?? $model,
            'usage' => $result['usage'] ?? null,
        ];
    }

    /**
     * Execute Claude CLI with full capabilities.
     * Supports session resume for conversation continuity.
     *
     * @return array{text: string, session_id: string, stop_reason: string, usage: ?array, model: string}|null
     */
    private function executeClaudeCli(string|array $message, string $model, string $systemPrompt = ''): ?array
    {
        $apiKey = AppSetting::get('anthropic_api_key');
        if (!$apiKey) return null;

        $textMessage = is_array($message)
            ? collect($message)->where('type', 'text')->pluck('text')->implode("\n")
            : $message;

        // Build the prompt with system instructions
        $fullPrompt = $systemPrompt
            ? "{$systemPrompt}\n\n---\n\nMessage de l'utilisateur:\n{$textMessage}"
            : $textMessage;

        $slug = $this->cliModelSlug($model);

        // Check for existing session to resume
        $sessionId = $this->getCliSessionId();

        if ($sessionId) {
            // Resume existing session — send new message via --resume
            $cmd = sprintf(
                'claude --resume %s -p %s --model %s --output-format json --max-turns 6 --allowedTools "WebSearch,WebFetch" 2>/dev/null',
                escapeshellarg($sessionId),
                escapeshellarg($textMessage), // Only user message, system prompt already in session
                escapeshellarg($slug)
            );
        } else {
            // New session
            $cmd = sprintf(
                'claude -p %s --model %s --output-format json --max-turns 6 --allowedTools "WebSearch,WebFetch" 2>/dev/null',
                escapeshellarg($fullPrompt),
                escapeshellarg($slug)
            );
        }

        try {
            $result = Process::timeout(180) // 3 min max for multi-turn
                ->env([
                    'CLAUDE_CODE_OAUTH_TOKEN' => $apiKey,
                    'HOME' => '/tmp',
                ])
                ->run($cmd);

            if (!$result->successful()) {
                Log::warning('AnthropicClient::executeClaudeCli failed', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => substr($result->errorOutput(), 0, 500),
                ]);

                // If resume failed (session expired), retry without resume
                if ($sessionId) {
                    Log::info('AnthropicClient: session resume failed, starting fresh');
                    $this->clearCliSessionId();
                    return $this->executeClaudeCli($message, $model, $systemPrompt);
                }

                return null;
            }

            $data = json_decode($result->output(), true);
            if (!$data) {
                Log::warning('AnthropicClient::executeClaudeCli invalid JSON', [
                    'output' => substr($result->output(), 0, 300),
                ]);
                return null;
            }

            if ($data['is_error'] ?? false) {
                Log::warning('AnthropicClient::executeClaudeCli error', [
                    'result' => substr($data['result'] ?? '', 0, 300),
                ]);
                return null;
            }

            // Save session ID for future resume
            if (!empty($data['session_id'])) {
                $this->saveCliSessionId($data['session_id']);
            }

            // Track usage
            if (!empty($data['usage'])) {
                $this->trackTokenUsage($model, $data['usage']);
            }

            return [
                'text' => $data['result'] ?? '',
                'session_id' => $data['session_id'] ?? null,
                'stop_reason' => $data['stop_reason'] ?? 'end_turn',
                'usage' => $data['usage'] ?? null,
                'model' => $model,
                'cost' => $data['total_cost_usd'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::error('AnthropicClient::executeClaudeCli exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get stored CLI session ID for current conversation.
     * Uses a per-request context key set by the ChatAgent.
     */
    private function getCliSessionId(): ?string
    {
        $key = $this->cliSessionCacheKey();
        return $key ? Cache::get($key) : null;
    }

    /**
     * Save CLI session ID for conversation continuity.
     */
    private function saveCliSessionId(string $sessionId): void
    {
        $key = $this->cliSessionCacheKey();
        if ($key) {
            // Session expires after 4 hours of inactivity
            Cache::put($key, $sessionId, now()->addHours(4));
        }
    }

    /**
     * Clear stored CLI session ID (on error/expiry).
     */
    private function clearCliSessionId(): void
    {
        $key = $this->cliSessionCacheKey();
        if ($key) {
            Cache::forget($key);
        }
    }

    /**
     * Build cache key for CLI session based on current conversation context.
     * Set via setCliConversationId() before API calls.
     */
    private function cliSessionCacheKey(): ?string
    {
        if (!$this->cliConversationId) return null;
        return "claude_cli_session:{$this->cliConversationId}";
    }

    /**
     * Set the conversation ID for CLI session persistence.
     * Call this before chat/chatWithToolUse to enable session resume.
     */
    public function setCliConversationId(string $conversationId): self
    {
        $this->cliConversationId = $conversationId;
        return $this;
    }

    private ?string $cliConversationId = null;

    /**
     * Build Anthropic API headers.
     */
    private function buildHeaders(?string $apiKey = null): ?array
    {
        $apiKey = $apiKey ?? AppSetting::get('anthropic_api_key');
        if (!$apiKey) return null;

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

        return $headers;
    }

    /**
     * @param string|array $message Text string or array of content blocks (multimodal)
     */
    public function chat(string|array $message, string $model = 'claude-haiku-4-5-20251001', string $systemPrompt = '', int $maxTokens = 0): ?string
    {
        if ($this->isOnPremModel($model)) {
            return $this->chatOnPrem($message, $model, $systemPrompt, $maxTokens);
        }

        // OAuth tokens must route through Claude CLI
        if ($this->isOAuthToken()) {
            return $this->chatViaCli($message, $model, $systemPrompt, $maxTokens);
        }

        // Circuit breaker check
        if (!$this->isCircuitClosed()) {
            $fallback = $this->getFallbackModel($model);
            if ($fallback && $fallback !== $model) {
                Log::info('AnthropicClient: circuit open, trying fallback', ['from' => $model, 'to' => $fallback]);
                $model = $fallback;
            } else {
                Log::warning('AnthropicClient: circuit open, no fallback available');
                return null;
            }
        }

        $headers = $this->buildHeaders();
        if (!$headers) return null;

        $isMultimodal = is_array($message);
        $content = $message;

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
            $timeoutSec = $this->getAdaptiveTimeout($model, $isMultimodal, $body['max_tokens']);

            $response = $this->requestWithRetry(
                "{$this->baseUrl}/messages",
                $body,
                $headers,
                $timeoutSec,
                maxRetries: 2
            );

            if (!$response || !$response->successful()) {
                // Try fallback model on failure
                if ($cont === 0 && $response) {
                    $error = self::classifyError($response->status());
                    if ($error['type'] === 'rate_limit' || $error['type'] === 'overloaded') {
                        $fallback = $this->getFallbackModel($model);
                        if ($fallback && $fallback !== $model) {
                            Log::info('AnthropicClient: falling back model', ['from' => $model, 'to' => $fallback]);
                            $body['model'] = $fallback;
                            $response = $this->requestWithRetry("{$this->baseUrl}/messages", $body, $headers, $timeoutSec);
                            if ($response && $response->successful()) {
                                goto process_response;
                            }
                        }
                    }
                }
                return $fullText ?: null;
            }

            process_response:
            $data = $response->json();

            // Track token usage
            $this->trackTokenUsage($model, $data['usage'] ?? null);

            $text = $data['content'][0]['text'] ?? '';
            $fullText .= $text;
            $stopReason = $data['stop_reason'] ?? 'end_turn';

            if ($stopReason !== 'max_tokens') {
                return $fullText;
            }

            Log::info('AnthropicClient::chat continuation needed', [
                'continuation' => $cont + 1,
                'accumulated_length' => mb_strlen($fullText),
            ]);

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
     * Includes error classification, retry, and fallback chain.
     */
    public function chatWithToolUse(array $messages, string $model, string $systemPrompt = '', array $tools = [], int $maxTokens = 4096): ?array
    {
        // OAuth tokens must route through Claude CLI
        if ($this->isOAuthToken()) {
            return $this->chatWithToolUseViaCli($messages, $model, $systemPrompt, $tools, $maxTokens);
        }

        // On-prem models: try tool_use for supported models, fallback to simple chat
        if ($this->isOnPremModel($model)) {
            if (!empty($tools) && $this->supportsOnPremTools($model)) {
                // Use OpenAI-compatible tool_use endpoint
                $reply = $this->chatOnPrem(
                    end($messages)['content'] ?? '',
                    $model,
                    $systemPrompt,
                    $maxTokens,
                    $tools
                );
            } else {
                $lastMessage = end($messages);
                $content = $lastMessage['content'] ?? '';
                if (is_array($content)) {
                    $content = collect($content)->where('type', 'text')->pluck('text')->implode("\n");
                }
                $reply = $this->chatOnPrem($content, $model, $systemPrompt);
            }

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

        // Circuit breaker check
        if (!$this->isCircuitClosed()) {
            $fallback = $this->getFallbackModel($model);
            if ($fallback && $fallback !== $model) {
                $model = $fallback;
            } else {
                return null;
            }
        }

        $headers = $this->buildHeaders();
        if (!$headers) return null;

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

        $timeoutSec = $this->getAdaptiveTimeout($model, false, $maxTokens);

        $response = $this->requestWithRetry(
            "{$this->baseUrl}/messages",
            $body,
            $headers,
            $timeoutSec,
            maxRetries: 2
        );

        if ($response && $response->successful()) {
            $data = $response->json();
            $this->trackTokenUsage($model, $data['usage'] ?? null);

            return [
                'content' => $data['content'] ?? [],
                'stop_reason' => $data['stop_reason'] ?? 'end_turn',
                'model' => $data['model'] ?? $model,
                'usage' => $data['usage'] ?? null,
            ];
        }

        // Fallback on rate_limit/overloaded
        if ($response) {
            $error = self::classifyError($response->status());
            if ($error['type'] === 'rate_limit' || $error['type'] === 'overloaded') {
                $fallback = $this->getFallbackModel($model);
                if ($fallback && $fallback !== $model) {
                    Log::info('AnthropicClient::chatWithToolUse fallback', ['from' => $model, 'to' => $fallback]);
                    $body['model'] = $fallback;
                    $retryResponse = $this->requestWithRetry("{$this->baseUrl}/messages", $body, $headers, $timeoutSec);
                    if ($retryResponse && $retryResponse->successful()) {
                        $data = $retryResponse->json();
                        $this->trackTokenUsage($fallback, $data['usage'] ?? null);
                        return [
                            'content' => $data['content'] ?? [],
                            'stop_reason' => $data['stop_reason'] ?? 'end_turn',
                            'model' => $data['model'] ?? $fallback,
                            'usage' => $data['usage'] ?? null,
                        ];
                    }
                }
            }
        }

        Log::error('AnthropicClient::chatWithToolUse failed', [
            'status' => $response?->status(),
            'body' => $response ? substr($response->body(), 0, 500) : 'no response',
            'model' => $model,
        ]);

        return null;
    }

    /**
     * Multi-turn conversation with full messages array.
     * Used by SubAgent for iterative code modification loops.
     */
    public function chatWithMessages(array $messages, string $model, string $systemPrompt = '', int $maxTokens = 4096): ?string
    {
        if ($this->isOnPremModel($model)) {
            $lastMessage = end($messages);
            $content = $lastMessage['content'] ?? '';
            return $this->chatOnPrem($content, $model, $systemPrompt);
        }

        $headers = $this->buildHeaders();
        if (!$headers) return null;

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }

        $timeoutSec = $this->getAdaptiveTimeout($model, false, $maxTokens);

        $response = $this->requestWithRetry(
            "{$this->baseUrl}/messages",
            $body,
            $headers,
            $timeoutSec,
            maxRetries: 1
        );

        if ($response && $response->successful()) {
            $data = $response->json();
            $this->trackTokenUsage($model, $data['usage'] ?? null);
            return $data['content'][0]['text'] ?? null;
        }

        Log::error('AnthropicClient::chatWithMessages failed', [
            'status' => $response?->status(),
            'body' => $response ? substr($response->body(), 0, 500) : 'no response',
            'model' => $model,
        ]);

        return null;
    }

    /**
     * Get the API key for Anthropic with rotation support (D5 enhanced).
     * Supports multiple keys: anthropic_api_key, anthropic_api_key_2, anthropic_api_key_3
     * Rotates to next key when one is rate-limited.
     */
    private function getApiKey(): ?string
    {
        $keys = array_filter([
            AppSetting::get('anthropic_api_key'),
            AppSetting::get('anthropic_api_key_2'),
            AppSetting::get('anthropic_api_key_3'),
        ]);

        if (empty($keys)) return null;
        if (count($keys) === 1) return $keys[0];

        // Check which keys are on cooldown
        foreach ($keys as $key) {
            $cooldownKey = 'api_key_cooldown:' . md5($key);
            if (!Cache::has($cooldownKey)) {
                return $key;
            }
        }

        // All on cooldown — return the one with earliest expiry
        return $keys[0];
    }

    /**
     * Put an API key on cooldown (after rate limit).
     */
    private function cooldownApiKey(?string $apiKey, int $seconds = 60): void
    {
        if (!$apiKey) return;
        Cache::put('api_key_cooldown:' . md5($apiKey), true, $seconds);
    }

    /**
     * Stream a chat response from the Anthropic API (D1.4 enhanced).
     * Yields content blocks as they arrive.
     *
     * @param array $messages
     * @param string $model
     * @param string $systemPrompt
     * @param array $tools
     * @param int $maxTokens
     * @return \Generator yields ['type' => 'text_delta'|'tool_use'|'done'|'error', 'data' => ...]
     */
    public function chatStream(array $messages, string $model, string $systemPrompt = '', array $tools = [], int $maxTokens = 4096): \Generator
    {
        // Route on-prem models to Ollama streaming
        if ($this->isOnPremModel($model)) {
            yield from $this->chatStreamOnPrem($messages, $model, $systemPrompt, $maxTokens);
            return;
        }

        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            yield ['type' => 'error', 'data' => 'No API key configured'];
            return;
        }

        $headers = $this->buildHeaders($apiKey);
        if (!$headers) {
            yield ['type' => 'error', 'data' => 'Failed to build headers'];
            return;
        }

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
            'stream' => true,
        ];

        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }
        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $timeout = $this->getAdaptiveTimeout($model, false, $maxTokens);

        try {
            $headerStrings = array_map(
                fn($k, $v) => "$k: $v",
                array_keys($headers),
                $headers
            );

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headerStrings),
                    'content' => json_encode($body),
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                ],
            ]);

            $stream = @fopen("{$this->baseUrl}/messages", 'r', false, $context);
            if (!$stream) {
                yield ['type' => 'error', 'data' => 'Failed to open stream'];
                return;
            }

            $currentToolBlock = null;
            $currentToolInput = '';
            $stopReason = 'end_turn';
            $usage = null;

            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false) {
                    break;
                }
                $line = trim($line);

                if (empty($line)) {
                    continue;
                }
                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    break;
                }

                $event = json_decode($json, true);
                if (!$event) {
                    continue;
                }

                $type = $event['type'] ?? '';

                switch ($type) {
                    case 'content_block_start':
                        $block = $event['content_block'] ?? [];
                        if (($block['type'] ?? '') === 'tool_use') {
                            $currentToolBlock = $block;
                            $currentToolInput = '';
                        }
                        break;

                    case 'content_block_delta':
                        $delta = $event['delta'] ?? [];
                        if (($delta['type'] ?? '') === 'text_delta') {
                            yield ['type' => 'text_delta', 'data' => $delta['text'] ?? ''];
                        } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                            $currentToolInput .= $delta['partial_json'] ?? '';
                        }
                        break;

                    case 'content_block_stop':
                        if ($currentToolBlock) {
                            $currentToolBlock['input'] = json_decode($currentToolInput, true) ?? [];
                            yield ['type' => 'tool_use', 'data' => $currentToolBlock];
                            $currentToolBlock = null;
                            $currentToolInput = '';
                        }
                        break;

                    case 'message_delta':
                        $usage = $event['usage'] ?? null;
                        $stopReason = $event['delta']['stop_reason'] ?? 'end_turn';
                        break;

                    case 'message_stop':
                        yield ['type' => 'done', 'data' => [
                            'stop_reason' => $stopReason,
                            'usage' => $usage,
                        ]];
                        break;

                    case 'error':
                        yield ['type' => 'error', 'data' => $event['error']['message'] ?? 'Unknown stream error'];
                        break;
                }
            }

            fclose($stream);
        } catch (\Throwable $e) {
            yield ['type' => 'error', 'data' => $e->getMessage()];
        }
    }

    /**
     * Health check — verify provider availability.
     */
    public static function healthCheck(): array
    {
        $results = ['anthropic' => false, 'ollama' => false];

        // Check Anthropic
        $apiKey = AppSetting::get('anthropic_api_key');
        if ($apiKey) {
            try {
                $headers = ['anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
                if (str_starts_with($apiKey, 'sk-ant-oat01-')) {
                    $headers['Authorization'] = "Bearer {$apiKey}";
                } else {
                    $headers['x-api-key'] = $apiKey;
                }
                $response = Http::timeout(10)->withHeaders($headers)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => 'claude-haiku-4-5-20251001',
                        'max_tokens' => 1,
                        'messages' => [['role' => 'user', 'content' => 'ping']],
                    ]);
                $results['anthropic'] = $response->successful() || $response->status() === 429;
            } catch (\Exception $e) {
                $results['anthropic'] = false;
            }
        }

        // Check Ollama
        $ollamaUrl = AppSetting::get('onprem_api_url');
        if ($ollamaUrl) {
            try {
                $results['ollama'] = Http::timeout(5)->get("{$ollamaUrl}/api/tags")->successful();
            } catch (\Exception $e) {
                $results['ollama'] = false;
            }
        }

        return $results;
    }
}
