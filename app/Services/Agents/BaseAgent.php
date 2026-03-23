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

    public function isPrivate(): bool
    {
        return false;
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
                'description' => 'Envoyer un message a un autre agent specialise et recevoir sa reponse. Permet la collaboration inter-agents. Exemples: demander a TodoAgent de creer une tache, demander a ReminderAgent de programmer un rappel, demander a WebSearchAgent de chercher quelque chose. Mode async pour taches longues.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'target_agent' => ['type' => 'string', 'description' => 'Nom de l\'agent cible (ex: "todo", "reminder", "web_search", "document", "finance", "dev")'],
                        'message' => ['type' => 'string', 'description' => 'Le message/instruction a envoyer a l\'agent cible'],
                        'async' => ['type' => 'boolean', 'description' => 'Si true, lance la tache en arriere-plan via spawn_subagent au lieu d\'attendre la reponse. Utile pour les taches longues.'],
                    ],
                    'required' => ['target_agent', 'message'],
                ],
            ],
            // ── Multimodal Tools (D9.1, D9.2) ───────────────────────────
            [
                'name' => 'create_audio',
                'description' => 'Convertir du texte en fichier audio (text-to-speech). Utiliser quand l\'utilisateur demande de lire a voix haute, creer un audio, ou generer une version vocale d\'un texte.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Le texte a convertir en audio'],
                        'voice' => ['type' => 'string', 'description' => 'Voix: "male" ou "female"', 'enum' => ['male', 'female']],
                        'language' => ['type' => 'string', 'description' => 'Langue: "fr", "en", "nl", etc.', 'default' => 'fr'],
                    ],
                    'required' => ['text'],
                ],
            ],
            [
                'name' => 'create_image',
                'description' => 'Generer une image a partir d\'une description textuelle (DALL-E). Utiliser quand l\'utilisateur demande de creer, generer, dessiner une image ou illustration.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Description detaillee de l\'image a generer (en anglais de preference pour meilleurs resultats)'],
                        'size' => ['type' => 'string', 'description' => 'Taille: "1024x1024", "1792x1024" (paysage), "1024x1792" (portrait)', 'enum' => ['1024x1024', '1792x1024', '1024x1792']],
                        'quality' => ['type' => 'string', 'description' => 'Qualite: "standard" ou "hd"', 'enum' => ['standard', 'hd']],
                    ],
                    'required' => ['prompt'],
                ],
            ],
            // ── Code Sandbox Tool (D11.1) ────────────────────────────────
            [
                'name' => 'run_code',
                'description' => 'Executer du code dans un environnement isole (sandbox Docker). Langages supportes: python, php, bash, node. Utiliser pour tester du code, faire des calculs, ou executer des scripts.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Le code a executer'],
                        'language' => ['type' => 'string', 'description' => 'Langage: python, php, bash, node', 'enum' => ['python', 'php', 'bash', 'node']],
                    ],
                    'required' => ['code', 'language'],
                ],
            ],
            // ── Audio Transcription Tool (D9.3) ─────────────────────────
            [
                'name' => 'transcribe_audio',
                'description' => 'Transcrire un fichier audio en texte (speech-to-text). Utilise whisper.cpp local ou OpenAI Whisper. Utile quand l\'utilisateur envoie un message vocal ou un fichier audio a transcrire.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'audio_path' => ['type' => 'string', 'description' => 'Chemin vers le fichier audio a transcrire'],
                        'mimetype' => ['type' => 'string', 'description' => 'Type MIME de l\'audio (ex: audio/ogg, audio/mpeg, audio/wav)', 'default' => 'audio/ogg'],
                    ],
                    'required' => ['audio_path'],
                ],
            ],
            // ── Video Analysis Tool (D9.4) ──────────────────────────────
            [
                'name' => 'analyze_video',
                'description' => 'Analyser une video en extrayant des frames cles et en les soumettant a Claude Vision. Fournir le chemin vers un fichier video.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'video_path' => ['type' => 'string', 'description' => 'Chemin vers le fichier video a analyser'],
                        'question' => ['type' => 'string', 'description' => 'Question specifique sur la video (optionnel)'],
                        'num_frames' => ['type' => 'integer', 'description' => 'Nombre de frames a extraire (2-8, defaut 4)'],
                    ],
                    'required' => ['video_path'],
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
            'create_audio' => $this->baseCreateAudio($input, $context),
            'create_image' => $this->baseCreateImage($input, $context),
            'run_code' => $this->baseRunCode($input, $context),
            'analyze_video' => $this->baseAnalyzeVideo($input, $context),
            'transcribe_audio' => $this->baseTranscribeAudio($input, $context),
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

        // Try hybrid search (semantic + keyword) if embeddings available
        if (\App\Services\EmbeddingService::isAvailable()) {
            return $this->semanticMemorySearch($query, $context);
        }

        // Fallback to keyword search
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

        return json_encode(['results' => $results, 'count' => count($results), 'search_type' => 'keyword']);
    }

    /**
     * Semantic memory search using embeddings (D4.5).
     * Combines keyword matching with vector similarity for best results.
     */
    private function semanticMemorySearch(string $query, AgentContext $context): string
    {
        $memories = \App\Models\ConversationMemory::forUser($context->from)
            ->active()
            ->notExpired()
            ->orderByDesc('created_at')
            ->limit(100) // Get more candidates for semantic ranking
            ->get();

        if ($memories->isEmpty()) {
            return json_encode(['results' => [], 'message' => "Aucun souvenir trouve pour: {$query}"]);
        }

        $items = $memories->map(fn($m) => [
            'id' => $m->id,
            'text' => $m->content,
            'fact_type' => $m->fact_type,
            'tags' => $m->tags,
            'created_at' => $m->created_at->format('d/m/Y H:i'),
        ])->toArray();

        $embedding = new \App\Services\EmbeddingService();
        $results = $embedding->hybridSearch($query, $items);

        if (empty($results)) {
            return json_encode(['results' => [], 'message' => "Aucun souvenir semantiquement proche de: {$query}"]);
        }

        // Clean up results for output
        $cleaned = array_map(fn($r) => [
            'id' => $r['id'],
            'fact_type' => $r['fact_type'],
            'content' => $r['text'],
            'tags' => $r['tags'],
            'created_at' => $r['created_at'],
            'relevance_score' => $r['score'],
            'semantic_score' => $r['semantic_score'],
        ], $results);

        return json_encode([
            'results' => $cleaned,
            'count' => count($cleaned),
            'search_type' => 'hybrid_semantic',
        ]);
    }

    // ── Spawn SubAgent Tool ──────────────────────────────────────

    private function baseSpawnSubagent(array $input, AgentContext $context): string
    {
        $taskDescription = $input['task_description'] ?? '';
        $timeoutMinutes = min((int) ($input['timeout_minutes'] ?? 5), 15);

        if (!$taskDescription) {
            return json_encode(['error' => 'Missing task_description parameter']);
        }

        // Guard: max depth 3
        $currentDepth = $context->currentDepth ?? 0;
        if ($currentDepth >= 3) {
            return json_encode(['error' => 'Profondeur maximale atteinte (max 3 niveaux). Impossible de creer un sous-agent supplementaire.']);
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

        // Dispatch the background job with priority queue support
        \App\Jobs\RunTaskJob::dispatch($subAgent)->onQueue($subAgent->getQueueName());

        // Fire SubagentSpawned event
        \App\Events\SubagentSpawned::dispatch($subAgent, $this->name(), $currentDepth + 1);

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
        $async = $input['async'] ?? false;

        if (!$targetName || !$message) {
            return json_encode(['error' => 'Missing target_agent or message parameter']);
        }

        // D6.3: Async mode — delegate to spawn_subagent
        if ($async) {
            return $this->baseSpawnSubagent([
                'task_description' => "[Inter-agent async → {$targetName}] {$message}",
                'timeout_minutes' => 10,
            ], $context);
        }

        // Guard: max 5 inter-agent calls per agentic loop
        $callCount = $context->interAgentCallCount ?? 0;
        if ($callCount >= 5) {
            return json_encode(['error' => 'Limite de 5 appels inter-agents atteinte pour cette iteration.']);
        }

        // Circuit breaker check (D6.5)
        if (self::isInterAgentCircuitOpen($targetName)) {
            return json_encode(['error' => "L'agent '{$targetName}' est temporairement indisponible (trop d'echecs recents). Reessaie dans quelques minutes ou utilise un autre agent."]);
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

        // Build a context for the target agent with inter-agent source context (D6.2)
        $interContext = new AgentContext(
            agent: $context->agent,
            session: $context->session,
            from: $context->from,
            senderName: $context->senderName,
            body: "[Inter-agent message from {$this->name()}] {$message}",
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
            sourceAgent: $this->name(),
        );

        try {
            $result = $targetAgent->handle($interContext);

            $this->log($context, "Inter-agent message to {$targetName}: " . mb_substr($message, 0, 80), [
                'target' => $targetName,
                'result_action' => $result->action,
            ]);

            // Reset circuit breaker on success
            self::resetInterAgentCircuit($targetName);

            // Structured response (D6.4): include action, metadata, and files if any
            return json_encode([
                'success' => true,
                'agent' => $targetName,
                'response' => $result->reply ?? 'Action effectuee (pas de reponse textuelle).',
                'action' => $result->action,
                'metadata' => $result->metadata ?? [],
                'source_agent' => $this->name(),
            ]);
        } catch (\Exception $e) {
            // Record failure for circuit breaker (D6.5)
            self::recordInterAgentFailure($targetName);

            \Illuminate\Support\Facades\Log::warning("Inter-agent call failed: {$this->name()} → {$targetName}", [
                'error' => $e->getMessage(),
            ]);
            return json_encode(['error' => "Erreur lors de l'appel a {$targetName}: " . $e->getMessage()]);
        }
    }

    // ── Multimodal Tools (D9.1, D9.2) ─────────────────────────

    private function baseCreateAudio(array $input, AgentContext $context): string
    {
        $text = $input['text'] ?? '';
        $voice = $input['voice'] ?? 'male';
        $language = $input['language'] ?? 'fr';

        if (!$text) {
            return json_encode(['error' => 'Missing text parameter']);
        }

        $tts = new \App\Services\TTSService();
        $result = $tts->synthesize($text, $voice, $language);

        if ($result['success']) {
            // Send audio file to user
            $this->sendFile($context->from, $result['path'], 'audio.mp3', 'Audio genere');
            $this->log($context, 'TTS audio generated', ['provider' => $result['provider'] ?? 'unknown', 'duration_ms' => $result['duration_ms']]);
            return json_encode(['success' => true, 'message' => 'Audio genere et envoye.', 'provider' => $result['provider'] ?? 'unknown']);
        }

        return json_encode(['error' => $result['error'] ?? 'TTS generation failed']);
    }

    private function baseCreateImage(array $input, AgentContext $context): string
    {
        $prompt = $input['prompt'] ?? '';
        $size = $input['size'] ?? '1024x1024';
        $quality = $input['quality'] ?? 'standard';

        if (!$prompt) {
            return json_encode(['error' => 'Missing prompt parameter']);
        }

        $imageGen = new \App\Services\ImageGenerationService();
        $result = $imageGen->generate($prompt, $size, $quality);

        if ($result['success']) {
            $this->sendFile($context->from, $result['path'], 'generated.png', $result['revised_prompt'] ?? 'Image generee');
            $this->log($context, 'Image generated', ['prompt' => mb_substr($prompt, 0, 100)]);
            return json_encode(['success' => true, 'message' => 'Image generee et envoyee.', 'revised_prompt' => $result['revised_prompt'] ?? null]);
        }

        return json_encode(['error' => $result['error'] ?? 'Image generation failed']);
    }

    // ── Code Sandbox Tool (D11.1) ───────────────────────────────

    private function baseRunCode(array $input, AgentContext $context): string
    {
        $code = $input['code'] ?? '';
        $language = $input['language'] ?? 'python';

        if (!$code) {
            return json_encode(['error' => 'Missing code parameter']);
        }

        if (!\App\Services\CodeSandbox::isAvailable()) {
            return json_encode(['error' => 'Code sandbox non disponible (Docker requis).']);
        }

        $sandbox = new \App\Services\CodeSandbox();
        $result = $sandbox->run($code, $language);

        $this->log($context, "Code executed ({$language})", [
            'success' => $result['success'],
            'duration_ms' => $result['duration_ms'],
        ]);

        return json_encode($result);
    }

    // ── Video Analysis Tool (D9.4) ─────────────────────────────

    private function baseAnalyzeVideo(array $input, AgentContext $context): string
    {
        $videoPath = $input['video_path'] ?? '';
        $question = $input['question'] ?? null;
        $numFrames = min(max((int)($input['num_frames'] ?? 4), 2), 8);

        if (!$videoPath || !file_exists($videoPath)) {
            return json_encode(['error' => 'Fichier video non trouve: ' . $videoPath]);
        }

        if (!\App\Services\VideoService::isAvailable()) {
            return json_encode(['error' => 'Analyse video non disponible (ffmpeg requis).']);
        }

        $videoService = new \App\Services\VideoService();
        $extraction = $videoService->extractFrames($videoPath, $numFrames);

        if (!$extraction['success']) {
            return json_encode(['error' => $extraction['error'] ?? 'Echec extraction frames']);
        }

        // Send frames to Claude Vision for analysis
        $contentBlocks = $videoService->buildVisionBlocks($extraction['frames'], $question);

        $response = $this->claude->chatWithToolUse(
            [['role' => 'user', 'content' => $contentBlocks]],
            \App\Services\ModelResolver::balanced(),
            'Tu analyses des frames extraites d\'une video. Decris ce que tu vois avec precision.',
            [],
            4096
        );

        $analysis = '';
        if ($response) {
            foreach (($response['content'] ?? []) as $block) {
                if ($block['type'] === 'text') $analysis .= $block['text'];
            }
        }

        // Cleanup frame files
        foreach ($extraction['frames'] as $frame) {
            if (isset($frame['path'])) @unlink($frame['path']);
        }

        $this->log($context, 'Video analyzed', [
            'duration' => $extraction['duration'],
            'frames' => $extraction['frame_count'],
        ]);

        return json_encode([
            'success' => true,
            'analysis' => $analysis,
            'duration_seconds' => $extraction['duration'],
            'frames_analyzed' => $extraction['frame_count'],
        ]);
    }

    // ── Audio Transcription Tool (D9.3) ────────────────────────

    private function baseTranscribeAudio(array $input, AgentContext $context): string
    {
        $audioPath = $input['audio_path'] ?? '';
        $mimetype = $input['mimetype'] ?? 'audio/ogg';

        if (!$audioPath || !file_exists($audioPath)) {
            return json_encode(['error' => 'Fichier audio non trouve: ' . $audioPath]);
        }

        if (!\App\Services\WhisperService::isAvailable()) {
            return json_encode(['error' => 'Transcription non disponible (aucun provider configure).']);
        }

        $audioBytes = file_get_contents($audioPath);
        if ($audioBytes === false || strlen($audioBytes) === 0) {
            return json_encode(['error' => 'Impossible de lire le fichier audio.']);
        }

        // Safety: max 25MB
        if (strlen($audioBytes) > 25 * 1024 * 1024) {
            return json_encode(['error' => 'Fichier audio trop volumineux (max 25 Mo).']);
        }

        $whisper = new \App\Services\WhisperService();
        $text = $whisper->transcribe($audioBytes, $mimetype);

        if ($text) {
            $this->log($context, 'Audio transcribed', ['length' => mb_strlen($text)]);
            return json_encode([
                'success' => true,
                'transcription' => $text,
                'length' => mb_strlen($text),
            ]);
        }

        return json_encode(['error' => 'Echec de la transcription audio.']);
    }

    // ── Inter-Agent Circuit Breaker (D6.5) ──────────────────────

    private static function isInterAgentCircuitOpen(string $targetAgent): bool
    {
        $failures = \Illuminate\Support\Facades\Cache::get("inter_agent_failures:{$targetAgent}", 0);
        if ($failures >= 3) {
            $openedAt = \Illuminate\Support\Facades\Cache::get("inter_agent_circuit_opened:{$targetAgent}", 0);
            if (time() - $openedAt < 120) { // 2 minute cooldown
                return true;
            }
            // Reset after cooldown
            \Illuminate\Support\Facades\Cache::forget("inter_agent_failures:{$targetAgent}");
            \Illuminate\Support\Facades\Cache::forget("inter_agent_circuit_opened:{$targetAgent}");
        }
        return false;
    }

    private static function recordInterAgentFailure(string $targetAgent): void
    {
        $failures = \Illuminate\Support\Facades\Cache::increment("inter_agent_failures:{$targetAgent}");
        if ($failures >= 3) {
            \Illuminate\Support\Facades\Cache::put("inter_agent_circuit_opened:{$targetAgent}", time(), 300);
        }
    }

    private static function resetInterAgentCircuit(string $targetAgent): void
    {
        \Illuminate\Support\Facades\Cache::forget("inter_agent_failures:{$targetAgent}");
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
        return <<<'RULE'
REGLE ANTI-HALLUCINATION (ABSOLUE — priorite maximale):
1. Ne pretends JAMAIS avoir effectue une action si tu n'as PAS utilise un outil (tool_use) pour le faire
2. Si un outil echoue ou n'est pas disponible, dis-le clairement au lieu d'inventer un resultat
3. N'invente JAMAIS de donnees factuelles (noms, chiffres, listes, statistiques, entreprises, prix, adresses, etc.)
4. Si tu as besoin de donnees du monde reel, utilise OBLIGATOIREMENT web_search/web_fetch AVANT de repondre
5. Si tu ne trouves pas l'information, dis "Je n'ai pas trouve cette information" — ne comble JAMAIS le vide avec des donnees inventees
6. Un document/reponse incomplet mais EXACT vaut toujours mieux qu'un document complet avec des donnees fausses
RULE;
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
- create_audio: Convertir du texte en fichier audio (TTS)
- create_image: Generer une image a partir d'une description (DALL-E)
- run_code: Executer du code dans un sandbox isole (python, php, bash, node)
- transcribe_audio: Transcrire un fichier audio en texte (speech-to-text)
- analyze_video: Analyser une video via extraction de frames + Claude Vision

REGLES DE COLLABORATION:
1. Si tu as besoin de donnees que tu n'as pas, utilise web_search ou web_fetch AVANT de repondre — C'EST OBLIGATOIRE
2. Si une tache necessite l'expertise d'un autre agent, utilise send_agent_message
3. Pour les taches longues (>30s), utilise spawn_subagent
4. Ne refuse JAMAIS une demande en disant "je n'ai pas acces" — utilise les outils disponibles
5. Combine plusieurs outils si necessaire (ex: web_search → create_document)
6. REGLE ABSOLUE: N'invente JAMAIS de donnees. Toute donnee factuelle (noms, prix, listes, statistiques) DOIT provenir d'un outil (web_search, web_fetch, memory_search). Si tu ne trouves pas, dis-le honnetement.
COLLAB;
    }
}
