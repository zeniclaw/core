<?php

namespace App\Services\Agents;

use App\Models\HangmanGame;
use App\Models\HangmanStats;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;

class HangmanGameAgent extends BaseAgent
{
    private const MAX_WRONG = 6;

    private const DIFFICULTY_RANGES = [
        'easy'   => [2, 6],
        'medium' => [7, 10],
        'hard'   => [11, 30],
    ];

    private const DIFFICULTY_LABELS = [
        'easy'   => '🟢 Facile',
        'medium' => '🟡 Moyen',
        'hard'   => '🔴 Difficile',
    ];

    private const DIFFICULTY_ALIASES = [
        'facile'    => 'easy',
        'simple'    => 'easy',
        'court'     => 'easy',
        'moyen'     => 'medium',
        'normale'   => 'medium',
        'normal'    => 'medium',
        'difficile' => 'hard',
        'dur'       => 'hard',
        'long'      => 'hard',
        'expert'    => 'hard',
    ];

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
        'geographie' => [
            'PARIS', 'TOKYO', 'BERLIN', 'MADRID', 'OSLO',
            'LISBONNE', 'ATHENES', 'VIENNE', 'PRAGUE', 'VARSOVIE',
            'AMAZONE', 'HIMALAYA', 'SAHARA', 'EVEREST', 'KILIMANJARO',
            'CONTINENT', 'MERIDIEN', 'LATITUDE', 'EQUATEUR', 'TROPIQUE',
            'PACIFIQUE', 'ATLANTIQUE', 'ARCTIQUE', 'MEDITERRANEAN', 'FJORD',
        ],
    ];

    private const CATEGORY_LABELS = [
        'tech'        => 'Informatique 💻',
        'animaux'     => 'Animaux 🦁',
        'nature'      => 'Nature 🌿',
        'vocab'       => 'Vocabulaire 📚',
        'sport'       => 'Sport 🏆',
        'gastronomie' => 'Gastronomie 🍽️',
        'geographie'  => 'Géographie 🌍',
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
        'geo'          => 'geographie',
        'pays'         => 'geographie',
        'ville'        => 'geographie',
        'villes'       => 'geographie',
        'monde'        => 'geographie',
        'carte'        => 'geographie',
    ];

    public function name(): string
    {
        return 'hangman';
    }

    public function description(): string
    {
        return 'Agent jeu du Pendu (Hangman). Permet de jouer au pendu avec des mots aleatoires par categorie (tech, animaux, nature, vocab, sport, gastronomie, geographie) ou personnalises, avec niveaux de difficulte (easy/medium/hard), deviner le mot entier, obtenir des indices intelligents (voyelles en priorite), voir les lettres restantes de l\'alphabet (avec separation voyelles/consonnes), abandonner une partie, consulter son historique, suivre ses statistiques avec meilleur score, voir les categories disponibles, reinitialiser ses stats avec confirmation, acceder au defi du jour (daily, avec detection anti-rejeu), voir le classement des meilleurs joueurs (top), estimer son score en cours (score, avec multiplicateur de difficulte), rejouer le dernier mot (replay), voir sa meilleure partie (best), voir sa serie de victoires (streak), partager son resultat (share), consulter ses stats mensuelles (monthly), obtenir une astuce strategique gratuite (tip) basee sur l\'analyse des lettres cachees, analyser sa derniere partie (analyse, avec evaluation de la strategie) et suivre ses objectifs quotidiens (goals, 3 defis journaliers sans nouveau champ DB).';
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
            'difficulte pendu', 'pendu facile', 'pendu difficile', 'pendu moyen',
            'alphabet pendu', 'lettres restantes', 'hangman alpha',
            'score pendu', 'hangman score', 'score actuel pendu', 'mon score pendu',
            'replay pendu', 'hangman replay', 'rejouer dernier mot', 'rejouer pendu',
            'aide pendu', 'hangman help', 'commandes pendu', 'comment jouer pendu',
            'meilleure partie pendu', 'hangman best', 'best pendu', 'record pendu',
            'serie pendu', 'hangman streak', 'streak pendu', 'ma serie pendu',
            'geographie pendu', 'hangman geo', 'pendu pays', 'pendu villes',
            'stats semaine pendu', 'hangman weekly', 'pendu weekly', 'semaine pendu',
            'recap semaine pendu', 'performances semaine pendu',
            'partager pendu', 'hangman share', 'share pendu', 'partager resultat pendu',
            'stats mois pendu', 'hangman monthly', 'pendu monthly', 'mois pendu',
            'recap mois pendu', 'performances mois pendu', 'stats mensuelles pendu',
            'astuce pendu', 'hangman tip', 'tip pendu', 'conseil pendu', 'strategie pendu',
            'indice strategique pendu', 'tuyau pendu', 'aide strategie pendu',
            'stats categorie pendu', 'hangman cat', 'pendu cat', 'perf categorie pendu',
            'stats difficulte pendu', 'hangman diff', 'pendu diff', 'stats niveaux pendu',
            'analyser pendu', 'hangman analyse', 'analyse pendu', 'analyse partie pendu',
            'debriefing pendu', 'analyse strategique pendu', 'bilan partie pendu',
            'objectifs pendu', 'hangman goals', 'goals pendu', 'defis quotidiens pendu',
            'missions pendu', 'objectifs quotidiens pendu', 'challenges pendu', 'defis pendu',
            'wordlen pendu', 'hangman wordlen', 'mot de n lettres', 'pendu longueur',
            'mot 5 lettres', 'mot 6 lettres', 'mot 7 lettres', 'mot 8 lettres',
            'progression pendu', 'hangman progress', 'progress pendu', 'evolution pendu',
            'amelioration pendu', 'mes progres pendu', 'courbe progression pendu',
        ];
    }

    public function version(): string
    {
        return '1.13.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match('/\bhangman\b|\bpendu\b/iu', $context->body);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';

        if ($type === 'confirm_reset') {
            $body  = mb_strtolower(trim($context->body ?? ''));
            $this->clearPendingContext($context);

            if (preg_match('/^(oui|yes|confirmer?|ok|ouais|yep)$/i', $body)) {
                return $this->executeResetStats($context);
            }

            return AgentResult::reply("❌ *Reinitialisation annulee.* Tes statistiques sont preservees.\n\n/hangman stats pour les consulter.");
        }

        return null;
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

        // Alphabet display (remaining untried letters)
        if (preg_match('/\/hangman\s+alpha(?:bet)?/i', $lower) || preg_match('/\b(alphabet|lettres?\s+restantes?|lettres?\s+disponibles?)\s*(pendu|hangman)?\b/iu', $lower)) {
            return $this->showAlphabet($context);
        }

        // Start / new game — optionally with category and/or difficulty: /hangman start tech hard
        if (preg_match('/\/hangman\s+start(?:\s+(\w+))?(?:\s+(\w+))?/i', $body, $m)) {
            $param1 = !empty($m[1]) ? mb_strtolower($m[1]) : null;
            $param2 = !empty($m[2]) ? mb_strtolower($m[2]) : null;
            [$forcedCategory, $difficulty] = $this->parseCategoryAndDifficulty($param1, $param2);
            return $this->startGame($context, null, $forcedCategory, $difficulty);
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

        // Strategic tip (free, no life cost)
        if (preg_match('/\/hangman\s+tip/i', $lower) || preg_match('/\b(astuce|conseil|strategie|tips?|tuyau)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+(tip|astuce|conseil)\b/iu', $lower)) {
            return $this->showTip($context);
        }

        // Help
        if (preg_match('/\/hangman\s+help/i', $lower) || preg_match('/\b(aide|help|commandes?|comment\s+jouer)\s*(pendu|hangman)?\b/iu', $lower)) {
            return $this->showHelp($this->getActiveGame($context));
        }

        // Current game score estimate
        if (preg_match('/\/hangman\s+score/i', $lower) || preg_match('/\b(score\s+(actuel|courant|maintenant|en\s+cours))\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+score\b/iu', $lower)) {
            return $this->showCurrentScore($context);
        }

        // Replay last game's word
        if (preg_match('/\/hangman\s+replay/i', $lower) || preg_match('/\b(rejouer|replay)\s*(dernier|meme|last)?\s*(mot|partie|pendu|hangman)?\b/iu', $lower)) {
            return $this->replayLastGame($context);
        }

        // Best game details
        if (preg_match('/\/hangman\s+best/i', $lower) || preg_match('/\b(meilleure?\s+partie|best\s+score|record\s+pendu|hangman\s+best|best\s+pendu)\b/iu', $lower)) {
            return $this->showBestGame($context);
        }

        // Streak view
        if (preg_match('/\/hangman\s+streak/i', $lower) || preg_match('/\b(ma\s+serie|serie\s+victoires?|hangman\s+streak|streak\s+pendu)\b/iu', $lower)) {
            return $this->showStreak($context);
        }

        // Weekly stats
        if (preg_match('/\/hangman\s+weekly/i', $lower) || preg_match('/\b(weekly|semaine|recap\s+semaine|stats?\s+semaine|performances?\s+semaine)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+(weekly|semaine)\b/iu', $lower)) {
            return $this->showWeeklyStats($context);
        }

        // Monthly stats
        if (preg_match('/\/hangman\s+monthly/i', $lower) || preg_match('/\b(monthly|mois|recap\s+mois|stats?\s+mois|performances?\s+mois|stats?\s+mensuelles?)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+(monthly|mois)\b/iu', $lower)) {
            return $this->showMonthlyStats($context);
        }

        // Share last game result
        if (preg_match('/\/hangman\s+share/i', $lower) || preg_match('/\b(share|partager?\s*(resultat|partie|score)?)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+share\b/iu', $lower)) {
            return $this->showShareResult($context);
        }

        // Category stats
        if (preg_match('/\/hangman\s+cat(?:\s+(\w+))?/i', $body, $m)) {
            $cat = isset($m[1]) ? mb_strtolower($m[1]) : null;
            return $this->showCategoryStats($context, $cat);
        }

        if (preg_match('/\b(stats?\s+categorie|categorie\s+stats?|perf(?:ormances?)?\s+categorie)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+cat\b/iu', $lower)) {
            return $this->showCategoryStats($context, null);
        }

        // Difficulty stats
        if (preg_match('/\/hangman\s+diff(?:icult[ey]s?)?/i', $lower) || preg_match('/\b(stats?\s+difficulte|difficulte\s+stats?|perf(?:ormances?)?\s+(niveaux?|difficulte))\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+diff\b/iu', $lower)) {
            return $this->showDifficultyStats($context);
        }

        // Post-game analysis
        if (preg_match('/\/hangman\s+analys[ei]/i', $lower) || preg_match('/\b(analys[ei]r?|debriefing|bilan\s+partie)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+analys[ei]\b/iu', $lower)) {
            return $this->showPostGameAnalysis($context);
        }

        // Daily goals
        if (preg_match('/\/hangman\s+goals?/i', $lower) || preg_match('/\b(objectifs?|goals?|missions?|defis?\s*quotidiens?|challenges?)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+goals?\b/iu', $lower)) {
            return $this->showGoals($context);
        }

        // Word length game: /hangman wordlen N or "un mot de N lettres"
        if (preg_match('/\/hangman\s+wordlen\s+(\d+)/i', $body, $m)) {
            return $this->showWordlenGame($context, (int) $m[1]);
        }
        if (preg_match('/\bmot\s+de\s+(\d+)\s+lettres?\b/iu', $lower, $m)) {
            $len = (int) $m[1];
            if ($len >= 2 && $len <= 30) {
                return $this->showWordlenGame($context, $len);
            }
        }

        // Progression report
        if (preg_match('/\/hangman\s+progress(?:ion)?/i', $lower) || preg_match('/\b(progress(?:ion)?|evolution|amelioration)\s*(pendu|hangman)?\b/iu', $lower) || preg_match('/\b(pendu|hangman)\s+progress(?:ion)?\b/iu', $lower)) {
            return $this->showProgress($context);
        }

        // Natural language handling via Claude
        return $this->handleNaturalLanguage($body, $context);
    }

    // ── Core game actions ─────────────────────────────────────────────────────

    private function startGame(AgentContext $context, ?string $customWord = null, ?string $forcedCategory = null, ?string $difficulty = null): AgentResult
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
            [$word, $category] = $this->getRandomWordAndCategory($forcedCategory, $difficulty);
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
        // Show category label; 🎲 indicates random selection
        if ($category) {
            $catLabel = $forcedCategory
                ? ' | ' . self::CATEGORY_LABELS[$category]
                : ' | ' . self::CATEGORY_LABELS[$category] . ' 🎲';
        } else {
            $catLabel = '';
        }
        $diffLabel = $difficulty ? ' | ' . self::DIFFICULTY_LABELS[$difficulty] : '';
        $wordLen   = mb_strlen($word);

        $this->log($context, 'New hangman game started', ['game_id' => $game->id, 'word_length' => $wordLen, 'category' => $category, 'difficulty' => $difficulty]);

        return AgentResult::reply(
            "{$abandonMsg}🎮 *Nouvelle partie de Pendu !*{$catLabel}{$diffLabel}\n"
            . "📏 Mot de *{$wordLen}* lettre(s)\n\n"
            . "{$board}\n\n"
            . "Envoie une lettre pour deviner !\n"
            . "💡 /hangman hint (indice, -1 vie) | /hangman devine MOT (mot entier, -2 vies si faux)\n"
            . "🗂️ Categorie : /hangman start [tech|animaux|nature|vocab|sport|gastronomie]\n"
            . "🎯 Difficulte : /hangman start [facile|moyen|difficile]"
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

        $letter  = $this->normalizeAccents(mb_strtoupper($letter));
        $guessed = $game->guessed_letters ?? [];

        // Validate: must be a single letter
        if (!preg_match('/^\pL$/u', $letter)) {
            return AgentResult::reply("❌ *{$letter}* n'est pas une lettre valide. Envoie une seule lettre (A-Z).");
        }

        // Already guessed?
        if (in_array($letter, $guessed)) {
            $board = $this->getDisplayBoard($game);
            return AgentResult::reply("⚠️ Tu as deja propose la lettre *{$letter}* !\n\n{$board}");
        }

        // Add letter
        $guessed[]             = $letter;
        $game->guessed_letters = $guessed;

        // Check if letter is in word — normalize word chars to handle accented words (custom words).
        $wordUpper         = mb_strtoupper($game->word);
        $wordNormalized    = $this->normalizeWordAccents($wordUpper);
        $letterNormalized  = $this->normalizeAccents($letter);
        $found             = mb_strpos($wordNormalized, $letterNormalized) !== false;

        if (!$found) {
            $game->wrong_count++;
        }

        // Count occurrences and positions for richer feedback (compare against normalized word)
        $occurrences = 0;
        $positions   = [];
        if ($found) {
            for ($i = 0; $i < mb_strlen($wordNormalized); $i++) {
                if (mb_substr($wordNormalized, $i, 1) === $letterNormalized) {
                    $occurrences++;
                    $positions[] = $i + 1;
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
            $score   = $this->computeScore($game);
            $bestMsg = $this->checkNewBestScore($context, $score);
            $this->updateStatsOnEnd($stats, true);

            $board      = $this->getDisplayBoard($game);
            $wrongCount = $game->wrong_count;
            $maxWrong   = self::MAX_WRONG;
            $speedMsg   = $this->getSpeedMessage($game);
            $streakMsg  = $stats->current_streak > 1 ? "\n🔥 Serie : *{$stats->current_streak}* victoires d'affile !" : '';
            return AgentResult::reply("🎉 *Bravo, tu as gagne !*{$speedMsg}\n\n{$board}\n\nMot : *{$game->word}*\nErreurs : {$wrongCount}/{$maxWrong}\n🏅 Score : *{$score} pts*{$bestMsg}{$streakMsg}\n\n🔄 /hangman start pour rejouer !");
        }

        $game->save();
        $stats->save();

        $board    = $this->getDisplayBoard($game);
        $emoji    = $found ? '✅' : '❌';
        $posMsg   = '';
        if ($found) {
            $posStr = count($positions) === 1
                ? 'position ' . $positions[0]
                : 'positions ' . implode(', ', $positions);
            $posMsg = " ({$posStr})";
        }
        $occMsg    = $found && $occurrences > 1 ? " — {$occurrences}x dans le mot" : '';
        $msg       = $found
            ? "Bien joue ! *{$letter}* est dans le mot{$posMsg}{$occMsg} !"
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
            $score    = $this->computeScore($game);
            $bestMsg  = $this->checkNewBestScore($context, $score);
            $this->updateStatsOnEnd($stats, true);

            $board      = $this->getDisplayBoard($game);
            $speedMsg   = $this->getSpeedMessage($game);
            $streakMsg  = $stats->current_streak > 1 ? "\n🔥 Serie : *{$stats->current_streak}* victoires d'affile !" : '';
            return AgentResult::reply(
                "🎉 *Excellent ! Tu as trouve le mot entier !*{$speedMsg}\n\n"
                . "{$board}\n\n"
                . "Mot : *{$game->word}*\n"
                . "🏅 Score : *{$score} pts*{$bestMsg}{$streakMsg}\n\n"
                . "🔄 /hangman start pour rejouer !"
            );
        }

        // Count letters in common between guess and actual word (set intersection)
        $commonCount = $this->countCommonLetters($guessUpper, $wordUpper);
        $commonMsg   = $commonCount > 0
            ? " | 💬 {$commonCount} lettre(s) en commun avec le mot"
            : '';

        // Wrong word guess costs 2 errors
        $game->wrong_count += 2;

        if ($game->isLost()) {
            $game->status = 'lost';
            $game->save();
            $this->updateStatsOnEnd($stats, false);

            $board = $this->getDisplayBoard($game);
            return AgentResult::reply(
                "💀 *Mauvaise reponse ! \"{$guessUpper}\" est faux. (-2 vies){$commonMsg}*\n\n"
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
            "❌ *\"{$guessUpper}\" n'est pas le bon mot !* (-2 vies){$commonMsg}\n\n"
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

        // Find letters not yet guessed (use normalized comparison for accent-insensitive check)
        $word        = mb_strtoupper($game->word);
        $guessedNorm = array_map(
            fn ($l) => $this->normalizeAccents(mb_strtoupper($l)),
            $game->guessed_letters ?? []
        );
        $unguessed = [];

        for ($i = 0; $i < mb_strlen($word); $i++) {
            $char     = mb_substr($word, $i, 1);
            $charNorm = $this->normalizeAccents($char);
            if (preg_match('/\pL/u', $char) && !in_array($charNorm, $guessedNorm) && !in_array($charNorm, array_map([$this, 'normalizeAccents'], $unguessed))) {
                $unguessed[] = $charNorm; // store normalized for consistency
            }
        }

        if (empty($unguessed)) {
            return AgentResult::reply("✅ Toutes les lettres ont deja ete revelees !");
        }

        // Prefer vowels for more useful hints
        $vowels       = ['A', 'E', 'I', 'O', 'U'];
        $vowelOptions = array_values(array_intersect($unguessed, $vowels));
        $pool         = !empty($vowelOptions) ? $vowelOptions : $unguessed;
        $hintLetter   = $pool[array_rand($pool)];
        $game->wrong_count++;
        $existingGuessed       = array_map('mb_strtoupper', $game->guessed_letters ?? []);
        $existingGuessed[]     = $hintLetter;
        $game->guessed_letters = array_values(array_unique($existingGuessed));

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

        // Compute positions of the revealed hint letter (use normalized comparison)
        $wordNormForHint = $this->normalizeWordAccents($word);
        $hintPositions   = [];
        for ($i = 0; $i < mb_strlen($wordNormForHint); $i++) {
            if (mb_substr($wordNormForHint, $i, 1) === $hintLetter) {
                $hintPositions[] = $i + 1;
            }
        }
        $hintPosMsg = count($hintPositions) === 1
            ? ' en position ' . $hintPositions[0]
            : ' en positions ' . implode(', ', $hintPositions);

        $board     = $this->getDisplayBoard($game);
        $livesLeft = self::MAX_WRONG - $game->wrong_count;
        return AgentResult::reply("💡 *Indice :* La lettre *{$hintLetter}* est dans le mot{$hintPosMsg} ! (-1 vie, {$livesLeft} restante(s))\n\n{$board}");
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
        $masked    = $game->getMaskedWord();
        $hidden    = substr_count($masked, '_');
        $totalLen  = mb_strlen(preg_replace('/[^a-zA-Z\x{00C0}-\x{017F}]/u', '', $game->word));
        $found     = max(0, $totalLen - $hidden);
        $pct       = $totalLen > 0 ? round(($found / $totalLen) * 100) : 0;
        $hiddenMsg = $hidden > 0 ? " | *{$found}/{$totalLen}* lettres trouvees ({$pct}%)" : ' | ✅ Toutes les lettres trouvees !';

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

        // Top category
        $catCounts = [];
        $wonGamesForCat = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'won')
            ->get(['word']);
        foreach ($wonGamesForCat as $g) {
            foreach (self::WORD_CATEGORIES as $cat => $words) {
                if (in_array($g->word, $words)) {
                    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
                    break;
                }
            }
        }
        if (!empty($catCounts)) {
            arsort($catCounts);
            $topCat    = array_key_first($catCounts);
            $topLabel  = self::CATEGORY_LABELS[$topCat];
            $response .= "\n🗂️ Categorie favorite : {$topLabel} ({$catCounts[$topCat]} victoires)";
        }

        $response .= "\n\n_/hangman cat pour les stats par categorie | /hangman reset pour reinitialiser_";

        return AgentResult::reply($response);
    }

    private function showHistory(AgentContext $context): AgentResult
    {
        $total = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->count();

        $games = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        if ($games->isEmpty()) {
            return AgentResult::reply("📋 Aucune partie terminee pour l'instant.\n\nEnvoie /hangman start pour jouer !");
        }

        $showing = $games->count();
        $suffix  = $total > $showing ? " (sur {$total} au total)" : '';
        $lines   = ["📋 *Historique — {$showing} dernieres parties{$suffix} :*\n"];

        $shownWon  = 0;
        $shownLost = 0;

        foreach ($games as $game) {
            $icon     = $game->status === 'won' ? '🏆' : '💀';
            $date     = $game->updated_at->format('d/m H:i');
            $errors   = $game->wrong_count;
            $wordLen  = mb_strlen($game->word);
            $diffEmoji = match (true) {
                $wordLen >= 11 => '🔴',
                $wordLen >= 7  => '🟡',
                default        => '🟢',
            };
            $scoreStr = $game->status === 'won'
                ? ' · 🏅 ' . $this->computeScore($game) . ' pts'
                : '';
            $lines[] = "{$icon} *{$game->word}* ({$wordLen}L {$diffEmoji}) — {$errors}/" . self::MAX_WRONG . " erreurs{$scoreStr} ({$date})";
            if ($game->status === 'won') {
                $shownWon++;
            } else {
                $shownLost++;
            }
        }

        $lines[] = "\n_Affiches : {$shownWon} victoires, {$shownLost} defaites | /hangman stats pour les stats globales_";

        return AgentResult::reply(implode("\n", $lines));
    }

    private function showCategories(): AgentResult
    {
        $lines = ["🗂️ *Categories disponibles :*\n"];

        foreach (self::CATEGORY_LABELS as $key => $label) {
            $count   = count(self::WORD_CATEGORIES[$key]);
            $lines[] = "• {$label} — {$count} mots → /hangman start {$key}";
        }

        $lines[] = "\n💡 Exemple : /hangman start sport | /hangman start geographie";
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

        $catLabel       = self::CATEGORY_LABELS[$category];
        $todayFormatted = Carbon::today()->format('d/m/Y');

        // Check if user already completed today's daily challenge
        $existingDaily = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('word', $word)
            ->whereDate('created_at', $dateStr)
            ->whereIn('status', ['won', 'lost'])
            ->latest()
            ->first();

        if ($existingDaily) {
            $icon     = $existingDaily->status === 'won' ? '🏆' : '💀';
            $result   = $existingDaily->status === 'won' ? 'Victoire' : 'Defaite';
            $scoreStr = $existingDaily->status === 'won'
                ? "\n🏅 Score : *" . $this->computeScore($existingDaily) . " pts*"
                : '';
            $errCount = $existingDaily->wrong_count;
            $maxWrong = self::MAX_WRONG;

            return AgentResult::reply(
                "📅 *Defi du Jour — {$todayFormatted}*\n\n"
                . "{$icon} Tu as deja joue le defi d'aujourd'hui !\n"
                . "Mot : *{$word}* — {$result} ({$errCount}/{$maxWrong} erreurs){$scoreStr}\n\n"
                . "🌅 Reviens demain pour un nouveau defi !\n"
                . "_/hangman start pour une partie normale | /hangman replay pour rejouer ce mot_"
            );
        }

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

        $board   = $this->getDisplayBoard($game);
        $wordLen = mb_strlen($word);

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

        // Ask for confirmation before wiping stats
        $this->setPendingContext($context, 'confirm_reset', [
            'games_played' => $stats->games_played,
            'games_won'    => $stats->games_won,
        ], 3, true);

        return AgentResult::reply(
            "⚠️ *Confirmation requise*\n\n"
            . "Tu es sur le point de supprimer *{$stats->games_played}* parties "
            . "({$stats->games_won} victoires) et toutes tes statistiques.\n\n"
            . "Reponds *OUI* pour confirmer, ou *NON* pour annuler."
        );
    }

    private function executeResetStats(AgentContext $context): AgentResult
    {
        $stats = HangmanStats::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if ($stats) {
            $stats->update([
                'games_played'   => 0,
                'games_won'      => 0,
                'best_streak'    => 0,
                'current_streak' => 0,
                'total_guesses'  => 0,
                'last_played_at' => null,
            ]);
        }

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
        // Normalize guessed letters to ASCII for comparison
        $guessedNorm = array_map(
            fn ($l) => $this->normalizeAccents(mb_strtoupper($l)),
            $game->guessed_letters ?? []
        );
        $word    = mb_strtoupper($game->word);
        $display = '';

        for ($i = 0; $i < mb_strlen($word); $i++) {
            $char     = mb_substr($word, $i, 1);
            $charNorm = $this->normalizeAccents($char);
            if ($char === ' ') {
                $display .= '   ';
            } elseif ($char === '-' || $char === "'") {
                $display .= $char . ' ';
            } elseif (in_array($charNorm, $guessedNorm)) {
                $display .= $char . ' ';
            } else {
                $display .= '_ ';
            }
        }

        return trim($display);
    }

    private function getWrongLetters(HangmanGame $game): array
    {
        $guessed        = $game->guessed_letters ?? [];
        $wordNormalized = $this->normalizeWordAccents(mb_strtoupper($game->word));
        $wrong          = [];

        foreach ($guessed as $letter) {
            $letterNorm = $this->normalizeAccents(mb_strtoupper($letter));
            if (mb_strpos($wordNormalized, $letterNorm) === false) {
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

        // Difficulty multiplier based on word length
        $multiplier = match (true) {
            $wordLen >= 11 => 1.5,
            $wordLen >= 7  => 1.2,
            default        => 1.0,
        };

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

        return max(0, (int) round(($base - $errorPenalty + $bonus) * $multiplier) + $speedBonus);
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

    private function getRandomWordAndCategory(?string $category = null, ?string $difficulty = null): array
    {
        $categories = array_keys(self::WORD_CATEGORIES);

        if ($category && isset(self::WORD_CATEGORIES[$category])) {
            $cat = $category;
        } else {
            $cat = $categories[array_rand($categories)];
        }

        $words = self::WORD_CATEGORIES[$cat];

        // Filter by difficulty if specified
        if ($difficulty && isset(self::DIFFICULTY_RANGES[$difficulty])) {
            [$minLen, $maxLen] = self::DIFFICULTY_RANGES[$difficulty];
            $filtered = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= $minLen && mb_strlen($w) <= $maxLen));

            // Fallback: if no words match in chosen category, try across all categories
            if (empty($filtered)) {
                $allWords = [];
                foreach (self::WORD_CATEGORIES as $words2) {
                    foreach ($words2 as $w) {
                        if (mb_strlen($w) >= $minLen && mb_strlen($w) <= $maxLen) {
                            $allWords[] = $w;
                        }
                    }
                }
                if (!empty($allWords)) {
                    $filtered = $allWords;
                    $cat      = null; // mixed category
                }
            }

            if (!empty($filtered)) {
                $words = $filtered;
            }
        }

        $word = $words[array_rand($words)];

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

    private function showAlphabet(AgentContext $context): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours. Envoie /hangman start pour commencer !");
        }

        $guessed   = array_map('mb_strtoupper', $game->guessed_letters ?? []);
        $alphabet  = range('A', 'Z');
        $remaining = array_values(array_filter($alphabet, fn ($l) => !in_array($l, $guessed)));
        $count     = count($remaining);

        $vowels           = ['A', 'E', 'I', 'O', 'U', 'Y'];
        $remainVowels     = array_values(array_filter($remaining, fn ($l) => in_array($l, $vowels)));
        $remainConsonants = array_values(array_filter($remaining, fn ($l) => !in_array($l, $vowels)));

        $vowelStr     = !empty($remainVowels) ? implode(' ', $remainVowels) : '(toutes essayees)';
        $consonantStr = !empty($remainConsonants) ? implode(' ', $remainConsonants) : '(toutes essayees)';

        $board = $this->getDisplayBoard($game);

        return AgentResult::reply(
            "🔤 *Lettres non essayees ({$count} restantes) :*\n"
            . "\n🔵 Voyelles (" . count($remainVowels) . ") : {$vowelStr}"
            . "\n⬜ Consonnes (" . count($remainConsonants) . ") : {$consonantStr}"
            . "\n\n{$board}"
        );
    }

    private function checkNewBestScore(AgentContext $context, int $currentScore): string
    {
        if ($currentScore <= 0) {
            return '';
        }

        $wonGames = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'won')
            ->get(['word', 'wrong_count', 'created_at', 'updated_at']);

        // Only celebrate if there are previous wins (more than just this one)
        if ($wonGames->count() <= 1) {
            return '';
        }

        $maxScore = $wonGames->map(fn ($g) => $this->computeScore($g))->max();

        if ($currentScore >= $maxScore) {
            return "\n🌟 *Nouveau record personnel !*";
        }

        return '';
    }

    private function parseCategoryAndDifficulty(?string $param1, ?string $param2): array
    {
        $category   = null;
        $difficulty = null;

        foreach ([$param1, $param2] as $param) {
            if ($param === null) {
                continue;
            }
            $resolvedDiff = self::DIFFICULTY_ALIASES[$param] ?? (isset(self::DIFFICULTY_RANGES[$param]) ? $param : null);
            if ($resolvedDiff !== null && $difficulty === null) {
                $difficulty = $resolvedDiff;
            } elseif ($category === null) {
                $resolved = $this->resolveCategory($param);
                if ($resolved !== null) {
                    $category = $resolved;
                }
            }
        }

        return [$category, $difficulty];
    }

    /**
     * Normalize an accented uppercase character to its ASCII base.
     * Allows users to type "e" and match "E" in words containing "É" etc.
     */
    private function normalizeAccents(string $char): string
    {
        $map = [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I',
            'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C',
        ];

        return $map[$char] ?? $char;
    }

    /**
     * Normalize every character of an entire uppercase word to its ASCII base.
     * Used for accent-insensitive letter matching in custom words.
     */
    private function normalizeWordAccents(string $word): string
    {
        $result = '';
        for ($i = 0; $i < mb_strlen($word); $i++) {
            $result .= $this->normalizeAccents(mb_substr($word, $i, 1));
        }

        return $result;
    }

    private function showShareResult(AgentContext $context): AgentResult
    {
        $lastGame = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->latest()
            ->first();

        if (!$lastGame) {
            return AgentResult::reply(
                "Aucune partie terminee a partager.\n\nEnvoie /hangman start pour jouer !"
            );
        }

        $word       = $lastGame->word;
        $wordLen    = mb_strlen($word);
        $errors     = $lastGame->wrong_count;
        $maxWrong   = self::MAX_WRONG;
        $status     = $lastGame->status;
        $dateStr    = $lastGame->updated_at ? $lastGame->updated_at->format('d/m/Y') : date('d/m/Y');
        $guessed    = $lastGame->guessed_letters ?? [];

        // Determine difficulty label from word length
        $diffLabel = '';
        foreach (self::DIFFICULTY_RANGES as $level => [$min, $max]) {
            if ($wordLen >= $min && $wordLen <= $max) {
                $diffLabel = self::DIFFICULTY_LABELS[$level];
                break;
            }
        }

        // Build emoji grid: each guessed letter shown as ✅ (correct) or ❌ (wrong)
        $wordNorm      = $this->normalizeWordAccents(mb_strtoupper($word));
        $emojiGuesses  = [];
        foreach ($guessed as $letter) {
            $letterNorm      = $this->normalizeAccents(mb_strtoupper($letter));
            $emojiGuesses[]  = mb_strpos($wordNorm, $letterNorm) !== false ? '✅' : '❌';
        }
        $emojiLine = !empty($emojiGuesses) ? implode('', $emojiGuesses) : '—';

        // Result
        $resultIcon = $status === 'won' ? '🏆 Gagne !' : '💀 Perdu...';
        $scoreStr   = '';
        if ($status === 'won') {
            $score    = $this->computeScore($lastGame);
            $scoreStr = "\n🏅 Score : *{$score} pts*";
        }

        $diffStr = $diffLabel ? "\n🎯 Niveau : {$diffLabel}" : '';

        return AgentResult::reply(
            "📋 *Mon dernier Pendu — {$dateStr}*\n\n"
            . "{$resultIcon}\n"
            . "🔤 Mot : *{$word}* ({$wordLen} lettres){$diffStr}\n"
            . "❌ Erreurs : *{$errors}/{$maxWrong}*{$scoreStr}\n\n"
            . "Mes propositions :\n{$emojiLine}\n\n"
            . "_Joue au Pendu ZeniClaw ! /hangman start_"
        );
    }

    private function showMonthlyStats(AgentContext $context): AgentResult
    {
        $since = Carbon::now()->subDays(30)->startOfDay();

        $games = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->get();

        $dateRange = $since->format('d/m') . ' → ' . Carbon::now()->format('d/m');

        if ($games->isEmpty()) {
            return AgentResult::reply(
                "📆 *Stats du mois ({$dateRange}) :*\n\n"
                . "Aucune partie terminee ce mois-ci.\n\n"
                . "Envoie /hangman start pour jouer !"
            );
        }

        $played   = $games->count();
        $wonGames = $games->where('status', 'won');
        $won      = $wonGames->count();
        $lost     = $played - $won;
        $winRate  = $played > 0 ? round(($won / $played) * 100) : 0;
        $bar      = $this->generateProgressBar($winRate);

        // Best score this month
        $bestScore = 0;
        $bestWord  = null;
        foreach ($wonGames as $game) {
            $score = $this->computeScore($game);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestWord  = $game->word;
            }
        }

        // Category breakdown: count games per category
        $catCounts = [];
        foreach ($games->where('status', 'won') as $game) {
            foreach (self::WORD_CATEGORIES as $cat => $words) {
                if (in_array($game->word, $words)) {
                    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
                    break;
                }
            }
        }
        arsort($catCounts);
        $topCat    = !empty($catCounts) ? array_key_first($catCounts) : null;
        $topCatMsg = $topCat ? "\n🏅 Categorie favorite : " . self::CATEGORY_LABELS[$topCat] . " ({$catCounts[$topCat]} victoires)" : '';

        // Average errors per won game
        $avgErrors = $won > 0
            ? round($wonGames->avg('wrong_count'), 1)
            : 0;

        $response = "📆 *Stats du mois ({$dateRange}) :*\n\n"
            . "🎮 Parties jouees : *{$played}*\n"
            . "🏆 Victoires : *{$won}*\n"
            . "💀 Defaites : *{$lost}*\n"
            . "📈 Taux de victoire : *{$winRate}%* {$bar}\n"
            . "📉 Moy. erreurs/victoire : *{$avgErrors}*\n";

        if ($bestWord) {
            $response .= "🏅 Meilleur score ce mois : *{$bestScore} pts* (mot : {$bestWord})\n";
        }

        $response .= $topCatMsg;
        $response .= "\n\n_/hangman stats pour tes stats globales | /hangman start pour continuer !_";

        return AgentResult::reply($response);
    }

    private function countCommonLetters(string $guessUpper, string $wordUpper): int
    {
        $guessNorm = $this->normalizeWordAccents($guessUpper);
        $wordNorm  = $this->normalizeWordAccents($wordUpper);

        $guessLetters = [];
        for ($i = 0; $i < mb_strlen($guessNorm); $i++) {
            $char = mb_substr($guessNorm, $i, 1);
            if (preg_match('/\pL/u', $char) && !in_array($char, $guessLetters)) {
                $guessLetters[] = $char;
            }
        }

        $count = 0;
        foreach ($guessLetters as $letter) {
            if (mb_strpos($wordNorm, $letter) !== false) {
                $count++;
            }
        }

        return $count;
    }

    private function showCurrentScore(AgentContext $context): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            $bestScore = $this->getBestScore($context);
            if ($bestScore > 0) {
                return AgentResult::reply(
                    "Pas de partie en cours.\n\n🏅 Ton meilleur score : *{$bestScore} pts*\n\n"
                    . "Envoie /hangman start pour jouer !"
                );
            }

            return AgentResult::reply("Pas de partie en cours. Envoie /hangman start pour commencer !");
        }

        $wordLen      = mb_strlen($game->word);
        $errors       = $game->wrong_count;
        $base         = $wordLen * 10;
        $errorPenalty = $errors * 5;
        $bonus        = max(0, (self::MAX_WRONG - $errors) * 3);
        $estimated    = $this->computeScore($game);
        $livesLeft    = self::MAX_WRONG - $errors;

        // Difficulty multiplier
        $multiplier     = match (true) {
            $wordLen >= 11 => 1.5,
            $wordLen >= 7  => 1.2,
            default        => 1.0,
        };
        $multiplierMsg = $multiplier > 1.0
            ? "\n🎯 Multiplicateur difficulte : *×{$multiplier}* (mot " . ($wordLen >= 11 ? 'difficile' : 'moyen') . ')'
            : '';

        $speedHint = '';
        if ($game->created_at) {
            $seconds = $game->created_at->diffInSeconds(now());
            if ($seconds < 60) {
                $speedHint = "\n⚡ Vitesse en cours : *Eclair* (+20 si tu gagnes maintenant !)";
            } elseif ($seconds < 120) {
                $speedHint = "\n🚀 Vitesse en cours : *Rapide* (+10 si tu gagnes maintenant !)";
            } elseif ($seconds < 300) {
                $speedHint = "\n⏱️ Vitesse en cours : *Bonne* (+5 si tu gagnes maintenant !)";
            }
        }

        $board = $this->getDisplayBoard($game);

        return AgentResult::reply(
            "📊 *Score estime si tu gagnes maintenant :*\n\n"
            . "🔤 Base (longueur mot × 10) : +{$base} pts\n"
            . "❌ Penalite erreurs (× 5) : -{$errorPenalty} pts\n"
            . "⭐ Bonus vies restantes (× 3) : +{$bonus} pts{$multiplierMsg}{$speedHint}\n\n"
            . "🏅 *Estimation : ~{$estimated} pts* | {$livesLeft} vie(s) restante(s)\n\n"
            . "{$board}"
        );
    }

    private function showBestGame(AgentContext $context): AgentResult
    {
        $wonGames = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'won')
            ->get(['word', 'wrong_count', 'created_at', 'updated_at']);

        if ($wonGames->isEmpty()) {
            return AgentResult::reply(
                "🏅 Aucune victoire enregistree pour l'instant.\n\nEnvoie /hangman start pour jouer !"
            );
        }

        $best      = $wonGames->sortByDesc(fn ($g) => $this->computeScore($g))->first();
        $score     = $this->computeScore($best);
        $errors    = $best->wrong_count;
        $maxWrong  = self::MAX_WRONG;
        $wordLen   = mb_strlen($best->word);
        $dateStr   = $best->updated_at ? $best->updated_at->format('d/m/Y') : '?';
        $totalWins = $wonGames->count();

        $speedBonus = 0;
        if ($best->created_at && $best->updated_at) {
            $secs = $best->created_at->diffInSeconds($best->updated_at);
            if ($secs < 60) {
                $speedBonus = 20;
            } elseif ($secs < 120) {
                $speedBonus = 10;
            } elseif ($secs < 300) {
                $speedBonus = 5;
            }
        }

        $speedLabel = match (true) {
            $speedBonus >= 20 => '⚡ Eclair (+20)',
            $speedBonus >= 10 => '🚀 Rapide (+10)',
            $speedBonus >= 5  => '⏱️ Bonne vitesse (+5)',
            default           => 'Normal (+0)',
        };

        return AgentResult::reply(
            "🏅 *Ta meilleure partie :*\n\n"
            . "🔤 Mot : *{$best->word}* ({$wordLen} lettres)\n"
            . "❌ Erreurs : *{$errors}/{$maxWrong}*\n"
            . "⚡ Vitesse : {$speedLabel}\n"
            . "🏆 Score : *{$score} pts*\n"
            . "📅 Date : {$dateStr}\n\n"
            . "_Total victoires : {$totalWins} | /hangman stats pour plus_"
        );
    }

    private function showStreak(AgentContext $context): AgentResult
    {
        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);

        if ($stats->games_played === 0) {
            return AgentResult::reply(
                "🔥 Pas encore de serie ! Commence a jouer pour batir ta serie.\n\n/hangman start"
            );
        }

        $current = $stats->current_streak;
        $best    = $stats->best_streak;

        $streakMsg = match (true) {
            $current >= 10 => "🔥🔥🔥 *Serie incroyable de {$current} victoires !* Tu es en feu !",
            $current >= 5  => "🔥🔥 *Serie impressionnante de {$current} victoires !*",
            $current >= 3  => "🔥 *Belle serie de {$current} victoires !*",
            $current >= 1  => "🔥 *Serie en cours : {$current} victoire(s)*",
            default        => "😔 Pas de serie en cours (derniere partie perdue).",
        };

        $bestMsg = $best > $current
            ? "\n\n🏆 Ton record : *{$best}* victoires d'affile"
            : ($best > 0 ? "\n\n🏆 C'est ton record egal !" : '');

        $toRecord = $best > $current ? $best - $current : 0;
        $goalMsg  = $toRecord > 0
            ? "\n🎯 Encore *{$toRecord}* pour battre ton record de {$best} !"
            : ($best > 0 && $current >= $best ? "\n🎯 Tu egales ton record !" : '');

        return AgentResult::reply(
            "{$streakMsg}{$bestMsg}{$goalMsg}\n\n"
            . "📊 {$stats->games_won}/{$stats->games_played} victoires | /hangman start pour continuer !"
        );
    }

    private function showWeeklyStats(AgentContext $context): AgentResult
    {
        $since = Carbon::now()->subDays(7)->startOfDay();

        $games = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->get();

        $dateRange = $since->format('d/m') . ' → ' . Carbon::now()->format('d/m');

        if ($games->isEmpty()) {
            return AgentResult::reply(
                "📅 *Stats de la semaine ({$dateRange}) :*\n\n"
                . "Aucune partie terminee cette semaine.\n\n"
                . "Envoie /hangman start pour jouer !"
            );
        }

        $played  = $games->count();
        $wonGames = $games->where('status', 'won');
        $won     = $wonGames->count();
        $lost    = $played - $won;
        $winRate = $played > 0 ? round(($won / $played) * 100) : 0;
        $bar     = $this->generateProgressBar($winRate);

        // Best score this week
        $bestScore = 0;
        $bestWord  = null;
        foreach ($wonGames as $game) {
            $score = $this->computeScore($game);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestWord  = $game->word;
            }
        }

        // Streak within the week (consecutive wins from latest)
        $weekStreak = 0;
        foreach ($games->sortByDesc('updated_at') as $game) {
            if ($game->status === 'won') {
                $weekStreak++;
            } else {
                break;
            }
        }

        $response = "📅 *Stats de la semaine ({$dateRange}) :*\n\n"
            . "🎮 Parties jouees : *{$played}*\n"
            . "🏆 Victoires : *{$won}*\n"
            . "💀 Defaites : *{$lost}*\n"
            . "📈 Taux de victoire : *{$winRate}%* {$bar}\n";

        if ($bestWord) {
            $response .= "🏅 Meilleur score cette semaine : *{$bestScore} pts* (mot : {$bestWord})\n";
        }

        if ($weekStreak >= 2) {
            $response .= "🔥 Serie en cours : *{$weekStreak}* victoires d'affile cette semaine\n";
        }

        $response .= "\n_/hangman stats pour tes stats globales | /hangman start pour continuer !_";

        return AgentResult::reply($response);
    }

    private function replayLastGame(AgentContext $context): AgentResult
    {
        $lastGame = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->latest()
            ->first();

        if (!$lastGame) {
            return AgentResult::reply(
                "Aucune partie precedente trouvee.\n\nEnvoie /hangman start pour commencer !"
            );
        }

        $icon    = $lastGame->status === 'won' ? '🏆 Gagne' : '💀 Perdu';
        $errors  = $lastGame->wrong_count;
        $maxWrong = self::MAX_WRONG;

        $this->log($context, 'Replay requested', ['word' => $lastGame->word, 'last_status' => $lastGame->status]);

        // Prepend a context message acknowledging the replay
        $existingGame = $this->getActiveGame($context);
        $abandonMsg   = '';
        if ($existingGame) {
            $this->abandonActiveGame($context, $existingGame);
            $abandonMsg = "⚠️ Ancienne partie abandonnee (mot : *{$existingGame->word}*)\n\n";
        }

        $replayMsg = "{$abandonMsg}🔁 *Rejouer le dernier mot !* (precedemment : {$icon} — {$errors}/{$maxWrong} erreurs)\n\n";

        $game  = HangmanGame::create([
            'user_phone'      => $context->from,
            'agent_id'        => $context->agent->id,
            'word'            => $lastGame->word,
            'guessed_letters' => [],
            'wrong_count'     => 0,
            'status'          => 'playing',
        ]);

        $board   = $this->getDisplayBoard($game);
        $wordLen = mb_strlen($game->word);

        return AgentResult::reply(
            "{$replayMsg}🎮 *Nouvelle partie de Pendu !*\n"
            . "📏 Mot de *{$wordLen}* lettre(s)\n\n"
            . "{$board}\n\n"
            . "Envoie une lettre pour deviner !\n"
            . "💡 /hangman hint (indice, -1 vie) | /hangman score (score estime)"
        );
    }

    private function showTip(AgentContext $context): AgentResult
    {
        $game = $this->getActiveGame($context);

        if (!$game) {
            return AgentResult::reply("Pas de partie en cours. Envoie /hangman start pour commencer !\n\n💡 /hangman tip sera disponible une fois ta partie lancee.");
        }

        $word        = mb_strtoupper($game->word);
        $wordNorm    = $this->normalizeWordAccents($word);
        $wordLen     = mb_strlen($word);
        $errors      = $game->wrong_count;
        $livesLeft   = self::MAX_WRONG - $errors;
        $guessedNorm = array_map(
            fn ($l) => $this->normalizeAccents(mb_strtoupper($l)),
            $game->guessed_letters ?? []
        );

        // Analyze hidden unique letters
        $vowels           = ['A', 'E', 'I', 'O', 'U'];
        $hiddenLetters    = [];
        $hiddenVowels     = 0;
        $hiddenConsonants = 0;

        for ($i = 0; $i < mb_strlen($wordNorm); $i++) {
            $char = mb_substr($wordNorm, $i, 1);
            if (!preg_match('/\pL/u', $char) || in_array($char, $hiddenLetters)) {
                continue;
            }
            if (!in_array($char, $guessedNorm)) {
                $hiddenLetters[] = $char;
                if (in_array($char, $vowels)) {
                    $hiddenVowels++;
                } else {
                    $hiddenConsonants++;
                }
            }
        }

        $uniqueHidden = count($hiddenLetters);

        // Most frequent French letters not yet tried (descending frequency order)
        $frenchFreq  = ['E', 'A', 'I', 'S', 'N', 'T', 'R', 'U', 'L', 'O', 'D', 'C', 'M', 'P', 'V'];
        $suggestions = array_values(array_filter($frenchFreq, fn ($l) => !in_array($l, $guessedNorm)));
        $topSugg     = array_slice($suggestions, 0, 3);

        $board = $this->getDisplayBoard($game);
        $tips  = [];

        // Vowel / consonant balance
        if ($uniqueHidden === 0) {
            $tips[] = "✅ Toutes les lettres uniques du mot ont ete trouvees — propose le mot complet avec /hangman devine MOT !";
        } elseif ($hiddenVowels === 0) {
            $tips[] = "🎯 Toutes les voyelles sont trouvees ! Concentre-toi sur les consonnes.";
        } else {
            $tips[] = "🔡 Il reste *{$hiddenVowels}* voyelle(s) cachee(s) — commence par celles-la !";
        }

        // Most frequent letters suggestion
        if (!empty($topSugg)) {
            $tips[] = "📊 Lettres les + frequentes en francais non essayees : *" . implode(', ', $topSugg) . "*";
        }

        // Unique hidden count
        if ($uniqueHidden > 0) {
            $tips[] = "🔍 *{$uniqueHidden}* lettre(s) unique(s) encore cachee(s) dans ce mot de *{$wordLen}* lettres";
        }

        // Consonant dominance
        if ($hiddenConsonants > $hiddenVowels + 1) {
            $tips[] = "📝 Le mot cache encore beaucoup de consonnes — les voyelles sont presque completement decouvertes !";
        } elseif ($hiddenVowels > $hiddenConsonants + 1) {
            $tips[] = "📝 Le mot a encore beaucoup de voyelles cachees — priorite aux voyelles !";
        }

        // Life warning
        if ($livesLeft <= 2 && $uniqueHidden > 0) {
            $tips[] = "⚠️ *Attention !* Seulement *{$livesLeft}* vie(s) restante(s) — reflechis bien avant de proposer !";
        }

        $tipsText = implode("\n", $tips);

        return AgentResult::reply(
            "🧠 *Astuce strategique* (gratuite — aucune vie perdue) :\n\n"
            . $tipsText
            . "\n\n{$board}\n\n"
            . "_💡 /hangman hint pour voir une lettre revelee (-1 vie) | /hangman alpha pour l'alphabet restant_"
        );
    }

    // ── Category & difficulty stats ───────────────────────────────────────────

    private function showCategoryStats(AgentContext $context, ?string $requestedCat = null): AgentResult
    {
        $resolvedCat = $requestedCat ? $this->resolveCategory($requestedCat) : null;

        $games = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->get();

        if ($games->isEmpty()) {
            return AgentResult::reply("📊 Aucune partie terminee.\n\nEnvoie /hangman start pour jouer !");
        }

        // Build per-category counts
        $catData = [];
        foreach (array_keys(self::CATEGORY_LABELS) as $cat) {
            $catData[$cat] = ['played' => 0, 'won' => 0, 'best_score' => 0, 'best_word' => null];
        }
        $catData['custom'] = ['played' => 0, 'won' => 0, 'best_score' => 0, 'best_word' => null];

        foreach ($games as $game) {
            $cat = null;
            foreach (self::WORD_CATEGORIES as $c => $words) {
                if (in_array($game->word, $words)) {
                    $cat = $c;
                    break;
                }
            }
            $cat = $cat ?? 'custom';
            $catData[$cat]['played']++;
            if ($game->status === 'won') {
                $catData[$cat]['won']++;
                $score = $this->computeScore($game);
                if ($score > $catData[$cat]['best_score']) {
                    $catData[$cat]['best_score'] = $score;
                    $catData[$cat]['best_word']  = $game->word;
                }
            }
        }

        // Single category detail
        if ($resolvedCat) {
            $data  = $catData[$resolvedCat] ?? null;
            $label = self::CATEGORY_LABELS[$resolvedCat];
            if (!$data || $data['played'] === 0) {
                return AgentResult::reply("📊 *{$label}* — Aucune partie dans cette categorie.\n\n/hangman start {$resolvedCat} pour jouer !");
            }
            $losses  = $data['played'] - $data['won'];
            $wr      = round(($data['won'] / $data['played']) * 100);
            $bar     = $this->generateProgressBar($wr);
            $bestMsg = $data['best_word']
                ? "\n🏅 Meilleur score : *{$data['best_score']} pts* (mot : {$data['best_word']})"
                : '';

            return AgentResult::reply(
                "📊 *Stats — {$label}*\n\n"
                . "🎮 Parties : *{$data['played']}*\n"
                . "🏆 Victoires : *{$data['won']}*\n"
                . "💀 Defaites : *{$losses}*\n"
                . "📈 Taux : *{$wr}%* {$bar}{$bestMsg}\n\n"
                . "_/hangman start {$resolvedCat} pour jouer dans cette categorie_"
            );
        }

        // Overview: all categories
        $lines = ["📊 *Stats par categorie :*\n"];
        foreach (self::CATEGORY_LABELS as $cat => $label) {
            $data = $catData[$cat];
            if ($data['played'] === 0) {
                continue;
            }
            $wr      = round(($data['won'] / $data['played']) * 100);
            $bar     = $this->generateProgressBar($wr);
            $lines[] = "{$label}\n   🎮 {$data['played']} parties · 🏆 {$data['won']} victoires · {$wr}% {$bar}";
        }

        if ($catData['custom']['played'] > 0) {
            $d       = $catData['custom'];
            $wr      = round(($d['won'] / $d['played']) * 100);
            $bar     = $this->generateProgressBar($wr);
            $lines[] = "✍️ Personnalise\n   🎮 {$d['played']} parties · 🏆 {$d['won']} victoires · {$wr}% {$bar}";
        }

        if (count($lines) === 1) {
            return AgentResult::reply("📊 Aucune partie terminee.\n\nEnvoie /hangman start pour jouer !");
        }

        $lines[] = "\n_/hangman cat tech pour les details d'une categorie | /hangman diff pour les niveaux_";

        return AgentResult::reply(implode("\n", $lines));
    }

    private function showDifficultyStats(AgentContext $context): AgentResult
    {
        $games = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->get();

        if ($games->isEmpty()) {
            return AgentResult::reply("📊 Aucune partie terminee.\n\nEnvoie /hangman start pour jouer !");
        }

        $levels = [
            'easy'   => ['label' => '🟢 Facile (2-6 lettres)',    'played' => 0, 'won' => 0, 'best_score' => 0, 'best_word' => null],
            'medium' => ['label' => '🟡 Moyen (7-10 lettres)',    'played' => 0, 'won' => 0, 'best_score' => 0, 'best_word' => null],
            'hard'   => ['label' => '🔴 Difficile (11+ lettres)', 'played' => 0, 'won' => 0, 'best_score' => 0, 'best_word' => null],
        ];

        foreach ($games as $game) {
            $wordLen = mb_strlen($game->word);
            $level   = match (true) {
                $wordLen >= 11 => 'hard',
                $wordLen >= 7  => 'medium',
                default        => 'easy',
            };
            $levels[$level]['played']++;
            if ($game->status === 'won') {
                $levels[$level]['won']++;
                $score = $this->computeScore($game);
                if ($score > $levels[$level]['best_score']) {
                    $levels[$level]['best_score'] = $score;
                    $levels[$level]['best_word']  = $game->word;
                }
            }
        }

        $lines = ["🎯 *Stats par niveau de difficulte :*\n"];
        foreach ($levels as $key => $data) {
            if ($data['played'] === 0) {
                continue;
            }
            $losses  = $data['played'] - $data['won'];
            $wr      = round(($data['won'] / $data['played']) * 100);
            $bar     = $this->generateProgressBar($wr);
            $bestMsg = $data['best_word']
                ? " · 🏅 {$data['best_score']} pts max ({$data['best_word']})"
                : '';
            $lines[] = "*{$data['label']}*\n   🎮 {$data['played']} · 🏆 {$data['won']} · 💀 {$losses} · {$wr}% {$bar}{$bestMsg}";
        }

        if (count($lines) === 1) {
            return AgentResult::reply("📊 Aucune partie terminee.\n\nEnvoie /hangman start pour jouer !");
        }

        $lines[] = "\n_/hangman start facile|moyen|difficile pour choisir un niveau_";

        return AgentResult::reply(implode("\n", $lines));
    }

    // ── Post-game analysis ────────────────────────────────────────────────────

    private function showPostGameAnalysis(AgentContext $context): AgentResult
    {
        $lastGame = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->latest()
            ->first();

        if (!$lastGame) {
            return AgentResult::reply(
                "Aucune partie terminee a analyser.\n\nEnvoie /hangman start pour jouer !"
            );
        }

        $word    = $lastGame->word;
        $wordLen = mb_strlen($word);
        $errors  = $lastGame->wrong_count;
        $guessed = $lastGame->guessed_letters ?? [];
        $status  = $lastGame->status;
        $score   = $status === 'won' ? $this->computeScore($lastGame) : 0;
        $dateStr = $lastGame->updated_at ? $lastGame->updated_at->format('d/m/Y') : '?';

        // Categorise guessed letters into correct/wrong
        $wordNorm       = $this->normalizeWordAccents(mb_strtoupper($word));
        $correctLetters = [];
        $wrongLetters   = [];

        foreach ($guessed as $letter) {
            $letterNorm = $this->normalizeAccents(mb_strtoupper($letter));
            if (mb_strpos($wordNorm, $letterNorm) !== false) {
                $correctLetters[] = mb_strtoupper($letter);
            } else {
                $wrongLetters[] = mb_strtoupper($letter);
            }
        }

        $totalGuessed = count($guessed);
        $efficiency   = $totalGuessed > 0 ? round((count($correctLetters) / $totalGuessed) * 100) : 0;

        // Vowel-first strategy check
        $vowels          = ['A', 'E', 'I', 'O', 'U'];
        $firstVowelPos   = null;
        $firstConsonPos  = null;
        foreach (array_values($guessed) as $pos => $letter) {
            $l = mb_strtoupper($letter);
            if ($firstVowelPos === null && in_array($l, $vowels)) {
                $firstVowelPos = $pos + 1;
            }
            if ($firstConsonPos === null && !in_array($l, $vowels)) {
                $firstConsonPos = $pos + 1;
            }
        }

        $icon   = $status === 'won' ? '🏆' : '💀';
        $result = $status === 'won' ? 'Victoire' : 'Defaite';

        $response = "🔬 *Analyse de partie — {$icon} {$result} ({$dateStr})*\n\n"
            . "🔤 Mot : *{$word}* ({$wordLen} lettres)\n"
            . "❌ Erreurs : *{$errors}/" . self::MAX_WRONG . "*\n";

        if ($status === 'won') {
            $response .= "🏅 Score : *{$score} pts*\n";
        }

        $response .= "\n*📊 Statistiques de ta partie :*\n"
            . "• Lettres essayees : *{$totalGuessed}*\n"
            . "• Bonnes lettres : *" . count($correctLetters) . "*"
            . (!empty($correctLetters) ? ' (' . implode(', ', $correctLetters) . ')' : '') . "\n"
            . "• Mauvaises lettres : *" . count($wrongLetters) . "*"
            . (!empty($wrongLetters) ? ' (' . implode(', ', $wrongLetters) . ')' : '') . "\n"
            . "• Efficacite : *{$efficiency}%*\n";

        // Strategic feedback tips
        $tips = [];

        if ($errors === 0 && $status === 'won') {
            $tips[] = "🌟 *Parfait !* Tu as trouve le mot sans aucune erreur !";
        } elseif ($errors <= 2 && $status === 'won') {
            $tips[] = "👍 *Tres bien joue !* Peu d'erreurs pour ce mot.";
        } elseif ($errors >= 4 && $status === 'won') {
            $tips[] = "😅 *Victoire au bout du suspense !* Tu pourrais ameliorer ta strategie de depart.";
        }

        // Vowel strategy
        if ($firstConsonPos !== null && ($firstVowelPos === null || $firstConsonPos < $firstVowelPos) && $firstVowelPos > 3) {
            $tips[] = "💡 *Conseil :* Tu as commence par des consonnes. Commencer par E, A, I, S, N est souvent plus efficace en francais.";
        } elseif ($firstVowelPos !== null && $firstVowelPos <= 2) {
            $tips[] = "✅ *Bonne strategie !* Tu as attaque avec les voyelles.";
        }

        // Efficiency feedback
        if ($efficiency < 50 && $totalGuessed >= 5) {
            $tips[] = "📈 *Efficacite faible ({$efficiency}%) :* Pense aux lettres frequentes du francais : E, A, I, S, N, T, R.";
        } elseif ($efficiency >= 80 && $totalGuessed >= 3) {
            $tips[] = "🎯 *Excellente precision ({$efficiency}%) !* Tes choix de lettres etaient tres pertinents.";
        }

        // Wrong letters that were statistically poor choices
        $frenchRare = ['X', 'K', 'W', 'Q', 'J', 'Z'];
        $poorChoices = array_intersect($wrongLetters, $frenchRare);
        if (!empty($poorChoices)) {
            $tips[] = "⚠️ *Lettres rares essayees :* " . implode(', ', $poorChoices) . " — ces lettres sont peu frequentes en francais.";
        }

        if (!empty($tips)) {
            $response .= "\n*🧠 Conseils pour la prochaine partie :*\n" . implode("\n", $tips);
        }

        $response .= "\n\n_/hangman start pour rejouer | /hangman stats pour les stats globales_";

        return AgentResult::reply($response);
    }

    // ── Daily goals ───────────────────────────────────────────────────────────

    private function showGoals(AgentContext $context): AgentResult
    {
        $today = Carbon::today()->startOfDay();

        // Goal 1: Play 3 games today
        $gamesPlayedToday = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->where('updated_at', '>=', $today)
            ->count();
        $goal1Target = 3;
        $goal1Done   = $gamesPlayedToday >= $goal1Target;
        $goal1Icon   = $goal1Done ? '✅' : '🔄';
        $goal1Bar    = min($gamesPlayedToday, $goal1Target) . '/' . $goal1Target;

        // Goal 2: Win 2 games today
        $gamesWonToday = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'won')
            ->where('updated_at', '>=', $today)
            ->count();
        $goal2Target = 2;
        $goal2Done   = $gamesWonToday >= $goal2Target;
        $goal2Icon   = $goal2Done ? '✅' : '🔄';
        $goal2Bar    = min($gamesWonToday, $goal2Target) . '/' . $goal2Target;

        // Goal 3: Score 100+ pts in a single game today
        $bestToday     = 0;
        $wonGamesToday = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'won')
            ->where('updated_at', '>=', $today)
            ->get(['word', 'wrong_count', 'created_at', 'updated_at']);
        foreach ($wonGamesToday as $game) {
            $s = $this->computeScore($game);
            if ($s > $bestToday) {
                $bestToday = $s;
            }
        }
        $goal3Target = 100;
        $goal3Done   = $bestToday >= $goal3Target;
        $goal3Icon   = $goal3Done ? '✅' : '🔄';
        $goal3Bar    = $bestToday . '/' . $goal3Target . ' pts';

        $completedCount = ($goal1Done ? 1 : 0) + ($goal2Done ? 1 : 0) + ($goal3Done ? 1 : 0);
        $totalGoals     = 3;
        $dateStr        = Carbon::today()->format('d/m/Y');

        $response = "🎯 *Objectifs du jour — {$dateStr}*\n"
            . "_{$completedCount}/{$totalGoals} objectif(s) complete(s)_\n\n"
            . "{$goal1Icon} Jouer *{$goal1Target}* parties ({$goal1Bar})\n"
            . "{$goal2Icon} Gagner *{$goal2Target}* parties ({$goal2Bar})\n"
            . "{$goal3Icon} Scorer *100+ pts* dans une partie ({$goal3Bar})\n";

        if ($completedCount === $totalGoals) {
            $response .= "\n🌟 *Bravo ! Tous les objectifs du jour accomplis !*";
        } elseif ($completedCount >= 2) {
            $response .= "\n💪 *Presque ! Encore " . ($totalGoals - $completedCount) . " objectif(s) a completer.*";
        } elseif ($completedCount >= 1) {
            $response .= "\n🎮 *Bon debut ! Continue pour tous les completer.*";
        } else {
            $response .= "\n🎮 *Lance une partie pour commencer tes objectifs !*";
        }

        $response .= "\n\n_Les objectifs se renouvellent chaque jour a minuit | /hangman start_";

        return AgentResult::reply($response);
    }

    // ── Word length game ──────────────────────────────────────────────────────

    private function showWordlenGame(AgentContext $context, int $requestedLen): AgentResult
    {
        if ($requestedLen < 2 || $requestedLen > 30) {
            return AgentResult::reply(
                "❌ Longueur invalide (*{$requestedLen}*). Choisis entre *2* et *30* lettres.\n\n"
                . "Exemple : /hangman wordlen 7"
            );
        }

        // Collect all words matching the exact length
        $candidates = [];
        foreach (self::WORD_CATEGORIES as $cat => $words) {
            foreach ($words as $word) {
                if (mb_strlen($word) === $requestedLen) {
                    $candidates[] = [$word, $cat];
                }
            }
        }

        $closestMsg = '';
        if (empty($candidates)) {
            // Find all available lengths and pick the closest
            $available = [];
            foreach (self::WORD_CATEGORIES as $words) {
                foreach ($words as $word) {
                    $available[mb_strlen($word)] = true;
                }
            }
            $lengths = array_keys($available);
            sort($lengths);

            $closest = $lengths[0];
            foreach ($lengths as $len) {
                if (abs($len - $requestedLen) < abs($closest - $requestedLen)) {
                    $closest = $len;
                }
            }

            foreach (self::WORD_CATEGORIES as $cat => $words) {
                foreach ($words as $word) {
                    if (mb_strlen($word) === $closest) {
                        $candidates[] = [$word, $cat];
                    }
                }
            }

            $availableStr = implode(', ', array_slice($lengths, 0, 10)) . (count($lengths) > 10 ? '…' : '');
            $closestMsg   = "⚠️ Aucun mot de *{$requestedLen}* lettres disponible.\n"
                . "Longueurs dispo : {$availableStr}\n"
                . "Mot le plus proche ({$closest} lettres) choisi.\n\n";
        }

        shuffle($candidates);
        [$word, $category] = $candidates[0];

        $catLabel = ' | ' . self::CATEGORY_LABELS[$category];

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

        $board   = $this->getDisplayBoard($game);
        $wordLen = mb_strlen($word);

        $this->log($context, 'Wordlen game started', [
            'game_id'       => $game->id,
            'requested_len' => $requestedLen,
            'actual_len'    => $wordLen,
            'category'      => $category,
        ]);

        return AgentResult::reply(
            "{$abandonMsg}{$closestMsg}🎮 *Nouvelle partie de Pendu !*{$catLabel}\n"
            . "📏 Mot de *{$wordLen}* lettre(s)\n\n"
            . "{$board}\n\n"
            . "Envoie une lettre pour deviner !\n"
            . "💡 /hangman hint | /hangman devine MOT | /hangman wordlen [n] pour changer"
        );
    }

    // ── Progression report ────────────────────────────────────────────────────

    private function showProgress(AgentContext $context): AgentResult
    {
        $allGames = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['won', 'lost'])
            ->orderBy('updated_at')
            ->get();

        $total = $allGames->count();

        if ($total < 4) {
            return AgentResult::reply(
                "📈 *Progression Pendu*\n\n"
                . "Pas encore assez de parties pour analyser ta progression.\n"
                . "_(il en faut au moins 4, tu en as {$total})_\n\n"
                . "/hangman start pour continuer !"
            );
        }

        // Split into first half vs second half
        $half      = (int) ceil($total / 2);
        $firstHalf = $allGames->slice(0, $half);
        $lastHalf  = $allGames->slice($half);

        // Win rates
        $firstWon      = $firstHalf->where('status', 'won')->count();
        $firstPlayed   = $firstHalf->count();
        $firstWinRate  = $firstPlayed > 0 ? round(($firstWon / $firstPlayed) * 100) : 0;

        $lastWon       = $lastHalf->where('status', 'won')->count();
        $lastPlayed    = $lastHalf->count();
        $lastWinRate   = $lastPlayed > 0 ? round(($lastWon / $lastPlayed) * 100) : 0;

        // Average errors on won games
        $firstWonGames  = $firstHalf->where('status', 'won');
        $lastWonGames   = $lastHalf->where('status', 'won');
        $firstAvgErrors = $firstWonGames->isNotEmpty() ? round($firstWonGames->avg('wrong_count'), 1) : null;
        $lastAvgErrors  = $lastWonGames->isNotEmpty() ? round($lastWonGames->avg('wrong_count'), 1) : null;

        $firstBar = $this->generateProgressBar($firstWinRate);
        $lastBar  = $this->generateProgressBar($lastWinRate);

        // Trend analysis
        $winDiff = $lastWinRate - $firstWinRate;
        [$trendMsg, $trendEmoji] = match (true) {
            $winDiff >= 15 => ["📈 *Progression fulgurante !* Continue comme ca !", '🌟'],
            $winDiff >= 5  => ["📈 *En progression* — tu t'ameliores !", '✅'],
            $winDiff <= -15 => ["📉 *En regression* — revois ta strategie.", '💪'],
            $winDiff <= -5  => ["📉 *Leger recul* — rien de grave, continue !", '🎯'],
            default         => ["➡️ *Stable* — niveau constant.", '⭐'],
        };

        $errTrend = '';
        if ($firstAvgErrors !== null && $lastAvgErrors !== null) {
            $errDiff = round($firstAvgErrors - $lastAvgErrors, 1);
            if ($errDiff >= 0.5) {
                $errTrend = "\n💡 Erreurs moy. en baisse : {$firstAvgErrors} → {$lastAvgErrors} (-{$errDiff}) ✅";
            } elseif ($errDiff <= -0.5) {
                $absDiff  = abs($errDiff);
                $errTrend = "\n⚠️ Erreurs moy. en hausse : {$firstAvgErrors} → {$lastAvgErrors} (+{$absDiff})";
            }
        }

        $diffSign  = $winDiff >= 0 ? '+' : '';
        $firstDate = $firstHalf->first()->updated_at->format('d/m');
        $firstEnd  = $firstHalf->last()->updated_at->format('d/m');
        $lastStart = $lastHalf->first()->updated_at->format('d/m');
        $lastDate  = $lastHalf->last()->updated_at->format('d/m');

        $errFirstStr = $firstAvgErrors !== null ? (string) $firstAvgErrors : 'N/A';
        $errLastStr  = $lastAvgErrors !== null ? (string) $lastAvgErrors : 'N/A';

        $response = "📈 *Ta Progression Pendu* {$trendEmoji}\n"
            . "_{$total} parties analysees_\n\n"
            . "*Debut* (parties 1-{$firstPlayed} | {$firstDate}–{$firstEnd})\n"
            . "   🏆 Win rate : *{$firstWinRate}%* {$firstBar}\n"
            . "   🎯 Moy. erreurs/victoire : *{$errFirstStr}*\n\n"
            . "*Recent* (parties " . ($firstPlayed + 1) . "-{$total} | {$lastStart}–{$lastDate})\n"
            . "   🏆 Win rate : *{$lastWinRate}%* {$lastBar}\n"
            . "   🎯 Moy. erreurs/victoire : *{$errLastStr}*\n\n"
            . "{$trendMsg} ({$diffSign}{$winDiff}% win rate){$errTrend}\n\n"
            . "_/hangman stats pour les stats globales | /hangman start pour continuer !_";

        return AgentResult::reply($response);
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
            . "{\"action\": \"start|guess|guess_word|stats|cat|diff|hint|abandon|status|history|reset|categories|daily|leaderboard|alphabet|score|replay|best|streak|weekly|monthly|share|tip|analyse|goals|wordlen|progress|help\", \"letter\": \"X\", \"word\": \"MOT\", \"category\": \"tech|animaux|nature|vocab|sport|gastronomie|geographie\", \"difficulty\": \"easy|medium|hard\", \"length\": 7}\n\n"
            . "Actions disponibles:\n"
            . "- start       = nouvelle partie (\"category\": tech|animaux|nature|vocab|sport|gastronomie|geographie) (\"difficulty\": easy|medium|hard)\n"
            . "- guess       = deviner une lettre (inclure \"letter\": \"X\")\n"
            . "- guess_word  = deviner le mot entier (inclure \"word\": \"MOT\")\n"
            . "- stats       = voir ses statistiques globales\n"
            . "- cat         = stats par categorie (optionnel: \"category\": tech|animaux|nature|vocab|sport|gastronomie|geographie)\n"
            . "- diff        = stats par niveau de difficulte\n"
            . "- weekly      = voir les stats de la semaine (7 derniers jours)\n"
            . "- monthly     = voir les stats du mois (30 derniers jours)\n"
            . "- hint        = demander un indice (revele une lettre-voyelle en priorite, coute -1 vie)\n"
            . "- tip         = astuce strategique gratuite (analyse voyelles/consonnes cachees, lettres frequentes)\n"
            . "- abandon     = abandonner/quitter la partie en cours\n"
            . "- status      = voir l'etat actuel de la partie\n"
            . "- history     = voir l'historique des dernieres parties\n"
            . "- reset       = reinitialiser les statistiques (demande confirmation)\n"
            . "- categories  = lister les categories de mots disponibles\n"
            . "- daily       = lancer le defi du jour (meme mot pour tous)\n"
            . "- leaderboard = voir le classement des meilleurs joueurs\n"
            . "- alphabet    = voir les lettres de l'alphabet pas encore essayees\n"
            . "- score       = voir le score estime si on gagne maintenant (avec multiplicateur de difficulte)\n"
            . "- replay      = rejouer le dernier mot perdu\n"
            . "- best        = voir sa meilleure partie (meilleur score)\n"
            . "- streak      = voir sa serie de victoires actuelle et son record\n"
            . "- share       = partager le resultat de la derniere partie (style Wordle)\n"
            . "- analyse      = analyser la derniere partie terminee (efficacite, erreurs, conseils)\n"
            . "- goals        = voir les objectifs quotidiens (3 defis du jour)\n"
            . "- wordlen      = lancer une partie avec un mot d'exactement N lettres (inclure \"length\": N)\n"
            . "- progress     = voir sa progression personnelle (comparaison debut vs recent)\n"
            . "- help         = afficher l'aide et les commandes\n\n"
            . "Exemples:\n"
            . "  \"donne moi un indice\" -> {\"action\":\"hint\"}\n"
            . "  \"donne moi une astuce\" -> {\"action\":\"tip\"}\n"
            . "  \"conseil strategique\" -> {\"action\":\"tip\"}\n"
            . "  \"je veux arreter\" -> {\"action\":\"abandon\"}\n"
            . "  \"historique\" -> {\"action\":\"history\"}\n"
            . "  \"remet mes stats a zero\" -> {\"action\":\"reset\"}\n"
            . "  \"le mot est LARAVEL\" -> {\"action\":\"guess_word\",\"word\":\"LARAVEL\"}\n"
            . "  \"liste les categories\" -> {\"action\":\"categories\"}\n"
            . "  \"jouer sport\" -> {\"action\":\"start\",\"category\":\"sport\"}\n"
            . "  \"jouer en mode facile\" -> {\"action\":\"start\",\"difficulty\":\"easy\"}\n"
            . "  \"partie difficile tech\" -> {\"action\":\"start\",\"category\":\"tech\",\"difficulty\":\"hard\"}\n"
            . "  \"defi du jour\" -> {\"action\":\"daily\"}\n"
            . "  \"classement\" -> {\"action\":\"leaderboard\"}\n"
            . "  \"quelles lettres restent\" -> {\"action\":\"alphabet\"}\n"
            . "  \"quel est mon score actuel\" -> {\"action\":\"score\"}\n"
            . "  \"rejouer le dernier mot\" -> {\"action\":\"replay\"}\n"
            . "  \"ma meilleure partie\" -> {\"action\":\"best\"}\n"
            . "  \"ma serie\" -> {\"action\":\"streak\"}\n"
            . "  \"mes stats cette semaine\" -> {\"action\":\"weekly\"}\n"
            . "  \"mes stats ce mois\" -> {\"action\":\"monthly\"}\n"
            . "  \"partager mon resultat\" -> {\"action\":\"share\"}\n"
            . "  \"analyse ma derniere partie\" -> {\"action\":\"analyse\"}\n"
            . "  \"mes objectifs du jour\" -> {\"action\":\"goals\"}\n"
            . "  \"un mot de 7 lettres\" -> {\"action\":\"wordlen\",\"length\":7}\n"
            . "  \"joue avec un mot de 10 lettres\" -> {\"action\":\"wordlen\",\"length\":10}\n"
            . "  \"ma progression\" -> {\"action\":\"progress\"}\n"
            . "  \"est ce que je progresse\" -> {\"action\":\"progress\"}\n"
            . "  \"pendu geographie\" -> {\"action\":\"start\",\"category\":\"geographie\"}\n"
            . "  \"aide moi\" -> {\"action\":\"help\"}\n"
            . "  \"je propose la lettre E\" -> {\"action\":\"guess\",\"letter\":\"E\"}\n"
            . "  \"mes stats par categorie\" -> {\"action\":\"cat\"}\n"
            . "  \"mes perf en tech\" -> {\"action\":\"cat\",\"category\":\"tech\"}\n"
            . "  \"stats par niveau\" -> {\"action\":\"diff\"}\n"
            . "  \"combien de parties en mode facile\" -> {\"action\":\"diff\"}\n"
            . "Reponds UNIQUEMENT avec le JSON, sans markdown."
        );

        $parsed = json_decode(trim($response ?? ''), true);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp($activeGame);
        }

        $nlCategory   = $this->resolveCategory($parsed['category'] ?? '');
        $nlDifficulty = isset($parsed['difficulty']) ? (self::DIFFICULTY_ALIASES[$parsed['difficulty']] ?? (isset(self::DIFFICULTY_RANGES[$parsed['difficulty']]) ? $parsed['difficulty'] : null)) : null;

        return match ($parsed['action']) {
            'start'       => $this->startGame($context, null, $nlCategory, $nlDifficulty),
            'guess'       => isset($parsed['letter'])
                ? $this->guessLetter($context, mb_strtoupper($parsed['letter']))
                : AgentResult::reply("Quelle lettre veux-tu proposer ?"),
            'guess_word'  => isset($parsed['word'])
                ? $this->guessWord($context, $parsed['word'])
                : AgentResult::reply("Quel mot veux-tu proposer ?"),
            'stats'       => $this->showStats($context),
            'cat'         => $this->showCategoryStats($context, $nlCategory),
            'diff'        => $this->showDifficultyStats($context),
            'hint'        => $this->hint($context),
            'abandon'     => $this->abandon($context),
            'status'      => $this->status($context),
            'history'     => $this->showHistory($context),
            'reset'       => $this->resetStats($context),
            'categories'  => $this->showCategories(),
            'daily'       => $this->dailyChallenge($context),
            'leaderboard' => $this->showLeaderboard($context),
            'alphabet'    => $this->showAlphabet($context),
            'score'       => $this->showCurrentScore($context),
            'replay'      => $this->replayLastGame($context),
            'best'        => $this->showBestGame($context),
            'streak'      => $this->showStreak($context),
            'weekly'      => $this->showWeeklyStats($context),
            'monthly'     => $this->showMonthlyStats($context),
            'share'       => $this->showShareResult($context),
            'tip'         => $this->showTip($context),
            'analyse'     => $this->showPostGameAnalysis($context),
            'goals'       => $this->showGoals($context),
            'wordlen'     => isset($parsed['length']) && is_numeric($parsed['length'])
                ? $this->showWordlenGame($context, (int) $parsed['length'])
                : AgentResult::reply("Quelle longueur de mot souhaites-tu ? Ex : /hangman wordlen 7"),
            'progress'    => $this->showProgress($context),
            'help'        => $this->showHelp($activeGame),
            default       => $this->showHelp($activeGame),
        };
    }

    private function showHelp(?HangmanGame $activeGame = null): AgentResult
    {
        $help = "🎮 *Jeu du Pendu - Commandes :*\n\n"
            . "▶️ /hangman start → Nouvelle partie\n"
            . "🗂️ /hangman start [categorie] → Choisir une categorie\n"
            . "   └ tech | animaux | nature | vocab | sport | gastronomie | geographie\n"
            . "🎯 /hangman start [difficulte] → Choisir un niveau\n"
            . "   └ facile (2-6 lettres) | moyen (7-10) | difficile (11+)\n"
            . "📅 /hangman daily → Defi du jour (meme mot pour tous)\n"
            . "🔄 /hangman replay → Rejouer le dernier mot\n"
            . "🔤 /hangman guess X → Proposer la lettre X\n"
            . "🎯 /hangman devine MOT → Deviner le mot entier (-2 vies si faux)\n"
            . "💡 /hangman hint → Indice (voyelle + position, -1 vie)\n"
            . "🧠 /hangman tip → Astuce strategique gratuite (0 vie perdue)\n"
            . "🔡 /hangman alpha → Lettres de l'alphabet non essayees\n"
            . "📊 /hangman score → Score estime (avec multiplicateur de difficulte)\n"
            . "✏️ /hangman word MOT → Partie avec mot personnalise\n"
            . "📋 /hangman status → Voir la partie en cours\n"
            . "📜 /hangman history → Historique des parties\n"
            . "🏳️ /hangman abandon → Abandonner la partie\n"
            . "📊 /hangman stats → Tes statistiques globales\n"
            . "🗂️ /hangman cat [categorie] → Stats par categorie (tech|animaux|nature|...)\n"
            . "🎯 /hangman diff → Stats par niveau de difficulte\n"
            . "📅 /hangman weekly → Stats de la semaine (7 derniers jours)\n"
            . "📆 /hangman monthly → Stats du mois (30 derniers jours)\n"
            . "🏅 /hangman best → Ta meilleure partie\n"
            . "🔥 /hangman streak → Ta serie de victoires\n"
            . "🏆 /hangman top → Classement des meilleurs joueurs\n"
            . "🗂️ /hangman categories → Lister les categories\n"
            . "📋 /hangman share → Partager ton dernier resultat\n"
            . "🔬 /hangman analyse → Analyser ta derniere partie (efficacite, erreurs, conseils)\n"
            . "🎯 /hangman goals → Objectifs quotidiens (3 defis, se renouvellent chaque jour)\n"
            . "📏 /hangman wordlen N → Partie avec un mot d'exactement N lettres\n"
            . "📈 /hangman progress → Ta progression (debut vs recent, win rate, erreurs)\n"
            . "🔁 /hangman reset → Reinitialiser les stats (avec confirmation)\n\n"
            . "💡 Pendant une partie : envoie une lettre ou un mot directement !\n"
            . "💡 Les accents sont acceptes : e/é/è/ê → E (mots personnalises aussi)\n"
            . "💡 Lettre trouvee : position(s) affichee(s) dans le mot !\n"
            . "🎯 Score : ×1.0 facile | ×1.2 moyen | ×1.5 difficile";

        if ($activeGame) {
            $board = $this->getDisplayBoard($activeGame);
            $help .= "\n\n--- Partie en cours ---\n\n{$board}";
        }

        return AgentResult::reply($help);
    }
}
