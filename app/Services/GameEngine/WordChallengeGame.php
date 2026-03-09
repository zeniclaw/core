<?php

namespace App\Services\GameEngine;

class WordChallengeGame implements GameInterface
{
    private string $difficulty;

    private const WORDS = [
        ['word' => 'AVENTURE', 'category' => 'voyage'],
        ['word' => 'CHOCOLAT', 'category' => 'nourriture'],
        ['word' => 'ELEPHANT', 'category' => 'animal'],
        ['word' => 'GUITARE', 'category' => 'musique'],
        ['word' => 'HORIZON', 'category' => 'nature'],
        ['word' => 'JOURNAL', 'category' => 'objet'],
        ['word' => 'KANGOUROU', 'category' => 'animal'],
        ['word' => 'LABYRINTHE', 'category' => 'concept'],
        ['word' => 'MYSTERE', 'category' => 'concept'],
        ['word' => 'PAPILLON', 'category' => 'animal'],
        ['word' => 'PYRAMIDE', 'category' => 'monument'],
        ['word' => 'SARDINE', 'category' => 'animal'],
        ['word' => 'TRESOR', 'category' => 'concept'],
        ['word' => 'VOLCAN', 'category' => 'nature'],
        ['word' => 'ALPHABET', 'category' => 'langage'],
    ];

    public function __construct(string $difficulty = 'medium')
    {
        $this->difficulty = $difficulty;
    }

    public function getType(): string
    {
        return 'word_challenge';
    }

    public function initGame(): array
    {
        $count = match ($this->difficulty) {
            'easy' => 3,
            'hard' => 7,
            default => 5,
        };

        $words = collect(self::WORDS)->shuffle()->take($count)->map(function ($item) {
            $word = $item['word'];
            $shuffled = $this->shuffleWord($word);
            return [
                'word' => $word,
                'shuffled' => $shuffled,
                'category' => $item['category'],
            ];
        })->values()->toArray();

        return [
            'type' => 'word_challenge',
            'difficulty' => $this->difficulty,
            'words' => $words,
            'current_index' => 0,
            'correct' => 0,
            'total' => $count,
            'streak' => 0,
            'bonus_points' => 0,
        ];
    }

    private function shuffleWord(string $word): string
    {
        $chars = mb_str_split($word);
        $attempts = 0;
        do {
            shuffle($chars);
            $attempts++;
        } while (implode('', $chars) === $word && $attempts < 10);

        return implode('', $chars);
    }

    public function validateAnswer(string $userAnswer, array $gameState): array
    {
        $index = $gameState['current_index'];
        $wordData = $gameState['words'][$index] ?? null;

        if (!$wordData) {
            return ['correct' => false, 'game_state' => $gameState, 'feedback' => 'Mot introuvable.'];
        }

        $answer = mb_strtoupper(trim($userAnswer));
        $isCorrect = $answer === $wordData['word'];

        $streak = $isCorrect ? ($gameState['streak'] + 1) : 0;
        $bonus = ($isCorrect && $streak >= 3) ? 5 : 0;

        $gameState['current_index']++;
        $gameState['correct'] += $isCorrect ? 1 : 0;
        $gameState['streak'] = $streak;
        $gameState['bonus_points'] += $bonus;

        $feedback = $isCorrect
            ? "Correct ! Le mot etait *{$wordData['word']}*" . ($bonus > 0 ? " (+{$bonus} bonus streak x{$streak})" : '')
            : "Raté ! Le mot etait *{$wordData['word']}*";

        return [
            'correct' => $isCorrect,
            'correct_answer' => $wordData['word'],
            'game_state' => $gameState,
            'feedback' => $feedback,
            'bonus' => $bonus,
            'streak' => $streak,
        ];
    }

    public function getScore(array $gameState): int
    {
        return ($gameState['correct'] ?? 0) + ($gameState['bonus_points'] ?? 0);
    }

    public function formatQuestion(array $gameState): string
    {
        $index = $gameState['current_index'];
        $total = $gameState['total'];
        $wordData = $gameState['words'][$index] ?? null;

        if (!$wordData) {
            return 'Pas de mot disponible.';
        }

        $text = "*Mot {$index}/{$total}*\n\n";
        $text .= "📝 Retrouve le mot melange :\n\n";
        $text .= "```{$wordData['shuffled']}```\n\n";
        $text .= "Categorie : _{$wordData['category']}_";

        return $text;
    }

    public function isFinished(array $gameState): bool
    {
        return ($gameState['current_index'] ?? 0) >= ($gameState['total'] ?? 0);
    }
}
