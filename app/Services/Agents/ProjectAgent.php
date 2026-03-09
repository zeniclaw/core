<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Models\SubAgent;
use App\Services\AgentContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProjectAgent extends BaseAgent
{
    public function name(): string
    {
        return 'project';
    }

    public function description(): string
    {
        return 'Agent de gestion de projets. Permet de changer de projet actif, creer/supprimer un projet, voir les statistiques et les details, archiver/restaurer, renommer, mettre a jour l\'URL GitLab ou la description, et lister tous les projets.';
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
            'update projet', 'mettre a jour projet', 'changer url', 'modifier url gitlab', 'changer description',
            'info projet', 'infos projet', 'detail projet', 'details projet', 'voir projet',
            'supprimer projet', 'delete projet', 'effacer projet', 'enlever projet',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'project';
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Handle generic pending context (delete confirmation, etc.)
        $pendingCtx = $context->session->pending_agent_context ?? null;
        if ($pendingCtx && ($pendingCtx['agent'] ?? '') === 'project') {
            $result = $this->handlePendingContext($context, $pendingCtx);
            if ($result !== null) {
                return $result;
            }
        }

        // Handle pending switch confirmation
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
            'update'  => $this->handleUpdate($context, $action),
            'info'    => $this->handleInfo($context, $action),
            'delete'  => $this->handleDelete($context, $action),
            default   => $this->handleProjectSwitch($context),
        };
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';

        if ($type === 'delete_confirm') {
            return $this->handleDeleteConfirmation($context, $pendingContext['data'] ?? []);
        }

        return null;
    }

    private function detectAction(AgentContext $context): array
    {
        try {
            $response = $this->claude->chat(
                "Message: \"{$context->body}\"",
                'claude-haiku-4-5-20251001',
                $this->buildActionPrompt()
            );

            return $this->parseJson($response) ?? ['action' => 'switch'];
        } catch (\Exception $e) {
            Log::warning("[project] detectAction failed: " . $e->getMessage());
            return ['action' => 'switch'];
        }
    }

    private function buildActionPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de gestion de projets. L'utilisateur te donne un message et tu dois determiner l'action a effectuer.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication:
{"action": "switch|create|stats|archive|restore|rename|list|update|info|delete", "project_name": "...", "new_name": "...", "gitlab_url": "...", "description": "...", "show_all": false}

