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
        'project', 'behavior', 'goal', 'general',
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
        return 'Agent interne d\'extraction de contexte utilisateur. Analyse les messages pour extraire des faits personnels durables (profession, preferences, projets, objectifs) et les stocker en memoire contextuelle. Detecte les contradictions pour mettre a jour le profil. Fonctionne en arriere-plan, pas d\'interaction directe.';
    }

    public function keywords(): array
    {
        return [];
    }

    public function version(): string
    {
        return '1.1.0';
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
     * Generate a human-readable profile summary grouped by category.
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
            $grouped[$cat][] = $fact['value'];
        }

        $categoryLabels = [
            'profession' => 'Profession',
            'personal'   => 'Infos personnelles',
            'preference' => 'Preferences',
            'project'    => 'Projets',
            'behavior'   => 'Comportement',
            'goal'       => 'Objectifs',
            'general'    => 'Divers',
        ];

        $lines = [];
        foreach ($categoryLabels as $cat => $label) {
            if (!empty($grouped[$cat])) {
                $lines[] = "*{$label}* : " . implode(', ', $grouped[$cat]);
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
            $topFacts = array_slice($existingFacts, 0, 10);
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
- "profession" : metier, competences techniques, stack, experience, certifications
- "preference" : gouts, style de communication, humour, langue preferee, hobbies
- "personal"   : nom, age, localisation, situation familiale, nationalite
- "project"    : projets en cours, technologies utilisees
- "behavior"   : habitudes, horaires, frequence d'utilisation, rythme de travail
- "goal"       : objectifs personnels, ambitions, plans futurs

REGLES:
- N'extrais QUE les faits DURABLES et PERSONNELS (pas les questions ponctuelles)
- score entre 0.1 (peu fiable/implicite) et 1.0 (explicite et certain)
- key doit etre un identifiant court en snake_case (ex: "tech_stack", "humor_level", "profession")
- Si le fait CONTREDIT un fait deja connu, mets la cle du fait existant dans "contradicts"
- Si AUCUN fait personnel n'est exprime, reponds: {"facts": []}
- Maximum {$maxFacts} faits par message
- Ne repete pas un fait deja connu avec la meme valeur (sauf contradiction)

EXEMPLES:
- "je suis dev Laravel depuis 5 ans"
  → {"facts": [{"key": "profession", "value": "Developpeur Laravel, 5 ans d'experience", "category": "profession", "score": 1.0, "contradicts": null}]}
- "j'aime les blagues sombres"
  → {"facts": [{"key": "humor_style", "value": "Apprecie l'humour noir/sombre", "category": "preference", "score": 0.9, "contradicts": null}]}
- "j'ai demenage a Lyon" (si "location: Habite a Paris" deja connu)
  → {"facts": [{"key": "location", "value": "Habite a Lyon", "category": "personal", "score": 1.0, "contradicts": "location"}]}
- "mon objectif est de devenir CTO"
  → {"facts": [{"key": "career_goal", "value": "Ambition de devenir CTO", "category": "goal", "score": 0.9, "contradicts": null}]}
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

            $valid[] = [
                'key'        => preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $fact['key'])),
                'value'      => mb_substr(trim((string) $fact['value']), 0, 500),
                'category'   => $category,
                'score'      => $score,
                'contradicts'=> !empty($fact['contradicts']) ? (string) $fact['contradicts'] : null,
                'timestamp'  => time(),
            ];
        }

        return array_slice($valid, 0, self::MAX_FACTS_PER_MESSAGE);
    }
}
