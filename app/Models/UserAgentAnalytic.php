<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAgentAnalytic extends Model
{
    protected $fillable = [
        'user_id',
        'agent_used',
        'interaction_type',
        'duration',
        'success',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'success' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function scopeByUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByAgent($query, string $agent)
    {
        return $query->where('agent_used', $agent);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    /**
     * Get agent usage stats for a user.
     */
    public static function getUserStats(string $userId, int $days = 30): array
    {
        $analytics = self::byUser($userId)->recent($days)->get();

        if ($analytics->isEmpty()) {
            return [
                'total_interactions' => 0,
                'agents_used' => [],
                'most_used' => null,
                'unique_agents' => 0,
                'avg_duration_ms' => 0,
                'success_rate' => 100,
                'adoption_score' => 0,
            ];
        }

        $agentCounts = $analytics->countBy('agent_used')->sortDesc();
        $totalInteractions = $analytics->count();
        $uniqueAgents = $agentCounts->count();
        $successRate = round($analytics->where('success', true)->count() / $totalInteractions * 100, 1);
        $avgDuration = round($analytics->whereNotNull('duration')->avg('duration') ?? 0);

        // Adoption score: based on unique agents used vs total available (35 agents)
        $totalAvailableAgents = 35;
        $adoptionScore = min(100, round(($uniqueAgents / $totalAvailableAgents) * 100));

        return [
            'total_interactions' => $totalInteractions,
            'agents_used' => $agentCounts->toArray(),
            'most_used' => $agentCounts->keys()->first(),
            'unique_agents' => $uniqueAgents,
            'avg_duration_ms' => $avgDuration,
            'success_rate' => $successRate,
            'adoption_score' => $adoptionScore,
        ];
    }

    /**
     * Get the last N interactions for a user.
     */
    public static function getRecentInteractions(string $userId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return self::byUser($userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Log an agent interaction.
     */
    public static function logInteraction(
        string $userId,
        string $agentUsed,
        string $interactionType = 'command',
        ?int $duration = null,
        bool $success = true,
        ?array $metadata = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'agent_used' => $agentUsed,
            'interaction_type' => $interactionType,
            'duration' => $duration,
            'success' => $success,
            'metadata' => $metadata,
        ]);
    }
}
