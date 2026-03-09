<?php

namespace App\Services\Agents;

use App\Models\ConversationMemory;
use App\Services\AgentContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ConversationMemoryAgent extends BaseAgent
{
    private const CACHE_TTL = 3600; // 1 hour
    private const MAX_RELEVANT_FACTS = 5;
    private const VALID_FACT_TYPES = ['project', 'preference', 'decision', 'skill', 'constraint'];

    public function name(): string
    {
        return 'conversation_memory';
    }

    public function description(): string
    {
        return 'Memorise le contexte conversationnel entre sessions. Detecte projets, decisions, preferences et competences pour enrichir automatiquement le contexte des autres agents.';
    }

    public function keywords(): array
    {
        return [
            'souviens', 'remember', 'memoire', 'memory', 'oublie', 'forget',
            'rappelle-toi', 'tu te souviens', 'contexte', 'historique',
            'qu est-ce que je faisais', 'dernier projet', 'on parlait de',
        ];
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (!$body) {
            return AgentResult::reply('Je n\'ai pas compris. Que voudrais-tu que je memorise ?');
        }

        // Direct commands
        if (preg_match('/^(show|affiche|liste)\s*(memories|memoire|souvenirs)/iu', $body)) {
            return $this->showMemories($context);
        }

        if (preg_match('/^(forget|oublie|supprime)\s+(.+)/iu', $body, $m)) {
            return $this->forgetMemory($context, trim($m[2]));
        }

        // Default: extract and store facts from the message
        return $this->extractAndStore($context, $body);
    }

    /**
     * Extract memorable facts from a message using Claude and store them.
     * Called in parallel during routing — does not block.
     */
    public function extractFactsInBackground(AgentContext $context): void
    {
        $body = trim($context->body ?? '');
        if (!$body || mb_strlen($body) < 10) {
            return;
        }

        try {
            $existingFacts = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->orderByDesc('updated_at')
                ->take(20)
                ->get()
                ->map(fn($m) => "[{$m->fact_type}] {$m->content}")
                ->implode("\n");

            $prompt = "Message utilisateur: \"{$body}\"\n\n";
            if ($existingFacts) {
                $prompt .= "Faits deja memorises:\n{$existingFacts}\n\n";
            }

            $response = $this->claude->chat(
                $prompt,
                'claude-haiku-4-5-20251001',
                <<<'SYSTEM'
Tu es un extracteur de faits memorables. Analyse le message et extrais UNIQUEMENT les faits importants a retenir pour les futures conversations.

Types de faits:
- project: nom de projet, techno utilisee, stack, URL
- preference: preferences personnelles, habitudes, style de travail
- decision: decisions techniques ou personnelles prises
- skill: competences, langages maitrises, domaines d'expertise
- constraint: contraintes, deadlines, limitations connues

Regles:
1. Ne memorise PAS les salutations, questions generiques ou small talk
2. Ne duplique PAS un fait deja memorise (verifie la liste existante)
3. Si un fait existant doit etre MIS A JOUR (ex: changement de projet), retourne-le avec action "update"
4. Si l'utilisateur semble abandonner/finir un projet, retourne action "archive" pour l'ancien

Reponds en JSON array (ou [] si rien a memoriser):
[{"fact_type": "project|preference|decision|skill|constraint", "content": "...", "tags": ["tag1"], "action": "create|update|archive", "match_content": "contenu existant a mettre a jour (si update/archive)"}]
SYSTEM
            );

            $this->processExtractedFacts($context->from, $response);
        } catch (\Throwable $e) {
            Log::warning('ConversationMemoryAgent: extraction failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Retrieve the most relevant facts for the current context.
     */
    public function getRelevantFacts(string $userId, ?string $currentMessage = null): array
    {
        $cacheKey = "user:{$userId}:conversation_memory";

        // Try cache first
        $cached = Cache::get($cacheKey);
        if ($cached && !$currentMessage) {
            return $cached;
        }

        $facts = ConversationMemory::forUser($userId)
            ->active()
            ->notExpired()
            ->orderByDesc('updated_at')
            ->take(self::MAX_RELEVANT_FACTS)
            ->get()
            ->map(fn($m) => [
                'fact_type' => $m->fact_type,
                'content' => $m->content,
                'tags' => $m->tags ?? [],
            ])
            ->toArray();

        // Cache for future use
        Cache::put($cacheKey, $facts, self::CACHE_TTL);

        return $facts;
    }

    /**
     * Format facts for injection into agent system prompts.
     */
    public function formatFactsForPrompt(string $userId, ?string $currentMessage = null): string
    {
        $facts = $this->getRelevantFacts($userId, $currentMessage);

        if (empty($facts)) {
            return '';
        }

        $lines = ['CONTEXTE MEMORISE (souvenirs des conversations precedentes):'];
        foreach ($facts as $fact) {
            $type = strtoupper($fact['fact_type']);
            $lines[] = "- [{$type}] {$fact['content']}";
        }

        return implode("\n", $lines);
    }

    private function showMemories(AgentContext $context): AgentResult
    {
        $memories = ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->orderByDesc('updated_at')
            ->take(15)
            ->get();

        if ($memories->isEmpty()) {
            return AgentResult::reply("Aucun souvenir memorise pour le moment. Je retiendrai automatiquement les informations importantes de nos conversations.");
        }

        $lines = ["*Mes souvenirs ({$memories->count()}):*\n"];
        $typeEmojis = [
            'project' => '📁',
            'preference' => '⚙️',
            'decision' => '✅',
            'skill' => '💡',
            'constraint' => '⚠️',
        ];

        foreach ($memories as $m) {
            $emoji = $typeEmojis[$m->fact_type] ?? '📝';
            $date = $m->updated_at->format('d/m');
            $lines[] = "{$emoji} [{$m->fact_type}] {$m->content} _({$date})_";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function forgetMemory(AgentContext $context, string $search): AgentResult
    {
        $memories = ConversationMemory::forUser($context->from)
            ->active()
            ->where('content', 'like', "%{$search}%")
            ->get();

        if ($memories->isEmpty()) {
            return AgentResult::reply("Aucun souvenir trouve contenant \"{$search}\".");
        }

        $count = $memories->count();
        foreach ($memories as $m) {
            $m->update(['status' => 'archived']);
        }

        $this->clearCache($context->from);

        return AgentResult::reply("J'ai archive {$count} souvenir(s) contenant \"{$search}\".");
    }

    private function extractAndStore(AgentContext $context, string $body): AgentResult
    {
        $this->extractFactsInBackground($context);

        $memories = ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->orderByDesc('updated_at')
            ->take(5)
            ->get();

        if ($memories->isEmpty()) {
            return AgentResult::reply("Message analyse. Je memoriserai les informations importantes au fil de nos conversations.");
        }

        $lines = ["J'ai mis a jour ma memoire. Voici ce que je retiens:\n"];
        foreach ($memories->take(3) as $m) {
            $lines[] = "- [{$m->fact_type}] {$m->content}";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function processExtractedFacts(string $userId, ?string $response): void
    {
        if (!$response) return;

        $clean = trim($response);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        if (!str_starts_with($clean, '[') && preg_match('/(\[.*\])/s', $clean, $m)) {
            $clean = $m[1];
        }

        $facts = json_decode($clean, true);
        if (!is_array($facts) || empty($facts)) {
            return;
        }

        foreach ($facts as $fact) {
            if (empty($fact['content']) || empty($fact['fact_type'])) continue;
            if (!in_array($fact['fact_type'], self::VALID_FACT_TYPES)) continue;

            $action = $fact['action'] ?? 'create';

            if ($action === 'archive' && !empty($fact['match_content'])) {
                ConversationMemory::forUser($userId)
                    ->active()
                    ->where('content', 'like', '%' . $fact['match_content'] . '%')
                    ->update(['status' => 'archived']);
                continue;
            }

            if ($action === 'update' && !empty($fact['match_content'])) {
                $existing = ConversationMemory::forUser($userId)
                    ->active()
                    ->where('content', 'like', '%' . $fact['match_content'] . '%')
                    ->first();

                if ($existing) {
                    $existing->update([
                        'content' => $fact['content'],
                        'tags' => $fact['tags'] ?? $existing->tags,
                    ]);
                    continue;
                }
            }

            // Check for duplicate before creating
            $duplicate = ConversationMemory::forUser($userId)
                ->active()
                ->where('fact_type', $fact['fact_type'])
                ->where('content', $fact['content'])
                ->exists();

            if (!$duplicate) {
                ConversationMemory::create([
                    'user_id' => $userId,
                    'fact_type' => $fact['fact_type'],
                    'content' => $fact['content'],
                    'tags' => $fact['tags'] ?? [],
                    'status' => 'active',
                ]);
            }
        }

        $this->clearCache($userId);
    }

    private function clearCache(string $userId): void
    {
        Cache::forget("user:{$userId}:conversation_memory");
    }
}
