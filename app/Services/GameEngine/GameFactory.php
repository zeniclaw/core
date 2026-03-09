<?php

namespace App\Services\GameEngine;

class GameFactory
{
    public static function create(string $gameType, string $difficulty = 'medium'): GameInterface
    {
        return match ($gameType) {
            'trivia' => new TriviaGame($difficulty),
            'riddle' => new RiddleGame($difficulty),
            'twenty_questions' => new TwentyQuestionsGame($difficulty),
            'word_challenge' => new WordChallengeGame($difficulty),
            default => new TriviaGame($difficulty),
        };
    }

    public static function getAvailableGames(): array
    {
        return [
            'trivia' => ['label' => 'Trivia', 'emoji' => '🧠', 'description' => 'Questions de culture generale'],
            'riddle' => ['label' => 'Enigmes', 'emoji' => '🔮', 'description' => 'Enigmes et devinettes a resoudre'],
            'twenty_questions' => ['label' => '20 Questions', 'emoji' => '❓', 'description' => 'Devine en posant des questions oui/non'],
            'word_challenge' => ['label' => 'Mots', 'emoji' => '📝', 'description' => 'Anagrammes et mots melanges'],
        ];
    }

    public static function resolveGameType(string $input): ?string
    {
        $input = mb_strtolower(trim($input));

        $aliases = [
            'trivia' => 'trivia',
            'culture' => 'trivia',
            'culture generale' => 'trivia',
            'general' => 'trivia',
            'enigme' => 'riddle',
            'enigmes' => 'riddle',
            'riddle' => 'riddle',
            'devinette' => 'riddle',
            'devinettes' => 'riddle',
            '20 questions' => 'twenty_questions',
            '20questions' => 'twenty_questions',
            'twenty' => 'twenty_questions',
            'vingt questions' => 'twenty_questions',
            'mot' => 'word_challenge',
            'mots' => 'word_challenge',
            'word' => 'word_challenge',
            'anagramme' => 'word_challenge',
            'anagrammes' => 'word_challenge',
        ];

        return $aliases[$input] ?? null;
    }
}
