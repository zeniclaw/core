<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserGameProfile extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'current_game',
        'score',
        'total_games',
        'achievements',
        'weekly_challenges_completed',
        'streak',
        'best_streak',
        'last_played_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'total_games' => 'integer',
            'achievements' => 'array',
            'weekly_challenges_completed' => 'integer',
            'streak' => 'integer',
            'best_streak' => 'integer',
            'last_played_at' => 'datetime',
        ];
    }

    public static function getOrCreate(string $userPhone, int $agentId): self
    {
        return self::firstOrCreate(
            ['user_phone' => $userPhone, 'agent_id' => $agentId],
            [
                'current_game' => null,
                'score' => 0,
                'total_games' => 0,
                'achievements' => [],
                'weekly_challenges_completed' => 0,
                'streak' => 0,
                'best_streak' => 0,
            ]
        );
    }

    public function addScore(int $points): void
    {
        $this->increment('score', $points);
    }

    public function incrementStreak(): void
    {
        $this->increment('streak');
        if ($this->streak > $this->best_streak) {
            $this->update(['best_streak' => $this->streak]);
        }
    }

    public function resetStreak(): void
    {
        $this->update(['streak' => 0]);
    }

    public function hasAchievement(string $key): bool
    {
        return in_array($key, $this->achievements ?? []);
    }

    public function unlockAchievement(string $key): bool
    {
        if ($this->hasAchievement($key)) {
            return false;
        }

        $achievements = $this->achievements ?? [];
        $achievements[] = $key;
        $this->update(['achievements' => $achievements]);

        return true;
    }

    public static function getLeaderboard(int $agentId, int $limit = 10)
    {
        return self::where('agent_id', $agentId)
            ->where('total_games', '>', 0)
            ->orderByDesc('score')
            ->limit($limit)
            ->get();
    }
}
