<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'agent_name',
        'action',
        'tool_name',
        'input_summary',
        'result_status',
        'duration_ms',
        'ip_address',
        'metadata',
    ];

    protected $casts = [
        'input_summary' => 'array',
        'metadata' => 'array',
    ];

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForAgent($query, string $agentName)
    {
        return $query->where('agent_name', $agentName);
    }

    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Log a tool execution for audit trail.
     */
    public static function logToolCall(string $userId, string $agentName, string $toolName, array $input, string $status, int $durationMs, array $metadata = []): self
    {
        return self::create([
            'user_id' => $userId,
            'agent_name' => $agentName,
            'action' => 'tool_call',
            'tool_name' => $toolName,
            'input_summary' => self::sanitizeInput($input),
            'result_status' => $status,
            'duration_ms' => $durationMs,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log a security event.
     */
    public static function logSecurity(string $userId, string $action, array $metadata = []): self
    {
        return self::create([
            'user_id' => $userId,
            'agent_name' => 'system',
            'action' => $action,
            'result_status' => 'security',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Sanitize input to avoid logging sensitive data.
     */
    private static function sanitizeInput(array $input): array
    {
        $sanitized = [];
        foreach ($input as $key => $value) {
            if (in_array(strtolower($key), ['password', 'token', 'api_key', 'secret', 'credential'])) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_string($value) && mb_strlen($value) > 200) {
                $sanitized[$key] = mb_substr($value, 0, 200) . '...';
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
}
