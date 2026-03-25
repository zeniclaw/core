<?php

namespace App\Services;

use App\Models\CustomAgent;
use App\Services\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\BaseAgent;
use Illuminate\Support\Facades\Log;

/**
 * Model tier classification for adaptive RAG.
 * Determines chunk limits, search strategy, and prompt format
 * based on model context window and reasoning capability.
 */
enum ModelTier: string
{
    case Small = 'small';       // qwen 0.5b-3b, gemma 2b, phi3 mini — tiny context, basic reasoning
    case Medium = 'medium';     // qwen 7b-14b, mistral 7b, llama 8b — decent context, good reasoning
    case Balanced = 'balanced'; // Haiku, Sonnet, GPT-3.5/4o-mini — large context, strong reasoning
    case Powerful = 'powerful';  // Opus, GPT-4 — massive context, best reasoning
}

/**
 * CustomAgentRunner — runtime engine for user-created custom agents.
 *
 * NOT placed in Agents/ directory to avoid glob auto-discovery.
 * Supports both RAG knowledge retrieval and selectable tools (agentic loop).
 */
class CustomAgentRunner extends BaseAgent
{
    private CustomAgent $customAgent;
    private KnowledgeChunker $chunker;

    /**
     * Available tool groups that can be enabled on custom agents.
     * Each group maps to tool names from registered agents.
     */
    public const TOOL_GROUPS = [
        'web_search' => [
            'label' => 'Recherche Web',
            'icon' => '🌐',
            'description' => 'Chercher sur internet, lire des pages web',
            'tools' => ['web_search', 'web_fetch', 'web_extract'],
        ],
        'document' => [
            'label' => 'Creation de documents',
            'icon' => '📄',
            'description' => 'Creer des fichiers Excel, PDF, Word, CSV',
            'tools' => ['create_document'],
        ],
        'code' => [
            'label' => 'Execution de code',
            'icon' => '💻',
            'description' => 'Executer du code Python, PHP, Bash, Node',
            'tools' => ['run_code'],
        ],
        'image' => [
            'label' => 'Generation d\'images',
            'icon' => '🎨',
            'description' => 'Creer des images avec DALL-E',
            'tools' => ['create_image'],
        ],
        'audio' => [
            'label' => 'Audio (TTS)',
            'icon' => '🔊',
            'description' => 'Convertir du texte en audio',
            'tools' => ['create_audio', 'transcribe_audio'],
        ],
        'todo' => [
            'label' => 'Gestion de taches',
            'icon' => '✅',
            'description' => 'Creer, lister, cocher des taches',
            'tools' => ['add_todos', 'list_todos', 'check_todos', 'uncheck_todos', 'delete_todos'],
        ],
        'reminder' => [
            'label' => 'Rappels',
            'icon' => '⏰',
            'description' => 'Creer et gerer des rappels',
            'tools' => ['create_reminder', 'list_reminders', 'delete_reminder', 'postpone_reminder'],
        ],
        'collaboration' => [
            'label' => 'Collaboration inter-agents',
            'icon' => '🤝',
            'description' => 'Communiquer avec d\'autres agents, lancer des sous-taches',
            'tools' => ['send_agent_message', 'spawn_subagent'],
        ],
        'memory' => [
            'label' => 'Memoire persistante',
            'icon' => '🧠',
            'description' => 'Sauvegarder et rechercher des infos sur l\'utilisateur',
            'tools' => ['memory_store', 'memory_search', 'teach_skill', 'list_skills', 'forget_skill'],
        ],
    ];

    public function __construct(CustomAgent $customAgent)
    {
        parent::__construct();
        $this->customAgent = $customAgent;
        $this->chunker = new KnowledgeChunker();
    }

    public function name(): string
    {
        return $this->customAgent->routingKey();
    }

    public function canHandle(AgentContext $context): bool
    {
        return true;
    }

    public function description(): string
    {
        return $this->customAgent->description ?? $this->customAgent->name;
    }

    public function keywords(): array
    {
        $text = $this->customAgent->name . ' ' . ($this->customAgent->description ?? '');
        $words = preg_split('/\s+/', mb_strtolower($text));
        return array_values(array_unique(array_filter($words, fn($w) => mb_strlen($w) > 3)));
    }

