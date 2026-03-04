<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicClient
{
    private string $baseUrl = 'https://api.anthropic.com/v1';

    /**
     * @param string|array $message Text string or array of content blocks (multimodal)
     */
    public function chat(string|array $message, string $model = 'claude-haiku-4-5-20251001', string $systemPrompt = ''): ?string
    {
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
            'max_tokens' => $isMultimodal ? 2048 : 1024,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }

        $response = Http::timeout($isMultimodal ? 60 : 30)
            ->withHeaders($headers)
            ->post("{$this->baseUrl}/messages", $body);

        if ($response->successful()) {
            $data = $response->json('content');
            return $data[0]['text'] ?? null;
        }

        Log::error('AnthropicClient::chat failed', [
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
