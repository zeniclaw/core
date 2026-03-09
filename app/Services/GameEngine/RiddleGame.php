<?php

namespace App\Services\GameEngine;

class RiddleGame implements GameInterface
{
    private string $difficulty;

    private const RIDDLES = [
        [
            'riddle' => 'Je suis toujours devant toi mais ne peux jamais etre vu. Que suis-je ?',
            'answer' => 'l\'avenir',
            'alternatives' => ['avenir', 'le futur', 'futur'],
            'hint' => 'Pense au temps...',
        ],
        [
            'riddle' => 'Plus je seche, plus je suis mouillee. Que suis-je ?',
            'answer' => 'une serviette',
            'alternatives' => ['serviette', 'la serviette'],
            'hint' => 'On l\'utilise apres la douche.',
        ],
        [
            'riddle' => 'J\'ai des villes mais pas de maisons, des forets mais pas d\'arbres, de l\'eau mais pas de poissons. Que suis-je ?',
            'answer' => 'une carte',
            'alternatives' => ['carte', 'la carte', 'carte geographique', 'une carte geographique'],
            'hint' => 'On la consulte pour voyager.',
        ],
        [
            'riddle' => 'On me prend sans me toucher, on me jette sans me lancer. Que suis-je ?',
            'answer' => 'une photo',
            'alternatives' => ['photo', 'la photo', 'une photographie', 'photographie'],
            'hint' => 'Souriez !',
        ],
        [
            'riddle' => 'Je commence la nuit et je finis le matin. Que suis-je ?',
            'answer' => 'la lettre n',
            'alternatives' => ['n', 'lettre n', 'la lettre N'],
            'hint' => 'C\'est une question de lettres...',
        ],
        [
            'riddle' => 'J\'ai un chapeau mais pas de tete, un pied mais pas de chaussure. Que suis-je ?',
            'answer' => 'un champignon',
            'alternatives' => ['champignon', 'le champignon'],
            'hint' => 'On me trouve en foret.',
        ],
        [
            'riddle' => 'Plus on en enleve, plus c\'est grand. Que suis-je ?',
            'answer' => 'un trou',
            'alternatives' => ['trou', 'le trou'],
            'hint' => 'On peut creuser pour me faire.',
        ],
        [
            'riddle' => 'Je peux voyager autour du monde tout en restant dans mon coin. Que suis-je ?',
            'answer' => 'un timbre',
            'alternatives' => ['timbre', 'le timbre'],
            'hint' => 'On me colle sur les enveloppes.',
        ],
        [
            'riddle' => 'J\'ai des dents mais je ne mords pas. Que suis-je ?',
            'answer' => 'un peigne',
            'alternatives' => ['peigne', 'le peigne'],
            'hint' => 'On m\'utilise pour les cheveux.',
        ],
        [
            'riddle' => 'Je monte sans bouger. Que suis-je ?',
            'answer' => 'la temperature',
            'alternatives' => ['temperature', 'la chaleur', 'chaleur'],
            'hint' => 'Le thermometre le montre.',
        ],
    ];

    public function __construct(string $difficulty = 'medium')
    {
        $this->difficulty = $difficulty;
    }

    public function getType(): string
    {
        return 'riddle';
    }

    public function initGame(): array
    {
        $count = match ($this->difficulty) {
            'easy' => 3,
            'hard' => 7,
            default => 5,
        };

        $riddles = collect(self::RIDDLES)->shuffle()->take($count)->values()->toArray();

        return [
            'type' => 'riddle',
            'difficulty' => $this->difficulty,
            'riddles' => $riddles,
            'current_index' => 0,
            'correct' => 0,
            'total' => $count,
            'hints_used' => 0,
            'streak' => 0,
            'bonus_points' => 0,
        ];
    }

    public function validateAnswer(string $userAnswer, array $gameState): array
    {
        $index = $gameState['current_index'];
        $riddle = $gameState['riddles'][$index] ?? null;

        if (!$riddle) {
            return ['correct' => false, 'game_state' => $gameState, 'feedback' => 'Enigme introuvable.'];
        }

        // Check for hint request
        $lower = mb_strtolower(trim($userAnswer));
        if (in_array($lower, ['indice', 'hint', 'aide', 'help'])) {
            $gameState['hints_used']++;
            return [
                'correct' => null,
                'game_state' => $gameState,
                'feedback' => "💡 Indice : {$riddle['hint']}",
                'is_hint' => true,
            ];
        }

        $answer = mb_strtolower(trim($userAnswer));
        $correct = mb_strtolower($riddle['answer']);
        $alternatives = array_map('mb_strtolower', $riddle['alternatives'] ?? []);

        $isCorrect = $answer === $correct || in_array($answer, $alternatives);

        // Also check if the answer is contained in the user's response
        if (!$isCorrect) {
            $allPossible = array_merge([$correct], $alternatives);
            foreach ($allPossible as $possible) {
                if (str_contains($answer, $possible)) {
                    $isCorrect = true;
                    break;
                }
            }
        }

        $streak = $isCorrect ? ($gameState['streak'] + 1) : 0;
        $bonus = ($isCorrect && $streak >= 3) ? 5 : 0;

        $gameState['current_index']++;
        $gameState['correct'] += $isCorrect ? 1 : 0;
        $gameState['streak'] = $streak;
        $gameState['bonus_points'] += $bonus;

        $feedback = $isCorrect
            ? "Bravo !" . ($bonus > 0 ? " (+{$bonus} bonus streak x{$streak})" : '')
            : "Raté ! La reponse etait : *{$riddle['answer']}*";

        return [
            'correct' => $isCorrect,
            'correct_answer' => $riddle['answer'],
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
        $riddle = $gameState['riddles'][$index] ?? null;

        if (!$riddle) {
            return 'Pas d\'enigme disponible.';
        }

        $text = "*Enigme {$index}/{$total}*\n\n";
        $text .= "🔮 {$riddle['riddle']}\n\n";
        $text .= "_Tape 'indice' si tu seches !_";

        return $text;
    }

    public function isFinished(array $gameState): bool
    {
        return ($gameState['current_index'] ?? 0) >= ($gameState['total'] ?? 0);
    }
}
