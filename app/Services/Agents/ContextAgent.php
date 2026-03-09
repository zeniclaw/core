<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\ContextMemoryBridge;
use Illuminate\Support\Facades\Log;

class ContextAgent extends BaseAgent
{
    private ContextMemoryBridge $bridge;

    public function __construct()
    {
        parent::__construct();
        $this->bridge = ContextMemoryBridge::getInstance();
    }

    public function name(): string
    {
        return 'context_memory_bridge';
    }

    public function description(): string
    {
        return 'Memoire conversationnelle intelligente inter-agents. Extrait entites (projets, taches, dates, domaines) de chaque message et maintient un cache Redis chaud partage entre tous les agents pour enrichir les decisions.';
    }

    public function keywords(): array
    {
        return [
            'contexte', 'context', 'memoire partagee', 'shared memory',
            'inter-agent', 'bridge', 'cache', 'profil contextuel',
        ];
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (!$body) {
            return AgentResult::reply('Je suis le ContextMemoryBridge. Je travaille en arriere-plan pour partager le contexte entre tous les agents.');
        }

        // Show current context command
        if (preg_match('/^(show|affiche|voir)\s*(context|contexte|bridge)/iu', $body)) {
            return $this->showContext($context);
        }

        // Clear context command
        if (preg_match('/^(clear|efface|reset)\s*(context|contexte|bridge)/iu', $body)) {
            $this->bridge->clearContext($context->from);
            return AgentResult::reply('Contexte partage reinitialise.');
        }

        return AgentResult::reply($this->bridge->formatForPrompt($context->from) ?: 'Aucun contexte partage pour le moment.');
    }

    /**
     * Extract entities from an incoming message and update the shared context.
     * Called by RouterAgent before routing — must be fast and non-blocking.
     */
    public function extractAndUpdate(AgentContext $context): void
    {
        $body = trim($context->body ?? '');
        if (!$body || mb_strlen($body) < 5) {
            return;
        }

        try {
            $this->extractEntities($context->from, $body);
        } catch (\Throwable $e) {
            Log::warning('ContextAgent: extractAndUpdate failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract entities from text and update context bridge.
     */
    private function extractEntities(string $userId, string $text): void
    {
        $prompt = <<<PROMPT
Analyse ce message et extrait les entites pertinentes en JSON.
Ne reponds QUE avec le JSON, rien d'autre.

Message: "{$text}"

Format attendu:
{
  "projects": ["nom_projet1"],
  "tags": ["tag1", "tag2"],
  "dates": ["2026-03-15"],
  "domains": ["web", "finance"],
  "intent": "action_principale"
}

Regles:
- projects: noms de projets mentionnes (vides si aucun)
- tags: mots-cles importants (max 5)
- dates: dates mentionnees au format ISO (vides si aucune)
- domains: domaines techniques/thematiques (max 3)
- intent: l'intention principale en 1-2 mots (ex: "creer_tache", "consulter", "modifier")

Si le message est trivial (salut, ok, merci), retourne des arrays vides.
PROMPT;

        $response = $this->claude->chat(
            $prompt,
            'claude-haiku-4-5-20251001',
            'Tu es un extracteur d\'entites. Reponds uniquement en JSON valide.'
        );

        if (!$response) return;

        $clean = trim($response);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        $parsed = json_decode($clean, true);
        if (!$parsed) return;

        // Update projects
        $projects = $parsed['projects'] ?? [];
        foreach ($projects as $project) {
            if (is_string($project) && mb_strlen($project) > 1) {
                $this->bridge->addActiveProject($userId, $project);
            }
        }

        // Update tags
        $tags = $parsed['tags'] ?? [];
        $validTags = array_filter($tags, fn($t) => is_string($t) && mb_strlen($t) > 1);
        if (!empty($validTags)) {
            $this->bridge->addTags($userId, array_values($validTags));
        }

        // Store intent and domains as preferences metadata
        $domains = $parsed['domains'] ?? [];
        if (!empty($domains)) {
            $this->bridge->updateContext($userId, [
                'preferences' => ['recent_domains' => implode(',', array_slice($domains, 0, 3))],
            ]);
        }

        $intent = $parsed['intent'] ?? null;
        if ($intent && is_string($intent)) {
            $this->bridge->updateContext($userId, [
                'preferences' => ['last_intent' => $intent],
            ]);
        }
    }

    private function showContext(AgentContext $context): AgentResult
    {
        $ctx = $this->bridge->getContext($context->from);

        $lines = ["*Contexte partage (ContextMemoryBridge)*\n"];

        $projects = $ctx['activeProjects'] ?? [];
        $lines[] = "Projets actifs: " . ($projects ? implode(', ', $projects) : 'aucun');

        $tags = $ctx['recentTags'] ?? [];
        $lines[] = "Tags recents: " . ($tags ? implode(', ', $tags) : 'aucun');

        $lastAgent = $ctx['lastAgent']['name'] ?? 'aucun';
        $lines[] = "Dernier agent: {$lastAgent}";

        $tz = $ctx['timeZone'] ?? 'non defini';
        $lines[] = "Fuseau horaire: {$tz}";

        $prefs = $ctx['preferences'] ?? [];
        if ($prefs) {
            $prefLines = [];
            foreach ($prefs as $k => $v) {
                $prefLines[] = "  {$k}: {$v}";
            }
            $lines[] = "Preferences:\n" . implode("\n", $prefLines);
        }

        $history = $ctx['conversationHistory'] ?? [];
        $lines[] = "Historique: " . count($history) . " entrees";

        $updatedAt = $ctx['updated_at'] ?? 'jamais';
        $lines[] = "\nMis a jour: {$updatedAt}";

        return AgentResult::reply(implode("\n", $lines));
    }
}
