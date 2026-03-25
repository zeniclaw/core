<?php

namespace App\Services\Agents;

use App\Models\ConversationMemory;
use App\Services\AgentContext;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ConversationMemoryAgent extends BaseAgent
{
    private const CACHE_TTL = 3600; // 1 hour
    private const MAX_RELEVANT_FACTS = 5;
    private const VALID_FACT_TYPES = ['project', 'preference', 'decision', 'skill', 'constraint'];

    private const TYPE_LABELS = [
        'project'    => 'Projet',
        'preference' => 'Preference',
        'decision'   => 'Decision',
        'skill'      => 'Competence',
        'constraint' => 'Contrainte',
    ];

    private const TYPE_EMOJIS = [
        'project'    => '📁',
        'preference' => '⚙️',
        'decision'   => '✅',
        'skill'      => '💡',
        'constraint' => '⚠️',
    ];

    public function name(): string
    {
        return 'conversation_memory';
    }

    public function description(): string
    {
        return 'Memorise le contexte conversationnel entre sessions. Detecte projets, decisions, preferences et competences pour enrichir automatiquement le contexte des autres agents. Permet aussi l\'ajout manuel, la recherche, le filtrage et les statistiques de souvenirs.';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function keywords(): array
    {
        return [
            'souviens', 'remember', 'memoire', 'memory', 'oublie', 'forget',
            'rappelle-toi', 'tu te souviens', 'contexte', 'historique',
            'qu est-ce que je faisais', 'dernier projet', 'on parlait de',
            'retiens', 'n oublie pas', 'garde en tete', 'note que',
            'affiche souvenirs', 'liste souvenirs', 'mes souvenirs',
            'cherche souvenir', 'trouve souvenir', 'search memory',
            'stats memoire', 'statistiques memoire', 'combien de souvenirs',
            'efface tout', 'vide memoire', 'supprimer tout souvenir',
            'mes projets memoire', 'mes decisions', 'mes competences',
            'ajoute memoire', 'nouvelle memoire', 'nouveau souvenir',
        ];
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower(trim($context->body ?? ''));
        foreach ($this->keywords() as $keyword) {
            if (str_contains($body, mb_strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (!$body) {
            return AgentResult::reply('Je n\'ai pas compris. Que voudrais-tu que je memorise ou recherche ?');
        }

        // Show all memories
        if (preg_match('/^(show|affiche|liste|voir|mes)\s*(memories|memoire|souvenirs?)/iu', $body)) {
            return $this->showMemories($context);
        }

        // Show memories filtered by type
        if (preg_match('/^(show|affiche|liste|mes)\s*(projets?|preferences?|decisions?|competences?|contraintes?)/iu', $body, $m)) {
            return $this->showMemoriesByType($context, $m[2]);
        }

        // Forget a specific memory (but not "forget all")
        if (preg_match('/^(forget|oublie|supprime|efface)\s+(?!tout\b)(.+)/iu', $body, $m)) {
            return $this->forgetMemory($context, trim($m[2]));
        }

        // Clear ALL memories (with confirmation)
        if (preg_match('/\b(efface tout|vide memoire|supprimer tout|oublie tout)\b/iu', $body)) {
            return $this->promptClearAll($context);
        }

        // Search memories
        if (preg_match('/^(cherche|trouve|search|recherche)\s+(?:souvenir|memoire|memory)s?\s+(.+)/iu', $body, $m)) {
            return $this->searchMemories($context, trim($m[2]));
        }

        // Memory stats
        if (preg_match('/\b(stats?|statistiques?|combien de souvenirs?)\b/iu', $body)) {
            return $this->showStats($context);
        }

        // Direct add: "note: ...", "note projet: ...", "retiens que ...", "n'oublie pas ...", "ajoute memoire: ..."
        if (preg_match('/^(?:note|retiens que|ajoute memoire|nouveau souvenir|n oublie pas|n\'oublie pas|garde en tete)[:\s]+(?:(projet|preference|decision|skill|competence|contrainte)[:\s]+)?(.+)/iu', $body, $m)) {
            $rawType = null;
            $content = '';
            if (!empty($m[1]) && !empty($m[2])) {
                $rawType = $m[1];
                $content = trim($m[2]);
            } elseif (!empty($m[1])) {
                $content = trim($m[1]);
            }
            if (empty($content)) {
                return AgentResult::reply('Donne-moi quelque chose a memoriser apres "note:".');
            }
            return $this->addMemoryDirectly($context, $content, $rawType);
        }

        // Default: extract and store facts from the message
        return $this->extractAndStore($context, $body);
    }

    /**
     * Handle follow-up messages (e.g. confirmation for "clear all").
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if (($pendingContext['type'] ?? '') !== 'clear_all_confirmation') {
            return null;
        }

        $body = mb_strtolower(trim($context->body ?? ''));
        $this->clearPendingContext($context);

        if (preg_match('/\b(oui|yes|ok|confirme?r?|vide|efface|supprime)\b/iu', $body)) {
            return $this->executeClearAll($context);
        }

        $reply = "Annule. Tous tes souvenirs sont conserves.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    /**
     * Extract memorable facts from a message using Claude and store them.
     * Called in parallel during routing — does not block.
     */
    public function extractFactsInBackground(AgentContext $context): void
    {
        $body = trim($context->body ?? '');
        if (!$body) {
            return;
        }
        if (mb_strlen($body) < 10) {
            Log::debug('[ConversationMemory] Skipped short message', ['len' => mb_strlen($body)]);
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
                ModelResolver::fast(),
                <<<'SYSTEM'
Tu es un extracteur de faits memorables. Analyse le message et extrais TOUT fait utile a retenir pour personnaliser les futures conversations, meme si le fait est implicite ou mentionne en passant.

Types de faits:
- project: nom de projet, technologie utilisee, stack technique, URL de depot, domaine metier
- preference: preferences implicites ou explicites, langue utilisee, ton employe, outils, habitudes, style de travail
- decision: decisions techniques ou personnelles, choix d'architecture, orientations prises
- skill: competences maitrisees, langages de programmation, domaines d'expertise, niveaux
- constraint: contraintes de temps, deadlines, limitations techniques ou personnelles

Exemples de faits a extraire (y compris les implicites):
- "Je travaille sur ZeniClaw avec Laravel 12" -> project: "Projet ZeniClaw - Laravel 12"
- "Je prefere TypeScript a JavaScript" -> preference: "Prefere TypeScript a JavaScript"
- "On a decide d'utiliser Redis pour le cache" -> decision: "Utilisation de Redis pour le cache"
- "Je maitrise Python et PHP" -> skill: "Maitrise Python et PHP"
- "Le projet doit etre fini avant le 1er avril" -> constraint: "Deadline: 1er avril"
- "salut, t'as une minute ?" -> preference: "Utilise un ton informel (tutoiement)"
- "bonjour, je cherche de l'aide" -> preference: "Communique en francais"
- "je suis dev freelance" -> skill: "Developpeur freelance"
- "mon client veut une appli mobile" -> project: "Projet appli mobile pour client"
- "j'ai pas trop le temps la" -> constraint: "Peu de disponibilite"

Exemple de reponse JSON pour un message banal comme "salut, ca va ?":
[{"fact_type": "preference", "content": "Utilise un ton informel et familier", "tags": ["langue", "ton"], "action": "create"}]

Regles:
1. Seuil bas : extrais tout fait meme partiellement utile, y compris les preferences implicites de langue, ton et style
2. Ne duplique PAS un fait deja memorise (verifie attentivement la liste existante)
3. Si un fait existant doit etre MIS A JOUR (ex: changement de version, de projet), retourne action "update" avec match_content
4. Si l'utilisateur abandonne/termine un projet, retourne action "archive" pour l'ancien
5. Contenu concis et clair (max 100 caracteres)
6. Retourne [] UNIQUEMENT si le message ne contient vraiment aucune information contextualisable

Reponds UNIQUEMENT en JSON array (ou [] si absolument rien a memoriser):
[{"fact_type": "project|preference|decision|skill|constraint", "content": "...", "tags": ["tag1"], "action": "create|update|archive", "match_content": "contenu existant a mettre a jour (si update/archive seulement)"}]
SYSTEM
            );

            $saved = $this->processExtractedFacts($context->from, $response);

            Log::info('[ConversationMemory] Facts extracted', ['count' => $saved, 'from' => substr($context->from, -4)]);

            if ($saved === 0) {
                Log::debug('ConversationMemoryAgent: aucun fait extrait', [
                    'body_length' => mb_strlen($body),
                ]);
                Log::warning('ConversationMemoryAgent: 0 facts saved for non-trivial message', [
                    'user_id'      => $context->from,
                    'message'      => substr($body, 0, 100),
                    'raw_response' => substr($response ?? '', 0, 200),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ConversationMemoryAgent: background extraction failed', [
                'error'    => $e->getMessage(),
                'user_id'  => $context->from,
                'body_len' => mb_strlen($body),
                'message'  => substr($body, 0, 100),
            ]);
        }
    }

    /**
     * Retrieve the most relevant facts for the current context.
     */
    public function getRelevantFacts(string $userId, ?string $currentMessage = null): array
    {
        $cacheKey = "user:{$userId}:conversation_memory";

        // Try cache first (only when no specific message to filter by)
        $cached = Cache::get($cacheKey);
        if ($cached !== null && !$currentMessage) {
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
                'content'   => $m->content,
                'tags'      => $m->tags ?? [],
            ])
            ->toArray();

        // Cache when not filtered by message
        if (!$currentMessage) {
            Cache::put($cacheKey, $facts, self::CACHE_TTL);
        }

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
            ->orderBy('fact_type')
            ->orderByDesc('updated_at')
            ->take(20)
            ->get();

        if ($memories->isEmpty()) {
            return AgentResult::reply("Aucun souvenir memorise pour le moment.\n\nJe retiendrai automatiquement les informations importantes de nos conversations. Tu peux aussi ajouter manuellement : note: <ton info>");
        }

        $grouped = $memories->groupBy('fact_type');
        $lines = ["*Mes souvenirs ({$memories->count()}) :*\n"];

        foreach (self::VALID_FACT_TYPES as $type) {
            if (!$grouped->has($type)) continue;
            $emoji = self::TYPE_EMOJIS[$type] ?? '📝';
            $label = self::TYPE_LABELS[$type] ?? ucfirst($type);
            $lines[] = "*{$emoji} {$label}s :*";
            foreach ($grouped[$type] as $m) {
                $date = $m->updated_at->format('d/m');
                $lines[] = "  * {$m->content} ({$date})";
            }
            $lines[] = '';
        }

        $lines[] = "Pour oublier : oublie <mot-cle>";

        return AgentResult::reply(implode("\n", $lines));
    }

    private function showMemoriesByType(AgentContext $context, string $rawType): AgentResult
    {
        $typeMap = [
            'projet'      => 'project',
            'projets'     => 'project',
            'project'     => 'project',
            'preference'  => 'preference',
            'preferences' => 'preference',
            'decision'    => 'decision',
            'decisions'   => 'decision',
            'competence'  => 'skill',
            'competences' => 'skill',
            'skill'       => 'skill',
            'contrainte'  => 'constraint',
            'contraintes' => 'constraint',
        ];

        $factType = $typeMap[mb_strtolower($rawType)] ?? null;
        if (!$factType) {
            return $this->showMemories($context);
        }

        $memories = ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->where('fact_type', $factType)
            ->orderByDesc('updated_at')
            ->take(15)
            ->get();

        $emoji = self::TYPE_EMOJIS[$factType] ?? '📝';
        $label = self::TYPE_LABELS[$factType] ?? ucfirst($factType);

        if ($memories->isEmpty()) {
            return AgentResult::reply("Aucun souvenir de type {$label} pour le moment.");
        }

        $lines = ["{$emoji} {$label}s ({$memories->count()}) :\n"];
        foreach ($memories as $m) {
            $date = $m->updated_at->format('d/m/y');
            $lines[] = "* {$m->content} ({$date})";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function searchMemories(AgentContext $context, string $keyword): AgentResult
    {
        if (mb_strlen($keyword) < 2) {
            return AgentResult::reply("Donne-moi un mot-cle d'au moins 2 caracteres pour chercher.");
        }

        $memories = ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->where('content', 'like', "%{$keyword}%")
            ->orderByDesc('updated_at')
            ->take(10)
            ->get();

        if ($memories->isEmpty()) {
            return AgentResult::reply("Aucun souvenir contenant \"{$keyword}\" trouve.");
        }

        $lines = ["Resultats pour \"{$keyword}\" ({$memories->count()}) :\n"];
        foreach ($memories as $m) {
            $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
            $date = $m->updated_at->format('d/m');
            $lines[] = "{$emoji} {$m->content} ({$m->fact_type}, {$date})";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function showStats(AgentContext $context): AgentResult
    {
        $total = ConversationMemory::forUser($context->from)->active()->notExpired()->count();

        if ($total === 0) {
            return AgentResult::reply("Aucun souvenir en memoire pour le moment.");
        }

        $counts = ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->selectRaw('fact_type, COUNT(*) as cnt')
            ->groupBy('fact_type')
            ->pluck('cnt', 'fact_type')
            ->toArray();

        $oldest = ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->orderBy('created_at')
            ->first();

        $lines = ["Statistiques memoire :\n"];
        $lines[] = "Total : {$total} souvenir(s)\n";

        foreach (self::VALID_FACT_TYPES as $type) {
            if (!isset($counts[$type])) continue;
            $emoji = self::TYPE_EMOJIS[$type] ?? '📝';
            $label = self::TYPE_LABELS[$type] ?? ucfirst($type);
            $lines[] = "{$emoji} {$label}s : {$counts[$type]}";
        }

        if ($oldest) {
            $lines[] = "\nPremier souvenir : " . $oldest->created_at->format('d/m/Y');
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function addMemoryDirectly(AgentContext $context, string $content, ?string $rawType): AgentResult
    {
        $content = trim($content);
        if (mb_strlen($content) < 3) {
            return AgentResult::reply("Le contenu du souvenir est trop court. Donne-moi plus de details.");
        }

        if (mb_strlen($content) > 200) {
            $content = mb_substr($content, 0, 200);
        }

        $typeMap = [
            'projet'      => 'project',
            'project'     => 'project',
            'preference'  => 'preference',
            'decision'    => 'decision',
            'skill'       => 'skill',
            'competence'  => 'skill',
            'contrainte'  => 'constraint',
            'constraint'  => 'constraint',
        ];

        $factType = $rawType ? ($typeMap[mb_strtolower($rawType)] ?? null) : null;

        // If no valid type, infer via Claude
        if (!$factType) {
            try {
                $inferredResponse = $this->claude->chat(
                    "Contenu: \"{$content}\"\n\nTypes disponibles: project, preference, decision, skill, constraint\n\nReponds avec UNIQUEMENT le type le plus approprie (un mot).",
                    ModelResolver::fast(),
                    'Tu es un classificateur de faits. Reponds UNIQUEMENT avec un seul mot parmi: project, preference, decision, skill, constraint.'
                );
                $inferred = trim(strtolower($inferredResponse ?? ''));
                $factType = in_array($inferred, self::VALID_FACT_TYPES) ? $inferred : 'preference';
            } catch (\Throwable $e) {
                $factType = 'preference';
            }
        }

        // Check for duplicate
        $duplicate = ConversationMemory::forUser($context->from)
            ->active()
            ->where('fact_type', $factType)
            ->where('content', $content)
            ->exists();

        if ($duplicate) {
            $emoji = self::TYPE_EMOJIS[$factType] ?? '📝';
            return AgentResult::reply("{$emoji} Ce souvenir existe deja : \"{$content}\"");
        }

        ConversationMemory::create([
            'user_id'   => $context->from,
            'fact_type' => $factType,
            'content'   => $content,
            'tags'      => [],
            'status'    => 'active',
        ]);

        $this->clearCache($context->from);

        $emoji = self::TYPE_EMOJIS[$factType] ?? '📝';
        $label = self::TYPE_LABELS[$factType] ?? ucfirst($factType);
        return AgentResult::reply("{$emoji} Souvenir ajoute !\nType : {$label}\nContenu : {$content}");
    }

    private function promptClearAll(AgentContext $context): AgentResult
    {
        $total = ConversationMemory::forUser($context->from)->active()->notExpired()->count();

        if ($total === 0) {
            return AgentResult::reply("Ta memoire est deja vide, rien a effacer.");
        }

        $this->setPendingContext($context, 'clear_all_confirmation', [], 2);

        $reply = "Attention ! Tu as {$total} souvenir(s) en memoire.\n\nCette action va tout effacer et est irreversible.\n\nReplier oui pour confirmer, ou tout autre message pour annuler.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    private function executeClearAll(AgentContext $context): AgentResult
    {
        $count = ConversationMemory::forUser($context->from)
            ->active()
            ->update(['status' => 'archived']);

        $this->clearCache($context->from);

        $reply = "Memoire effacee. {$count} souvenir(s) archive(s).\n\nJe repartirai de zero pour les prochaines conversations.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    private function forgetMemory(AgentContext $context, string $search): AgentResult
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return AgentResult::reply("Donne-moi un mot-cle pour identifier le souvenir a oublier.");
        }

        $memories = ConversationMemory::forUser($context->from)
            ->active()
            ->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                  ->orWhereJsonContains('tags', $search);
            })
            ->get();

        if ($memories->isEmpty()) {
            $hint = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->take(5)
                ->pluck('content')
                ->implode(', ');

            $hintText = $hint ? "\n\nSouvenirs disponibles : {$hint}" : '';
            return AgentResult::reply("Aucun souvenir trouve contenant \"{$search}\".{$hintText}");
        }

        $count = $memories->count();
        foreach ($memories as $m) {
            $m->update(['status' => 'archived']);
        }

        $this->clearCache($context->from);

        $label = $count === 1 ? 'souvenir archive' : 'souvenirs archives';
        return AgentResult::reply("{$count} {$label} contenant \"{$search}\".");
    }

    private function extractAndStore(AgentContext $context, string $body): AgentResult
    {
        $countBefore = ConversationMemory::forUser($context->from)->active()->notExpired()->count();

        $this->extractFactsInBackground($context);

        $countAfter = ConversationMemory::forUser($context->from)->active()->notExpired()->count();
        $newCount = max(0, $countAfter - $countBefore);

        $recent = ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->orderByDesc('updated_at')
            ->take(3)
            ->get();

        if ($recent->isEmpty()) {
            return AgentResult::reply("Message analyse. Je memoriserai les informations importantes au fil de nos conversations.\n\nAstuce : tu peux aussi ajouter manuellement avec \"note: <info>\"");
        }

        $lines = $newCount > 0
            ? ["{$newCount} nouveau(x) souvenir(s) ajoute(s) :\n"]
            : ["Souvenirs recents :\n"];

        foreach ($recent as $m) {
            $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
            $lines[] = "{$emoji} {$m->content}";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function processExtractedFacts(string $userId, ?string $response): int
    {
        if (!$response) {
            Log::debug('[ConversationMemoryAgent] No facts extracted', [
                'reason'  => 'empty_response',
                'user_id' => $userId,
            ]);
            return 0;
        }

        $clean = trim($response);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        if (!str_starts_with($clean, '[') && preg_match('/(\[.*\])/s', $clean, $m)) {
            $clean = $m[1];
        }

        $facts = json_decode($clean, true);
        if (!is_array($facts) || empty($facts)) {
            Log::warning('[ConversationMemoryAgent] No facts extracted', [
                'reason'       => 'json_error',
                'user_id'      => $userId,
                'raw_response' => substr($response, 0, 200),
                'json_error'   => json_last_error_msg(),
            ]);
            return 0;
        }

        $saved = 0;
        $total = count($facts);

        foreach ($facts as $fact) {
            if (empty($fact['content']) || empty($fact['fact_type'])) continue;
            if (!in_array($fact['fact_type'], self::VALID_FACT_TYPES)) {
                Log::debug('ConversationMemoryAgent: rejected fact with invalid type', [
                    'user_id'   => $userId,
                    'fact_type' => $fact['fact_type'],
                    'content'   => mb_substr($fact['content'] ?? '', 0, 80),
                ]);
                continue;
            }

            // Enforce content length limit
            $fact['content'] = mb_substr(trim($fact['content']), 0, 200);
            if (mb_strlen($fact['content']) < 3) continue;

            $action = $fact['action'] ?? 'create';

            if ($action === 'archive' && !empty($fact['match_content'])) {
                ConversationMemory::forUser($userId)
                    ->active()
                    ->where('content', 'like', '%' . $fact['match_content'] . '%')
                    ->update(['status' => 'archived']);
                $saved++;
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
                        'tags'    => $fact['tags'] ?? $existing->tags,
                    ]);
                    $saved++;
                    continue;
                }
                // Fall through to create if no match found
            }

            // Check for exact duplicate before creating
            $duplicate = ConversationMemory::forUser($userId)
                ->active()
                ->where('fact_type', $fact['fact_type'])
                ->where('content', $fact['content'])
                ->exists();

            if ($duplicate) {
                Log::debug('ConversationMemoryAgent: duplicate fact skipped', [
                    'user_id'   => $userId,
                    'fact_type' => $fact['fact_type'],
                    'content'   => mb_substr($fact['content'], 0, 80),
                ]);
            }

            if (!$duplicate) {
                ConversationMemory::create([
                    'user_id'   => $userId,
                    'fact_type' => $fact['fact_type'],
                    'content'   => $fact['content'],
                    'tags'      => $fact['tags'] ?? [],
                    'status'    => 'active',
                ]);
                $saved++;
            }
        }

        if ($saved === 0 && $total > 0) {
            Log::warning('[ConversationMemoryAgent] No facts extracted', [
                'reason'  => 'duplicate',
                'user_id' => $userId,
                'total'   => $total,
            ]);
        }

        Log::info('ConversationMemoryAgent: extraction', [
            'received' => $total,
            'saved'    => $saved,
            'user_id'  => $userId,
        ]);

        $this->clearCache($userId);

        return $saved;
    }

    private function clearCache(string $userId): void
    {
        Cache::forget("user:{$userId}:conversation_memory");
    }
}
