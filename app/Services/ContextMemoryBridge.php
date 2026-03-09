<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ContextMemoryBridge
{
    private const PREFIX = 'ctx_bridge:';
    private const DEFAULT_TTL = 86400; // 24 hours

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get full shared context for a user.
     */
    public function getContext(string $userId): array
    {
        $key = $this->key($userId);
        $data = Redis::get($key);

        if ($data) {
            return json_decode($data, true) ?: $this->defaultContext();
        }

        return $this->defaultContext();
    }

    /**
     * Update context with new data (merges with existing).
     */
    public function updateContext(string $userId, array $data): void
    {
        try {
            $existing = $this->getContext($userId);
            $merged = $this->mergeContext($existing, $data);
            $merged['updated_at'] = now()->toIso8601String();

            $key = $this->key($userId);
            $ttl = (int) config('context_bridge.ttl', self::DEFAULT_TTL);
            Redis::setex($key, $ttl, json_encode($merged));
        } catch (\Throwable $e) {
            Log::warning('ContextMemoryBridge: update failed: ' . $e->getMessage());
        }
    }

    /**
     * Get a specific section of the context.
     */
    public function getSection(string $userId, string $section): mixed
    {
        $context = $this->getContext($userId);
        return $context[$section] ?? null;
    }

    /**
     * Set the last agent that handled user's message.
     */
    public function setLastAgent(string $userId, string $agentName): void
    {
        $this->updateContext($userId, [
            'lastAgent' => [
                'name' => $agentName,
                'at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Add a tag to recent tags (deduped, max 20).
     */
    public function addTags(string $userId, array $tags): void
    {
        $context = $this->getContext($userId);
        $existing = $context['recentTags'] ?? [];
        $merged = array_unique(array_merge($tags, $existing));
        $merged = array_slice($merged, 0, 20);

        $this->updateContext($userId, ['recentTags' => $merged]);
    }

    /**
     * Track an active project reference.
     */
    public function addActiveProject(string $userId, string $projectName): void
    {
        $context = $this->getContext($userId);
        $projects = $context['activeProjects'] ?? [];

        // Remove if already present, then prepend
        $projects = array_filter($projects, fn($p) => $p !== $projectName);
        array_unshift($projects, $projectName);
        $projects = array_slice($projects, 0, 10);

        $this->updateContext($userId, ['activeProjects' => array_values($projects)]);
    }

    /**
     * Store a conversation snippet for inter-agent context.
     */
    public function addConversationEntry(string $userId, string $agent, string $message, string $reply): void
    {
        $context = $this->getContext($userId);
        $history = $context['conversationHistory'] ?? [];

        $history[] = [
            'agent' => $agent,
            'message' => mb_substr($message, 0, 200),
            'reply' => mb_substr($reply, 0, 300),
            'at' => now()->toIso8601String(),
        ];

        // Keep last 10 entries
        $history = array_slice($history, -10);

        $this->updateContext($userId, ['conversationHistory' => $history]);
    }

    /**
     * Format shared context as a prompt-ready string for agents.
     */
    public function formatForPrompt(string $userId): string
    {
        $ctx = $this->getContext($userId);
        $parts = [];

        if (!empty($ctx['activeProjects'])) {
            $parts[] = 'Projets actifs: ' . implode(', ', $ctx['activeProjects']);
        }

        if (!empty($ctx['recentTags'])) {
            $parts[] = 'Tags recents: ' . implode(', ', array_slice($ctx['recentTags'], 0, 10));
        }

        if (!empty($ctx['lastAgent']['name'])) {
            $parts[] = 'Dernier agent: ' . $ctx['lastAgent']['name'];
        }

        if (!empty($ctx['preferences'])) {
            $prefLines = [];
            foreach ($ctx['preferences'] as $k => $v) {
                $prefLines[] = "{$k}={$v}";
            }
            $parts[] = 'Preferences: ' . implode(', ', $prefLines);
        }

        if (!empty($ctx['conversationHistory'])) {
            $recent = array_slice($ctx['conversationHistory'], -3);
            $histLines = [];
            foreach ($recent as $entry) {
                $histLines[] = "[{$entry['agent']}] User: {$entry['message']}";
            }
            $parts[] = "Historique recent inter-agents:\n" . implode("\n", $histLines);
        }

        return $parts ? "CONTEXTE PARTAGE (ContextMemoryBridge):\n" . implode("\n", $parts) : '';
    }

    /**
     * Check if context exists for a user.
     */
    public function hasContext(string $userId): bool
    {
        return (bool) Redis::exists($this->key($userId));
    }

    /**
     * Clear context for a user.
     */
    public function clearContext(string $userId): void
    {
        Redis::del($this->key($userId));
    }

    /**
     * Purge all stale contexts (TTL expired ones are auto-purged by Redis,
     * but this scans for contexts older than a given threshold).
     */
    public function purgeStale(int $maxAgeSec = 86400): int
    {
        $count = 0;
        $cursor = null;

        do {
            $result = Redis::scan($cursor, ['match' => self::PREFIX . '*', 'count' => 100]);

            if ($result === false) break;

            [$cursor, $keys] = $result;

            foreach ($keys as $key) {
                $data = Redis::get($key);
                if (!$data) continue;

                $context = json_decode($data, true);
                $updatedAt = $context['updated_at'] ?? null;

                if ($updatedAt && now()->diffInSeconds($updatedAt) > $maxAgeSec) {
                    Redis::del($key);
                    $count++;
                }
            }
        } while ($cursor);

        return $count;
    }

    private function key(string $userId): string
    {
        return self::PREFIX . $userId;
    }

    private function defaultContext(): array
    {
        return [
            'activeProjects' => [],
            'preferences' => [],
            'recentTags' => [],
            'conversationHistory' => [],
            'lastAgent' => [],
            'timeZone' => 'Europe/Paris',
            'updated_at' => null,
        ];
    }

    private function mergeContext(array $existing, array $new): array
    {
        foreach ($new as $key => $value) {
            if ($key === 'updated_at') continue;

            if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
                // For indexed arrays (lists), replace entirely
                if (array_is_list($value)) {
                    $existing[$key] = $value;
                } else {
                    // For associative arrays, merge recursively
                    $existing[$key] = array_merge($existing[$key], $value);
                }
            } else {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }
}
