<?php

namespace App\Services\Agents;

use App\Models\HangmanGame;
use App\Models\HangmanStats;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;

class HangmanGameAgent extends BaseAgent
{
    private const MAX_WRONG = 6;

    private const WORD_CATEGORIES = [
        'tech' => [
            'LARAVEL', 'SYMFONY', 'PYTHON', 'JAVASCRIPT', 'ALGORITHME',
            'DATABASE', 'SERVEUR', 'TERMINAL', 'INTERNET', 'NAVIGATEUR',
            'CLAVIER', 'ECRAN', 'SOURIS', 'LOGICIEL', 'PROGRAMME',
            'FONCTION', 'VARIABLE', 'TABLEAU', 'BOUCLE', 'CONDITION',
            'RESEAU', 'SECURITE', 'CRYPTAGE', 'MEMOIRE', 'DISQUE',
            'FENETRE', 'DOSSIER', 'FICHIER', 'CONSOLE', 'INTERFACE',
            'MODULE', 'PAQUET', 'CLASSE', 'METHODE', 'REQUETE',
            'REPONSE', 'ERREUR', 'DEBUGGER', 'COMPILATEUR', 'FRAMEWORK',
            'DEPLOIEMENT', 'CONTENEUR', 'MICROSERVICE', 'API', 'PIPELINE',
        ],
        'animaux' => [
            'ELEPHANT', 'GIRAFE', 'PAPILLON', 'DAUPHIN', 'PANTHERE',
            'RENARD', 'HIBOU', 'CROCODILE', 'PERROQUET', 'KANGOUROU',
            'MANCHOT', 'CHAMELEON', 'SCORPION', 'MEDUSE', 'PIEUVRE',
        ],
        'nature' => [
            'MONTAGNE', 'VOLCAN', 'GLACIER', 'TROPICAL', 'TEMPETE',
            'TORNADE', 'TSUNAMI', 'AVALANCHE', 'SAVANE', 'MANGROVE',
        ],
        'vocab' => [
            'CHOCOLAT', 'VOITURE', 'AVION', 'BATEAU', 'MAISON',
            'JARDIN', 'AVENTURE', 'GALAXIE', 'BIBLIOTHEQUE', 'UNIVERSITE',
            'SYMPHONIE', 'TELESCOPE', 'PARACHUTE', 'LABORATOIRE', 'PHILOSOPHIE',
        ],
    ];

    private const CATEGORY_LABELS = [
        'tech'    => 'Informatique 💻',
        'animaux' => 'Animaux 🦁',
        'nature'  => 'Nature 🌿',
        'vocab'   => 'Vocabulaire 📚',
    ];

    public function name(): string
    {
        return 'hangman';
    }

