<?php

namespace App\Services\ContextMemory;

use App\Models\UserContextProfile;
use Illuminate\Support\Facades\Redis;

class ContextStore
{
    private const PREFIX = 'context_memory:';
    private const DEFAULT_TTL = 86400 * 30; // 30 days

    public function store(string $userId, array $facts, int $ttl = null): void
    {
        $key = $this->key($userId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $existing = $this->retrieve($userId);
        $merged = $this->mergeFacts($existing, $facts);

        Redis::setex($key, $ttl, serialize($merged));

        $this->persistToDatabase($userId, $merged);
    }

    public function retrieve(string $userId): array
    {
        $key = $this->key($userId);
        $data = Redis::get($key);

        if ($data) {
            return unserialize($data) ?: [];
        }

        return $this->loadFromDatabase($userId);
    }

    public function forget(string $userId, string $factKey): void
    {
        $facts = $this->retrieve($userId);
        $facts = array_filter($facts, fn($f) => ($f['key'] ?? '') !== $factKey);
        $facts = array_values($facts);

        $key = $this->key($userId);
        Redis::setex($key, self::DEFAULT_TTL, serialize($facts));

        $this->persistToDatabase($userId, $facts);
    }

    public function cleanup(string $userId, int $maxAge = null): int
    {
        $maxAge = $maxAge ?? (86400 * 90); // 90 days default
        $facts = $this->retrieve($userId);
        $cutoff = time() - $maxAge;

        $before = count($facts);
        $facts = array_filter($facts, fn($f) => ($f['timestamp'] ?? time()) > $cutoff);
        $facts = array_values($facts);
        $removed = $before - count($facts);

        if ($removed > 0) {
            $key = $this->key($userId);
            Redis::setex($key, self::DEFAULT_TTL, serialize($facts));
            $this->persistToDatabase($userId, $facts);
        }

        return $removed;
    }

    public function flush(string $userId): void
    {
        Redis::del($this->key($userId));
        UserContextProfile::where('user_phone', $userId)->delete();
    }

    private function key(string $userId): string
    {
        return self::PREFIX . $userId;
    }

    private function mergeFacts(array $existing, array $newFacts): array
    {
        $indexed = [];
        foreach ($existing as $fact) {
            $key = $fact['key'] ?? md5($fact['value'] ?? '');
            $indexed[$key] = $fact;
        }

        foreach ($newFacts as $fact) {
            $key = $fact['key'] ?? md5($fact['value'] ?? '');
            $indexed[$key] = array_merge(
                $indexed[$key] ?? [],
                $fact,
                ['timestamp' => time()]
            );
        }

        // Sort by relevance score descending, keep top 200
        $result = array_values($indexed);
        usort($result, fn($a, $b) => ($b['score'] ?? 0.5) <=> ($a['score'] ?? 0.5));

        return array_slice($result, 0, 200);
    }

    private function persistToDatabase(string $userId, array $facts): void
    {
        try {
            UserContextProfile::updateOrCreate(
                ['user_phone' => $userId],
                [
                    'facts' => $facts,
                    'last_updated_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("ContextStore: DB persist failed: " . $e->getMessage());
        }
    }

    private function loadFromDatabase(string $userId): array
    {
        try {
            $profile = UserContextProfile::where('user_phone', $userId)->first();
            if ($profile && is_array($profile->facts)) {
                // Re-cache in Redis
                $key = $this->key($userId);
                Redis::setex($key, self::DEFAULT_TTL, serialize($profile->facts));
                return $profile->facts;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("ContextStore: DB load failed: " . $e->getMessage());
        }

        return [];
    }
}
