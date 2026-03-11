<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Models\SubAgent;
use App\Services\AgentContext;
use App\Services\ModelResolver;
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
        return 'Agent de gestion de projets. Permet de changer de projet actif, creer/supprimer un projet, voir les statistiques et la progression hebdomadaire, archiver/restaurer, renommer, mettre a jour l\'URL GitLab ou la description, definir une priorite, voir les taches recentes, ajouter/consulter des notes, et lister tous les projets.';
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
            'priorite projet', 'priorite', 'urgent', 'haute priorite', 'basse priorite', 'priority',
            'taches recentes', 'dernieres taches', 'historique projet', 'activite projet', 'recent tasks',
            'note projet', 'noter projet', 'ajouter note', 'notes projet', 'memo projet', 'notes du projet',
            'progression projet', 'progres projet', 'avancement projet', 'progress projet', 'progression cette semaine',
        ];
    }

    public function version(): string
    {
        return '1.4.0';
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
            'create'   => $this->handleCreate($context, $action),
            'stats'    => $this->handleStats($context, $action),
            'archive'  => $this->handleArchive($context, $action),
            'restore'  => $this->handleRestore($context, $action),
            'rename'   => $this->handleRename($context, $action),
            'list'     => $this->handleList($context, $action),
            'update'   => $this->handleUpdate($context, $action),
            'info'     => $this->handleInfo($context, $action),
            'delete'   => $this->handleDelete($context, $action),
            'priority' => $this->handlePriority($context, $action),
            'recent'   => $this->handleRecent($context, $action),
            'notes'    => $this->handleNotes($context, $action),
            'progress' => $this->handleProgress($context, $action),
            default    => $this->handleProjectSwitch($context),
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
                ModelResolver::fast(),
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
{"action": "switch|create|stats|archive|restore|rename|list|update|info|delete|priority|recent|notes|progress", "project_name": "...", "new_name": "...", "gitlab_url": "...", "description": "...", "show_all": false, "priority": null, "note_text": null, "filter_priority": null}

ACTIONS:
- "switch": changer de projet actif (ex: "bosse sur mon-app", "switch zeniclaw", "projet X")
- "create": creer un nouveau projet (ex: "cree un projet mon-app", "nouveau projet test-api avec gitlab.com/...")
- "stats": voir les statistiques d'un projet (ex: "stats du projet", "comment va mon projet", "statistiques")
- "archive": archiver un projet (ex: "archive le projet X", "archive mon-app")
- "restore": restaurer/desarchiver un projet (ex: "restaure le projet X", "reactive le projet Y")
- "rename": renommer un projet (ex: "renomme zeniclaw en zeniclaw-v2", "le projet X s'appelle maintenant Y")
- "list": lister les projets (ex: "mes projets", "liste des projets", "tous mes projets", "quels projets", "projets urgents")
- "update": mettre a jour l'URL GitLab ou la description d'un projet (ex: "change l'url gitlab de mon-app", "met a jour la description", "l'url du projet est maintenant https://...")
- "info": voir les details complets d'un projet (ex: "infos sur le projet", "details du projet X", "montre moi le projet Y")
- "delete": supprimer definitivement un projet archive (ex: "supprime le projet test", "efface le projet archive X")
- "priority": definir ou voir la priorite d'un projet (ex: "met le projet en urgent", "priorite haute pour zeniclaw", "quelle est la priorite", "priorite normale")
- "recent": voir les dernieres taches d'un projet (ex: "dernieres taches", "activite recente du projet", "historique zeniclaw", "quoi de neuf sur le projet")
- "notes": ajouter ou consulter les notes d'un projet (ex: "note sur zeniclaw: surveiller la migration", "notes du projet", "voir mes notes", "memo mon-app: RDV client vendredi")
- "progress": voir la progression hebdomadaire d'un projet (ex: "progression du projet", "avancement cette semaine", "progres zeniclaw")

CHAMPS:
- project_name: nom actuel du projet mentionne (ou null si non mentionne)
- new_name: nouveau nom si action=rename (ou null)
- gitlab_url: URL GitLab si mentionnee (ou null)
- description: description courte du projet si fournie (ou null)
- show_all: true si l'utilisateur demande "tous" les projets (inclut les archives)
- priority: "urgent"|"haute"|"normale"|"basse" si action=priority et une valeur est donnee (ou null pour juste consulter)
- note_text: texte de la note si action=notes et qu'il y a un texte a ajouter (ou null pour juste consulter)
- filter_priority: "urgent"|"haute"|"normale"|"basse" si l'utilisateur filtre la liste par priorite (ou null)

EXEMPLES:
- "bosse sur zeniclaw" → {"action": "switch", "project_name": "zeniclaw", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "cree un projet mon-app avec https://gitlab.com/team/mon-app" → {"action": "create", "project_name": "mon-app", "new_name": null, "gitlab_url": "https://gitlab.com/team/mon-app", "description": null, "show_all": false, "priority": null}
- "nouveau projet api-gateway API de routage interne" → {"action": "create", "project_name": "api-gateway", "new_name": null, "gitlab_url": null, "description": "API de routage interne", "show_all": false, "priority": null}
- "stats du projet" → {"action": "stats", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "archive le projet test" → {"action": "archive", "project_name": "test", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "restaure le projet test" → {"action": "restore", "project_name": "test", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "renomme zeniclaw en zeniclaw-v2" → {"action": "rename", "project_name": "zeniclaw", "new_name": "zeniclaw-v2", "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "mes projets" → {"action": "list", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "tous mes projets" → {"action": "list", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": true, "priority": null}
- "change l'url gitlab de mon-app en https://gitlab.com/new/mon-app" → {"action": "update", "project_name": "mon-app", "new_name": null, "gitlab_url": "https://gitlab.com/new/mon-app", "description": null, "show_all": false, "priority": null}
- "mets a jour la description: nouvelle API REST" → {"action": "update", "project_name": null, "new_name": null, "gitlab_url": null, "description": "nouvelle API REST", "show_all": false, "priority": null}
- "infos sur le projet zeniclaw" → {"action": "info", "project_name": "zeniclaw", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "supprime le projet test" → {"action": "delete", "project_name": "test", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "met le projet zeniclaw en urgent" → {"action": "priority", "project_name": "zeniclaw", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": "urgent"}
- "priorite normale pour mon-app" → {"action": "priority", "project_name": "mon-app", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": "normale"}
- "quelle est la priorite du projet" → {"action": "priority", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "dernieres taches du projet zeniclaw" → {"action": "recent", "project_name": "zeniclaw", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null}
- "activite recente" → {"action": "recent", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null, "note_text": null, "filter_priority": null}
- "note sur zeniclaw: surveiller la migration DB" → {"action": "notes", "project_name": "zeniclaw", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null, "note_text": "surveiller la migration DB", "filter_priority": null}
- "notes du projet" → {"action": "notes", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null, "note_text": null, "filter_priority": null}
- "progression du projet" → {"action": "progress", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null, "note_text": null, "filter_priority": null}
- "avancement zeniclaw cette semaine" → {"action": "progress", "project_name": "zeniclaw", "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null, "note_text": null, "filter_priority": null}
- "mes projets urgents" → {"action": "list", "project_name": null, "new_name": null, "gitlab_url": null, "description": null, "show_all": false, "priority": null, "note_text": null, "filter_priority": "urgent"}

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
                ModelResolver::fast(),
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

        $priority      = $project->getSetting('priority');
        $priorityBadge = $this->priorityBadge($priority);

        $lines = ["✅ Projet *{$project->name}* active !" . ($priorityBadge ? " {$priorityBadge}" : '')];

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

        // Aggregate counts in DB (compatible SQLite + MySQL)
        $counts = SubAgent::where('project_id', $project->id)
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'running'   THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'failed'    THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) as pending")
            ->first();

        $total     = (int) ($counts->total ?? 0);
        $completed = (int) ($counts->completed ?? 0);
        $running   = (int) ($counts->running ?? 0);
        $failed    = (int) ($counts->failed ?? 0);
        $pending   = (int) ($counts->pending ?? 0);

        // Success rate (completed vs completed+failed)
        $doneCount   = $completed + $failed;
        $successRate = $doneCount > 0 ? round(($completed / $doneCount) * 100) : null;

        // Average execution time for completed tasks (only load those with times)
        $avgTime            = null;
        $completedWithTimes = SubAgent::where('project_id', $project->id)
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get(['started_at', 'completed_at']);
        if ($completedWithTimes->isNotEmpty()) {
            $totalSeconds = $completedWithTimes->sum(
                fn($s) => Carbon::parse($s->completed_at)->diffInSeconds(Carbon::parse($s->started_at))
            );
            $avgSeconds = (int) ($totalSeconds / $completedWithTimes->count());
            $avgTime    = $this->formatDuration($avgSeconds);
        }

        // Last activity
        $lastActivity     = SubAgent::where('project_id', $project->id)->orderByDesc('updated_at')->first(['updated_at']);
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
            $runningTasks = SubAgent::where('project_id', $project->id)
                ->where('status', 'running')
                ->orderByDesc('updated_at')
                ->limit(3)
                ->get(['task_description']);
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
                            ModelResolver::fast(),
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
        $showAll        = $action['show_all'] ?? false;
        $filterPriority = $action['filter_priority'] ?? null;
        if ($filterPriority) {
            $filterPriority = mb_strtolower($filterPriority);
        }

        $query = Project::withCount([
            'subAgents as completed_tasks_count' => fn($q) => $q->where('status', 'completed'),
            'subAgents as running_tasks_count'   => fn($q) => $q->where('status', 'running'),
            'subAgents as pending_tasks_count'   => fn($q) => $q->where('status', 'pending'),
        ])->orderByDesc('created_at');

        if ($showAll) {
            $query->whereIn('status', ['approved', 'in_progress', 'completed', 'archived']);
        } else {
            $query->whereIn('status', ['approved', 'in_progress', 'completed']);
        }

        $projects = $query->limit(20)->get();

        // Filter by priority if requested (settings JSON filter in PHP)
        if ($filterPriority) {
            $projects = $projects->filter(fn($p) => $p->getSetting('priority') === $filterPriority)->values();
        }

        if ($projects->isEmpty()) {
            $reply = $filterPriority
                ? "Aucun projet avec priorite \"{$filterPriority}\". Dis \"mes projets\" pour voir tous tes projets."
                : "Aucun projet trouve. Dis \"cree un projet mon-app\" pour en creer un.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_list_empty']);
        }

        $activeId = $context->session->active_project_id;
        $count    = $projects->count();

        $title = $filterPriority
            ? "Projets priorite " . ucfirst($filterPriority)
            : ($showAll ? "Tous tes projets" : "Tes projets");

        $lines = ["📁 *{$title}* ({$count}) :"];
        foreach ($projects as $p) {
            $isActive      = $p->id === $activeId;
            $marker        = $isActive ? ' 👈' : '';
            $archived      = $p->status === 'archived' ? ' _(archive)_' : '';
            $done          = $p->completed_tasks_count ?? 0;
            $running       = $p->running_tasks_count ?? 0;
            $pending       = $p->pending_tasks_count ?? 0;
            $priorityBadge = $this->priorityBadge($p->getSetting('priority'));
            $details       = "{$done} faite" . ($done !== 1 ? 's' : '');
            if ($running > 0) {
                $details .= " · {$running} en cours";
            }
            if ($pending > 0) {
                $details .= " · {$pending} en attente";
            }
            $lines[] = "• *{$p->name}*{$archived} ({$details}){$priorityBadge}{$marker}";
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

        // Single query for all task counts (CASE WHEN for SQLite + MySQL compatibility)
        $taskCounts = SubAgent::where('project_id', $project->id)
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'running'   THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'failed'    THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) as pending")
            ->first();

        $totalTasks     = (int) ($taskCounts->total ?? 0);
        $completedTasks = (int) ($taskCounts->completed ?? 0);
        $runningTasks   = (int) ($taskCounts->running ?? 0);
        $failedTasks    = (int) ($taskCounts->failed ?? 0);
        $pendingTasks   = (int) ($taskCounts->pending ?? 0);
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

        $isActive      = $context->session->active_project_id === $project->id;
        $priority      = $project->getSetting('priority');
        $priorityBadge = $this->priorityBadge($priority);

        $lines = [
            "📋 *{$project->name}*" . ($isActive ? ' 👈 actif' : '') . ($priorityBadge ? " {$priorityBadge}" : ''),
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
            if ($pendingTasks > 0)   $taskLine .= " · {$pendingTasks} en attente";
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
                ModelResolver::fast(),
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

    // ─── Priority (NEW) ────────────────────────────────────────────────

    private function handlePriority(AgentContext $context, array $action): AgentResult
    {
        $project  = $this->resolveTargetProject($context, $action);
        $priority = $action['priority'] ?? null;

        if (!$project) {
            $reply = "Aucun projet actif. Precise le nom du projet ou active-en un d'abord.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_priority_no_project']);
        }

        // Read-only: just show current priority
        if (!$priority) {
            $current = $project->getSetting('priority');
            $badge   = $this->priorityBadge($current);
            $label   = $current ? ucfirst($current) : 'non definie';
            $reply   = "🎯 Priorite du projet *{$project->name}* : {$label}" . ($badge ? " {$badge}" : '') . "\n\n"
                . "Pour changer : \"priorite urgent|haute|normale|basse pour {$project->name}\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_priority_view', 'project_id' => $project->id]);
        }

        $allowed = ['urgent', 'haute', 'normale', 'basse'];
        if (!in_array(mb_strtolower($priority), $allowed)) {
            $reply = "Priorite invalide. Valeurs acceptees : urgent, haute, normale, basse.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_priority_invalid']);
        }

        $priority = mb_strtolower($priority);

        try {
            $project->setSetting('priority', $priority);
        } catch (\Exception $e) {
            Log::error("[project] handlePriority setSetting failed: " . $e->getMessage());
            $reply = "Erreur lors de la mise a jour de la priorite. Reessaie dans un moment.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_priority_error']);
        }

        $badge = $this->priorityBadge($priority);
        $reply = "🎯 Priorite du projet *{$project->name}* definie a *{$priority}* {$badge}";
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project priority updated', [
            'project_id' => $project->id,
            'priority'   => $priority,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_priority_set', 'project_id' => $project->id]);
    }

    // ─── Recent (NEW) ──────────────────────────────────────────────────

    private function handleRecent(AgentContext $context, array $action): AgentResult
    {
        $project = $this->resolveTargetProject($context, $action);

        if (!$project) {
            $reply = "Aucun projet actif. Dis \"switch nom-du-projet\" pour en selectionner un, ou precise le nom.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_recent_no_project']);
        }

        $tasks = SubAgent::where('project_id', $project->id)
            ->orderByDesc('updated_at')
            ->limit(7)
            ->get();

        if ($tasks->isEmpty()) {
            $reply = "Aucune tache enregistree pour le projet *{$project->name}*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_recent_empty', 'project_id' => $project->id]);
        }

        $lines = ["🕐 *Activite recente : {$project->name}*", ""];

        foreach ($tasks as $task) {
            $statusIcon = match ($task->status) {
                'completed' => '✅',
                'failed'    => '❌',
                'running'   => '🔄',
                'pending'   => '⏳',
                default     => '•',
            };
            $desc = mb_strimwidth($task->task_description ?? 'sans description', 0, 55, '...');
            $ago  = $task->updated_at ? Carbon::parse($task->updated_at)->diffForHumans() : '';

            $line = "{$statusIcon} {$desc}";
            if ($ago) {
                $line .= " _({$ago})_";
            }
            $lines[] = $line;
        }

        $total = SubAgent::where('project_id', $project->id)->count();
        if ($total > 7) {
            $lines[] = "";
            $lines[] = "_(+ " . ($total - 7) . " autres taches — dis \"stats {$project->name}\" pour le detail)_";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project recent tasks requested', ['project_id' => $project->id]);

        return AgentResult::reply($reply, ['action' => 'project_recent', 'project_id' => $project->id]);
    }

    // ─── Notes ─────────────────────────────────────────────────────────

    private function handleNotes(AgentContext $context, array $action): AgentResult
    {
        $project  = $this->resolveTargetProject($context, $action);
        $noteText = trim($action['note_text'] ?? '');

        if (!$project) {
            $reply = "Aucun projet actif. Precise le nom du projet ou active-en un d'abord.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_notes_no_project']);
        }

        // Add a new note
        if ($noteText) {
            if (mb_strlen($noteText) > 500) {
                $reply = "La note est trop longue (max 500 caracteres).";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'project_notes_too_long']);
            }

            $notes = $project->getSetting('notes', []);
            if (!is_array($notes)) {
                $notes = [];
            }

            $notes[] = [
                'text' => $noteText,
                'at'   => now()->toISOString(),
            ];

            // Keep only the last 10 notes
            if (count($notes) > 10) {
                $notes = array_slice($notes, -10);
            }

            try {
                $project->setSetting('notes', $notes);
            } catch (\Exception $e) {
                Log::error("[project] handleNotes setSetting failed: " . $e->getMessage());
                $reply = "Erreur lors de l'enregistrement de la note. Reessaie dans un moment.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'project_notes_error']);
            }

            $reply = "📌 Note ajoutee au projet *{$project->name}* :\n\"{$noteText}\"";
            $this->sendText($context->from, $reply);

            $this->log($context, 'Project note added', [
                'project_id'   => $project->id,
                'project_name' => $project->name,
            ]);

            return AgentResult::reply($reply, ['action' => 'project_note_added', 'project_id' => $project->id]);
        }

        // View notes
        $notes = $project->getSetting('notes', []);
        if (empty($notes)) {
            $reply = "Aucune note pour le projet *{$project->name}*.\n\n"
                . "Ajoute-en une : \"note sur {$project->name}: ton texte\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_notes_empty', 'project_id' => $project->id]);
        }

        $lines = ["📌 *Notes : {$project->name}* (" . count($notes) . ")", ""];
        foreach (array_reverse($notes) as $i => $note) {
            $text = is_array($note) ? ($note['text'] ?? '') : (string) $note;
            $at   = is_array($note) && isset($note['at'])
                ? ' _(' . Carbon::parse($note['at'])->diffForHumans() . ')_'
                : '';
            $lines[] = ($i + 1) . ". {$text}{$at}";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project notes viewed', ['project_id' => $project->id]);

        return AgentResult::reply($reply, ['action' => 'project_notes_view', 'project_id' => $project->id]);
    }

    // ─── Progress ──────────────────────────────────────────────────────

    private function handleProgress(AgentContext $context, array $action): AgentResult
    {
        $project = $this->resolveTargetProject($context, $action);

        if (!$project) {
            $reply = "Aucun projet actif. Precise le nom du projet ou active-en un d'abord.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'project_progress_no_project']);
        }

        $weekStart     = Carbon::now()->startOfWeek(\Carbon\Carbon::MONDAY)->setTime(0, 0, 0);
        $lastWeekStart = $weekStart->copy()->subWeek();
        $lastWeekEnd   = $weekStart->copy()->subSecond();

        // This week completed
        $thisWeek = SubAgent::where('project_id', $project->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $weekStart)
            ->count();

        // Last week completed
        $lastWeek = SubAgent::where('project_id', $project->id)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$lastWeekStart, $lastWeekEnd])
            ->count();

        // Currently running
        $running = SubAgent::where('project_id', $project->id)
            ->where('status', 'running')
            ->count();

        // Total completed all time
        $totalCompleted = SubAgent::where('project_id', $project->id)
            ->where('status', 'completed')
            ->count();

        // Trend indicator
        $trend = '';
        if ($lastWeek > 0) {
            $delta = $thisWeek - $lastWeek;
            if ($delta > 0)      $trend = " 📈 +{$delta} vs sem. derniere";
            elseif ($delta < 0)  $trend = " 📉 {$delta} vs sem. derniere";
            else                  $trend = " → stable vs sem. derniere";
        }

        $priorityBadge = $this->priorityBadge($project->getSetting('priority'));

        $lines = [
            "📈 *Progression : {$project->name}*" . ($priorityBadge ? " {$priorityBadge}" : ''),
            "",
            "Cette semaine  : {$thisWeek} tache" . ($thisWeek !== 1 ? 's' : '') . " completee" . ($thisWeek !== 1 ? 's' : '') . $trend,
            "Semaine passee : {$lastWeek} tache" . ($lastWeek !== 1 ? 's' : '') . " completee" . ($lastWeek !== 1 ? 's' : ''),
        ];

        if ($running > 0) {
            $lines[] = "🔄 En cours : {$running} tache" . ($running !== 1 ? 's' : '');
        }

        $lines[] = "";
        $lines[] = "Total completes : {$totalCompleted}";

        if ($thisWeek === 0 && $lastWeek === 0 && $running === 0) {
            $lines[] = "_Aucune activite recente sur ce projet._";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Project progress viewed', [
            'project_id'     => $project->id,
            'this_week'      => $thisWeek,
            'last_week'      => $lastWeek,
            'total_completed'=> $totalCompleted,
        ]);

        return AgentResult::reply($reply, ['action' => 'project_progress', 'project_id' => $project->id]);
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
                ModelResolver::fast(),
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

    private function priorityBadge(?string $priority): string
    {
        return match ($priority) {
            'urgent'  => '🔴',
            'haute'   => '🟠',
            'normale' => '🟡',
            'basse'   => '🟢',
            default   => '',
        };
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

    // ── ToolProviderInterface ──────────────────────────────────────

    public function tools(): array
    {
        return array_merge(parent::tools(), [
            [
                'name' => 'switch_project',
                'description' => 'Switch the active project for the user.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_name' => ['type' => 'string', 'description' => 'Name or partial name of the project to switch to'],
                    ],
                    'required' => ['project_name'],
                ],
            ],
            [
                'name' => 'list_projects',
                'description' => 'List all projects available to the user.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'show_archived' => ['type' => 'boolean', 'description' => 'Include archived projects'],
                    ],
                ],
            ],
            [
                'name' => 'project_stats',
                'description' => 'Get statistics for a project (tasks completed, in progress, etc.).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_name' => ['type' => 'string', 'description' => 'Project name (null for active project)'],
                    ],
                ],
            ],
        ]);
    }

    public function executeTool(string $name, array $input, AgentContext $context): ?string
    {
        return match ($name) {
            'switch_project' => $this->toolSwitchProject($input, $context),
            'list_projects' => $this->toolListProjects($input, $context),
            'project_stats' => $this->toolProjectStats($input, $context),
            default => parent::executeTool($name, $input, $context),
        };
    }

    private function toolSwitchProject(array $input, AgentContext $context): string
    {
        $name = $input['project_name'];

        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        $match = null;

        foreach ($projects as $project) {
            if (mb_stripos($project->name, $name) !== false) {
                $match = $project;
                break;
            }
        }

        if (!$match) {
            foreach ($projects as $project) {
                $slug = basename(parse_url($project->gitlab_url, PHP_URL_PATH) ?? '');
                $slug = str_replace('.git', '', $slug);
                if ($slug && mb_stripos($slug, $name) !== false) {
                    $match = $project;
                    break;
                }
            }
        }

        if (!$match) {
            $available = $projects->map(fn($p) => $p->name)->implode(', ');
            return json_encode(['error' => "Project \"{$name}\" not found.", 'available_projects' => $available]);
        }

        $context->session->update(['active_project_id' => $match->id]);

        return json_encode([
            'success' => true,
            'project_name' => $match->name,
            'project_id' => $match->id,
            'gitlab_url' => $match->gitlab_url,
        ]);
    }

    private function toolListProjects(array $input, AgentContext $context): string
    {
        $showArchived = $input['show_archived'] ?? false;

        $statuses = ['approved', 'in_progress', 'completed'];
        if ($showArchived) {
            $statuses[] = 'archived';
        }

        $projects = Project::whereIn('status', $statuses)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $activeId = $context->session->active_project_id;

        $list = [];
        foreach ($projects as $p) {
            $list[] = [
                'name' => $p->name,
                'status' => $p->status,
                'gitlab_url' => $p->gitlab_url,
                'is_active' => $p->id === $activeId,
                'task_count' => $p->subAgents()->where('status', 'completed')->count(),
            ];
        }

        return json_encode(['projects' => $list]);
    }

    private function toolProjectStats(array $input, AgentContext $context): string
    {
        $name = $input['project_name'] ?? null;
        $project = null;

        if ($name) {
            $project = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
                ->where('name', 'ilike', "%{$name}%")
                ->first();
        }

        if (!$project && $context->session->active_project_id) {
            $project = Project::find($context->session->active_project_id);
        }

        if (!$project) {
            return json_encode(['error' => 'No project found. Specify a project name or switch to one first.']);
        }

        $subAgents = $project->subAgents()->get();

        return json_encode([
            'project' => $project->name,
            'gitlab_url' => $project->gitlab_url,
            'total_tasks' => $subAgents->count(),
            'completed' => $subAgents->where('status', 'completed')->count(),
            'failed' => $subAgents->where('status', 'failed')->count(),
            'running' => $subAgents->where('status', 'running')->count(),
            'pending' => $subAgents->whereIn('status', ['queued', 'pending'])->count(),
        ]);
    }
}
