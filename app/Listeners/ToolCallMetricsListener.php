<?php

namespace App\Listeners;

use App\Events\AfterToolCall;
use App\Models\AgentLog;
use Illuminate\Support\Facades\Log;

class ToolCallMetricsListener
{
    public function handle(AfterToolCall $event): void
    {
        // Log slow tool calls (>5s)
        if ($event->durationMs > 5000) {
            Log::warning('Slow tool call', [
                'tool' => $event->toolName,
                'duration_ms' => $event->durationMs,
                'from' => $event->context->from,
            ]);
        }

        // Record metrics in agent_logs for observability
        AgentLog::create([
            'agent_id' => $event->context->agent->id,
            'level' => 'debug',
            'message' => "[ToolMetrics] {$event->toolName} completed in {$event->durationMs}ms",
            'context' => [
                'tool' => $event->toolName,
                'duration_ms' => $event->durationMs,
                'from' => $event->context->from,
                'result_length' => strlen($event->result),
            ],
        ]);
    }
}