    public function description(): string
    {
        return 'Agent jeu du Pendu (Hangman). Permet de jouer au pendu avec des mots aleatoires par categorie ou personnalises, obtenir des indices, abandonner une partie, suivre ses statistiques de victoires/defaites, streaks et taux de reussite.';
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
            'indice pendu', 'hint hangman', 'abandonner pendu',
            'pendu status', 'voir pendu',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
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

        // Abandon / forfeit
        if (preg_match('/\/hangman\s+abandon/i', $lower) || preg_match('/\b(abandon(ner)?|forfait|quitter)\s*(la\s+)?(partie|pendu|game)?\b/iu', $lower)) {
            return $this->abandon($context);
        }

        // Hint
        if (preg_match('/\/hangman\s+hint/i', $lower) || preg_match('/\b(indice|hint|aide[\s-]moi|aide\s+lettre)\b/iu', $lower)) {
            return $this->hint($context);
        }

        // Status / show board
        if (preg_match('/\/hangman\s+status/i', $lower) || preg_match('/\b(status|etat|voir\s+(partie|pendu|board|jeu))\b/iu', $lower)) {
            return $this->status($context);
        }

        // Start / new game
        if (preg_match('/\/hangman\s+start/i', $lower) || preg_match('/\b(nouvelle?\s+partie|new\s+game|start.*pendu|jouer.*pendu|pendu\s+start|recommencer)\b/iu', $lower)) {
            return $this->startGame($context);
        }

        // Custom word
        if (preg_match('/\/hangman\s+word\s+(.+)/i', $body, $m)) {
            return $this->startGameWithWord($context, trim($m[1]));
        }

        // Stats
        if (preg_match('/\/hangman\s+stats/i', $lower) || preg_match('/\b(stats?|statistiques?)\s*(hangman|pendu)\b/iu', $lower) || preg_match('/\b(hangman|pendu)\s*(stats?|statistiques?)\b/iu', $lower)) {
            return $this->showStats($context);
        }

        // Explicit guess command
        if (preg_match('/\/hangman\s+guess\s+([a-zA-Z\x{00C0}-\x{017F}])/iu', $body, $m)) {
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

    // ── Core game actions ─────────────────────────────────────────────────────

    private function startGame(AgentContext $context, ?string $customWord = null, ?string $forcedCategory = null): AgentResult
    {
        // Abandon existing game if any
        $abandonMsg = '';
        $existing = $this->getActiveGame($context);
        if ($existing) {
            $this->abandonActiveGame($context, $existing);
            $abandonMsg = "⚠️ Ancienne partie abandonnee (mot : *{$existing->word}*)\n\n";
        }

        // Select word
        if ($customWord !== null) {
            $word = mb_strtoupper($customWord);
            $category = null;
        } else {
            [$word, $category] = $this->getRandomWordAndCategory($forcedCategory);
        }

        $game = HangmanGame::create([
            'user_phone'      => $context->from,
            'agent_id'        => $context->agent->id,
            'word'            => $word,
            'guessed_letters' => [],
            'wrong_count'     => 0,
            'status'          => 'playing',
        ]);

        $board = $this->getDisplayBoard($game);
        $catLabel = $category ? ' | ' . self::CATEGORY_LABELS[$category] : '';

        $this->log($context, 'New hangman game started', ['game_id' => $game->id, 'word_length' => mb_strlen($word), 'category' => $category]);

        return AgentResult::reply("{$abandonMsg}🎮 *Nouvelle partie de Pendu !*{$catLabel}\n\n{$board}\n\nEnvoie une lettre pour deviner !\n💡 Besoin d'aide ? /hangman hint (coute 1 vie)");
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

    private function hint(AgentContext $context): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours ! Envoie /hangman start pour commencer.");
        }

        // Find letters not yet guessed
        $word = mb_strtoupper($game->word);
        $guessed = array_map('mb_strtoupper', $game->guessed_letters ?? []);
        $unguessed = [];

        for ($i = 0; $i < mb_strlen($word); $i++) {
            $char = mb_substr($word, $i, 1);
            if (preg_match('/\pL/u', $char) && !in_array($char, $guessed) && !in_array($char, $unguessed)) {
                $unguessed[] = $char;
            }
        }

        if (empty($unguessed)) {
            return AgentResult::reply("Toutes les lettres ont deja ete revelees !");
        }

        // Pick a random letter and reveal it (costs 1 error)
        $hintLetter = $unguessed[array_rand($unguessed)];

        $game->wrong_count++;
        $newGuessed = $guessed;
        $newGuessed[] = $hintLetter;
        $game->guessed_letters = array_values(array_unique($newGuessed));

        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $stats->total_guesses++;

        // Check if hint caused a loss
        if ($game->isLost()) {
            $game->status = 'lost';
            $game->save();
            $this->updateStatsOnEnd($stats, false);

            $board = $this->getDisplayBoard($game);
            return AgentResult::reply("💀 *L'indice t'a coute la partie !*\n\nLettre revelee : *{$hintLetter}*\n\n{$board}\n\nLe mot etait : *{$game->word}*\n\n/hangman start pour rejouer !");
        }

        // Check if hint caused a win (all letters guessed)
        if ($game->isWon()) {
            $game->status = 'won';
            $game->save();
            $this->updateStatsOnEnd($stats, true);

            $board = $this->getDisplayBoard($game);
            return AgentResult::reply("🎉 *Victoire avec indice !*\n\n{$board}\n\nMot : *{$game->word}*\n\n/hangman start pour rejouer !");
        }

        $game->save();
        $stats->save();

        $board = $this->getDisplayBoard($game);
        return AgentResult::reply("💡 *Indice :* La lettre *{$hintLetter}* est dans le mot ! (-1 vie)\n\n{$board}");
    }

    private function abandon(AgentContext $context): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours. Envoie /hangman start pour commencer !");
        }

