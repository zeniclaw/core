<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizScore extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'quiz_id',
        'category',
        'score',
        'total_questions',
        'time_taken',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'total_questions' => 'integer',
            'time_taken' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function getPercentage(): float
    {
        if ($this->total_questions === 0) return 0;
        return round(($this->score / $this->total_questions) * 100, 1);
    }

    public static function getLeaderboard(int $agentId, int $limit = 10): \Illuminate\Support\Collection
    {
        return self::where('agent_id', $agentId)
            ->selectRaw('user_phone, SUM(score) as total_score, COUNT(*) as quizzes_played, AVG(score * 100.0 / total_questions) as avg_percentage')
            ->groupBy('user_phone')
            ->orderByDesc('total_score')
            ->limit($limit)
            ->get();
    }

    public static function getUserStats(string $userPhone, int $agentId): array
    {
        $scores = self::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->get();

        if ($scores->isEmpty()) {
            return [
                'quizzes_played' => 0,
                'total_score' => 0,
                'avg_percentage' => 0,
                'best_score' => 0,
                'favorite_category' => null,
                'current_streak' => 0,
            ];
        }

        $categoryCount = $scores->groupBy('category')->map->count();

        return [
            'quizzes_played' => $scores->count(),
            'total_score' => $scores->sum('score'),
            'avg_percentage' => round($scores->avg(fn ($s) => $s->getPercentage()), 1),
            'best_score' => $scores->max(fn ($s) => $s->getPercentage()),
            'favorite_category' => $categoryCount->sortDesc()->keys()->first(),
            'current_streak' => self::calculateStreak($userPhone, $agentId),
        ];
    }

    private static function calculateStreak(string $userPhone, int $agentId): int
    {
        $recentScores = self::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->orderByDesc('completed_at')
            ->limit(50)
            ->get();

        $streak = 0;
        foreach ($recentScores as $score) {
            if ($score->getPercentage() >= 50) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }
}
