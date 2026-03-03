<?php

namespace App\Http\Controllers;

use App\Jobs\RunSubAgentJob;
use App\Models\AgentSession;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SubAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::with(['agent', 'approver', 'latestSubAgent'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('projects.index', compact('projects'));
    }

    public function show(Project $project)
    {
        $project->load(['agent', 'approver', 'subAgents' => fn($q) => $q->orderByDesc('created_at')]);

        return view('projects.show', compact('project'));
    }

    public function create()
    {
        return view('projects.create');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);

        $request->validate([
            'gitlab_url' => 'required|url',
            'name' => 'required|string|max:255',
            'request_description' => 'nullable|string|max:2000',
            'allowed_phones' => 'nullable|array',
            'allowed_phones.*' => 'string',
            'notify_groups' => 'nullable|array',
            'notify_groups.*' => 'string',
        ]);

        $project = Project::create([
            'name' => $request->name,
            'gitlab_url' => $request->gitlab_url,
            'request_description' => $request->request_description ?? 'Projet cree manuellement depuis le dashboard.',
            'requester_phone' => $user->email,
            'requester_name' => $user->name,
            'allowed_phones' => $request->allowed_phones ?: null,
            'notify_groups' => $request->notify_groups ?: null,
            'agent_id' => \App\Models\Agent::first()?->id ?? 1,
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        // Notify allowed contacts via WhatsApp
        if ($project->allowed_phones) {
            $message = "[{$project->name}] Nouveau projet configure !\n"
                . "Vous pouvez maintenant m'envoyer des taches directement ici.\n"
                . "Je les traiterai automatiquement sur ce repo.";

            foreach ($project->allowed_phones as $phone) {
                try {
                    Http::timeout(10)
                        ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                        ->post('http://waha:3000/api/sendText', [
                            'chatId' => $phone,
                            'text' => $message,
                            'session' => 'default',
                        ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to notify {$phone} about new project: " . $e->getMessage());
                }
            }
        }

        // Notify selected WhatsApp groups
        if ($project->notify_groups) {
            $groupMessage = "[{$project->name}] Nouveau projet configure !\n"
                . "Les taches peuvent maintenant etre envoyees pour ce repo.";

            foreach ($project->notify_groups as $groupId) {
                try {
                    Http::timeout(10)
                        ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                        ->post('http://waha:3000/api/sendText', [
                            'chatId' => $groupId,
                            'text' => $groupMessage,
                            'session' => 'default',
                        ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to notify group {$groupId} about new project: " . $e->getMessage());
                }
            }
        }

        return redirect()->route('projects.show', $project)
            ->with('success', 'Projet cree et approuve.');
    }

    public function apiGitlabProjects(Request $request)
    {
        $token = AppSetting::get('gitlab_access_token');
        if (!$token) {
            return response()->json(['error' => 'GitLab token non configure'], 422);
        }

        $search = $request->query('q', '');

        // Determine GitLab host from existing projects or default to gitlab.com
        $gitlabHost = 'gitlab.com';
        $existingProject = Project::whereNotNull('gitlab_url')->first();
        if ($existingProject) {
            $parsed = parse_url($existingProject->gitlab_url);
            if (!empty($parsed['host'])) {
                $gitlabHost = $parsed['host'];
            }
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['PRIVATE-TOKEN' => $token])
                ->get("https://{$gitlabHost}/api/v4/projects", [
                    'membership' => 'true',
                    'per_page' => 50,
                    'order_by' => 'last_activity_at',
                    'search' => $search,
                ]);

            if (!$response->successful()) {
                return response()->json(['error' => 'GitLab API error: ' . $response->status()], 502);
            }

            $projects = collect($response->json())->map(fn($p) => [
                'id' => $p['id'],
                'name' => $p['name'],
                'path_with_namespace' => $p['path_with_namespace'],
                'web_url' => $p['web_url'],
            ]);

            return response()->json($projects);
        } catch (\Exception $e) {
            Log::warning('GitLab API error: ' . $e->getMessage());
            return response()->json(['error' => 'Impossible de contacter GitLab'], 502);
        }
    }

    public function apiContacts()
    {
        $sessions = AgentSession::where('channel', 'whatsapp')
            ->where('peer_id', '!=', 'status@broadcast')
            ->where('peer_id', 'NOT LIKE', '%@g.us')
            ->orderByDesc('last_message_at')
            ->get();

        // Build pushName lookup from logs
        $pushNames = [];
        $logs = \App\Models\AgentLog::where('message', 'WhatsApp message received')
            ->orderByDesc('id')->limit(500)->get(['context']);
        foreach ($logs as $log) {
            $payload = $log->context['payload'] ?? [];
            $from = $payload['from'] ?? null;
            $pushName = $payload['_data']['pushName'] ?? null;
            if ($from && $pushName && !isset($pushNames[$from])) {
                $pushNames[$from] = $pushName;
            }
        }

        $contacts = $sessions->map(function ($session) use ($pushNames) {
            $peerId = $session->peer_id;

            $name = Project::where('requester_phone', $peerId)->value('requester_name');
            if (!$name) {
                $name = $pushNames[$peerId] ?? $session->displayName();
            }

            return [
                'peer_id' => $peerId,
                'name' => $name,
                'message_count' => $session->message_count,
                'last_message_at' => $session->last_message_at?->toIso8601String(),
            ];
        });

        return response()->json($contacts->values());
    }

    public function apiGroups()
    {
        $sessions = AgentSession::where('channel', 'whatsapp')
            ->where('peer_id', 'LIKE', '%@g.us')
            ->orderByDesc('last_message_at')
            ->get();

        // Build pushName lookup from logs
        $pushNames = [];
        $logs = \App\Models\AgentLog::where('message', 'WhatsApp message received')
            ->orderByDesc('id')->limit(500)->get(['context']);
        foreach ($logs as $log) {
            $payload = $log->context['payload'] ?? [];
            $from = $payload['from'] ?? null;
            $pushName = $payload['_data']['pushName'] ?? null;
            if ($from && $pushName && !isset($pushNames[$from])) {
                $pushNames[$from] = $pushName;
            }
        }

        $groups = $sessions->map(function ($session) use ($pushNames) {
            $peerId = $session->peer_id;
            $name = $pushNames[$peerId] ?? $session->displayName();

            return [
                'peer_id' => $peerId,
                'name' => $name,
                'message_count' => $session->message_count,
                'last_message_at' => $session->last_message_at?->toIso8601String(),
            ];
        });

        return response()->json($groups->values());
    }

    public function approve(Request $request, Project $project)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);
        abort_unless($project->status === 'pending', 422, 'Ce projet ne peut plus etre approuve.');

        $project->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        // Create SubAgent and dispatch job
        $defaultTimeout = (int) (\App\Models\AppSetting::get('subagent_default_timeout') ?: 10);
        $subAgent = SubAgent::create([
            'project_id' => $project->id,
            'status' => 'queued',
            'task_description' => $project->request_description,
            'timeout_minutes' => $defaultTimeout,
        ]);

        RunSubAgentJob::dispatch($subAgent);

        return redirect()->route('projects.show', $project)
            ->with('success', 'Projet approuve. Le SubAgent a ete lance.');
    }

    public function reject(Request $request, Project $project)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['superadmin', 'admin']), 403);
        abort_unless($project->status === 'pending', 422, 'Ce projet ne peut plus etre rejete.');

        $request->validate([
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        $project->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);

        // Notify requester via WhatsApp
        try {
            \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $project->requester_phone,
                    'text' => "Ta demande pour {$project->name} a ete rejetee."
                        . ($project->rejection_reason ? "\nRaison: {$project->rejection_reason}" : ''),
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to notify rejection: " . $e->getMessage());
        }

        return redirect()->route('projects.show', $project)
            ->with('success', 'Projet rejete.');
    }
}
