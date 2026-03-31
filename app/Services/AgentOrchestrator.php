<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Services\AgentManager;
use App\Models\Project;
use App\Services\Agents\AgentInterface;
use App\Services\Agents\AgentResult;
use App\Services\Agents\BaseAgent;
use App\Services\Agents\RouterAgent;
use App\Services\Agents\ToolProviderInterface;
use App\Models\CollaborativeVote;
use App\Models\UserAgentAnalytic;
use App\Jobs\AnalyzeSelfImprovementJob;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Http\Controllers\AgentController;

/**
 * AgentOrchestrator v3 — Dynamic agent discovery
 *
 * All agents in app/Services/Agents/ that extend BaseAgent are auto-discovered.
 * Adding a new *Agent.php file is all it takes — no manual registration needed.
 * Tools are auto-collected from any agent that overrides tools().
 */

class AgentOrchestrator
{
    private RouterAgent $router;
    private ConversationMemoryService $memory;
    private ToolRegistry $toolRegistry;
    private array $agents = [];
    private int $maxHandoffs = 3;

    /** Agents that should NOT be auto-registered (infrastructure, not user-facing) */
    private const EXCLUDED_AGENTS = [
        'BaseAgent',
        'RouterAgent',
        'ZenibizDocsAgent', // Migrated to custom/private agents system
    ];

    public function __construct()
    {
        $this->router = new RouterAgent();
        $this->memory = new ConversationMemoryService();
        $this->toolRegistry = new ToolRegistry();

        $this->discoverAgents();

        // Build tool registry — every agent with tools() is automatically a provider
        foreach ($this->agents as $agent) {
            if ($agent instanceof ToolProviderInterface && !empty($agent->tools())) {
                $this->toolRegistry->register($agent);
            }
        }

        // Pass agents to router so it can read their keywords/descriptions
        $this->router->registerAgents($this->agents);
    }