    public function handle(AgentContext $context): AgentResult
    {
        $this->log($context, "Custom agent '{$this->customAgent->name}' handling message");

        // 1. Resolve model
        $model = $this->customAgent->model !== 'default'
            ? $this->customAgent->model
            : $this->resolveModel($context);

        // 2. Classify model capabilities
        $tier = $this->classifyModel($model);

        // 3. Retrieve relevant knowledge via adaptive RAG
        $ragContext = $this->retrieveKnowledge($context->body ?? '', $tier);

        // 4. Build system prompt with injected knowledge (format adapted to tier)
        $systemPrompt = $this->buildSystemPrompt($ragContext, $context, $tier);

        // 5. Check if tools are enabled — use agentic loop or simple chat
        // Small/medium on-prem models can't handle tool_use — fall back to simple chat
        $enabledTools = $this->customAgent->enabled_tools ?? [];
        $hasTools = !empty($enabledTools);
        $canUseTools = in_array($tier, [ModelTier::Balanced, ModelTier::Powerful]);

        // Test chat sessions bypass agentic loop (no tool execution needed)
        $isTestChat = str_starts_with($context->from, 'web-custom-test-');

        if ($hasTools && $canUseTools && !$isTestChat) {
            $result = $this->handleWithTools($context, $systemPrompt, $model, $enabledTools, $ragContext);
            // Fallback to simple chat if agentic loop failed
            if (!$result->reply || str_contains($result->reply, 'trop volumineux') || str_contains($result->reply, "n'ai pas pu")) {
                $this->log($context, "Agentic loop failed, falling back to simple chat");
                $result = $this->handleSimpleChat($context, $systemPrompt, $model, $tier);
            }
        } else {
            $result = $this->handleSimpleChat($context, $systemPrompt, $model, $tier);
        }

        $this->log($context, "Custom agent replied", [
            'model' => $model,
            'tier' => $tier->value,
            'rag_chunks' => count($ragContext),
        ]);

        return $result;
    }

    /**
     * Handle with agentic loop (tools enabled).
     */
    private function handleWithTools(AgentContext $context, string $systemPrompt, string $model, array $enabledGroups, array $ragContext): AgentResult
    {
        // Build filtered tool list from enabled groups
        $allowedToolNames = $this->getEnabledToolNames($enabledGroups);

        // Get all tool definitions and filter
        $allTools = $context->toolRegistry
            ? $context->toolRegistry->definitions()
            : AgentTools::definitions();

        $filteredTools = array_values(array_filter($allTools, function ($tool) use ($allowedToolNames) {
            return in_array($tool['name'] ?? '', $allowedToolNames);
        }));

        // Always include base tools (memory, skills) if memory group is enabled
        // Add RAG search as a tool
        if (!empty($ragContext)) {
            $systemPrompt .= "\n\nTu as des CONNAISSANCES provenant de tes documents. Utilise-les en priorite avant de chercher sur le web.";
        }

        $loop = new AgenticLoop(maxIterations: 10, debug: $context->session->debug_mode ?? false);

        $loopResult = $loop->run(
            userMessage: $context->body ?? '',
            systemPrompt: $systemPrompt,
            model: $model,
            context: $context,
            tools: $filteredTools,
        );

        $response = $loopResult->reply;

        if (!$response) {
            return AgentResult::reply("Désolé, je n'ai pas pu traiter votre message. Réessayez.");
        }

        // Save conversation
        $this->memory->append($context->agent->id, $context->from, $context->senderName, $context->body ?? '', $response);
        $this->sendText($context->from, $response);

        return AgentResult::reply($response, [
            'custom_agent_id' => $this->customAgent->id,
            'rag_chunks_used' => count($ragContext),
            'tools_used' => $loopResult->toolsUsed ?? [],
        ]);
    }

    /**
     * Handle simple chat (no tools, knowledge-only).
     */
    private function handleSimpleChat(AgentContext $context, string $systemPrompt, string $model, ModelTier $tier = ModelTier::Balanced): AgentResult
    {
        $history = $this->memory->read($context->agent->id, $context->from);
        $messages = $this->buildMessages($history, $context, $tier);

        // Small on-prem models ignore system prompts — inject context into the user message
        $isOnPrem = !str_starts_with($model, 'claude-') && !str_starts_with($model, 'gpt-');
        if ($isOnPrem && $systemPrompt) {
            $messages = "Instructions: {$systemPrompt}\n\n{$messages}";
            $systemPrompt = '';
        }

        $response = $this->claude->chat($messages, $model, $systemPrompt);

        if (!$response) {
            return AgentResult::reply("Désolé, je n'ai pas pu traiter votre message. Réessayez.");
        }

        $this->memory->append($context->agent->id, $context->from, $context->senderName, $context->body ?? '', $response);
        $this->sendText($context->from, $response);

        return AgentResult::reply($response, [
            'custom_agent_id' => $this->customAgent->id,
        ]);
    }

