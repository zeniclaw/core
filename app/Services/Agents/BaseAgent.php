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

        $this->log($context, "Intent dispatch: no handler for '{$intent}' (method {$method} not found)", [], 'warning');
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
        ];
    }

    public function executeTool(string $name, array $input, AgentContext $context): ?string
    {
        return match ($name) {
            'teach_skill' => $this->baseTeachSkill($input, $context),
            'list_skills' => $this->baseListSkills($context),
            'forget_skill' => $this->baseForgetSkill($input, $context),
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
}
