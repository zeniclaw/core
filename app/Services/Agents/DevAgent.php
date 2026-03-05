<?php

namespace App\Services\Agents;

use App\Jobs\RunSubAgentJob;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SubAgent;
use App\Services\AgentContext;

class DevAgent extends BaseAgent
{
    public function name(): string
    {
        return 'dev';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'dev';
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Handle task awaiting validation first
        $awaitingProject = Project::where('status', 'awaiting_validation')
            ->where('requester_phone', $context->from)
            ->orderByDesc('created_at')
            ->first();

        if ($awaitingProject) {
            return $this->handleTaskValidation($awaitingProject, $context);
        }

        // Process new dev request
        return $this->handleDevRequest($context);
    }

    private function handleTaskValidation(Project $project, AgentContext $context): AgentResult
    {
        $classification = $this->claude->chat(
            "Message de l'utilisateur: \"{$context->body}\"",
            'claude-haiku-4-5-20251001',
            "Tu classes la reponse d'un utilisateur a qui on a demande de confirmer une tache.\n"
            . "Reponds UNIQUEMENT par un seul mot: CONFIRM, MODIFY ou CANCEL.\n"
            . "CONFIRM = l'utilisateur accepte/valide (oui, ok, go, lance, c'est bon, parfait, envoie, yes, let's go, top, nickel...)\n"
            . "MODIFY = l'utilisateur precise, corrige ou ajoute des details a la demande\n"
            . "CANCEL = l'utilisateur refuse ou annule (non, annule, stop, laisse tomber, oublie...)"
        );

        $intent = strtoupper(trim($classification ?? 'MODIFY'));
        $repoName = $project->name;

        if (str_contains($intent, 'CONFIRM')) {
            return $this->launchSubAgent($project, $context);
        }

        if (str_contains($intent, 'CANCEL')) {
            $project->update(['status' => 'rejected']);
            $reply = "[{$repoName}] Ok, j'annule.";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Task cancelled by user', ['project_id' => $project->id, 'action' => 'cancel']);
            return AgentResult::reply($reply, ['action' => 'cancel']);
        }

        // MODIFY — re-analyze
        $newDescription = $project->request_description . "\n\nPrecision: " . $context->body;
        $project->update(['request_description' => $newDescription]);

        $analysis = $this->analyzeTask($newDescription, $repoName, $context);
        $reply = "[{$repoName}] Voici ce que j'ai compris :\n{$analysis}\n\nC'est bon ? Reponds \"ok\" pour lancer ou precise ta demande.";
        $this->sendText($context->from, $reply);

        $this->log($context, 'Task modified, re-analyzed', ['project_id' => $project->id, 'action' => 'modify']);
        return AgentResult::reply($reply, ['action' => 'modify']);
    }

    private function handleDevRequest(AgentContext $context): AgentResult
    {
        $gitlabData = $this->detectGitlabUrl($context->body);
        $gitlabUrl = null;
        $repoName = null;
        $description = $context->body;
        $isNewRepo = false;

        if ($gitlabData) {
            $gitlabUrl = $gitlabData['url'];
            $description = $gitlabData['description'];
            $repoName = basename(parse_url($gitlabUrl, PHP_URL_PATH) ?? 'repo');
            $repoName = str_replace('.git', '', $repoName);

            $existingApproved = Project::where('requester_phone', $context->from)
                ->where('gitlab_url', $gitlabUrl)
                ->whereIn('status', ['approved', 'in_progress', 'completed'])
                ->first();
            $isNewRepo = !$existingApproved;
        } else {
            // Dev request without GitLab URL — find project
            $lastProject = $this->findProjectForUser($context);

            if ($lastProject) {
                $gitlabUrl = $lastProject->gitlab_url;
                $repoName = $lastProject->name;
                $isNewRepo = !in_array($lastProject->status, ['approved', 'in_progress', 'completed']);
            } else {
                $reply = "On dirait une demande de modif !\n"
                    . "Envoie-moi l'URL GitLab du repo sur lequel tu veux que je travaille.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'asked_for_repo']);
            }
        }

        if ($isNewRepo) {
            return $this->createPendingProject($context, $repoName, $gitlabUrl, $description, $gitlabData);
        }

        // Autonomy: auto-execute safe read/diagnostic tasks without confirmation
        if ($context->autonomy === 'auto') {
            return $this->createAndLaunchAutoProject($context, $repoName, $gitlabUrl, $description, $gitlabData);
        }

        return $this->createAwaitingValidationProject($context, $repoName, $gitlabUrl, $description, $gitlabData);
    }

