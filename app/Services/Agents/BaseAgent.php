<?php

namespace App\Services\Agents;

use App\Models\AgentLog;
use App\Services\AgentContext;
use App\Services\AnthropicClient;
use App\Services\ContextMemory\ContextStore;
use App\Services\ConversationMemoryService;
use Illuminate\Support\Facades\Http;

abstract class BaseAgent implements AgentInterface
{
    protected string $wahaBase = 'http://waha:3000';
    protected string $wahaApiKey = 'zeniclaw-waha-2026';
    protected string $sessionName = 'default';

    protected AnthropicClient $claude;
    protected ConversationMemoryService $memory;

    public function __construct()
    {
        $this->claude = new AnthropicClient();
        $this->memory = new ConversationMemoryService();
    }

    public function description(): string
    {
        return '';
    }

    public function keywords(): array
    {
        return [];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    protected function waha(int $timeout = 15)
    {
        return Http::timeout($timeout)->withHeaders(['X-Api-Key' => $this->wahaApiKey]);
    }

    protected function sendText(string $chatId, string $text): void
    {
        // Skip WhatsApp send for web chat sessions — reply goes through HTTP response
        if (str_starts_with($chatId, 'web-')) {
            return;
        }

        $maxRetries = 3;
        $lastException = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $response = $this->waha(15)->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $chatId,
                    'text' => $text,
                    'session' => $this->sessionName,
                ]);

                if ($response->successful()) {
                    return;
                }

                \Illuminate\Support\Facades\Log::warning("sendText attempt " . ($i + 1) . " failed: HTTP " . $response->status());
            } catch (\Exception $e) {
                $lastException = $e;
                \Illuminate\Support\Facades\Log::warning("sendText attempt " . ($i + 1) . " exception: " . $e->getMessage());
            }

            if ($i < $maxRetries - 1) {
                sleep(3 * ($i + 1));
            }
        }

        if ($lastException) {
            \Illuminate\Support\Facades\Log::error("sendText failed after {$maxRetries} attempts: " . $lastException->getMessage());
        }
    }

    protected function log(AgentContext $context, string $message, array $extra = [], string $level = 'info'): void
    {
        AgentLog::create([
            'agent_id' => $context->agent->id,
            'level' => $level,
            'message' => "[{$this->name()}] {$message}",
            'context' => array_merge(['from' => $context->from], $extra),
        ]);
    }

    protected function resolveModel(AgentContext $context): string
    {
        return $context->routedModel ?? 'claude-haiku-4-5-20251001';
    }

    protected function getContextMemory(string $userId): array
    {
        $store = new ContextStore();
        return $store->retrieve($userId);
    }

    /**
     * Store pending context on the session so follow-up messages are routed back to this agent.
     * Structure: {agent: "dev", type: "list_selection", data: {...}, expires_at: "..."}
     */
    protected function setPendingContext(AgentContext $context, string $type, array $data = [], int $ttlMinutes = 5): void
    {
        $context->session->update([
            'pending_agent_context' => [
                'agent' => $this->name(),
                'type' => $type,
                'data' => $data,
                'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
            ],
        ]);
    }

    protected function clearPendingContext(AgentContext $context): void
    {
        $context->session->update(['pending_agent_context' => null]);
    }

    /**
     * Override in subclasses to handle follow-up messages from pending context.
     * Return null to fall through to normal routing.
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        return null;
    }

    protected function formatContextMemoryForPrompt(string $userId): string
    {
        $facts = $this->getContextMemory($userId);
        if (empty($facts)) {
            return '';
        }

        $lines = ['PROFIL UTILISATEUR (memoire contextuelle):'];
        foreach ($facts as $fact) {
            $category = $fact['category'] ?? 'general';
            $value = $fact['value'] ?? '';
            $lines[] = "- [{$category}] {$value}";
        }

        return implode("\n", $lines);
    }
}
