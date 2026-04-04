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
    private string $currentStepLabel = '';
    private ?string $businessDataForInjection = null;

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
        'gitlab' => [
            'label' => 'GitLab',
            'icon' => '🦊',
            'description' => 'Acceder aux projets GitLab (code, branches, MRs, issues, pipelines)',
            'tools' => ['gitlab_api'],
        ],
    ];

    private const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const MAX_MEDIA_BYTES = 20 * 1024 * 1024; // 20 MB

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

        // 0. Check if message triggers a skill routine
        $skillResult = $this->trySkillTrigger($context);
        if ($skillResult) {
            return $skillResult;
        }

        // 0b. Check if user wants to teach the agent something
        $teachResult = $this->tryTeachMemory($context);
        if ($teachResult) {
            return $teachResult;
        }

        // 0c. Pre-fetch business data if message matches an API endpoint
        $bizResult = $this->prefetchBusinessData($context);
        $businessData = $bizResult['prompt'] ?? null;

        // 1. Resolve model — prefer custom agent's explicit model, otherwise use routing/fast default
        $model = $this->customAgent->model !== 'default'
            ? $this->customAgent->model
            : $this->resolveModel($context);

        // 2. Classify model capabilities
        $tier = $this->classifyModel($model);

        // 3. Retrieve relevant knowledge via adaptive RAG (skip for very short messages)
        $body = $context->body ?? '';
        $isShortMessage = mb_strlen(trim($body)) < 30;
        $ragContext = $isShortMessage ? [] : $this->retrieveKnowledge($body, $tier);

        // Update progress for polling
        $this->updateProgress($context, 'thinking', 'Recherche dans les documents...', '');

        // 4. Build system prompt with injected knowledge (format adapted to tier)
        $systemPrompt = $this->buildSystemPrompt($ragContext, $context, $tier);

        // 4b. Store pre-fetched business data for injection into user message later
        $this->businessDataForInjection = $businessData;

        $this->updateProgress($context, 'thinking', 'Generation de la reponse...', "Modele: {$model}");

        // 5. Check if tools are enabled — use agentic loop or simple chat
        // Small on-prem models can't handle tool_use — fall back to simple chat
        // Memory/skill tools are always available (learning is core to custom agents)
        $enabledTools = $this->customAgent->enabled_tools ?? [];
        $canUseTools = in_array($tier, [ModelTier::Medium, ModelTier::Balanced, ModelTier::Powerful]);

        // Test chat sessions bypass agentic loop (no tool execution needed)
        $isTestChat = str_starts_with($context->from, 'web-custom-test-');

        if ($canUseTools && !$isTestChat) {
            $result = $this->handleWithTools($context, $systemPrompt, $model, $enabledTools, $ragContext);
            // Fallback to simple chat if agentic loop failed
            if (!$result->reply || str_contains($result->reply, 'trop volumineux') || str_contains($result->reply, "n'ai pas pu")) {
                $this->log($context, "Agentic loop failed, falling back to simple chat");
                $result = $this->handleSimpleChat($context, $systemPrompt, $model, $tier, $ragContext);
            }
        } else {
            $result = $this->handleSimpleChat($context, $systemPrompt, $model, $tier, $ragContext);
        }

        $this->log($context, "Custom agent replied", [
            'model' => $model,
            'tier' => $tier->value,
            'rag_chunks' => count($ragContext),
        ]);

        $this->updateProgress($context, 'idle', '', '');

        // Return with model info in metadata
        return AgentResult::reply($result->reply, array_merge($result->metadata ?? [], [
            'model' => $model,
            'tier' => $tier->value,
        ]));
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
            : AgentTools::allDefinitions();

        $filteredTools = array_values(array_filter($allTools, function ($tool) use ($allowedToolNames) {
            return in_array($tool['name'] ?? '', $allowedToolNames);
        }));

        // Inject business API endpoints as tools (filtered by relevance)
        $businessTools = $this->buildBusinessApiTools($context->body ?? '');
        if (!empty($businessTools)) {
            $filteredTools = array_merge($filteredTools, $businessTools['definitions']);
            $systemPrompt .= "\n\n" . $businessTools['prompt'];
        }

        // Always include base tools (memory, skills) if memory group is enabled
        // Add RAG search as a tool
        if (!empty($ragContext)) {
            $systemPrompt .= "\n\nTu as des CONNAISSANCES provenant de tes documents. Utilise-les en priorite avant de chercher sur le web.";
        }

        $loop = new AgenticLoop(maxIterations: 15, debug: $context->session->debug_mode ?? false);

        // Build user message — inject business data if available
        $userMessage = $context->body ?? '';
        if ($this->businessDataForInjection) {
            $userMessage .= "\n\n" . $this->businessDataForInjection
                . "\n\nPresente ces donnees clairement. Montants: separateur milliers + €. Dates: format dd/mm/yyyy. Cache les champs techniques (*_id, *_at, *_path). N'invente RIEN.";
            $this->businessDataForInjection = null; // consumed
        }
        $mediaBlocks = $this->buildMediaBlocks($context);
        if ($mediaBlocks) {
            $contentBlocks = [];
            if ($userMessage) {
                $contentBlocks[] = ['type' => 'text', 'text' => $userMessage];
            }
            $userMessage = array_merge($contentBlocks, $mediaBlocks);
        }

        $loopResult = $loop->run(
            userMessage: $userMessage,
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
        if (!$this->isWebChat($context)) $this->sendText($context->from, $response);

        return AgentResult::reply($response, [
            'custom_agent_id' => $this->customAgent->id,
            'rag_chunks_used' => count($ragContext),
            'tools_used' => $loopResult->toolsUsed ?? [],
        ]);
    }

    /**
     * Handle simple chat (no tools, knowledge-only).
     */
    private function handleSimpleChat(AgentContext $context, string $systemPrompt, string $model, ModelTier $tier = ModelTier::Balanced, array $ragContext = []): AgentResult
    {
        $history = $this->memory->read($context->agent->id, $context->from);
        $messages = $this->buildMessages($history, $context, $tier);

        // Inject business data into messages if available
        if ($this->businessDataForInjection) {
            $messages = (is_string($messages) ? $messages : '') . "\n\n" . $this->businessDataForInjection
                . "\n\nPresente ces donnees clairement. Montants: separateur milliers + €. Dates: format dd/mm/yyyy. Cache les champs techniques (*_id, *_at, *_path). N'invente RIEN.";
            $this->businessDataForInjection = null;
        }

        // Build multimodal content blocks if media is present
        $mediaBlocks = $this->buildMediaBlocks($context);
        if ($mediaBlocks) {
            // Convert text history + media into content blocks array
            $contentBlocks = [];
            if ($messages) {
                $contentBlocks[] = ['type' => 'text', 'text' => $messages];
            }
            $contentBlocks = array_merge($contentBlocks, $mediaBlocks);
            $messages = $contentBlocks;
        }

        // Small on-prem models ignore system prompts — inject context into the user message
        $isOnPrem = !str_starts_with($model, 'claude-') && !str_starts_with($model, 'gpt-');
        if ($isOnPrem && $systemPrompt) {
            if (is_array($messages)) {
                array_unshift($messages, ['type' => 'text', 'text' => "Instructions: {$systemPrompt}"]);
            } else {
                $messages = "Instructions: {$systemPrompt}\n\n{$messages}";
            }
            $systemPrompt = '';
        }

        $response = $this->claude->chat($messages, $model, $systemPrompt);

        // Fallback to on-prem if cloud model failed (e.g. OAuth CLI unavailable)
        if (!$response && (str_starts_with($model, 'claude-') || str_starts_with($model, 'gpt-'))) {
            $onpremModel = 'qwen2.5:7b';
            $this->log($context, "Cloud model failed, falling back to on-prem", ['from' => $model, 'to' => $onpremModel]);
            // Build ultra-minimal prompt for on-prem (keep it very short to avoid timeout)
            $compactMsg = '';
            if (!empty($ragContext)) {
                foreach (array_slice($ragContext, 0, 2) as $c) {
                    $compactMsg .= mb_substr($c['content'], 0, 150) . "\n";
                }
                $compactMsg .= "\n";
            }
            $compactMsg .= "Question: " . ($context->body ?? '') . "\nReponds brievement:";
            $response = $this->claude->chat($compactMsg, $onpremModel, '', 200);
        }

        if (!$response) {
            return AgentResult::reply("Désolé, je n'ai pas pu traiter votre message. Réessayez.");
        }

        $this->memory->append($context->agent->id, $context->from, $context->senderName, $context->body ?? '', $response);
        if (!$this->isWebChat($context)) $this->sendText($context->from, $response);

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

        // Always include memory, skill, and persistent file tools — learning is core to custom agents
        $alwaysInclude = ['memory_store', 'memory_search', 'teach_skill', 'list_skills', 'forget_skill', 'update_instructions', 'update_session_memory'];
        $names = array_merge($names, $alwaysInclude);

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
     * Check if the user message matches a skill trigger phrase.
     * If matched, execute the routine steps sequentially and return the combined result.
     */
    /**
     * Build tool definitions for business API endpoints.
     * Each endpoint becomes a tool the LLM can call naturally.
     * Tool execution is strict PHP (no hallucination possible).
     */
    /**
     * Pre-fetch business data if message matches a configured API endpoint.
     * Uses trigger phrase matching (PHP, no LLM) to detect intent,
     * then calls the API and returns formatted data for injection into system prompt.
     */
    /**
     * @return array{prompt: string, raw_data: mixed, endpoint_name: string}|array{}
     */
    private function prefetchBusinessData(AgentContext $context): array
    {
        $body = trim($context->body ?? '');
        if (mb_strlen($body) < 5) {
            return [];
        }

        $service = new BusinessQueryService();
        $match = $service->tryMatch($this->customAgent, $body);

        if (!$match || !$match['matched']) {
            return [];
        }

        $this->log($context, "Business prefetch matched: {$match['endpoint']->name}", [
            'confidence' => $match['confidence'],
        ]);

        $this->updateProgress($context, 'thinking', 'Requete API metier...', $match['endpoint']->name);

        // Extract smart filters from the user message using LLM
        $params = $match['params'] ?? [];
        $endpointParams = $match['endpoint']->parameters ?? [];
        if (!empty($endpointParams) && empty($params)) {
            $smartParams = $this->extractSmartParams($body, $match['endpoint']);
            if ($smartParams) {
                $params = $smartParams;
                $this->log($context, "Smart params extracted", $smartParams);
            }
        }

        $result = $service->execute($match['endpoint'], $params, $body);
        $endpointName = $match['endpoint']->name;

        if (!$result['success']) {
            return [
                'prompt' => "DONNEES API METIER (erreur):\nL'endpoint \"{$endpointName}\" a retourne une erreur: " . ($result['error'] ?? 'Erreur inconnue') . "\nSignale cette erreur a l'utilisateur.",
                'raw_data' => [],
                'endpoint_name' => $endpointName,
            ];
        }

        $data = $result['raw_data'];

        // Slim down records: keep only scalar fields, drop large nested objects
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $data = array_slice($data, 0, 20); // max 20 records
            $data = array_map(function ($record) {
                if (!is_array($record)) return $record;
                $slim = [];
                foreach ($record as $key => $value) {
                    // Keep scalars and short strings, skip large nested objects/arrays
                    if (is_scalar($value) || is_null($value)) {
                        $slim[$key] = $value;
                    } elseif (is_array($value) && count($value) <= 3) {
                        $slim[$key] = $value; // keep small arrays (e.g., tags)
                    }
                    // Drop: billing_address, shipping_address, dunning_history, etc.
                }
                return $slim;
            }, $data);
        }

        $dataJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (mb_strlen($dataJson) > 6000) {
            $dataJson = mb_substr($dataJson, 0, 6000) . "\n... (donnees tronquees)";
        }

        $recordCount = is_array($data) && isset($data[0]) ? count($data) : 1;

        $prompt = <<<DATA
DONNEES API METIER — REPONSE DE L'ENDPOINT "{$endpointName}" ({$recordCount} resultats):
Les donnees ci-dessous sont REELLES, provenant de l'API de l'utilisateur. Presente-les de facon claire et lisible.
N'INVENTE RIEN. N'ajoute AUCUNE donnee fictive. Si la liste est vide, dis "Aucun resultat".

{$dataJson}
DATA;

        return [
            'prompt' => $prompt,
            'raw_data' => $data,
            'endpoint_name' => $endpointName,
        ];
    }

    /**
     * Format business API data as readable text when LLM is unavailable.
     */
    private function formatBusinessDataFallback(mixed $data, string $endpointName, string $userMessage): string
    {
        if (!is_array($data) || empty($data)) {
            return "Aucun resultat pour \"{$endpointName}\".";
        }

        // List of records
        if (isset($data[0]) && is_array($data[0])) {
            $count = count($data);
            $lines = ["📊 **{$endpointName}** — {$count} resultat(s)\n"];

            foreach (array_slice($data, 0, 10) as $i => $record) {
                $parts = [];
                foreach ($record as $key => $val) {
                    if ($this->isFieldHidden($key, $val)) continue;
                    $parts[] = $this->formatFieldLabel($key) . ': ' . $this->formatFieldValue($key, $val);
                }
                $lines[] = ($i + 1) . ". " . implode(' · ', array_slice($parts, 0, 6));
            }

            if ($count > 10) {
                $lines[] = "\n_... et " . ($count - 10) . " autres resultats_";
            }

            return implode("\n", $lines);
        }

        // Single record
        $lines = ["📊 **{$endpointName}**\n"];
        foreach ($data as $key => $val) {
            if ($this->isFieldHidden($key, $val)) continue;
            $lines[] = "- **" . $this->formatFieldLabel($key) . "**: " . $this->formatFieldValue($key, $val);
        }
        return implode("\n", $lines);
    }

    /**
     * Extract API filter parameters from a natural language message using PHP heuristics.
     * No LLM needed — pattern matching + synonym expansion.
     *
     * Examples:
     * "factures de prounity" → {search: "prounity"}
     * "factures payées" → {status: "paid"}
     * "5 dernières factures" → {per_page: 5, sort_order: "desc"}
     * "factures de mars 2026" → {date_from: "2026-03-01", date_to: "2026-03-31"}
     */
    private function extractSmartParams(string $message, \App\Models\CustomAgentEndpoint $endpoint): ?array
    {
        $params = $endpoint->parameters ?? [];
        if (empty($params)) return null;

        $msg = mb_strtolower(trim($message));
        $extracted = [];
        $paramsByName = collect($params)->keyBy('name');

        // 1. Numeric extraction: "5 derniers" → per_page=5
        if (preg_match('/\b(\d{1,3})\s*(derniers?|dernieres?|premiers?|premieres?|top|last|first|recent)/iu', $msg, $m)) {
            if ($paramsByName->has('per_page')) $extracted['per_page'] = (int) $m[1];
            if ($paramsByName->has('limit')) $extracted['limit'] = (int) $m[1];
        }

        // 2. Sort: "derniers/récents" → sort_order=desc
        if (preg_match('/\b(derniers?|dernieres?|recents?|recentes?|last|recent|newest)/iu', $msg)) {
            if ($paramsByName->has('sort_order')) $extracted['sort_order'] = 'desc';
            if ($paramsByName->has('sort_by') && !isset($extracted['sort_by'])) {
                // Default sort by date if available
                foreach (['created_at', 'date', 'issue_date', 'updated_at'] as $dateField) {
                    if ($paramsByName->has($dateField) || str_contains(json_encode($params), $dateField)) {
                        $extracted['sort_by'] = $dateField;
                        break;
                    }
                }
            }
        }

        // 3. Enum matching: "payées" → status=paid, "brouillon" → status=draft
        // Normalize accents for matching
        $msgNorm = $this->stripAccents($msg);
        $statusMap = [
            'paye' => 'paid', 'payee' => 'paid', 'payees' => 'paid', 'payes' => 'paid', 'paid' => 'paid',
            'impaye' => 'overdue', 'impayes' => 'overdue', 'impayees' => 'overdue', 'en retard' => 'overdue', 'overdue' => 'overdue',
            'brouillon' => 'draft', 'draft' => 'draft',
            'envoye' => 'sent', 'envoyee' => 'sent', 'envoyees' => 'sent', 'sent' => 'sent',
            'annule' => 'cancelled', 'annulee' => 'cancelled', 'annulees' => 'cancelled', 'cancelled' => 'cancelled',
            'en attente' => 'pending', 'pending' => 'pending',
            'actif' => 'active', 'active' => 'active',
            'inactif' => 'inactive', 'inactive' => 'inactive',
            'termine' => 'completed', 'completed' => 'completed',
        ];
        $matchedStatuses = [];
        foreach ($params as $p) {
            if (($p['type'] ?? '') === 'enum' && !empty($p['values'])) {
                foreach ($statusMap as $fr => $en) {
                    if (str_contains($msgNorm, $fr) && in_array($en, $p['values'])) {
                        $extracted[$p['name']] = $en;
                        $matchedStatuses[] = $fr;
                        break;
                    }
                }
            }
        }

        // 4. Date extraction: "de mars", "mars 2026", "en janvier"
        $months = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5,
            'juin' => 6, 'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10,
            'novembre' => 11, 'decembre' => 12,
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5,
            'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10,
            'november' => 11, 'december' => 12];
        foreach ($months as $name => $num) {
            if (str_contains($msg, $name)) {
                $year = date('Y');
                if (preg_match('/\b(20\d{2})\b/', $msg, $ym)) $year = $ym[1];
                $dateFrom = sprintf('%s-%02d-01', $year, $num);
                $dateTo = date('Y-m-t', strtotime($dateFrom));
                if ($paramsByName->has('date_from')) $extracted['date_from'] = $dateFrom;
                if ($paramsByName->has('date_to')) $extracted['date_to'] = $dateTo;
                if ($paramsByName->has('month')) $extracted['month'] = $num;
                break;
            }
        }

        // 5. Search term: extract words that aren't fillers or known keywords
        $fillers = ['liste', 'lister', 'moi', 'mes', 'les', 'des', 'du', 'de', 'la', 'le', 'donne',
            'montre', 'affiche', 'facture', 'factures', 'invoice', 'invoices', 'client', 'clients',
            'produit', 'produits', 'contact', 'contacts', 'commande', 'commandes', 'vente', 'achat',
            'derniers', 'dernieres', 'derniere', 'dernier', 'premiers', 'premier', 'premiere',
            'toutes', 'tous', 'tout', 'une', 'un', 'que', 'qui', 'quoi'];
        // Also add status words and matched status terms as fillers
        $fillers = array_merge($fillers, array_keys($statusMap), $matchedStatuses);
        // Also add month names
        $fillers = array_merge($fillers, array_keys($months));

        $words = preg_split('/[\s,;.!?\']+/', $msg, -1, PREG_SPLIT_NO_EMPTY);
        $searchTerms = array_filter($words, fn($w) => mb_strlen($w) >= 3 && !in_array($w, $fillers) && !is_numeric($w));

        if (!empty($searchTerms) && $paramsByName->has('search')) {
            $extracted['search'] = implode(' ', $searchTerms);
        }

        return !empty($extracted) ? $extracted : null;
    }

    private function stripAccents(string $str): string
    {
        return strtr($str, [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'ý'=>'y','ÿ'=>'y','ñ'=>'n','ç'=>'c',
        ]);
    }

    /** Auto-detect if a field should be hidden (generic, no hardcoded list). */
    private function isFieldHidden(string $key, mixed $val): bool
    {
        // Non-scalar values (objects, arrays)
        if (!is_scalar($val) || $val === null || $val === '') return true;
        // Internal IDs (except the main record id)
        if ($key !== 'id' && str_ends_with($key, '_id')) return true;
        // Timestamps (created_at, updated_at, deleted_at, verified_at, etc.)
        if (preg_match('/^(created|updated|deleted|verified|generated|signed)_at$/', $key)) return true;
        // File paths
        if (preg_match('/path$|hash$|signature$/', $key)) return true;
        // Boolean flags starting with is_ or has_
        if (preg_match('/^(is|has)_/', $key) && is_bool($val)) return true;
        // Long base64/hash values
        if (is_string($val) && mb_strlen($val) > 200) return true;

        return false;
    }

    private function formatFieldValue(string $key, mixed $val): string
    {
        if (!is_scalar($val)) return (string) $val;

        // Money: fields containing total, amount, price, cost, tax, subtotal, balance
        if (is_numeric($val) && (float) $val != 0 && preg_match('/total|amount|subtotal|tax|price|cost|balance/i', $key)) {
            return number_format((float) $val, 2, ',', ' ') . ' €';
        }

        // Dates: ISO format → dd/mm/yyyy
        if (preg_match('/date|_at$/i', $key) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $val)) {
            try { return \Carbon\Carbon::parse($val)->format('d/m/Y'); } catch (\Throwable) {}
        }

        // Status keywords → French
        if ($key === 'status') {
            $map = ['draft' => 'Brouillon', 'sent' => 'Envoyee', 'paid' => 'Payee',
                'overdue' => 'En retard', 'cancelled' => 'Annulee', 'pending' => 'En attente',
                'active' => 'Actif', 'inactive' => 'Inactif', 'completed' => 'Termine',
                'failed' => 'Echoue', 'processing' => 'En cours'];
            return $map[(string) $val] ?? (string) $val;
        }

        return (string) $val;
    }

    private function formatFieldLabel(string $key): string
    {
        // Convert snake_case to readable, with common translations
        return ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Build tool definitions for business API endpoints.
     * Pre-filters by relevance to avoid injecting too many tools.
     */
    private function buildBusinessApiTools(?string $userMessage = null): array
    {
        $allEndpoints = $this->customAgent->endpoints()->where('is_active', true)->get();
        if ($allEndpoints->isEmpty()) {
            return [];
        }

        // Pre-filter: score endpoints by relevance to the user message
        $endpoints = $this->filterRelevantEndpoints($allEndpoints, $userMessage, 20);
        if ($endpoints->isEmpty()) {
            return [];
        }

        $definitions = [];
        foreach ($endpoints as $ep) {
            $toolName = 'biz_' . $ep->id;
            $params = $ep->parameters ?? [];

            // Build JSON schema properties from declared parameters
            $properties = [];
            $required = [];
            foreach ($params as $p) {
                $prop = ['type' => match ($p['type'] ?? 'string') {
                    'int', 'integer' => 'integer',
                    'float', 'number' => 'number',
                    'bool', 'boolean' => 'boolean',
                    default => 'string',
                }];
                if (($p['type'] ?? '') === 'enum' && !empty($p['values'])) {
                    $prop['enum'] = $p['values'];
                }
                $prop['description'] = $p['name'];
                $properties[$p['name']] = $prop;
                if (!empty($p['required'])) {
                    $required[] = $p['name'];
                }
            }

            $triggers = implode(', ', $ep->trigger_phrases ?? []);
            $definitions[] = [
                'name' => $toolName,
                'description' => "{$ep->name} [{$ep->method}] — {$ep->description}. Phrases: {$triggers}",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => $properties ?: (object) [],
                    'required' => $required,
                ],
            ];
        }

        // Build system prompt addition — strong instruction to use business tools
        $epList = collect($definitions)->map(fn($d) => "- {$d['name']}: {$d['description']}")->implode("\n");
        $prompt = <<<PROMPT
REGLE PRIORITAIRE — ENDPOINTS API METIER:
Tu as acces a des outils biz_* qui interrogent les APIs d'entreprise de l'utilisateur.

QUAND l'utilisateur demande des donnees (factures, clients, produits, contacts, commandes, devis, rapports, etc.):
→ Tu DOIS utiliser le tool biz_* correspondant. NE REPONDS JAMAIS "je n'ai pas acces" pour ce type de demande.
→ Les donnees retournees sont REELLES. Presente-les telles quelles, N'INVENTE RIEN.
→ Si un outil echoue (erreur API, 401, etc.), dis "L'API a retourne une erreur: [detail]".

OUTILS DISPONIBLES:
{$epList}
PROMPT;

        return [
            'definitions' => $definitions,
            'prompt' => $prompt,
        ];
    }

    /**
     * Execute a business API tool call (invoked by the agentic loop).
     */
    /**
     * Pre-filter endpoints by relevance to the user message.
     * Uses word overlap + synonym matching to keep only the most relevant.
     */
    private function filterRelevantEndpoints(\Illuminate\Database\Eloquent\Collection $endpoints, ?string $message, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        if (!$message || mb_strlen(trim($message)) < 3) {
            // No message context — return top endpoints by ID (most common first)
            return $endpoints->take($limit);
        }

        $normalized = mb_strtolower(trim($message));

        // Synonym expansion for matching
        $synonyms = [
            'facture' => ['invoice', 'invoices', 'factures', 'bill', 'bills'],
            'client' => ['customer', 'customers', 'clients', 'contact', 'contacts'],
            'produit' => ['product', 'products', 'produits', 'item', 'items'],
            'commande' => ['order', 'orders', 'commandes'],
            'paiement' => ['payment', 'payments', 'paiements'],
            'devis' => ['quote', 'quotes', 'estimate', 'estimates'],
            'vente' => ['sale', 'sales', 'ventes', 'invoice', 'invoices'],
            'achat' => ['purchase', 'purchases', 'achats'],
            'fournisseur' => ['supplier', 'suppliers', 'vendor', 'vendors', 'fournisseurs'],
            'categorie' => ['category', 'categories'],
            'utilisateur' => ['user', 'users', 'utilisateurs'],
            'rapport' => ['report', 'reports', 'dashboard', 'stats', 'statistics'],
            'depense' => ['expense', 'expenses', 'depenses'],
            'compte' => ['account', 'accounts', 'comptes'],
            'taxe' => ['tax', 'taxes', 'tva', 'vat'],
        ];

        // Extract message words and expand with synonyms
        $words = preg_split('/[\s,;.!?\']+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $expandedWords = $words;
        foreach ($words as $word) {
            foreach ($synonyms as $key => $syns) {
                if ($word === $key || in_array($word, $syns)) {
                    $expandedWords = array_merge($expandedWords, [$key], $syns);
                }
            }
        }
        $expandedWords = array_unique($expandedWords);

        // Score each endpoint
        $scored = $endpoints->map(function ($ep) use ($expandedWords, $normalized) {
            $score = 0;
            $searchText = mb_strtolower(
                $ep->name . ' ' . ($ep->description ?? '') . ' '
                . implode(' ', $ep->trigger_phrases ?? []) . ' ' . $ep->url
            );

            foreach ($expandedWords as $word) {
                if (mb_strlen($word) >= 3 && str_contains($searchText, $word)) {
                    $score += mb_strlen($word); // Longer matches = higher score
                }
            }

            // Boost GET endpoints (most likely for "liste moi...")
            if ($ep->method === 'GET' && $score > 0) {
                $score += 3;
            }

            return ['endpoint' => $ep, 'score' => $score];
        })
        ->filter(fn($item) => $item['score'] > 0)
        ->sortByDesc('score')
        ->take($limit);

        if ($scored->isEmpty()) {
            // No relevant match — return a small default set
            return $endpoints->take(5);
        }

        return new \Illuminate\Database\Eloquent\Collection(
            $scored->pluck('endpoint')->values()->all()
        );
    }

    public function executeBusinessTool(string $toolName, array $params): string
    {
        $endpointId = (int) str_replace('biz_', '', $toolName);
        $endpoint = $this->customAgent->endpoints()->find($endpointId);

        if (!$endpoint) {
            return json_encode(['error' => true, 'message' => "Endpoint {$toolName} introuvable."]);
        }

        $service = new BusinessQueryService();
        $result = $service->execute($endpoint, $params, '');

        if (!$result['success']) {
            return json_encode([
                'error' => true,
                'message' => $result['error'] ?? 'Erreur API',
            ]);
        }

        // Return raw data — let the LLM format it naturally in its response
        return json_encode([
            'success' => true,
            'data' => $result['raw_data'],
            'endpoint' => $endpoint->name,
            'records_count' => is_array($result['raw_data']) && isset($result['raw_data'][0])
                ? count($result['raw_data']) : 1,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function trySkillTrigger(AgentContext $context): ?AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));
        $hasContent = $body !== '' || $context->hasMedia;

        if (!$hasContent) return null;

        $activeSkills = $this->customAgent->skills()->where('is_active', true)->get();

        // Allow user to cancel any active routine
        if ($body && in_array($body, ['stop', 'annuler', 'cancel', 'quitter', 'sortir', 'exit'])) {
            foreach ($activeSkills as $skill) {
                $cacheKey = "skill_step:{$context->from}:{$skill->id}";
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
            }
            return null; // Fall through to normal chat
        }

        // Check if message matches a NEW trigger phrase (takes priority over active routines)
        // Also supports event-based triggers: photo_received, image_received, document_received, pdf_received
        $matchedSkill = null;
        $mimetype = $context->mimetype ?? '';
        $isImage = $context->hasMedia && in_array($mimetype, self::SUPPORTED_IMAGE_TYPES);
        $isPdf = $context->hasMedia && $mimetype === 'application/pdf';

        foreach ($activeSkills as $skill) {
            $trigger = mb_strtolower(trim($skill->trigger_phrase ?? ''));
            $trigger = trim($trigger, "\"'");
            if (!$trigger) continue;

            // Event-based triggers for media
            $eventTriggers = ['photo_received', 'image_received', 'photo_reçue', 'image_reçue'];
            $docTriggers = ['document_received', 'pdf_received', 'document_reçu', 'pdf_reçu'];

            if ($isImage && in_array($trigger, $eventTriggers)) {
                $matchedSkill = $skill;
                break;
            }
            if ($isPdf && in_array($trigger, $docTriggers)) {
                $matchedSkill = $skill;
                break;
            }
            // Also match on media if trigger contains "photo" or "image" and media is present
            if ($isImage && (str_contains($trigger, 'photo') || str_contains($trigger, 'image'))) {
                $matchedSkill = $skill;
                break;
            }
            if ($isPdf && (str_contains($trigger, 'document') || str_contains($trigger, 'pdf'))) {
                $matchedSkill = $skill;
                break;
            }

            // Standard text matching
            if ($body && str_contains($body, $trigger)) {
                $matchedSkill = $skill;
                break;
            }
        }

        if ($matchedSkill) {
            // Cancel any other active routines
            foreach ($activeSkills as $skill) {
                if ($skill->id !== $matchedSkill->id) {
                    \Illuminate\Support\Facades\Cache::forget("skill_step:{$context->from}:{$skill->id}");
                }
            }
            // Reset step counter for fresh trigger
            \Illuminate\Support\Facades\Cache::put("skill_step:{$context->from}:{$matchedSkill->id}", 0, 3600);
            $this->log($context, "Skill triggered: {$matchedSkill->name}");
            return $this->executeSkillRoutine($matchedSkill, $context);
        }

        // No new trigger — check if there's an active routine in progress
        // (also continues when user sends an image without text as reply to a step)
        foreach ($activeSkills as $skill) {
            $cacheKey = "skill_step:{$context->from}:{$skill->id}";
            $currentStep = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($currentStep !== null && $currentStep < count($skill->routine ?? [])) {
                $this->log($context, "Continuing skill routine: {$skill->name} step " . ($currentStep + 1));
                $result = $this->executeSkillRoutine($skill, $context);
                if ($result) return $result;
            }
        }

        return null;
    }

    /**
     * Execute a skill's routine — only the CURRENT step, not all at once.
     * Tracks progress via session memory so each user reply advances to the next step.
     */
    private function executeSkillRoutine(\App\Models\CustomAgentSkill $skill, AgentContext $context): AgentResult
    {
        $routine = $skill->routine ?? [];
        if (empty($routine)) {
            return AgentResult::reply("Skill \"{$skill->name}\" n'a pas de routine configurée.");
        }

        $model = $this->customAgent->model !== 'default'
            ? $this->customAgent->model
            : $this->resolveModel($context);

        // Track which step we're on via cache
        $cacheKey = "skill_step:{$context->from}:{$skill->id}";
        $currentStep = (int) \Illuminate\Support\Facades\Cache::get($cacheKey, 0);

        // Past all steps → reset, fall through to normal chat
        if ($currentStep >= count($routine)) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            return null;
        }

        $step = $routine[$currentStep];
        $stepType = $step['type'] ?? 'prompt';
        $stepContent = $step['content'] ?? '';

        $this->currentStepLabel = "Routine \"{$skill->name}\" — etape " . ($currentStep + 1) . "/" . count($routine);
        $this->updateProgress($context, 'skill', $this->currentStepLabel, "Preparation...");

        // Build system prompt for this step
        $tier = $this->classifyModel($model);
        $systemPrompt = $this->buildSkillStepPrompt($skill, $currentStep, $routine, $context, $tier);

        // Determine execution mode based on step type
        $enabledTools = $this->customAgent->enabled_tools ?? [];
        $hasTools = !empty($enabledTools);
        $canUseTools = in_array($tier, [ModelTier::Balanced, ModelTier::Powerful]);
        // Only use agentic loop for explicit action steps — NOT for regular prompts
        $needsAgenticLoop = in_array($stepType, ['action', 'api_call']);

        $reply = '';

        if ($needsAgenticLoop && $hasTools && $canUseTools) {
            // ── Agentic loop: execute real tools (API calls, web search, code) ──
            $reply = $this->executeStepWithTools($context, $systemPrompt, $model, $enabledTools, $stepContent);
        } elseif ($stepType === 'script') {
            // ── Script execution ──
            $reply = $this->executeStepScript($step, $context, $model);
        } else {
            // ── Simple chat (prompt only) — use CLI with system-prompt-file for long prompts ──
            $userText = "Instruction: {$stepContent}\n\nMessage utilisateur: " . ($context->body ?? '') . "\n\nReponds UNIQUEMENT pour cette etape. Sois naturel et conversationnel.";

            // Include media (image/PDF) if present
            $mediaBlocks = $this->buildMediaBlocks($context);
            if ($mediaBlocks) {
                $userMsg = array_merge(
                    [['type' => 'text', 'text' => $userText]],
                    $mediaBlocks,
                );
                \Illuminate\Support\Facades\Log::info("Skill step: simple chat with media", ['type' => $stepType, 'step' => $currentStep + 1, 'media_blocks' => count($mediaBlocks)]);
                $this->updateProgress($context, 'skill', $this->currentStepLabel ?? '', "Analyse du media...");
                $reply = $this->claude->chat($userMsg, $model, $systemPrompt);
            } else {
                $userMsg = $userText;
                \Illuminate\Support\Facades\Log::info("Skill step: simple chat", ['type' => $stepType, 'step' => $currentStep + 1, 'userMsg_len' => mb_strlen($userMsg), 'sysPrompt_len' => mb_strlen($systemPrompt)]);
                $this->updateProgress($context, 'skill', $this->currentStepLabel ?? '', "Generation de la reponse...");
                // Use local chatViaCli with --max-turns 1 (no tools) for prompt steps
                // This avoids the CLI using Bash in loops and hitting max_turns
                \Illuminate\Support\Facades\Log::info("Skill step: calling LOCAL chatViaCli", ['msg_len' => mb_strlen($userMsg)]);
                $reply = $this->chatViaCli($userMsg, $systemPrompt, $model);
            }
            \Illuminate\Support\Facades\Log::info("Skill step: reply returned", ['reply_len' => mb_strlen($reply ?? ''), 'preview' => mb_substr($reply ?? '', 0, 100)]);
        }

        if (!$reply) {
            $reply = "❌ Erreur : le modèle ({$model}) n'a pas répondu pour l'étape " . ($currentStep + 1) . "/" . count($routine) . " de la routine \"{$skill->name}\".\n\nInstruction : {$stepContent}\n\nTapez **stop** pour quitter ou réessayez.";
            \Illuminate\Support\Facades\Log::warning("Skill step failed", [
                'skill' => $skill->name, 'step' => $currentStep + 1, 'model' => $model,
            ]);
            // Don't advance — let user retry
            \Illuminate\Support\Facades\Cache::put($cacheKey, $currentStep, 3600);
        } else {
            // Advance to next step
            \Illuminate\Support\Facades\Cache::put($cacheKey, $currentStep + 1, 3600);
        }

        // Prefix with skill name on first step
        if ($currentStep === 0) {
            $reply = "⚡ **{$skill->name}**\n\n{$reply}";
        }

        $this->memory->append($context->agent->id, $context->from, $context->senderName, $context->body ?? '', $reply);
        if (!$this->isWebChat($context)) $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, [
            'custom_agent_id' => $this->customAgent->id,
            'model' => $model,
            'skill_triggered' => $skill->name,
            'step' => $currentStep + 1,
            'total_steps' => count($routine),
        ]);
    }

    /**
     * Build system prompt for a skill step, with RAG + history + credentials.
     */
    private function buildSkillStepPrompt(\App\Models\CustomAgentSkill $skill, int $step, array $routine, AgentContext $context, ModelTier $tier): string
    {
        $stepContent = $routine[$step]['content'] ?? '';
        $parts = [];

        // Base agent prompt
        if ($this->customAgent->system_prompt) {
            $parts[] = $this->customAgent->system_prompt;
        }

        // Skill context
        $parts[] = "Tu executes la routine \"{$skill->name}\" (etape " . ($step + 1) . "/" . count($routine) . ").";
        if ($skill->description) {
            $parts[] = "Objectif de la routine: {$skill->description}";
        }

        // Inject previous steps summary so LLM has context of what happened
        if ($step > 0) {
            $prevSteps = "ETAPES PRECEDENTES COMPLETEES:\n";
            for ($i = 0; $i < $step; $i++) {
                $prevSteps .= "- Etape " . ($i + 1) . ": " . ($routine[$i]['content'] ?? '') . " ✅\n";
            }
            $parts[] = $prevSteps;

            // Include recent conversation history for context
            $history = $this->memory->read($context->agent->id, $context->from);
            $entries = $history['entries'] ?? [];
            $recentEntries = array_slice($entries, -($step * 2)); // Last exchanges from skill
            if (!empty($recentEntries)) {
                $histBlock = "HISTORIQUE RECENT DE LA CONVERSATION:\n";
                foreach ($recentEntries as $entry) {
                    if (!empty($entry['sender_message'])) {
                        $histBlock .= "Utilisateur: " . mb_substr($entry['sender_message'], 0, 200) . "\n";
                    }
                    if (!empty($entry['agent_reply'])) {
                        $histBlock .= "Assistant: " . mb_substr($entry['agent_reply'], 0, 300) . "\n";
                    }
                }
                $parts[] = $histBlock;
            }
        }

        $parts[] = "INSTRUCTION POUR CETTE ETAPE:\n{$stepContent}";
        $parts[] = "IMPORTANT: Execute UNIQUEMENT l'instruction de cette etape. Ne propose pas d'alternatives, execute directement.";

        // RAG knowledge
        $ragContext = $this->retrieveKnowledge($context->body ?? $skill->name, $tier);
        if (!empty($ragContext)) {
            $ragBlock = "CONNAISSANCES (documents):\n";
            foreach (array_slice($ragContext, 0, 3) as $c) {
                $ragBlock .= "[{$c['document_title']}] " . mb_substr($c['content'], 0, 400) . "\n\n";
            }
            $parts[] = $ragBlock;
        }

        // Credentials — inject values directly so the LLM can use them in API calls
        $creds = $this->customAgent->credentials()->where('is_active', true)->get();
        if ($creds->isNotEmpty()) {
            $credBlock = "CREDENTIALS DISPONIBLES (utilise ces valeurs directement dans tes appels API):\n";
            foreach ($creds as $cred) {
                $credBlock .= "- {$cred->key}";
                if ($cred->description) $credBlock .= " ({$cred->description})";
                $credBlock .= ": {$cred->decrypted_value}\n";
            }
            $parts[] = $credBlock;
        }

        // Note: workspace path is NOT included in skill step prompts to avoid
        // the CLI attempting file system access with --allowedTools ""

        $parts[] = "Reponds en texte uniquement. Decris ce que tu FERAIS a cette etape (creation PDF, OCR, appel API, etc.) de maniere conversationnelle, comme si tu l'avais fait. Ne demande pas de fichiers ou d'acces — simule l'execution et donne un resultat concret.";

        return implode("\n\n", $parts);
    }

    /**
     * Execute a skill step using the agentic loop (with tools).
     */
    private function executeStepWithTools(AgentContext $context, string $systemPrompt, string $model, array $enabledGroups, string $stepInstruction): string
    {
        $apiKey = \App\Models\AppSetting::get('anthropic_api_key');
        if (!$apiKey) return '';

        $slug = match (true) {
            str_contains($model, 'opus') => 'opus',
            str_contains($model, 'haiku') => 'haiku',
            default => 'sonnet',
        };

        // Inject credentials values directly into the instruction for the LLM
        $enrichedInstruction = $stepInstruction;
        $creds = $this->customAgent->credentials()->where('is_active', true)->get();
        foreach ($creds as $cred) {
            if (str_contains($stepInstruction, $cred->key)) {
                $enrichedInstruction = str_replace(
                    "credential {$cred->key}",
                    "{$cred->key}: {$cred->decrypted_value}",
                    $enrichedInstruction
                );
                $enrichedInstruction = str_replace(
                    $cred->key,
                    "{$cred->key} ({$cred->decrypted_value})",
                    $enrichedInstruction
                );
            }
        }

        $userMessage = "EXECUTE CETTE INSTRUCTION:\n{$enrichedInstruction}\n\nContexte utilisateur: " . ($context->body ?? '');

        // Write system prompt to temp file
        $tmpFile = tempnam('/tmp', 'zc_sys_');
        file_put_contents($tmpFile, $systemPrompt);

        $cmd = sprintf(
            'claude -p %s --system-prompt-file %s --model %s --output-format json --max-turns 8 --allowedTools "Bash,WebSearch,WebFetch" 2>/dev/null',
            escapeshellarg($userMessage),
            escapeshellarg($tmpFile),
            escapeshellarg($slug)
        );

        $this->updateProgress($context, 'skill', $this->currentStepLabel ?? 'Execution...', "Connexion au modele {$slug}...");
        $this->startProgressTimer($context, $this->currentStepLabel ?? 'Execution...');

        // Execute via shell_exec (simpler, works from FPM context)
        $env = "CLAUDE_CODE_OAUTH_TOKEN=" . escapeshellarg($apiKey) . " HOME=/tmp";
        $fullCmd = "{$env} {$cmd}";
        $output = shell_exec($fullCmd);

        @unlink($tmpFile);
        $this->updateProgress($context, 'skill', $this->currentStepLabel ?? '', "Reponse recue...");

        if ($output) {
            $data = json_decode($output, true);
            $reply = $data['result'] ?? '';
            if ($reply) return $reply;
        }

        // Fallback: simple chat via LLMClient
        $this->updateProgress($context, 'skill', $this->currentStepLabel ?? '', "Fallback...");
        return $this->claude->chat($userMessage, $model, $systemPrompt, 800) ?: '';
    }

    /**
     * Execute a script step (find and run the named script).
     */
    private function executeStepScript(array $step, AgentContext $context, string $model): string
    {
        $scriptName = $step['script'] ?? $step['content'] ?? '';
        $script = $this->customAgent->scripts()
            ->where('name', $scriptName)
            ->where('is_active', true)
            ->first();

        if (!$script) {
            return "❌ Script \"{$scriptName}\" non trouvé ou inactif.";
        }

        // Execute via run_code tool if available, otherwise just reference it
        $enabledTools = $this->customAgent->enabled_tools ?? [];
        if (in_array('code', $enabledTools)) {
            $prompt = "Execute ce script {$script->language}:\n```{$script->language}\n{$script->code}\n```\nUtilise l'outil run_code pour l'executer. Retourne le resultat.";
            return $this->claude->chat($prompt, $model, '', 800) ?: "Script \"{$script->name}\" référencé mais exécution non disponible.";
        }

        return "📋 Script \"{$script->name}\" ({$script->language}):\n```{$script->language}\n{$script->code}\n```\n\n_Pour l'exécuter automatiquement, activez le groupe d'outils \"Execution de code\" sur cet agent._";
    }

    /**
     * Detect if user is teaching the agent something and store it.
     */
    private function tryTeachMemory(AgentContext $context): ?AgentResult
    {
        $body = trim($context->body ?? '');
        if (!$body || mb_strlen($body) < 10) return null;

        $lower = mb_strtolower($body);

        // Detect teaching patterns
        $teachPatterns = [
            'retiens que ', 'retiens: ', 'retiens : ',
            'apprends que ', 'apprends: ', 'apprends : ',
            'souviens-toi que ', 'souviens toi que ',
            'n\'oublie pas que ', 'noublie pas que ', 'n oublie pas que ',
            'rappelle-toi que ', 'rappelle toi que ',
            'memorise: ', 'memorise : ', 'memorise que ',
            'remember that ', 'remember: ',
            'note que ', 'note: ', 'note : ',
            'sache que ', 'sache: ',
            'important: ', 'important : ',
        ];

        $matchedPattern = null;
        foreach ($teachPatterns as $pattern) {
            if (str_starts_with($lower, $pattern)) {
                $matchedPattern = $pattern;
                break;
            }
        }

        if (!$matchedPattern) return null;

        // Extract the content to remember
        $content = trim(mb_substr($body, mb_strlen($matchedPattern)));
        if (mb_strlen($content) < 5) return null;

        // Categorize
        $category = 'general';
        if (preg_match('/\b(regle|regl|policy|procedure|process)\b/i', $content)) $category = 'instruction';
        if (preg_match('/\b(prefere|preference|aime|naime pas|evite)\b/i', $content)) $category = 'preference';
        if (preg_match('/\b(est|sont|fait|a|possede|mesure|pese|habite|travaille)\b/i', $content)) $category = 'fact';

        // Store
        \App\Models\CustomAgentMemory::create([
            'custom_agent_id' => $this->customAgent->id,
            'category' => $category,
            'content' => $content,
            'source' => str_starts_with($context->from, 'partner-') ? 'partner' : 'chat',
        ]);

        $reply = "✅ C'est noté ! J'ai mémorisé : \"{$content}\"\n\n_Je m'en souviendrai dans toutes nos futures conversations._";

        $this->memory->append($context->agent->id, $context->from, $context->senderName, $body, $reply);
        if (!$this->isWebChat($context)) $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, [
            'custom_agent_id' => $this->customAgent->id,
            'memory_stored' => $category,
        ]);
    }

    /**
     * Build context from learned memories for the system prompt.
     */
    private function buildMemoriesContext(): string
    {
        $memories = $this->customAgent->memories()->orderBy('category')->orderByDesc('created_at')->limit(50)->get();

        if ($memories->isEmpty()) return '';

        $grouped = $memories->groupBy('category');
        $parts = ["MEMOIRE DE L'AGENT (informations apprises) :"];

        $labels = [
            'fact' => 'Faits',
            'instruction' => 'Instructions/Regles',
            'preference' => 'Preferences',
            'general' => 'Notes generales',
        ];

        foreach ($grouped as $cat => $items) {
            $label = $labels[$cat] ?? ucfirst($cat);
            $parts[] = "\n{$label}:";
            foreach ($items as $m) {
                $parts[] = "- {$m->content}";
            }
        }

        $parts[] = "\nUtilise ces informations pour personnaliser tes reponses.";

        return implode("\n", $parts);
    }

    /**
     * Chat via Claude CLI with system-prompt-file (handles long prompts).
     */
    private function chatViaCli(string $userMessage, string $systemPrompt, string $model): string
    {
        $apiKey = \App\Models\AppSetting::get('anthropic_api_key');
        if (!$apiKey) {
            \Illuminate\Support\Facades\Log::error("chatViaCli: no API key");
            return '';
        }

        $slug = match (true) {
            str_contains($model, 'opus') => 'opus',
            str_contains($model, 'haiku') => 'haiku',
            default => 'sonnet',
        };

        $tmpFile = tempnam('/tmp', 'zc_sys_');
        file_put_contents($tmpFile, $systemPrompt);

        $cmd = sprintf(
            'sudo CLAUDE_CODE_OAUTH_TOKEN=%s HOME=/tmp claude -p %s --system-prompt-file %s --model %s --output-format json --max-turns 1 --allowedTools "" 2>&1',
            escapeshellarg($apiKey),
            escapeshellarg($userMessage),
            escapeshellarg($tmpFile),
            escapeshellarg($slug)
        );

        try {
            $result = \Illuminate\Support\Facades\Process::timeout(60)->run($cmd);
            $output = $result->output();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("chatViaCli exception: " . $e->getMessage());
            $output = '';
        }
        @unlink($tmpFile);

        \Illuminate\Support\Facades\Log::info("chatViaCli result", [
            'output_len' => strlen($output ?? ''),
            'preview' => mb_substr($output ?? 'NULL', 0, 150),
        ]);

        if ($output) {
            $data = json_decode($output, true);
            return $data['result'] ?? '';
        }

        return '';
    }

    /**
     * Start a background timer that updates progress every 3s.
     */
    private function startProgressTimer(AgentContext $context, string $stepLabel): ?int
    {
        if (!$this->isWebChat($context)) return null;

        $cacheKey = "agent_progress:{$context->from}";
        $phases = [
            0 => "Connexion au modele IA...",
            3 => "Analyse de la requete...",
            6 => "Preparation des outils...",
            10 => "Execution en cours...",
            15 => "Appel API / commande...",
            20 => "Traitement des resultats...",
            30 => "Formatage de la reponse...",
            45 => "Presque termine...",
            60 => "Requete longue, patience...",
            90 => "Encore un peu...",
        ];

        // Write phases to a temp file for a background process
        $startTime = time();
        // Just update cache with phases based on elapsed time in a loop
        // Use a simple approach: pre-fill the cache with increasing timestamps
        foreach ($phases as $sec => $label) {
            \Illuminate\Support\Facades\Cache::put($cacheKey . ":phase:{$sec}", $label, 180);
        }
        \Illuminate\Support\Facades\Cache::put($cacheKey . ":start", $startTime, 180);
        \Illuminate\Support\Facades\Cache::put($cacheKey . ":label", $stepLabel, 180);

        return $startTime;
    }

    private function stopProgressTimer(?int $pid): void
    {
        // Nothing to stop — cleanup happens naturally
    }

    /**
     * Update processing progress for polling (partner portal).
     */
    private function updateProgress(AgentContext $context, string $status, string $step, string $detail): void
    {
        if (!$this->isWebChat($context)) return;
        $key = "agent_progress:{$context->from}";
        \Illuminate\Support\Facades\Cache::put($key, [
            'status' => $status,
            'step' => $step,
            'detail' => $detail,
        ], 120);

        // Clean up timer when idle
        if ($status === 'idle') {
            \Illuminate\Support\Facades\Cache::forget("{$key}:start");
            \Illuminate\Support\Facades\Cache::forget("{$key}:label");
        }
    }

    /**
     * Check if the context is a web/partner chat (not WhatsApp).
     */
    private function isWebChat(AgentContext $context): bool
    {
        return str_starts_with($context->from, 'web-custom-test-')
            || str_starts_with($context->from, 'partner-');
    }

    /**
     * Build context about available skills and scripts for the system prompt.
     */
    private function buildSkillsContext(): string
    {
        $skills = $this->customAgent->skills()->where('is_active', true)->get();
        $scripts = $this->customAgent->scripts()->where('is_active', true)->get();

        if ($skills->isEmpty() && $scripts->isEmpty()) {
            return '';
        }

        $parts = [];

        if ($skills->isNotEmpty()) {
            $parts[] = "SKILLS DISPONIBLES (routines que tu peux executer) :";
            foreach ($skills as $skill) {
                $trigger = $skill->trigger_phrase ? " (declencheur: \"{$skill->trigger_phrase}\")" : '';
                $parts[] = "- {$skill->name}{$trigger}: " . ($skill->description ?: 'Pas de description');
            }
            $parts[] = "Si l'utilisateur mentionne un de ces skills ou son declencheur, execute la routine correspondante.";
        }

        if ($scripts->isNotEmpty()) {
            $parts[] = "\nSCRIPTS DISPONIBLES :";
            foreach ($scripts as $script) {
                $parts[] = "- {$script->name} ({$script->language}): " . ($script->description ?: 'Pas de description');
            }
        }

        return implode("\n", $parts);
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

        // Inject learned memories
        $memoriesContext = $this->buildMemoriesContext();
        if ($memoriesContext) {
            $parts[] = $memoriesContext;
        }

        // Inject available skills and scripts so the agent knows about them
        $skillsInfo = $this->buildSkillsContext();
        if ($skillsInfo) {
            $parts[] = $skillsInfo;
        }

        // Inject persistent instructions file (check both root and memory/ for compatibility)
        $workspace = $this->customAgent->workspacePath();
        $memoryDir = $this->customAgent->workspacePath('memory');
        $instructionsPath = file_exists("{$workspace}/instructions.md") ? "{$workspace}/instructions.md" : "{$memoryDir}/instructions.md";
        if (file_exists($instructionsPath) && ($instructions = file_get_contents($instructionsPath))) {
            $parts[] = "INSTRUCTIONS PERSISTANTES (mises a jour via update_instructions):\n{$instructions}";
        }

        // Inject session memory file (try exact session key, then fallback to session.md)
        $sessionKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $context->from);
        $memoryPath = "{$memoryDir}/{$sessionKey}.md";
        if (!file_exists($memoryPath)) {
            $memoryPath = "{$memoryDir}/session.md";
        }
        if (file_exists($memoryPath) && ($sessionMemory = file_get_contents($memoryPath))) {
            $parts[] = "MEMOIRE DE SESSION (mise a jour via update_session_memory):\n{$sessionMemory}";
        }

        // Inject workspace path so agent knows where to store/read files
        $workspace = $this->customAgent->workspacePath();
        $parts[] = "ESPACE DE TRAVAIL : {$workspace}\nSi tu dois telecharger, generer ou stocker des fichiers, utilise ce repertoire. Sous-dossiers : docs/, scripts/, downloads/, memory/";

        // Inject available credentials (keys only, not values — agent reads them at runtime)
        $creds = $this->customAgent->credentials()->where('is_active', true)->get();
        if ($creds->isNotEmpty()) {
            $credList = "CREDENTIALS DISPONIBLES :\n";
            foreach ($creds as $cred) {
                $value = $cred->decrypted_value;
                $credList .= "- {$cred->key}" . ($cred->description ? " ({$cred->description})" : '') . " = {$value}\n";
            }
            $credList .= "Utilise ces valeurs directement dans tes appels API (headers, tokens, etc.).";
            $parts[] = $credList;
        }

        // Inject current local time so the agent always knows the correct time
        $tz = \App\Models\AppSetting::timezone();
        $now = now($tz);
        $parts[] = "DATE/HEURE ACTUELLE : " . $now->format('d/m/Y H:i') . " ({$tz}). Utilise TOUJOURS cette heure, jamais UTC.";

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
    /**
     * Build multimodal content blocks from media in the context.
     */
    private function buildMediaBlocks(AgentContext $context): ?array
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return null;
        }

        $mimetype = $context->mimetype ?? '';
        $isImage = in_array($mimetype, self::SUPPORTED_IMAGE_TYPES);
        $isPdf = $mimetype === 'application/pdf';

        if (!$isImage && !$isPdf) {
            return null;
        }

        $base64Data = $this->downloadMedia($context->mediaUrl);
        if (!$base64Data) {
            return null;
        }

        $blocks = [];

        if ($isImage) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimetype,
                    'data' => $base64Data,
                ],
            ];
            $blocks[] = ['type' => 'text', 'text' => $context->body ?: 'Analyse cette image.'];
        } elseif ($isPdf) {
            $blocks[] = [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => $base64Data,
                ],
            ];
            $blocks[] = ['type' => 'text', 'text' => $context->body ?: 'Analyse ce document PDF.'];
        }

        return $blocks;
    }

    private function downloadMedia(string $mediaUrl): ?string
    {
        try {
            $headResponse = $this->waha(10)->head($mediaUrl);
            if ($headResponse->successful()) {
                $contentLength = (int) ($headResponse->header('Content-Length') ?? 0);
                if ($contentLength > 0 && $contentLength > self::MAX_MEDIA_BYTES) {
                    Log::warning("[custom-agent] Media too large: " . round($contentLength / 1024 / 1024, 1) . " MB");
                    return null;
                }
            }

            $response = $this->waha(30)->get($mediaUrl);
            if ($response->successful()) {
                $body = $response->body();
                if (strlen($body) > self::MAX_MEDIA_BYTES) {
                    Log::warning('[custom-agent] Downloaded media exceeds size limit, discarding.');
                    return null;
                }
                return base64_encode($body);
            }
        } catch (\Throwable $e) {
            Log::error('[custom-agent] Media download failed: ' . $e->getMessage());
        }
        return null;
    }

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
