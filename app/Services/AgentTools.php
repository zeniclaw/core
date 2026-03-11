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
                default => json_encode(['error' => "Unknown tool: {$toolName}"]),
            };
        } catch (\Exception $e) {
            Log::error("AgentTools::execute failed", ['tool' => $toolName, 'error' => $e->getMessage()]);
            return json_encode(['error' => $e->getMessage()]);
        }
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
}
