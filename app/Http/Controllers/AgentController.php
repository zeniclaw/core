<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentLog;
use Illuminate\Http\Request;

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
        $validModels = ['default', 'claude-haiku-4-5', 'claude-sonnet-4-5', 'claude-opus-4-5', 'qwen2.5:7b', 'qwen2.5-coder:7b'];
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
}
