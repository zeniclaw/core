<?php

namespace App\Http\Controllers;

use App\Jobs\RunSubAgentJob;
use App\Models\AppSetting;
use App\Models\SubAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SubAgentController extends Controller
{
    public function index()
    {
        $subAgents = SubAgent::with('project')
            ->orderByDesc('created_at')
            ->paginate(20);

        $defaultTimeout = (int) (AppSetting::get('subagent_default_timeout') ?: 10);

        return view('subagents.index', compact('subAgents', 'defaultTimeout'));
    }

    public function show(SubAgent $subAgent)
    {
        $subAgent->load('project');

        return view('subagents.show', compact('subAgent'));
    }

    /**
     * JSON endpoint for polling the output log (used by Alpine.js).
     */
    public function output(SubAgent $subAgent): JsonResponse
    {
        return response()->json([
            'status' => $subAgent->status,
            'output_log' => $subAgent->output_log ?? '',
            'api_calls_count' => $subAgent->api_calls_count,
            'branch_name' => $subAgent->branch_name,
            'commit_hash' => $subAgent->commit_hash,
            'error_message' => $subAgent->error_message,
        ]);
    }

    /**
     * Kill a running SubAgent and its associated processes.
     */
    public function kill(Request $request, SubAgent $subAgent)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);
        abort_unless(in_array($subAgent->status, ['running', 'queued']), 422, 'Ce SubAgent ne peut pas etre arrete.');

        $pid = $subAgent->pid;

        // Mark as killed first (the job checks this)
        $subAgent->update([
            'status' => 'killed',
            'error_message' => "Arrete manuellement par {$user->name}",
            'completed_at' => now(),
        ]);
        $subAgent->appendLog("[KILL] Arret demande par {$user->name}");

        // Kill process tree if PID is known
        if ($pid) {
            try {
                // Try to kill the process group, then individual PID
                Process::run("kill -TERM -{$pid} 2>/dev/null; kill -TERM {$pid} 2>/dev/null");
                usleep(500_000);
                Process::run("kill -KILL -{$pid} 2>/dev/null; kill -KILL {$pid} 2>/dev/null");
                $subAgent->appendLog("[KILL] Process {$pid} termine");
            } catch (\Throwable $e) {
                Log::warning("Failed to kill process {$pid}: " . $e->getMessage());
            }
            $subAgent->update(['pid' => null]);
        }

        // Clean up workspace if it exists
        $workspace = storage_path("app/subagent-workspaces/{$subAgent->id}");
        if (is_dir($workspace)) {
            Process::run("rm -rf " . escapeshellarg($workspace));
            $subAgent->appendLog("[CLEANUP] Workspace supprime");
        }

        // Update project status
        $subAgent->project->update(['status' => 'failed']);

        return redirect()->back()->with('success', "SubAgent #{$subAgent->id} arrete.");
    }

    /**
     * Retry a failed/killed SubAgent by creating a new one with the same task.
     */
    public function retry(Request $request, SubAgent $subAgent)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);
        abort_unless(in_array($subAgent->status, ['failed', 'killed']), 422, 'Seuls les SubAgents echoues ou arretes peuvent etre relances.');

        $newSubAgent = SubAgent::create([
            'project_id' => $subAgent->project_id,
            'task_description' => $subAgent->task_description,
            'model' => $subAgent->model ?? 'opus',
            'timeout_minutes' => $subAgent->timeout_minutes ?? (int) (AppSetting::get('subagent_default_timeout') ?: 10),
            'status' => 'queued',
            'branch_name' => $subAgent->branch_name,
        ]);

        $subAgent->project->update(['status' => 'in_progress']);

        RunSubAgentJob::dispatch($newSubAgent);

        return redirect()->route('subagents.show', $newSubAgent)
            ->with('success', "SubAgent #{$newSubAgent->id} relance (copie de #{$subAgent->id}).");
    }

    /**
     * Relaunch a SubAgent with a custom prompt on the same project.
     */
    public function relaunch(Request $request, SubAgent $subAgent)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);
        abort_unless(in_array($subAgent->status, ['completed', 'failed', 'killed']), 422);

        $request->validate(['prompt' => 'required|string|min:5']);

        $newSubAgent = SubAgent::create([
            'project_id' => $subAgent->project_id,
            'task_description' => $request->prompt,
            'model' => $subAgent->model ?? 'opus',
            'timeout_minutes' => $subAgent->timeout_minutes ?? (int) (AppSetting::get('subagent_default_timeout') ?: 10),
            'status' => 'queued',
            'branch_name' => $subAgent->branch_name,
        ]);

        $subAgent->project->update(['status' => 'in_progress']);

        RunSubAgentJob::dispatch($newSubAgent);

        return redirect()->route('subagents.show', $newSubAgent)
            ->with('success', "SubAgent #{$newSubAgent->id} lance sur {$subAgent->project->name}.");
    }

    /**
     * Update default timeout setting.
     */
    public function updateDefaultTimeout(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);

        $request->validate(['timeout_minutes' => 'required|integer|min:1|max:120']);

        AppSetting::set('subagent_default_timeout', (string) $request->timeout_minutes);

        return redirect()->route('subagents.index')
            ->with('success', "Timeout par defaut mis a jour: {$request->timeout_minutes} min.");
    }
}