    /**
     * Auto-discover all agents in app/Services/Agents/*Agent.php
     */
    private function discoverAgents(): void
    {
        $agentDir = app_path('Services/Agents');
        $namespace = 'App\\Services\\Agents\\';

        foreach (glob("{$agentDir}/*Agent.php") as $file) {
            $className = basename($file, '.php');

            if (in_array($className, self::EXCLUDED_AGENTS)) {
                continue;
            }

            $fqcn = $namespace . $className;

            try {
                // Syntax-check the file before loading to prevent fatal parse errors
                // from rogue files (e.g. created by auto-improve on disk but not in git)
                $syntaxCheck = @exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $exitCode);
                if ($exitCode !== 0) {
                    Log::warning("AgentOrchestrator: syntax error in {$className}, skipping", ['file' => $file]);
                    continue;
                }

                if (!class_exists($fqcn)) {
                    continue;
                }

                $reflection = new \ReflectionClass($fqcn);
                if ($reflection->isAbstract() || !$reflection->isSubclassOf(BaseAgent::class)) {
                    continue;
                }

                $agent = new $fqcn();
                $this->agents[$agent->name()] = $agent;
            } catch (\Throwable $e) {
                Log::warning("AgentOrchestrator: failed to load {$className}", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Resolve an agent instance by name. Used by send_agent_message tool.
     * Static so BaseAgent can call it without an orchestrator instance.
     */
    public static function resolveAgent(string $name): ?BaseAgent
    {
        // Build a temporary discovery (cached via static)
        static $agentCache = null;

        if ($agentCache === null) {
            $agentCache = [];
            $agentDir = app_path('Services/Agents');
            $namespace = 'App\\Services\\Agents\\';

            foreach (glob("{$agentDir}/*Agent.php") as $file) {
                $className = basename($file, '.php');
                if (in_array($className, ['BaseAgent', 'RouterAgent'])) continue;

                $fqcn = $namespace . $className;

                try {
                    // Syntax-check before loading
                    @exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $exitCode);
                    if ($exitCode !== 0) continue;

                    if (!class_exists($fqcn)) continue;

                    $reflection = new \ReflectionClass($fqcn);
                    if ($reflection->isAbstract() || !$reflection->isSubclassOf(BaseAgent::class)) continue;

                    $agent = new $fqcn();
                    $agentCache[$agent->name()] = $agent;
                } catch (\Throwable $e) {
                    // Skip broken agents
                }
            }
        }

        return $agentCache[$name] ?? null;
    }

    /**
     * Hot reload a specific agent (D12.4).
     * Re-instantiate and re-register a single agent without restarting the app.
     */
    public function hotReloadAgent(string $agentName): bool
    {
        $agentDir = app_path('Services/Agents');
        $namespace = 'App\\Services\\Agents\\';

        foreach (glob("{$agentDir}/*Agent.php") as $file) {
            $className = basename($file, '.php');
            if (in_array($className, self::EXCLUDED_AGENTS)) continue;

            $fqcn = $namespace . $className;
            try {
                $reflection = new \ReflectionClass($fqcn);
                if ($reflection->isAbstract() || !$reflection->isSubclassOf(BaseAgent::class)) continue;

                $agent = new $fqcn();
                if ($agent->name() === $agentName) {
                    $this->agents[$agentName] = $agent;
                    // Re-register tool provider
                    if ($agent instanceof ToolProviderInterface && !empty($agent->tools())) {
                        $this->toolRegistry->register($agent);
                    }
                    $this->router->registerAgents($this->agents);
                    Log::info("Hot reloaded agent: {$agentName}");
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning("Hot reload failed for {$className}: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Get list of all registered agent names and their versions.
     */
    public function listAgents(): array
    {
        $list = [];
        foreach ($this->agents as $name => $agent) {
            $list[$name] = [
                'name' => $name,
                'class' => get_class($agent),
                'description' => $agent->description(),
                'version' => $agent->version(),
                'tools_count' => count($agent->tools()),
            ];
        }
        return $list;
    }

    public function process(AgentContext $context): AgentResult
    {
        try {
            // 0. Check for debug mode toggle commands
            $debugToggle = $this->handleDebugToggle($context);
            if ($debugToggle) {
                $this->sendReply($context, $debugToggle->reply);
                return $debugToggle;
            }

            // 0b. Check for private agent commands
            $privateCmd = $this->handlePrivateCommand($context);
            if ($privateCmd) {
                $this->sendReply($context, $privateCmd->reply);
                return $privateCmd;
            }

            $debug = $context->session->debug_mode ?? false;
            $debugTraces = [];

            // 0c. Check for #exit to deactivate custom agent
            $cleanBody = mb_strtolower(trim($context->body ?? ''));
            if (($cleanBody === '#exit' || $cleanBody === '/exit') && !empty($context->session->active_custom_agent_id)) {
                $context->session->update(['active_custom_agent_id' => null]);
                $reply = "✅ Mode agent prive desactive. Tu parles a nouveau a l'assistant principal.";
                $this->sendReply($context, $reply);
                return AgentResult::reply($reply);
            }

            // 1. Handle pending stateful flows (before custom agent routing — preserves photo/PDF context)
            $pendingResult = $this->handlePendingStates($context, $debug, $debugTraces);
            if ($pendingResult) {
                // Append debug traces to pending result too
                if ($debug && !empty($debugTraces) && $pendingResult->action === 'reply' && $pendingResult->reply) {
                    $debugBlock = "\n\n---\n🔍 *DEBUG MODE*\n" . implode("\n\n", $debugTraces);
                    $pendingResult = AgentResult::reply(
                        $pendingResult->reply . $debugBlock,
                        $pendingResult->metadata
                    );
                }
                return $pendingResult;
            }

            // 1b. Route to active custom agent if set (after pending states)
            if (!empty($context->session->active_custom_agent_id)) {
                $customAgentName = "custom_{$context->session->active_custom_agent_id}";
                return $this->dispatch($context, $customAgentName);
            }

            // 2. Route the message
            \App\Events\BeforeRouting::dispatch($context);
            $routing = $this->router->route($context);
            \App\Events\AfterRouting::dispatch($context, $routing);

            // 2b. Check if sub-agent is disabled — redirect to chat
            $routedAgentName = $routing['agent'];
            if ($routedAgentName !== 'chat' && $context->agent->isSubAgentDisabled($routedAgentName)) {
                Log::info("Sub-agent '{$routedAgentName}' is disabled for agent #{$context->agent->id}, falling back to chat");
                $routing['agent'] = 'chat';
                $routing['reasoning'] = "Agent '{$routedAgentName}' is disabled";
                $routedAgentName = 'chat';
            }

            // 2c. Check private agent access — redirect to chat if unauthorized
            $routedAgentInstance = $this->agents[$routedAgentName] ?? null;
            if ($routedAgentInstance && $routedAgentInstance->isPrivate()) {
                $privateAccess = $context->agent->private_sub_agents ?? [];
                $allowedPeers = $privateAccess[$routedAgentName] ?? [];
                if (!in_array($context->from, $allowedPeers)) {
                    Log::info("Private agent '{$routedAgentName}' blocked for peer {$context->from}");
                    $routing['agent'] = 'chat';
                    $routing['reasoning'] = "Private agent not authorized for this session";
                }
            }

            // Log routing decision
            AgentManager::log($context->agent->id, 'orchestrator', 'Router decision', [
                'from'    => $context->from,
                'body'    => mb_substr($context->body ?? '', 0, 100),
                'routing' => $routing,
            ]);

            // Override model if the agent has a specific sub-agent model configured
            $configuredModel = $context->agent->getSubAgentModel($routing['agent']);
            $routingModel = $this->resolveFullModelId($configuredModel)
                ?? $this->resolveFullModelId($routing['model'])
                ?? $routing['model'];

            if ($debug) {
                $confidence = $routing['confidence'] ?? '?';
                $isOnPrem = !str_starts_with($routingModel, 'claude-');
                $routerDebug = "[DEBUG ROUTER]\n"
                    . "Message: " . mb_substr($context->body ?? '', 0, 80) . "\n"
                    . "Agent: {$routing['agent']}\n"
                    . "Confidence: {$confidence}%\n"
                    . "Router model: {$routing['model']}\n"
                    . "Agent default: {$context->agent->model}\n"
                    . "Sub-agent override: {$configuredModel}\n"
                    . "Final model: {$routingModel}" . ($isOnPrem ? " (on-prem)" : " (cloud)") . "\n"
                    . "Complexity: {$routing['complexity']}\n"
                    . "Autonomy: {$routing['autonomy']}\n"
                    . "Reasoning: {$routing['reasoning']}";
                $debugTraces[] = $routerDebug;
                $this->sendDebug($context, $routerDebug);
            }

            // Enrich context with routing info and tool registry
            $routedContext = $context->withToolRegistry($this->toolRegistry)->withRouting(
                $routing['agent'],
                $routingModel,
                $routing['complexity'],
                $routing['reasoning'],
                $routing['autonomy'] ?? 'confirm'
            );

            // 3. Dispatch to agent — agentic architecture
            // VoiceCommandAgent transcribes audio, then we re-route the text.
            // DevAgent handles code tasks via SubAgent.
            // Everything else goes through ChatAgent's agentic loop (with tools).
            $dispatchAgent = $routing['agent'];

            if ($dispatchAgent === 'voice_command') {
                // Voice: transcribe first, then re-route the transcribed text
                $voiceAgent = $this->agents['voice_command'];
                $voiceResult = $voiceAgent->handle($routedContext);

                if ($debug) {
                    $voiceDebug = "[DEBUG VOICE] Transcription: " . mb_substr($voiceResult->reply ?? '', 0, 100);
                    $debugTraces[] = $voiceDebug;
                    $this->sendDebug($context, $voiceDebug);
                }

                // If transcription succeeded and confidence is good, re-route the text
                $isLowConfidence = $voiceResult->metadata['low_confidence'] ?? false;
                if ($voiceResult->action === 'reply' && $voiceResult->reply && !$isLowConfidence) {
                    $transcript = $voiceResult->reply;

                    // Create a new context with the transcribed text replacing the body
                    $textContext = new AgentContext(
                        agent: $context->agent,
                        session: $context->session,
                        from: $context->from,
                        senderName: $context->senderName,
                        body: $transcript,
                        hasMedia: false,
                        mediaUrl: null,
                        mimetype: null,
                        media: null,
                    );

                    // Re-route the transcribed text
                    $newRouting = $this->router->route($textContext);

                    if ($debug) {
                        $reRouteDebug = "[DEBUG VOICE RE-ROUTE] → {$newRouting['agent']} (from transcript)";
                        $debugTraces[] = $reRouteDebug;
                        $this->sendDebug($context, $reRouteDebug);
                    }

                    $routedContext = $textContext->withRouting(
                        $newRouting['agent'],
                        $newRouting['model'],
                        $newRouting['complexity'],
                        'voice_command → ' . $newRouting['reasoning'],
                        $newRouting['autonomy'] ?? 'confirm'
                    );

                    $dispatchAgent = $newRouting['agent'];
                    if (!isset($this->agents[$dispatchAgent])) {
                        $dispatchAgent = 'chat';
                    }
                } else {
                    // Low confidence or failed — voice agent already replied to user
                    return $voiceResult;
                }
            } elseif (!isset($this->agents[$dispatchAgent])) {
                // Agent not found in registry — fallback to chat
                $dispatchAgent = 'chat';
            }
            // If routed to document and the message explicitly references an API project,
            // redirect to dev — DevAgent fetches fresh data then creates the file.
            // Only redirect when the message itself mentions the project (not just session).
            if ($dispatchAgent === 'document' && $this->messageReferencesApiProject($context->body ?? '')) {
                $dispatchAgent = 'dev';
            }

            // If image received and peer has access to a coded custom agent
            // that handles images (e.g. ZenibizDocsAgent photo-to-PDF), redirect there
            if ($context->hasMedia && $dispatchAgent === 'screenshot') {
                $mime = $context->mimetype ?? ($context->media['mimetype'] ?? '');
                if (str_starts_with($mime, 'image/')) {
                    $imageCustomAgent = \App\Models\CustomAgent::where('agent_id', $context->agent->id)
                        ->where('is_active', true)
                        ->whereNotNull('agent_class')
                        ->get()
                        ->first(fn($ca) => $ca->isPeerAllowed($context->from));
                    if ($imageCustomAgent) {
                        $dispatchAgent = $imageCustomAgent->routingKey();
                    }
                }
            }

            // Inject conversation memory context into the routed context
            try {
                $memoryAgent = $this->agents['conversation_memory'] ?? null;
                if ($memoryAgent instanceof ConversationMemoryAgent) {
                    $memoryContext = $memoryAgent->formatFactsForPrompt($context->from, $context->body);
                    if ($memoryContext) {
                        $routedContext = $routedContext->withMemoryContext($memoryContext);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ConversationMemory injection failed: ' . $e->getMessage());
            }

            // Track last agent in shared context bridge
            try {
                \App\Services\ContextMemoryBridge::getInstance()->setLastAgent($context->from, $routing['agent']);
            } catch (\Throwable $e) {
                Log::warning('ContextMemoryBridge setLastAgent failed: ' . $e->getMessage());
            }

            if ($debug) {
                $isOnPrem = !str_starts_with($routingModel, 'claude-');
                $dispatchDebug = "[DEBUG DISPATCH]\n"
                    . "Dispatch to: {$dispatchAgent}" . ($dispatchAgent !== ($routing['agent'] ?? '') ? " (agentic, routed from {$routing['agent']})" : '') . "\n"
                    . "Model: {$routingModel}" . ($isOnPrem ? " (on-prem, no tools, compact prompt)" : " (cloud, agentic loop + tools)");
                $debugTraces[] = $dispatchDebug;
                $this->sendDebug($context, $dispatchDebug);
            }

            $dispatchStart = microtime(true);
            $result = $this->dispatch($routedContext, $dispatchAgent);
            $dispatchDuration = (int) ((microtime(true) - $dispatchStart) * 1000);

            // 4. Track interaction in user_agent_analytics
            try {
                $interactionType = $context->hasMedia ? 'file_upload' : ($this->isQuestion($context->body) ? 'question' : 'command');
                UserAgentAnalytic::logInteraction(
                    $context->from,
                    $routing['agent'],
                    $interactionType,
                    $dispatchDuration,
                    $result->action === 'reply' && !empty($result->reply),
                    ['dispatched_to' => $dispatchAgent, 'model' => $routingModel]
                );
            } catch (\Throwable $e) {
                Log::warning('UserAgentAnalytic tracking failed: ' . $e->getMessage());
            }

            // 4b. Detect significant agent change and notify AIAssistantAgent
            try {
                $previousAgent = \App\Services\ContextMemoryBridge::getInstance()->getLastAgent($context->from);
                if ($previousAgent && $previousAgent !== $routing['agent']) {
                    $prevCategory = $this->getAgentCategory($previousAgent);
                    $newCategory = $this->getAgentCategory($routing['agent']);

                    if ($prevCategory !== $newCategory) {
                        $assistantAgent = $this->agents['assistant'] ?? null;
                        if ($assistantAgent instanceof AIAssistantAgent) {
                            Log::info('AIAssistant: significant agent change detected', [
                                'user' => $context->from,
                                'from' => "{$previousAgent} ({$prevCategory})",
                                'to' => "{$routing['agent']} ({$newCategory})",
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Agent change detection failed: ' . $e->getMessage());
            }

            // 5. Save memory for reply actions
            if ($result->action === 'reply' && $result->reply) {
                $this->saveMemory($context, $result->reply);

                // 6. Dispatch self-improvement analysis in background
                if ($context->body) {
                    AnalyzeSelfImprovementJob::dispatch(
                        $context->agent->id,
                        $context->from,
                        $context->body,
                        $result->reply,
                        $routing['agent']
                    )->onQueue('low');
                }
            }

            // Append debug traces to reply if debug mode is on
            if ($debug && !empty($debugTraces) && $result->action === 'reply' && $result->reply) {
                $debugBlock = "\n\n---\n🔍 *DEBUG MODE*\n" . implode("\n\n", $debugTraces)
                    . "\n\n[DEBUG TIMING] Dispatch: {$dispatchDuration}ms";
                $result = AgentResult::reply(
                    $result->reply . $debugBlock,
                    $result->metadata
                );
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('AgentOrchestrator error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return AgentResult::reply('Désolé, j\'ai eu un souci technique. Réessaie dans un instant.');
        }
    }

    /**
     * Detect debug mode toggle commands and update session accordingly.
     */
    /**
     * Handle /private command — list available private agents.
     */
    private function handlePrivateCommand(AgentContext $context): ?AgentResult
    {
        if (!$context->body) return null;

        $clean = mb_strtolower(trim($context->body));

        $patterns = ['#private', '/private', 'private bot', 'bot prive', 'bots prives', 'agents prives', 'agent prive'];
        $match = false;
        foreach ($patterns as $pattern) {
            if ($clean === $pattern || str_contains($clean, $pattern)) {
                $match = true;
                break;
            }
        }
        if (!$match) return null;

        // Hardcoded private sub-agents
        $privateAgents = AgentController::getPrivateSubAgents();

        // Custom agents from database (created via UI)
        $customAgents = \App\Models\CustomAgent::where('agent_id', $context->agent->id)
            ->where('is_active', true)
            ->get();

        if (empty($privateAgents) && $customAgents->isEmpty()) {
            return AgentResult::reply("Aucun agent prive disponible.");
        }

        // Check which ones the peer already has access to
        $currentAccess = $context->agent->private_sub_agents ?? [];
        $lines = [];
        $available = [];
        $i = 1;

        // List hardcoded private agents
        foreach ($privateAgents as $key => $meta) {
            $allowedPeers = $currentAccess[$key] ?? [];
            $hasAccess = in_array($context->from, $allowedPeers);
            $status = $hasAccess ? ' _(actif)_' : '';
            $lines[] = "{$i}. {$meta['icon']} *{$meta['label']}*{$status}
   _{$meta['description']}_";
            $available[] = ['key' => $key, 'meta' => $meta, 'has_access' => $hasAccess, 'type' => 'sub_agent'];
            $i++;
        }

        // List custom agents from DB
        foreach ($customAgents as $ca) {
            $allowedPeers = $ca->allowed_peers ?? [];
            $hasAccess = empty($allowedPeers) || in_array($context->from, $allowedPeers);
            $status = $hasAccess ? ' _(actif)_' : '';
            $icon = $ca->avatar ?? '🤖';
            $lines[] = "{$i}. {$icon} *{$ca->name}*{$status}
   _{$ca->description}_";
            $available[] = ['key' => "custom_{$ca->id}", 'meta' => [
                'label' => $ca->name,
                'icon' => $icon,
                'description' => $ca->description,
                'model' => $ca->model,
            ], 'has_access' => $hasAccess, 'type' => 'custom', 'custom_id' => $ca->id];
            $i++;
        }

        $list = implode("\n\n", $lines);
        $reply = "🔒 *Agents prives disponibles*\n\n{$list}\n\n_Reponds avec le numero pour demander l'acces._";

        $context->session->update([
            'pending_agent_context' => [
                'agent' => '_system',
                'type' => 'private_select',
                'agents' => $available,
                'expires_at' => now()->addMinutes(5)->toISOString(),
            ],
        ]);

        return AgentResult::reply($reply);
    }

    /**
     * Handle the numeric selection after /private listing.
     */
    private function handlePrivateSelect(AgentContext $context, array $pendingCtx): ?AgentResult
    {
        $context->session->update(['pending_agent_context' => null]);

        $input = trim($context->body);
        $agents = $pendingCtx['agents'] ?? [];

        // Parse numeric choice
        if (!ctype_digit($input) || (int) $input < 1 || (int) $input > count($agents)) {
            return AgentResult::reply("Choix invalide. Envoie *#private* pour revoir la liste.");
        }

        $choice = $agents[(int) $input - 1];
        $agentKey = $choice['key'];
        $meta = $choice['meta'];

        // Already has access — activate the agent for this session
        if ($choice['has_access']) {
            if (($choice['type'] ?? '') === 'custom' && !empty($choice['custom_id'])) {
                $context->session->update(['active_custom_agent_id' => $choice['custom_id']]);
                return AgentResult::reply("{$meta['icon']} *{$meta['label']}* est maintenant actif ! Tes prochains messages lui seront envoyes.\n\n_Envoie *#exit* pour revenir au mode normal._");
            }
            return AgentResult::reply("{$meta['icon']} Tu as deja acces a *{$meta['label']}*. Utilise-le directement !");
        }

        // Generate approval token
        $token = Str::random(64);
        Cache::put("private_approval:{$token}", [
            'agent_id' => $context->agent->id,
            'agent_key' => $agentKey,
            'agent_label' => $meta['label'],
            'agent_icon' => $meta['icon'],
            'peer_id' => $context->from,
            'peer_name' => $context->senderName,
            'requested_at' => now()->toISOString(),
        ], now()->addMinutes(30));

        $baseUrl = rtrim(config('app.url'), '/');
        $approvalUrl = "{$baseUrl}/approve/private/{$token}";

        AgentManager::log($context->agent->id, 'orchestrator', 'Private agent access requested', [
            'agent_key' => $agentKey,
            'peer_id' => $context->from,
            'peer_name' => $context->senderName,
            'token' => substr($token, 0, 8) . '...',
        ]);

        return AgentResult::reply(
            "{$meta['icon']} *Demande d'acces a {$meta['label']}*\n\n"
            . "Un lien d'approbation a ete genere.\n"
            . "L'administrateur doit ouvrir ce lien pour valider :\n\n"
            . "{$approvalUrl}\n\n"
            . "_Ce lien expire dans 30 minutes._"
        );
    }

    private function handleDebugToggle(AgentContext $context): ?AgentResult
    {
        if (!$context->body) return null;

        $clean = mb_strtolower(trim($context->body));

        // Exact match shortcuts
        if ($clean === '#debug' || $clean === '/debug') {
            $context->session->update(['debug_mode' => true]);
            return AgentResult::reply(
                "🔍 *Mode debug activé*\n\nChaque réponse inclura le routing, l'agent choisi, le timing.\nPour désactiver : _#nodebug_"
            );
        }
        if ($clean === '#nodebug' || $clean === '/nodebug') {
            $context->session->update(['debug_mode' => false]);
            return AgentResult::reply("✅ Mode debug désactivé.");
        }

        // Activate debug mode (fuzzy match — contains)
        $activatePatterns = [
            'mode debug', 'debug mode', 'active debug', 'activer debug', 'activer le debug',
            'active le debug', 'debug on', 'enable debug', 'passer en debug', 'passe en debug',
            'activer mode debug', 'lance le debug',
        ];
        foreach ($activatePatterns as $pattern) {
            if (str_contains($clean, $pattern)) {
                $context->session->update(['debug_mode' => true]);
                return AgentResult::reply(
                    "🔍 *Mode debug activé*\n\n"
                    . "Chaque réponse inclura désormais :\n"
                    . "• L'agent choisi par le routeur et pourquoi\n"
                    . "• Le modèle et la complexité\n"
                    . "• Le temps de traitement\n\n"
                    . "Pour désactiver : _désactiver debug_ ou _debug off_"
                );
            }
        }

        // Deactivate debug mode (fuzzy match — contains)
        $deactivatePatterns = [
            'désactiver debug', 'desactiver debug', 'désactive debug', 'desactive debug',
            'debug off', 'disable debug', 'stop debug', 'arrête debug', 'arrete debug',
            'supprimer debug', 'supprime debug', 'enlever debug', 'enlève debug',
            'désactiver le debug', 'desactiver le debug', 'supprime le debug',
            'couper le debug', 'coupe le debug', 'quitter debug',
        ];
        foreach ($deactivatePatterns as $pattern) {
            if (str_contains($clean, $pattern)) {
                $context->session->update(['debug_mode' => false]);
                return AgentResult::reply("✅ Mode debug désactivé.");
            }
        }

        return null;
    }

    private function handlePendingStates(AgentContext $context, bool $debug = false, array &$debugTraces = []): ?AgentResult
    {
        // Allow media-only messages through if there is a pending photo collection
        $pendingCheck = $context->session->pending_agent_context;
        if (!$context->body && !($context->hasMedia && $pendingCheck && str_contains($pendingCheck['type'] ?? '', 'photo'))) {
            return null;
        }

        // Generic pending agent context (list selection, multi-step flows, etc.)
        $pendingCtx = $context->session->pending_agent_context;
        if ($pendingCtx && !empty($pendingCtx['agent'])) {
            // Check expiry
            $expiresAt = $pendingCtx['expires_at'] ?? null;
            if ($expiresAt && now()->greaterThan($expiresAt)) {
                $context->session->update(['pending_agent_context' => null]);
                if ($debug) {
                    $msg = "[DEBUG PENDING] pending_agent_context expired → cleared";
                    $debugTraces[] = $msg;
                    $this->sendDebug($context, $msg);
                }
            } else {
                // Skip isNewIntent check when the agent expects raw input (credentials, URLs, etc.)
                $expectRawInput = $pendingCtx['expect_raw_input'] ?? false;
                $isNew = $expectRawInput ? false : $this->isNewIntent($context->body);

                if ($debug) {
                    $msg = "[DEBUG PENDING] pending_agent_context: agent={$pendingCtx['agent']}, type={$pendingCtx['type']}\n"
                        . "expectRawInput=" . ($expectRawInput ? 'TRUE' : 'FALSE') . "\n"
                        . "isNewIntent=" . ($isNew ? 'TRUE' : 'FALSE');
                    $debugTraces[] = $msg;
                    $this->sendDebug($context, $msg);
                }

                if ($isNew) {
                    $context->session->update(['pending_agent_context' => null]);
                } else {
                    // Handle private agent selection (system-level pending)
                    if (($pendingCtx['agent'] ?? '') === '_system' && ($pendingCtx['type'] ?? '') === 'private_select') {
                        $result = $this->handlePrivateSelect($context, $pendingCtx);
                        if ($result) {
                            $this->sendReply($context, $result->reply);
                            return $result;
                        }
                        $context->session->update(['pending_agent_context' => null]);
                        return null;
                    }

                    $agent = $this->agents[$pendingCtx['agent']] ?? null;

                    // If agent not in registry, try to instantiate it (e.g. excluded/custom agents)
                    if (!$agent) {
                        $agentClass = "App\\Services\\Agents\\" . str_replace(' ', '', ucwords(str_replace('_', ' ', $pendingCtx['agent']))) . 'Agent';
                        if (class_exists($agentClass)) {
                            $agent = new $agentClass();
                        }
                    }

                    if ($agent && method_exists($agent, 'handlePendingContext')) {
                        $result = $agent->handlePendingContext($context, $pendingCtx);
                        if ($result) return $result;
                    }
                    // If agent didn't handle it, clear and fall through
                    $context->session->update(['pending_agent_context' => null]);
                }
            }
        }

        // Pending project switch confirmation
        if ($context->session->pending_switch_project_id) {
            $isNew = $this->isNewIntent($context->body);

            if ($debug) {
                $msg = "[DEBUG PENDING] pending_switch_project_id={$context->session->pending_switch_project_id}\n"
                    . "isNewIntent=" . ($isNew ? 'TRUE' : 'FALSE') . "\n"
                    . "Action: " . ($isNew ? 'CLEAR pending, route normally' : 'handle as switch confirmation');
                $debugTraces[] = $msg;
                $this->sendDebug($context, $msg);
            }

            if ($isNew) {
                $context->session->update(['pending_switch_project_id' => null]);
                return null;
            }

            $projectAgent = $this->agents['project'] ?? null;
            if ($projectAgent) {
                return $projectAgent->handle($context);
            }
        }

        // Task awaiting validation
        $awaitingProject = Project::where('status', 'awaiting_validation')
            ->where('requester_phone', $context->from)
            ->orderByDesc('created_at')
            ->first();

        if ($awaitingProject) {
            $isNew = $this->isNewIntent($context->body);

            if ($debug) {
                $msg = "[DEBUG PENDING] awaiting_validation project={$awaitingProject->name} (id={$awaitingProject->id})\n"
                    . "isNewIntent=" . ($isNew ? 'TRUE' : 'FALSE') . "\n"
                    . "Action: " . ($isNew ? 'CANCEL awaiting, route normally' : 'handle as task validation');
                $debugTraces[] = $msg;
                $this->sendDebug($context, $msg);
            }

            if ($isNew) {
                $awaitingProject->update(['status' => 'rejected']);

                AgentManager::log($context->agent->id, 'orchestrator', 'Awaiting validation auto-cancelled — new intent detected', [
                    'project_id'  => $awaitingProject->id,
                    'new_message' => mb_substr($context->body, 0, 100),
                ]);

                return null;
            }

            $devAgent = $this->agents['dev'] ?? null;
            if ($devAgent) {
                return $devAgent->handle($context);
            }
        }

        if ($debug && !$context->session->pending_switch_project_id && !$awaitingProject) {
            $msg = "[DEBUG PENDING] No pending states found → routing normally";
            $debugTraces[] = $msg;
            $this->sendDebug($context, $msg);
        }

        return null;
    }

    /**
     * Detect if a message is a new intent rather than a response to a pending state.
     * Short confirmations (oui, non, ok, go, annule) are NOT new intents.
     * Longer messages or messages mentioning projects/tasks are new intents.
     */
    private function isNewIntent(string $body): bool
    {
        $clean = mb_strtolower(trim($body));

        // Short confirmation/rejection words → NOT a new intent
        $confirmWords = ['oui', 'non', 'ok', 'go', 'yes', 'no', 'annule', 'stop',
            'c\'est bon', 'parfait', 'lance', 'envoie', 'top', 'nickel', 'yep',
            'ouais', 'nope', 'cancel', 'let\'s go'];

        foreach ($confirmWords as $word) {
            if ($clean === $word) return false;
        }

        // Very short messages (< 15 chars) are likely confirmations
        if (mb_strlen($clean) < 15) return false;

        // Use Haiku to detect if this is a new topic
        $claude = new LLMClient();
        $response = $claude->chat(
            "Message: \"{$body}\"",
            ModelResolver::fast(),
            "L'utilisateur etait en train de confirmer/modifier une tache precedente.\n"
            . "Determine si ce nouveau message est:\n"
            . "- CONTINUATION = une reponse a la tache en cours (confirmation, modification, precision, annulation)\n"
            . "- NEW_INTENT = un sujet completement different (changer de projet, nouvelle demande, autre question)\n\n"
            . "Indices de NEW_INTENT: mentionne un autre projet, demande de switcher, nouvelle tache sans rapport, "
            . "mots comme 'je veux bosser sur', 'on passe sur', 'switch', 'autre projet', 'plutot'\n\n"
            . "Reponds UNIQUEMENT par CONTINUATION ou NEW_INTENT."
        );

        $result = strtoupper(trim($response ?? ''));

        Log::info('isNewIntent check', [
            'body' => mb_substr($body, 0, 100),
            'haiku_response' => $response,
            'result' => str_contains($result, 'NEW_INTENT') ? 'NEW_INTENT' : 'CONTINUATION',
        ]);

        return str_contains($result, 'NEW_INTENT');
    }

    private function dispatch(AgentContext $context, string $agentName, int $depth = 0): AgentResult
    {
        if ($depth >= $this->maxHandoffs) {
            Log::warning('AgentOrchestrator: max handoff depth reached', ['agent' => $agentName]);
            $agentName = 'chat'; // Fallback to chat
        }

        // Custom agent routing: "custom_{id}" → CustomAgentRunner
        if (str_starts_with($agentName, 'custom_')) {
            $customAgentId = (int) substr($agentName, 7);
            $customAgent = \App\Models\CustomAgent::where('id', $customAgentId)
                ->where('agent_id', $context->agent->id)
                ->where('is_active', true)
                ->first();

            if ($customAgent) {
                // Check peer access control
                if (!$customAgent->isPeerAllowed($context->from)) {
                    Log::info("Custom agent '{$agentName}' blocked for peer {$context->from}");
                    $agentName = 'chat';
                } else {
                    // Use coded agent class if available, otherwise CustomAgentRunner
                    $runner = $customAgent->isCoded()
                        ? $customAgent->makeCodedAgent()
                        : new \App\Services\CustomAgentRunner($customAgent);

                    \App\Events\BeforeAgentHandle::dispatch($runner, $context, $agentName);
                    $handleStart = microtime(true);
                    $result = $runner->handle($context);
                    $handleDuration = (microtime(true) - $handleStart) * 1000;
                    \App\Events\AfterAgentHandle::dispatch($runner, $context, $result, $agentName, $handleDuration);
                    return $result;
                }
            }

            Log::warning("Custom agent not found or inactive: {$agentName}");
            $agentName = 'chat';
        }

        $agent = $this->agents[$agentName] ?? $this->agents['chat'];

        \App\Events\BeforeAgentHandle::dispatch($agent, $context, $agentName);
        $handleStart = microtime(true);

        $result = $agent->handle($context);

        $handleDuration = (microtime(true) - $handleStart) * 1000;
        \App\Events\AfterAgentHandle::dispatch($agent, $context, $result, $agentName, $handleDuration);

        // Handle handoff
        if ($result->action === 'handoff' && $result->handoffTo) {
            AgentManager::log($context->agent->id, 'orchestrator', "Handoff: {$agentName} → {$result->handoffTo}", $result->metadata ?? []);

            return $this->dispatch($context, $result->handoffTo, $depth + 1);
        }

        return $result;
    }

    /**
     * Send a WhatsApp reply from the orchestrator (for system commands like /debug, /private).
     * Web chat doesn't need this — the reply is returned in the HTTP response.
     */
    private function sendReply(AgentContext $context, string $text): void
    {
        if ($context->session->channel === 'web') {
            return;
        }

        try {
            \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $context->from,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Exception $e) {
            Log::warning('Orchestrator sendReply failed: ' . $e->getMessage());
        }
    }

    private function sendDebug(AgentContext $context, string $text): void
    {
        $this->sendReply($context, $text);
    }

    /**
     * Map short model names (from UI) to full Anthropic model IDs.
     */
    private function resolveFullModelId(string $model): ?string
    {
        // Already a full model ID (dated versions or on-prem with colons)
        if (str_contains($model, '-202') || str_contains($model, ':')) {
            return $model;
        }

        // Resolve short Claude names (e.g. claude-sonnet-4-6 → claude-sonnet-4-6-20250514)
        if (str_starts_with($model, 'claude-')) {
            $resolved = ModelResolver::resolve('balanced'); // try to find it
            foreach (\App\Services\ModelResolver::CLOUD_MODELS as $id => $label) {
                if (str_contains($id, $model) || str_contains($model, str_replace(['-20250514', '-20251001', '-20250602'], '', $id))) {
                    return $id;
                }
            }
            return $model; // return as-is, API might accept it
        }

        return match ($model) {
            'fast' => ModelResolver::fast(),
            'balanced' => ModelResolver::balanced(),
            'powerful' => ModelResolver::powerful(),
            'claude-haiku-4-5' => 'claude-haiku-4-5-20251001',
            'claude-sonnet-4-5' => 'claude-sonnet-4-20250514',
            'claude-opus-4-5' => 'claude-opus-4-20250514',
            'qwen2.5:3b' => 'qwen2.5:3b',
            'qwen2.5:7b' => 'qwen2.5:7b',
            'qwen2.5:14b' => 'qwen2.5:14b',
            'qwen2.5-coder:7b' => 'qwen2.5-coder:7b',
            'llama3.2:3b' => 'llama3.2:3b',
            'gemma2:2b' => 'gemma2:2b',
            'phi3:mini' => 'phi3:mini',
            'deepseek-coder-v2:16b' => 'deepseek-coder-v2:16b',
            default => null,
        };
    }

    /**
     * Detect if a message is a question based on simple heuristics.
     */
    private function isQuestion(?string $body): bool
    {
        if (!$body) return false;
        $clean = trim($body);
        return str_ends_with($clean, '?')
            || (bool) preg_match('/^(qui|que|quoi|comment|pourquoi|combien|ou|quand|est[\s-]ce|what|how|why|where|when|who|which|is|are|do|does|can|could)\b/iu', $clean);
    }

    /**
     * Categorize an agent for significant change detection.
     */
    private function getAgentCategory(string $agent): string
    {
        $categories = [
            'productivity' => ['todo', 'reminder', 'pomodoro', 'time_blocker', 'habit', 'event_reminder', 'daily_brief'],
            'dev' => ['dev', 'code_review', 'document', 'analysis'],
            'fun' => ['hangman', 'game_master', 'interactive_quiz', 'music'],
            'finance' => ['finance', 'budget_tracker'],
            'learning' => ['flashcard', 'content_summarizer', 'content_curator', 'web_search'],
            'wellbeing' => ['mood_check', 'recipe'],
            'social' => ['collaborative_task', 'smart_meeting'],
            'system' => ['chat', 'assistant', 'user_preferences', 'conversation_memory', 'smart_context', 'context_memory_bridge', 'streamline', 'voice_command', 'screenshot'],
        ];

        foreach ($categories as $category => $agents) {
            if (in_array($agent, $agents)) {
                return $category;
            }
        }

        return 'other';
    }

    private function saveMemory(AgentContext $context, string $reply): void
    {
        $bodyForMemory = $context->body ?? '';

        if (!$bodyForMemory && $context->hasMedia) {
            $bodyForMemory = $context->mimetype === 'application/pdf'
                ? '[PDF envoyé]'
                : '[Image envoyée]';
        }

        // Generate summary
        $summary = $this->memory->formatForPrompt($context->agent->id, $context->from)
            ? (new LLMClient())->chat(
                "Résume cet échange en 1 phrase courte (max 20 mots).\n"
                . "Message de {$context->senderName}: {$bodyForMemory}\n"
                . "Réponse de ZeniClaw: {$reply}",
                ModelResolver::fast(),
                'Tu es un assistant qui résume des échanges. Réponds uniquement avec le résumé, rien d\'autre.'
            )
            : '';

        $this->memory->append(
            $context->agent->id,
            $context->from,
            $context->senderName,
            $bodyForMemory,
            $reply,
            $summary ?? ''
        );
    }

    /**
     * Check if a message references a project that has API credentials configured.
     * Used to redirect document creation requests to DevAgent for fresh API data.
     */
    private function messageReferencesApiProject(string $body): bool
    {
        $projects = \App\Models\Project::whereIn('status', ['approved', 'in_progress', 'completed'])->get();

        foreach ($projects as $project) {
            // Check if project name appears in message
            if (mb_stripos($body, $project->name) === false) continue;

            // Check if project has API config
            $settings = $project->settings ?? [];
            $hasToken = (bool) collect($settings)->keys()->first(fn($k) => str_contains($k, 'token') || str_contains($k, 'api_key'));

            if ($hasToken) return true;
        }

        return false;
    }

    /**
     * Check if the current session's active project has API credentials.
     */
    private function sessionHasApiProject($session): bool
    {
        $projectId = $session->active_project_id ?? null;
        if (!$projectId) return false;

        $project = \App\Models\Project::find($projectId);
        if (!$project) return false;

        $settings = $project->settings ?? [];
        return (bool) collect($settings)->keys()->first(fn($k) => str_contains($k, 'token') || str_contains($k, 'api_key'));
    }
}

