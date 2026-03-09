<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Models\SubAgent;
use App\Services\AgentContext;
use Carbon\Carbon;

class ProjectAgent extends BaseAgent
{
    public function name(): string
    {
        return 'project';
    }

    public function description(): string
    {
        return 'Agent de gestion de projets. Permet de changer de projet actif, creer un nouveau projet, voir les statistiques, archiver/restaurer un projet, renommer un projet et lister tous les projets.';
    }

    public function keywords(): array
    {
        return [
            'switch', 'switch projet', 'changer de projet', 'changer projet',
            'bosser sur', 'bosse sur', 'travailler sur', 'passer sur',
            'activer projet', 'activate project', 'select project',
            'creer projet', 'nouveau projet', 'create project', 'new project',
            'cree un projet', 'ajouter projet', 'add project',
            'stats projet', 'statistiques projet', 'project stats',
            'archiver projet', 'archive projet', 'archive project',
            'restaurer projet', 'restore projet', 'restore project', 'desarchiver', 'reactiver projet',
            'renommer projet', 'rename projet', 'rename project',
            'mes projets', 'liste projets', 'list projects', 'my projects',
            'tous mes projets', 'all projects',
            'quel projet', 'projet actif', 'active project',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
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

        // Detect action via Haiku
        $action = $this->detectAction($context);

        return match ($action['action'] ?? 'switch') {
            'create'  => $this->handleCreate($context, $action),
            'stats'   => $this->handleStats($context, $action),
            'archive' => $this->handleArchive($context, $action),
            'restore' => $this->handleRestore($context, $action),
            'rename'  => $this->handleRename($context, $action),
            'list'    => $this->handleList($context, $action),
            default   => $this->handleProjectSwitch($context),
        };
    }

    private function detectAction(AgentContext $context): array
    {
        $response = $this->claude->chat(
            "Message: \"{$context->body}\"",
            'claude-haiku-4-5-20251001',
            $this->buildActionPrompt()
        );

        return $this->parseJson($response) ?? ['action' => 'switch'];
    }

    private function buildActionPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de gestion de projets. L'utilisateur te donne un message et tu dois determiner l'action a effectuer.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication:
{"action": "switch|create|stats|archive|restore|rename|list", "project_name": "...", "new_name": "...", "gitlab_url": "...", "description": "...", "show_all": false}

ACTIONS:
- "switch": l'utilisateur veut changer de projet actif (ex: "bosse sur mon-app", "switch zeniclaw", "projet X")
- "create": l'utilisateur veut creer un nouveau projet avec un nom et/ou une URL GitLab (ex: "cree un projet mon-app avec gitlab.com/...", "nouveau projet test-api")
- "stats": l'utilisateur demande des statistiques (ex: "stats du projet", "comment va mon projet", "etat du projet", "statistiques")
- "archive": l'utilisateur veut archiver un projet (ex: "archive le projet X", "archive mon-app")
- "restore": l'utilisateur veut restaurer/desarchiver un projet (ex: "restaure le projet X", "desarchive mon-app", "reactive le projet Y")
- "rename": l'utilisateur veut renommer un projet (ex: "renomme zeniclaw en zeniclaw-v2", "le projet X s'appelle maintenant Y")
- "list": l'utilisateur veut voir la liste de ses projets (ex: "mes projets", "liste des projets", "quels projets", "tous mes projets")

CHAMPS:
- project_name: nom actuel du projet mentionne (ou null si non mentionne)
- new_name: nouveau nom si action=rename (ou null)
- gitlab_url: URL GitLab si mentionnee (ou null)
- description: description courte du projet si fournie (ou null)
- show_all: true si l'utilisateur demande "tous" les projets (inclut les archives)

EXEMPLES:
- "bosse sur zeniclaw" → {"action": "switch", "project_name": "zeniclaw", "new_name": null, "gitlab_url": null, "description": null, "show_all": false}
- "cree un projet mon-app avec https://gitlab.com/team/mon-app" → {"action": "create", "project_name": "mon-app", "new_name": null, "gitlab_url": "https://gitlab.com/team/mon-app", "description": null, "show_all": false}
- "nouveau projet api-gateway API de routage interne" → {"action": "create", "project_name": "api-gateway", "new_name": null, "gitlab_url": null, "description": "API de routage interne", "show_all": false}
- "stats du projet" → {"action": "stats", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false}
- "archive le projet test" → {"action": "archive", "project_name": "test", "new_name": null, "gitlab_url": null, "description": null, "show_all": false}
- "restaure le projet test" → {"action": "restore", "project_name": "test", "new_name": null, "gitlab_url": null, "description": null, "show_all": false}
- "renomme zeniclaw en zeniclaw-v2" → {"action": "rename", "project_name": "zeniclaw", "new_name": "zeniclaw-v2", "gitlab_url": null, "description": null, "show_all": false}
- "mes projets" → {"action": "list", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false}
- "tous mes projets" → {"action": "list", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": true}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    // ─── Switch ────────────────────────────────────────────────────────

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

                $reply = $this->buildSwitchSummary($project);
                $this->sendText($context->from, $reply);

                $this->log($context, 'Active project switched', [
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                ]);

                return AgentResult::reply($reply, ['action' => 'project_switched', 'project_id' => $project->id]);
            }
        }

