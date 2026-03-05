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

    protected function waha(int $timeout = 15)
    {
        return Http::timeout($timeout)->withHeaders(['X-Api-Key' => $this->wahaApiKey]);
    }

    protected function sendText(string $chatId, string $text): void
    {
        $this->waha(15)->post("{$this->wahaBase}/api/sendText", [
            'chatId' => $chatId,
            'text' => $text,
            'session' => $this->sessionName,
        ]);
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
