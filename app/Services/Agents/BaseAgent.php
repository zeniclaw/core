<?php

namespace App\Services\Agents;

use App\Models\AgentLog;
use App\Services\AgentContext;
use App\Services\AnthropicClient;
use App\Services\ContextMemory\ContextStore;
use App\Services\ContextMemoryBridge;
use App\Services\ConversationMemoryService;
use App\Services\PreferencesManager;
use Illuminate\Support\Facades\Http;

abstract class BaseAgent implements AgentInterface
{
    protected string $wahaBase = 'http://waha:3000';
    protected string $wahaApiKey = 'zeniclaw-waha-2026';
    protected string $sessionName = 'default';

    protected AnthropicClient $claude;
    protected ConversationMemoryService $memory;
    protected PreferencesManager $preferencesManager;

    public function __construct()
    {
        $this->claude = new AnthropicClient();
        $this->memory = new ConversationMemoryService();
        $this->preferencesManager = new PreferencesManager();
    }

    protected function getUserPrefs(AgentContext $context): array
    {
        return PreferencesManager::getPreferences($context->from);
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

    protected function sendFile(string $chatId, string $filePath, string $filename, ?string $caption = null): void
    {
        if (str_starts_with($chatId, 'web-')) {
            return;
        }

        $data = base64_encode(file_get_contents($filePath));
        $mimetype = mime_content_type($filePath) ?: 'application/octet-stream';

        try {
            $this->waha(30)->post("{$this->wahaBase}/api/sendFile", [
                'chatId' => $chatId,
                'file' => [
                    'data' => $data,
                    'filename' => $filename,
                    'mimetype' => $mimetype,
                ],
                'caption' => $caption,
                'session' => $this->sessionName,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("sendFile failed: " . $e->getMessage());
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
     * Get shared inter-agent context from ContextMemoryBridge.
     * All agents can call this to access the hot Redis context cache.
     */
    protected function getSharedContext(string $userId): array
    {
        return ContextMemoryBridge::getInstance()->getContext($userId);
    }

    /**
     * Get shared context formatted as a prompt string.
     */
    protected function getSharedContextForPrompt(string $userId): string
    {
        return ContextMemoryBridge::getInstance()->formatForPrompt($userId);
    }

    /**
     * Store pending context on the session so follow-up messages are routed back to this agent.
     * Structure: {agent: "dev", type: "list_selection", data: {...}, expires_at: "..."}
     */
    protected function setPendingContext(AgentContext $context, string $type, array $data = [], int $ttlMinutes = 5, bool $expectRawInput = false): void
    {
        $context->session->update([
            'pending_agent_context' => [
                'agent' => $this->name(),
                'type' => $type,
                'data' => $data,
                'expect_raw_input' => $expectRawInput,
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

    protected function formatContextMemoryForPrompt(string $userId, ?AgentContext $context = null): string
    {
        $parts = [];

        $facts = $this->getContextMemory($userId);
        if (!empty($facts)) {
            $lines = ['PROFIL UTILISATEUR (memoire contextuelle):'];
            foreach ($facts as $fact) {
                $category = $fact['category'] ?? 'general';
                $value = $fact['value'] ?? '';
                $lines[] = "- [{$category}] {$value}";
            }
            $parts[] = implode("\n", $lines);
        }

        // Append persistent conversation memory if injected via context
        if ($context && $context->memoryContext) {
            $parts[] = $context->memoryContext;
        }

        return implode("\n\n", $parts);
    }
}