    private function launchSubAgent(Project $project, AgentContext $context): AgentResult
    {
        $project->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $defaultTimeout = (int) (AppSetting::get('subagent_default_timeout') ?: 10);
        $subAgent = SubAgent::create([
            'project_id' => $project->id,
            'status' => 'queued',
            'task_description' => $project->request_description,
            'timeout_minutes' => $defaultTimeout,
        ]);

        RunSubAgentJob::dispatch($subAgent);

        $reply = "[{$project->name}] C'est parti ! Je bosse dessus.\nJe te tiens au courant de l'avancement.";
        $this->sendText($context->from, $reply);

        $this->log($context, 'SubAgent launched', ['project_id' => $project->id, 'sub_agent_id' => $subAgent->id]);

        return AgentResult::dispatched(['project_id' => $project->id, 'sub_agent_id' => $subAgent->id, 'reply' => $reply]);
    }

    private function createAndLaunchAutoProject(AgentContext $context, string $repoName, string $gitlabUrl, string $description, ?array $gitlabData): AgentResult
    {
        $project = Project::create([
            'name' => $repoName,
            'gitlab_url' => $gitlabUrl,
            'request_description' => $description,
            'requester_phone' => $context->from,
            'requester_name' => $context->senderName,
            'agent_id' => $context->agent->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $defaultTimeout = (int) (AppSetting::get('subagent_default_timeout') ?: 10);
        $subAgent = SubAgent::create([
            'project_id' => $project->id,
            'status' => 'queued',
            'task_description' => $description,
            'timeout_minutes' => $defaultTimeout,
            'is_readonly' => true,
        ]);

        RunSubAgentJob::dispatch($subAgent);

        $reply = "[{$repoName}] Je regarde ca...";
        $this->sendText($context->from, $reply);

        $this->log($context, 'Auto-launched readonly SubAgent (autonomy=auto)', [
            'project_id' => $project->id,
            'sub_agent_id' => $subAgent->id,
        ]);

        return AgentResult::dispatched(['project_id' => $project->id, 'sub_agent_id' => $subAgent->id, 'reply' => $reply]);
    }

    private function createPendingProject(AgentContext $context, string $repoName, string $gitlabUrl, string $description, ?array $gitlabData): AgentResult
    {
        $project = Project::create([
            'name' => $repoName,
            'gitlab_url' => $gitlabUrl,
            'request_description' => $description,
            'requester_phone' => $context->from,
            'requester_name' => $context->senderName,
            'agent_id' => $context->agent->id,
            'status' => 'pending',
        ]);

        $reply = "[{$repoName}] J'ai recu ta demande.\n"
            . "Un admin doit d'abord approuver avant que je commence.\n"
            . "Je te tiens au courant !";
        $this->sendText($context->from, $reply);

        $this->notifyAdminNewProject($project);

        $this->log($context, 'New project pending approval', [
            'project_id' => $project->id,
            'gitlab_url' => $gitlabUrl,
            'detection' => $gitlabData ? 'gitlab_url' : 'claude_classification',
        ]);

        return AgentResult::reply($reply, ['project_id' => $project->id, 'action' => 'pending']);
    }

    private function createAwaitingValidationProject(AgentContext $context, string $repoName, string $gitlabUrl, string $description, ?array $gitlabData): AgentResult
    {
        $project = Project::create([
            'name' => $repoName,
            'gitlab_url' => $gitlabUrl,
            'request_description' => $description,
            'requester_phone' => $context->from,
            'requester_name' => $context->senderName,
            'agent_id' => $context->agent->id,
            'status' => 'awaiting_validation',
        ]);

        $analysis = $this->analyzeTask($description, $repoName, $context);
        $reply = "[{$repoName}] Voici ce que j'ai compris :\n{$analysis}\n\nC'est bon ? Reponds \"ok\" pour lancer ou precise ta demande.";
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project awaiting validation', [
            'project_id' => $project->id,
            'gitlab_url' => $gitlabUrl,
            'detection' => $gitlabData ? 'gitlab_url' : 'claude_classification',
            'auto_approved' => true,
        ]);

        return AgentResult::reply($reply, ['project_id' => $project->id, 'action' => 'awaiting_validation']);
    }

