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
            'description' => 'Conversations générales et réponses contextuelles',
        ],
        'dev' => [
            'label' => 'DevAgent',
            'icon' => '💻',
            'color' => 'purple',
            'description' => 'Assistance développement, code et GitLab',
        ],
        'reminder' => [
            'label' => 'ReminderAgent',
            'icon' => '⏰',
            'color' => 'orange',
            'description' => 'Gestion des rappels et notifications',
        ],
        'project' => [
            'label' => 'ProjectAgent',
            'icon' => '📋',
            'color' => 'green',
            'description' => 'Gestion de projets et suivi des tâches',
        ],
        'analysis' => [
            'label' => 'AnalysisAgent',
            'icon' => '📊',
            'color' => 'red',
            'description' => 'Analyse de données et rapports',
        ],
        'todo' => [
            'label' => 'TodoAgent',
            'icon' => '✅',
            'color' => 'teal',
            'description' => 'Gestion de checklist et to-do list',
        ],
        'smart_context' => [
            'label' => 'SmartContextAgent',
            'icon' => '🧠',
            'color' => 'blue',
            'description' => 'Memorisation intelligente du contexte utilisateur',
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

    public function destroy(Request $request, Agent $agent)
    {
        $this->authorize('delete', $agent);
        $agent->delete();
        return redirect()->route('agents.index')->with('success', 'Agent deleted.');
    }
}
