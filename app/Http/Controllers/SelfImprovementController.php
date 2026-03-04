<?php

namespace App\Http\Controllers;

use App\Jobs\RunSelfImprovementJob;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SelfImprovement;
use App\Models\SubAgent;
use Illuminate\Http\Request;

class SelfImprovementController extends Controller
{
    public function index(Request $request)
    {
        $query = SelfImprovement::with('agent')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $improvements = $query->paginate(20);

        return view('improvements.index', compact('improvements'));
    }

    public function show(SelfImprovement $improvement)
    {
        $improvement->load(['agent', 'subAgent']);

        return view('improvements.show', compact('improvement'));
    }

    public function approve(Request $request, SelfImprovement $improvement)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);
        abort_unless($improvement->status === 'pending', 422, 'Cette amelioration ne peut plus etre approuvee.');

        // Create project + SubAgent upfront so they survive restarts
        $project = $this->getOrCreateZeniclawProject();
        $defaultTimeout = (int) (AppSetting::get('subagent_default_timeout') ?: 10);

        $subAgent = SubAgent::create([
            'project_id' => $project->id,
            'status' => 'queued',
            'task_description' => "Auto-amelioration: {$improvement->improvement_title}\n\n{$improvement->development_plan}",
            'timeout_minutes' => $defaultTimeout,
        ]);

        $improvement->update([
            'status' => 'in_progress',
            'sub_agent_id' => $subAgent->id,
        ]);

        RunSelfImprovementJob::dispatch($improvement, $subAgent);

        return redirect()->route('improvements.show', $improvement)
            ->with('success', 'Amelioration approuvee. SubAgent #' . $subAgent->id . ' lance.');
    }

    public function reject(Request $request, SelfImprovement $improvement)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);
        abort_unless($improvement->status === 'pending', 422, 'Cette amelioration ne peut plus etre rejetee.');

        $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $improvement->update([
            'status' => 'rejected',
            'admin_notes' => $request->admin_notes,
        ]);

        return redirect()->route('improvements.show', $improvement)
            ->with('success', 'Amelioration rejetee.');
    }

    public function update(Request $request, SelfImprovement $improvement)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);
        abort_unless($improvement->status === 'pending', 422, 'Cette amelioration ne peut plus etre modifiee.');

        $request->validate([
            'development_plan' => 'nullable|string|max:10000',
            'admin_notes' => 'nullable|string|max:1000',
            'improvement_title' => 'nullable|string|max:255',
        ]);

        $improvement->update(array_filter([
            'development_plan' => $request->development_plan,
            'admin_notes' => $request->admin_notes,
            'improvement_title' => $request->improvement_title,
        ], fn($v) => $v !== null));

        return redirect()->route('improvements.show', $improvement)
            ->with('success', 'Amelioration mise a jour.');
    }

    private function getOrCreateZeniclawProject(): Project
    {
        $projectId = AppSetting::get('zeniclaw_project_id');
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) return $project;
        }

        $project = Project::where('name', 'ZeniClaw (Auto-Improve)')->first();
        if ($project) return $project;

        $project = Project::create([
            'name' => 'ZeniClaw (Auto-Improve)',
            'gitlab_url' => 'https://gitlab.com/zenidev/zeniclaw.git',
            'request_description' => 'Projet auto-genere pour les auto-ameliorations de ZeniClaw.',
            'requester_phone' => 'system',
            'requester_name' => 'ZeniClaw Auto-Improve',
            'agent_id' => Agent::first()?->id ?? 1,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        AppSetting::set('zeniclaw_project_id', (string) $project->id);

        return $project;
    }
}
