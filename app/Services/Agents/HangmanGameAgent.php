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
            'TYPESCRIPT', 'KUBERNETES', 'DOCKER', 'WEBHOOK', 'MIDDLEWARE',
            'CACHE', 'SOCKET', 'PROTOCOLE', 'ENCODAGE', 'BINAIRE',
        ],
        'animaux' => [
            'ELEPHANT', 'GIRAFE', 'PAPILLON', 'DAUPHIN', 'PANTHERE',
            'RENARD', 'HIBOU', 'CROCODILE', 'PERROQUET', 'KANGOUROU',
            'MANCHOT', 'CHAMELEON', 'SCORPION', 'MEDUSE', 'PIEUVRE',
            'FLAMANT', 'GUEPARD', 'RHINOCEROS', 'HIPPOPOTAME', 'ORQUE',
            'AIGLE', 'COBRA', 'GORILLE', 'JAGUAR', 'BELETTE',
        ],
        'nature' => [
            'MONTAGNE', 'VOLCAN', 'GLACIER', 'TROPICAL', 'TEMPETE',
            'TORNADE', 'TSUNAMI', 'AVALANCHE', 'SAVANE', 'MANGROVE',
            'FALAISE', 'CANYON', 'ARCHIPEL', 'PLATEAU', 'FORET',
            'RIVIERE', 'PRAIRIE', 'MARAIS', 'TOUNDRA', 'DESERT',
        ],
        'vocab' => [
            'CHOCOLAT', 'VOITURE', 'AVION', 'BATEAU', 'MAISON',
            'JARDIN', 'AVENTURE', 'GALAXIE', 'BIBLIOTHEQUE', 'UNIVERSITE',
            'SYMPHONIE', 'TELESCOPE', 'PARACHUTE', 'LABORATOIRE', 'PHILOSOPHIE',
            'ARCHITECTURE', 'MEDITATION', 'CINEMATHEQUE', 'REVOLUTION', 'MYSTERE',
            'KALEIDOSCOPE', 'EXPEDITION', 'HARMONIE', 'PAYSAGE', 'MERVEILLE',
        ],
        'sport' => [
            'FOOTBALL', 'TENNIS', 'BASKETBALL', 'NATATION', 'CYCLISME',
            'ATHLETISME', 'VOLLEYBALL', 'HANDBALL', 'RUGBY', 'BOXE',
            'JUDO', 'ESCALADE', 'TRIATHLON', 'MARATHON', 'PLONGEON',
            'PATINAGE', 'EQUITATION', 'AVIRON', 'ESCRIME', 'BADMINTON',
            'KARATE', 'LUTTE', 'TIRO', 'SURF', 'PLANCHE',
        ],
        'gastronomie' => [
            'BAGUETTE', 'CROISSANT', 'RATATOUILLE', 'BOUILLABAISSE', 'CASSOULET',
            'CREPE', 'ECLAIR', 'MACARON', 'GATEAU', 'FONDUE',
            'RACLETTE', 'SOUFFLE', 'BRIOCHE', 'PROFITEROLE', 'MADELEINE',
            'MILLEFEUILLE', 'GALETTE', 'QUICHE', 'CAMEMBERT', 'TARTIFLETTE',
            'COUSCOUS', 'TAPENADE', 'FLAMICHE', 'ANDOUILLE', 'CLAFOUTIS',
        ],
    ];

    private const CATEGORY_LABELS = [
        'tech'        => 'Informatique 💻',
        'animaux'     => 'Animaux 🦁',
        'nature'      => 'Nature 🌿',
        'vocab'       => 'Vocabulaire 📚',
        'sport'       => 'Sport 🏆',
        'gastronomie' => 'Gastronomie 🍽️',
    ];

    private const CATEGORY_ALIASES = [
        'informatique' => 'tech',
        'info'         => 'tech',
        'dev'          => 'tech',
        'animal'       => 'animaux',
        'faune'        => 'animaux',
        'flore'        => 'nature',
        'mot'          => 'vocab',
        'mots'         => 'vocab',
        'sports'       => 'sport',
        'foot'         => 'sport',
        'cuisine'      => 'gastronomie',
        'food'         => 'gastronomie',
        'gastro'       => 'gastronomie',
    ];

    public function name(): string
    {
        return 'hangman';
    }

    public function description(): string
    {
        return 'Agent jeu du Pendu (Hangman). Permet de jouer au pendu avec des mots aleatoires par categorie (tech, animaux, nature, vocab, sport, gastronomie) ou personnalises, deviner le mot entier, obtenir des indices, abandonner une partie, consulter son historique, suivre ses statistiques avec meilleur score, voir les categories disponibles, reinitialiser ses stats, acceder au defi du jour (daily) et voir le classement des meilleurs joueurs (top).';
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
            'historique pendu', 'hangman history',
            'reset pendu', 'reinitialiser pendu',
            'categories pendu', 'hangman categories', 'liste categories',
            'devine le mot', 'deviner le mot entier', 'mot entier pendu',
            'defi du jour', 'pendu daily', 'hangman daily', 'mot du jour',
            'classement pendu', 'top pendu', 'hangman top', 'meilleurs joueurs pendu',
        ];
    }

    public function version(): string
    {
        return '1.4.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match('/\bhangman\b|\bpendu\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body  = trim($context->body ?? '');
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

        // History
        if (preg_match('/\/hangman\s+history/i', $lower) || preg_match('/\b(histori(?:que)?|mes\s+parties|dernieres?\s+parties)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+histori(?:que)?\b/iu', $lower)) {
            return $this->showHistory($context);
        }

        // Reset stats
        if (preg_match('/\/hangman\s+reset/i', $lower) || preg_match('/\b(reset|reinitialiser?|remettre?\s+a\s+zero)\s*(stats?|statistiques?)?\s*(pendu|hangman)?\b/iu', $lower)) {
            return $this->resetStats($context);
        }

        // Categories listing
        if (preg_match('/\/hangman\s+categori(?:es?)?/i', $lower) || preg_match('/\b(categories?|liste?\s+categories?)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+categories?\b/iu', $lower)) {
            return $this->showCategories();
        }

        // Leaderboard / top players
        if (preg_match('/\/hangman\s+top/i', $lower) || preg_match('/\b(classement|top|leaderboard|meilleurs?\s+joueurs?)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+(top|classement)\b/iu', $lower)) {
            return $this->showLeaderboard($context);
        }

        // Daily challenge
        if (preg_match('/\/hangman\s+daily/i', $lower) || preg_match('/\b(daily|defi\s+du\s+jour|mot\s+du\s+jour|pendu\s+daily)\b/iu', $lower)) {
            return $this->dailyChallenge($context);
        }

        // Start / new game — optionally with a category: /hangman start tech
        if (preg_match('/\/hangman\s+start(?:\s+(\w+))?/i', $body, $m)) {
            $forcedCategory = !empty($m[1]) ? $this->resolveCategory(mb_strtolower($m[1])) : null;
            return $this->startGame($context, null, $forcedCategory);
        }

        if (preg_match('/\b(nouvelle?\s+partie|new\s+game|start.*pendu|jouer.*pendu|pendu\s+start|recommencer)\b/iu', $lower)) {
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

        // Word guess command (explicit: /hangman devine MOT)
        if (preg_match('/\/hangman\s+devine\s+(.+)/i', $body, $m)) {
            return $this->guessWord($context, trim($m[1]));
        }

        // Explicit letter guess command
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

        // Multi-letter word guess (when game is active, 2–30 letters, no spaces)
        if (preg_match('/^([a-zA-Z\x{00C0}-\x{017F}]{2,30})$/u', trim($body), $m)) {
            $activeGame = $this->getActiveGame($context);
            if ($activeGame) {
                return $this->guessWord($context, $m[1]);
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
        $existing   = $this->getActiveGame($context);
        if ($existing) {
            $this->abandonActiveGame($context, $existing);
            $abandonMsg = "⚠️ Ancienne partie abandonnee (mot : *{$existing->word}*)\n\n";
        }

        // Select word
        if ($customWord !== null) {
            $word     = mb_strtoupper($customWord);
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

        $board    = $this->getDisplayBoard($game);
        $catLabel = $category ? ' | ' . self::CATEGORY_LABELS[$category] : '';
        $wordLen  = mb_strlen($word);

        $this->log($context, 'New hangman game started', ['game_id' => $game->id, 'word_length' => $wordLen, 'category' => $category]);

        return AgentResult::reply(
            "{$abandonMsg}🎮 *Nouvelle partie de Pendu !*{$catLabel}\n"
            . "📏 Mot de *{$wordLen}* lettre(s)\n\n"
            . "{$board}\n\n"
            . "Envoie une lettre pour deviner !\n"
            . "💡 /hangman hint (indice, -1 vie) | /hangman devine MOT (mot entier, -2 vies si faux)\n"
            . "🗂️ Categorie : /hangman start [tech|animaux|nature|vocab|sport|gastronomie]"
        );
    }

    private function startGameWithWord(AgentContext $context, string $word): AgentResult
    {
        $word = mb_strtoupper(trim($word));

        if (mb_strlen($word) < 2 || mb_strlen($word) > 30) {
            return AgentResult::reply("❌ Le mot doit faire entre 2 et 30 caracteres.");
        }

        if (!preg_match('/^[A-Z\x{00C0}-\x{017F}\s\'-]+$/u', $word)) {
            return AgentResult::reply("❌ Le mot ne doit contenir que des lettres, espaces, tirets ou apostrophes.");
        }

        return $this->startGame($context, $word);
    }

    private function guessLetter(AgentContext $context, string $letter): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours ! Envoie /hangman start pour commencer.");
        }

        $letter  = mb_strtoupper($letter);
        $guessed = $game->guessed_letters ?? [];

        // Already guessed?
        if (in_array($letter, $guessed)) {
            $board = $this->getDisplayBoard($game);
            return AgentResult::reply("⚠️ Tu as deja propose la lettre *{$letter}* !\n\n{$board}");
        }

        // Add letter
        $guessed[]             = $letter;
        $game->guessed_letters = $guessed;

        // Check if letter is in word
        $wordUpper = mb_strtoupper($game->word);
        $found     = mb_strpos($wordUpper, $letter) !== false;

        if (!$found) {
            $game->wrong_count++;
        }

        // Count occurrences for richer feedback
        $occurrences = 0;
        if ($found) {
            for ($i = 0; $i < mb_strlen($wordUpper); $i++) {
                if (mb_substr($wordUpper, $i, 1) === $letter) {
                    $occurrences++;
                }
            }
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
            return AgentResult::reply("💀 *Perdu !*\n\n{$board}\n\nLe mot etait : *{$game->word}*\n\n🔄 /hangman start pour rejouer !");
        }

        if ($game->isWon()) {
            $game->status = 'won';
            $game->save();
            $this->updateStatsOnEnd($stats, true);

            $board      = $this->getDisplayBoard($game);
            $wrongCount = $game->wrong_count;
            $maxWrong   = self::MAX_WRONG;
            $score      = $this->computeScore($game);
            $speedMsg   = $this->getSpeedMessage($game);
            return AgentResult::reply("🎉 *Bravo, tu as gagne !*{$speedMsg}\n\n{$board}\n\nMot : *{$game->word}*\nErreurs : {$wrongCount}/{$maxWrong}\n🏅 Score : *{$score} pts*\n\n🔄 /hangman start pour rejouer !");
        }

        $game->save();
        $stats->save();

        $board     = $this->getDisplayBoard($game);
        $emoji     = $found ? '✅' : '❌';
        $occMsg    = $found && $occurrences > 1 ? " ({$occurrences}x dans le mot)" : '';
        $msg       = $found
            ? "Bien joue ! *{$letter}* est dans le mot{$occMsg} !"
            : "Dommage, *{$letter}* n'est pas dans le mot.";
        $livesLeft = self::MAX_WRONG - $game->wrong_count;
        $livesMsg  = $found ? '' : "\n⚠️ {$livesLeft} vie(s) restante(s)";

        return AgentResult::reply("{$emoji} {$msg}{$livesMsg}\n\n{$board}");
    }

    private function guessWord(AgentContext $context, string $word): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours ! Envoie /hangman start pour commencer.");
        }

        $guessUpper = mb_strtoupper(trim($word));
        $wordUpper  = mb_strtoupper($game->word);

        if (mb_strlen($guessUpper) < 2) {
            return AgentResult::reply("❌ Utilise /hangman guess pour proposer une lettre, ou /hangman devine MOT pour le mot entier.");
        }

        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $stats->total_guesses++;

        if ($guessUpper === $wordUpper) {
            // Reveal all unique letters of the word
            $letters = [];
            for ($i = 0; $i < mb_strlen($wordUpper); $i++) {
                $char = mb_substr($wordUpper, $i, 1);
                if (preg_match('/\pL/u', $char) && !in_array($char, $letters)) {
                    $letters[] = $char;
                }
            }
            $game->guessed_letters = array_values(array_unique(
                array_merge($game->guessed_letters ?? [], $letters)
            ));
            $game->status = 'won';
            $game->save();
            $this->updateStatsOnEnd($stats, true);

            $board     = $this->getDisplayBoard($game);
            $score     = $this->computeScore($game);
            $speedMsg  = $this->getSpeedMessage($game);
            return AgentResult::reply(
                "🎉 *Excellent ! Tu as trouve le mot entier !*{$speedMsg}\n\n"
                . "{$board}\n\n"
                . "Mot : *{$game->word}*\n"
                . "🏅 Score : *{$score} pts*\n\n"
                . "🔄 /hangman start pour rejouer !"
            );
        }

        // Wrong word guess costs 2 errors
        $game->wrong_count += 2;

        if ($game->isLost()) {
            $game->status = 'lost';
            $game->save();
            $this->updateStatsOnEnd($stats, false);

            $board = $this->getDisplayBoard($game);
            return AgentResult::reply(
                "💀 *Mauvaise reponse ! \"{$guessUpper}\" est faux. (-2 vies)*\n\n"
                . "{$board}\n\n"
                . "Le mot etait : *{$game->word}*\n\n"
                . "🔄 /hangman start pour rejouer !"
            );
        }

        $game->save();
        $stats->save();

        $board     = $this->getDisplayBoard($game);
        $livesLeft = self::MAX_WRONG - $game->wrong_count;

        return AgentResult::reply(
            "❌ *\"{$guessUpper}\" n'est pas le bon mot !* (-2 vies)\n\n"
            . "⚠️ {$livesLeft} vie(s) restante(s)\n\n"
            . "{$board}"
        );
    }

    private function hint(AgentContext $context): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours ! Envoie /hangman start pour commencer.");
        }

        if ($game->wrong_count >= self::MAX_WRONG - 1) {
            $board = $this->getDisplayBoard($game);
            return AgentResult::reply("⚠️ *Trop risque !* Il ne te reste qu'une vie. L'indice te ferait perdre !\n\n{$board}\n\nPropose directement une lettre.");
        }

        // Find letters not yet guessed
        $word      = mb_strtoupper($game->word);
        $guessed   = array_map('mb_strtoupper', $game->guessed_letters ?? []);
        $unguessed = [];

        for ($i = 0; $i < mb_strlen($word); $i++) {
            $char = mb_substr($word, $i, 1);
            if (preg_match('/\pL/u', $char) && !in_array($char, $guessed) && !in_array($char, $unguessed)) {
                $unguessed[] = $char;
            }
        }

        if (empty($unguessed)) {
            return AgentResult::reply("✅ Toutes les lettres ont deja ete revelees !");
        }

        // Pick a random letter and reveal it (costs 1 error)
        $hintLetter            = $unguessed[array_rand($unguessed)];
        $game->wrong_count++;
        $newGuessed            = $guessed;
        $newGuessed[]          = $hintLetter;
        $game->guessed_letters = array_values(array_unique($newGuessed));

        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $stats->total_guesses++;

        // Check if hint caused a loss
        if ($game->isLost()) {
            $game->status = 'lost';
            $game->save();
            $this->updateStatsOnEnd($stats, false);

            $board = $this->getDisplayBoard($game);
            return AgentResult::reply("💀 *L'indice t'a coute la partie !*\n\nLettre revelee : *{$hintLetter}*\n\n{$board}\n\nLe mot etait : *{$game->word}*\n\n🔄 /hangman start pour rejouer !");
        }

        // Check if hint caused a win (all letters guessed)
        if ($game->isWon()) {
            $game->status = 'won';
            $game->save();
            $this->updateStatsOnEnd($stats, true);

            $board = $this->getDisplayBoard($game);
            $score = $this->computeScore($game);
            return AgentResult::reply("🎉 *Victoire avec indice !*\n\n{$board}\n\nMot : *{$game->word}*\n🏅 Score : *{$score} pts*\n\n🔄 /hangman start pour rejouer !");
        }

        $game->save();
        $stats->save();

        $board     = $this->getDisplayBoard($game);
        $livesLeft = self::MAX_WRONG - $game->wrong_count;
        return AgentResult::reply("💡 *Indice :* La lettre *{$hintLetter}* est dans le mot ! (-1 vie, {$livesLeft} restante(s))\n\n{$board}");
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

        return AgentResult::reply("🏳️ *Partie abandonnee.*\n\nLe mot etait : *{$word}*\n\n🔄 /hangman start pour une nouvelle partie !");
    }

    private function status(AgentContext $context): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours. Envoie /hangman start pour commencer !");
        }

        $board          = $this->getDisplayBoard($game);
        $remainingLives = self::MAX_WRONG - $game->wrong_count;
        $guessCount     = count($game->guessed_letters ?? []);

        // Count remaining hidden letters
        $masked  = $game->getMaskedWord();
        $hidden  = substr_count($masked, '_');

        $hiddenMsg = $hidden > 0 ? " | *{$hidden}* lettre(s) a trouver" : '';

        // Show elapsed time
        $elapsedMsg = '';
        if ($game->created_at) {
            $elapsedMsg = ' | ⏱️ ' . $game->created_at->diffForHumans(null, true);
        }

        return AgentResult::reply(
            "🎮 *Partie en cours* | {$remainingLives} vie(s) restante(s) | {$guessCount} lettre(s) essayee(s){$hiddenMsg}{$elapsedMsg}\n\n"
            . "{$board}\n\n"
            . "💡 /hangman hint | /hangman devine MOT | /hangman abandon"
        );
    }

    private function showStats(AgentContext $context): AgentResult
    {
        $stats   = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $winRate = $stats->getWinRate();
        $bar     = $this->generateProgressBar($winRate);
        $losses  = $stats->games_played - $stats->games_won;

        $avgGuesses = $stats->games_played > 0
            ? round($stats->total_guesses / $stats->games_played, 1)
            : 0;

        $response = "📊 *Tes stats Pendu :*\n\n"
            . "🎮 Parties jouees : *{$stats->games_played}*\n"
            . "🏆 Victoires : *{$stats->games_won}*\n"
            . "💀 Defaites : *{$losses}*\n"
            . "📈 Taux de victoire : *{$winRate}%* {$bar}\n"
            . "🔥 Meilleure serie : *{$stats->best_streak}*\n"
            . "⚡ Serie actuelle : *{$stats->current_streak}*\n"
            . "🔤 Total lettres proposees : *{$stats->total_guesses}*\n"
            . "📉 Moy. lettres/partie : *{$avgGuesses}*";

        $bestScore = $this->getBestScore($context);
        if ($bestScore > 0) {
            $response .= "\n🏅 Meilleur score : *{$bestScore} pts*";
        }

        if ($stats->last_played_at) {
            $lastPlayed = $stats->last_played_at->diffForHumans();
            $response  .= "\n⏰ Derniere partie : {$lastPlayed}";
        }

        $response .= "\n\n_/hangman reset pour reinitialiser | /hangman top pour le classement_";

        return AgentResult::reply($response);
    }

    private function showHistory(AgentContext $context): AgentResult
    {
        $games = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        if ($games->isEmpty()) {
            return AgentResult::reply("📋 Aucune partie terminee pour l'instant.\n\nEnvoie /hangman start pour jouer !");
        }

        $lines = ["📋 *Historique de tes dernieres parties :*\n"];

        foreach ($games as $game) {
            $icon     = $game->status === 'won' ? '🏆' : '💀';
            $date     = $game->updated_at->format('d/m H:i');
            $errors   = $game->wrong_count;
            $letters  = count($game->guessed_letters ?? []);
            $scoreStr = $game->status === 'won'
                ? ' · 🏅 ' . $this->computeScore($game) . ' pts'
                : '';
            $lines[] = "{$icon} *{$game->word}* — {$errors}/" . self::MAX_WRONG . " erreurs, {$letters} lettres{$scoreStr} ({$date})";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function showCategories(): AgentResult
    {
        $lines = ["🗂️ *Categories disponibles :*\n"];

        foreach (self::CATEGORY_LABELS as $key => $label) {
            $count   = count(self::WORD_CATEGORIES[$key]);
            $lines[] = "• {$label} — {$count} mots → /hangman start {$key}";
        }

        $lines[] = "\n💡 Exemple : /hangman start sport";
        $lines[] = "🎲 Sans categorie : /hangman start (aleatoire)";
        $lines[] = "📅 Defi du jour : /hangman daily";

        return AgentResult::reply(implode("\n", $lines));
    }

    private function showLeaderboard(AgentContext $context): AgentResult
    {
        $topStats = HangmanStats::where('agent_id', $context->agent->id)
            ->where('games_played', '>', 0)
            ->orderByDesc('games_won')
            ->orderByDesc('best_streak')
            ->limit(5)
            ->get();

        if ($topStats->isEmpty()) {
            return AgentResult::reply("🏆 Aucun joueur au classement pour l'instant.\n\nSois le premier ! /hangman start");
        }

        $lines   = ["🏆 *Classement Pendu - Top joueurs :*\n"];
        $medals  = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
        $isMe    = false;

        foreach ($topStats as $i => $stat) {
            $medal  = $medals[$i] ?? ($i + 1) . '.';
            $isSelf = $stat->user_phone === $context->from;

            // Mask phone: keep last 4 digits of number part
            if (preg_match('/^(\d+?)(\d{4})(@.+)?$/', $stat->user_phone, $pm)) {
                $maskedPhone = '****' . $pm[2];
            } else {
                $maskedPhone = '****';
            }

            $winRate  = $stat->getWinRate();
            $selfMark = $isSelf ? ' ← Toi' : '';
            $lines[]  = "{$medal} {$maskedPhone} — *{$stat->games_won}* victoires | {$stat->best_streak}🔥 serie | {$winRate}%{$selfMark}";

            if ($isSelf) {
                $isMe = true;
            }
        }

        if (!$isMe) {
            $myStats = HangmanStats::where('agent_id', $context->agent->id)
                ->where('user_phone', $context->from)
                ->first();

            if ($myStats && $myStats->games_played > 0) {
                $lines[] = "\n_Tu n'es pas dans le top 5 — continue a jouer !_";
            }
        }

        $lines[] = "\n_/hangman stats pour tes propres stats_";

        return AgentResult::reply(implode("\n", $lines));
    }

    private function dailyChallenge(AgentContext $context): AgentResult
    {
        // Build a flat word pool across all categories
        $allWords = [];
        foreach (self::WORD_CATEGORIES as $cat => $words) {
            foreach ($words as $word) {
                $allWords[] = [$word, $cat];
            }
        }

        // Deterministic index based on the current date
        $dateStr = date('Y-m-d');
        $index   = abs(crc32($dateStr)) % count($allWords);
        [$word, $category] = $allWords[$index];

        $catLabel = self::CATEGORY_LABELS[$category];

        // Abandon existing game if any
        $abandonMsg = '';
        $existing   = $this->getActiveGame($context);
        if ($existing) {
            $this->abandonActiveGame($context, $existing);
            $abandonMsg = "⚠️ Ancienne partie abandonnee (mot : *{$existing->word}*)\n\n";
        }

        $game = HangmanGame::create([
            'user_phone'      => $context->from,
            'agent_id'        => $context->agent->id,
            'word'            => $word,
            'guessed_letters' => [],
            'wrong_count'     => 0,
            'status'          => 'playing',
        ]);

        $board        = $this->getDisplayBoard($game);
        $wordLen      = mb_strlen($word);
        $todayFormatted = Carbon::today()->format('d/m/Y');

        $this->log($context, 'Daily challenge started', ['game_id' => $game->id, 'word_length' => $wordLen, 'category' => $category]);

        return AgentResult::reply(
            "{$abandonMsg}📅 *Defi du Jour — {$todayFormatted}*\n"
            . "Categorie : {$catLabel}\n"
            . "📏 Mot de *{$wordLen}* lettre(s)\n\n"
            . "{$board}\n\n"
            . "Tout le monde a le meme mot aujourd'hui ! 🌍\n"
            . "💡 /hangman hint | /hangman devine MOT | /hangman top"
        );
    }

    private function resetStats(AgentContext $context): AgentResult
    {
        $stats = HangmanStats::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$stats || $stats->games_played === 0) {
            return AgentResult::reply("📊 Aucune statistique a reinitialiser.");
        }

        $stats->update([
            'games_played'   => 0,
            'games_won'      => 0,
            'best_streak'    => 0,
            'current_streak' => 0,
            'total_guesses'  => 0,
            'last_played_at' => null,
        ]);

        $this->log($context, 'Stats reset by user');

        return AgentResult::reply("🔄 *Tes statistiques ont ete remises a zero.*\n\nBonne chance pour la prochaine serie ! /hangman start");
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

        $masked       = $this->formatMaskedWord($game);
        $guessed      = $game->guessed_letters ?? [];
        $wrongLetters = $this->getWrongLetters($game);

        $result  = $hangman . "\n\n";
        $result .= "📝 " . $masked . "\n\n";
        $maxWrong = self::MAX_WRONG;
        $result .= "❌ Erreurs : {$game->wrong_count}/{$maxWrong}";

        if (!empty($wrongLetters)) {
            sort($wrongLetters);
            $result .= " (" . implode(', ', $wrongLetters) . ")";
        }

        if (!empty($guessed)) {
            $correctLetters = array_diff($guessed, $wrongLetters);
            sort($correctLetters);
            if (!empty($correctLetters)) {
                $result .= "\n✅ Bonnes lettres : " . implode(', ', $correctLetters);
            }
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
        $guessed   = $game->guessed_letters ?? [];
        $wordUpper = mb_strtoupper($game->word);
        $wrong     = [];

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

    private function computeScore(HangmanGame $game): int
    {
        $wordLen      = mb_strlen($game->word);
        $errors       = $game->wrong_count;
        $base         = $wordLen * 10;
        $errorPenalty = $errors * 5;
        $bonus        = max(0, (self::MAX_WRONG - $errors) * 3);

        // Speed bonus based on game duration
        $speedBonus = 0;
        if ($game->created_at && $game->updated_at) {
            $seconds = $game->created_at->diffInSeconds($game->updated_at);
            if ($seconds < 60) {
                $speedBonus = 20;
            } elseif ($seconds < 120) {
                $speedBonus = 10;
            } elseif ($seconds < 300) {
                $speedBonus = 5;
            }
        }

        return max(0, $base - $errorPenalty + $bonus + $speedBonus);
    }

    private function getSpeedMessage(HangmanGame $game): string
    {
        if (!$game->created_at || !$game->updated_at) {
            return '';
        }

        $seconds = $game->created_at->diffInSeconds($game->updated_at);

        if ($seconds < 60) {
            return ' ⚡ *Eclair !* (+20 bonus)';
        }

        if ($seconds < 120) {
            return ' 🚀 *Rapide !* (+10 bonus)';
        }

        if ($seconds < 300) {
            return ' ⏱️ *Bonne vitesse !* (+5 bonus)';
        }

        return '';
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function getBestScore(AgentContext $context): int
    {
        $wonGames = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'won')
            ->get(['word', 'wrong_count', 'created_at', 'updated_at']);

        if ($wonGames->isEmpty()) {
            return 0;
        }

        return $wonGames->map(fn ($g) => $this->computeScore($g))->max();
    }

    private function resolveCategory(string $input): ?string
    {
        if (isset(self::WORD_CATEGORIES[$input])) {
            return $input;
        }

        return self::CATEGORY_ALIASES[$input] ?? null;
    }

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
            $livesLeft   = self::MAX_WRONG - $activeGame->wrong_count;
            $gameContext = "\nPartie en cours: mot={$masked}, erreurs={$activeGame->wrong_count}/6, vies={$livesLeft}, lettres essayees=" . implode(',', $activeGame->guessed_letters ?? []);
        }

        $model    = $this->resolveModel($context);
        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"{$gameContext}",
            $model,
            "Tu es l'agent du jeu du Pendu ZeniClaw. Analyse l'intention de l'utilisateur et reponds en JSON strict:\n"
            . "{\"action\": \"start|guess|guess_word|stats|hint|abandon|status|history|reset|categories|daily|leaderboard|help\", \"letter\": \"X\", \"word\": \"MOT\", \"category\": \"tech|animaux|nature|vocab|sport|gastronomie\"}\n\n"
            . "Actions disponibles:\n"
            . "- start       = nouvelle partie (inclure \"category\" si demandee: tech, animaux, nature, vocab, sport, gastronomie)\n"
            . "- guess       = deviner une lettre (inclure \"letter\": \"X\")\n"
            . "- guess_word  = deviner le mot entier (inclure \"word\": \"MOT\")\n"
            . "- stats       = voir ses statistiques\n"
            . "- hint        = demander un indice (revele une lettre, coute -1 vie)\n"
            . "- abandon     = abandonner/quitter la partie en cours\n"
            . "- status      = voir l'etat actuel de la partie\n"
            . "- history     = voir l'historique des dernieres parties\n"
            . "- reset       = reinitialiser les statistiques\n"
            . "- categories  = lister les categories de mots disponibles\n"
            . "- daily       = lancer le defi du jour (meme mot pour tous)\n"
            . "- leaderboard = voir le classement des meilleurs joueurs\n"
            . "- help        = afficher l'aide et les commandes\n\n"
            . "Exemples:\n"
            . "  \"donne moi un indice\" -> {\"action\":\"hint\"}\n"
            . "  \"je veux arreter\" -> {\"action\":\"abandon\"}\n"
            . "  \"historique\" -> {\"action\":\"history\"}\n"
            . "  \"remet mes stats a zero\" -> {\"action\":\"reset\"}\n"
            . "  \"le mot est LARAVEL\" -> {\"action\":\"guess_word\",\"word\":\"LARAVEL\"}\n"
            . "  \"liste les categories\" -> {\"action\":\"categories\"}\n"
            . "  \"jouer sport\" -> {\"action\":\"start\",\"category\":\"sport\"}\n"
            . "  \"defi du jour\" -> {\"action\":\"daily\"}\n"
            . "  \"classement\" -> {\"action\":\"leaderboard\"}\n"
            . "  \"je propose la lettre E\" -> {\"action\":\"guess\",\"letter\":\"E\"}\n"
            . "Reponds UNIQUEMENT avec le JSON, sans markdown."
        );

        $parsed = json_decode(trim($response ?? ''), true);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp($activeGame);
        }

        return match ($parsed['action']) {
            'start'       => $this->startGame($context, null, $this->resolveCategory($parsed['category'] ?? '')),
            'guess'       => isset($parsed['letter'])
                ? $this->guessLetter($context, mb_strtoupper($parsed['letter']))
                : AgentResult::reply("Quelle lettre veux-tu proposer ?"),
            'guess_word'  => isset($parsed['word'])
                ? $this->guessWord($context, $parsed['word'])
                : AgentResult::reply("Quel mot veux-tu proposer ?"),
            'stats'       => $this->showStats($context),
            'hint'        => $this->hint($context),
            'abandon'     => $this->abandon($context),
            'status'      => $this->status($context),
            'history'     => $this->showHistory($context),
            'reset'       => $this->resetStats($context),
            'categories'  => $this->showCategories(),
            'daily'       => $this->dailyChallenge($context),
            'leaderboard' => $this->showLeaderboard($context),
            default       => $this->showHelp($activeGame),
        };
    }

    private function showHelp(?HangmanGame $activeGame = null): AgentResult
    {
        $help = "🎮 *Jeu du Pendu - Commandes :*\n\n"
            . "▶️ /hangman start → Nouvelle partie\n"
            . "🗂️ /hangman start [categorie] → Choisir une categorie\n"
            . "   └ tech | animaux | nature | vocab | sport | gastronomie\n"
            . "📅 /hangman daily → Defi du jour (meme mot pour tous)\n"
            . "🔤 /hangman guess X → Proposer la lettre X\n"
            . "🎯 /hangman devine MOT → Deviner le mot entier (-2 vies si faux)\n"
            . "💡 /hangman hint → Indice (revele une lettre, -1 vie)\n"
            . "✏️ /hangman word MOT → Partie avec mot personnalise\n"
            . "📋 /hangman status → Voir la partie en cours\n"
            . "📜 /hangman history → Historique des parties\n"
            . "🏳️ /hangman abandon → Abandonner la partie\n"
            . "📊 /hangman stats → Tes statistiques\n"
            . "🏆 /hangman top → Classement des meilleurs joueurs\n"
            . "🗂️ /hangman categories → Lister les categories\n"
            . "🔄 /hangman reset → Reinitialiser les stats\n\n"
            . "💡 Pendant une partie : envoie une lettre ou un mot directement !";

        if ($activeGame) {
            $board = $this->getDisplayBoard($activeGame);
            $help .= "\n\n--- Partie en cours ---\n\n{$board}";
        }

        return AgentResult::reply($help);
    }
}