    private function findProjectForUser(AgentContext $context): ?Project
    {
        $body = $context->body;
        $from = $context->from;

        // Priority: name match > active project > allowed_phones > last project
        $namedProject = $this->findProjectByNameInMessage($body, $from);
        $activeProject = $context->session->active_project_id
            ? Project::whereIn('status', ['approved', 'in_progress', 'completed'])->find($context->session->active_project_id)
            : null;

        return $namedProject
            ?? $activeProject
            ?? $this->findProjectByAllowedPhone($from)
            ?? $this->findLastProjectForUser($from);
    }

    private function findProjectByNameInMessage(string $body, string $phone): ?Project
    {
        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        if ($projects->isEmpty()) return null;

        foreach ($projects as $project) {
            if (mb_stripos($body, $project->name) !== false) {
                return $project;
            }
        }

        foreach ($projects as $project) {
            $slug = basename(parse_url($project->gitlab_url, PHP_URL_PATH) ?? '');
            $slug = str_replace('.git', '', $slug);
            if ($slug && mb_stripos($body, $slug) !== false) {
                return $project;
            }
        }

        return null;
    }

    private function findProjectByAllowedPhone(string $phone): ?Project
    {
        return Project::whereNotNull('allowed_phones')
            ->whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get()
            ->first(fn($project) => is_array($project->allowed_phones) && in_array($phone, $project->allowed_phones));
    }

    private function findLastProjectForUser(string $phone): ?Project
    {
        return Project::where('requester_phone', $phone)
            ->whereNotIn('status', ['rejected'])
            ->orderByDesc('created_at')
            ->first();
    }

    private function detectGitlabUrl(string $body): ?array
    {
        if (preg_match('#(https?://gitlab\.[^\s]+)#i', $body, $matches)) {
            $url = rtrim($matches[1], '.,;:!?)');
            $description = trim(str_replace($matches[0], '', $body));
            if (!$description) {
                $description = 'Modification demandee (pas de description supplementaire)';
            }
            return ['url' => $url, 'description' => $description];
        }
        return null;
    }

    private function analyzeTask(string $body, string $repoName, ?AgentContext $context = null): string
    {
        $techContext = '';
        if ($context) {
            $facts = $this->getContextMemory($context->from);
            $techFacts = array_filter($facts, fn($f) => in_array($f['category'] ?? '', ['profession', 'project']));
            if (!empty($techFacts)) {
                $techLines = array_map(fn($f) => $f['value'], $techFacts);
                $techContext = "\nProfil technique de l'utilisateur: " . implode(', ', $techLines);
            }
        }

        $response = $this->claude->chat(
            "Projet: {$repoName}\nDemande: {$body}{$techContext}",
            'claude-haiku-4-5-20251001',
            "Tu es un assistant technique. Reformule cette demande en un plan d'action clair et concis.\n"
            . "Liste 3 a 5 etapes numerotees.\n"
            . "Sois precis mais bref (1 ligne par etape).\n"
            . "Pas de code, pas d'explications longues.\n"
            . "Si tu connais le profil technique de l'utilisateur, propose des solutions adaptees a son stack.\n"
            . "Reponds directement avec la liste numerotee, rien d'autre."
        );

        return $response ?? 'Impossible d\'analyser la demande pour le moment.';
    }

    private function notifyAdminNewProject(Project $project): void
    {
        try {
            $adminPhone = AppSetting::get('admin_whatsapp_phone');
            if (!$adminPhone) return;

            $message = "Nouvelle demande de projet !\n\n"
                . "De: {$project->requester_name}\n"
                . "Repo: {$project->gitlab_url}\n"
                . "Demande: " . substr($project->request_description, 0, 200) . "\n\n"
                . "Connecte-toi au dashboard pour approuver ou rejeter.";

            $this->sendText($adminPhone, $message);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to notify admin: " . $e->getMessage());
        }
    }
}
