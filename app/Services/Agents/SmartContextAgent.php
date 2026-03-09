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

    /** Valid fact categories */
    private const VALID_CATEGORIES = [
        'profession', 'preference', 'personal',
        'project', 'behavior', 'goal', 'skill', 'general',
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
        return 'Agent interne d\'extraction de contexte utilisateur. Analyse les messages pour extraire des faits personnels durables (profession, competences, preferences, projets, objectifs) et les stocker en memoire contextuelle. Detecte les contradictions pour mettre a jour le profil. Fonctionne en arriere-plan, pas d\'interaction directe.';
    }

    public function keywords(): array
    {
        return [];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return true;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = $context->body;
        if (!$body || mb_strlen(trim($body)) < 10) {
            return AgentResult::silent(['reason' => 'message_too_short']);
        }

        // Skip commands and system messages
        if (preg_match('/^[\/!]/', trim($body))) {
            return AgentResult::silent(['reason' => 'command_skipped']);
        }

        // Skip very repetitive/short-value messages (pure numbers, single emoji, etc.)
        if (preg_match('/^[\d\s\.\,\!\?]+$/', trim($body))) {
            return AgentResult::silent(['reason' => 'numeric_only_skipped']);
        }

        try {
            $existingFacts = $this->contextStore->retrieve($context->from);
            $facts = $this->extractFacts($body, $context, $existingFacts);

            if (!empty($facts)) {
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
            Log::warning("SmartContextAgent: extraction failed for {$context->from}: " . $e->getMessage());
            return AgentResult::silent(['reason' => 'extraction_error']);
        }
    }

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
     * Includes confidence indicator and fact count.
     * Useful for ChatAgent or other agents that need user context.
     */
    public function summarizeProfile(string $userId): string
    {
        $facts = $this->contextStore->retrieve($userId);

        if (empty($facts)) {
            return '';
        }

        $grouped = [];
        foreach ($facts as $fact) {
            $cat = $fact['category'] ?? 'general';
            $score = $fact['score'] ?? 0.5;

            // Append confidence indicator only for low-confidence facts
            $display = $fact['value'];
            if ($score < 0.6) {
                $display .= ' _(?)_';
            }

            $grouped[$cat][] = $display;
        }

        $lines = [];
        foreach (self::CATEGORY_LABELS as $cat => $label) {
            if (!empty($grouped[$cat])) {
                $count = count($grouped[$cat]);
                $countSuffix = $count > 1 ? " ({$count})" : '';
                $lines[] = "*{$label}*{$countSuffix} : " . implode(', ', $grouped[$cat]);
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
        $facts = $this->contextStore->retrieve($userId);
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

        foreach ($facts as $fact) {
            $cat = $fact['category'] ?? 'general';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
            $totalScore += $fact['score'] ?? 0.5;
            if (!empty($fact['timestamp'])) {
                $timestamps[] = $fact['timestamp'];
            }
        }

        return [
            'total'       => count($facts),
            'by_category' => $byCategory,
            'avg_score'   => round($totalScore / count($facts), 2),
            'oldest_fact' => !empty($timestamps) ? min($timestamps) : null,
            'newest_fact' => !empty($timestamps) ? max($timestamps) : null,
        ];
    }

    private function extractFacts(string $message, AgentContext $context, array $existingFacts = []): array
    {
        $response = $this->claude->chat(
            "Message de {$context->senderName}: \"{$message}\"",
            'claude-haiku-4-5-20251001',
            $this->buildExtractionPrompt($existingFacts)
        );

        if (!$response) {
            Log::warning("SmartContextAgent: no response from Claude for user {$context->from}");
            return [];
        }

        return $this->parseFactsResponse($response);
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
            $existingContext = "\nFAITS DEJA CONNUS (evite de re-extraire sauf mise a jour/contradiction):\n"
                . implode("\n", $lines) . "\n";
        }

        return <<<PROMPT
Tu es un extracteur de faits personnels. A partir du message, extrais les informations durables sur l'utilisateur.
{$existingContext}
Reponds UNIQUEMENT en JSON valide, sans markdown:
{"facts": [{"key": "identifiant_unique", "value": "description du fait", "category": "categorie", "score": 0.8, "contradicts": null}]}

CATEGORIES possibles:
- "profession" : metier, poste, titre, experience professionnelle, certifications
- "skill"      : langages de programmation, frameworks, outils, technologies maitrisees
- "preference" : gouts, style de communication, humour, langue preferee, hobbies, alimentation
- "personal"   : nom, age, localisation, situation familiale, nationalite, contact
- "project"    : projets en cours, applications developpees, side-projects
- "behavior"   : habitudes, horaires, frequence d'utilisation, rythme de travail, methode de travail
- "goal"       : objectifs personnels, ambitions, plans futurs, projets de carriere

REGLES:
- N'extrais QUE les faits DURABLES et PERSONNELS (pas les questions ponctuelles ou les opinions passageres)
- score entre 0.1 (peu fiable/implicite) et 1.0 (explicite et certain)
- key doit etre un identifiant court en snake_case (ex: "tech_stack", "humor_level", "profession")
- Separe "profession" (metier/poste) de "skill" (technologies maitrisees) : un dev peut avoir profession=developpeur et skill=Laravel
- Si le fait CONTREDIT un fait deja connu, mets la cle du fait existant dans "contradicts"
- Si AUCUN fait personnel n'est exprime, reponds: {"facts": []}
- Maximum {$maxFacts} faits par message
- Ne repete pas un fait deja connu avec la meme valeur (sauf contradiction)

EXEMPLES:
- "je suis dev backend chez une startup"
  → {"facts": [{"key": "profession", "value": "Developpeur backend en startup", "category": "profession", "score": 1.0, "contradicts": null}]}
- "j'utilise Laravel, Vue.js et PostgreSQL au quotidien"
  → {"facts": [{"key": "tech_stack", "value": "Laravel, Vue.js, PostgreSQL", "category": "skill", "score": 1.0, "contradicts": null}]}
- "j'aime les blagues sombres"
  → {"facts": [{"key": "humor_style", "value": "Apprecie l'humour noir/sombre", "category": "preference", "score": 0.9, "contradicts": null}]}
- "j'ai demenage a Lyon" (si "location: Habite a Paris" deja connu)
  → {"facts": [{"key": "location", "value": "Habite a Lyon", "category": "personal", "score": 1.0, "contradicts": "location"}]}
- "mon objectif est de devenir CTO dans 3 ans"
  → {"facts": [{"key": "career_goal", "value": "Ambition de devenir CTO dans 3 ans", "category": "goal", "score": 0.9, "contradicts": null}]}
- "je commence a apprendre Rust"
  → {"facts": [{"key": "learning_rust", "value": "Apprend Rust", "category": "skill", "score": 0.7, "contradicts": null}]}
- "quel temps fait-il ?" → {"facts": []}

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
