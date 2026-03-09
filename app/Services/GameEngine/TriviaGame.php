<?php

namespace App\Services\GameEngine;

class TriviaGame implements GameInterface
{
    private string $difficulty;

    private const QUESTIONS = [
        [
            'question' => 'Quelle est la capitale de l\'Australie ?',
            'options' => ['Sydney', 'Melbourne', 'Canberra', 'Brisbane'],
            'answer' => 2,
        ],
        [
            'question' => 'Combien de planetes composent le systeme solaire ?',
            'options' => ['7', '8', '9', '10'],
            'answer' => 1,
        ],
        [
            'question' => 'Quel est le plus grand ocean du monde ?',
            'options' => ['Atlantique', 'Indien', 'Arctique', 'Pacifique'],
            'answer' => 3,
        ],
        [
            'question' => 'En quelle annee a ete construite la Tour Eiffel ?',
            'options' => ['1879', '1889', '1899', '1909'],
            'answer' => 1,
        ],
        [
            'question' => 'Quel element chimique a pour symbole "Au" ?',
            'options' => ['Argent', 'Aluminium', 'Or', 'Cuivre'],
            'answer' => 2,
        ],
        [
            'question' => 'Qui a peint la Joconde ?',
            'options' => ['Michel-Ange', 'Raphael', 'Leonard de Vinci', 'Botticelli'],
            'answer' => 2,
        ],
        [
            'question' => 'Quel est le plus long fleuve du monde ?',
            'options' => ['Amazone', 'Nil', 'Mississippi', 'Yangtze'],
            'answer' => 1,
        ],
        [
            'question' => 'Combien d\'os compte le corps humain adulte ?',
            'options' => ['186', '196', '206', '216'],
            'answer' => 2,
        ],
        [
            'question' => 'Quel pays a le plus de fuseaux horaires ?',
            'options' => ['Russie', 'Etats-Unis', 'France', 'Chine'],
            'answer' => 2,
        ],
        [
            'question' => 'Quelle est la vitesse de la lumiere en km/s ?',
            'options' => ['200 000', '300 000', '400 000', '150 000'],
            'answer' => 1,
        ],
        [
            'question' => 'Quel gaz les plantes absorbent-elles lors de la photosynthese ?',
            'options' => ['Oxygene', 'Azote', 'Dioxyde de carbone', 'Hydrogene'],
            'answer' => 2,
        ],
        [
            'question' => 'Quel est le plus petit pays du monde ?',
            'options' => ['Monaco', 'Vatican', 'Saint-Marin', 'Liechtenstein'],
            'answer' => 1,
        ],
        [
            'question' => 'Qui a ecrit "Les Miserables" ?',
            'options' => ['Emile Zola', 'Victor Hugo', 'Gustave Flaubert', 'Alexandre Dumas'],
            'answer' => 1,
        ],
        [
            'question' => 'Combien de cordes a un violon ?',
            'options' => ['3', '4', '5', '6'],
            'answer' => 1,
        ],
        [
            'question' => 'Quel animal est le plus rapide sur terre ?',
            'options' => ['Lion', 'Guepard', 'Gazelle', 'Faucon pelerin'],
            'answer' => 1,
        ],
    ];

    public function __construct(string $difficulty = 'medium')
    {
        $this->difficulty = $difficulty;
    }

    public function getType(): string
    {
        return 'trivia';
    }

    public function initGame(): array
    {
        $count = match ($this->difficulty) {
            'easy' => 3,
            'hard' => 7,
            default => 5,
        };

        $questions = collect(self::QUESTIONS)->shuffle()->take($count)->values()->toArray();

        return [
            'type' => 'trivia',
            'difficulty' => $this->difficulty,
            'questions' => $questions,
            'current_index' => 0,
            'correct' => 0,
            'total' => $count,
            'streak' => 0,
            'bonus_points' => 0,
        ];
    }

    public function validateAnswer(string $userAnswer, array $gameState): array
    {
        $index = $gameState['current_index'];
        $question = $gameState['questions'][$index] ?? null;

        if (!$question) {
            return ['correct' => false, 'game_state' => $gameState, 'feedback' => 'Question introuvable.'];
        }

        $correctIndex = $question['answer'];
        $correctText = $question['options'][$correctIndex] ?? '';

        $answer = trim($userAnswer);
        $isCorrect = false;

        // Check by option number (1-4) or letter (A-D)
        if (preg_match('/^([1-4])$/u', $answer, $m)) {
            $isCorrect = ((int) $m[1] - 1) === $correctIndex;
        } elseif (preg_match('/^([a-d])$/iu', $answer, $m)) {
            $letterIndex = ord(mb_strtolower($m[1])) - ord('a');
            $isCorrect = $letterIndex === $correctIndex;
        } else {
            // Check by text content
            $isCorrect = mb_strtolower(trim($answer)) === mb_strtolower(trim($correctText));
        }

        $streak = $isCorrect ? ($gameState['streak'] + 1) : 0;
        $bonus = 0;
        if ($isCorrect && $streak >= 3) {
            $bonus = 5;
        }

        $gameState['current_index']++;
        $gameState['correct'] += $isCorrect ? 1 : 0;
        $gameState['streak'] = $streak;
        $gameState['bonus_points'] += $bonus;

        $feedback = $isCorrect
            ? "Correct !" . ($bonus > 0 ? " (+{$bonus} bonus streak x{$streak})" : '')
            : "Raté ! La reponse etait : *{$correctText}*";

        return [
            'correct' => $isCorrect,
            'correct_answer' => $correctText,
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
        $question = $gameState['questions'][$index] ?? null;

        if (!$question) {
            return 'Pas de question disponible.';
        }

        $letters = ['A', 'B', 'C', 'D'];
        $text = "*Question {$index}/{$total}*\n\n";
        $text .= "{$question['question']}\n\n";

        foreach ($question['options'] as $i => $option) {
            $text .= "{$letters[$i]}. {$option}\n";
        }

        $text .= "\nReponds avec A, B, C ou D";

        return $text;
    }

    public function isFinished(array $gameState): bool
    {
        return ($gameState['current_index'] ?? 0) >= ($gameState['total'] ?? 0);
    }
}