        $word = $game->word;
        $this->abandonActiveGame($context, $game);

        $this->log($context, 'Game abandoned by user', ['game_id' => $game->id]);

        return AgentResult::reply("🏳️ *Partie abandonnee.*\n\nLe mot etait : *{$word}*\n\n/hangman start pour une nouvelle partie !");
    }

    private function status(AgentContext $context): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours. Envoie /hangman start pour commencer !");
        }

        $board = $this->getDisplayBoard($game);
        $remainingLives = self::MAX_WRONG - $game->wrong_count;
        return AgentResult::reply("🎮 *Partie en cours* | {$remainingLives} vie(s) restante(s)\n\n{$board}");
    }

    private function showStats(AgentContext $context): AgentResult
    {
        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);

        $winRate = $stats->getWinRate();
        $bar = $this->generateProgressBar($winRate);
        $losses = $stats->games_played - $stats->games_won;

        $response = "📊 *Tes stats Pendu :*\n\n"
            . "🎮 Parties jouees : *{$stats->games_played}*\n"
            . "🏆 Victoires : *{$stats->games_won}*\n"
            . "💀 Defaites : *{$losses}*\n"
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

    // ── Display helpers ───────────────────────────────────────────────────────

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

        $stage   = min($game->wrong_count, self::MAX_WRONG);
        $hangman = $stages[$stage];

        $masked      = $this->formatMaskedWord($game);
        $guessed     = $game->guessed_letters ?? [];
        $wrongLetters = $this->getWrongLetters($game);

        $result  = $hangman . "\n\n";
        $result .= "📝 " . $masked . "\n\n";
        $maxWrong = self::MAX_WRONG;
        $result .= "❌ Erreurs : {$game->wrong_count}/{$maxWrong}";

        if (!empty($wrongLetters)) {
            $result .= " (" . implode(', ', $wrongLetters) . ")";
        }

        if (!empty($guessed)) {
            sort($guessed);
            $result .= "\n🔤 Lettres essayees : " . implode(', ', $guessed);
        }

        return $result;
    }

    private function formatMaskedWord(HangmanGame $game): string
    {
        $guessed = array_map('mb_strtoupper', $game->guessed_letters ?? []);
        $word    = mb_strtoupper($game->word);
        $display = '';

        for ($i = 0; $i < mb_strlen($word); $i++) {
            $char = mb_substr($word, $i, 1);
            if ($char === ' ') {
                $display .= '   ';
            } elseif ($char === '-' || $char === "'") {
                $display .= $char . ' ';
            } elseif (in_array($char, $guessed)) {
                $display .= $char . ' ';
            } else {
                $display .= '_ ';
            }
        }

        return trim($display);
    }

    private function getWrongLetters(HangmanGame $game): array
    {
        $guessed  = $game->guessed_letters ?? [];
        $wordUpper = mb_strtoupper($game->word);
        $wrong    = [];

        foreach ($guessed as $letter) {
            if (mb_strpos($wordUpper, mb_strtoupper($letter)) === false) {
                $wrong[] = mb_strtoupper($letter);
            }
        }

        return $wrong;
    }

    private function generateProgressBar(float $percentage): string
    {
        $filled = (int) round($percentage / 10);
        $empty  = 10 - $filled;
        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function getRandomWordAndCategory(?string $category = null): array
    {
        $categories = array_keys(self::WORD_CATEGORIES);

        if ($category && isset(self::WORD_CATEGORIES[$category])) {
            $cat = $category;
        } else {
            $cat = $categories[array_rand($categories)];
        }

        $words = self::WORD_CATEGORIES[$cat];
        $word  = $words[array_rand($words)];

        return [$word, $cat];
    }

    private function getActiveGame(AgentContext $context): ?HangmanGame
    {
        return HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->latest()
            ->first();
    }

    private function abandonActiveGame(AgentContext $context, HangmanGame $game): void
    {
        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $this->updateStatsOnEnd($stats, false);
        $game->update(['status' => 'lost']);
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

    // ── Natural language / help ───────────────────────────────────────────────

    private function handleNaturalLanguage(string $body, AgentContext $context): AgentResult
    {
        $activeGame  = $this->getActiveGame($context);
        $gameContext = '';

        if ($activeGame) {
            $masked      = $activeGame->getMaskedWord();
            $gameContext = "\nPartie en cours: mot={$masked}, erreurs={$activeGame->wrong_count}/6, lettres essayees=" . implode(',', $activeGame->guessed_letters ?? []);
        }

        $model    = $this->resolveModel($context);
        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"{$gameContext}",
            $model,
            "Tu es l'agent du jeu du Pendu. Comprends l'intention de l'utilisateur et reponds en JSON:\n"
            . "{\"action\": \"start|guess|stats|hint|abandon|status|help\", \"letter\": \"X\"}\n"
            . "Actions disponibles:\n"
            . "- start   = nouvelle partie (ou recommencer)\n"
            . "- guess   = deviner une lettre (inclure \"letter\": \"X\")\n"
            . "- stats   = voir statistiques\n"
            . "- hint    = demander un indice\n"
            . "- abandon = abandonner/quitter la partie en cours\n"
            . "- status  = voir l'etat de la partie en cours\n"
            . "- help    = afficher l'aide\n"
            . "Exemples: \"donne moi un indice\" -> hint, \"je veux arreter\" -> abandon, \"quelle est la situation\" -> status\n"
            . "Reponds UNIQUEMENT avec le JSON."
        );

        $parsed = json_decode(trim($response ?? ''), true);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp($activeGame);
        }

        return match ($parsed['action']) {
            'start'  => $this->startGame($context),
            'guess'  => isset($parsed['letter'])
                ? $this->guessLetter($context, mb_strtoupper($parsed['letter']))
                : AgentResult::reply("Quelle lettre veux-tu proposer ?"),
            'stats'  => $this->showStats($context),
            'hint'   => $this->hint($context),
            'abandon'=> $this->abandon($context),
            'status' => $this->status($context),
            default  => $this->showHelp($activeGame),
        };
    }

    private function showHelp(?HangmanGame $activeGame = null): AgentResult
    {
        $help = "🎮 *Jeu du Pendu - Commandes :*\n\n"
            . "▶️ /hangman start → Nouvelle partie\n"
            . "🔤 /hangman guess X → Proposer la lettre X\n"
            . "💡 /hangman hint → Indice (revele une lettre, -1 vie)\n"
            . "✏️ /hangman word MOT → Partie avec mot personnalise\n"
            . "📋 /hangman status → Voir la partie en cours\n"
            . "🏳️ /hangman abandon → Abandonner la partie\n"
            . "📊 /hangman stats → Tes statistiques\n\n"
            . "💡 Tu peux aussi envoyer juste une lettre pendant une partie !";

        if ($activeGame) {
            $board = $this->getDisplayBoard($activeGame);
            $help .= "\n\n--- Partie en cours ---\n\n{$board}";
        }

        return AgentResult::reply($help);
    }
}
