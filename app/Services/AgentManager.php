<?php

namespace App\Services;

use App\Models\AgentLog;

/**
 * AgentManager — Centralized agent logging utility.
 *
 * Ensures every log entry is attributed to a named agent.
 * Prevents 'unknown' attribution in log analysis and performance monitoring.
 */
class AgentManager
{
    /**
     * Write an agent log entry, guaranteeing the [agent_name] prefix is present.
     *
     * If $agentName is null or empty, falls back to the calling class name
     * so logs are never silently attributed to 'unknown'.
     */
    public static function log(
        int $agentId,
        string $agentName,
        string $message,
        array $context = [],
        string $level = 'info'
    ): void {
        $name = filled($agentName) ? $agentName : static::callerName();

        if (!str_starts_with($message, '[')) {
            $message = "[{$name}] {$message}";
        }

        AgentLog::create([
            'agent_id' => $agentId,
            'level'    => $level,
            'message'  => $message,
            'context'  => $context,
        ]);
    }

    /**
     * Derive a fallback name from the calling class when no name is supplied.
     */
    private static function callerName(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[2]['class'] ?? $trace[1]['class'] ?? null;

        return $caller ? class_basename($caller) : 'system';
    }
}
