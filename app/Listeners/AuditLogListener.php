<?php

namespace App\Listeners;

use App\Events\AfterToolCall;
use App\Models\AuditLog;

class AuditLogListener
{
    public function handle(AfterToolCall $event): void
    {
        try {
            $status = 'success';
            if (is_string($event->result)) {
                $decoded = json_decode($event->result, true);
                if (is_array($decoded) && isset($decoded['error'])) {
                    $status = 'error';
                }
            }

            AuditLog::logToolCall(
                userId: $event->context->from ?? 'unknown',
                agentName: $event->context->routedAgent ?? 'unknown',
                toolName: $event->toolName,
                input: $event->input,
                status: $status,
                durationMs: (int) $event->durationMs,
                metadata: [
                    'agent_id' => $event->context->agent->id ?? null,
                ],
            );
        } catch (\Throwable $e) {
            // Never break the flow for audit logging
            \Illuminate\Support\Facades\Log::warning('AuditLogListener failed: ' . $e->getMessage());
        }
    }
}
