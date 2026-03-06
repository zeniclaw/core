<?php

namespace App\Services\Agents;

use App\Models\HangmanGame;
use App\Models\HangmanStats;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;

class HangmanGameAgent extends BaseAgent
{
    private const MAX_WRONG = 6;

    private const WORD_LIST = [
        'LARAVEL', 'SYMFONY', 'PYTHON', 'JAVASCRIPT', 'ALGORITHME',
        'DATABASE', 'SERVEUR', 'TERMINAL', 'INTERNET', 'NAVIGATEUR',
        'CLAVIER', 'ECRAN', 'SOURIS', 'LOGICIEL', 'PROGRAMME',
        'FONCTION', 'VARIABLE', 'TABLEAU', 'BOUCLE', 'CONDITION',
        'RESEAU', 'SECURITE', 'CRYPTAGE', 'SERVEUR', 'MEMOIRE',
        'DISQUE', 'FENETRE', 'DOSSIER', 'FICHIER', 'CONSOLE',
        'INTERFACE', 'MODULE', 'PAQUET', 'CLASSE', 'METHODE',
        'REQUETE', 'REPONSE', 'ERREUR', 'DEBUGGER', 'COMPILATEUR',
        'ELEPHANT', 'GIRAFE', 'PAPILLON', 'CHOCOLAT', 'MONTAGNE',
        'VOITURE', 'AVION', 'BATEAU', 'MAISON', 'JARDIN',
    ];

    public function name(): string
    {
        return 'hangman';
    }

    public function description(): string
    {
        return 'Agent jeu du Pendu (Hangman). Permet de jouer au pendu avec des mots aleatoires ou personnalises, suivre ses statistiques de victoires/defaites, streaks et taux de reussite.';
    }

    public function keywords(): array
    {
        return [
            'pendu', 'hangman', 'jeu du pendu', 'hangman game',
            'jouer au pendu', 'play hangman', 'partie de pendu',
            'nouvelle partie pendu', 'new game hangman',
            'pendu start', 'hangman start', '/hangman',
            'deviner un mot', 'guess a word',
            'stats pendu', 'hangman stats', 'statistiques pendu',
            'mot mystere', 'mot cache', 'mystery word',
            'jeu de mots', 'word game',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match('/\bhangman\b|\bpendu\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        $this->log($context, 'Hangman command received', ['body' => mb_substr($body, 0, 100)]);

        // Parse commands
        if (preg_match('/\/hangman\s+start/i', $lower) || preg_match('/\b(nouvelle?\s+partie|new\s+game|start.*pendu|jouer.*pendu|pendu\s+start)\b/iu', $lower)) {
            return $this->startGame($context);
        }

        if (preg_match('/\/hangman\s+word\s+(.+)/i', $lower, $m)) {
            return $this->startGameWithWord($context, trim($m[1]));
        }

        if (preg_match('/\/hangman\s+stats/i', $lower) || preg_match('/\b(stats?|statistiques?)\s*(hangman|pendu)\b/iu', $lower) || preg_match('/\b(hangman|pendu)\s*(stats?|statistiques?)\b/iu', $lower)) {
            return $this->showStats($context);
        }

        if (preg_match('/\/hangman\s+guess\s+([a-zA-Z\x{00C0}-\x{017F}])/iu', $lower, $m)) {
            return $this->guessLetter($context, mb_strtoupper($m[1]));
        }

        // Single letter guess (when game is active)
        if (preg_match('/^([a-zA-Z\x{00C0}-\x{017F}])$/u', trim($body), $m)) {
            $activeGame = $this->getActiveGame($context);
            if ($activeGame) {
                return $this->guessLetter($context, mb_strtoupper($m[1]));
            }
        }

        // Natural language handling via Claude
        return $this->handleNaturalLanguage($body, $context);
    }

    private function startGame(AgentContext $context, ?string $customWord = null): AgentResult
    {
        // Check for existing active game
        $existing = $this->getActiveGame($context);
        if ($existing) {
            $board = $this->getDisplayBoard($existing);
            return AgentResult::reply("Tu as deja une partie en cours !\n\n{$board}\n\nEnvoie une lettre pour continuer ou /hangman start pour recommencer.");
        }

        $word = $customWord ?? self::WORD_LIST[array_rand(self::WORD_LIST)];
        $word = mb_strtoupper($word);

        $game = HangmanGame::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'word' => $word,
            'guessed_letters' => [],
            'wrong_count' => 0,
            'status' => 'playing',
        ]);

        $board = $this->getDisplayBoard($game);

        $this->log($context, 'New hangman game started', ['game_id' => $game->id, 'word_length' => mb_strlen($word)]);

        return AgentResult::reply("🎮 *Nouvelle partie de Pendu !*\n\n{$board}\n\nEnvoie une lettre pour deviner !");
    }