ACTIONS:
- "switch": changer de projet actif (ex: "bosse sur mon-app", "switch zeniclaw", "projet X")
- "create": creer un nouveau projet (ex: "cree un projet mon-app", "nouveau projet test-api avec gitlab.com/...")
- "stats": voir les statistiques d'un projet (ex: "stats du projet", "comment va mon projet", "statistiques")
- "archive": archiver un projet (ex: "archive le projet X", "archive mon-app")
- "restore": restaurer/desarchiver un projet (ex: "restaure le projet X", "reactive le projet Y")
- "rename": renommer un projet (ex: "renomme zeniclaw en zeniclaw-v2", "le projet X s'appelle maintenant Y")
- "list": lister les projets (ex: "mes projets", "liste des projets", "tous mes projets", "quels projets")
- "update": mettre a jour l'URL GitLab ou la description d'un projet (ex: "change l'url gitlab de mon-app", "met a jour la description", "l'url du projet est maintenant https://...")
- "info": voir les details complets d'un projet (ex: "infos sur le projet", "details du projet X", "montre moi le projet Y")
- "delete": supprimer definitivement un projet archive (ex: "supprime le projet test", "efface le projet archive X")

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
- "change l'url gitlab de mon-app en https://gitlab.com/new/mon-app" → {"action": "update", "project_name": "mon-app", "new_name": null, "gitlab_url": "https://gitlab.com/new/mon-app", "description": null, "show_all": false}
- "mets a jour la description: nouvelle API REST" → {"action": "update", "project_name": null, "new_name": null, "gitlab_url": null, "description": "nouvelle API REST", "show_all": false}
- "infos sur le projet zeniclaw" → {"action": "info", "project_name": "zeniclaw", "new_name": null, "gitlab_url": null, "description": null, "show_all": false}
- "supprime le projet test" → {"action": "delete", "project_name": "test", "new_name": null, "gitlab_url": null, "description": null, "show_all": false}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    // ─── Switch ────────────────────────────────────────────────────────

    private function handlePendingSwitchConfirmation(AgentContext $context): AgentResult
    {
        $pendingId = $context->session->pending_switch_project_id;

        try {
            $classification = $this->claude->chat(
                "Message de l'utilisateur: \"{$context->body}\"",
                'claude-haiku-4-5-20251001',
                "L'utilisateur repond a une demande de confirmation (oui/non).\n"
                . "Reponds UNIQUEMENT par OUI ou NON.\n"
                . "OUI = confirme (oui, ok, yes, go, c'est bon, parfait, yep, ouais, confirme, allez, let's go...)\n"
                . "NON = refuse ou autre chose (non, annule, stop, pas celui-la, nope, laisse tomber...)"
            );
            $intent = strtoupper(trim($classification ?? ''));
        } catch (\Exception $e) {
            Log::warning("[project] handlePendingSwitchConfirmation AI failed: " . $e->getMessage());
            $intent = 'NON';
        }

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
        $subAgents      = SubAgent::where('project_id', $project->id)->get();
        $total          = $subAgents->count();
        $completedCount = $subAgents->where('status', 'completed')->count();
        $runningCount   = $subAgents->where('status', 'running')->count();

        $lastSubAgent = $subAgents->sortByDesc('updated_at')->first();

        $lines = ["✅ Projet *{$project->name}* active !"];

        $taskSummary = "{$total} tache" . ($total !== 1 ? 's' : '');
        if ($runningCount > 0) {
            $taskSummary .= " · {$runningCount} en cours";
        }
        if ($completedCount > 0) {
            $taskSummary .= " · {$completedCount} terminees";
        }
        $lines[] = "📊 {$taskSummary}";

        if ($lastSubAgent) {
            $taskDesc    = mb_strimwidth($lastSubAgent->task_description ?? 'sans description', 0, 50, '...');
            $statusLabel = $this->statusLabel($lastSubAgent->status);
            $ago         = $lastSubAgent->updated_at ? Carbon::parse($lastSubAgent->updated_at)->diffForHumans() : '';
            $lines[] = "🔧 Derniere : \"{$taskDesc}\" ({$statusLabel}" . ($ago ? ", {$ago}" : '') . ")";
        }

        if ($project->request_description) {
            $lines[] = "📝 " . mb_strimwidth($project->request_description, 0, 80, '...');
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

            $desc    = $project->request_description ? "\n📝 " . mb_strimwidth($project->request_description, 0, 60, '...') : '';
            $urlPart = $project->gitlab_url ? "\n🔗 {$project->gitlab_url}" : '';
            $reply   = "*{$project->name}*{$desc}{$urlPart}\nTu veux bosser sur ce projet ? Dis \"oui\" pour confirmer.";

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
            $reply = "Aucun projet configure. Dis \"cree un projet mon-app\" pour en ajouter un.";
        } else {
            $activeId = $context->session->active_project_id;
            $list     = $projects->map(fn($p) => ($p->id === $activeId ? '👈 ' : '- ') . $p->name)->implode("\n");
            $reply    = "Projet introuvable. Projets disponibles :\n{$list}\n\nPrecise lequel tu veux.";
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
            $reply = "Pour creer un projet, donne-moi un nom.\nEx: \"cree un projet mon-app avec https://gitlab.com/team/mon-app\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_create_missing_name']);
        }

        if (mb_strlen($name) > 100) {
            $reply = "Le nom du projet est trop long (max 100 caracteres).";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_create_invalid_name']);
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
            $isArchived = $existing->status === 'archived';
            $hint       = $isArchived
                ? "Dis \"restaure {$existing->name}\" pour le reactiver."
                : "Dis \"switch {$existing->name}\" pour l'activer.";
            $reply = "Un projet *{$existing->name}*" . ($isArchived ? ' (archive)' : '') . " existe deja. {$hint}";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_create_duplicate']);
        }

        try {
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
        } catch (\Exception $e) {
            Log::error("[project] handleCreate DB error: " . $e->getMessage());
            $reply = "Erreur lors de la creation du projet. Reessaie dans un moment.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_create_error']);
        }

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
        $avgTime            = null;
        $completedWithTimes = $subAgents->filter(fn($s) => $s->status === 'completed' && $s->started_at && $s->completed_at);
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

        // Project age
        $projectAge = $project->created_at
            ? Carbon::parse($project->created_at)->diffForHumans()
            : null;

        $lines = [
            "📊 *Stats : {$project->name}*",
            "",
            "Total : {$total} tache" . ($total !== 1 ? 's' : ''),
        ];

        if ($completed > 0) $lines[] = "✅ Terminees : {$completed}";
        if ($running > 0)   $lines[] = "🔄 En cours : {$running}";
        if ($pending > 0)   $lines[] = "⏳ En attente : {$pending}";
        if ($failed > 0)    $lines[] = "❌ Echouees : {$failed}";

        if ($successRate !== null) {
            $lines[] = "🎯 Succes : {$successRate}%";
        }

        if ($avgTime) {
            $lines[] = "⏱️ Temps moyen : {$avgTime}";
        }

        $lines[] = "🕐 Derniere activite : {$lastActivityText}";

        if ($projectAge) {
            $lines[] = "📅 Cree : {$projectAge}";
        }

        if ($project->status === 'archived') {
            $lines[] = "📦 Statut : archive";
        }

        // Show running task names if any
        if ($running > 0) {
            $runningTasks = $subAgents->where('status', 'running')->take(3);
            $lines[] = "";
            $lines[] = "Taches en cours :";
            foreach ($runningTasks as $task) {
                $desc    = mb_strimwidth($task->task_description ?? '?', 0, 60, '...');
                $lines[] = "• {$desc}";
            }
        }

        if ($project->gitlab_url) {
            $lines[] = "";
            $lines[] = "🔗 {$project->gitlab_url}";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project stats requested', [
            'project_id'  => $project->id,
            'total_tasks' => $total,
            'completed'   => $completed,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_stats', 'project_id' => $project->id]);
    }

    // ─── Archive ───────────────────────────────────────────────────────

    private function handleArchive(AgentContext $context, array $action): AgentResult
    {
        $project = $this->resolveTargetProject($context, $action);

        if (!$project) {
            $reply = "Projet introuvable. Precise le nom exact ou active un projet d'abord.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_archive_not_found']);
        }

        if ($project->status === 'archived') {
            $reply = "Le projet *{$project->name}* est deja archive.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_already_archived']);
        }

        // Warn about running tasks (but proceed anyway)
        $runningCount = SubAgent::where('project_id', $project->id)->where('status', 'running')->count();
        $warning      = $runningCount > 0
            ? "⚠️ {$runningCount} tache" . ($runningCount > 1 ? 's' : '') . " en cours (non interrompue" . ($runningCount > 1 ? 's' : '') . ").\n"
            : '';

        $project->update(['status' => 'archived']);

        // If this was the active project, clear it
        if ($context->session->active_project_id === $project->id) {
            $context->session->update(['active_project_id' => null]);
        }

        $reply = "{$warning}📦 Projet *{$project->name}* archive.\nDis \"restaure {$project->name}\" pour le reactiver.";
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
                    try {
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
                    } catch (\Exception $e) {
                        Log::warning("[project] handleRestore AI match failed: " . $e->getMessage());
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
                $reply = "Projet archive introuvable. Projets archives :\n{$list}\n\nPrecise lequel tu veux restaurer.";
            }
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_restore_not_found']);
        }

        $project->update(['status' => 'approved']);

        $reply = "✅ Projet *{$project->name}* restaure !\nDis \"switch {$project->name}\" pour l'activer.";
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
            $reply = "Projet introuvable. Precise le nom actuel.\nEx: \"renomme mon-app en mon-app-v2\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_rename_not_found']);
        }

        if (!$newName) {
            $reply = "Donne-moi le nouveau nom du projet.\nEx: \"renomme {$project->name} en nouveau-nom\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_rename_missing_name']);
        }

        if (mb_strlen($newName) > 100) {
            $reply = "Le nouveau nom est trop long (max 100 caracteres).";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_rename_invalid']);
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

        $query = Project::withCount([
            'subAgents as completed_tasks_count' => fn($q) => $q->where('status', 'completed'),
            'subAgents as running_tasks_count'   => fn($q) => $q->where('status', 'running'),
        ])->orderByDesc('created_at');

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
        $count    = $projects->count();

        $lines = ["📁 *" . ($showAll ? "Tous tes projets" : "Tes projets") . "* ({$count}) :"];
        foreach ($projects as $p) {
            $isActive = $p->id === $activeId;
            $marker   = $isActive ? ' 👈' : '';
            $archived = $p->status === 'archived' ? ' _(archive)_' : '';
            $done     = $p->completed_tasks_count ?? 0;
            $running  = $p->running_tasks_count ?? 0;
            $details  = "{$done} faite" . ($done !== 1 ? 's' : '');
            if ($running > 0) {
                $details .= " · {$running} en cours";
            }
            $lines[] = "• *{$p->name}*{$archived} ({$details}){$marker}";
        }

        $lines[] = "\nDis \"switch nom\" pour changer · \"info nom\" pour les details";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project list requested', [
            'count'    => $count,
            'show_all' => $showAll,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_list']);
    }

    // ─── Update (NEW) ──────────────────────────────────────────────────

    private function handleUpdate(AgentContext $context, array $action): AgentResult
    {
        $project = $this->resolveTargetProject($context, $action);
        $newUrl  = $action['gitlab_url'] ?? null;
        $newDesc = $action['description'] ?? null;

        if (!$project) {
            $reply = "Aucun projet cible. Precise le nom du projet ou active-en un d'abord.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_update_no_project']);
        }

        if (!$newUrl && !$newDesc) {
            $reply = "Dis-moi ce que tu veux mettre a jour :\n"
                . "• URL GitLab : \"change l'url de {$project->name} en https://gitlab.com/...\"\n"
                . "• Description : \"description de {$project->name} : nouveau texte\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_update_nothing']);
        }

        // Validate URL if provided
        if ($newUrl && !filter_var($newUrl, FILTER_VALIDATE_URL)) {
            $reply = "L'URL \"{$newUrl}\" n'est pas valide. Donne-moi une URL complete (ex: https://gitlab.com/team/mon-app).";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_update_invalid_url']);
        }

        $updates = [];
        $changes = [];

        if ($newUrl) {
            $updates['gitlab_url'] = $newUrl;
            $changes[] = "🔗 URL : {$newUrl}";
        }

        if ($newDesc) {
            $updates['request_description'] = $newDesc;
            $changes[] = "📝 Description : {$newDesc}";
        }

        try {
            $project->update($updates);
        } catch (\Exception $e) {
            Log::error("[project] handleUpdate DB error: " . $e->getMessage());
            $reply = "Erreur lors de la mise a jour. Reessaie dans un moment.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_update_error']);
        }

        $reply = "✅ Projet *{$project->name}* mis a jour !\n" . implode("\n", $changes);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project updated', [
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'updates'      => array_keys($updates),
        ]);

        return AgentResult::reply($reply, ['action' => 'project_updated', 'project_id' => $project->id]);
    }

    // ─── Info (NEW) ────────────────────────────────────────────────────

    private function handleInfo(AgentContext $context, array $action): AgentResult
    {
        $project = $this->resolveTargetProject($context, $action);

        if (!$project) {
            $reply = "Aucun projet actif. Dis \"switch nom-du-projet\" pour en selectionner un, ou precise le nom.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_info_no_project']);
        }

        $totalTasks     = SubAgent::where('project_id', $project->id)->count();
        $completedTasks = SubAgent::where('project_id', $project->id)->where('status', 'completed')->count();
        $runningTasks   = SubAgent::where('project_id', $project->id)->where('status', 'running')->count();
        $failedTasks    = SubAgent::where('project_id', $project->id)->where('status', 'failed')->count();
        $lastTask       = SubAgent::where('project_id', $project->id)->orderByDesc('updated_at')->first();

        $statusEmoji = match ($project->status) {
            'archived'    => '📦',
            'completed'   => '✅',
            'in_progress' => '🔄',
            default       => '🟢',
        };

        $statusLabel = match ($project->status) {
            'archived'    => 'Archive',
            'completed'   => 'Termine',
            'in_progress' => 'En cours',
            default       => 'Actif',
        };

        $isActive = $context->session->active_project_id === $project->id;

        $lines = [
            "📋 *{$project->name}*" . ($isActive ? ' 👈 actif' : ''),
            "",
            "{$statusEmoji} Statut : {$statusLabel}",
        ];

        if ($project->request_description) {
            $lines[] = "📝 {$project->request_description}";
        }

        if ($project->gitlab_url) {
            $lines[] = "🔗 {$project->gitlab_url}";
        }

        if ($project->created_at) {
            $lines[] = "📅 Cree " . Carbon::parse($project->created_at)->diffForHumans();
        }

        if ($totalTasks > 0) {
            $lines[] = "";
            $taskLine = "📊 {$totalTasks} tache" . ($totalTasks !== 1 ? 's' : '');
            if ($completedTasks > 0) $taskLine .= " · {$completedTasks} terminees";
            if ($runningTasks > 0)   $taskLine .= " · {$runningTasks} en cours";
            if ($failedTasks > 0)    $taskLine .= " · {$failedTasks} echouees";
            $lines[] = $taskLine;
        }

        if ($lastTask) {
            $desc      = mb_strimwidth($lastTask->task_description ?? 'sans description', 0, 60, '...');
            $statusLbl = $this->statusLabel($lastTask->status);
            $ago       = $lastTask->updated_at ? Carbon::parse($lastTask->updated_at)->diffForHumans() : '';
            $lines[]   = "🔧 Derniere tache : \"{$desc}\" ({$statusLbl}" . ($ago ? ", {$ago}" : '') . ")";
        }

        if ($project->requester_name) {
            $lines[] = "👤 Cree par : {$project->requester_name}";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project info requested', ['project_id' => $project->id]);

        return AgentResult::reply($reply, ['action' => 'project_info', 'project_id' => $project->id]);
    }

    // ─── Delete (NEW) ──────────────────────────────────────────────────

    private function handleDelete(AgentContext $context, array $action): AgentResult
    {
        $name    = $action['project_name'] ?? null;
        $project = null;

        // Only archived projects can be deleted
        if ($name) {
            $project = Project::where('status', 'archived')
                ->where(function ($q) use ($name) {
                    $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                      ->orWhere('name', 'LIKE', "%{$name}%");
                })
                ->first();
        }

        if (!$project) {
            $reply = "Je ne peux supprimer que des projets *archives*.\n"
                . ($name ? "Le projet \"{$name}\" n'est pas archive ou n'existe pas.\n" : '')
                . "Archive d'abord un projet : \"archive {$name}\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_delete_not_archived']);
        }

        $taskCount = SubAgent::where('project_id', $project->id)->count();

        $reply = "⚠️ Supprimer *{$project->name}* definitivement ?\n"
            . ($taskCount > 0 ? "{$taskCount} tache" . ($taskCount > 1 ? 's' : '') . " associee" . ($taskCount > 1 ? 's' : '') . " seront perdues.\n" : '')
            . "Cette action est *irreversible*. Reponds \"oui\" pour confirmer.";

        $this->setPendingContext($context, 'delete_confirm', [
            'project_id'   => $project->id,
            'project_name' => $project->name,
        ], 5);

        $this->sendText($context->from, $reply);

        $this->log($context, 'Project delete proposed', [
            'project_id'   => $project->id,
            'project_name' => $project->name,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_delete_proposed']);
    }

    private function handleDeleteConfirmation(AgentContext $context, array $data): AgentResult
    {
        $this->clearPendingContext($context);

        $projectId   = $data['project_id'] ?? null;
        $projectName = $data['project_name'] ?? 'inconnu';

        try {
            $classification = $this->claude->chat(
                "Message: \"{$context->body}\"",
                'claude-haiku-4-5-20251001',
                "L'utilisateur confirme-t-il une suppression definitive ? Reponds OUI ou NON uniquement.\n"
                . "OUI = confirme (oui, yes, ok, confirme, go, d'accord, supprimer, delete...)\n"
                . "NON = refuse (non, annule, stop, non merci, laisse tomber, nope...)"
            );
            $intent = strtoupper(trim($classification ?? ''));
        } catch (\Exception $e) {
            Log::warning("[project] handleDeleteConfirmation AI failed: " . $e->getMessage());
            $intent = 'NON';
        }

        if (!str_contains($intent, 'OUI')) {
            $reply = "Suppression annulee. Le projet *{$projectName}* est conserve.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_delete_cancelled']);
        }

        $project = Project::find($projectId);

        if (!$project) {
            $reply = "Projet introuvable. Il a peut-etre deja ete supprime.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_delete_not_found']);
        }

        // Safety guard: only archived projects can be deleted
        if ($project->status !== 'archived') {
            $reply = "Impossible de supprimer un projet non archive. Archive-le d'abord.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_delete_not_archived']);
        }

        try {
            if ($context->session->active_project_id === $project->id) {
                $context->session->update(['active_project_id' => null]);
            }
            $project->delete();
        } catch (\Exception $e) {
            Log::error("[project] handleDeleteConfirmation delete failed: " . $e->getMessage());
            $reply = "Erreur lors de la suppression. Reessaie dans un moment.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_delete_error']);
        }

        $reply = "🗑️ Projet *{$projectName}* supprime definitivement.";
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project deleted', [
            'project_id'   => $projectId,
            'project_name' => $projectName,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_deleted']);
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

        // Exact name match (case-insensitive)
        foreach ($projects as $project) {
            if (mb_stripos($body, $project->name) !== false) {
                return $project;
            }
        }

        // GitLab repo slug match
        foreach ($projects as $project) {
            $slug = basename(parse_url($project->gitlab_url ?? '', PHP_URL_PATH) ?? '');
            $slug = str_replace('.git', '', $slug);
            if ($slug && mb_stripos($body, $slug) !== false) {
                return $project;
            }
        }

        // AI match with Haiku
        $projectList = $projects->map(fn($p) => "- ID:{$p->id} nom:\"{$p->name}\" url:{$p->gitlab_url}")->implode("\n");

        try {
            $response = $this->claude->chat(
                "Message utilisateur: \"{$body}\"\n\nProjets disponibles:\n{$projectList}",
                'claude-haiku-4-5-20251001',
                "L'utilisateur mentionne un projet. Trouve le projet le plus probable dans la liste.\n"
                . "Reponds UNIQUEMENT avec l'ID du projet (ex: 42) ou AUCUN si aucun ne correspond.\n"
                . "Gere les noms partiels, fautes de frappe, descriptions vagues.\n"
                . "Reponds un seul mot: l'ID ou AUCUN."
            );

            $clean = trim($response ?? '');
            if ($clean === 'AUCUN' || !is_numeric($clean)) return null;

            return $projects->firstWhere('id', (int) $clean);
        } catch (\Exception $e) {
            Log::warning("[project] smartMatchProject AI failed: " . $e->getMessage());
            return null;
        }
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
        if ($seconds < 60)   return "{$seconds}s";
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
