<?php

namespace App\Services\Agents;

use App\Models\AgentLog;
use App\Services\AgentContext;
use App\Services\AnthropicClient;
use App\Services\ContextMemory\ContextStore;
use App\Services\ContextMemoryBridge;
use App\Services\ConversationMemoryService;
use App\Services\ModelResolver;
use App\Services\PreferencesManager;
use Illuminate\Support\Facades\Http;

abstract class BaseAgent implements AgentInterface, ToolProviderInterface
{
    protected string $wahaBase = 'http://waha:3000';
    protected string $wahaApiKey = 'zeniclaw-waha-2026';
    protected string $sessionName = 'default';

    protected AnthropicClient $claude;
    protected ConversationMemoryService $memory;
    protected PreferencesManager $preferencesManager;

    public function __construct()
    {
        $this->claude = new AnthropicClient();
        $this->memory = new ConversationMemoryService();
        $this->preferencesManager = new PreferencesManager();
    }

    protected function getUserPrefs(AgentContext $context): array
    {
        return PreferencesManager::getPreferences($context->from);
    }

    public function description(): string
    {
        return '';
    }

    public function keywords(): array
    {
        return [];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    protected function waha(int $timeout = 15)
    {
        return Http::timeout($timeout)->withHeaders(['X-Api-Key' => $this->wahaApiKey]);
    }

    protected function sendText(string $chatId, string $text): void
    {
        // Skip WhatsApp send for web chat sessions — reply goes through HTTP response
        if (str_starts_with($chatId, 'web-')) {
            return;
        }

        $maxRetries = 3;
        $lastException = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $response = $this->waha(15)->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $chatId,
                    'text' => $text,
                    'session' => $this->sessionName,
                ]);

                if ($response->successful()) {
                    return;
                }

