<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\Workflow;
use App\Services\AgentContext;
use App\Services\AgentOrchestrator;
use App\Services\WorkflowExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WorkflowController extends Controller
{
    /**
     * List all workflows for the authenticated user's agents.
     */
    public function index(Request $request)
    {
        $agentIds = $request->user()->agents()->pluck('id');
        $workflows = Workflow::whereIn('agent_id', $agentIds)
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('workflows.index', compact('workflows'));
    }

    /**
     * Show a single workflow.
     */
    public function show(Request $request, Workflow $workflow)
    {
        $this->authorizeWorkflow($request, $workflow);

        return view('workflows.show', compact('workflow'));
    }

    /**
     * Create a new workflow via web form.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'steps' => 'required|array|min:1',
            'steps.*.message' => 'required|string',
            'steps.*.agent' => 'nullable|string',
            'steps.*.condition' => 'nullable|string',
            'steps.*.on_error' => 'nullable|in:stop,continue',
            'triggers' => 'nullable|array',
            'conditions' => 'nullable|array',
        ]);

        $agent = $request->user()->agents()->findOrFail($validated['agent_id']);

        $workflow = Workflow::create([
            'user_phone' => $request->input('user_phone', 'web-' . $request->user()->id),
            'agent_id' => $agent->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'steps' => $validated['steps'],
            'triggers' => $validated['triggers'] ?? null,
            'conditions' => $validated['conditions'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->route('workflows.index')
            ->with('success', "Workflow \"{$workflow->name}\" cree avec " . count($workflow->steps) . " etapes.");
    }

    /**
     * Delete a workflow.
     */
    public function destroy(Request $request, Workflow $workflow)
    {
        $this->authorizeWorkflow($request, $workflow);

        $name = $workflow->name;
        $workflow->delete();

        return redirect()->route('workflows.index')
            ->with('success', "Workflow \"{$name}\" supprime.");
    }

    /**
     * Trigger a workflow execution.
     */
    public function trigger(Request $request, Workflow $workflow)
    {
        $this->authorizeWorkflow($request, $workflow);

        $result = $this->executeWorkflow($request, $workflow);

        return redirect()->route('workflows.show', $workflow)
            ->with('success', "Workflow execute: {$result['status']}")
            ->with('execution_result', $result);
    }

    /**
     * Toggle workflow active status.
     */
    public function toggle(Request $request, Workflow $workflow)
    {
        $this->authorizeWorkflow($request, $workflow);

        $workflow->update(['is_active' => !$workflow->is_active]);
        $status = $workflow->is_active ? 'active' : 'inactif';

        return back()->with('success', "Workflow \"{$workflow->name}\" est maintenant {$status}.");
    }

    // ── API Endpoints ────────────────────────────────────────────────────────

    /**
     * API: List workflows.
     */
    public function apiList(Request $request): JsonResponse
    {
        $agentIds = $request->user()->agents()->pluck('id');
        $workflows = Workflow::whereIn('agent_id', $agentIds)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'ok' => true,
            'workflows' => $workflows->map(fn (Workflow $wf) => [
                'id' => $wf->id,
                'name' => $wf->name,
                'description' => $wf->description,
                'steps_count' => count($wf->steps ?? []),
                'is_active' => $wf->is_active,
                'run_count' => $wf->run_count,
                'last_run_at' => $wf->last_run_at?->toIso8601String(),
                'created_at' => $wf->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * API: Create a workflow.
     */
    public function apiStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'steps' => 'required|array|min:1',
            'steps.*.message' => 'required|string',
            'steps.*.agent' => 'nullable|string',
            'steps.*.condition' => 'nullable|string',
            'steps.*.on_error' => 'nullable|in:stop,continue',
        ]);

        $agent = $request->user()->agents()->findOrFail($validated['agent_id']);

        $workflow = Workflow::create([
            'user_phone' => 'web-' . $request->user()->id,
            'agent_id' => $agent->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'steps' => $validated['steps'],
            'is_active' => true,
        ]);

        return response()->json([
            'ok' => true,
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'steps_count' => count($workflow->steps),
            ],
        ], 201);
    }

    /**
     * API: Trigger a workflow.
     */
    public function apiTrigger(Request $request, Workflow $workflow): JsonResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        $result = $this->executeWorkflow($request, $workflow);

        return response()->json([
            'ok' => true,
            'execution' => $result,
        ]);
    }

    /**
     * API: Delete a workflow.
     */
    public function apiDestroy(Request $request, Workflow $workflow): JsonResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        $workflow->delete();

        return response()->json(['ok' => true, 'deleted' => true]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function authorizeWorkflow(Request $request, Workflow $workflow): void
    {
        $agentIds = $request->user()->agents()->pluck('id')->toArray();
        abort_unless(in_array($workflow->agent_id, $agentIds), 403);
    }

    private function executeWorkflow(Request $request, Workflow $workflow): array
    {
        $agent = Agent::findOrFail($workflow->agent_id);

        $peerId = 'web-' . $request->user()->id;
        $sessionKey = AgentSession::keyFor($agent->id, 'web', $peerId);
        $session = AgentSession::firstOrCreate(
            ['session_key' => $sessionKey],
            [
                'agent_id' => $agent->id,
                'channel' => 'web',
                'peer_id' => $peerId,
                'last_message_at' => now(),
            ]
        );

        $context = new AgentContext(
            agent: $agent,
            session: $session,
            from: $workflow->user_phone,
            senderName: $request->user()->name,
            body: "workflow trigger {$workflow->name}",
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
        );

        $orchestrator = new AgentOrchestrator();
        $executor = new WorkflowExecutor($orchestrator);

        return $executor->execute($workflow, $context);
    }
}
