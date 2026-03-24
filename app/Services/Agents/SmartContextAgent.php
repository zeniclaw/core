<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\ContextMemory\ContextStore;
use Illuminate\Support\Facades\Log;

class SmartContextAgent extends BaseAgent
{
    private ContextStore $contextStore;

    /** Minimum confidence score for a fact to be stored */
    private const MIN_SCORE = 0.3;

    /** Maximum facts extracted per message */
    private const MAX_FACTS_PER_MESSAGE = 5;

    /** Score boost applied when an existing fact is confirmed again (same key + same value) */
    private const REINFORCEMENT_BOOST = 0.1;

    /** Similarity threshold (%) above which fact values are considered equivalent for reinforcement */
    private const SIMILARITY_THRESHOLD = 80;

    /** Age (seconds) after which a fact is considered stale in summarizeProfile */
    private const STALE_AGE = 86400 * 30; // 30 days

    /** Score decay per day applied to facts older than DECAY_START_DAYS */
    private const SCORE_DECAY_PER_DAY = 0.005;

    /** Age in days after which score decay starts being applied */
    private const DECAY_START_DAYS = 60;

    /** Valid fact categories */
    private const VALID_CATEGORIES = [
        'profession', 'preference', 'personal',
        'project', 'behavior', 'goal', 'skill', 'general',
    ];

    /** French label → internal category key mapping for forget_category command */
    private const CATEGORY_FR_MAP = [
        'profession'    => 'profession',
        'professions'   => 'profession',
        'metier'        => 'profession',
        'metiers'       => 'profession',
        'competence'    => 'skill',
        'competences'   => 'skill',
        'skill'         => 'skill',
        'skills'        => 'skill',
        'preference'    => 'preference',
        'preferences'   => 'preference',
        'gout'          => 'preference',
        'gouts'         => 'preference',
        'loisirs'       => 'preference',
        'personnel'     => 'personal',
        'personnelles'  => 'personal',
        'personnels'    => 'personal',
        'perso'         => 'personal',
        'personal'      => 'personal',
        'projet'        => 'project',
        'projets'       => 'project',
        'project'       => 'project',
        'projects'      => 'project',
        'comportement'  => 'behavior',
        'comportements' => 'behavior',
        'habitude'      => 'behavior',
        'habitudes'     => 'behavior',
        'behavior'      => 'behavior',
        'objectif'      => 'goal',
        'objectifs'     => 'goal',
        'goal'          => 'goal',
        'goals'         => 'goal',
        'divers'        => 'general',
        'general'       => 'general',
    ];

    /** Category display labels */
    private const CATEGORY_LABELS = [
        'profession' => 'Profession',
        'personal'   => 'Infos personnelles',
        'preference' => 'Preferences',
        'skill'      => 'Competences',
        'project'    => 'Projets',
        'behavior'   => 'Comportement',
        'goal'       => 'Objectifs',
        'general'    => 'Divers',
    ];

    /** Category icons for WhatsApp display */
    private const CATEGORY_ICONS = [
        'profession' => '💼',
        'personal'   => '👤',
        'preference' => '❤️',
        'skill'      => '🛠️',
        'project'    => '📦',
        'behavior'   => '🔄',
        'goal'       => '🎯',
        'general'    => '📝',
    ];

    /**
     * Noise patterns: messages matching these are skipped (no extraction value).
     * Order matters - URL check before domain-like check.
     */
    private const NOISE_PATTERNS = [
        '/^(bonjour|bonsoir|salut|hello|hi|hey|coucou|yo|bjr|bsr)\s*[!.?]*$/ui',
        '/^(ok|oui|non|ouais|yep|nope|oki|nah|yup|d\'accord|daccord|vu|recu|compris)\s*[!.?]*$/ui',
        '/^(merci|thanks|thx|stp|svp|super|cool|parfait|nickel|top|bof|meh|lol|haha)\s*[!.?]*$/ui',
        '/^(yes|no|yeah|nah|sure|okay|k)\s*[!.?]*$/ui',
        '/^https?:\/\/\S+$/u',
        '/^\p{So}+$/u',           // emoji-only (Unicode symbols)
        '/^\p{Emoticons}+$/u',    // emoji-only (Emoticons block)
    ];

    public function __construct()
    {
        parent::__construct();
        $this->contextStore = new ContextStore();
    }

    public function name(): string
    {
        return 'smart_context';
    }

    public function description(): string
    {
        return 'Agent de memoire contextuelle. En arriere-plan, extrait et memorise des faits personnels durables (profession, competences, preferences, projets, objectifs) depuis les conversations. Supporte aussi des commandes directes : "mon profil" (voir le profil), "profil stats" (statistiques), "profil reset" (effacer).';
    }

    public function keywords(): array
    {
        return [
            'mon profil', 'mon contexte', 'profil stats', 'profil reset', 'oublie mon profil',
            'profil chercher', 'oublie categorie', 'retiens que', 'profil aide',
        ];
    }

