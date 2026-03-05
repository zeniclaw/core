<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HangmanStats extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'games_played',
        'games_won',
        'best_streak',
        'current_streak',
        'total_guesses',
        'last_played_at',
    ];

    protected function casts(): array
    {
        return [
            'games_played' => 'integer',
            'games_won' => 'integer',
            'best_streak' => 'integer',
            'current_streak' => 'integer',
            'total_guesses' => 'integer',
            'last_played_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_phone', 'phone');
    }

    public static function getOrCreate(string $userPhone, int $agentId): self
    {
        return self::firstOrCreate(
            ['user_phone' => $userPhone, 'agent_id' => $agentId],
            [
                'games_played' => 0,
                'games_won' => 0,
                'best_streak' => 0,
                'current_streak' => 0,
                'total_guesses' => 0,
            ]
        );
    }

    public function getWinRate(): float
    {
        if ($this->games_played === 0) return 0;
        return round(($this->games_won / $this->games_played) * 100, 1);
    }
}