        $reply = "Ok, pas de changement de projet.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'project_switch_cancelled']);
    }

    private function buildSwitchSummary(Project $project): string
    {
        $completedCount = SubAgent::where('project_id', $project->id)
            ->where('status', 'completed')
            ->count();

        $lastSubAgent = SubAgent::where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->first();

        $lines = ["✅ Projet *{$project->name}* active !"];
        $lines[] = "📊 {$completedCount} tache" . ($completedCount > 1 ? 's' : '') . " realisee" . ($completedCount > 1 ? 's' : '');

        if ($lastSubAgent) {
            $taskDesc    = mb_strimwidth($lastSubAgent->task_description ?? 'sans description', 0, 50, '...');
            $statusLabel = $this->statusLabel($lastSubAgent->status);
            $ago         = $lastSubAgent->updated_at ? Carbon::parse($lastSubAgent->updated_at)->diffForHumans() : '';
            $lines[] = "🔧 Derniere : \"{$taskDesc}\" ({$statusLabel} {$ago})";
        }

        if ($project->gitlab_url) {
            $lines[] = "🔗 {$project->gitlab_url}";
        }

        $lines[] = "\nEnvoie-moi tes demandes !";

        return implode("\n", $lines);
    }

    private function handleProjectSwitch(AgentContext $context): AgentResult
    {
        $project = $this->smartMatchProject($context->body, $context->from);

        if ($project) {
            $context->session->update(['pending_switch_project_id' => $project->id]);

            $urlPart = $project->gitlab_url ? " ({$project->gitlab_url})" : '';
            $reply = "[{$project->name}] Tu veux bosser sur ce projet{$urlPart} ?\nDis \"oui\" pour confirmer.";
            $this->sendText($context->from, $reply);

            $this->log($context, 'Project switch proposed', [
                'project_id'   => $project->id,
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
            $reply = "Je n'ai trouve aucun projet configure. Dis \"cree un projet mon-app\" pour en ajouter un.";
        } else {
            $list  = $projects->map(fn($p) => "- {$p->name}")->implode("\n");
            $reply = "J'ai pas trouve le projet. Voici les projets disponibles :\n{$list}\n\nPrecise lequel tu veux.";
        }

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'project_switch_not_found']);
    }

    // ─── Create ────────────────────────────────────────────────────────

    private function handleCreate(AgentContext $context, array $action): AgentResult
    {
        $name        = trim($action['project_name'] ?? '');
        $gitlabUrl   = $action['gitlab_url'] ?? null;
        $description = $action['description'] ?? null;

        if (!$name) {
            $reply = "Pour creer un projet, donne-moi un nom.\nEx: \"Cree un projet mon-app avec https://gitlab.com/team/mon-app\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_create_missing_name']);
        }

        // Validate GitLab URL format if provided
        if ($gitlabUrl && !filter_var($gitlabUrl, FILTER_VALIDATE_URL)) {
            $reply = "L'URL \"{$gitlabUrl}\" ne semble pas valide. Donne-moi une URL complete (ex: https://gitlab.com/team/mon-app).";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_create_invalid_url']);
        }

        // Check for duplicate name (case-insensitive)
        $existing = Project::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            $reply = "Un projet \"{$existing->name}\" existe deja. Tu veux bosser dessus ? Dis \"switch {$existing->name}\".";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_create_duplicate']);
        }

        $project = Project::create([
            'name'                => $name,
            'gitlab_url'          => $gitlabUrl,
            'request_description' => $description,
            'requester_phone'     => $context->from,
            'requester_name'      => $context->senderName,
            'agent_id'            => $context->agent->id,
            'status'              => 'approved',
            'approved_at'         => now(),
        ]);

        // Auto-activate the new project
        $context->session->update(['active_project_id' => $project->id]);

        $lines = ["✅ Projet *{$project->name}* cree et active !"];
        if ($gitlabUrl) {
            $lines[] = "🔗 {$gitlabUrl}";
        }
        if ($description) {
            $lines[] = "📝 {$description}";
        }
        $lines[] = "\nEnvoie-moi tes demandes !";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project created via WhatsApp', [
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'gitlab_url'   => $gitlabUrl,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_created', 'project_id' => $project->id]);
    }

    // ─── Stats ─────────────────────────────────────────────────────────

    private function handleStats(AgentContext $context, array $action): AgentResult
    {
        $project = $this->resolveTargetProject($context, $action);

        if (!$project) {
            $reply = "Aucun projet actif. Dis \"switch nom-du-projet\" pour en selectionner un, ou precise le nom du projet.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_stats_no_project']);
        }

        $subAgents = SubAgent::where('project_id', $project->id)->get();

        $completed = $subAgents->where('status', 'completed')->count();
        $failed    = $subAgents->where('status', 'failed')->count();
        $running   = $subAgents->where('status', 'running')->count();
        $pending   = $subAgents->where('status', 'pending')->count();
        $total     = $subAgents->count();

        // Success rate (completed vs completed+failed)
        $doneCount   = $completed + $failed;
        $successRate = $doneCount > 0 ? round(($completed / $doneCount) * 100) : null;

        // Average execution time for completed tasks
        $avgTime             = null;
        $completedWithTimes  = $subAgents->filter(fn($s) => $s->status === 'completed' && $s->started_at && $s->completed_at);
        if ($completedWithTimes->isNotEmpty()) {
            $totalSeconds = $completedWithTimes->sum(
                fn($s) => Carbon::parse($s->completed_at)->diffInSeconds(Carbon::parse($s->started_at))
            );
            $avgSeconds = (int) ($totalSeconds / $completedWithTimes->count());
            $avgTime    = $this->formatDuration($avgSeconds);
        }

        // Last activity
        $lastActivity     = $subAgents->sortByDesc('updated_at')->first();
        $lastActivityText = $lastActivity
            ? Carbon::parse($lastActivity->updated_at)->diffForHumans()
            : 'aucune';

        $lines = [
            "📊 *Stats du projet {$project->name}*",
            "",
            "Total : {$total} tache" . ($total > 1 ? 's' : ''),
            "✅ Terminees : {$completed}",
        ];

        if ($running > 0) $lines[] = "🔄 En cours : {$running}";
        if ($pending > 0) $lines[] = "⏳ En attente : {$pending}";
        if ($failed > 0)  $lines[] = "❌ Echouees : {$failed}";

        if ($successRate !== null) {
            $lines[] = "🎯 Taux de succes : {$successRate}%";
        }

        if ($avgTime) {
            $lines[] = "⏱️ Temps moyen : {$avgTime}";
        }

        $lines[] = "🕐 Derniere activite : {$lastActivityText}";

        if ($project->status === 'archived') {
            $lines[] = "📦 Statut : archive";
        }

        if ($project->gitlab_url) {
            $lines[] = "🔗 {$project->gitlab_url}";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project stats requested', [
            'project_id'   => $project->id,
            'total_tasks'  => $total,
            'completed'    => $completed,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_stats', 'project_id' => $project->id]);
    }

    // ─── Archive ───────────────────────────────────────────────────────

    private function handleArchive(AgentContext $context, array $action): AgentResult
    {
        $project = $this->resolveTargetProject($context, $action);

        if (!$project) {
            $reply = "Je n'ai pas trouve le projet a archiver. Precise le nom exact ou active un projet d'abord.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_archive_not_found']);
        }

        if ($project->status === 'archived') {
            $reply = "Le projet *{$project->name}* est deja archive.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_already_archived']);
        }

        $project->update(['status' => 'archived']);

        // If this was the active project, clear it
        if ($context->session->active_project_id === $project->id) {
            $context->session->update(['active_project_id' => null]);
        }

        $reply = "📦 Projet *{$project->name}* archive.\nIl n'apparaitra plus dans ta liste par defaut. Dis \"restaure {$project->name}\" pour le reactiver.";
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project archived', [
            'project_id'   => $project->id,
            'project_name' => $project->name,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_archived', 'project_id' => $project->id]);
    }

    // ─── Restore ───────────────────────────────────────────────────────

    private function handleRestore(AgentContext $context, array $action): AgentResult
    {
        $name    = $action['project_name'] ?? null;
        $project = null;

        if ($name) {
            // Search only among archived projects
            $project = Project::where('status', 'archived')
                ->where(function ($q) use ($name) {
                    $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                      ->orWhere('name', 'LIKE', "%{$name}%");
                })
                ->first();

            // AI fallback among archived projects
            if (!$project) {
                $archived = Project::where('status', 'archived')->get();
                if ($archived->isNotEmpty()) {
                    $list     = $archived->map(fn($p) => "- ID:{$p->id} nom:\"{$p->name}\"")->implode("\n");
                    $response = $this->claude->chat(
                        "Message utilisateur: \"{$name}\"\n\nProjets archives:\n{$list}",
                        'claude-haiku-4-5-20251001',
                        "Trouve le projet archive le plus probable dans la liste.\n"
                        . "Reponds UNIQUEMENT avec l'ID (ex: 42) ou AUCUN si aucun ne correspond."
                    );
                    $clean = trim($response ?? '');
                    if (is_numeric($clean)) {
                        $project = $archived->firstWhere('id', (int) $clean);
                    }
                }
            }
        }

        if (!$project) {
            $archived = Project::where('status', 'archived')->orderByDesc('updated_at')->limit(5)->get();
            if ($archived->isEmpty()) {
                $reply = "Aucun projet archive. Il n'y a rien a restaurer.";
            } else {
                $list  = $archived->map(fn($p) => "- {$p->name}")->implode("\n");
                $reply = "Je n'ai pas trouve le projet archive. Projets archives :\n{$list}\n\nPrecise lequel tu veux restaurer.";
            }
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_restore_not_found']);
        }

        $project->update(['status' => 'approved']);

        $reply = "✅ Projet *{$project->name}* restaure et disponible !\nDis \"switch {$project->name}\" pour l'activer.";
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project restored', [
            'project_id'   => $project->id,
            'project_name' => $project->name,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_restored', 'project_id' => $project->id]);
    }

    // ─── Rename ────────────────────────────────────────────────────────

    private function handleRename(AgentContext $context, array $action): AgentResult
    {
        $project = $this->resolveTargetProject($context, $action);
        $newName = trim($action['new_name'] ?? '');

        if (!$project) {
            $reply = "Je n'ai pas trouve le projet a renommer. Precise le nom actuel.\nEx: \"renomme mon-app en mon-app-v2\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_rename_not_found']);
        }

        if (!$newName) {
            $reply = "Donne-moi le nouveau nom du projet.\nEx: \"renomme {$project->name} en nouveau-nom\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_rename_missing_name']);
        }

        // Check if new name is already taken
        $conflict = Project::whereRaw('LOWER(name) = ?', [mb_strtolower($newName)])
            ->where('id', '!=', $project->id)
            ->first();

        if ($conflict) {
            $reply = "Un projet *{$conflict->name}* existe deja avec ce nom. Choisis un autre nom.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_rename_conflict']);
        }

        $oldName = $project->name;
        $project->update(['name' => $newName]);

        $reply = "✏️ Projet renomme : *{$oldName}* → *{$newName}*";
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project renamed', [
            'project_id' => $project->id,
            'old_name'   => $oldName,
            'new_name'   => $newName,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_renamed', 'project_id' => $project->id]);
    }

    // ─── List ──────────────────────────────────────────────────────────

    private function handleList(AgentContext $context, array $action): AgentResult
    {
        $showAll = $action['show_all'] ?? false;

        // Use withCount to avoid N+1 query
        $query = Project::withCount(['subAgents as completed_tasks_count' => function ($q) {
            $q->where('status', 'completed');
        }])->orderByDesc('created_at');

        if ($showAll) {
            $query->whereIn('status', ['approved', 'in_progress', 'completed', 'archived']);
        } else {
            $query->whereIn('status', ['approved', 'in_progress', 'completed']);
        }

        $projects = $query->limit(20)->get();

        if ($projects->isEmpty()) {
            $reply = "Aucun projet trouve. Dis \"cree un projet mon-app\" pour en creer un.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_list_empty']);
        }

        $activeId = $context->session->active_project_id;

        $lines = ["📁 *" . ($showAll ? "Tous tes projets" : "Tes projets") . "* :"];
        foreach ($projects as $p) {
            $marker   = $p->id === $activeId ? ' 👈' : '';
            $archived = $p->status === 'archived' ? ' (archive)' : '';
            $count    = $p->completed_tasks_count ?? 0;
            $lines[]  = "- *{$p->name}*{$archived} ({$count} taches){$marker}";
        }

        $lines[] = "\nDis \"switch nom-du-projet\" pour changer.";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project list requested', [
            'count'    => $projects->count(),
            'show_all' => $showAll,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_list']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function resolveTargetProject(AgentContext $context, array $action): ?Project
    {
        $name = $action['project_name'] ?? null;

        // If a project name is mentioned, try to find it
        if ($name) {
            $project = $this->smartMatchProject($name, $context->from);
            if ($project) return $project;
        }

        // Fall back to active project
        if ($context->session->active_project_id) {
            return Project::find($context->session->active_project_id);
        }

        return null;
    }

    private function smartMatchProject(string $body, string $phone): ?Project
    {
        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        if ($projects->isEmpty()) return null;

        // Try exact name match (case-insensitive)
        foreach ($projects as $project) {
            if (mb_stripos($body, $project->name) !== false) {
                return $project;
            }
        }

        // Try repo slug match
        foreach ($projects as $project) {
            $slug = basename(parse_url($project->gitlab_url ?? '', PHP_URL_PATH) ?? '');
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

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'terminee',
            'failed'    => 'echouee',
            'running'   => 'en cours',
            'pending'   => 'en attente',
            default     => $status,
        };
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}s";
        if ($seconds < 3600) return round($seconds / 60) . " min";
        $hours = floor($seconds / 3600);
        $mins  = round(($seconds % 3600) / 60);
        return "{$hours}h{$mins}m";
    }

    private function parseJson(?string $response): ?array
    {
        if (!$response) return null;

        $clean = trim($response);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        return json_decode($clean, true);
    }
}
