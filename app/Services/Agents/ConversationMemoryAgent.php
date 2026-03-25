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
    private const MAX_PINNED_FACTS = 3;
    private const PINNED_TAG = '__pinned';
    private const SIMILARITY_THRESHOLD = 75; // % similarity to consider as duplicate
    private const EXTRACT_COOLDOWN = 5; // seconds between extractions per user
    private const MAX_MEMORIES_PER_USER = 100; // soft cap before suggesting cleanup
    private const VALID_FACT_TYPES = ['project', 'preference', 'decision', 'skill', 'constraint'];
    private const NOISE_PATTERNS = [
        '/^(salut|hello|hi|hey|bonjour|bonsoir|coucou|yo|slt)\s*[!.?]*$/iu',
        '/^(merci|thanks|ok|oui|non|d accord|ca marche|super|cool|top|nice|parfait)\s*[!.?]*$/iu',
        '/^[\p{So}\p{Sk}\s]+$/u', // emoji-only messages
        '/^https?:\/\/\S+$/i',     // pure URL messages
        '/^[👍👎❤️🔥😂🙏✅❌]+$/u',
        '/^(lol|mdr|haha|ptdr|xd|wtf|omg)\s*[!.?]*$/iu',
        '/^[\d\s.,;:!?]+$/u', // numbers-only messages
        '/^(quoi|hein|bah|bof|mouais|ah|oh|euh|hmm)\s*[!.?]*$/iu',
        '/^[.!?]{1,5}$/u', // punctuation-only messages
    ];

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
        return '1.9.0';
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
            'exporte memoire', 'export memory', 'exporter souvenirs',
            'timeline memoire', 'chronologie souvenirs', 'historique souvenirs',
            'resume memoire', 'memory summary',
            'epingle souvenir', 'pin memory', 'epingle memoire',
            'desepingle', 'unpin memory', 'desepingle souvenir',
            'mes epingles', 'pinned memories', 'souvenirs epingles',
            'aide memoire', 'help memory', 'commandes memoire',
            'restaure souvenir', 'restore memory', 'mes archives',
            'deduplique memoire', 'deduplicate memory', 'nettoie memoire',
            'cherche partout', 'search all', 'recherche globale',
            'fusionne souvenirs', 'merge memories', 'fusionner memoire',
            'analyse memoire', 'memory insights', 'tendances memoire',
            'modifie souvenir', 'edit memory', 'edite souvenir', 'modifier memoire',
            'tag souvenir', 'tag memory', 'ajoute tag', 'etiquette souvenir',
            'expire souvenir', 'expiration memoire', 'expire memory', 'set expiry',
            'rappel rapide', 'quick recall', 'rappel memoire', 'recall',
            'compare souvenirs', 'compare memories', 'comparer souvenirs',
            'archive type', 'archiver type', 'vide type',
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

        // Help command
        if (preg_match('/\b(aide memoire|help memory|commandes memoire)\b/iu', $body)) {
            return $this->showHelp();
        }

        // Restore archived memories
        if (preg_match('/^(restaure|restore|recupere)\s+(?:souvenir|memoire|memory)?\s*(.+)/iu', $body, $m)) {
            return $this->restoreMemory($context, trim($m[2]));
        }

        // Show archived memories
        if (preg_match('/\b(mes archives|archived|souvenirs archives)\b/iu', $body)) {
            return $this->showArchivedMemories($context);
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

        // Export memories
        if (preg_match('/\b(export|exporte|exporter)\s*(memoire|memory|souvenirs?)\b/iu', $body)) {
            return $this->exportMemories($context);
        }

        // Timeline / chronology
        if (preg_match('/\b(timeline|chronologie|historique souvenirs)\b/iu', $body)) {
            return $this->showTimeline($context);
        }

        // Memory summary
        if (preg_match('/\b(resume memoire|memory summary)\b/iu', $body)) {
            return $this->showSummary($context);
        }

        // Pin a memory
        if (preg_match('/^(epingle|pin)\s+(?:souvenir|memoire|memory)?\s*(.+)/iu', $body, $m)) {
            return $this->pinMemory($context, trim($m[2]));
        }

        // Unpin a memory
        if (preg_match('/^(desepingle|unpin)\s+(?:souvenir|memoire|memory)?\s*(.+)/iu', $body, $m)) {
            return $this->unpinMemory($context, trim($m[2]));
        }

        // Show pinned memories
        if (preg_match('/\b(mes epingles|pinned|souvenirs epingles)\b/iu', $body)) {
            return $this->showPinnedMemories($context);
        }

        // Deduplicate memories
        if (preg_match('/\b(deduplique|deduplicate|nettoie)\s*(memoire|memory|souvenirs?)\b/iu', $body)) {
            return $this->deduplicateMemories($context);
        }

        // Global search (cross-type with scoring)
        if (preg_match('/^(cherche partout|search all|recherche globale)\s+(.+)/iu', $body, $m)) {
            return $this->globalSearch($context, trim($m[2]));
        }

        // Merge similar memories
        if (preg_match('/\b(fusionne|merge|fusionner)\s*(?:souvenirs?|memories?|memoire)?\s+(.+)/iu', $body, $m)) {
            return $this->mergeMemories($context, trim($m[2]));
        }

        // Memory insights / analysis
        if (preg_match('/\b(analyse memoire|memory insights|tendances memoire)\b/iu', $body)) {
            return $this->showInsights($context);
        }

        // Edit a memory: "modifie souvenir Laravel -> Laravel 12"
        if (preg_match('/^(?:modifie|edit|edite|modifier)\s+(?:souvenir|memoire|memory)?\s*(.+?)\s*(?:->|=>|→)\s*(.+)/iu', $body, $m)) {
            return $this->editMemory($context, trim($m[1]), trim($m[2]));
        }

        // Tag a memory: "tag souvenir Laravel php,backend"
        if (preg_match('/^(?:tag|etiquette|ajoute tag)\s+(?:souvenir|memoire|memory)?\s*(.+?)\s+(?:avec\s+)?([a-zA-Z0-9àâéèêëïîôùûüÿçæœ, _-]+)$/iu', $body, $m)) {
            return $this->tagMemory($context, trim($m[1]), trim($m[2]));
        }

        // Set expiration: "expire souvenir Laravel 7 jours"
        if (preg_match('/^(?:expire|expiration)\s+(?:souvenir|memoire|memory)?\s*(.+?)\s+(\d+)\s*(jours?|heures?|days?|hours?|semaines?|weeks?)/iu', $body, $m)) {
            return $this->setExpiration($context, trim($m[1]), (int)$m[2], trim($m[3]));
        }

        // Quick recall: "rappel rapide Laravel" or "recall Laravel"
        if (preg_match('/^(?:rappel\s*(?:rapide)?|quick\s*recall|recall)\s+(.+)/iu', $body, $m)) {
            return $this->quickRecall($context, trim($m[1]));
        }

        // Compare two memories: "compare souvenirs Laravel vs Redis"
        if (preg_match('/^(?:compare|comparer)\s+(?:souvenirs?|memories?|memoire)?\s*(.+?)\s+(?:vs|versus|et|and)\s+(.+)/iu', $body, $m)) {
            return $this->compareMemories($context, trim($m[1]), trim($m[2]));
        }

        // Bulk archive by type: "archive type projet"
        if (preg_match('/^(?:archive|archiver|vide)\s+type\s+(.+)/iu', $body, $m)) {
            return $this->bulkArchiveByType($context, trim($m[1]));
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
        if ($this->isNoise($body)) {
            Log::debug('[ConversationMemory] Skipped noise message', ['body' => mb_substr($body, 0, 50)]);
            return;
        }

        // Rate limit: avoid hammering Claude for the same user
        $cooldownKey = "cm_extract:{$context->from}";
        if (Cache::has($cooldownKey)) {
            Log::debug('[ConversationMemory] Skipped: cooldown active', ['user' => substr($context->from, -4)]);
            return;
        }
        Cache::put($cooldownKey, true, self::EXTRACT_COOLDOWN);

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
2. Ne duplique PAS un fait deja memorise NI un fait similaire (verifie attentivement la liste existante). Deux contenus paraphrases sont des doublons.
3. Si un fait existant doit etre MIS A JOUR (ex: changement de version, de projet), retourne action "update" avec match_content contenant un extrait EXACT du fait existant
4. Si l'utilisateur abandonne/termine un projet, retourne action "archive" avec match_content contenant un extrait EXACT du fait existant
5. Contenu concis et clair (max 100 caracteres), en francais
6. Retourne [] UNIQUEMENT si le message ne contient vraiment aucune information contextualisable
7. Un seul fait par information — ne repete pas la meme info sous des types differents
8. Les tags doivent etre des mots-cles courts en minuscules et pertinents (ex: ["laravel", "php"], ["deadline", "avril"]), 1 a 3 tags max
9. Ignore les formules de politesse pures ("merci", "ok", "d'accord") sauf si elles revelent une preference de communication
10. Ne genere JAMAIS de fait sur le simple fait que l'utilisateur pose une question — concentre-toi sur les informations factuelles
11. Si le message contient une correction d'un fait existant, utilise "update" avec le match_content exact

Reponds UNIQUEMENT en JSON array (ou [] si absolument rien a memoriser):
[{"fact_type": "project|preference|decision|skill|constraint", "content": "...", "tags": ["tag1"], "action": "create|update|archive", "match_content": "contenu existant a mettre a jour (si update/archive seulement)"}]
SYSTEM
            );

            $saved = $this->processExtractedFacts($context->from, $response);

            Log::info('[ConversationMemory] Facts extracted', ['count' => $saved, 'from' => substr($context->from, -4)]);

            if ($saved === 0) {
                // Only warn for messages likely to contain facts (>30 chars)
                $level = mb_strlen($body) > 30 ? 'warning' : 'debug';
                Log::$level('[ConversationMemory] 0 facts saved', [
                    'user_id'      => $context->from,
                    'body_length'  => mb_strlen($body),
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

        // Pinned memories always come first
        $pinned = ConversationMemory::forUser($userId)
            ->active()
            ->notExpired()
            ->whereJsonContains('tags', self::PINNED_TAG)
            ->orderByDesc('updated_at')
            ->take(self::MAX_PINNED_FACTS)
            ->get();

        $pinnedIds = $pinned->pluck('id')->toArray();
        $remaining = self::MAX_RELEVANT_FACTS - $pinned->count();

        $regular = $remaining > 0
            ? ConversationMemory::forUser($userId)
                ->active()
                ->notExpired()
                ->whereNotIn('id', $pinnedIds)
                ->orderByDesc('updated_at')
                ->take($remaining)
                ->get()
            : collect();

        $isPinned = fn($m) => in_array(self::PINNED_TAG, $m->tags ?? []);

        $facts = $pinned->concat($regular)
            ->map(fn($m) => [
                'fact_type' => $m->fact_type,
                'content'   => $m->content,
                'tags'      => array_values(array_filter($m->tags ?? [], fn($t) => $t !== self::PINNED_TAG)),
                'pinned'    => $isPinned($m),
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
            $pin = !empty($fact['pinned']) ? ' [IMPORTANT]' : '';
            $lines[] = "- [{$type}]{$pin} {$fact['content']}";
        }

        return implode("\n", $lines);
    }

    private function showMemories(AgentContext $context): AgentResult
    {
        try {
            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->orderBy('fact_type')
                ->orderByDesc('updated_at')
                ->take(20)
                ->get();

            if ($memories->isEmpty()) {
                return AgentResult::reply("🧠 Aucun souvenir memorise pour le moment.\n\nJe retiendrai automatiquement les infos importantes de nos conversations.\n\n💡 _Tu peux aussi ajouter manuellement :_\n  note: <ton info>\n  note projet: <detail>");
            }

            $grouped = $memories->groupBy('fact_type');
            $lines = ["*🧠 Mes souvenirs ({$memories->count()}) :*\n"];

            foreach (self::VALID_FACT_TYPES as $type) {
                if (!$grouped->has($type)) continue;
                $emoji = self::TYPE_EMOJIS[$type] ?? '📝';
                $label = self::TYPE_LABELS[$type] ?? ucfirst($type);
                $lines[] = "*{$emoji} {$label}s :*";
                foreach ($grouped[$type] as $m) {
                    $date = $m->updated_at->format('d/m');
                    $version = $m->version > 1 ? " _(v{$m->version})_" : '';
                    $pin = in_array(self::PINNED_TAG, $m->tags ?? []) ? ' 📌' : '';
                    $lines[] = "  • {$m->content}{$pin} _{$date}{$version}_";
                }
                $lines[] = '';
            }

            $lines[] = "_Commandes : oublie <mot-cle> | cherche souvenir <mot> | epingle <mot-cle> | stats memoire | export memoire_";

            $reply = implode("\n", $lines);
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] showMemories failed', ['error' => $e->getMessage()]);
            $reply = "Erreur lors du chargement des souvenirs. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    private function showMemoriesByType(AgentContext $context, string $rawType): AgentResult
    {
        try {
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

            $lines = ["*{$emoji} {$label}s ({$memories->count()}) :*\n"];
            foreach ($memories as $m) {
                $date = $m->updated_at->format('d/m/y');
                $pin = in_array(self::PINNED_TAG, $m->tags ?? []) ? ' 📌' : '';
                $lines[] = "  • {$m->content}{$pin} _({$date})_";
            }

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] showMemoriesByType failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors du chargement des souvenirs. Reessaie dans un instant.");
        }
    }

    private function searchMemories(AgentContext $context, string $keyword): AgentResult
    {
        if (mb_strlen($keyword) < 2) {
            return AgentResult::reply("Donne-moi un mot-cle d'au moins 2 caracteres pour chercher.");
        }

        try {
            $escaped = $this->escapeLike($keyword);

            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where(function ($q) use ($escaped, $keyword) {
                    $q->where('content', 'like', "%{$escaped}%")
                      ->orWhereJsonContains('tags', mb_strtolower($keyword));
                })
                ->orderByDesc('updated_at')
                ->take(10)
                ->get();

            if ($memories->isEmpty()) {
                return AgentResult::reply("Aucun souvenir contenant \"{$keyword}\" trouve.\n\n💡 _Essaie avec un mot-cle plus court ou \"affiche souvenirs\" pour tout voir._");
            }

            $lines = ["*🔍 Resultats pour \"{$keyword}\"* ({$memories->count()}) :\n"];
            foreach ($memories as $m) {
                $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
                $label = self::TYPE_LABELS[$m->fact_type] ?? $m->fact_type;
                $date = $m->updated_at->format('d/m');
                $pin = in_array(self::PINNED_TAG, $m->tags ?? []) ? ' 📌' : '';
                $lines[] = "{$emoji} {$m->content}{$pin}\n    _{$label} — {$date}_";
            }

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] searchMemories failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la recherche. Reessaie dans un instant.");
        }
    }

    private function showStats(AgentContext $context): AgentResult
    {
        try {
            $total = ConversationMemory::forUser($context->from)->active()->notExpired()->count();

            if ($total === 0) {
                return AgentResult::reply("📊 Aucun souvenir en memoire pour le moment.");
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

            $newest = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->orderByDesc('updated_at')
                ->first();

            $archivedCount = ConversationMemory::forUser($context->from)
                ->where('status', 'archived')
                ->count();

            $pinnedCount = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->whereJsonContains('tags', self::PINNED_TAG)
                ->count();

            $lines = ["*📊 Statistiques memoire :*\n"];
            $lines[] = "Total actifs : *{$total}* souvenir(s)";
            if ($pinnedCount > 0) {
                $lines[] = "📌 Epingles : {$pinnedCount}/" . self::MAX_PINNED_FACTS;
            }
            if ($archivedCount > 0) {
                $lines[] = "🗃️ Archives : {$archivedCount}";
            }
            $lines[] = '';

            foreach (self::VALID_FACT_TYPES as $type) {
                if (!isset($counts[$type])) continue;
                $emoji = self::TYPE_EMOJIS[$type] ?? '📝';
                $label = self::TYPE_LABELS[$type] ?? ucfirst($type);
                $bar = str_repeat('▓', min($counts[$type], 15)) . str_repeat('░', max(0, 15 - $counts[$type]));
                $lines[] = "{$emoji} {$label}s : *{$counts[$type]}* {$bar}";
            }

            if ($oldest) {
                $lines[] = "\n📅 Premier : " . $oldest->created_at->format('d/m/Y');
            }
            if ($newest) {
                $lines[] = "📅 Dernier : " . $newest->updated_at->format('d/m/Y H:i');
            }

            // Expiring soon count
            $expiringSoon = ConversationMemory::forUser($context->from)
                ->active()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(3))
                ->where('expires_at', '>', now())
                ->count();
            if ($expiringSoon > 0) {
                $lines[] = "\n⏰ *{$expiringSoon}* souvenir(s) expirent dans les 3 prochains jours";
            }

            // Memory health / cap warning
            if ($total >= self::MAX_MEMORIES_PER_USER) {
                $lines[] = "\n⚠️ *Seuil atteint* ({$total}/" . self::MAX_MEMORIES_PER_USER . "). Pense a dedupliquer ou archiver.";
            } elseif ($total >= self::MAX_MEMORIES_PER_USER * 0.8) {
                $pct = round($total / self::MAX_MEMORIES_PER_USER * 100);
                $lines[] = "\n📈 Utilisation : {$pct}% ({$total}/" . self::MAX_MEMORIES_PER_USER . ")";
            }

            $reply = implode("\n", $lines);
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] showStats failed', ['error' => $e->getMessage()]);
            $reply = "Erreur lors du chargement des statistiques. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    private function addMemoryDirectly(AgentContext $context, string $content, ?string $rawType): AgentResult
    {
        try {
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

            // Check memory cap before adding
            $currentCount = ConversationMemory::forUser($context->from)->active()->notExpired()->count();
            if ($currentCount >= self::MAX_MEMORIES_PER_USER) {
                return AgentResult::reply("⚠️ *Limite atteinte !* Tu as deja {$currentCount} souvenirs (max " . self::MAX_MEMORIES_PER_USER . ").\n\n_Utilise \"deduplique memoire\" ou \"oublie <mot-cle>\" pour faire de la place._");
            }

            // Check for duplicate (exact + fuzzy)
            if ($this->isSimilarMemory($context->from, $factType, $content)) {
                $emoji = self::TYPE_EMOJIS[$factType] ?? '📝';
                return AgentResult::reply("{$emoji} Un souvenir similaire existe deja.\n\n_Utilise \"affiche souvenirs\" pour voir tes souvenirs existants._");
            }

            // Auto-generate tags from content
            $tags = [];
            try {
                $tagResponse = $this->claude->chat(
                    "Contenu: \"{$content}\"\nType: {$factType}\n\nGenere 1 a 3 tags courts (mots-cles) pour ce souvenir. Reponds UNIQUEMENT en JSON array de strings, ex: [\"php\", \"laravel\"]",
                    ModelResolver::fast(),
                    'Tu generes des tags courts (1-2 mots max) pour categoriser des souvenirs. Reponds UNIQUEMENT en JSON array de strings.'
                );
                $parsedTags = json_decode(trim($tagResponse ?? ''), true);
                if (is_array($parsedTags)) {
                    $tags = array_slice(array_map(fn($t) => mb_strtolower(trim($t)), $parsedTags), 0, 3);
                }
            } catch (\Throwable) {
                // Silently continue without tags
            }

            ConversationMemory::create([
                'user_id'   => $context->from,
                'fact_type' => $factType,
                'content'   => $content,
                'tags'      => $tags,
                'status'    => 'active',
            ]);

            $this->clearCache($context->from);

            $emoji = self::TYPE_EMOJIS[$factType] ?? '📝';
            $label = self::TYPE_LABELS[$factType] ?? ucfirst($factType);
            $total = ConversationMemory::forUser($context->from)->active()->notExpired()->count();
            $tagStr = !empty($tags) ? "\n*Tags :* " . implode(', ', $tags) : '';
            return AgentResult::reply("{$emoji} *Souvenir ajoute !*\n\n*Type :* {$label}\n*Contenu :* {$content}{$tagStr}\n\n_Total en memoire : {$total}_");
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] addMemoryDirectly failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de l'ajout du souvenir. Reessaie dans un instant.");
        }
    }

    private function promptClearAll(AgentContext $context): AgentResult
    {
        try {
            $total = ConversationMemory::forUser($context->from)->active()->notExpired()->count();

            if ($total === 0) {
                return AgentResult::reply("Ta memoire est deja vide, rien a effacer.");
            }

            $this->setPendingContext($context, 'clear_all_confirmation', [], 2);

            $reply = "⚠️ *Attention !* Tu as *{$total}* souvenir(s) en memoire.\n\nCette action va tout archiver et est _irreversible_.\n\nReponds *oui* pour confirmer, ou tout autre message pour annuler.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] promptClearAll failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la preparation de la suppression. Reessaie dans un instant.");
        }
    }

    private function executeClearAll(AgentContext $context): AgentResult
    {
        try {
            $count = ConversationMemory::forUser($context->from)
                ->active()
                ->update(['status' => 'archived']);

            $this->clearCache($context->from);

            $reply = "🗑️ *Memoire effacee.* {$count} souvenir(s) archive(s).\n\nJe repartirai de zero pour les prochaines conversations.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] executeClearAll failed', ['error' => $e->getMessage()]);
            $reply = "Erreur lors de la suppression. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    private function forgetMemory(AgentContext $context, string $search): AgentResult
    {
        try {
            $search = trim($search);
            if (mb_strlen($search) < 2) {
                return AgentResult::reply("Donne-moi un mot-cle pour identifier le souvenir a oublier.");
            }

            $escaped = $this->escapeLike($search);

            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where(function ($q) use ($escaped, $search) {
                    $q->where('content', 'like', "%{$escaped}%")
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
            $archived = [];
            foreach ($memories as $m) {
                $m->update(['status' => 'archived']);
                $archived[] = "  • {$m->content}";
            }

            $this->clearCache($context->from);

            $label = $count === 1 ? 'souvenir archive' : 'souvenirs archives';
            $detail = $count <= 5 ? "\n" . implode("\n", $archived) : '';
            return AgentResult::reply("🗑️ {$count} {$label} contenant \"{$search}\".{$detail}");
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] forgetMemory failed', ['error' => $e->getMessage(), 'search' => $search]);
            return AgentResult::reply("Erreur lors de la suppression. Reessaie dans un instant.");
        }
    }

    private function extractAndStore(AgentContext $context, string $body): AgentResult
    {
        if (mb_strlen($body) < 5) {
            return AgentResult::reply("Message trop court pour en extraire des souvenirs.\n\n💡 _Utilise \"note: <info>\" pour ajouter directement._");
        }

        $countBefore = ConversationMemory::forUser($context->from)->active()->notExpired()->count();

        try {
            $this->extractFactsInBackground($context);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] extractAndStore failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de l'analyse. Reessaie ou utilise \"note: <info>\" pour ajouter manuellement.");
        }

        $countAfter = ConversationMemory::forUser($context->from)->active()->notExpired()->count();
        $newCount = max(0, $countAfter - $countBefore);

        $recent = ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->orderByDesc('updated_at')
            ->take(3)
            ->get();

        if ($recent->isEmpty()) {
            return AgentResult::reply("🧠 Message analyse — rien de nouveau a memoriser.\n\n💡 _Astuce : \"note: <info>\" pour ajouter manuellement._");
        }

        if ($newCount > 0) {
            $lines = ["*✨ {$newCount} nouveau(x) souvenir(s) :*\n"];
        } else {
            $lines = ["*🧠 Souvenirs recents :*\n"];
        }

        foreach ($recent as $m) {
            $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
            $label = self::TYPE_LABELS[$m->fact_type] ?? $m->fact_type;
            $lines[] = "{$emoji} {$m->content} _{$label}_";
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
        // Strip markdown code fences
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        // Extract JSON array if surrounded by text
        if (!str_starts_with($clean, '[') && preg_match('/(\[.*\])/s', $clean, $m)) {
            $clean = $m[1];
        }
        // Fix common LLM JSON issues: trailing commas before ] or }
        $clean = preg_replace('/,\s*([\]}])/', '$1', $clean);

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
                $escapedMatch = $this->escapeLike($fact['match_content']);
                ConversationMemory::forUser($userId)
                    ->active()
                    ->where('content', 'like', '%' . $escapedMatch . '%')
                    ->update(['status' => 'archived']);
                $saved++;
                continue;
            }

            if ($action === 'update' && !empty($fact['match_content'])) {
                $escapedMatch = $this->escapeLike($fact['match_content']);
                $existing = ConversationMemory::forUser($userId)
                    ->active()
                    ->where('content', 'like', '%' . $escapedMatch . '%')
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

            // Check for duplicate (exact + fuzzy similarity)
            if ($this->isSimilarMemory($userId, $fact['fact_type'], $fact['content'])) {
                Log::debug('ConversationMemoryAgent: similar fact skipped', [
                    'user_id'   => $userId,
                    'fact_type' => $fact['fact_type'],
                    'content'   => mb_substr($fact['content'], 0, 80),
                ]);
                continue;
            }

            // Enforce memory cap
            $currentCount = ConversationMemory::forUser($userId)->active()->notExpired()->count();
            if ($currentCount >= self::MAX_MEMORIES_PER_USER) {
                Log::warning('ConversationMemoryAgent: memory cap reached', [
                    'user_id' => $userId,
                    'count'   => $currentCount,
                ]);
                break;
            }

            ConversationMemory::create([
                'user_id'   => $userId,
                'fact_type' => $fact['fact_type'],
                'content'   => $fact['content'],
                'tags'      => $fact['tags'] ?? [],
                'status'    => 'active',
            ]);
            $saved++;
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

    /**
     * Export all memories as a formatted summary.
     */
    private function exportMemories(AgentContext $context): AgentResult
    {
        try {
            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->orderBy('fact_type')
                ->orderByDesc('updated_at')
                ->get();

            if ($memories->isEmpty()) {
                return AgentResult::reply("🧠 Aucun souvenir a exporter.");
            }

            $grouped = $memories->groupBy('fact_type');
            $lines = ["*📋 Export memoire — " . now()->format('d/m/Y H:i') . "*\n"];
            $lines[] = "Total : *{$memories->count()}* souvenir(s)\n";

            foreach (self::VALID_FACT_TYPES as $type) {
                if (!$grouped->has($type)) continue;
                $emoji = self::TYPE_EMOJIS[$type] ?? '📝';
                $label = self::TYPE_LABELS[$type] ?? ucfirst($type);
                $lines[] = "*{$emoji} {$label}s ({$grouped[$type]->count()}) :*";
                foreach ($grouped[$type] as $m) {
                    $date = $m->created_at->format('d/m/Y');
                    $updated = $m->version > 1 ? " _(maj v{$m->version} le {$m->updated_at->format('d/m')})_" : '';
                    $visibleTags = array_values(array_filter($m->tags ?? [], fn($t) => $t !== self::PINNED_TAG));
                    $tags = !empty($visibleTags) ? ' [' . implode(', ', $visibleTags) . ']' : '';
                    $pin = in_array(self::PINNED_TAG, $m->tags ?? []) ? ' 📌' : '';
                    $lines[] = "  • {$m->content}{$pin}{$tags} — _{$date}{$updated}_";
                }
                $lines[] = '';
            }

            $reply = implode("\n", $lines);
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] exportMemories failed', ['error' => $e->getMessage()]);
            $reply = "Erreur lors de l'export. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    /**
     * Show memories as a chronological timeline.
     */
    private function showTimeline(AgentContext $context): AgentResult
    {
        try {
            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->orderByDesc('created_at')
                ->take(15)
                ->get();

            if ($memories->isEmpty()) {
                return AgentResult::reply("🧠 Aucun souvenir a afficher en timeline.");
            }

            $lines = ["*📅 Timeline memoire :*\n"];
            $currentDate = '';

            foreach ($memories as $m) {
                $date = $m->created_at->format('d/m/Y');
                if ($date !== $currentDate) {
                    $currentDate = $date;
                    $lines[] = "\n*— {$date} —*";
                }
                $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
                $time = $m->created_at->format('H:i');
                $pin = in_array(self::PINNED_TAG, $m->tags ?? []) ? ' 📌' : '';
                $lines[] = "  {$time} {$emoji} {$m->content}{$pin}";
            }

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] showTimeline failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors du chargement de la timeline. Reessaie dans un instant.");
        }
    }

    /**
     * Generate an AI-powered summary of all memories.
     */
    private function showSummary(AgentContext $context): AgentResult
    {
        try {
            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->orderBy('fact_type')
                ->orderByDesc('updated_at')
                ->take(30)
                ->get();

            if ($memories->isEmpty()) {
                return AgentResult::reply("🧠 Aucun souvenir a resumer.");
            }

            $factsText = $memories->map(fn($m) => "[{$m->fact_type}] {$m->content}")->implode("\n");
            $summary = $this->claude->chat(
                "Voici les souvenirs memorises pour cet utilisateur :\n\n{$factsText}\n\nGenere un resume concis (max 8 lignes) du profil de cet utilisateur : ses projets, competences, preferences et contraintes. Sois synthetique et utile.",
                ModelResolver::fast(),
                <<<'SYSTEM'
Tu es un assistant qui resume des profils utilisateurs pour WhatsApp.
Reponds directement avec le resume, sans introduction.

Formatage WhatsApp OBLIGATOIRE:
- *gras* pour les titres et mots-cles importants
- _italique_ pour les nuances
- Listes avec • ou -
- Emojis pertinents (📁 projets, 💡 competences, ⚙️ preferences, ⚠️ contraintes)
- PAS de markdown (```), PAS de liens cliquables
- Max 8 lignes, concis et actionnable
SYSTEM
            );

            if (!$summary) {
                return AgentResult::reply("Impossible de generer le resume. Voici tes souvenirs bruts :\n\n" . $factsText);
            }

            $lines = ["*🧠 Resume de ton profil memoire :*\n"];
            $lines[] = trim($summary);
            $lines[] = "\n_Base sur {$memories->count()} souvenir(s)_";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] Summary generation failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la generation du resume. Utilise \"affiche souvenirs\" pour voir la liste.");
        }
    }

    /**
     * Pin a memory so it always appears in context (stored via __pinned tag).
     */
    private function pinMemory(AgentContext $context, string $search): AgentResult
    {
        try {
            $escaped = $this->escapeLike($search);

            $memory = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where('content', 'like', "%{$escaped}%")
                ->first();

            if (!$memory) {
                return AgentResult::reply("Aucun souvenir contenant \"{$search}\" trouve a epingler.\n\n💡 _Utilise \"affiche souvenirs\" pour voir la liste._");
            }

            $pinnedCount = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->whereJsonContains('tags', self::PINNED_TAG)
                ->count();

            if ($pinnedCount >= self::MAX_PINNED_FACTS) {
                return AgentResult::reply("📌 Tu as deja *{$pinnedCount}* souvenir(s) epingle(s) (max " . self::MAX_PINNED_FACTS . ").\n\nDesepingle un souvenir d'abord : _desepingle <mot-cle>_");
            }

            $tags = $memory->tags ?? [];
            if (in_array(self::PINNED_TAG, $tags)) {
                return AgentResult::reply("📌 Ce souvenir est deja epingle : \"{$memory->content}\"");
            }

            $tags[] = self::PINNED_TAG;
            $memory->update(['tags' => $tags]);
            $this->clearCache($context->from);

            $emoji = self::TYPE_EMOJIS[$memory->fact_type] ?? '📝';
            return AgentResult::reply("📌 *Souvenir epingle !*\n\n{$emoji} {$memory->content}\n\n_Ce souvenir apparaitra toujours dans le contexte des conversations._");
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] pinMemory failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de l'epinglage. Reessaie dans un instant.");
        }
    }

    /**
     * Unpin a memory.
     */
    private function unpinMemory(AgentContext $context, string $search): AgentResult
    {
        try {
            $escaped = $this->escapeLike($search);

            $memory = ConversationMemory::forUser($context->from)
                ->active()
                ->whereJsonContains('tags', self::PINNED_TAG)
                ->where('content', 'like', "%{$escaped}%")
                ->first();

            if (!$memory) {
                return AgentResult::reply("Aucun souvenir epingle contenant \"{$search}\" trouve.\n\n💡 _Utilise \"mes epingles\" pour voir tes souvenirs epingles._");
            }

            $tags = array_values(array_filter($memory->tags ?? [], fn($t) => $t !== self::PINNED_TAG));
            $memory->update(['tags' => $tags]);
            $this->clearCache($context->from);

            return AgentResult::reply("✅ Souvenir desepingle : \"{$memory->content}\"");
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] unpinMemory failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors du desepinglage. Reessaie dans un instant.");
        }
    }

    /**
     * Show all pinned memories.
     */
    private function showPinnedMemories(AgentContext $context): AgentResult
    {
        try {
            $pinned = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->whereJsonContains('tags', self::PINNED_TAG)
                ->orderByDesc('updated_at')
                ->get();

            if ($pinned->isEmpty()) {
                return AgentResult::reply("📌 Aucun souvenir epingle.\n\n_Utilise \"epingle <mot-cle>\" pour epingler un souvenir important._");
            }

            $lines = ["*📌 Souvenirs epingles ({$pinned->count()}) :*\n"];
            foreach ($pinned as $m) {
                $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
                $label = self::TYPE_LABELS[$m->fact_type] ?? $m->fact_type;
                $lines[] = "{$emoji} {$m->content} _{$label}_";
            }

            $lines[] = "\n_Desepingle avec : desepingle <mot-cle>_";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] showPinnedMemories failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors du chargement des epingles. Reessaie dans un instant.");
        }
    }

    /**
     * Check if a new content is too similar to an existing memory (fuzzy dedup).
     */
    private function isSimilarMemory(string $userId, string $factType, string $newContent): bool
    {
        $existing = ConversationMemory::forUser($userId)
            ->active()
            ->notExpired()
            ->where('fact_type', $factType)
            ->pluck('content');

        $newNormalized = mb_strtolower(trim($newContent));

        foreach ($existing as $content) {
            $existingNormalized = mb_strtolower(trim($content));

            // Exact match
            if ($newNormalized === $existingNormalized) {
                return true;
            }

            // Fuzzy similarity check
            similar_text($newNormalized, $existingNormalized, $percent);
            if ($percent >= self::SIMILARITY_THRESHOLD) {
                Log::debug('[ConversationMemory] Fuzzy duplicate detected', [
                    'existing'   => mb_substr($content, 0, 80),
                    'new'        => mb_substr($newContent, 0, 80),
                    'similarity' => round($percent, 1),
                ]);
                return true;
            }
        }

        return false;
    }

    private function showHelp(): AgentResult
    {
        $lines = [
            '*🧠 Aide — Agent Memoire (v' . $this->version() . ')*',
            '',
            '*📝 Ajouter un souvenir :*',
            '  note: <info>',
            '  note projet: <detail>',
            '  retiens que <info>',
            '',
            '*👁️ Consulter :*',
            '  affiche souvenirs',
            '  mes projets / decisions / competences',
            '  mes epingles',
            '  mes archives',
            '  timeline memoire',
            '  stats memoire',
            '  resume memoire',
            '  export memoire',
            '',
            '*🔍 Rechercher :*',
            '  cherche souvenir <mot-cle>',
            '',
            '*📌 Epingler / Desepingler :*',
            '  epingle <mot-cle>',
            '  desepingle <mot-cle>',
            '',
            '*✏️ Modifier :*',
            '  modifie souvenir <mot-cle> -> <nouveau contenu>',
            '',
            '*🏷️ Etiqueter :*',
            '  tag souvenir <mot-cle> tag1, tag2',
            '',
            '*🔎 Recherche globale :*',
            '  cherche partout <mot-cle>',
            '',
            '*🔗 Fusionner :*',
            '  fusionne souvenirs <mot-cle>',
            '',
            '*📊 Analyse :*',
            '  analyse memoire',
            '',
            '*⏰ Expiration :*',
            '  expire souvenir <mot-cle> <N> jours',
            '  expire souvenir <mot-cle> <N> heures',
            '',
            '*💬 Rappel rapide :*',
            '  rappel <mot-cle>',
            '  recall <mot-cle>',
            '',
            '*⚖️ Comparer :*',
            '  compare souvenirs <mot1> vs <mot2>',
            '',
            '*🧹 Maintenance :*',
            '  deduplique memoire',
            '  archive type <projet|preference|...>',
            '',
            '*🗑️ Supprimer :*',
            '  oublie <mot-cle>',
            '  efface tout',
            '',
            '*♻️ Restaurer :*',
            '  restaure <mot-cle>',
            '',
            '_Les souvenirs sont aussi extraits automatiquement de nos conversations._',
        ];

        return AgentResult::reply(implode("\n", $lines));
    }

    private function restoreMemory(AgentContext $context, string $search): AgentResult
    {
        try {
            $escaped = $this->escapeLike($search);

            $memory = ConversationMemory::forUser($context->from)
                ->where('status', 'archived')
                ->where('content', 'like', "%{$escaped}%")
                ->orderByDesc('updated_at')
                ->first();

            if (!$memory) {
                return AgentResult::reply("Aucun souvenir archive contenant \"{$search}\" trouve.\n\n💡 _Utilise \"mes archives\" pour voir les souvenirs archives._");
            }

            $memory->update(['status' => 'active']);
            $this->clearCache($context->from);

            $emoji = self::TYPE_EMOJIS[$memory->fact_type] ?? '📝';
            $label = self::TYPE_LABELS[$memory->fact_type] ?? ucfirst($memory->fact_type);
            return AgentResult::reply("♻️ *Souvenir restaure !*\n\n{$emoji} {$memory->content}\n*Type :* {$label}\n\n_Ce souvenir est de nouveau actif._");
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] restoreMemory failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la restauration. Reessaie dans un instant.");
        }
    }

    private function showArchivedMemories(AgentContext $context): AgentResult
    {
        try {
            $memories = ConversationMemory::forUser($context->from)
                ->where('status', 'archived')
                ->orderByDesc('updated_at')
                ->take(15)
                ->get();

            if ($memories->isEmpty()) {
                return AgentResult::reply("🗃️ Aucun souvenir archive.");
            }

            $lines = ["*🗃️ Souvenirs archives ({$memories->count()}) :*\n"];
            foreach ($memories as $m) {
                $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
                $date = $m->updated_at->format('d/m');
                $lines[] = "{$emoji} ~~{$m->content}~~ _{$date}_";
            }

            $lines[] = "\n_Restaure avec : restaure <mot-cle>_";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] showArchivedMemories failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors du chargement des archives. Reessaie dans un instant.");
        }
    }

    /**
     * Detect noise messages (greetings, emoji-only, pure URLs) that should not be processed.
     */
    private function isNoise(string $body): bool
    {
        $trimmed = trim($body);
        foreach (self::NOISE_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find and merge duplicate/similar memories across all types.
     */
    private function deduplicateMemories(AgentContext $context): AgentResult
    {
        try {
            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->orderBy('fact_type')
                ->orderByDesc('updated_at')
                ->get();

            if ($memories->count() < 2) {
                return AgentResult::reply("🧹 Pas assez de souvenirs pour chercher des doublons.");
            }

            $duplicates = [];
            $checked = [];

            foreach ($memories as $i => $a) {
                if (in_array($a->id, $checked)) continue;

                $aNorm = mb_strtolower(trim($a->content));

                foreach ($memories as $j => $b) {
                    if ($i >= $j || in_array($b->id, $checked)) continue;
                    if ($a->fact_type !== $b->fact_type) continue;

                    $bNorm = mb_strtolower(trim($b->content));

                    similar_text($aNorm, $bNorm, $percent);
                    if ($percent >= self::SIMILARITY_THRESHOLD) {
                        $duplicates[] = ['keep' => $a, 'remove' => $b, 'similarity' => round($percent, 1)];
                        $checked[] = $b->id;
                    }
                }
            }

            if (empty($duplicates)) {
                return AgentResult::reply("🧹 *Aucun doublon detecte !*\n\n_Tes {$memories->count()} souvenirs sont tous uniques._");
            }

            $archived = 0;
            $lines = ["*🧹 Deduplication memoire :*\n"];

            foreach ($duplicates as $dup) {
                $emoji = self::TYPE_EMOJIS[$dup['keep']->fact_type] ?? '📝';
                $lines[] = "{$emoji} _{$dup['similarity']}%_ — conserve : \"{$dup['keep']->content}\"";
                $lines[] = "  ~~{$dup['remove']->content}~~ → archive";

                $dup['remove']->update(['status' => 'archived']);
                $archived++;
            }

            $this->clearCache($context->from);

            $lines[] = "\n*Resultat :* {$archived} doublon(s) archive(s)";
            $lines[] = "_Utilise \"mes archives\" pour voir les souvenirs archives._";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] deduplicateMemories failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la deduplication. Reessaie dans un instant.");
        }
    }

    /**
     * Cross-type global search with relevance scoring.
     */
    private function globalSearch(AgentContext $context, string $query): AgentResult
    {
        try {
            $escaped = $this->escapeLike($query);
            $queryNorm = mb_strtolower(trim($query));

            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where(function ($q) use ($escaped) {
                    $q->where('content', 'like', "%{$escaped}%")
                      ->orWhereJsonContains('tags', mb_strtolower($escaped));
                })
                ->orderByDesc('updated_at')
                ->take(20)
                ->get();

            if ($memories->isEmpty()) {
                return AgentResult::reply("🔎 Aucun resultat pour \"{$query}\".\n\n_Essaie avec un mot-cle different ou \"affiche souvenirs\" pour tout voir._");
            }

            // Score results by relevance
            $scored = $memories->map(function ($m) use ($queryNorm) {
                $contentNorm = mb_strtolower($m->content);
                $score = 0;

                // Exact match in content
                if (str_contains($contentNorm, $queryNorm)) {
                    $score += 10;
                }

                // Fuzzy similarity
                similar_text($queryNorm, $contentNorm, $percent);
                $score += $percent / 10;

                // Tag match bonus
                $tags = array_map('mb_strtolower', $m->tags ?? []);
                if (in_array($queryNorm, $tags)) {
                    $score += 5;
                }

                // Pinned bonus
                if (in_array(self::PINNED_TAG, $m->tags ?? [])) {
                    $score += 3;
                }

                return ['memory' => $m, 'score' => round($score, 1)];
            })->sortByDesc('score')->take(10);

            $lines = ["*🔎 Recherche globale : \"{$query}\"*\n"];
            $lines[] = "_" . $scored->count() . " resultat(s) :_\n";

            foreach ($scored as $item) {
                $m = $item['memory'];
                $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
                $label = self::TYPE_LABELS[$m->fact_type] ?? $m->fact_type;
                $pin = in_array(self::PINNED_TAG, $m->tags ?? []) ? ' 📌' : '';
                $date = $m->updated_at->format('d/m');
                $lines[] = "{$emoji} {$m->content}{$pin} _{$label} — {$date}_";
            }

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] globalSearch failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la recherche. Reessaie dans un instant.");
        }
    }

    /**
     * Merge similar memories matching a keyword into a single consolidated memory.
     */
    private function mergeMemories(AgentContext $context, string $keyword): AgentResult
    {
        try {
            if (mb_strlen($keyword) < 2) {
                return AgentResult::reply("Donne-moi un mot-cle d'au moins 2 caracteres pour identifier les souvenirs a fusionner.");
            }

            $escaped = $this->escapeLike($keyword);

            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where('content', 'like', "%{$escaped}%")
                ->orderByDesc('updated_at')
                ->take(10)
                ->get();

            if ($memories->count() < 2) {
                return AgentResult::reply("🔗 Moins de 2 souvenirs trouvés pour \"{$keyword}\".\n\n_Il faut au moins 2 souvenirs similaires pour fusionner._");
            }

            $factsText = $memories->map(fn($m) => "[{$m->fact_type}] {$m->content}")->implode("\n");

            $merged = $this->claude->chat(
                "Voici des souvenirs similaires a fusionner :\n\n{$factsText}\n\nGenere UN SEUL fait qui consolide toutes ces informations. Reponds en JSON : {\"fact_type\": \"...\", \"content\": \"...\", \"tags\": [\"...\"]}",
                ModelResolver::fast(),
                <<<'SYSTEM'
Tu es un assistant qui fusionne des souvenirs en un seul fait consolide.
Regles :
- fact_type doit etre parmi : project, preference, decision, skill, constraint
- Choisis le type le plus representatif des faits fusionnes
- Le contenu doit etre concis (max 150 caracteres), en francais
- Les tags doivent etre des mots-cles courts (1-3 tags)
- Reponds UNIQUEMENT en JSON valide, sans markdown
SYSTEM
            );

            if (!$merged) {
                return AgentResult::reply("Impossible de fusionner les souvenirs. Reessaie dans un instant.");
            }

            $clean = trim($merged);
            if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
                $clean = $m[1];
            }

            $data = json_decode($clean, true);
            if (!is_array($data) || empty($data['content']) || empty($data['fact_type'])) {
                return AgentResult::reply("Erreur lors de la fusion. Reessaie dans un instant.");
            }

            if (!in_array($data['fact_type'], self::VALID_FACT_TYPES)) {
                $data['fact_type'] = $memories->first()->fact_type;
            }

            // Archive old memories
            foreach ($memories as $m) {
                $m->update(['status' => 'archived']);
            }

            // Create the merged memory
            ConversationMemory::create([
                'user_id'   => $context->from,
                'fact_type' => $data['fact_type'],
                'content'   => mb_substr(trim($data['content']), 0, 200),
                'tags'      => $data['tags'] ?? [],
                'status'    => 'active',
            ]);

            $this->clearCache($context->from);

            $emoji = self::TYPE_EMOJIS[$data['fact_type']] ?? '📝';
            $label = self::TYPE_LABELS[$data['fact_type']] ?? ucfirst($data['fact_type']);
            $lines = ["*🔗 Fusion reussie !*\n"];
            $lines[] = "{$emoji} *Nouveau :* {$data['content']} _{$label}_\n";
            $lines[] = "_" . $memories->count() . " souvenir(s) fusionne(s) et archive(s)._";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] mergeMemories failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la fusion. Reessaie dans un instant.");
        }
    }

    /**
     * Generate AI-powered insights about patterns in user's memories.
     */
    private function showInsights(AgentContext $context): AgentResult
    {
        try {
            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->orderBy('fact_type')
                ->orderByDesc('updated_at')
                ->get();

            if ($memories->count() < 3) {
                return AgentResult::reply("📊 Pas assez de souvenirs pour generer des tendances (minimum 3).\n\n_Continue a discuter, je memorise automatiquement !_");
            }

            $counts = $memories->groupBy('fact_type')->map->count();
            $factsText = $memories->map(fn($m) => "[{$m->fact_type}] {$m->content} (tags: " . implode(', ', $m->tags ?? []) . ")")->implode("\n");

            $insights = $this->claude->chat(
                "Voici les souvenirs memorises pour cet utilisateur :\n\n{$factsText}\n\nStats : " . json_encode($counts) . "\n\nGenere une analyse en 5-8 lignes : tendances, points forts, themes recurrents, suggestions pour mieux exploiter la memoire.",
                ModelResolver::fast(),
                <<<'SYSTEM'
Tu es un analyste de profil utilisateur pour WhatsApp.

Analyse les souvenirs et produis des insights actionnables :
1. Themes dominants et tendances
2. Points forts de l'utilisateur
3. Suggestions pour mieux utiliser la memoire (types sous-utilises, souvenirs a epingler, etc.)

Formatage WhatsApp OBLIGATOIRE :
- *gras* pour les titres et mots-cles
- _italique_ pour les nuances
- Listes avec • ou -
- Emojis pertinents
- PAS de markdown (```), PAS de liens
- Max 8 lignes, concis et utile
SYSTEM
            );

            if (!$insights) {
                return AgentResult::reply("Impossible de generer les tendances. Reessaie dans un instant.");
            }

            $lines = ["*📊 Analyse de ta memoire :*\n"];
            $lines[] = trim($insights);
            $lines[] = "\n_Base sur {$memories->count()} souvenir(s) actif(s)._";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] showInsights failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de l'analyse. Reessaie dans un instant.");
        }
    }

    /**
     * Edit a memory's content by keyword match.
     */
    private function editMemory(AgentContext $context, string $search, string $newContent): AgentResult
    {
        try {
            $search = trim($search);
            $newContent = trim($newContent);

            if (mb_strlen($search) < 2) {
                return AgentResult::reply("Donne-moi un mot-cle d'au moins 2 caracteres pour identifier le souvenir a modifier.");
            }
            if (mb_strlen($newContent) < 3) {
                return AgentResult::reply("Le nouveau contenu est trop court (min 3 caracteres).");
            }
            $newContent = mb_substr($newContent, 0, 200);

            $escaped = $this->escapeLike($search);

            $memory = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where('content', 'like', "%{$escaped}%")
                ->orderByDesc('updated_at')
                ->first();

            if (!$memory) {
                return AgentResult::reply("Aucun souvenir actif contenant \"{$search}\" trouve.\n\n💡 _Utilise \"affiche souvenirs\" pour voir tes souvenirs._");
            }

            $oldContent = $memory->content;
            $memory->update([
                'content' => $newContent,
                'version' => ($memory->version ?? 1) + 1,
            ]);

            $this->clearCache($context->from);

            $emoji = self::TYPE_EMOJIS[$memory->fact_type] ?? '📝';
            return AgentResult::reply("{$emoji} *Souvenir modifie !*\n\n*Avant :* ~~{$oldContent}~~\n*Apres :* {$newContent}\n\n_Version {$memory->version}_");
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] editMemory failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la modification. Reessaie dans un instant.");
        }
    }

    /**
     * Add or replace tags on a memory by keyword match.
     */
    private function tagMemory(AgentContext $context, string $search, string $rawTags): AgentResult
    {
        try {
            $search = trim($search);
            if (mb_strlen($search) < 2) {
                return AgentResult::reply("Donne-moi un mot-cle d'au moins 2 caracteres pour identifier le souvenir.");
            }

            $newTags = array_map(fn($t) => mb_strtolower(trim($t)), explode(',', $rawTags));
            $newTags = array_filter($newTags, fn($t) => mb_strlen($t) >= 1 && $t !== self::PINNED_TAG);
            $newTags = array_slice(array_values($newTags), 0, 5);

            if (empty($newTags)) {
                return AgentResult::reply("Donne-moi au moins un tag valide (separes par des virgules).");
            }

            $escaped = $this->escapeLike($search);

            $memory = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where('content', 'like', "%{$escaped}%")
                ->orderByDesc('updated_at')
                ->first();

            if (!$memory) {
                return AgentResult::reply("Aucun souvenir actif contenant \"{$search}\" trouve.\n\n💡 _Utilise \"affiche souvenirs\" pour voir tes souvenirs._");
            }

            // Preserve pinned tag if present
            $existingTags = $memory->tags ?? [];
            $hasPinned = in_array(self::PINNED_TAG, $existingTags);
            $finalTags = $hasPinned ? array_merge($newTags, [self::PINNED_TAG]) : $newTags;

            $memory->update(['tags' => array_values(array_unique($finalTags))]);
            $this->clearCache($context->from);

            $emoji = self::TYPE_EMOJIS[$memory->fact_type] ?? '📝';
            $tagDisplay = implode(', ', $newTags);
            return AgentResult::reply("{$emoji} *Tags mis a jour !*\n\n{$memory->content}\n*Tags :* {$tagDisplay}");
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] tagMemory failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la mise a jour des tags. Reessaie dans un instant.");
        }
    }

    /**
     * Set an expiration date on a memory.
     */
    private function setExpiration(AgentContext $context, string $search, int $amount, string $unit): AgentResult
    {
        try {
            if (mb_strlen($search) < 2) {
                return AgentResult::reply("Donne-moi un mot-cle d'au moins 2 caracteres pour identifier le souvenir.");
            }

            $unitMap = [
                'jour' => 'days', 'jours' => 'days', 'day' => 'days', 'days' => 'days',
                'heure' => 'hours', 'heures' => 'hours', 'hour' => 'hours', 'hours' => 'hours',
                'semaine' => 'weeks', 'semaines' => 'weeks', 'week' => 'weeks', 'weeks' => 'weeks',
            ];

            $carbonUnit = $unitMap[mb_strtolower($unit)] ?? 'days';
            $amount = max(1, min($amount, 365)); // clamp 1-365

            $escaped = $this->escapeLike($search);
            $memory = ConversationMemory::forUser($context->from)
                ->active()
                ->where('content', 'like', "%{$escaped}%")
                ->orderByDesc('updated_at')
                ->first();

            if (!$memory) {
                return AgentResult::reply("Aucun souvenir actif contenant \"{$search}\" trouve.\n\n💡 _Utilise \"affiche souvenirs\" pour voir tes souvenirs._");
            }

            $expiresAt = now()->add($carbonUnit, $amount);
            $memory->update(['expires_at' => $expiresAt]);
            $this->clearCache($context->from);

            $emoji = self::TYPE_EMOJIS[$memory->fact_type] ?? '📝';
            $unitLabel = match ($carbonUnit) {
                'hours' => 'heure(s)',
                'weeks' => 'semaine(s)',
                default => 'jour(s)',
            };

            return AgentResult::reply("⏰ *Expiration definie !*\n\n{$emoji} {$memory->content}\n*Expire dans :* {$amount} {$unitLabel} ({$expiresAt->format('d/m/Y H:i')})\n\n_Le souvenir disparaitra automatiquement apres cette date._");
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] setExpiration failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la definition de l'expiration. Reessaie dans un instant.");
        }
    }

    /**
     * Quick recall: find and display the most relevant memory for a keyword with context.
     */
    private function quickRecall(AgentContext $context, string $keyword): AgentResult
    {
        try {
            if (mb_strlen($keyword) < 2) {
                return AgentResult::reply("Donne-moi un mot-cle d'au moins 2 caracteres.");
            }

            $escaped = $this->escapeLike($keyword);
            $queryNorm = mb_strtolower(trim($keyword));

            $memories = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where(function ($q) use ($escaped, $keyword) {
                    $q->where('content', 'like', "%{$escaped}%")
                      ->orWhereJsonContains('tags', mb_strtolower($keyword));
                })
                ->orderByDesc('updated_at')
                ->take(10)
                ->get();

            if ($memories->isEmpty()) {
                return AgentResult::reply("🔍 Aucun souvenir pour \"{$keyword}\".\n\n_Essaie \"affiche souvenirs\" pour tout voir._");
            }

            // Score and pick the best match
            $best = $memories->map(function ($m) use ($queryNorm) {
                $score = 0;
                $contentNorm = mb_strtolower($m->content);

                if (str_contains($contentNorm, $queryNorm)) $score += 10;
                similar_text($queryNorm, $contentNorm, $pct);
                $score += $pct / 10;
                if (in_array(self::PINNED_TAG, $m->tags ?? [])) $score += 3;

                return ['memory' => $m, 'score' => $score];
            })->sortByDesc('score')->first();

            $m = $best['memory'];
            $emoji = self::TYPE_EMOJIS[$m->fact_type] ?? '📝';
            $label = self::TYPE_LABELS[$m->fact_type] ?? $m->fact_type;
            $pin = in_array(self::PINNED_TAG, $m->tags ?? []) ? ' 📌' : '';
            $date = $m->updated_at->format('d/m/Y H:i');
            $version = $m->version > 1 ? " _(v{$m->version})_" : '';
            $visibleTags = array_values(array_filter($m->tags ?? [], fn($t) => $t !== self::PINNED_TAG));
            $tagStr = !empty($visibleTags) ? "\n*Tags :* " . implode(', ', $visibleTags) : '';
            $expiry = $m->expires_at ? "\n*Expire :* " . $m->expires_at->format('d/m/Y') : '';

            $lines = ["*💬 Rappel rapide pour \"{$keyword}\" :*\n"];
            $lines[] = "{$emoji} *{$m->content}*{$pin}";
            $lines[] = "_Type :_ {$label} — _{$date}{$version}_";
            if ($tagStr) $lines[] = $tagStr;
            if ($expiry) $lines[] = $expiry;

            $otherCount = $memories->count() - 1;
            if ($otherCount > 0) {
                $lines[] = "\n_{$otherCount} autre(s) souvenir(s) trouvé(s). Utilise \"cherche partout {$keyword}\" pour tout voir._";
            }

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] quickRecall failed', ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors du rappel. Reessaie dans un instant.");
        }
    }

    /**
     * Compare two memories side by side using AI analysis.
     */
    private function compareMemories(AgentContext $context, string $keyword1, string $keyword2): AgentResult
    {
        try {
            $escaped1 = $this->escapeLike($keyword1);
            $escaped2 = $this->escapeLike($keyword2);

            $mem1 = ConversationMemory::forUser($context->from)
                ->active()->notExpired()
                ->where('content', 'like', "%{$escaped1}%")
                ->orderByDesc('updated_at')->first();

            $mem2 = ConversationMemory::forUser($context->from)
                ->active()->notExpired()
                ->where('content', 'like', "%{$escaped2}%")
                ->orderByDesc('updated_at')->first();

            if (!$mem1 || !$mem2) {
                $missing = !$mem1 ? "\"{$keyword1}\"" : "\"{$keyword2}\"";
                return AgentResult::reply("Aucun souvenir trouve pour {$missing}.\n\n💡 _Utilise \"affiche souvenirs\" pour voir les mots-cles disponibles._");
            }

            if ($mem1->id === $mem2->id) {
                return AgentResult::reply("Les deux mots-cles correspondent au meme souvenir.\n\n💡 _Utilise des mots-cles differents pour comparer deux souvenirs distincts._");
            }

            $comparison = $this->claude->chat(
                "Souvenir 1: [{$mem1->fact_type}] {$mem1->content}\nSouvenir 2: [{$mem2->fact_type}] {$mem2->content}\n\nCompare ces deux souvenirs : points communs, differences, et s'ils pourraient etre fusionnes.",
                ModelResolver::fast(),
                "Tu es un assistant WhatsApp. Compare deux souvenirs en 3-5 lignes max. Formatage WhatsApp : *gras*, _italique_, emojis. Sois concis et utile. Pas de markdown ```."
            );

            $emoji1 = self::TYPE_EMOJIS[$mem1->fact_type] ?? '📝';
            $emoji2 = self::TYPE_EMOJIS[$mem2->fact_type] ?? '📝';

            $lines = ["*⚖️ Comparaison de souvenirs :*\n"];
            $lines[] = "{$emoji1} *1.* {$mem1->content} _{$mem1->fact_type}_";
            $lines[] = "{$emoji2} *2.* {$mem2->content} _{$mem2->fact_type}_";
            $lines[] = "";
            $lines[] = trim($comparison ?? 'Impossible de generer la comparaison.');
            $lines[] = "\n_💡 Utilise \"fusionne souvenirs <mot-cle>\" pour combiner des souvenirs similaires._";

            $reply = implode("\n", $lines);
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] compareMemories failed', ['error' => $e->getMessage()]);
            $reply = "Erreur lors de la comparaison. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    /**
     * Bulk archive all memories of a given type.
     */
    private function bulkArchiveByType(AgentContext $context, string $rawType): AgentResult
    {
        try {
            $typeMap = [
                'projet'      => 'project',    'projets'     => 'project',    'project'     => 'project',
                'preference'  => 'preference',  'preferences' => 'preference',
                'decision'    => 'decision',    'decisions'   => 'decision',
                'competence'  => 'skill',       'competences' => 'skill',       'skill'       => 'skill',
                'contrainte'  => 'constraint',  'contraintes' => 'constraint',  'constraint'  => 'constraint',
            ];

            $factType = $typeMap[mb_strtolower(trim($rawType))] ?? null;
            if (!$factType) {
                return AgentResult::reply("Type inconnu. Types valides : projet, preference, decision, competence, contrainte.");
            }

            $count = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where('fact_type', $factType)
                ->count();

            if ($count === 0) {
                $label = self::TYPE_LABELS[$factType] ?? ucfirst($factType);
                return AgentResult::reply("Aucun souvenir de type *{$label}* a archiver.");
            }

            $archived = ConversationMemory::forUser($context->from)
                ->active()
                ->notExpired()
                ->where('fact_type', $factType)
                ->update(['status' => 'archived']);

            $this->clearCache($context->from);

            $emoji = self::TYPE_EMOJIS[$factType] ?? '📝';
            $label = self::TYPE_LABELS[$factType] ?? ucfirst($factType);
            $reply = "🗑️ *{$archived} {$label}(s) archive(s) !*\n\n{$emoji} Tous les souvenirs de type _{$label}_ ont ete archives.\n\n_Restaure avec : restaure <mot-cle>_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemory] bulkArchiveByType failed', ['error' => $e->getMessage()]);
            $reply = "Erreur lors de l'archivage. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    private function clearCache(string $userId): void
    {
        Cache::forget("user:{$userId}:conversation_memory");
    }
}