    /**
     * Get flat list of tool names from enabled groups.
     */
    private function getEnabledToolNames(array $enabledGroups): array
    {
        $names = [];
        foreach ($enabledGroups as $groupKey) {
            if (isset(self::TOOL_GROUPS[$groupKey])) {
                $names = array_merge($names, self::TOOL_GROUPS[$groupKey]['tools']);
            }
        }
        return array_unique($names);
    }

    /**
     * Classify a model ID into a capability tier.
     */
    private function classifyModel(string $model): ModelTier
    {
        // Cloud models
        if (str_contains($model, 'opus')) return ModelTier::Powerful;
        if (str_contains($model, 'sonnet')) return ModelTier::Balanced;
        if (str_contains($model, 'haiku')) return ModelTier::Balanced;
        if (str_contains($model, 'gpt-4o') && !str_contains($model, 'mini')) return ModelTier::Powerful;
        if (str_contains($model, 'gpt-4') && !str_contains($model, 'mini')) return ModelTier::Powerful;
        if (str_contains($model, 'gpt-3.5') || str_contains($model, 'gpt-4o-mini')) return ModelTier::Balanced;

        // On-prem — classify by parameter size extracted from model name
        $paramSize = $this->extractParamSize($model);

        if ($paramSize !== null) {
            if ($paramSize <= 3) return ModelTier::Small;
            if ($paramSize <= 16) return ModelTier::Medium;
            return ModelTier::Balanced; // 30b+ on-prem ≈ cloud balanced
        }

        // Unknown on-prem → safe default
        return ModelTier::Medium;
    }

