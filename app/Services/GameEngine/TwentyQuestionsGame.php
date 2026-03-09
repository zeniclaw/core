<?php

namespace App\Services\GameEngine;

class TwentyQuestionsGame implements GameInterface
{
    private string $difficulty;

    private const SUBJECTS = [
        ['subject' => 'un chat', 'category' => 'animal', 'hints' => ['C\'est un animal domestique', 'Il ronronne', 'Il a des moustaches']],
        ['subject' => 'la Tour Eiffel', 'category' => 'monument', 'hints' => ['C\'est un monument', 'Il est en France', 'Il est en metal']],
        ['subject' => 'une guitare', 'category' => 'objet', 'hints' => ['C\'est un instrument', 'Il a des cordes', 'On en joue avec les doigts']],
        ['subject' => 'le soleil', 'category' => 'nature', 'hints' => ['C\'est dans le ciel', 'C\'est tres chaud', 'Il eclaire la Terre']],
        ['subject' => 'un avion', 'category' => 'transport', 'hints' => ['Ca vole', 'C\'est un moyen de transport', 'Ca a des ailes']],
        ['subject' => 'une pizza', 'category' => 'nourriture', 'hints' => ['C\'est de la nourriture', 'C\'est d\'origine italienne', 'C\'est rond']],
        ['subject' => 'un livre', 'category' => 'objet', 'hints' => ['Ca contient des mots', 'On le lit', 'Il a des pages']],
        ['subject' => 'la lune', 'category' => 'nature', 'hints' => ['C\'est dans le ciel', 'On la voit la nuit', 'Elle tourne autour de la Terre']],
    ];

    public function __construct(string $difficulty = 'medium')
    {
        $this->difficulty = $difficulty;
    }

    public function getType(): string
    {
        return 'twenty_questions';
    }

    public function initGame(): array
    {
        $subject = collect(self::SUBJECTS)->random();
        $maxQuestions = match ($this->difficulty) {
            'easy' => 15,
            'hard' => 8,
            default => 10,
        };

        return [
            'type' => 'twenty_questions',
            'difficulty' => $this->difficulty,
            'subject' => $subject['subject'],
            'category' => $subject['category'],
            'hints' => $subject['hints'],
            'questions_asked' => 0,
            'max_questions' => $maxQuestions,
            'hints_given' => 0,
            'guessed' => false,
            'streak' => 0,
            'bonus_points' => 0,
        ];
    }

    public function validateAnswer(string $userAnswer, array $gameState): array
    {
        $answer = mb_strtolower(trim($userAnswer));
        $subject = mb_strtolower($gameState['subject']);

        // Check if user is guessing
        $isGuess = str_starts_with($answer, 'c\'est ') || str_starts_with($answer, 'est-ce ')
            || str_starts_with($answer, 'je devine ') || str_starts_with($answer, 'reponse ');

        if ($isGuess || str_contains($answer, $subject) || similar_text($answer, $subject) > strlen($subject) * 0.7) {
            // Check if correct
            $cleanGuess = preg_replace('/^(c\'est |est-ce |je devine |reponse :?\s*)/iu', '', $answer);
            $isCorrect = str_contains(mb_strtolower($cleanGuess), $subject)
                || str_contains($subject, mb_strtolower($cleanGuess));

            if ($isCorrect) {
                $gameState['guessed'] = true;
                $points = max(1, $gameState['max_questions'] - $gameState['questions_asked']);
                $gameState['bonus_points'] = $points;

                return [
                    'correct' => true,
                    'game_state' => $gameState,
                    'feedback' => "Bravo ! C'etait bien *{$gameState['subject']}* !\nTu as trouve en {$gameState['questions_asked']} questions. (+{$points} points)",
                ];
            }
        }

        // It's a question — give a hint
        $gameState['questions_asked']++;

        // Auto-give hints at intervals
        $hintIndex = $gameState['hints_given'] ?? 0;
        $hints = $gameState['hints'] ?? [];
        $hint = '';

        if ($hintIndex < count($hints) && $gameState['questions_asked'] % 3 === 0) {
            $hint = "\n💡 Indice : {$hints[$hintIndex]}";
            $gameState['hints_given'] = $hintIndex + 1;
        }

        $remaining = $gameState['max_questions'] - $gameState['questions_asked'];

        if ($remaining <= 0) {
            $gameState['guessed'] = false;
            return [
                'correct' => false,
                'game_state' => $gameState,
                'feedback' => "Temps ecoule ! La reponse etait *{$gameState['subject']}*.",
            ];
        }

        // Simple yes/no response based on category match
        $category = $gameState['category'];
        $response = $this->generateResponse($answer, $category, $gameState['subject']);

        return [
            'correct' => null,
            'game_state' => $gameState,
            'feedback' => "{$response}{$hint}\n\n_{$remaining} questions restantes_",
            'is_question' => true,
        ];
    }

    private function generateResponse(string $question, string $category, string $subject): string
    {
        $subject = mb_strtolower($subject);

        // Simple keyword matching for yes/no
        $yesPatterns = [
            'animal' => ['animal', 'vivant', 'creature', 'domestique', 'poils', 'pattes'],
            'monument' => ['monument', 'construction', 'batiment', 'grand', 'celebre', 'connu'],
            'objet' => ['objet', 'chose', 'utilise', 'toucher'],
            'nature' => ['nature', 'naturel', 'ciel', 'espace'],
            'transport' => ['transport', 'deplacer', 'vehicule', 'voyage'],
            'nourriture' => ['manger', 'nourriture', 'aliment', 'cuisine', 'gout'],
        ];

        $patterns = $yesPatterns[$category] ?? [];
        foreach ($patterns as $pattern) {
            if (str_contains($question, $pattern)) {
                return '✅ Oui !';
            }
        }

        // Default semi-random response
        return str_contains($question, '?') ? '❌ Non.' : '🤔 Pose une question oui/non !';
    }

    public function getScore(array $gameState): int
    {
        if ($gameState['guessed'] ?? false) {
            return $gameState['bonus_points'] ?? 1;
        }
        return 0;
    }

    public function formatQuestion(array $gameState): string
    {
        $max = $gameState['max_questions'];
        $asked = $gameState['questions_asked'];
        $category = $gameState['category'];

        $text = "*20 Questions*\n\n";
        $text .= "Je pense a quelque chose ({$category}).\n";
        $text .= "Pose des questions oui/non pour deviner !\n\n";
        $text .= "Questions restantes : {$max}\n";
        $text .= "Quand tu penses savoir, dis _\"c'est [ta reponse]\"_";

        return $text;
    }

    public function isFinished(array $gameState): bool
    {
        if ($gameState['guessed'] ?? false) return true;
        return ($gameState['questions_asked'] ?? 0) >= ($gameState['max_questions'] ?? 10);
    }
}
