<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\ContextMemory\ContextStore;

class SmartContextAgent extends BaseAgent
{
    private ContextStore $contextStore;

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
        return 'Agent interne d\'extraction de contexte utilisateur. Analyse les messages pour extraire des faits personnels durables (profession, preferences, projets) et les stocker en memoire contextuelle. Fonctionne en arriere-plan, pas d\'interaction directe.';
    }

    public function keywords(): array
    {
        return [];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return true;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = $context->body;
        if (!$body || mb_strlen(trim($body)) < 5) {
            return AgentResult::silent(['reason' => 'message_too_short']);
        }

        $facts = $this->extractFacts($body, $context);

        if (!empty($facts)) {
            $this->contextStore->store($context->from, $facts);
            $this->log($context, 'Context facts extracted', [
                'facts_count' => count($facts),
                'facts' => array_map(fn($f) => $f['key'] . ': ' . $f['value'], $facts),
            ]);
        }

        // Cleanup old entries
        $this->contextStore->cleanup($context->from);

        return AgentResult::silent([
            'facts_extracted' => count($facts),
            'total_facts' => count($this->contextStore->retrieve($context->from)),
        ]);
    }

    public function getStoredContext(string $userId): array
    {
        return $this->contextStore->retrieve($userId);
    }

    private function extractFacts(string $message, AgentContext $context): array
    {
        $response = $this->claude->chat(
            "Message de {$context->senderName}: \"{$message}\"",
            'claude-haiku-4-5-20251001',
            $this->buildExtractionPrompt()
        );

        return $this->parseFactsResponse($response);
    }

    private function buildExtractionPrompt(): string
    {
        return <<<'PROMPT'
Tu es un extracteur de faits personnels. A partir du message, extrais les informations durables sur l'utilisateur.

Reponds UNIQUEMENT en JSON valide, sans markdown:
{"facts": [{"key": "identifiant_unique", "value": "description du fait", "category": "categorie", "score": 0.8}]}

CATEGORIES possibles:
- "profession" : metier, competences techniques, stack, experience
- "preference" : gouts, style de communication, humour, langue preferee
- "personal" : nom, age, localisation, situation
- "project" : projets en cours, technologies utilisees
- "behavior" : habitudes, horaires, frequence d'utilisation

REGLES:
- N'extrais QUE les faits DURABLES et PERSONNELS (pas les questions ponctuelles)
- score entre 0.1 (peu fiable/implicite) et 1.0 (explicite et certain)
- key doit etre un identifiant court en snake_case (ex: "tech_stack", "humor_level", "profession")
- Si AUCUN fait personnel n'est exprime, reponds: {"facts": []}
- Maximum 3 faits par message
- Ne repete pas des faits evidents ou generiques

EXEMPLES:
- "je suis dev Laravel depuis 5 ans" → {"facts": [{"key": "profession", "value": "Developpeur Laravel avec 5 ans d'experience", "category": "profession", "score": 1.0}]}
- "j'aime les blagues sombres" → {"facts": [{"key": "humor_style", "value": "Apprecie l'humour noir/sombre", "category": "preference", "score": 0.9}]}
- "quel temps fait-il ?" → {"facts": []}
- "je bosse sur un projet React Native" → {"facts": [{"key": "current_tech", "value": "Travaille sur un projet React Native", "category": "project", "score": 0.8}]}

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
            if (!empty($fact['key']) && !empty($fact['value'])) {
                $valid[] = [
                    'key' => $fact['key'],
                    'value' => $fact['value'],
                    'category' => $fact['category'] ?? 'general',
                    'score' => min(1.0, max(0.1, (float) ($fact['score'] ?? 0.5))),
                    'timestamp' => time(),
                ];
            }
        }

        return $valid;
    }
}