                \Illuminate\Support\Facades\Log::warning("sendText attempt " . ($i + 1) . " failed: HTTP " . $response->status());
            } catch (\Exception $e) {
                $lastException = $e;
                \Illuminate\Support\Facades\Log::warning("sendText attempt " . ($i + 1) . " exception: " . $e->getMessage());
            }

            if ($i < $maxRetries - 1) {
                sleep(3 * ($i + 1));
            }
        }

        if ($lastException) {
            \Illuminate\Support\Facades\Log::error("sendText failed after {$maxRetries} attempts: " . $lastException->getMessage());
        }
    }

    protected function sendFile(string $chatId, string $filePath, string $filename, ?string $caption = null): void
    {
        if (str_starts_with($chatId, 'web-')) {
            return;
        }

        $data = base64_encode(file_get_contents($filePath));
        $mimetype = mime_content_type($filePath) ?: 'application/octet-stream';

        try {
            $this->waha(30)->post("{$this->wahaBase}/api/sendFile", [
                'chatId' => $chatId,
                'file' => [
                    'data' => $data,
                    'filename' => $filename,
                    'mimetype' => $mimetype,
                ],
                'caption' => $caption,
                'session' => $this->sessionName,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("sendFile failed: " . $e->getMessage());
        }
    }

    protected function log(AgentContext $context, string $message, array $extra = [], string $level = 'info'): void
    {
        AgentLog::create([
            'agent_id' => $context->agent->id,
            'level' => $level,
            'message' => "[{$this->name()}] {$message}",
            'context' => array_merge(['from' => $context->from], $extra),
        ]);
    }

    protected function resolveModel(AgentContext $context): string
    {
        return $context->routedModel ?? ModelResolver::fast();
    }

    protected function getContextMemory(string $userId): array
    {
        $store = new ContextStore();
        return $store->retrieve($userId);
    }

    /**
     * Get shared inter-agent context from ContextMemoryBridge.
     * All agents can call this to access the hot Redis context cache.
     */
    protected function getSharedContext(string $userId): array
    {
        return ContextMemoryBridge::getInstance()->getContext($userId);
    }

    /**
     * Get shared context formatted as a prompt string.
     */
    protected function getSharedContextForPrompt(string $userId): string
    {
        return ContextMemoryBridge::getInstance()->formatForPrompt($userId);
    }

    /**
     * Store pending context on the session so follow-up messages are routed back to this agent.
     * Structure: {agent: "dev", type: "list_selection", data: {...}, expires_at: "..."}
     */
    protected function setPendingContext(AgentContext $context, string $type, array $data = [], int $ttlMinutes = 5, bool $expectRawInput = false): void
    {
        $context->session->update([
            'pending_agent_context' => [
                'agent' => $this->name(),
                'type' => $type,
                'data' => $data,
                'expect_raw_input' => $expectRawInput,
                'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
            ],
        ]);
    }

    protected function clearPendingContext(AgentContext $context): void
    {
        $context->session->update(['pending_agent_context' => null]);
    }

    /**
     * Override in subclasses to handle follow-up messages from pending context.
     * Return null to fall through to normal routing.
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        return null;
    }

    protected function formatContextMemoryForPrompt(string $userId, ?AgentContext $context = null): string
    {
        $parts = [];

        $facts = $this->getContextMemory($userId);
        if (!empty($facts)) {
            $lines = ['PROFIL UTILISATEUR (memoire contextuelle):'];
            foreach ($facts as $fact) {
                $category = $fact['category'] ?? 'general';
                $value = $fact['value'] ?? '';
                $lines[] = "- [{$category}] {$value}";
            }
            $parts[] = implode("\n", $lines);
        }

        // Append persistent conversation memory if injected via context
        if ($context && $context->memoryContext) {
            $parts[] = $context->memoryContext;
        }

        return implode("\n\n", $parts);
    }

    // ── Intent Classifier ──────────────────────────────────────────

    /**
     * Define supported intents for this agent.
     * Override in subclasses. Each intent has: key, description, examples.
     * Return empty array to skip intent classification (legacy behavior).
     */
    public function intents(): array
    {
        return [];
    }

    /**
     * Get recent conversation history formatted for intent classification.
     * Override maxEntries for more/less history.
     */
    protected function getConversationHistoryForClassifier(AgentContext $context, int $maxEntries = 5): string
    {
        $history = $this->memory->read($context->agent->id, $context->from);
        $entries = $history['entries'] ?? [];

        if (empty($entries)) {
            return '';
        }

        $recent = array_slice($entries, -$maxEntries);
        $lines = ["HISTORIQUE RECENT:"];
        foreach ($recent as $entry) {
            $msg = mb_substr($entry['sender_message'] ?? '', 0, 120);
            $reply = mb_substr($entry['agent_reply'] ?? '', 0, 120);
            $lines[] = "- User: {$msg}";
            $lines[] = "  Agent: {$reply}";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Classify user message intent using Haiku LLM.
     * Returns ['intent' => string, 'args' => array, 'confidence' => int].
     * Automatically includes recent conversation history for better context.
     */
    protected function classifyIntent(AgentContext $context, string $extraContext = ''): array
    {
        $intents = $this->intents();
        if (empty($intents)) {
            return ['intent' => 'default', 'args' => [], 'confidence' => 0];
        }

        // Build intent descriptions for the prompt
        $intentList = '';
        foreach ($intents as $intent) {
            $intentList .= "- {$intent['key']}: {$intent['description']}\n";
            if (!empty($intent['examples'])) {
                foreach ($intent['examples'] as $ex) {
                    $intentList .= "  ex: \"{$ex}\"\n";
                }
            }
        }

        // Automatically include conversation history
        $historyContext = $this->getConversationHistoryForClassifier($context);

        $prompt = <<<PROMPT
Tu classes le message d'un utilisateur en une INTENTION parmi celles disponibles.

INTENTIONS DISPONIBLES:
{$intentList}
{$extraContext}{$historyContext}
Reponds UNIQUEMENT en JSON valide:
{"intent": "nom_intent", "args": {}, "confidence": 85}

- "args": parametres extraits du message (nom de projet, query, etc.)
- "confidence": 0-100, ta certitude
- Utilise l'HISTORIQUE RECENT pour comprendre le contexte (ex: si l'utilisateur a parle d'un projet API, un message court fait probablement reference a ce contexte)

Si aucune intention ne correspond bien, utilise "default" avec confidence basse.

JSON UNIQUEMENT.
PROMPT;

        $response = $this->claude->chat(
            "Message: \"{$context->body}\"",
            ModelResolver::fast(),
            $prompt
        );

        if (!$response) {
            return ['intent' => 'default', 'args' => [], 'confidence' => 0];
        }

        $clean = trim($response);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $parsed = json_decode($clean, true);
        if (!$parsed || empty($parsed['intent'])) {
            return ['intent' => 'default', 'args' => [], 'confidence' => 0];
        }

        return [
            'intent' => $parsed['intent'],
            'args' => $parsed['args'] ?? [],
            'confidence' => (int) ($parsed['confidence'] ?? 50),
        ];
    }

    /**
     * Dispatch to handler based on classified intent.
     * Convention: intent "api_query" calls handleIntentApiQuery($args, $context).
     * Returns null if no handler found (falls back to default behavior).
     */
    protected function dispatchIntent(array $classified, AgentContext $context): ?AgentResult
    {
        $intent = $classified['intent'];
        if ($intent === 'default') {
            $this->log($context, 'Intent dispatch: default (no match)');
            return null;
        }

        // Convert snake_case intent to camelCase method name
        $method = 'handleIntent' . str_replace('_', '', ucwords($intent, '_'));

        if (method_exists($this, $method)) {
            $this->log($context, "Intent dispatch: {$intent} → {$method}()", [
                'confidence' => $classified['confidence'],
                'args' => $classified['args'],
            ]);
            return $this->$method($classified['args'], $context);
        }

        $this->log($context, "Intent dispatch: no handler for '{$intent}' (method {$method} not found)", [], 'warn');
        return null;
    }

    // ── ToolProviderInterface ──────────────────────────────────────

    /**
     * Base skill tools available to ALL agents.
     * Subclasses override and call parent::tools() + their own.
     */
    public function tools(): array
    {
        return [
            [
                'name' => 'teach_skill',
                'description' => 'Enseigner une nouvelle competence/instruction a cet agent. L\'agent retiendra cette information pour toutes les conversations futures. Utiliser quand l\'utilisateur dit "retiens que...", "apprends que...", "souviens-toi que...", "a l\'avenir...".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_key' => ['type' => 'string', 'description' => 'Identifiant unique court en snake_case (ex: "format_factures", "langue_reponse", "style_email")'],
                        'title' => ['type' => 'string', 'description' => 'Titre court de la competence (ex: "Format des factures", "Langue de reponse")'],
                        'instructions' => ['type' => 'string', 'description' => 'Instructions detaillees que l\'agent doit retenir et appliquer'],
                        'examples' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Exemples optionnels d\'utilisation'],
                    ],
                    'required' => ['skill_key', 'title', 'instructions'],
                ],
            ],
            [
                'name' => 'list_skills',
                'description' => 'Lister toutes les competences apprises par cet agent. Utiliser quand l\'utilisateur demande "qu\'est-ce que tu sais ?", "tes competences", "qu\'as-tu retenu ?".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'forget_skill',
                'description' => 'Oublier/supprimer une competence apprise. Utiliser quand l\'utilisateur dit "oublie que...", "supprime la competence...".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_key' => ['type' => 'string', 'description' => 'Identifiant de la competence a oublier'],
                    ],
                    'required' => ['skill_key'],
                ],
            ],
            // ── Memory Tools ──────────────────────────────────────────
            [
                'name' => 'memory_store',
                'description' => 'Sauvegarder un fait ou une information importante sur l\'utilisateur dans la memoire persistante. Utiliser quand l\'utilisateur partage une info personnelle, une preference, ou quand tu decouvres quelque chose d\'utile a retenir pour les prochaines conversations.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Le fait ou l\'information a memoriser (ex: "L\'utilisateur habite a Bruxelles", "Il prefere les reponses courtes")'],
                        'fact_type' => ['type' => 'string', 'description' => 'Type de fait: preference, personal_info, work_context, relationship, habit, other', 'enum' => ['preference', 'personal_info', 'work_context', 'relationship', 'habit', 'other']],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags optionnels pour categoriser (ex: ["localisation", "belgique"])'],
                    ],
                    'required' => ['content', 'fact_type'],
                ],
            ],
            [
                'name' => 'memory_search',
                'description' => 'Chercher dans la memoire persistante de l\'utilisateur. Utiliser pour retrouver des informations precedemment sauvegardees. Utile quand l\'utilisateur fait reference a quelque chose qu\'il a deja dit.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Mot-cle ou phrase a chercher dans la memoire'],
                    ],
                    'required' => ['query'],
                ],
            ],
            // ── Spawn SubAgent Tool ───────────────────────────────────
            [
                'name' => 'spawn_subagent',
                'description' => 'Lancer une tache autonome en arriere-plan. Le sous-agent executera la tache de maniere independante avec ses propres outils (recherche web, creation de documents, etc.). Utiliser pour les taches longues: recherches approfondies, creation de fichiers complexes, collecte de donnees. L\'utilisateur sera notifie quand la tache sera terminee.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_description' => ['type' => 'string', 'description' => 'Description detaillee de la tache a effectuer (sois precis pour que le sous-agent sache exactement quoi faire)'],
                        'timeout_minutes' => ['type' => 'integer', 'description' => 'Duree max en minutes (defaut: 5, max: 15)', 'default' => 5],
                    ],
                    'required' => ['task_description'],
                ],
            ],
            // ── Send Agent Message Tool ───────────────────────────────
            [
                'name' => 'send_agent_message',
                'description' => 'Envoyer un message a un autre agent specialise et recevoir sa reponse. Permet la collaboration inter-agents. Exemples: demander a TodoAgent de creer une tache, demander a ReminderAgent de programmer un rappel, demander a WebSearchAgent de chercher quelque chose.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'target_agent' => ['type' => 'string', 'description' => 'Nom de l\'agent cible (ex: "todo", "reminder", "web_search", "document", "finance", "dev")'],
                        'message' => ['type' => 'string', 'description' => 'Le message/instruction a envoyer a l\'agent cible'],
                    ],
                    'required' => ['target_agent', 'message'],
                ],
            ],
        ];
    }

    public function executeTool(string $name, array $input, AgentContext $context): ?string
    {
        return match ($name) {
            'teach_skill' => $this->baseTeachSkill($input, $context),
            'list_skills' => $this->baseListSkills($context),
            'forget_skill' => $this->baseForgetSkill($input, $context),
            'memory_store' => $this->baseMemoryStore($input, $context),
            'memory_search' => $this->baseMemorySearch($input, $context),
            'spawn_subagent' => $this->baseSpawnSubagent($input, $context),
            'send_agent_message' => $this->baseSendAgentMessage($input, $context),
            default => null,
        };
    }

    private function baseTeachSkill(array $input, AgentContext $context): string
    {
        $skill = \App\Models\AgentSkill::teach(
            agentId: $context->agent->id,
            subAgent: $this->name(),
            skillKey: $input['skill_key'],
            title: $input['title'],
            instructions: $input['instructions'],
            examples: $input['examples'] ?? null,
            taughtBy: $context->from,
        );

        return json_encode([
            'success' => true,
            'skill_key' => $skill->skill_key,
            'title' => $skill->title,
            'message' => "Competence '{$skill->title}' enregistree. Je m'en souviendrai pour nos prochaines conversations.",
        ]);
    }

    private function baseListSkills(AgentContext $context): string
    {
        $skills = \App\Models\AgentSkill::forAgent($context->agent->id, $this->name());

        if ($skills->isEmpty()) {
            // Also check global skills (sub_agent = '*')
            $skills = \App\Models\AgentSkill::allForAgent($context->agent->id);
        }

        if ($skills->isEmpty()) {
            return json_encode(['skills' => [], 'message' => 'Aucune competence apprise pour le moment.']);
        }

        $list = $skills->map(fn($s) => [
            'skill_key' => $s->skill_key,
            'title' => $s->title,
            'instructions' => $s->instructions,
            'sub_agent' => $s->sub_agent,
            'taught_at' => $s->created_at->format('d/m/Y H:i'),
        ])->toArray();

        return json_encode(['skills' => $list, 'count' => count($list)]);
    }

    private function baseForgetSkill(array $input, AgentContext $context): string
    {
        $skill = \App\Models\AgentSkill::where('agent_id', $context->agent->id)
            ->where('sub_agent', $this->name())
            ->where('skill_key', $input['skill_key'])
            ->first();

        if (!$skill) {
            return json_encode(['error' => "Competence '{$input['skill_key']}' non trouvee."]);
        }

        $title = $skill->title;
        $skill->update(['active' => false]);

        return json_encode([
            'success' => true,
            'message' => "Competence '{$title}' oubliee.",
        ]);
    }

    // ── Memory Tools ──────────────────────────────────────────────

    private function baseMemoryStore(array $input, AgentContext $context): string
    {
        $content = $input['content'] ?? '';
        $factType = $input['fact_type'] ?? 'other';
        $tags = $input['tags'] ?? [];

        if (!$content) {
            return json_encode(['error' => 'Missing content parameter']);
        }

        $memory = \App\Models\ConversationMemory::create([
            'user_id' => $context->from,
            'fact_type' => $factType,
            'content' => $content,
            'tags' => $tags,
            'status' => 'active',
        ]);

        return json_encode([
            'success' => true,
            'memory_id' => $memory->id,
            'message' => "Memorise: {$content}",
        ]);
    }

    private function baseMemorySearch(array $input, AgentContext $context): string
    {
        $query = $input['query'] ?? '';
        if (!$query) {
            return json_encode(['error' => 'Missing query parameter']);
        }

        $memories = \App\Models\ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->search($query)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($memories->isEmpty()) {
            return json_encode(['results' => [], 'message' => "Aucun souvenir trouve pour: {$query}"]);
        }

        $results = $memories->map(fn($m) => [
            'id' => $m->id,
            'fact_type' => $m->fact_type,
            'content' => $m->content,
            'tags' => $m->tags,
            'created_at' => $m->created_at->format('d/m/Y H:i'),
        ])->toArray();

        return json_encode(['results' => $results, 'count' => count($results)]);
    }

    // ── Spawn SubAgent Tool ──────────────────────────────────────

    private function baseSpawnSubagent(array $input, AgentContext $context): string
    {
        $taskDescription = $input['task_description'] ?? '';
        $timeoutMinutes = min((int) ($input['timeout_minutes'] ?? 5), 15);

        if (!$taskDescription) {
            return json_encode(['error' => 'Missing task_description parameter']);
        }

        // Guard: max depth 2
        $currentDepth = $context->currentDepth ?? 0;
        if ($currentDepth >= 2) {
            return json_encode(['error' => 'Profondeur maximale atteinte (max 2 niveaux). Impossible de creer un sous-agent supplementaire.']);
        }

        // Guard: max 5 active subagents per user
        $activeCount = \App\Models\SubAgent::where('requester_phone', $context->from)
            ->whereIn('status', ['queued', 'running'])
            ->count();

        if ($activeCount >= 5) {
            return json_encode(['error' => "Trop de taches en cours ({$activeCount}/5). Attends qu'une tache se termine."]);
        }

        // Create the SubAgent record
        $subAgent = \App\Models\SubAgent::create([
            'type' => 'research',
            'requester_phone' => $context->from,
            'status' => 'queued',
            'task_description' => $taskDescription,
            'timeout_minutes' => $timeoutMinutes,
            'parent_id' => $context->currentSubAgentId,
            'spawning_agent' => $this->name(),
            'depth' => $currentDepth + 1,
        ]);

        // Dispatch the background job
        \App\Jobs\RunTaskJob::dispatch($subAgent)->onQueue('default');

        $this->log($context, "Spawned subagent #{$subAgent->id}: " . mb_substr($taskDescription, 0, 100), [
            'subagent_id' => $subAgent->id,
            'depth' => $currentDepth + 1,
        ]);

        return json_encode([
            'success' => true,
            'subagent_id' => $subAgent->id,
            'message' => "Tache lancee en arriere-plan (#{$subAgent->id}). L'utilisateur sera notifie quand elle sera terminee.",
            'timeout_minutes' => $timeoutMinutes,
        ]);
    }

    // ── Send Agent Message Tool ──────────────────────────────────

    private function baseSendAgentMessage(array $input, AgentContext $context): string
    {
        $targetName = $input['target_agent'] ?? '';
        $message = $input['message'] ?? '';

        if (!$targetName || !$message) {
            return json_encode(['error' => 'Missing target_agent or message parameter']);
        }

        // Guard: max 3 inter-agent calls per agentic loop
        $callCount = $context->interAgentCallCount ?? 0;
        if ($callCount >= 3) {
            return json_encode(['error' => 'Limite de 3 appels inter-agents atteinte pour cette iteration.']);
        }

        // Resolve the target agent
        $targetAgent = \App\Services\AgentOrchestrator::resolveAgent($targetName);
        if (!$targetAgent) {
            return json_encode(['error' => "Agent '{$targetName}' non trouve. Agents disponibles: todo, reminder, web_search, document, finance, dev, chat, etc."]);
        }

        // Prevent self-calls
        if ($targetAgent->name() === $this->name()) {
            return json_encode(['error' => 'Un agent ne peut pas s\'envoyer un message a lui-meme.']);
        }

        // Increment call count
        $context->interAgentCallCount = ($context->interAgentCallCount ?? 0) + 1;

        // Build a context for the target agent with the inter-agent message
        $interContext = new AgentContext(
            agent: $context->agent,
            session: $context->session,
            from: $context->from,
            senderName: $context->senderName,
            body: $message,
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
            routedAgent: $targetAgent->name(),
            routedModel: $context->routedModel,
            complexity: $context->complexity,
            reasoning: "inter-agent call from {$this->name()}",
            memoryContext: $context->memoryContext,
            toolRegistry: $context->toolRegistry,
        );

        try {
            $result = $targetAgent->handle($interContext);

            $this->log($context, "Inter-agent message to {$targetName}: " . mb_substr($message, 0, 80), [
                'target' => $targetName,
                'result_action' => $result->action,
            ]);

            return json_encode([
                'success' => true,
                'agent' => $targetName,
                'response' => $result->reply ?? 'Action effectuee (pas de reponse textuelle).',
                'action' => $result->action,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Inter-agent call failed: {$this->name()} → {$targetName}", [
                'error' => $e->getMessage(),
            ]);
            return json_encode(['error' => "Erreur lors de l'appel a {$targetName}: " . $e->getMessage()]);
        }
    }

    /**
     * Get learned skills formatted for injection into the system prompt.
     */
    protected function getSkillsForPrompt(AgentContext $context): string
    {
        return \App\Models\AgentSkill::formatForPrompt($context->agent->id, $this->name());
    }

    /**
     * Anti-hallucination rule to inject into agent system prompts.
     */
    protected function getAntiHallucinationRule(): string
    {
        return "REGLE ANTI-HALLUCINATION: Ne pretends JAMAIS avoir effectue une action si tu n'as PAS utilise un outil (tool_use) pour le faire. Si un outil echoue ou n'est pas disponible, dis-le clairement au lieu d'inventer un resultat.";
    }

    // ── Agentic Loop Helper ──────────────────────────────────────

    /**
     * Run the agentic loop with all registered tools.
     * Any agent can call this to get full tool access (web_search, create_document,
     * send_agent_message, spawn_subagent, memory, etc.).
     *
     * Use this instead of $this->claude->chat() when the task may require
     * external data, collaboration with other agents, or tool usage.
     */
    protected function runWithTools(
        string|array $userMessage,
        string $systemPrompt,
        AgentContext $context,
        ?string $model = null,
        int $maxIterations = 10,
    ): \App\Services\AgenticLoopResult {
        $model = $model ?? $this->resolveModel($context);

        // Append collaboration instructions to system prompt
        $systemPrompt .= "\n\n" . $this->getCollaborationPrompt();
        $systemPrompt .= "\n" . $this->getAntiHallucinationRule();

        $loop = new \App\Services\AgenticLoop(maxIterations: $maxIterations, debug: $context->session->debug_mode ?? false);

        return $loop->run(
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            model: $model,
            context: $context,
        );
    }

    /**
     * Collaboration instructions injected into every agent that uses runWithTools().
     * Tells the LLM about the tools available and how to use inter-agent communication.
     */
    protected function getCollaborationPrompt(): string
    {
        return <<<'COLLAB'
COLLABORATION INTER-AGENTS:
Tu fais partie d'un ecosysteme d'agents specialises. Tu as acces a ces outils de collaboration:

- web_search: Chercher des informations sur le web en temps reel
- web_fetch: Lire le contenu complet d'une page web
- create_document: Creer un fichier Excel, PDF, Word ou CSV
- send_agent_message: Envoyer un message a un autre agent et recevoir sa reponse
  Agents disponibles: todo, reminder, web_search, document, finance, dev, chat, daily_brief, recipe, music, pomodoro, flashcard, event_reminder, habit
- spawn_subagent: Lancer une tache autonome en arriere-plan (recherches longues, collecte de donnees)
- memory_store / memory_search: Sauvegarder/chercher des infos sur l'utilisateur
- teach_skill / list_skills / forget_skill: Gerer les competences apprises

REGLES DE COLLABORATION:
1. Si tu as besoin de donnees que tu n'as pas, utilise web_search ou web_fetch AVANT de repondre
2. Si une tache necessite l'expertise d'un autre agent, utilise send_agent_message
3. Pour les taches longues (>30s), utilise spawn_subagent
4. Ne refuse JAMAIS une demande en disant "je n'ai pas acces" — utilise les outils disponibles
5. Combine plusieurs outils si necessaire (ex: web_search → create_document)
COLLAB;
    }
}
