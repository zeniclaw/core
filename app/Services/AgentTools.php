<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\UserKnowledge;
use Illuminate\Support\Facades\Log;

/**
 * Legacy utility tools for the agentic loop.
 *
 * Domain-specific tools (reminders, todos, projects, music, web_search, documents)
 * have been migrated to their respective agents via ToolProviderInterface.
 * This class now only contains shared utility tools (datetime, knowledge).
 */
class AgentTools
{
    /**
     * Get utility tool definitions for the Anthropic API.
     */
    public static function definitions(): array
    {
        $tz = AppSetting::timezone();

        return [
            // ── Utility tools ──
            [
                'name' => 'get_current_datetime',
                'description' => "Get the current date and time in {$tz} timezone. Use this to answer questions about the current time or date.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],

            // ── Knowledge tools (per-user persistent memory) ──
            [
                'name' => 'store_knowledge',
                'description' => 'Store information persistently for this user. Use to save API results, client lists, financial data, preferences, or any data the user might ask about again later. Data is stored PER USER and persists across conversations.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic_key' => ['type' => 'string', 'description' => 'Unique key for this data (e.g. "clients_list", "invoices_2025", "api_endpoints", "compta_2025"). Use snake_case.'],
                        'label' => ['type' => 'string', 'description' => 'Human-readable label (e.g. "Liste des clients", "Factures 2025")'],
                        'data' => ['type' => 'object', 'description' => 'The structured data to store (JSON object)'],
                        'source' => ['type' => 'string', 'description' => 'Where this data came from (e.g. "invoices_api", "user_input", "analysis")'],
                        'ttl_minutes' => ['type' => 'integer', 'description' => 'Optional: data expires after N minutes. Null = permanent. Use for volatile data (API results that change often).'],
                    ],
                    'required' => ['topic_key', 'data'],
                ],
            ],
            [
                'name' => 'recall_knowledge',
                'description' => 'Retrieve previously stored data for this user. ALWAYS check this BEFORE making API calls or asking the user for information you might already have. Search by exact topic_key or keyword.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic_key' => ['type' => 'string', 'description' => 'Exact topic key to recall (e.g. "clients_list")'],
                        'search' => ['type' => 'string', 'description' => 'Search keyword if you don\'t know the exact key (e.g. "client", "facture", "compta")'],
                    ],
                ],
            ],
            [
                'name' => 'list_knowledge',
                'description' => 'List all stored knowledge topics for this user. Use this to see what data is already available before asking or fetching.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
        ];
    }

    /**
     * Execute a tool call and return the result as a string.
     */
    public static function execute(string $toolName, array $input, AgentContext $context): string
    {
        try {
            return match ($toolName) {
                'get_current_datetime' => self::executeGetCurrentDatetime(),
                'store_knowledge' => self::executeStoreKnowledge($input, $context),
                'recall_knowledge' => self::executeRecallKnowledge($input, $context),
                'list_knowledge' => self::executeListKnowledge($context),
                'update_instructions' => self::executeUpdateInstructions($input, $context),
                'update_session_memory' => self::executeUpdateSessionMemory($input, $context),
                'memory_store' => self::executeMemoryStore($input, $context),
                'memory_search' => self::executeMemorySearch($input, $context),
                'teach_skill' => self::executeTeachSkill($input, $context),
                'list_skills' => self::executeListSkills($context),
                'forget_skill' => self::executeForgetSkill($input, $context),
                'gitlab_api' => self::executeGitlabApi($input, $context),
                default => self::executeFallback($toolName, $input, $context),
            };
        } catch (\Exception $e) {
            Log::error("AgentTools::execute failed", ['tool' => $toolName, 'error' => $e->getMessage()]);
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Fallback: try known agent classes that implement executeTool.
     */
    private static function executeFallback(string $toolName, array $input, AgentContext $context): string
    {
        $agentClasses = [
            Agents\ReminderAgent::class,
            Agents\TodoAgent::class,
        ];

        foreach ($agentClasses as $class) {
            if (!class_exists($class)) continue;
            try {
                $agent = new $class();
                $result = $agent->executeTool($toolName, $input, $context);
                if ($result !== null) return $result;
            } catch (\Throwable $e) {
                // This agent doesn't handle this tool — continue
            }
        }

        return json_encode(['error' => "Unknown tool: {$toolName}"]);
    }

    // ── Utility executors ───────────────────────────────────────────

    private static function executeGetCurrentDatetime(): string
    {
        $now = now(AppSetting::timezone());
        $days = ['Monday' => 'lundi', 'Tuesday' => 'mardi', 'Wednesday' => 'mercredi',
            'Thursday' => 'jeudi', 'Friday' => 'vendredi', 'Saturday' => 'samedi', 'Sunday' => 'dimanche'];
        $dayName = $days[$now->format('l')] ?? $now->format('l');

        return json_encode([
            'datetime' => $now->format('Y-m-d H:i:s'),
            'human' => "{$dayName} {$now->format('d/m/Y')} a {$now->format('H:i')}",
            'timezone' => AppSetting::timezone(),
        ]);
    }

    // ── Knowledge executors ─────────────────────────────────────────

    private static function executeStoreKnowledge(array $input, AgentContext $context): string
    {
        $topicKey = $input['topic_key'] ?? '';
        $data = $input['data'] ?? [];
        $label = $input['label'] ?? null;
        $source = $input['source'] ?? null;
        $ttl = $input['ttl_minutes'] ?? null;

        if (!$topicKey || empty($data)) {
            return json_encode(['error' => 'topic_key and data are required.']);
        }

        $entry = UserKnowledge::store($context->from, $topicKey, $data, $label, $source, $ttl);

        return json_encode([
            'success' => true,
            'topic_key' => $topicKey,
            'label' => $label,
            'stored_at' => $entry->updated_at->format('d/m/Y H:i'),
            'expires_at' => $entry->expires_at?->format('d/m/Y H:i'),
        ]);
    }

    private static function executeRecallKnowledge(array $input, AgentContext $context): string
    {
        $topicKey = $input['topic_key'] ?? null;
        $search = $input['search'] ?? null;

        if ($topicKey) {
            $entry = UserKnowledge::recall($context->from, $topicKey);
            if ($entry) {
                return json_encode([
                    'found' => true,
                    'topic_key' => $entry->topic_key,
                    'label' => $entry->label,
                    'data' => $entry->data,
                    'source' => $entry->source,
                    'stored_at' => $entry->updated_at->format('d/m/Y H:i'),
                ]);
            }
            return json_encode(['found' => false, 'topic_key' => $topicKey, 'message' => "No stored data for \"{$topicKey}\"."]);
        }

        if ($search) {
            $results = UserKnowledge::search($context->from, $search);
            if ($results->isEmpty()) {
                return json_encode(['found' => false, 'search' => $search, 'message' => "No stored data matching \"{$search}\"."]);
            }

            $items = $results->map(fn ($r) => [
                'topic_key' => $r->topic_key,
                'label' => $r->label,
                'data' => $r->data,
                'source' => $r->source,
                'stored_at' => $r->updated_at->format('d/m/Y H:i'),
            ])->toArray();

            return json_encode(['found' => true, 'results' => $items]);
        }

        return json_encode(['error' => 'Provide either topic_key or search keyword.']);
    }

    private static function executeListKnowledge(AgentContext $context): string
    {
        $entries = UserKnowledge::allFor($context->from);

        if ($entries->isEmpty()) {
            return json_encode(['entries' => [], 'message' => 'No stored knowledge for this user.']);
        }

        $list = $entries->map(fn ($e) => [
            'topic_key' => $e->topic_key,
            'label' => $e->label,
            'source' => $e->source,
            'stored_at' => $e->updated_at->format('d/m/Y H:i'),
            'expires_at' => $e->expires_at?->format('d/m/Y H:i'),
            'data_preview' => mb_substr(json_encode($e->data, JSON_UNESCAPED_UNICODE), 0, 100),
        ])->toArray();

        return json_encode(['entries' => $list]);
    }

    // ── Persistent files (instructions + session memory) ─────────

    private static function resolveCustomAgent(AgentContext $context): ?\App\Models\CustomAgent
    {
        $activeId = $context->session->active_custom_agent_id ?? null;
        if ($activeId) {
            return \App\Models\CustomAgent::find($activeId);
        }
        if (str_starts_with($context->from, 'partner-')) {
            $shareId = (int) substr($context->from, 8);
            $share = \App\Models\CustomAgentShare::find($shareId);
            return $share?->customAgent;
        }
        return null;
    }

    private static function executeUpdateInstructions(array $input, AgentContext $context): string
    {
        $content = $input['content'] ?? '';
        if (!$content) return json_encode(['error' => 'Missing content']);

        $ca = self::resolveCustomAgent($context);
        if (!$ca) return json_encode(['error' => 'No custom agent context']);

        $path = $ca->workspacePath() . '/instructions.md';
        file_put_contents($path, $content);
        return json_encode(['success' => true, 'message' => 'Instructions mises a jour', 'size' => mb_strlen($content)]);
    }

    private static function executeUpdateSessionMemory(array $input, AgentContext $context): string
    {
        $content = $input['content'] ?? '';
        if (!$content) return json_encode(['error' => 'Missing content']);

        $ca = self::resolveCustomAgent($context);
        if (!$ca) return json_encode(['error' => 'No custom agent context']);

        $dir = $ca->workspacePath('memory');
        $sessionKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $context->from);
        file_put_contents("{$dir}/{$sessionKey}.md", $content);
        return json_encode(['success' => true, 'message' => 'Memoire de session mise a jour', 'size' => mb_strlen($content)]);
    }

    // ── Memory tools (forwarded from BaseAgent) ─────────────────

    private static function executeMemoryStore(array $input, AgentContext $context): string
    {
        $content = $input['content'] ?? '';
        $factType = $input['fact_type'] ?? 'other';
        $tags = $input['tags'] ?? [];
        if (!$content) return json_encode(['error' => 'Missing content']);

        $memory = \App\Models\ConversationMemory::create([
            'user_id' => $context->from,
            'agent_id' => $context->agent->id,
            'content' => $content,
            'fact_type' => $factType,
            'tags' => $tags,
            'source' => 'tool',
        ]);
        return json_encode(['success' => true, 'id' => $memory->id, 'message' => "Memorise: {$content}"]);
    }

    private static function executeMemorySearch(array $input, AgentContext $context): string
    {
        $query = $input['query'] ?? '';
        if (!$query) return json_encode(['error' => 'Missing query']);

        $memories = \App\Models\ConversationMemory::forUser($context->from)
            ->active()->notExpired()->search($query)
            ->orderByDesc('created_at')->limit(10)->get();

        if ($memories->isEmpty()) {
            return json_encode(['results' => [], 'message' => "Aucun souvenir pour: {$query}"]);
        }

        return json_encode(['results' => $memories->map(fn($m) => [
            'content' => $m->content, 'fact_type' => $m->fact_type, 'tags' => $m->tags,
        ])->toArray()]);
    }

    private static function executeTeachSkill(array $input, AgentContext $context): string
    {
        $key = $input['skill_key'] ?? '';
        $title = $input['title'] ?? '';
        $instructions = $input['instructions'] ?? '';
        if (!$key || !$title || !$instructions) return json_encode(['error' => 'Missing required fields']);

        \App\Models\AgentSkill::teach(
            $context->agent->id, 'custom', $key, $title, $instructions,
            $input['examples'] ?? null, $context->from,
        );
        return json_encode(['success' => true, 'message' => "Competence '{$title}' enregistree"]);
    }

    private static function executeListSkills(AgentContext $context): string
    {
        $skills = \App\Models\AgentSkill::allForAgent($context->agent->id);
        if ($skills->isEmpty()) return json_encode(['skills' => [], 'message' => 'Aucune competence apprise']);

        return json_encode(['skills' => $skills->map(fn($s) => [
            'key' => $s->skill_key, 'title' => $s->title, 'instructions' => $s->instructions,
        ])->toArray()]);
    }

    private static function executeForgetSkill(array $input, AgentContext $context): string
    {
        $key = $input['skill_key'] ?? '';
        if (!$key) return json_encode(['error' => 'Missing skill_key']);

        $deleted = \App\Models\AgentSkill::where('agent_id', $context->agent->id)
            ->where('skill_key', $key)->delete();
        return json_encode(['success' => $deleted > 0, 'message' => $deleted ? "Competence '{$key}' oubliee" : "Competence '{$key}' non trouvee"]);
    }

    // ── GitLab API Tool ─────────────────────────────────────────

    private static function executeGitlabApi(array $input, AgentContext $context): string
    {
        $action = $input['action'] ?? '';
        if (!$action) return json_encode(['error' => 'Missing action']);

        // Get token from custom agent credentials
        $ca = self::resolveCustomAgent($context);
        $token = null;
        $host = 'gitlab.com';

        if ($ca) {
            $token = $ca->getCredential('gitlabtoken') ?: $ca->getCredential('gitlab_token');
            $hostCred = $ca->getCredential('gitlab_host');
            if ($hostCred) $host = $hostCred;
        }

        // Fallback to global setting
        if (!$token) {
            $token = \App\Models\AppSetting::get('gitlab_access_token');
        }

        if (!$token) {
            return json_encode(['error' => 'Aucun token GitLab configure. Ajoutez le credential "gitlabtoken" sur cet agent.']);
        }

        $gitlab = new GitLabService($host, $token);
        $projectId = $input['project_id'] ?? '';

        try {
            $result = match ($action) {
                'list_projects' => $gitlab->listProjects($input['search'] ?? '', 20),
                'get_project' => $gitlab->getProject($projectId),
                'list_branches' => $gitlab->listBranches($projectId),
                'list_commits' => $gitlab->listCommits($projectId, $input['branch'] ?? 'main', 10),
                'list_mrs' => $gitlab->listMergeRequests($projectId, $input['state'] ?? 'opened', 10),
                'list_pipelines' => $gitlab->listPipelines($projectId, 5),
                'list_tree' => $gitlab->listTree($projectId, $input['path'] ?? '', $input['branch'] ?? 'main'),
                'read_file' => $gitlab->readFile($projectId, $input['path'] ?? '', $input['branch'] ?? 'main'),
                'list_issues' => $gitlab->listIssues($projectId, $input['state'] ?? 'opened', 10),
                'create_issue' => $gitlab->createIssue($projectId, $input['title'] ?? '', $input['description'] ?? ''),
                'search_code' => $gitlab->searchCode($projectId, $input['search'] ?? ''),
                'compare_branches' => $gitlab->compareBranches($projectId, $input['from'] ?? 'main', $input['to'] ?? 'develop'),
                'list_environments' => $gitlab->listEnvironments($projectId),
                default => ['error' => "Action inconnue: {$action}"],
            };

            if ($result === null) {
                return json_encode(['error' => "Appel API GitLab echoue pour action: {$action}. Verifiez le project_id et le token."]);
            }

            // Truncate large responses
            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (mb_strlen($json) > 8000) {
                $json = mb_substr($json, 0, 8000) . "\n... (tronque)";
            }
            return $json;
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
