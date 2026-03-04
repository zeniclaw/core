<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Services\AgentContext;
use App\Services\AnthropicClient;

class ProjectAgent extends BaseAgent
{
    public function name(): string
    {
        return 'project';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'project';
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Handle pending switch confirmation first
        if ($context->session->pending_switch_project_id) {
            return $this->handlePendingSwitchConfirmation($context);
        }

        // New project switch request
        return $this->handleProjectSwitch($context);
    }

    private function handlePendingSwitchConfirmation(AgentContext $context): AgentResult
    {
        $pendingId = $context->session->pending_switch_project_id;

        $classification = $this->claude->chat(
            "Message de l'utilisateur: \"{$context->body}\"",
            'claude-haiku-4-5-20251001',
            "L'utilisateur repond a une demande de confirmation (oui/non).\n"
            . "Reponds UNIQUEMENT par OUI ou NON.\n"
            . "OUI = l'utilisateur confirme (oui, ok, yes, go, c'est bon, parfait, yep, ouais...)\n"
            . "NON = l'utilisateur refuse ou dit autre chose (non, annule, stop, pas celui-la...)"
        );

        $intent = strtoupper(trim($classification ?? ''));
        $context->session->update(['pending_switch_project_id' => null]);

        if (str_contains($intent, 'OUI')) {
            $project = Project::find($pendingId);
            if ($project) {
                $context->session->update(['active_project_id' => $project->id]);

                $reply = "[{$project->name}] C'est bon, je bosse sur ce projet maintenant.\nEnvoie-moi tes demandes !";
                $this->sendText($context->from, $reply);

                $this->log($context, 'Active project switched', [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                ]);

                return AgentResult::reply($reply, ['action' => 'project_switched', 'project_id' => $project->id]);
            }
        }

        $reply = "Ok, pas de changement de projet.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'project_switch_cancelled']);
    }

    private function handleProjectSwitch(AgentContext $context): AgentResult
    {
        $project = $this->smartMatchProject($context->body, $context->from);

        if ($project) {
            $context->session->update(['pending_switch_project_id' => $project->id]);

            $reply = "[{$project->name}] Tu veux bosser sur ce projet ({$project->gitlab_url}) ?\nDis \"oui\" pour confirmer.";
            $this->sendText($context->from, $reply);

            $this->log($context, 'Project switch proposed', [
                'project_id' => $project->id,
                'project_name' => $project->name,
            ]);

            return AgentResult::reply($reply, ['action' => 'project_switch_proposed']);
        }

        // No project found — list available ones
        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($projects->isEmpty()) {
            $reply = "Je n'ai trouve aucun projet configure. Envoie-moi l'URL GitLab du repo que tu veux utiliser.";
        } else {
            $list = $projects->map(fn($p) => "- {$p->name}")->implode("\n");
            $reply = "J'ai pas trouve le projet. Voici les projets disponibles :\n{$list}\n\nPrecise lequel tu veux.";
        }

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'project_switch_not_found']);
    }

    private function smartMatchProject(string $body, string $phone): ?Project
    {
        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        if ($projects->isEmpty()) return null;

        // Try exact name match
        foreach ($projects as $project) {
            if (mb_stripos($body, $project->name) !== false) {
                return $project;
            }
        }

        // Try repo slug match
        foreach ($projects as $project) {
            $slug = basename(parse_url($project->gitlab_url, PHP_URL_PATH) ?? '');
            $slug = str_replace('.git', '', $slug);
            if ($slug && mb_stripos($body, $slug) !== false) {
                return $project;
            }
        }

        // AI match with Haiku
        $projectList = $projects->map(fn($p) => "- ID:{$p->id} nom:\"{$p->name}\" url:{$p->gitlab_url}")->implode("\n");

        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"\n\nProjets disponibles:\n{$projectList}",
            'claude-haiku-4-5-20251001',
            "L'utilisateur mentionne un projet. Trouve le projet le plus probable dans la liste.\n"
            . "Reponds UNIQUEMENT avec l'ID du projet (ex: 42) ou AUCUN si aucun projet ne correspond.\n"
            . "Gere les noms partiels, fautes de frappe, descriptions vagues.\n"
            . "Reponds un seul mot: l'ID ou AUCUN."
        );

        $clean = trim($response ?? '');
        if ($clean === 'AUCUN' || !is_numeric($clean)) return null;

        return $projects->firstWhere('id', (int) $clean);
    }
}