    private function startGameWithWord(AgentContext $context, string $word): AgentResult
    {
        $word = mb_strtoupper(trim($word));

        if (mb_strlen($word) < 2 || mb_strlen($word) > 30) {
            return AgentResult::reply("Le mot doit faire entre 2 et 30 caracteres.");
        }

        if (!preg_match('/^[A-Z\x{00C0}-\x{017F}\s\'-]+$/u', $word)) {
            return AgentResult::reply("Le mot ne doit contenir que des lettres, espaces, tirets ou apostrophes.");
        }

        // End existing game if any
        $this->forceEndActiveGame($context);

        return $this->startGame($context, $word);
    }

    private function guessLetter(AgentContext $context, string $letter): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours ! Envoie /hangman start pour commencer.");
        }

        $letter = mb_strtoupper($letter);
        $guessed = $game->guessed_letters ?? [];

        // Already guessed?
        if (in_array($letter, $guessed)) {
            $board = $this->getDisplayBoard($game);
            return AgentResult::reply("Tu as deja propose la lettre *{$letter}* !\n\n{$board}");
        }

        // Add letter
        $guessed[] = $letter;
        $game->guessed_letters = $guessed;

        // Check if letter is in word
        $wordUpper = mb_strtoupper($game->word);
        $found = mb_strpos($wordUpper, $letter) !== false;

        if (!$found) {
            $game->wrong_count++;
        }

        // Update stats
        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $stats->total_guesses++;

        // Check game end
        if ($game->isLost()) {
            $game->status = 'lost';
            $game->save();
            $this->updateStatsOnEnd($stats, false);

            $board = $this->getDisplayBoard($game);
            return AgentResult::reply("💀 *Perdu !*\n\n{$board}\n\nLe mot etait : *{$game->word}*\n\n/hangman start pour rejouer !");
        }

        if ($game->isWon()) {
            $game->status = 'won';
            $game->save();
            $this->updateStatsOnEnd($stats, true);

            $board = $this->getDisplayBoard($game);
            $wrongCount = $game->wrong_count;
            $maxWrong = self::MAX_WRONG;
            return AgentResult::reply("🎉 *Bravo, tu as gagne !*\n\n{$board}\n\nMot : *{$game->word}*\nErreurs : {$wrongCount}/{$maxWrong}\n\n/hangman start pour rejouer !");
        }

        $game->save();
        $stats->save();

        $board = $this->getDisplayBoard($game);
        $emoji = $found ? '✅' : '❌';
        $msg = $found ? "Bien joue ! *{$letter}* est dans le mot !" : "Dommage, *{$letter}* n'est pas dans le mot.";

        return AgentResult::reply("{$emoji} {$msg}\n\n{$board}");
    }

    private function showStats(AgentContext $context): AgentResult
    {
        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);

        $winRate = $stats->getWinRate();
        $bar = $this->generateProgressBar($winRate);

        $response = "📊 *Tes stats Pendu :*\n\n"
            . "🎮 Parties jouees : *{$stats->games_played}*\n"
            . "🏆 Victoires : *{$stats->games_won}*\n"
            . "💀 Defaites : *" . ($stats->games_played - $stats->games_won) . "*\n"
            . "📈 Taux de victoire : *{$winRate}%* {$bar}\n"
            . "🔥 Meilleure serie : *{$stats->best_streak}*\n"
            . "⚡ Serie actuelle : *{$stats->current_streak}*\n"
            . "🔤 Total lettres proposees : *{$stats->total_guesses}*";

        if ($stats->last_played_at) {
            $lastPlayed = $stats->last_played_at->diffForHumans();
            $response .= "\n⏰ Derniere partie : {$lastPlayed}";
        }

        return AgentResult::reply($response);
    }

    private function getDisplayBoard(HangmanGame $game): string
    {
        $stages = [
            "```\n  ┌───┐\n  │   │\n  │\n  │\n  │\n══╧══\n```",
            "```\n  ┌───┐\n  │   │\n  │   😵\n  │\n  │\n══╧══\n```",
            "```\n  ┌───┐\n  │   │\n  │   😵\n  │   │\n  │\n══╧══\n```",
            "```\n  ┌───┐\n  │   │\n  │   😵\n  │  /│\n  │\n══╧══\n```",
            "```\n  ┌───┐\n  │   │\n  │   😵\n  │  /│\\\n  │\n══╧══\n```",
            "```\n  ┌───┐\n  │   │\n  │   😵\n  │  /│\\\n  │  /\n══╧══\n```",
            "```\n  ┌───┐\n  │   │\n  │   😵\n  │  /│\\\n  │  / \\\n══╧══\n```",
        ];

        $stage = min($game->wrong_count, self::MAX_WRONG);
        $hangman = $stages[$stage];

        $masked = $this->formatMaskedWord($game);
        $guessed = $game->guessed_letters ?? [];
        $wrongLetters = $this->getWrongLetters($game);

        $result = $hangman . "\n\n";
        $result .= "📝 " . $masked . "\n\n";
        $maxWrong = self::MAX_WRONG;
        $result .= "❌ Erreurs : {$game->wrong_count}/{$maxWrong}";

        if (!empty($wrongLetters)) {
            $result .= " (" . implode(', ', $wrongLetters) . ")";
        }

        if (!empty($guessed)) {
            $result .= "\n🔤 Lettres essayees : " . implode(', ', $guessed);
        }

        return $result;
    }

    private function formatMaskedWord(HangmanGame $game): string
    {
        $guessed = $game->guessed_letters ?? [];
        $word = mb_strtoupper($game->word);
        $display = '';

        for ($i = 0; $i < mb_strlen($word); $i++) {
            $char = mb_substr($word, $i, 1);
            if ($char === ' ') {
                $display .= '   ';
            } elseif ($char === '-' || $char === "'") {
                $display .= $char . ' ';
            } elseif (in_array($char, array_map('mb_strtoupper', $guessed))) {
                $display .= $char . ' ';
            } else {
                $display .= '_ ';
            }
        }

        return trim($display);
    }

    private function getWrongLetters(HangmanGame $game): array
    {
        $guessed = $game->guessed_letters ?? [];
        $wordUpper = mb_strtoupper($game->word);
        $wrong = [];

        foreach ($guessed as $letter) {
            if (mb_strpos($wordUpper, mb_strtoupper($letter)) === false) {
                $wrong[] = $letter;
            }
        }

        return $wrong;
    }

    private function getActiveGame(AgentContext $context): ?HangmanGame
    {
        return HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->latest()
            ->first();
    }

    private function forceEndActiveGame(AgentContext $context): void
    {
        HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'lost']);
    }

    private function updateStatsOnEnd(HangmanStats $stats, bool $won): void
    {
        $stats->games_played++;
        $stats->last_played_at = Carbon::now();

        if ($won) {
            $stats->games_won++;
            $stats->current_streak++;
            if ($stats->current_streak > $stats->best_streak) {
                $stats->best_streak = $stats->current_streak;
            }
        } else {
            $stats->current_streak = 0;
        }

        $stats->save();
    }

    private function generateProgressBar(float $percentage): string
    {
        $filled = (int) round($percentage / 10);
        $empty = 10 - $filled;
        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }

    private function handleNaturalLanguage(string $body, AgentContext $context): AgentResult
    {
        $activeGame = $this->getActiveGame($context);
        $gameContext = '';

        if ($activeGame) {
            $masked = $activeGame->getMaskedWord();
            $gameContext = "\nPartie en cours: mot={$masked}, erreurs={$activeGame->wrong_count}/6, lettres essayees=" . implode(',', $activeGame->guessed_letters ?? []);
        }

        $model = $this->resolveModel($context);
        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"{$gameContext}",
            $model,
            "Tu es l'agent du jeu du Pendu. Comprends l'intention de l'utilisateur et reponds en JSON:\n"
            . "{\"action\": \"start|guess|stats|help\", \"letter\": \"X\"}\n"
            . "- start = nouvelle partie\n"
            . "- guess = deviner une lettre (inclure \"letter\")\n"
            . "- stats = voir statistiques\n"
            . "- help = aide\n"
            . "Reponds UNIQUEMENT avec le JSON."
        );

        $parsed = json_decode(trim($response ?? ''), true);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp($activeGame);
        }

        return match ($parsed['action']) {
            'start' => $this->startGame($context),
            'guess' => isset($parsed['letter']) ? $this->guessLetter($context, mb_strtoupper($parsed['letter'])) : AgentResult::reply("Quelle lettre veux-tu proposer ?"),
            'stats' => $this->showStats($context),
            default => $this->showHelp($activeGame),
        };
    }

    private function showHelp(?HangmanGame $activeGame): AgentResult
    {
        $help = "🎮 *Jeu du Pendu - Commandes :*\n\n"
            . "▶️ /hangman start → Nouvelle partie\n"
            . "🔤 /hangman guess X → Proposer la lettre X\n"
            . "✏️ /hangman word MOT → Partie avec mot personnalise\n"
            . "📊 /hangman stats → Tes statistiques\n\n"
            . "💡 Tu peux aussi envoyer juste une lettre pendant une partie !";

        if ($activeGame) {
            $board = $this->getDisplayBoard($activeGame);
            $help .= "\n\n--- Partie en cours ---\n\n{$board}";
        }

        return AgentResult::reply($help);
    }
}