    public function version(): string
    {
        return '1.6.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return true;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = $context->body;
        if (!$body) {
            return AgentResult::silent(['reason' => 'message_too_short']);
        }

        $trimmed = trim($body);

        // Skip commands and system messages first (fast check)
        if (preg_match('/^[\/!]/', $trimmed)) {
            return AgentResult::silent(['reason' => 'command_skipped']);
        }

        // Check for explicit profile commands BEFORE noise filtering
        $profileCommand = $this->detectProfileCommand($trimmed);
        if ($profileCommand) {
            return $this->handleProfileCommand($profileCommand, $context);
        }

        // Skip noise patterns before length check: greetings, pure URLs, emoji-only
        // (these are known-useless regardless of length)
        foreach (self::NOISE_PATTERNS as $pattern) {
            if (@preg_match($pattern, $trimmed)) {
                return AgentResult::silent(['reason' => 'noise_skipped']);
            }
        }

        // Skip messages that are too short to contain meaningful personal facts
        if (mb_strlen($trimmed) < 10) {
            return AgentResult::silent(['reason' => 'message_too_short']);
        }

        // Skip pure-numeric messages (amounts, codes, etc.)
        if (preg_match('/^[\d\s\.\,\!\?]+$/', $trimmed)) {
            return AgentResult::silent(['reason' => 'numeric_only_skipped']);
        }

        // Skip question-only messages (unlikely to contain durable personal facts)
        if (preg_match('/^\p{L}[\p{L}\p{N}\s\'\-,]{3,}\?+\s*$/u', $trimmed) && substr_count($trimmed, '?') >= 1 && mb_strlen($trimmed) < 80) {
            return AgentResult::silent(['reason' => 'question_only_skipped']);
        }

        try {
            $existingFacts = $this->contextStore->retrieve($context->from);
            $facts = $this->extractFacts($body, $context, $existingFacts);

            if (!empty($facts)) {
                // Apply score reinforcement for facts confirmed again
                $facts = $this->applyScoreReinforcement($facts, $existingFacts);

                // Handle contradictions: remove old fact before storing the new one
                foreach ($facts as $fact) {
                    if (!empty($fact['contradicts'])) {
                        $this->contextStore->forget($context->from, $fact['contradicts']);
                    }
                }

                $this->contextStore->store($context->from, $facts);
                $this->log($context, 'Context facts extracted', [
                    'facts_count' => count($facts),
                    'facts'       => array_map(fn ($f) => $f['key'] . ': ' . $f['value'], $facts),
                ]);
            }

            // Cleanup old entries
            $removed = $this->contextStore->cleanup($context->from);

            return AgentResult::silent([
                'facts_extracted' => count($facts),
                'facts_removed'   => $removed,
                'total_facts'     => count($this->contextStore->retrieve($context->from)),
            ]);
        } catch (\Throwable $e) {
            Log::error('SmartContextAgent: extraction failed', [
                'error'      => $e->getMessage(),
                'user_id'    => $context->from,
                'body_len'   => mb_strlen($body),
                'message'    => substr($body, 0, 100),
            ]);
            return AgentResult::silent(['reason' => 'extraction_error']);
        }
    }

    // ── Direct profile command handlers ───────────────────────────────────────

    /**
     * Detect if the message is an explicit profile command.
     * Returns the command type or null if not a profile command.
     */
    public function detectProfileCommand(string $text): ?string
    {
        $lower = mb_strtolower(trim($text));

        // 1. Reset — must come first: "oublie mon profil" contains "mon profil"
        if (preg_match('/\b(reset\s+profil|profil\s+reset|efface\s+profil|oublie\s+mon\s+profil|supprime\s+profil|raz\s+profil|vide\s+profil)\b/ui', $lower)) {
            return 'reset';
        }

        // 2. Forget category — "oublie categorie X" or "efface mes competences" etc.
        if (preg_match('/\b(oublie|efface|supprime|vide)\s+(?:(?:ma|mes|la|les|le)\s+)?cat[eé]gorie\b/ui', $lower)
            || preg_match('/\b(oublie|efface|supprime)\s+(?:mes?\s+)?(comp[eé]tences?|pr[eé]f[eé]rences?|projets?|objectifs?|habitudes?|comportements?|professions?|m[eé]tiers?|loisirs)\b/ui', $lower)) {
            return 'forget_category';
        }

        // 3. Stats
        if (preg_match('/\bprofil\s+stats?\b|\bstats?\s+profil\b/ui', $lower)) {
            return 'stats';
        }

        // 4. Search — "profil chercher X" or "chercher dans mon profil X"
        if (preg_match('/\bprofil\s+cherche[rz]?\b|\bcherche[rz]?\s+(?:dans\s+)?(?:mon\s+)?(?:profil|contexte)\b/ui', $lower)) {
            return 'search';
        }

        // 5. Help — "profil aide" / "aide profil" / "profil help"
        if (preg_match('/\b(?:profil|contexte)\s+(?:aide|help|commandes?)\b|\b(?:aide|help)\s+(?:profil|contexte)\b/ui', $lower)) {
            return 'help';
        }

        // 6. View
        if (preg_match('/\b(mon\s+profil|voir\s+profil|afficher\s+profil|mon\s+contexte|voir\s+contexte)\b/ui', $lower)) {
            return 'view';
        }

        // 7. Remember — "retiens que ...", "note: ...", "rappelle-toi ..."
        if (preg_match('/^\s*(?:retiens|note|rappelle-toi|souviens-toi|m[eé]morise)\s*(?:que\s+|:\s*|bien\s+que\s+)?/ui', $lower)) {
            return 'remember';
        }

        return null;
    }