    /**
     * Extract parameter size (in billions) from a model name like "qwen2.5:7b".
     */
    private function extractParamSize(string $model): ?float
    {
        // Match patterns like :7b, :14b, :0.5b, :1.5b, :3b, :8x7b (MoE → use single expert)
        if (preg_match('/(\d+)x(\d+(?:\.\d+)?)b/i', $model, $m)) {
            return (float) $m[2]; // MoE: use single expert size for context estimation
        }
        if (preg_match('/[\:\-](\d+(?:\.\d+)?)b/i', $model, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * Get RAG search parameters adapted to the model tier.
     */
    private function ragParamsForTier(ModelTier $tier): array
    {
        return match ($tier) {
            ModelTier::Small => [
                'limit' => 3,
                'threshold' => 0.35,
                'strategy' => 'keyword',    // embeddings are heavy, keyword is faster + small models can't exploit subtle semantic matches
                'max_chunk_chars' => 300,    // truncate long chunks to fit tiny context
            ],
            ModelTier::Medium => [
                'limit' => 3,
                'threshold' => 0.30,
                'strategy' => 'semantic',
                'max_chunk_chars' => 400,
            ],
            ModelTier::Balanced => [
                'limit' => 8,
                'threshold' => 0.20,
                'strategy' => 'hybrid',
                'max_chunk_chars' => 0,      // no truncation
            ],
            ModelTier::Powerful => [
                'limit' => 12,
                'threshold' => 0.15,
                'strategy' => 'hybrid',
                'max_chunk_chars' => 0,
            ],
        };
    }

    /**
     * Retrieve relevant knowledge chunks via RAG, adapted to the model tier.
     */
    private function retrieveKnowledge(string $query, ModelTier $tier): array
    {
        if (!$query || $this->customAgent->chunks()->count() === 0) {
            return [];
        }

        $params = $this->ragParamsForTier($tier);

        // Powerful models: expand query for better recall (only for long queries worth expanding)
        $searchQueries = [$query];
        if ($tier === ModelTier::Powerful && mb_strlen($query) > 20) {
            $expanded = $this->expandQuery($query);
            if ($expanded) {
                $searchQueries[] = $expanded;
            }
        }

        // Choose search strategy
        if ($params['strategy'] === 'hybrid') {
            $results = $this->hybridChunkSearch($searchQueries, $params['limit'], $params['threshold']);
        } else {
            // Keyword-only for small models
            $results = $this->chunker->search($this->customAgent, $query, $params['limit'], $params['threshold']);
        }

        // Truncate chunks for small context models
        if ($params['max_chunk_chars'] > 0) {
            foreach ($results as &$chunk) {
                if (mb_strlen($chunk['content']) > $params['max_chunk_chars']) {
                    $chunk['content'] = mb_substr($chunk['content'], 0, $params['max_chunk_chars']) . '…';
                }
            }
            unset($chunk);
        }

        return $results;
    }

    /**
     * Hybrid search: combine semantic + keyword results, de-duplicate, re-rank.
     * Loads all chunks once and reuses them for both semantic and keyword passes.
     */
    private function hybridChunkSearch(array $queries, int $limit, float $threshold): array
    {
        // Load chunks ONCE from DB (biggest perf win for large chunk counts)
        $allChunks = \App\Models\CustomAgentChunk::where('custom_agent_id', $this->customAgent->id)
            ->whereNotNull('embedding')
            ->with('document:id,title')
            ->get();

        $embedder = new EmbeddingService();
        $allResults = [];
        $seen = [];

        // Semantic search for each query variant
        foreach ($queries as $q) {
            $queryVector = $embedder->embed($q);
            if (!$queryVector) continue;

            foreach ($allChunks as $chunk) {
                $key = $chunk->id;
                $raw = is_resource($chunk->embedding) ? stream_get_contents($chunk->embedding) : $chunk->embedding;
                $chunkVector = json_decode($raw, true);
                if (!$chunkVector) continue;

                $similarity = EmbeddingService::cosineSimilarity($queryVector, $chunkVector);
                if ($similarity < $threshold) continue;

                if (!isset($seen[$key])) {
                    $seen[$key] = round($similarity, 4);
                    $allResults[$key] = [
                        'content' => $chunk->content,
                        'similarity' => round($similarity, 4),
                        'document_title' => $chunk->document->title ?? 'Unknown',
                        'chunk_index' => $chunk->chunk_index,
                    ];
                } else {
                    // Boost score if found by multiple queries
                    $allResults[$key]['similarity'] = min(1.0, $allResults[$key]['similarity'] + 0.1);
                }
            }
        }

        // Keyword pass on the same pre-loaded chunks (no extra DB query)
        $keywordResults = $this->keywordFallbackSearch($queries[0], $limit, $allChunks);
        foreach ($keywordResults as $r) {
            $key = $r['_chunk_id'] ?? ($r['chunk_index'] . ':' . $r['document_title']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $r['similarity'] = round($r['similarity'] * 0.4, 4);
                $allResults[$key] = $r;
            }
        }

        $results = array_values($allResults);
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Keyword search fallback (used by hybrid and small models).
     * Accepts optional pre-loaded chunks to avoid a second DB query.
     */
    private function keywordFallbackSearch(string $query, int $limit, ?\Illuminate\Support\Collection $preloaded = null): array
    {
        $keywords = preg_split('/\s+/', mb_strtolower($query));

        $chunks = $preloaded ?? \App\Models\CustomAgentChunk::where('custom_agent_id', $this->customAgent->id)
            ->with('document:id,title')
            ->get();

        $results = [];
        foreach ($chunks as $chunk) {
            $text = mb_strtolower($chunk->content);
            $score = 0;
            foreach ($keywords as $kw) {
                if (mb_strlen($kw) <= 2) continue;
                if (str_contains($text, $kw)) {
                    $score += 1.0 / max(1, count($keywords));
                }
            }
            if ($score > 0.2) {
                $results[] = [
                    '_chunk_id' => $chunk->id,
                    'content' => $chunk->content,
                    'similarity' => round($score, 4),
                    'document_title' => $chunk->document->title ?? 'Unknown',
                    'chunk_index' => $chunk->chunk_index,
                ];
            }
        }

        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Query expansion via fast LLM — generates alternative search terms.
     * Only used for Powerful tier to maximize recall.
     */
    private function expandQuery(string $query): ?string
    {
        try {
            $prompt = "Reformule cette question en une seule phrase alternative pour une recherche documentaire. Reponds UNIQUEMENT avec la reformulation, rien d'autre.\n\nQuestion: {$query}";
            $expanded = $this->claude->chat($prompt, ModelResolver::fast(), '', 100);
            return $expanded && mb_strlen($expanded) > 5 && mb_strlen($expanded) < 500 ? trim($expanded) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build system prompt with RAG knowledge injected, format adapted to model tier.
     */
    private function buildSystemPrompt(array $ragChunks, AgentContext $context, ModelTier $tier): string
    {
        $parts = [];

        $basePrompt = $this->customAgent->system_prompt;
        if ($basePrompt) {
            $parts[] = $basePrompt;
        } else {
            $parts[] = "Tu es {$this->customAgent->name}, un assistant IA spécialisé.";
            if ($this->customAgent->description) {
                $parts[] = "Ta spécialité : {$this->customAgent->description}";
            }
        }

        // Inject RAG knowledge — format adapted to tier
        if (!empty($ragChunks)) {
            $parts[] = $this->formatRagForTier($ragChunks, $tier);
        }

        // Context memory (skip for small models to save context space)
        if ($tier !== ModelTier::Small) {
            $memoryContext = $this->formatContextMemoryForPrompt($context->from, $context);
            if ($memoryContext) {
                $parts[] = $memoryContext;
            }
        }

        $parts[] = "Réponds en français sauf si l'utilisateur écrit dans une autre langue.";

        return implode("\n\n", $parts);
    }

    /**
     * Format RAG chunks based on model tier.
     *
     * Small:    minimal — numbered list, no metadata, save every token
     * Medium:   standard — source names, no scores
     * Balanced: rich — source + relevance score
     * Powerful: detailed — source, score, instructions to cross-reference and cite
     */
    private function formatRagForTier(array $ragChunks, ModelTier $tier): string
    {
        return match ($tier) {
            ModelTier::Small => $this->formatRagSmall($ragChunks),
            ModelTier::Medium => $this->formatRagMedium($ragChunks),
            ModelTier::Balanced => $this->formatRagBalanced($ragChunks),
            ModelTier::Powerful => $this->formatRagPowerful($ragChunks),
        };
    }

    private function formatRagSmall(array $chunks): string
    {
        // Ultra-compact: every token counts
        $block = "DOCS:\n";
        foreach ($chunks as $i => $chunk) {
            $block .= ($i + 1) . ". " . $chunk['content'] . "\n";
        }
        $block .= "Reponds en utilisant ces infos.";
        return $block;
    }

    private function formatRagMedium(array $chunks): string
    {
        $block = "CONNAISSANCES (documents) :\n";
        foreach ($chunks as $i => $chunk) {
            $source = $chunk['document_title'] ?? 'Doc';
            $block .= "[{$source}] " . $chunk['content'] . "\n\n";
        }
        $block .= "Utilise ces connaissances pour répondre.";
        return $block;
    }

    private function formatRagBalanced(array $chunks): string
    {
        $block = "CONNAISSANCES PERTINENTES (extraites de tes documents de formation) :\n";
        foreach ($chunks as $i => $chunk) {
            $n = $i + 1;
            $source = $chunk['document_title'] ?? 'Doc';
            $block .= "--- [{$n}] Source: {$source} (pertinence: {$chunk['similarity']}) ---\n";
            $block .= $chunk['content'] . "\n\n";
        }
        $block .= "Utilise ces connaissances pour répondre. Cite les sources si pertinent.";
        return $block;
    }

    private function formatRagPowerful(array $chunks): string
    {
        $block = "CONNAISSANCES PERTINENTES (extraites de tes documents de formation) :\n";
        $block .= "Tu disposes de " . count($chunks) . " extraits. Croise les informations entre les sources pour une réponse complète.\n\n";
        foreach ($chunks as $i => $chunk) {
            $n = $i + 1;
            $source = $chunk['document_title'] ?? 'Doc';
            $score = $chunk['similarity'];
            $block .= "━━━ [{$n}] {$source} (score: {$score}) ━━━\n";
            $block .= $chunk['content'] . "\n\n";
        }
        $block .= "INSTRUCTIONS :\n";
        $block .= "- Croise les informations entre les différentes sources pour construire une réponse complète et nuancée.\n";
        $block .= "- Si des sources se contredisent, mentionne-le.\n";
        $block .= "- Cite les sources par leur nom (ex: \"Selon [NomDoc]...\").\n";
        $block .= "- Si les documents ne couvrent pas la question, dis-le clairement plutôt que d'inventer.";
        return $block;
    }

    /**
     * Build message string from conversation history.
     * Limits history depth for on-prem models to keep prompt small.
     */
    private function buildMessages(array $history, AgentContext $context, ModelTier $tier = ModelTier::Balanced): string
    {
        $messages = '';
        $entries = $history['entries'] ?? [];
        $historyDepth = match ($tier) {
            ModelTier::Small => 1,
            ModelTier::Medium => 2,
            ModelTier::Balanced => 5,
            ModelTier::Powerful => 8,
        };
        $recent = array_slice($entries, -$historyDepth);
        foreach ($recent as $entry) {
            if (!empty($entry['sender_message'])) {
                $messages .= "Utilisateur: {$entry['sender_message']}\n";
            }
            if (!empty($entry['agent_reply'])) {
                $messages .= "Assistant: {$entry['agent_reply']}\n";
            }
        }
        $messages .= "Utilisateur: {$context->body}";
        return $messages;
    }
}
