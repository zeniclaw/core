<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameAchievement extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'achievement_key',
        'game_type',
        'unlocked_at',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
        ];
    }

    public const ACHIEVEMENTS = [
        'first_win' => ['label' => 'Premiere Victoire', 'emoji' => '🏆', 'description' => 'Gagne ta premiere partie'],
        'ten_wins' => ['label' => '10 Victoires', 'emoji' => '🌟', 'description' => 'Gagne 10 parties'],
        'fifty_wins' => ['label' => '50 Victoires', 'emoji' => '💎', 'description' => 'Gagne 50 parties'],
        'streak_3' => ['label' => 'Streak x3', 'emoji' => '🔥', 'description' => '3 bonnes reponses consecutives'],
        'streak_7' => ['label' => 'Streak x7', 'emoji' => '⚡', 'description' => '7 bonnes reponses consecutives'],
        'streak_7d' => ['label' => '7 Jours Consecutifs', 'emoji' => '📅', 'description' => 'Joue 7 jours de suite'],
        'trivia_master' => ['label' => 'Trivia Master', 'emoji' => '🧠', 'description' => 'Score parfait en trivia'],
        'riddle_solver' => ['label' => 'Enigmiste', 'emoji' => '🔮', 'description' => 'Resous 10 enigmes'],
        'speed_demon' => ['label' => 'Speed Demon', 'emoji' => '⏱', 'description' => 'Reponds en moins de 5 secondes'],
        'all_rounder' => ['label' => 'Polyvalent', 'emoji' => '🎯', 'description' => 'Joue a tous les types de jeux'],
    ];

    public static function getLabel(string $key): string
    {
        return self::ACHIEVEMENTS[$key]['label'] ?? $key;
    }

    public static function getEmoji(string $key): string
    {
        return self::ACHIEVEMENTS[$key]['emoji'] ?? '🏅';
    }
}