    /**
     * Dispatch profile command to the appropriate handler.
     */
    private function handleProfileCommand(string $command, AgentContext $context): AgentResult
    {
        return match ($command) {
            'view'            => $this->handleProfileView($context->from, $context),
            'stats'           => $this->handleProfileStats($context->from, $context),
            'reset'           => $this->handleProfileReset($context->from, $context),
            'search'          => $this->handleProfileSearch($context),
            'forget_category' => $this->handleForgetCategory($context),
            'remember'        => $this->handleRememberFact($context),
            'help'            => $this->handleProfileHelp($context),
            default           => AgentResult::silent(['reason' => 'unknown_profile_command']),
        };
    }

    /**
     * Display the user's full profile summary grouped by category.
     */
    private function handleProfileView(string $userId, AgentContext $context): AgentResult
    {
        $summary = $this->summarizeProfile($userId);

        if (empty($summary)) {
            $text = "🧠 *Votre profil contextuel*\n\nAucun fait connu pour le moment.\nContinuez a interagir et j'apprendrai a mieux vous connaitre !";
        } else {
            $total = count($this->contextStore->retrieve($userId));
            $text  = "🧠 *Votre profil contextuel* ({$total} fait" . ($total > 1 ? 's' : '') . ")\n\n{$summary}";
        }

        $this->sendText($context->from, $text);
        return AgentResult::reply($text, ['command' => 'profile_view']);
    }

    /**
     * Display profile statistics (total facts, by category, confidence breakdown).
     */
    private function handleProfileStats(string $userId, AgentContext $context): AgentResult
    {
        $stats = $this->getProfileStats($userId);

        if ($stats['total'] === 0) {
            $text = "📊 *Statistiques de profil*\n\nAucun fait enregistre pour le moment.";
        } else {
            $avgPct = round($stats['avg_score'] * 100);
            $lines  = [
                "📊 *Statistiques de profil*",
                "",
                "Total : *{$stats['total']}* faits",
                "Confiance moyenne : *{$avgPct}%*",
                "Haute confiance (≥70%) : {$stats['high_confidence']}",
                "Faible confiance (<70%) : {$stats['low_confidence']}",
            ];

            if (!empty($stats['by_category'])) {
                $lines[] = "";
                $lines[] = "*Par categorie :*";
                foreach (self::CATEGORY_LABELS as $cat => $label) {
                    if (!isset($stats['by_category'][$cat])) {
                        continue;
                    }
                    $count = $stats['by_category'][$cat];
                    $icon  = self::CATEGORY_ICONS[$cat] ?? '•';
                    $lines[] = "{$icon} {$label} : {$count}";
                }
            }

            if (!empty($stats['oldest_fact'])) {
                $lines[] = "";
                $lines[] = "Premier fait : " . \Carbon\Carbon::createFromTimestamp($stats['oldest_fact'])->diffForHumans();
            }
            if (!empty($stats['newest_fact'])) {
                $lines[] = "Dernier fait : " . \Carbon\Carbon::createFromTimestamp($stats['newest_fact'])->diffForHumans();
            }

            $gaps = $this->detectProfileGaps($userId);
            if (!empty($gaps)) {
                $lines[] = "";
                $lines[] = "*Informations manquantes :*";
                $lines[] = implode(', ', $gaps);
                $lines[] = "_Tapez_ *profil aide* _pour voir toutes les commandes._";
            }

            $text = implode("\n", $lines);
        }

        $this->sendText($context->from, $text);
        return AgentResult::reply($text, ['command' => 'profile_stats']);
    }

    /**
     * Reset (flush) the user's full profile.
     */
    private function handleProfileReset(string $userId, AgentContext $context): AgentResult
    {
        $count = count($this->contextStore->retrieve($userId));
        $this->contextStore->flush($userId);

        $text = $count > 0
            ? "🗑️ Profil efface — {$count} fait" . ($count > 1 ? 's' : '') . " supprime" . ($count > 1 ? 's' : '') . ".\nJe repartirai de zero lors de vos prochains echanges."
            : "🗑️ Votre profil etait deja vide.";

        $this->sendText($context->from, $text);
        $this->log($context, 'Profile reset by user', ['facts_removed' => $count]);
        return AgentResult::reply($text, ['command' => 'profile_reset', 'facts_removed' => $count]);
    }

    /**
     * Search stored facts and return matching results formatted for WhatsApp.
     */
    private function handleProfileSearch(AgentContext $context): AgentResult
    {
        // Extract query: strip command prefix ("profil chercher X" → "X")
        $body  = $context->body;
        $query = preg_replace('/^.*?(?:profil\s+cherche[rz]?|cherche[rz]?\s+(?:dans\s+)?(?:mon\s+)?(?:profil|contexte))\s*/ui', '', $body);
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            $text = "🔍 Precisez ce que vous cherchez.\nExemple : *profil chercher Laravel*";
            $this->sendText($context->from, $text);
            return AgentResult::reply($text, ['command' => 'profile_search', 'error' => 'no_query']);
        }

        $results = $this->searchFacts($context->from, $query);

