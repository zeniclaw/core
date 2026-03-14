<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;

class RateLimiter
{
    private const DEFAULT_MAX_PER_MINUTE = 20;
    private const DEFAULT_MAX_PER_HOUR = 200;
    private const ADMIN_MULTIPLIER = 5;

    /**
     * Check if a user is rate-limited.
     * Returns null if OK, or error message if limited.
     */
    public static function check(string $userId, string $role = 'user'): ?string
    {
        $maxPerMinute = self::DEFAULT_MAX_PER_MINUTE;
        $maxPerHour = self::DEFAULT_MAX_PER_HOUR;

        if ($role === 'admin') {
            $maxPerMinute *= self::ADMIN_MULTIPLIER;
            $maxPerHour *= self::ADMIN_MULTIPLIER;
        }

        // Per-minute check
        $minuteKey = "rate_limit:{$userId}:" . date('Y-m-d-H-i');
        $minuteCount = (int) Cache::get($minuteKey, 0);
        if ($minuteCount >= $maxPerMinute) {
            AuditLog::logSecurity($userId, 'rate_limit_exceeded', [
                'period' => 'minute',
                'count' => $minuteCount,
                'limit' => $maxPerMinute,
            ]);
            return "Trop de requetes ({$minuteCount}/{$maxPerMinute} par minute). Reessaie dans un instant.";
        }

        // Per-hour check
        $hourKey = "rate_limit:{$userId}:" . date('Y-m-d-H');
        $hourCount = (int) Cache::get($hourKey, 0);
        if ($hourCount >= $maxPerHour) {
            AuditLog::logSecurity($userId, 'rate_limit_exceeded', [
                'period' => 'hour',
                'count' => $hourCount,
                'limit' => $maxPerHour,
            ]);
            return "Limite horaire atteinte ({$hourCount}/{$maxPerHour}). Reessaie dans quelques minutes.";
        }

        return null;
    }

    /**
     * Record a request for rate limiting.
     */
    public static function hit(string $userId): void
    {
        $minuteKey = "rate_limit:{$userId}:" . date('Y-m-d-H-i');
        $hourKey = "rate_limit:{$userId}:" . date('Y-m-d-H');

        Cache::increment($minuteKey);
        Cache::put($minuteKey, (int) Cache::get($minuteKey, 1), 120); // 2 min TTL

        Cache::increment($hourKey);
        Cache::put($hourKey, (int) Cache::get($hourKey, 1), 7200); // 2 hour TTL
    }

    /**
     * Check if a phone number is in the allowlist.
     * Returns true if allowed, false if blocked.
     * When allowlist is empty, all numbers are allowed.
     */
    public static function isAllowed(string $from, int $agentId): bool
    {
        // Web chat users always allowed
        if (str_starts_with($from, 'web-')) {
            return true;
        }

        // Check agent-level whitelist (existing feature)
        $agent = \App\Models\Agent::find($agentId);
        if (!$agent || !$agent->whitelist_enabled) {
            return true; // Whitelist not enabled = all allowed
        }

        // Check session whitelist
        $sessionKey = \App\Models\AgentSession::keyFor($agentId, 'whatsapp', $from);
        $session = \App\Models\AgentSession::where('session_key', $sessionKey)->first();

        return $session && $session->whitelisted;
    }
}
