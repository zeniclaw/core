<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\UserBriefPreference;
use App\Models\UserAgentAnalytic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class AgentController extends Controller
{
    private const SUB_AGENTS = [
        'chat' => [
            'label' => 'ChatAgent',
            'icon' => '💬',
            'color' => 'blue',
            'version' => '2.3.0',
            'updated_at' => '2026-02-15',
            'description' => 'Conversations générales et réponses contextuelles',
        ],
        'dev' => [
            'label' => 'DevAgent',
            'icon' => '💻',
            'color' => 'purple',
            'version' => '2.5.0',
            'updated_at' => '2026-03-01',
            'description' => 'Assistance développement, code et GitLab',
        ],
        'reminder' => [
            'label' => 'ReminderAgent',
            'icon' => '⏰',
            'color' => 'orange',
            'version' => '1.4.0',
            'updated_at' => '2026-01-20',
            'description' => 'Gestion des rappels et notifications',
        ],
        'project' => [
            'label' => 'ProjectAgent',
            'icon' => '📋',
            'color' => 'green',
            'version' => '2.1.0',
            'updated_at' => '2026-02-10',
            'description' => 'Gestion de projets et suivi des tâches',
        ],
        'analysis' => [
            'label' => 'AnalysisAgent',
            'icon' => '📊',
            'color' => 'red',
            'version' => '1.2.0',
            'updated_at' => '2026-01-15',
            'description' => 'Analyse de données et rapports',
        ],
        'todo' => [
            'label' => 'TodoAgent',
            'icon' => '✅',
            'color' => 'teal',
            'version' => '1.3.0',
            'updated_at' => '2026-02-05',
            'description' => 'Gestion de checklist et to-do list',
        ],
        'smart_context' => [
            'label' => 'SmartContextAgent',
            'icon' => '🧠',
            'color' => 'blue',
            'version' => '2.0.0',
            'updated_at' => '2026-02-20',
            'description' => 'Memorisation intelligente du contexte utilisateur',
        ],
        'mood_check' => [
            'label' => 'Mood Check',
            'icon' => '😊',
            'color' => 'pink',
            'version' => '1.0.0',
            'updated_at' => '2026-01-10',
            'description' => 'Suivi emotionnel & recommandations bien-etre',
        ],
        'finance' => [
            'label' => 'Finance',
            'icon' => '💰',
            'color' => 'green',
            'version' => '1.1.0',
            'updated_at' => '2026-01-25',
            'description' => 'Suivi budgets, depenses & alertes financieres',
        ],
        'smart_meeting' => [
            'label' => 'Smart Meeting',
            'icon' => '📋',
            'color' => 'indigo',
            'version' => '1.0.0',
            'updated_at' => '2025-12-20',
            'description' => 'Capture et synthese auto de reunions',
        ],
        'hangman' => [
            'label' => 'Hangman Game',
            'icon' => '🎮',
            'color' => 'purple',
            'version' => '1.1.0',
            'updated_at' => '2026-01-05',
            'description' => 'Jeu du pendu interactif avec stats',
        ],
        'flashcard' => [
            'label' => 'Flashcards',
            'icon' => '📚',
            'color' => 'indigo',
            'version' => '1.2.0',
            'updated_at' => '2026-02-01',
            'description' => 'Apprentissage adaptatif avec repetition espacee',
        ],
        'voice_command' => [
            'label' => 'Voice Commands',
            'icon' => '🎤',
            'color' => 'cyan',
            'version' => '1.0.0',
            'updated_at' => '2025-12-15',
            'description' => 'Transcribe & execute audio commands',
        ],
        'code_review' => [
            'label' => 'Code Review',
            'icon' => '🔍',
            'color' => 'blue',
            'version' => '1.3.0',
            'updated_at' => '2026-02-25',
            'description' => 'Analyse de code, bugs, securite et optimisations',
        ],
        'screenshot' => [
            'label' => 'Screenshot & Annotate',
            'icon' => '📸',
            'color' => 'cyan',
            'version' => '1.0.0',
            'updated_at' => '2025-12-10',
            'description' => 'Capture, extract & annotate images',
        ],
        'content_summarizer' => [
            'label' => 'Resume Contenu',
            'icon' => '📰',
            'color' => 'cyan',
            'version' => '1.0.0',
            'updated_at' => '2026-03-08',
            'description' => 'Resume automatique d\'articles, pages web et videos YouTube avec transcription',
        ],
        'event_reminder' => [
            'label' => 'Event Reminder',
            'icon' => '📅',
            'color' => 'purple',
            'version' => '1.2.0',
            'updated_at' => '2026-02-08',
            'description' => 'Evenements intelligents avec rappels contextuels',
        ],
        'habit' => [
            'label' => 'HabitAgent',
            'icon' => '🎯',
            'color' => 'green',
            'version' => '1.0.0',
            'updated_at' => '2026-02-18',
            'description' => 'Suivi des habitudes quotidiennes, streaks et statistiques',
        ],
        'music' => [
            'label' => 'Music Agent',
            'icon' => '🎵',
            'color' => 'pink',
            'version' => '1.0.0',
            'updated_at' => '2026-03-03',
            'description' => 'Découvrez et gérez vos playlists musicales',
        ],
        'pomodoro' => [
            'label' => 'Pomodoro Timer',
            'icon' => '🍅',
            'color' => 'red',
            'version' => '1.0.0',
            'updated_at' => '2026-03-05',
            'description' => 'Focus sessions avec minuteur et stats',
        ],
        'document' => [
            'label' => 'Document Creator',
            'icon' => '📄',
            'color' => 'blue',
            'version' => '1.0.0',
            'updated_at' => '2026-03-06',
            'description' => 'Creation de fichiers Excel, PDF et Word',
        ],
        'user_preferences' => [
            'label' => 'Mes Preferences',
            'icon' => '⚙️',
            'color' => 'indigo',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Langue, fuseau horaire, format, style communication',
        ],
        'conversation_memory' => [
            'label' => 'Memory',
            'icon' => '🧠',
            'color' => 'indigo',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Memorise contexte et historique pour continuite',
        ],
        'streamline' => [
            'label' => 'Streamline',
            'icon' => '⚙️',
            'color' => 'indigo',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Chainer et automatiser des workflows multi-agents',
        ],
        'interactive_quiz' => [
            'label' => 'Quiz Interactif',
            'icon' => '🎯',
            'color' => 'purple',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Quizz ludiques avec scoring et défis',
        ],
        'content_curator' => [
            'label' => 'Content Curator',
            'icon' => '📰',
            'color' => 'indigo',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Agregez et organisez l\'actualite selon vos interets',
        ],
        'context_memory_bridge' => [
            'label' => 'Context Memory',
            'icon' => '🧠',
            'color' => 'indigo',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Memoire intelligente partagee inter-agents',
        ],
        'game_master' => [
            'label' => 'GameMaster',
            'icon' => '🎮',
            'color' => 'purple',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Jeux interactifs, trivia, enigmes avec scoring',
        ],
        'budget_tracker' => [
            'label' => 'Budget Tracker',
            'icon' => '💰',
            'color' => 'amber',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Suivi intelligent des depenses et budgets',
        ],
        'daily_brief' => [
            'label' => 'Daily Brief',
            'icon' => '🌅',
            'color' => 'amber',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Resume personnalise du jour',
        ],
        'collaborative_task' => [
            'label' => 'Votes Equipe',
            'icon' => '🗳️',
            'color' => 'purple',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Votez sur des taches, decidez en groupe',
        ],
        'recipe' => [
            'label' => 'Recipe AI',
            'icon' => '👨‍🍳',
            'color' => 'orange',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Suggestions de recettes par ingredients, regime, temps',
        ],
        'time_blocker' => [
            'label' => 'Time Blocker',
            'icon' => '⏰',
            'color' => 'indigo',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Optimisation intelligente de votre agenda par blocs de temps',
        ],
        'assistant' => [
            'label' => 'AI Assistant',
            'icon' => '🤖',
            'color' => 'purple',
            'version' => '1.0.0',
            'updated_at' => '2026-03-09',
            'description' => 'Coaching personnalise & suggestions intelligentes',
        ],
    ];

    public function index(Request $request)
    {
        $agents = $request->user()->agents()->latest()->paginate(15);

        // Build sub-agent stats per agent
        $subAgentData = [];
        foreach ($agents as $agent) {
            $routingContexts = $agent->logs()
                ->where('message', 'Router decision')
                ->pluck('context');

            $counts = $routingContexts->countBy(fn ($ctx) => $ctx['routing']['agent'] ?? 'unknown');

            $lastActivity = [];
            foreach (array_keys(self::SUB_AGENTS) as $key) {
                $lastLog = $agent->logs()
                    ->where(function ($q) use ($key) {
                        $q->where('message', 'like', "[{$key}]%")
                          ->orWhere(function ($q2) use ($key) {
                              $q2->where('message', 'Router decision')
                                 ->whereJsonContains('context->routing->agent', $key);
                          });
                    })
                    ->latest('created_at')
                    ->first();
                $lastActivity[$key] = $lastLog?->created_at;
            }

            $subAgentData[$agent->id] = [
                'counts' => $counts,
                'lastActivity' => $lastActivity,
            ];
        }

        $subAgentMeta = self::SUB_AGENTS;

        return view('agents.index', compact('agents', 'subAgentData', 'subAgentMeta'));
    }

    public function create()
    {
        return view('agents.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'system_prompt' => 'nullable|string',
            'model' => 'required|string',
            'status' => 'required|in:active,inactive',
        ]);

        $request->user()->agents()->create($validated);

        return redirect()->route('agents.index')->with('success', 'Agent created successfully.');
    }

    public function show(Request $request, Agent $agent)
    {
        $this->authorize('view', $agent);
        $logs = $agent->logs()->latest('created_at')->take(20)->get();
        $reminders = $agent->reminders()->latest()->take(10)->get();
        $secrets = $agent->secrets()->get();
        $memories = $agent->memory()->orderByDesc('date')->get();
        $sessions = $agent->sessions()->orderByDesc('last_message_at')->get();

        // Orchestrator data from Router decision logs
        $routingLogs = $agent->logs()
            ->where('message', 'Router decision')
            ->latest('created_at')
            ->take(50)
            ->get();

        $routingHistory = $routingLogs->map(function ($log) {
            $ctx = $log->context ?? [];
            $routing = $ctx['routing'] ?? [];
            return (object) [
                'created_at' => $log->created_at,
                'body' => $ctx['body'] ?? '',
                'agent' => $routing['agent'] ?? '—',
                'model' => $routing['model'] ?? '—',
                'complexity' => $routing['complexity'] ?? '—',
                'reasoning' => $routing['reasoning'] ?? '',
            ];
        });

        $allRoutingContexts = $agent->logs()
            ->where('message', 'Router decision')
            ->pluck('context');

        $agentStats = $allRoutingContexts->countBy(fn ($ctx) => $ctx['routing']['agent'] ?? 'unknown');
        $modelStats = $allRoutingContexts->countBy(fn ($ctx) => $ctx['routing']['model'] ?? 'unknown');
        $complexityStats = $allRoutingContexts->countBy(fn ($ctx) => $ctx['routing']['complexity'] ?? 'unknown');
        $totalRouted = $allRoutingContexts->count();

        return view('agents.show', compact(
            'agent', 'logs', 'reminders', 'secrets', 'memories', 'sessions',
            'routingHistory', 'agentStats', 'modelStats', 'complexityStats', 'totalRouted'
        ));
    }

    public function showSubAgent(Request $request, Agent $agent, string $subAgent)
    {
        $this->authorize('view', $agent);

        $meta = self::SUB_AGENTS[$subAgent];

        // Routing decisions for this sub-agent
        $allRouting = $agent->logs()
            ->where('message', 'Router decision')
            ->latest('created_at')
            ->get()
            ->filter(fn ($log) => ($log->context['routing']['agent'] ?? null) === $subAgent);

        $totalRouted = $allRouting->count();

        $routingHistory = $allRouting->take(50)->map(function ($log) {
            $ctx = $log->context ?? [];
            $routing = $ctx['routing'] ?? [];
            return (object) [
                'created_at' => $log->created_at,
                'body' => $ctx['body'] ?? '',
                'model' => $routing['model'] ?? '—',
                'complexity' => $routing['complexity'] ?? '—',
                'reasoning' => $routing['reasoning'] ?? '',
            ];
        });

        $modelStats = $allRouting->countBy(fn ($log) => $log->context['routing']['model'] ?? 'unknown');
        $complexityStats = $allRouting->countBy(fn ($log) => $log->context['routing']['complexity'] ?? 'unknown');

        // Agent-specific logs (prefixed with [$subAgent])
        $agentLogs = $agent->logs()
            ->where('message', 'like', "[{$subAgent}]%")
            ->latest('created_at')
            ->take(50)
            ->get();

        return view('agents.sub-agent', compact(
            'agent', 'subAgent', 'meta',
            'totalRouted', 'routingHistory', 'modelStats', 'complexityStats',
            'agentLogs'
        ));
    }

    public function edit(Request $request, Agent $agent)
    {
        $this->authorize('update', $agent);
        return view('agents.edit', compact('agent'));
    }

    public function update(Request $request, Agent $agent)
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'system_prompt' => 'nullable|string',
            'model' => 'required|string',
            'status' => 'required|in:active,inactive',
        ]);

        $agent->update($validated);

        return redirect()->route('agents.index')->with('success', 'Agent updated successfully.');
    }

    public function toggleWhitelist(Request $request, Agent $agent)
    {
        $this->authorize('update', $agent);
        $agent->update(['whitelist_enabled' => !$agent->whitelist_enabled]);

        $status = $agent->whitelist_enabled ? 'activée' : 'désactivée';
        return back()->with('success', "Whitelist {$status}.");
    }

    public function updateSubAgentModels(Request $request, Agent $agent)
    {
        $this->authorize('update', $agent);

        $models = $request->input('sub_agent_models', []);

        // Validate model values
        $validModels = ['default', 'claude-haiku-4-5', 'claude-sonnet-4-5', 'claude-opus-4-5',
            'qwen2.5:3b', 'qwen2.5:7b', 'qwen2.5:14b', 'qwen2.5-coder:7b',
            'llama3.2:3b', 'gemma2:2b', 'phi3:mini', 'deepseek-coder-v2:16b'];
        $filtered = [];
        foreach ($models as $key => $model) {
            if (array_key_exists($key, self::SUB_AGENTS) && in_array($model, $validModels)) {
                if ($model !== 'default') {
                    $filtered[$key] = $model;
                }
            }
        }

        $agent->update(['sub_agent_models' => $filtered ?: null]);

        return back()->with('success', 'Modeles des sub-agents mis a jour.');
    }

    public function destroy(Request $request, Agent $agent)
    {
        $this->authorize('delete', $agent);
        $agent->delete();
        return redirect()->route('agents.index')->with('success', 'Agent deleted.');
    }

    /**
     * GET /api/context/{userId} — Debug endpoint for ContextMemoryBridge.
     */
    public function showContext(Request $request, string $userId)
    {
        $bridge = \App\Services\ContextMemoryBridge::getInstance();

        return response()->json([
            'userId' => $userId,
            'context' => $bridge->getContext($userId),
            'hasContext' => $bridge->hasContext($userId),
        ]);
    }

    /**
     * GET /api/brief-preferences/{phone}
     */
    public function getBriefPreferences(Request $request, string $phone)
    {
        $pref = UserBriefPreference::where('user_phone', $phone)->first();

        if (!$pref) {
            return response()->json([
                'user_phone' => $phone,
                'brief_time' => '07:00',
                'enabled' => false,
                'preferred_sections' => ['reminders', 'tasks', 'weather', 'news', 'quote'],
            ]);
        }

        return response()->json($pref);
    }

    /**
     * POST /api/agents/time-blocker/apply-block
     * Persist accepted time blocks in Redis and sync with ReminderAgent.
     */
    public function applyTimeBlock(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'blocks' => 'required|array',
            'blocks.*.start' => 'required|string|regex:/^\d{2}:\d{2}$/',
            'blocks.*.end' => 'required|string|regex:/^\d{2}:\d{2}$/',
            'blocks.*.type' => 'required|string|in:focus,pause,reunion,admin,dejeuner,sport',
            'blocks.*.label' => 'required|string|max:255',
        ]);

        $phone = $validated['phone'];
        $blocks = $validated['blocks'];

        // Store blocks in Redis with end-of-day expiration
        $ttl = now()->endOfDay()->diffInSeconds(now());
        $redisKey = "time_blocks:{$phone}";
        Redis::setex($redisKey, max($ttl, 60), json_encode($blocks));

        // Create reminders for actionable blocks
        $createdReminders = 0;
        $agent = $request->user()?->agents()->first();

        if ($agent) {
            foreach ($blocks as $block) {
                if (in_array($block['type'], ['focus', 'reunion', 'admin'])) {
                    try {
                        $startTime = now()->setTimeFromTimeString($block['start']);
                        if ($startTime->isFuture()) {
                            $emoji = match ($block['type']) {
                                'focus' => '🎯',
                                'reunion' => '📞',
                                'admin' => '📧',
                                default => '⏰',
                            };
                            \App\Models\Reminder::create([
                                'requester_phone' => $phone,
                                'agent_id' => $agent->id,
                                'message' => "{$emoji} Bloc: {$block['label']} ({$block['start']} - {$block['end']})",
                                'scheduled_at' => $startTime->setTimezone('UTC'),
                                'status' => 'pending',
                            ]);
                            $createdReminders++;
                        }
                    } catch (\Throwable $e) {
                        // Skip invalid blocks
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'blocks_stored' => count($blocks),
            'reminders_created' => $createdReminders,
        ]);
    }

    /**
     * GET /api/agents/stats — User dashboard: most used agents, avg time, adoption score, recommendations.
     */
    public function agentStats(Request $request)
    {
        $phone = $request->query('phone');

        if (!$phone) {
            return response()->json(['error' => 'phone parameter required'], 422);
        }

        $analytics = UserAgentAnalytic::where('user_id', $phone)->get();

        if ($analytics->isEmpty()) {
            return response()->json([
                'total_interactions' => 0,
                'unique_agents' => 0,
                'adoption_score' => 0,
                'avg_duration_ms' => 0,
                'top_agents' => [],
                'recommendations' => ['Start using agents to see your stats!'],
            ]);
        }

        $total = $analytics->count();
        $agentCounts = $analytics->groupBy('agent_used')->map->count()->sortDesc();
        $uniqueAgents = $agentCounts->count();
        $totalAvailable = count(self::SUB_AGENTS);
        $adoptionScore = min(100, round(($uniqueAgents / max(1, $totalAvailable)) * 100));

        $avgDuration = (int) $analytics->whereNotNull('duration')->avg('duration');
        $successCount = $analytics->where('success', true)->count();
        $successRate = $total > 0 ? round(($successCount / $total) * 100) : 0;

        $topAgents = $agentCounts->take(10)->map(function ($count, $agent) use ($total, $analytics) {
            $agentAnalytics = $analytics->where('agent_used', $agent);
            return [
                'agent' => $agent,
                'count' => $count,
                'percentage' => round(($count / $total) * 100, 1),
                'avg_duration_ms' => (int) $agentAnalytics->whereNotNull('duration')->avg('duration'),
                'success_rate' => $agentAnalytics->count() > 0
                    ? round(($agentAnalytics->where('success', true)->count() / $agentAnalytics->count()) * 100)
                    : 0,
            ];
        })->values();

        // Build detailed upskilling recommendations based on usage patterns
        $recommendations = [];
        $usedAgentKeys = $agentCounts->keys()->toArray();

        // Pattern-based recommendations
        if (in_array('dev', $usedAgentKeys) && !in_array('code_review', $usedAgentKeys)) {
            $recommendations[] = [
                'agent' => 'code_review',
                'label' => 'Code Review',
                'reason' => 'Tu codes souvent — fais reviewer ton code pour detecter bugs et failles',
                'priority' => 'high',
            ];
        }
        if (in_array('todo', $usedAgentKeys) && !in_array('pomodoro', $usedAgentKeys)) {
            $recommendations[] = [
                'agent' => 'pomodoro',
                'label' => 'Pomodoro',
                'reason' => 'Combine tes taches avec des sessions focus pour etre plus productif',
                'priority' => 'medium',
            ];
        }
        if (in_array('reminder', $usedAgentKeys) && !in_array('habit', $usedAgentKeys)) {
            $recommendations[] = [
                'agent' => 'habit',
                'label' => 'HabitAgent',
                'reason' => 'Transforme tes rappels en habitudes durables avec des streaks',
                'priority' => 'medium',
            ];
        }

        // Add popular unused agents
        $popularUnused = array_diff(['todo', 'reminder', 'dev', 'pomodoro', 'habit', 'budget_tracker', 'time_blocker', 'daily_brief'], $usedAgentKeys);
        foreach (array_slice($popularUnused, 0, max(0, 3 - count($recommendations))) as $agent) {
            $meta = self::SUB_AGENTS[$agent] ?? null;
            if ($meta) {
                $recommendations[] = [
                    'agent' => $agent,
                    'label' => $meta['label'],
                    'reason' => $meta['description'],
                    'priority' => 'low',
                ];
            }
        }

        // Upskilling tips based on adoption score
        $upskillingTips = [];
        if ($adoptionScore < 30) {
            $upskillingTips[] = 'Explore plus d\'agents pour booster ton score d\'adoption!';
            $upskillingTips[] = 'Essaie "mes stats" dans le chat pour un coaching personnalise.';
        } elseif ($adoptionScore < 60) {
            $upskillingTips[] = 'Bonne progression! Essaie les agents de productivite comme Pomodoro et TimeBlocker.';
        } else {
            $upskillingTips[] = 'Excellent usage! Tu maitrises la plupart des agents.';
        }

        if ($successRate < 80 && $total > 10) {
            $upskillingTips[] = 'Ton taux de succes peut s\'ameliorer — essaie des commandes plus precises.';
        }

        return response()->json([
            'total_interactions' => $total,
            'unique_agents' => $uniqueAgents,
            'total_available' => $totalAvailable,
            'adoption_score' => $adoptionScore,
            'success_rate' => $successRate,
            'avg_duration_ms' => $avgDuration,
            'top_agents' => $topAgents,
            'recommendations' => $recommendations,
            'upskilling_tips' => $upskillingTips,
        ]);
    }

    /**
     * POST /api/brief-preferences/{phone}
     */
    public function updateBriefPreferences(Request $request, string $phone)
    {
        $validated = $request->validate([
            'brief_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'enabled' => 'sometimes|boolean',
            'preferred_sections' => 'sometimes|array',
            'preferred_sections.*' => 'string|in:reminders,tasks,weather,news,quote',
        ]);

        $pref = UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            $validated
        );

        return response()->json($pref);
    }

    /**
     * Skills marketplace — list all shared skills across agents (D12.3).
     * Skills taught by one agent can be exported/imported to others.
     */
    public function skillsMarketplace(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $agentId = $request->query('agent_id');

        // Get all skills for user's agents
        $query = \App\Models\AgentSkill::where('active', true);

        if ($agentId) {
            $query->where('agent_id', $agentId);
        } else {
            $agentIds = $user->agents()->pluck('id');
            $query->whereIn('agent_id', $agentIds);
        }

        $skills = $query->orderBy('sub_agent')->orderBy('title')->get();

        $grouped = $skills->groupBy('sub_agent')->map(function ($group, $subAgent) {
            return [
                'agent' => $subAgent,
                'skills' => $group->map(fn($s) => [
                    'id' => $s->id,
                    'skill_key' => $s->skill_key,
                    'title' => $s->title,
                    'instructions' => $s->instructions,
                    'examples' => $s->examples,
                    'taught_by' => $s->taught_by,
                    'created_at' => $s->created_at->toIso8601String(),
                ])->values(),
                'count' => $group->count(),
            ];
        })->values();

        return response()->json([
            'skills' => $grouped,
            'total' => $skills->count(),
        ]);
    }

    /**
     * Import a skill to a specific agent (D12.3).
     */
    public function importSkill(Request $request, \App\Models\Agent $agent): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'skill_key' => 'required|string',
            'title' => 'required|string',
            'instructions' => 'required|string',
            'sub_agent' => 'required|string',
            'examples' => 'nullable|array',
        ]);

        $skill = \App\Models\AgentSkill::updateOrCreate(
            [
                'agent_id' => $agent->id,
                'sub_agent' => $validated['sub_agent'],
                'skill_key' => $validated['skill_key'],
            ],
            [
                'title' => $validated['title'],
                'instructions' => $validated['instructions'],
                'examples' => $validated['examples'] ?? null,
                'taught_by' => 'marketplace',
                'active' => true,
            ]
        );

        return response()->json(['success' => true, 'skill' => $skill]);
    }
}