        if (empty($results)) {
            $text = "🔍 Aucun fait trouve pour *{$query}*.\nConsultez votre profil complet avec *mon profil*.";
        } else {
            $count = count($results);
            $lines = ["🔍 *Recherche : {$query}* ({$count} resultat" . ($count > 1 ? 's' : '') . ")", ""];
            foreach ($results as $fact) {
                $icon = self::CATEGORY_ICONS[$fact['category'] ?? 'general'] ?? '•';
                $conf = round($this->computeDecayedScore($fact) * 100);
                $lines[] = "{$icon} {$fact['value']} _({$conf}%)_";
            }
            $text = implode("\n", $lines);
        }

        $this->sendText($context->from, $text);
        return AgentResult::reply($text, ['command' => 'profile_search', 'query' => $query, 'results_count' => count($results)]);
    }

    /**
     * Delete all facts belonging to a user-specified category.
     */
    private function handleForgetCategory(AgentContext $context): AgentResult
    {
        $body  = mb_strtolower($context->body);
        $detectedCategory = null;

        // Sort by key length descending to match longer labels first (e.g. "comportements" before "comportement")
        $map = self::CATEGORY_FR_MAP;
        uksort($map, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($map as $label => $key) {
            if (mb_strpos($body, $label) !== false) {
                $detectedCategory = $key;
                break;
            }
        }

        if (!$detectedCategory) {
            $catList = implode("\n", array_map(
                fn ($cat, $label) => (self::CATEGORY_ICONS[$cat] ?? '•') . " {$label}",
                array_keys(self::CATEGORY_LABELS),
                self::CATEGORY_LABELS
            ));
            $text = "❓ Categorie non reconnue. Categories disponibles :\n{$catList}\n\nExemple : *oublie categorie competences*";
            $this->sendText($context->from, $text);
            return AgentResult::reply($text, ['command' => 'forget_category', 'error' => 'unknown_category']);
        }

        $removed = $this->forgetCategory($context->from, $detectedCategory);
        $label   = self::CATEGORY_LABELS[$detectedCategory] ?? $detectedCategory;
        $icon    = self::CATEGORY_ICONS[$detectedCategory] ?? '•';

        $text = $removed > 0
            ? "🗑️ {$icon} *{$label}* : {$removed} fait" . ($removed > 1 ? 's' : '') . " supprime" . ($removed > 1 ? 's' : '') . "."
            : "ℹ️ Aucun fait trouve dans la categorie *{$label}*.";

        $this->sendText($context->from, $text);
        $this->log($context, "Forget category: {$detectedCategory}", ['removed' => $removed]);
        return AgentResult::reply($text, ['command' => 'forget_category', 'category' => $detectedCategory, 'removed' => $removed]);
    }

    /**
     * Manually add a fact explicitly stated by the user ("retiens que ...").
     * Extracts with LLM and boosts confidence score since it's an explicit statement.
     */
    private function handleRememberFact(AgentContext $context): AgentResult
    {
        // Strip the command prefix to get the actual fact text
        $factText = preg_replace('/^\s*(?:retiens|note|rappelle-toi|souviens-toi|m[eé]morise)\s*(?:que\s+|:\s*|bien\s+que\s+)?/ui', '', $context->body);
        $factText = trim($factText);

        if (mb_strlen($factText) < 5) {
            $text = "❓ Precisez ce que vous souhaitez que je retienne.\nExemple : *retiens que je suis developpeur senior*";
            $this->sendText($context->from, $text);
            return AgentResult::reply($text, ['command' => 'remember_fact', 'error' => 'no_fact']);
        }

        try {
            $existingFacts = $this->contextStore->retrieve($context->from);
            $facts         = $this->extractFacts($factText, $context, $existingFacts);

            if (empty($facts)) {
                $text = "🤔 Je n'ai pas pu identifier un fait personnel clair. Reformulez si besoin.\nExemple : *retiens que j'utilise Laravel au travail*";
            } else {
                // Boost confidence for manually stated facts (+0.2, capped at 1.0)
                $facts = array_map(fn ($f) => array_merge($f, ['score' => min(1.0, ($f['score'] ?? 0.5) + 0.2)]), $facts);
                $facts = $this->applyScoreReinforcement($facts, $existingFacts);

                foreach ($facts as $fact) {
                    if (!empty($fact['contradicts'])) {
                        $this->contextStore->forget($context->from, $fact['contradicts']);
                    }
                }
                $this->contextStore->store($context->from, $facts);

                $count    = count($facts);
                $previews = array_map(fn ($f) => (self::CATEGORY_ICONS[$f['category'] ?? 'general'] ?? '•') . ' ' . $f['value'], $facts);
                $text     = "✅ Memorise ({$count} fait" . ($count > 1 ? 's' : '') . ") :\n" . implode("\n", $previews);
                $this->log($context, 'Manual fact added', ['facts_count' => $count]);
            }
        } catch (\Throwable $e) {
            Log::warning("SmartContextAgent: remember failed: " . $e->getMessage());
            $text = "❌ Erreur lors de la memorisation. Reessayez dans un instant.";
        }

        $this->sendText($context->from, $text);
        return AgentResult::reply($text, ['command' => 'remember_fact']);
    }

    /**
     * Display available profile commands as a WhatsApp-friendly help message.
     */
    private function handleProfileHelp(AgentContext $context): AgentResult
    {
        $lines = [
            "🧠 *Commandes de profil contextuel*",
            "",
            "📋 *Voir votre profil*",
            "  • `mon profil` / `voir profil`",
            "",
            "📊 *Statistiques*",
            "  • `profil stats`",
            "",
            "🔍 *Rechercher dans votre profil*",
            "  • `profil chercher <mot-cle>`",
            "  _Ex : profil chercher Laravel_",
            "",
            "✏️ *Retenir un fait manuellement*",
            "  • `retiens que <information>`",
            "  _Ex : retiens que je suis dev senior_",
            "",
            "🗑️ *Supprimer une categorie*",
            "  • `oublie categorie <categorie>`",
            "  _Categories : competences, preferences, projets,_",
            "  _objectifs, profession, personnel, comportement_",
            "",
            "💣 *Effacer tout le profil*",
            "  • `profil reset` / `oublie mon profil`",
        ];

        $text = implode("\n", $lines);
        $this->sendText($context->from, $text);
        return AgentResult::reply($text, ['command' => 'profile_help']);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Retrieve all stored context facts for a user.
     */
    public function getStoredContext(string $userId): array
    {
        return $this->contextStore->retrieve($userId);
    }

    /**
     * Retrieve facts filtered by a specific category.
     * Returns an empty array if the category is invalid or no facts match.
     */
    public function getFactsByCategory(string $userId, string $category): array
    {
        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            return [];
        }

        $facts = $this->contextStore->retrieve($userId);

        return array_values(array_filter(
            $facts,
            fn ($f) => ($f['category'] ?? 'general') === $category
        ));
    }

    /**
     * Remove all facts belonging to a specific category.
     * Returns the number of facts removed.
     */
    public function forgetCategory(string $userId, string $category): int
    {
        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            return 0;
        }

        $facts = $this->contextStore->retrieve($userId);
        $toForget = array_filter(
            $facts,
            fn ($f) => ($f['category'] ?? 'general') === $category
        );

        $count = count($toForget);
        foreach ($toForget as $fact) {
            if (!empty($fact['key'])) {
                $this->contextStore->forget($userId, $fact['key']);
            }
        }

        return $count;
    }

    /**
     * Generate a human-readable profile summary grouped by category.
     * Includes confidence indicator and staleness marker.
     * Useful for ChatAgent or other agents that need user context.
     */
    public function summarizeProfile(string $userId): string
    {
        $facts = $this->contextStore->retrieve($userId);

        if (empty($facts)) {
            return '';
        }

        $staleThreshold = time() - self::STALE_AGE;
        $grouped = [];

        foreach ($facts as $fact) {
            $cat   = $fact['category'] ?? 'general';
            $score = $this->computeDecayedScore($fact);
            $ts    = $fact['timestamp'] ?? null;

            $display = $fact['value'];

            // Append confidence indicator for low-confidence facts
            if ($score < 0.6) {
                $display .= ' _(?)_';
            }

            // Append staleness marker for facts not updated in 30+ days
            if ($ts && $ts < $staleThreshold) {
                $display .= ' _(ancien)_';
            }

            $grouped[$cat][] = $display;
        }

        $lines = [];
        foreach (self::CATEGORY_LABELS as $cat => $label) {
            if (!empty($grouped[$cat])) {
                $icon        = self::CATEGORY_ICONS[$cat] ?? '•';
                $count       = count($grouped[$cat]);
                $countSuffix = $count > 1 ? " ({$count})" : '';
                $lines[] = "{$icon} *{$label}*{$countSuffix} : " . implode(', ', $grouped[$cat]);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Remove a specific fact from a user's context by key.
     * Returns true if the fact existed and was removed, false otherwise.
     */
    public function forgetFact(string $userId, string $factKey): bool
    {
        $facts  = $this->contextStore->retrieve($userId);
        $exists = collect($facts)->firstWhere('key', $factKey) !== null;

        if ($exists) {
            $this->contextStore->forget($userId, $factKey);
            return true;
        }

        return false;
    }

    /**
     * Return statistics about the stored profile for a user.
     */
    public function getProfileStats(string $userId): array
    {
        $facts = $this->contextStore->retrieve($userId);

        if (empty($facts)) {
            return ['total' => 0, 'by_category' => [], 'avg_score' => 0.0];
        }

        $byCategory = [];
        $totalScore = 0.0;
        $timestamps = [];
        $highConf   = 0;
        $lowConf    = 0;

        foreach ($facts as $fact) {
            $cat = $fact['category'] ?? 'general';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
            $score = $this->computeDecayedScore($fact);
            $totalScore += $score;
            if (!empty($fact['timestamp'])) {
                $timestamps[] = $fact['timestamp'];
            }
            if ($score >= 0.7) {
                $highConf++;
            } else {
                $lowConf++;
            }
        }

        return [
            'total'           => count($facts),
            'by_category'     => $byCategory,
            'avg_score'       => round($totalScore / count($facts), 2),
            'high_confidence' => $highConf,
            'low_confidence'  => $lowConf,
            'oldest_fact'     => !empty($timestamps) ? min($timestamps) : null,
            'newest_fact'     => !empty($timestamps) ? max($timestamps) : null,
        ];
    }

    /**
     * Return the N most recently added or updated facts for a user, sorted by timestamp descending.
     * Useful for surfacing the freshest profile information to other agents.
     */
    public function getRecentFacts(string $userId, int $limit = 5): array
    {
        $facts = $this->contextStore->retrieve($userId);

        if (empty($facts)) {
            return [];
        }

        usort($facts, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        return array_slice($facts, 0, max(1, $limit));
    }

    /**
     * Return a compact dot-separated summary of the user's key facts (profession, skills, projects).
     * Only includes high-confidence facts (score >= 0.6). Limited to 6 items.
     * Useful for injecting a brief user context into other agents' prompts without verbosity.
     * Example: "Dev backend · Laravel, Vue.js · Projet ZeniClaw"
     */
    public function getTagsCompact(string $userId): string
    {
        $facts = $this->contextStore->retrieve($userId);

        if (empty($facts)) {
            return '';
        }

        $tagCategories = ['profession', 'skill', 'project'];
        $parts = [];

        foreach ($facts as $fact) {
            $cat   = $fact['category'] ?? 'general';
            $score = $this->computeDecayedScore($fact);

            if (!in_array($cat, $tagCategories, true) || $score < 0.6) {
                continue;
            }

            $val = trim($fact['value'] ?? '');
            if ($val === '') {
                continue;
            }

            // Truncate at word boundary if too long
            if (mb_strlen($val) > 40) {
                $val = mb_substr($val, 0, 37) . '...';
            }

            $parts[] = $val;
        }

        $parts = array_unique(array_slice($parts, 0, 6));

        return implode(' · ', $parts);
    }

    /**
     * Search stored facts by a free-text query matching key or value.
     * Uses case-insensitive substring match first, then fuzzy similarity as fallback.
     * Returns facts sorted by match relevance (exact substring matches first).
     * Useful for ChatAgent to quickly locate relevant context before injecting into prompts.
     */
    public function searchFacts(string $userId, string $query): array
    {
        $facts = $this->contextStore->retrieve($userId);

        if (empty($facts) || trim($query) === '') {
            return [];
        }

        $query  = mb_strtolower(trim($query));
        $scored = [];

        foreach ($facts as $fact) {
            $key   = mb_strtolower($fact['key'] ?? '');
            $value = mb_strtolower($fact['value'] ?? '');
            $matchScore = 0;

            // Exact substring match in value (high relevance)
            if (mb_stripos($value, $query) !== false) {
                $matchScore = 2;
            // Exact substring match in key (medium relevance)
            } elseif (mb_stripos($key, $query) !== false) {
                $matchScore = 1;
            } else {
                // Fuzzy fallback: check similarity to value
                similar_text($query, $value, $pct);
                if ($pct >= 60) {
                    $matchScore = 1;
                }
            }

            if ($matchScore > 0) {
                $scored[] = ['fact' => $fact, 'match' => $matchScore];
            }
        }

        if (empty($scored)) {
            return [];
        }

        // Sort by match score descending, then by fact score descending
        usort($scored, fn ($a, $b) =>
            $b['match'] !== $a['match']
                ? $b['match'] <=> $a['match']
                : ($b['fact']['score'] ?? 0.5) <=> ($a['fact']['score'] ?? 0.5)
        );

        return array_values(array_map(fn ($s) => $s['fact'], $scored));
    }

    /**
     * Return only facts with a confidence score >= $minScore (after applying decay).
     * Defaults to 0.7 (high confidence). Sorted by score descending.
     * Ideal for injecting reliable user context into other agents' prompts.
     */
    public function getHighConfidenceFacts(string $userId, float $minScore = 0.7): array
    {
        $facts = $this->contextStore->retrieve($userId);

        $filtered = array_values(array_filter(
            $facts,
            fn ($f) => $this->computeDecayedScore($f) >= $minScore
        ));

        usort($filtered, fn ($a, $b) => $this->computeDecayedScore($b) <=> $this->computeDecayedScore($a));

        return $filtered;
    }

    /**
     * Detect which profile categories have no stored facts.
     * Returns an array of human-readable category labels that are missing.
     * Useful for prompting users to share more context or for gap-aware prompts.
     */
    public function detectProfileGaps(string $userId): array
    {
        $facts = $this->contextStore->retrieve($userId);

        if (empty($facts)) {
            return array_values(self::CATEGORY_LABELS);
        }

        $filledCategories = array_unique(array_map(
            fn ($f) => $f['category'] ?? 'general',
            $facts
        ));

        $gaps = [];
        $priorityCategories = ['profession', 'skill', 'goal', 'personal'];

        foreach ($priorityCategories as $cat) {
            if (!in_array($cat, $filledCategories, true)) {
                $gaps[] = self::CATEGORY_LABELS[$cat] ?? $cat;
            }
        }

        return $gaps;
    }

    /**
     * Compute the effective (decayed) score for a fact based on its age.
     * Facts older than DECAY_START_DAYS lose SCORE_DECAY_PER_DAY per additional day.
     * The raw stored score is never mutated — decay is applied on the fly for reads.
     */
    public function computeDecayedScore(array $fact): float
    {
        $score = (float) ($fact['score'] ?? 0.5);
        $ts    = $fact['timestamp'] ?? null;

        if (!$ts) {
            return $score;
        }

        $ageSeconds = time() - $ts;
        $decayStart = self::DECAY_START_DAYS * 86400;

        if ($ageSeconds <= $decayStart) {
            return $score;
        }

        $extraDays = ($ageSeconds - $decayStart) / 86400;
        $decay     = min(0.3, $extraDays * self::SCORE_DECAY_PER_DAY);

        return max(self::MIN_SCORE, round($score - $decay, 3));
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Apply score reinforcement: if a newly extracted fact has the same key AND a similar value
     * as an existing fact (and is not contradicting it), boost the score to reflect repeated confirmation.
     */
    private function applyScoreReinforcement(array $newFacts, array $existingFacts): array
    {
        if (empty($existingFacts)) {
            return $newFacts;
        }

        $existingByKey = [];
        foreach ($existingFacts as $f) {
            if (!empty($f['key'])) {
                $existingByKey[$f['key']] = $f;
            }
        }

        return array_map(function ($fact) use ($existingByKey) {
            $key = $fact['key'] ?? '';

            // Only reinforce non-contradicting facts that match an existing key
            if (empty($key) || !empty($fact['contradicts']) || !isset($existingByKey[$key])) {
                return $fact;
            }

            $existingValue = strtolower(trim($existingByKey[$key]['value'] ?? ''));
            $newValue      = strtolower(trim($fact['value'] ?? ''));

            if ($existingValue === '' || $newValue === '') {
                return $fact;
            }

            // Use similar_text percentage for fuzzy comparison
            similar_text($existingValue, $newValue, $percent);

            if ($percent >= self::SIMILARITY_THRESHOLD) {
                $boosted = min(1.0, ($fact['score'] ?? 0.5) + self::REINFORCEMENT_BOOST);
                return array_merge($fact, ['score' => $boosted]);
            }

            return $fact;
        }, $newFacts);
    }

    private function extractFacts(string $message, AgentContext $context, array $existingFacts = []): array
    {
        $response = $this->claude->chat(
            "Message de {$context->senderName}: \"{$message}\"",
            $this->resolveModel($context),
            $this->buildExtractionPrompt($existingFacts)
        );

        if (!$response) {
            Log::warning("SmartContextAgent: no response from Claude for user {$context->from}");
            return [];
        }

        $facts = $this->parseFactsResponse($response);

        if (empty($facts)) {
            Log::warning('SmartContextAgent: 0 facts extracted from LLM response', [
                'user_id'        => $context->from,
                'message_length' => strlen($message),
                'raw_response'   => substr($response, 0, 200),
            ]);
        }

        return $facts;
    }

    private function buildExtractionPrompt(array $existingFacts = []): string
    {
        $maxFacts = self::MAX_FACTS_PER_MESSAGE;

        $existingContext = '';
        if (!empty($existingFacts)) {
            $topFacts = array_slice($existingFacts, 0, 15);
            $lines    = array_map(
                fn ($f) => "  - [{$f['category']}] {$f['key']}: {$f['value']}",
                $topFacts
            );
            $existingContext = "\nFAITS DEJA CONNUS (evite de re-extraire sauf mise a jour ou contradiction):\n"
                . implode("\n", $lines) . "\n";
        }

        return <<<PROMPT
Tu es un extracteur de faits personnels durables. Analyse le message et extrais uniquement les informations stables et personnelles sur l'utilisateur.
{$existingContext}
Reponds UNIQUEMENT en JSON valide, sans markdown ni texte supplementaire:
{"facts": [{"key": "identifiant_unique", "value": "description du fait", "category": "categorie", "score": 0.8, "contradicts": null}]}

CATEGORIES possibles:
- "profession" : metier, poste, titre, experience professionnelle, certifications, secteur d'activite, employeur
- "skill"      : langages, frameworks, outils, technologies maitrisees, competences techniques et non-techniques, langues parlees
- "preference" : gouts, style de communication, humour, hobbies, alimentation, musique, sports, loisirs, sujets d'interet
- "personal"   : prenom, age, localisation, situation familiale, nationalite, fuseau horaire, langue maternelle, contacts
- "project"    : projets en cours, applications developpees, side-projects, produits, startups, initiatives personnelles
- "behavior"   : habitudes, horaires de travail, frequence d'utilisation, rythme de travail, methode de travail, rituels quotidiens
- "goal"       : objectifs personnels, ambitions, plans futurs, projets de carriere, apprentissages prevus, resolutions

REGLES STRICTES:
- N'extrais QUE les faits DURABLES et PERSONNELS (pas les questions, opinions passageres ou etat emotionnel du moment)
- score entre 0.1 (tres implicite/incertain) et 1.0 (explicite et certain); 0.5 = mentionne sans insistance
- key: identifiant court en snake_case, specifique (ex: "tech_stack", "humor_style", "city", "side_project_name")
- Separe "profession" (metier/poste) de "skill" (technologies): un dev a profession=developpeur ET skill=Laravel
- Si le fait CONTREDIT un fait deja connu, indique la cle du fait existant dans "contradicts"
- Si AUCUN fait personnel durable, reponds: {"facts": []}
- Maximum {$maxFacts} faits par message; priorise les faits les plus certains
- Ne re-extrais pas un fait deja connu avec exactement la meme valeur (sauf si tu veux renforcer la confiance)
- Ignore les commandes (/help, /start), les salutations seules, les questions sans contexte personnel

EXEMPLES:
Message: "je suis dev backend chez une startup a Paris"
=> {"facts": [{"key": "profession", "value": "Developpeur backend en startup", "category": "profession", "score": 1.0, "contradicts": null}, {"key": "city", "value": "Paris", "category": "personal", "score": 0.9, "contradicts": null}]}

Message: "j'utilise Laravel, Vue.js et PostgreSQL au quotidien"
=> {"facts": [{"key": "tech_stack", "value": "Laravel, Vue.js, PostgreSQL", "category": "skill", "score": 1.0, "contradicts": null}]}

Message: "j'aime les blagues sombres et je deteste le sport"
=> {"facts": [{"key": "humor_style", "value": "Apprecie l'humour noir/sombre", "category": "preference", "score": 0.9, "contradicts": null}, {"key": "sport_preference", "value": "N'aime pas le sport", "category": "preference", "score": 0.8, "contradicts": null}]}

Message: "j'ai demenage a Lyon" (si "city: Habite a Paris" deja connu)
=> {"facts": [{"key": "city", "value": "Habite a Lyon", "category": "personal", "score": 1.0, "contradicts": "city"}]}

Message: "mon objectif est de devenir CTO dans 3 ans"
=> {"facts": [{"key": "career_goal", "value": "Ambition de devenir CTO dans 3 ans", "category": "goal", "score": 0.9, "contradicts": null}]}

Message: "je commence a apprendre Rust le week-end"
=> {"facts": [{"key": "learning_rust", "value": "Apprend Rust le week-end", "category": "skill", "score": 0.7, "contradicts": null}]}

Message: "je travaille de chez moi le matin, au bureau l'apres-midi"
=> {"facts": [{"key": "work_schedule", "value": "Matin en remote, apres-midi au bureau", "category": "behavior", "score": 0.9, "contradicts": null}]}

Message: "je suis passionne de randonnee et de cuisine japonaise"
=> {"facts": [{"key": "hobbies", "value": "Randonnee et cuisine japonaise", "category": "preference", "score": 0.9, "contradicts": null}]}

Message: "je parle anglais et espagnol couramment, et un peu d'allemand"
=> {"facts": [{"key": "languages", "value": "Anglais et espagnol courants, notions d'allemand", "category": "skill", "score": 1.0, "contradicts": null}]}

Message: "je prevois de lancer mon SaaS l'ete prochain"
=> {"facts": [{"key": "launch_plan", "value": "Lancement SaaS prevu en ete", "category": "goal", "score": 0.8, "contradicts": null}]}

Message: "je me leve a 6h tous les jours pour coder avant le boulot"
=> {"facts": [{"key": "morning_routine", "value": "Leve a 6h, code le matin avant le travail", "category": "behavior", "score": 0.9, "contradicts": null}]}

Message: "j'ai 3 enfants et ma femme est medecin"
=> {"facts": [{"key": "family", "value": "3 enfants, conjoint(e) medecin", "category": "personal", "score": 0.9, "contradicts": null}]}

Message: "je travaille sur ZeniClaw, une appli WhatsApp IA avec Laravel"
=> {"facts": [{"key": "current_project", "value": "ZeniClaw — assistant WhatsApp IA (Laravel)", "category": "project", "score": 1.0, "contradicts": null}]}

Message: "oui bien sur" => {"facts": []}
Message: "quel temps fait-il ?" => {"facts": []}
Message: "envoie-moi ca demain" => {"facts": []}
Message: "lol super merci" => {"facts": []}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function parseFactsResponse(?string $response): array
    {
        if (!$response) return [];

        $clean = trim($response);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $parsed = json_decode($clean, true);

        if (!$parsed || !isset($parsed['facts']) || !is_array($parsed['facts'])) {
            Log::warning('SmartContextAgent: JSON parse failed or missing facts key', [
                'raw_snippet' => substr($clean, 0, 200),
                'json_error'  => json_last_error_msg(),
            ]);
            return [];
        }

        $valid = [];
        foreach ($parsed['facts'] as $fact) {
            if (empty($fact['key']) || empty($fact['value'])) {
                continue;
            }

            $score = min(1.0, max(0.1, (float) ($fact['score'] ?? 0.5)));

            // Discard very low-confidence facts
            if ($score < self::MIN_SCORE) {
                continue;
            }

            // Validate and normalize category
            $category = $fact['category'] ?? 'general';
            if (!in_array($category, self::VALID_CATEGORIES, true)) {
                $category = 'general';
            }

            // Sanitize key: snake_case only, strip leading/trailing underscores
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $fact['key']));
            $key = trim($key, '_');
            if (empty($key)) {
                continue;
            }

            $valid[] = [
                'key'         => $key,
                'value'       => mb_substr(trim((string) $fact['value']), 0, 500),
                'category'    => $category,
                'score'       => $score,
                'contradicts' => !empty($fact['contradicts']) ? trim((string) $fact['contradicts'], '_') : null,
                'timestamp'   => time(),
            ];
        }

        return array_slice($valid, 0, self::MAX_FACTS_PER_MESSAGE);
    }
}
