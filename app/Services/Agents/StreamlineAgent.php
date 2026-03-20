<?php

namespace App\Services\Agents;

use App\Models\Reminder;
use App\Models\Workflow;
use App\Services\AgentContext;
use App\Services\AgentOrchestrator;
use App\Services\WorkflowExecutor;
use Illuminate\Support\Facades\Log;

class StreamlineAgent extends BaseAgent
{
    public function name(): string
    {
        return 'streamline';
    }

    public function description(): string
    {
        return 'Agent de workflows automatises. Permet de chainer plusieurs agents en sequence, creer des workflows reutilisables, executer des pipelines multi-etapes avec passage de contexte entre agents, gerer des conditions et branches.';
    }

    public function keywords(): array
    {
        return [
            'workflow', 'chain', 'chainer', 'then', 'ensuite', 'apres',
            'pipeline', 'automatiser', 'automate', 'sequence',
            'enchainer', 'etape', 'step', 'puis', 'and then',
            '/workflow', 'workflow create', 'workflow list', 'workflow trigger',
            'workflow delete', 'workflow run', 'lancer workflow',
            'creer workflow', 'mes workflows', 'supprimer workflow',
            'when done', 'quand fini', 'after that', 'apres ca',
            'workflow enable', 'workflow disable', 'workflow rename',
            'activer workflow', 'desactiver workflow', 'renommer workflow',
            'workflow duplicate', 'workflow copy', 'copier workflow', 'dupliquer workflow',
            'workflow stats', 'statistiques workflow',
            'workflow edit', 'workflow export', 'workflow add',
            'modifier workflow', 'modifier etape', 'ajouter etape',
            'exporter workflow', 'partager workflow',
            'workflow remove-step', 'supprimer etape', 'enlever etape',
            'workflow describe', 'description workflow', 'decrire workflow',
            'workflow move', 'workflow move-step', 'deplacer etape', 'reordonner etape',
            'workflow history', 'historique workflow', 'dernieres executions',
            'workflow search', 'workflow find', 'chercher workflow', 'rechercher workflow',
            'workflow import', 'importer workflow', 'recreer workflow',
            'workflow dryrun', 'dryrun', 'tester workflow', 'simuler workflow', 'preview workflow',
            'workflow reset-stats', 'reset stats workflow', 'reinitialiser stats', 'remettre a zero',
            'workflow template', 'template workflow', 'modele workflow', 'workflow from template',
            'utiliser template', 'creer depuis modele', 'templates disponibles',
            'workflow pin', 'workflow unpin', 'epingler workflow', 'desepingler workflow',
            'pinned workflow', 'workflow epingle',
            'workflow insert', 'workflow insert-step', 'inserer etape', 'insérer étape',
            'workflow tag', 'workflow tags', 'etiquette workflow', 'tagger workflow',
            'tag workflow', '#workflow',
            'workflow run-all', 'run all workflows', 'lancer tous les workflows',
            'executer tous', 'tous mes workflows', 'run-all',
            'workflow summary', 'resumé workflow', 'resumer workflow', 'expliquer workflow',
            'que fait ce workflow', 'workflow clone',
            'workflow step-config', 'step-config', 'config etape', 'configurer etape',
            'changer agent etape', 'modifier condition etape', 'changer condition',
            'workflow suggest', 'suggere workflow', 'suggerer workflow', 'propose workflow',
            'proposer workflow', 'idee workflow', 'cree automatiquement', 'genere workflow',
            'workflow batch', 'batch workflow', 'lancer plusieurs workflows', 'multi-workflow',
            'enchaîner workflows', 'enchainer workflows', 'plusieurs workflows',
            'workflow notes', 'workflow note', 'note workflow', 'annoter workflow',
            'memo workflow', 'commentaire workflow',
            'workflow health', 'health workflow', 'sante workflow', 'etat workflows',
            'verifier workflows', 'audit workflow', 'problemes workflow',
            'workflow quick', 'quick workflow', 'lancer rapidement', 'run rapide',
            'trouver et lancer', 'chercher et executer',
            'workflow last', 'dernier workflow', 'relancer workflow', 'relancer dernier',
            'rejouer workflow', 'replay workflow',
            'workflow copy-step', 'copier etape', 'copier une etape', 'transferer etape',
            'workflow diff', 'comparer workflows', 'comparer deux workflows', 'difference workflow',
            'workflow favorites', 'workflow favoris', 'mes favoris', 'workflows preferes',
            'top workflows', 'workflows les plus utilises',
            'workflow schedule', 'planifier workflow', 'programmer workflow', 'workflow cron',
            'workflow automatique', 'lancer automatiquement', 'executer chaque jour',
            'workflow merge', 'fusionner workflows', 'combiner workflows', 'merger workflows',
            'unir workflows', 'joindre workflows',
            'workflow optimize', 'workflow optimise', 'optimiser workflow', 'ameliorer workflow',
            'workflow swap', 'workflow swap-step', 'echanger etapes', 'inverser etapes',
            'permuter etapes', 'swap etape',
            'workflow undo', 'annuler modification workflow', 'restaurer workflow',
            'revenir en arriere workflow', 'undo workflow',
            'workflow dashboard', 'workflow dash', 'tableau de bord workflow',
            'resume workflows', 'apercu workflows',
            'workflow retry', 'relancer workflow', 'reessayer workflow', 'retry workflow',
            'workflow clean', 'workflow cleanup', 'nettoyer workflows', 'menage workflow',
            'cleanup workflows', 'purger workflows',
            'workflow status', 'statut workflow', 'etat workflow', 'status workflows',
            'workflow help create', 'workflow help execute', 'workflow help organize',
            'workflow test-step', 'tester etape', 'test etape', 'essayer etape',
            'workflow disable-step', 'workflow enable-step', 'desactiver etape', 'activer etape',
            'skip etape', 'sauter etape', 'ignorer etape',
            'workflow graph', 'workflow flow', 'graphe workflow', 'visualiser workflow',
            'flux workflow', 'schema workflow', 'flowchart workflow',
            'workflow recent', 'workflows recents', 'derniers workflows',
        ];
    }

    public function version(): string
    {
        return '1.23.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return $context->routedAgent === 'streamline'
            || (bool) preg_match('/\b(workflow|chain|pipeline|enchainer|chainer|\/workflow|automatiser|automate|sequence|lancer workflow|mes workflows|creer workflow|gerer workflows)\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (empty($body)) {
            return $this->showHelp($context);
        }

        $lower = mb_strtolower($body);

        // Parse /workflow commands
        if (str_starts_with($lower, '/workflow')) {
            return $this->handleCommand($context, $body);
        }

        // Detect inline workflow patterns (then/chain/after/>>)
        if ($this->isInlineChain($lower)) {
            return $this->handleInlineChain($context, $body);
        }

        // Detect workflow-related keywords
        if (preg_match('/\b(workflow|pipeline)\b/iu', $lower)) {
            return $this->handleNaturalLanguage($context, $body);
        }

        return $this->showHelp($context);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';
        $data = $pendingContext['data'] ?? [];

        if ($type === 'confirm_workflow') {
            $lower = mb_strtolower(trim($context->body ?? ''));
            if (in_array($lower, ['oui', 'yes', 'ok', 'go', 'lance', 'confirme', 'o'])) {
                $this->clearPendingContext($context);
                return $this->createWorkflowFromParsed($context, $data);
            }
            if (in_array($lower, ['non', 'no', 'annuler', 'cancel', 'n', 'stop'])) {
                $this->clearPendingContext($context);
                return AgentResult::reply('Workflow annule.');
            }
            // Allow custom name: if the response looks like a valid workflow name, use it
            $customName = mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', trim($context->body ?? '')));
            $customName = trim($customName, '-');
            if (!empty($customName) && preg_match('/^[a-zA-Z0-9\-_]+$/', $customName) && mb_strlen($customName) <= 50) {
                // Check for conflict
                $conflict = Workflow::forUser($context->from)->where('name', $customName)->first();
                if ($conflict) {
                    return AgentResult::reply(
                        "Un workflow nomme \"{$customName}\" existe deja.\n"
                        . "Choisis un autre nom, ou reponds \"oui\" pour garder \"{$data['name']}\"."
                    );
                }
                $data['name'] = $customName;
                $this->clearPendingContext($context);
                return $this->createWorkflowFromParsed($context, $data);
            }
            $this->clearPendingContext($context);
            return AgentResult::reply('Workflow annule. Reponds "oui" pour confirmer ou "non" pour annuler.');
        }

        if ($type === 'confirm_delete') {
            $lower = mb_strtolower(trim($context->body ?? ''));
            $this->clearPendingContext($context);
            if (in_array($lower, ['oui', 'yes', 'ok', 'confirme', 'supprimer', 'o'])) {
                $workflowId = $data['workflow_id'] ?? null;
                $workflow = $workflowId ? Workflow::find($workflowId) : null;
                if (!$workflow) {
                    return AgentResult::reply('Workflow introuvable (peut-etre deja supprime).');
                }
                $wfName = $workflow->name;
                $workflow->delete();
                $this->log($context, "Workflow supprime: {$wfName}");
                return AgentResult::reply("Workflow \"{$wfName}\" supprime.");
            }
            return AgentResult::reply('Suppression annulee.');
        }

        if ($type === 'select_workflow') {
            $this->clearPendingContext($context);
            $selection = trim($context->body ?? '');
            if (is_numeric($selection)) {
                $workflows = Workflow::forUser($context->from)->active()->orderBy('name')->get();
                $index = (int) $selection - 1;
                if (isset($workflows[$index])) {
                    return $this->triggerWorkflow($context, $workflows[$index]);
                }
            }
            // Also try by name
            $workflow = $this->findWorkflow($context->from, $selection);
            if ($workflow) {
                if (!$workflow->is_active) {
                    return AgentResult::reply("Le workflow \"{$workflow->name}\" est desactive. Active-le avec: /workflow enable {$workflow->name}");
                }
                return $this->triggerWorkflow($context, $workflow);
            }
            return AgentResult::reply('Selection invalide. Utilise /workflow list pour voir tes workflows.');
        }

        if ($type === 'confirm_schedule') {
            $lower = mb_strtolower(trim($context->body ?? ''));
            $this->clearPendingContext($context);
            if (in_array($lower, ['oui', 'yes', 'ok', 'confirme', 'o'])) {
                $workflowId = $data['workflow_id'] ?? null;
                $schedule   = $data['schedule'] ?? '';
                $workflow   = $workflowId ? Workflow::find($workflowId) : null;
                if (!$workflow) {
                    return AgentResult::reply('Workflow introuvable.');
                }
                return $this->createScheduleReminder($context, $workflow, $schedule);
            }
            return AgentResult::reply('Planification annulee.');
        }

        if ($type === 'confirm_merge') {
            $lower = mb_strtolower(trim($context->body ?? ''));
            $this->clearPendingContext($context);
            if (in_array($lower, ['oui', 'yes', 'ok', 'confirme', 'o'])) {
                return $this->executeMerge($context, $data);
            }
            if (in_array($lower, ['non', 'no', 'annuler', 'cancel', 'n'])) {
                return AgentResult::reply('Fusion annulee.');
            }
            // Treat as custom name for the merged workflow
            $customName = mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', trim($context->body ?? '')));
            $customName = trim($customName, '-');
            if (!empty($customName) && preg_match('/^[a-zA-Z0-9\-_]+$/', $customName) && mb_strlen($customName) <= 50) {
                $data['new_name'] = $customName;
                return $this->executeMerge($context, $data);
            }
            return AgentResult::reply('Fusion annulee.');
        }

        if ($type === 'confirm_optimize') {
            $lower = mb_strtolower(trim($context->body ?? ''));
            $this->clearPendingContext($context);
            if (in_array($lower, ['oui', 'yes', 'ok', 'confirme', 'o', 'applique'])) {
                $workflowId = $data['workflow_id'] ?? null;
                $workflow = $workflowId ? Workflow::find($workflowId) : null;
                if (!$workflow) {
                    return AgentResult::reply('Workflow introuvable (peut-etre supprime).');
                }
                $newSteps = $data['steps'] ?? [];
                if (empty($newSteps)) {
                    return AgentResult::reply('Aucune etape optimisee a appliquer.');
                }
                try {
                    $oldCount = count($workflow->steps ?? []);
                    $this->backupSteps($workflow);
                    $workflow->update(['steps' => $newSteps]);
                    $newCount = count($newSteps);
                    $this->log($context, "Workflow optimized: {$workflow->name} ({$oldCount} → {$newCount} etapes)");
                    return AgentResult::reply(
                        "Optimisations appliquees au workflow \"{$workflow->name}\".\n"
                        . "Etapes: {$oldCount} → {$newCount}\n\n"
                        . "Voir: /workflow show {$workflow->name}\n"
                        . "Simuler: /workflow dryrun {$workflow->name}"
                    );
                } catch (\Throwable $e) {
                    Log::error("StreamlineAgent: failed to apply optimization", [
                        'error' => $e->getMessage(),
                        'workflow' => $workflow->name,
                    ]);
                    return AgentResult::reply('Erreur lors de l\'application. Reessaie.');
                }
            }
            return AgentResult::reply('Optimisation annulee. Le workflow reste inchange.');
        }

        if ($type === 'save_inline_workflow') {
            $lower = mb_strtolower(trim($context->body ?? ''));
            $this->clearPendingContext($context);
            if (in_array($lower, ['non', 'no', 'annuler', 'cancel', 'n'])) {
                return AgentResult::reply('Chain non sauvegardee.');
            }
            // "oui"/"yes" → auto-generated name
            if (in_array($lower, ['oui', 'yes', 'ok', 'o', 'y'])) {
                $name = 'chain-' . now()->format('mdHi');
            } else {
                // Use the response as workflow name
                $rawName = trim($context->body ?? '');
                $name = mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $rawName));
                $name = trim($name, '-');
                if (empty($name)) {
                    return AgentResult::reply('Nom invalide. Chain non sauvegardee.');
                }
            }
            // Handle duplicate name
            $existing = Workflow::forUser($context->from)->where('name', $name)->first();
            if ($existing) {
                $name = $name . '-' . now()->format('His');
            }
            return $this->createWorkflowFromParsed($context, [
                'name'  => $name,
                'steps' => $data['steps'] ?? [],
            ]);
        }

        return null;
    }

    /**
     * Handle /workflow commands.
     */
    private function handleCommand(AgentContext $context, string $body): AgentResult
    {
        $parts = preg_split('/\s+/', trim($body), 4);
        $action = mb_strtolower($parts[1] ?? 'help');
        $arg1 = $parts[2] ?? '';
        $arg2 = $parts[3] ?? '';

        return match ($action) {
            'create'                        => $this->commandCreate($context, implode(' ', array_slice($parts, 2))),
            'import'                        => $this->commandImport($context, implode(' ', array_slice($parts, 2))),
            'list'                          => $this->commandList($context, $arg1),
            'search', 'find'               => $this->commandSearch($context, implode(' ', array_slice($parts, 2))),
            'trigger', 'run'                => $this->commandTrigger($context, $arg1, $arg2),
            'delete'                        => $this->commandDelete($context, $arg1),
            'show', 'detail'                => $this->commandShow($context, $arg1),
            'enable'                        => $this->commandToggle($context, $arg1, true),
            'disable'                       => $this->commandToggle($context, $arg1, false),
            'rename'                        => $this->commandRename($context, $arg1, $arg2),
            'duplicate', 'copy', 'clone'    => $this->commandDuplicate($context, $arg1, $arg2),
            'stats'                         => $this->commandStats($context),
            'history'                       => $this->commandHistory($context, $arg1),
            'edit'                          => $this->commandEdit($context, $arg1, $arg2),
            'export'                        => $this->commandExport($context, $arg1),
            'add', 'add-step'               => $this->commandAddStep($context, $arg1, $arg2),
            'remove-step', 'remove'         => $this->commandRemoveStep($context, $arg1, $arg2),
            'move-step', 'move'             => $this->commandMoveStep($context, $arg1, $arg2),
            'describe'                      => $this->commandDescribe($context, $arg1, $arg2),
            'dryrun', 'dry-run'             => $this->commandDryrun($context, $arg1),
            'reset-stats', 'resetstats'     => $this->commandResetStats($context, $arg1),
            'template'                      => $this->commandTemplate($context, $arg1, $arg2),
            'pin'                           => $this->commandPin($context, $arg1, true),
            'unpin'                         => $this->commandPin($context, $arg1, false),
            'insert', 'insert-step'         => $this->commandInsertStep($context, $arg1, $arg2),
            'tag', 'tags'                   => $this->commandTag($context, $arg1, $arg2),
            'run-all', 'runall'             => $this->commandRunAll($context, $arg1),
            'batch'                         => $this->commandBatch($context, implode(' ', array_slice($parts, 2))),
            'summary'                       => $this->commandSummary($context, $arg1),
            'step-config', 'stepconfig'     => $this->commandStepConfig($context, $arg1, $arg2),
            'suggest'                       => $this->commandSuggest($context, implode(' ', array_slice($parts, 2))),
            'notes', 'note'                 => $this->commandNotes($context, $arg1, $arg2),
            'health'                        => $this->commandHealth($context),
            'quick'                         => $this->commandQuick($context, implode(' ', array_slice($parts, 2))),
            'last'                          => $this->commandLast($context),
            'copy-step', 'copystep'         => $this->commandCopyStep($context, $arg1, $arg2),
            'diff', 'compare'              => $this->commandDiff($context, $arg1, $arg2),
            'favorites', 'favoris', 'top'  => $this->commandFavorites($context),
            'schedule', 'cron'              => $this->commandSchedule($context, $arg1, $arg2),
            'merge'                         => $this->commandMerge($context, $arg1, $arg2),
            'optimize', 'optimise'          => $this->commandOptimize($context, $arg1),
            'swap', 'swap-step'             => $this->commandSwapStep($context, $arg1, $arg2),
            'undo'                          => $this->commandUndo($context, $arg1),
            'dashboard', 'dash'             => $this->commandDashboard($context),
            'retry'                         => $this->commandRetry($context, $arg1),
            'clean', 'cleanup'              => $this->commandClean($context, $arg1),
            'status'                        => $this->commandStatus($context, $arg1),
            'graph', 'flow', 'flowchart'    => $this->commandGraph($context, $arg1),
            'recent'                        => $this->commandRecent($context, $arg1),
            'test-step', 'teststep', 'test' => $this->commandTestStep($context, $arg1, $arg2),
            'disable-step', 'disablestep', 'skip-step' => $this->commandToggleStep($context, $arg1, $arg2, true),
            'enable-step', 'enablestep', 'unskip-step' => $this->commandToggleStep($context, $arg1, $arg2, false),
            'help'                          => $this->showHelp($context, $arg1),
            default                         => $this->showHelp($context),
        };
    }

    /**
     * Create a workflow from command: /workflow create [name] [step1] then [step2] then [step3]
     */
    private function commandCreate(AgentContext $context, string $arg): AgentResult
    {
        if (empty($arg)) {
            return AgentResult::reply(
                "Pour creer un workflow:\n"
                . "/workflow create [nom] [etape1] then [etape2] then [etape3]\n\n"
                . "Exemples:\n"
                . "  /workflow create morning-brief resume mes todos then check mes rappels\n"
                . "  /workflow create daily-check voir taches then liste rappels du jour"
            );
        }

        $parsed = $this->parseWorkflowDefinition($arg);
        if (!$parsed) {
            return AgentResult::reply(
                "Je n'ai pas compris la definition du workflow.\n"
                . "Separe les etapes avec \"then\", \"puis\" ou \">>\".\n\n"
                . "Exemple: /workflow create daily-brief check todos then voir rappels"
            );
        }

        if (empty($parsed['steps'])) {
            return AgentResult::reply(
                "Le workflow doit avoir au moins une etape.\n"
                . "Exemple: /workflow create morning-brief resume mes todos then voir rappels"
            );
        }

        if (count($parsed['steps']) > 10) {
            return AgentResult::reply(
                "Maximum 10 etapes par workflow (tu en as " . count($parsed['steps']) . ").\n"
                . "Divise ton workflow en plusieurs workflows plus courts."
            );
        }

        // Validate each step has a non-empty message
        foreach ($parsed['steps'] as $i => $step) {
            if (empty(trim($step['message'] ?? ''))) {
                return AgentResult::reply("L'etape " . ($i + 1) . " est vide. Chaque etape doit avoir une instruction.");
            }
        }

        // Validate name format
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $parsed['name'])) {
            return AgentResult::reply(
                "Nom invalide: \"{$parsed['name']}\". Utilise uniquement des lettres, chiffres, tirets et underscores.\n"
                . "Exemples valides: morning-brief, daily_check, weekly-review"
            );
        }

        // Check for duplicate name
        $existing = Workflow::forUser($context->from)
            ->where('name', $parsed['name'])
            ->first();

        if ($existing) {
            return AgentResult::reply(
                "Un workflow nomme \"{$parsed['name']}\" existe deja.\n"
                . "Choisis un autre nom ou supprime l'existant avec: /workflow delete {$parsed['name']}"
            );
        }

        $preview = $this->formatWorkflowPreview($parsed);
        $this->setPendingContext($context, 'confirm_workflow', $parsed, 3);

        return AgentResult::reply(
            "Workflow a creer:\n{$preview}\n\nConfirmer? (oui/non)"
        );
    }

    /**
     * List user's workflows with optional search filter (name or #tag).
     */
    private function commandList(AgentContext $context, string $search = ''): AgentResult
    {
        $query = Workflow::forUser($context->from)->orderByDesc('updated_at');

        // Detect tag filter (#tag)
        $isTagFilter = !empty($search) && str_starts_with(ltrim($search), '#');
        $tagFilter   = $isTagFilter ? ltrim(mb_strtolower(trim($search)), '#') : '';

        if (!empty($search) && !$isTagFilter) {
            $query->where('name', 'like', "%{$search}%");
        }

        $workflows = $query->get();

        // Apply tag filter in-memory (tags are stored in conditions JSON)
        if ($isTagFilter && !empty($tagFilter)) {
            $workflows = $workflows->filter(function ($wf) use ($tagFilter) {
                $tags = $wf->conditions['tags'] ?? [];
                return in_array($tagFilter, $tags, true);
            });
        }

        if ($workflows->isEmpty()) {
            $msg = $isTagFilter
                ? "Aucun workflow avec le tag #{$tagFilter}.\n\nVoir tous les tags: /workflow tags"
                : (!empty($search)
                    ? "Aucun workflow contenant \"{$search}\".\n\nUtilise /workflow list pour voir tous tes workflows."
                    : "Aucun workflow enregistre.\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]");
            return AgentResult::reply($msg);
        }

        $activeCount = $workflows->where('is_active', true)->count();
        $header = $isTagFilter
            ? "Workflows #\"{$tagFilter}\" ({$workflows->count()}):"
            : (!empty($search)
                ? "Workflows contenant \"{$search}\" ({$workflows->count()}):"
                : "Tes workflows ({$workflows->count()} · {$activeCount} actif" . ($activeCount > 1 ? 's' : '') . "):");

        // Sort: pinned first, then by updated_at desc
        $workflows = $workflows->sortByDesc(fn($wf) => $this->isPinned($wf) ? 1 : 0);

        $lines = [$header, str_repeat('─', 28)];
        foreach ($workflows->values() as $i => $wf) {
            $active    = $wf->is_active ? 'ON' : 'OFF';
            $pinBadge  = $this->isPinned($wf) ? '[PIN] ' : '';
            $stepCount = count($wf->steps ?? []);
            $lastRun   = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : 'jamais';
            $desc      = !empty($wf->description) ? "\n   " . mb_substr($wf->description, 0, 60) : '';
            $lines[] = ($i + 1) . ". [{$active}] {$pinBadge}{$wf->name}{$desc}";
            $lines[] = "   {$stepCount} etapes · {$wf->run_count} exec. · {$lastRun}";
        }

        $lines[] = str_repeat('─', 24);
        $lines[] = "/workflow trigger [nom]  — lancer";
        $lines[] = "/workflow show [nom]     — details";
        $lines[] = "/workflow delete [nom]   — supprimer";
        if ($workflows->count() > 5) {
            $lines[] = "/workflow list [filtre]  — filtrer";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Trigger a workflow by name, optionally with input context passed to each step.
     * Usage: /workflow trigger [name]
     *        /workflow trigger [name] [input context]
     */
    private function commandTrigger(AgentContext $context, string $name, string $input = ''): AgentResult
    {
        if (empty($name)) {
            $workflows = Workflow::forUser($context->from)->active()->orderBy('name')->get();
            if ($workflows->isEmpty()) {
                return AgentResult::reply('Aucun workflow actif. Cree-en un avec /workflow create.');
            }

            $lines = ["Quel workflow lancer?\n"];
            foreach ($workflows->values() as $i => $wf) {
                $stepCount = count($wf->steps ?? []);
                $lines[] = ($i + 1) . ". {$wf->name} ({$stepCount} etapes)";
            }
            $lines[] = "\nReponds avec le numero ou le nom.";

            $this->setPendingContext($context, 'select_workflow', [], 3);
            return AgentResult::reply(implode("\n", $lines));
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        if (!$workflow->is_active) {
            return AgentResult::reply(
                "Le workflow \"{$workflow->name}\" est desactive.\n"
                . "Active-le avec: /workflow enable {$workflow->name}"
            );
        }

        if (empty($workflow->steps)) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape a executer.");
        }

        $input = trim($input);
        return $this->triggerWorkflow($context, $workflow, $input ?: null);
    }

    /**
     * Delete a workflow by name (with confirmation).
     */
    private function commandDelete(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply('Precise le nom du workflow a supprimer: /workflow delete [nom]');
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $stepCount = count($workflow->steps ?? []);
        $this->setPendingContext($context, 'confirm_delete', ['workflow_id' => $workflow->id], 3);

        return AgentResult::reply(
            "Supprimer le workflow \"{$workflow->name}\" ({$stepCount} etapes, {$workflow->run_count} exec.)?\n\n"
            . "Cette action est irreversible. Confirmer? (oui/non)"
        );
    }

    /**
     * Show workflow details.
     */
    private function commandShow(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply('Precise le nom: /workflow show [nom]');
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $stepCount = count($workflow->steps ?? []);
        $status    = $workflow->is_active ? 'Actif' : 'Inactif';
        $lastRun   = $workflow->last_run_at ? $workflow->last_run_at->format('d/m/Y H:i') : 'jamais';
        $createdAt = $workflow->created_at->format('d/m/Y H:i');

        $lines = [
            "Workflow: {$workflow->name}",
            str_repeat('─', 28),
        ];

        if (!empty($workflow->description)) {
            $lines[] = $workflow->description;
            $lines[] = '';
        }

        $lines[] = "Statut  : {$status}";
        $lines[] = "Etapes  : {$stepCount}";
        $lines[] = "Exec.   : {$workflow->run_count}";
        $lines[] = "Dernier : {$lastRun}";
        $lines[] = "Cree    : {$createdAt}";
        $lines[] = '';
        $lines[] = "Etapes ({$stepCount}):";

        foreach (($workflow->steps ?? []) as $i => $step) {
            $agent     = $step['agent'] ?? 'auto';
            $msg       = mb_substr($step['message'] ?? '', 0, 80);
            $condition = (!empty($step['condition']) && $step['condition'] !== 'always')
                ? " [si:{$step['condition']}]"
                : '';
            $onError   = (!empty($step['on_error']) && $step['on_error'] !== 'stop')
                ? " [err:continuer]"
                : '';
            $skipBadge = !empty($step['_skip']) ? " [SKIP]" : '';
            $lines[] = "  " . ($i + 1) . ". [{$agent}] {$msg}{$condition}{$onError}{$skipBadge}";
        }

        // Show note if present
        $note = $workflow->conditions['note'] ?? null;
        if (!empty($note)) {
            $lines[] = '';
            $lines[] = "Note: " . mb_substr($note, 0, 100) . (mb_strlen($note) > 100 ? '...' : '');
        }

        // Show tags if present
        $tags = $workflow->conditions['tags'] ?? [];
        if (!empty($tags)) {
            $lines[] = 'Tags: ' . implode(' ', array_map(fn($t) => "#{$t}", $tags));
        }

        $lines[] = '';
        $lines[] = "/workflow trigger {$workflow->name}";
        $lines[] = "/workflow edit {$workflow->name} [N] [msg]";
        $lines[] = "/workflow remove-step {$workflow->name} [N]";
        $lines[] = "/workflow duplicate {$workflow->name} [nouveau]";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Enable or disable a workflow.
     */
    private function commandToggle(AgentContext $context, string $name, bool $enable): AgentResult
    {
        if (empty($name)) {
            $action = $enable ? 'activer' : 'desactiver';
            return AgentResult::reply("Precise le nom du workflow a {$action}: /workflow " . ($enable ? 'enable' : 'disable') . " [nom]");
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.");
        }

        if ($workflow->is_active === $enable) {
            $state = $enable ? 'deja actif' : 'deja inactif';
            return AgentResult::reply("Le workflow \"{$workflow->name}\" est {$state}.");
        }

        try {
            $workflow->update(['is_active' => $enable]);
            $state = $enable ? 'active' : 'desactive';
            $this->log($context, "Workflow {$state}: {$workflow->name}");

            return AgentResult::reply("Workflow \"{$workflow->name}\" {$state}.");
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to toggle workflow", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'enable'   => $enable,
            ]);
            $action = $enable ? "l'activation" : 'la desactivation';
            return AgentResult::reply("Erreur lors de {$action} du workflow. Reessaie.");
        }
    }

    /**
     * Rename a workflow.
     */
    private function commandRename(AgentContext $context, string $oldName, string $newName): AgentResult
    {
        if (empty($oldName) || empty($newName)) {
            return AgentResult::reply("Usage: /workflow rename [ancien-nom] [nouveau-nom]");
        }

        // Normalize new name
        $newName = mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $newName));
        $newName = trim($newName, '-');

        if (empty($newName)) {
            return AgentResult::reply("Nouveau nom invalide. Utilise uniquement des lettres, chiffres, tirets et underscores.");
        }

        $workflow = $this->findWorkflow($context->from, $oldName);

        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$oldName}\" introuvable.");
        }

        // Check new name not already taken
        $conflict = Workflow::forUser($context->from)
            ->where('name', $newName)
            ->where('id', '!=', $workflow->id)
            ->first();

        if ($conflict) {
            return AgentResult::reply("Un workflow nomme \"{$newName}\" existe deja. Choisis un autre nom.");
        }

        $previousName = $workflow->name;

        try {
            $workflow->update(['name' => $newName]);
            $this->log($context, "Workflow renomme: {$previousName} -> {$newName}");

            return AgentResult::reply("Workflow renomme: \"{$previousName}\" → \"{$newName}\".");
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to rename workflow", [
                'error'   => $e->getMessage(),
                'old'     => $previousName,
                'new'     => $newName,
            ]);
            return AgentResult::reply('Erreur lors du renommage du workflow. Reessaie.');
        }
    }

    /**
     * Duplicate a workflow under a new name.
     */
    private function commandDuplicate(AgentContext $context, string $sourceName, string $newName): AgentResult
    {
        if (empty($sourceName)) {
            return AgentResult::reply(
                "Usage: /workflow duplicate [nom-source] [nouveau-nom]\n\n"
                . "Exemple: /workflow duplicate morning-brief morning-brief-v2"
            );
        }

        $source = $this->findWorkflow($context->from, $sourceName);

        if (!$source) {
            return AgentResult::reply(
                "Workflow \"{$sourceName}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        // Auto-generate new name if not provided
        if (empty($newName)) {
            $newName = $source->name . '-copy';
        }

        // Normalize new name
        $newName = mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $newName));
        $newName = trim($newName, '-');

        if (empty($newName)) {
            return AgentResult::reply("Nouveau nom invalide. Utilise uniquement des lettres, chiffres, tirets et underscores.");
        }

        // Check for name conflict
        $conflict = Workflow::forUser($context->from)->where('name', $newName)->first();
        if ($conflict) {
            return AgentResult::reply("Un workflow nomme \"{$newName}\" existe deja. Choisis un autre nom.");
        }

        try {
            $copy = Workflow::create([
                'user_phone'  => $context->from,
                'agent_id'    => $source->agent_id,
                'name'        => $newName,
                'description' => $source->description
                    ? "[Copie de {$source->name}] {$source->description}"
                    : null,
                'steps'       => $source->steps,
                'triggers'    => $source->triggers,
                'conditions'  => $source->conditions,
                'is_active'   => false, // starts inactive for safety
            ]);

            $stepCount = count($copy->steps ?? []);
            $this->log($context, "Workflow duplique: {$source->name} -> {$newName}", ['id' => $copy->id]);

            return AgentResult::reply(
                "Workflow \"{$source->name}\" duplique vers \"{$newName}\" ({$stepCount} etapes).\n"
                . "Le workflow est desactive par defaut.\n\n"
                . "Active-le: /workflow enable {$newName}\n"
                . "Details: /workflow show {$newName}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to duplicate workflow", [
                'error'  => $e->getMessage(),
                'source' => $sourceName,
            ]);
            return AgentResult::reply('Erreur lors de la duplication du workflow. Reessaie.');
        }
    }

    /**
     * Show workflow usage statistics summary.
     */
    private function commandStats(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow cree.\n\n"
                . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        $total      = $workflows->count();
        $active     = $workflows->where('is_active', true)->count();
        $totalRuns  = $workflows->sum('run_count');
        $totalSteps = $workflows->sum(fn($wf) => count($wf->steps ?? []));
        $neverRun   = $workflows->where('run_count', 0)->count();
        $avgSteps   = $total > 0 ? round($totalSteps / $total, 1) : 0;
        $pinnedCount = $workflows->filter(fn($wf) => $this->isPinned($wf))->count();
        $taggedCount = $workflows->filter(fn($wf) => !empty($wf->conditions['tags'] ?? []))->count();
        $staleCount  = $workflows->filter(fn($wf) =>
            $wf->is_active && $wf->last_run_at && $wf->last_run_at->diffInDays(now()) > 30
        )->count();

        $topWorkflows = $workflows->sortByDesc('run_count')->take(3)->filter(fn($wf) => $wf->run_count > 0);
        $recentlyRun  = $workflows->whereNotNull('last_run_at')->sortByDesc('last_run_at')->first();
        $mostSteps    = $workflows->sortByDesc(fn($wf) => count($wf->steps ?? []))->first();

        $lines = [
            "Statistiques workflows",
            str_repeat('─', 24),
            "Total       : {$total} workflow" . ($total > 1 ? 's' : ''),
            "Actifs      : {$active}/{$total}",
            "Inactifs    : " . ($total - $active) . "/{$total}",
            "Jamais uses : {$neverRun}",
            "Epingles    : {$pinnedCount}",
            "Tagues      : {$taggedCount}",
            "─",
            "Exec. tot.  : {$totalRuns}",
            "Etapes tot. : {$totalSteps}",
            "Moy. etapes : {$avgSteps}/workflow",
        ];

        if ($staleCount > 0) {
            $lines[] = "Inactifs >30j: {$staleCount} (voir /workflow health)";
        }

        if ($mostSteps && count($mostSteps->steps ?? []) > 1) {
            $sc = count($mostSteps->steps ?? []);
            $lines[] = "Plus long   : {$mostSteps->name} ({$sc} etapes)";
        }

        if ($recentlyRun) {
            $lines[] = "Dernier exe : {$recentlyRun->name} (" . $recentlyRun->last_run_at->diffForHumans() . ")";
        }

        if ($topWorkflows->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "Top workflows:";
            foreach ($topWorkflows->values() as $i => $wf) {
                $lines[] = "  " . ($i + 1) . ". {$wf->name} — {$wf->run_count} exec.";
            }
        }

        $neverRunList = $workflows->where('run_count', 0);
        if ($neverRunList->isNotEmpty() && $neverRunList->count() <= 5) {
            $lines[] = '';
            $lines[] = "Jamais lances:";
            foreach ($neverRunList->values() as $wf) {
                $lines[] = "  · {$wf->name}";
            }
        }

        // Tag breakdown
        $allTags = [];
        foreach ($workflows as $wf) {
            foreach ($wf->conditions['tags'] ?? [] as $tag) {
                $allTags[$tag] = ($allTags[$tag] ?? 0) + 1;
            }
        }
        if (!empty($allTags)) {
            arsort($allTags);
            $lines[] = '';
            $lines[] = "Tags:";
            foreach (array_slice($allTags, 0, 5, true) as $tag => $cnt) {
                $lines[] = "  #{$tag} — {$cnt} workflow" . ($cnt > 1 ? 's' : '');
            }
            if (count($allTags) > 5) {
                $lines[] = "  + " . (count($allTags) - 5) . " tag(s) supplementaire(s). /workflow tags";
            }
        }

        $lines[] = '';
        $lines[] = "/workflow list     — voir tous";
        $lines[] = "/workflow history  — dernieres executions";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Handle inline chain patterns: "do X then do Y then do Z"
     */
    private function handleInlineChain(AgentContext $context, string $body): AgentResult
    {
        $steps = $this->splitChainSteps($body);

        if (count($steps) < 2) {
            return AgentResult::reply(
                'Je n\'ai detecte qu\'une seule etape. '
                . 'Utilise "then", "puis", "ensuite" ou ">>" pour chainer des actions.'
            );
        }

        if (count($steps) > 8) {
            return AgentResult::reply(
                "Maximum 8 etapes pour une chain inline (tu en as " . count($steps) . ").\n"
                . "Cree un workflow avec /workflow create pour les chains plus longues."
            );
        }

        $this->log($context, 'Inline chain detected', ['steps' => count($steps)]);
        $stepCount = count($steps);
        $this->sendText($context->from, "Execution de {$stepCount} etape" . ($stepCount > 1 ? 's' : '') . " en sequence...");

        try {
            // Execute steps inline without saving as workflow
            $orchestrator = new AgentOrchestrator();
            $executor     = new WorkflowExecutor($orchestrator);

            $workflow = new Workflow([
                'name'  => 'inline-chain',
                'steps' => array_map(fn($s) => ['message' => $s, 'agent' => null, 'condition' => 'always', 'on_error' => 'stop'], $steps),
            ]);
            $workflow->run_count = 0;

            $executionResult = $executor->execute($workflow, $context);

            $result = AgentResult::reply(
                WorkflowExecutor::formatResults($executionResult),
                ['workflow_execution' => $executionResult]
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: inline chain execution failed", ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de l'execution de la chain. Reessaie ou utilise /workflow create pour un workflow permanent.");
        }

        // Offer to save this chain as a reusable workflow
        $stepsData = array_map(fn($s) => ['message' => $s, 'agent' => null, 'condition' => 'always', 'on_error' => 'stop'], $steps);
        $this->setPendingContext($context, 'save_inline_workflow', ['steps' => $stepsData], 2);
        $this->sendText($context->from, "Sauvegarder cette chain comme workflow reutilisable?\nReponds avec un nom (ex: my-chain) ou \"non\".");

        return $result;
    }

    /**
     * Handle natural language workflow requests via Claude.
     */
    private function handleNaturalLanguage(AgentContext $context, string $body): AgentResult
    {
        $model         = $this->resolveModel($context);
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);

        $userWorkflows   = Workflow::forUser($context->from)->pluck('name')->implode(', ');
        $workflowContext = $userWorkflows
            ? "Workflows existants de l'utilisateur: {$userWorkflows}"
            : "L'utilisateur n'a pas encore de workflow.";

        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"\n\n{$workflowContext}\n\n{$contextMemory}",
            $model,
            "Tu es un assistant specialise dans la creation et gestion de workflows WhatsApp.\n"
            . "Reponds UNIQUEMENT en JSON valide. Aucun markdown, aucun backtick, aucun commentaire.\n\n"
            . "REGLE ANTI-HALLUCINATION:\n"
            . "- N'invente JAMAIS de workflows, noms ou donnees qui n'existent pas\n"
            . "- Si l'intention est ambigue, utilise action=\"help\" plutot que de deviner\n"
            . "- Le champ \"name\" doit correspondre a un workflow existant ou a un nouveau nom explicitement demande\n\n"
            . "Format de reponse:\n"
            . "{\n"
            . "  \"action\": \"create|list|trigger|delete|show|enable|disable|rename|duplicate|stats|history|dryrun|run-all|summary|step-config|suggest|health|quick|search|export|pin|unpin|reset-stats|notes|batch|tags|template|last|schedule|merge|optimize|swap|undo|dashboard|retry|clean|status|graph|recent|test-step|disable-step|enable-step|help\",\n"
            . "  \"name\": \"nom-en-kebab-case-sans-espaces (requis sauf pour list/help/status/stats/dashboard/health)\",\n"
            . "  \"input\": \"contexte optionnel pour trigger (ex: 'focus finances', 'urgent seulement') — passe a chaque etape\",\n"
            . "  \"steps\": [\n"
            . "    {\n"
            . "      \"message\": \"instruction claire, complete et directement actionnable\",\n"
            . "      \"agent\": \"nom_agent ou null (auto-detection)\",\n"
            . "      \"condition\": \"always|success|contains:mot|not_contains:mot\",\n"
            . "      \"on_error\": \"stop|continue\"\n"
            . "    }\n"
            . "  ],\n"
            . "  \"reply\": \"confirmation courte en francais (max 1 phrase)\"\n"
            . "}\n\n"
            . "Agents disponibles: chat, dev, todo, reminder, event_reminder, finance, music, habit, pomodoro, content_summarizer, code_review, web_search, document, analysis, streamline\n\n"
            . "Actions supportees:\n"
            . "- create : creer un nouveau workflow (necessite name + steps)\n"
            . "- list : lister les workflows existants\n"
            . "- trigger : lancer/executer un workflow (necessite name)\n"
            . "- delete : supprimer un workflow (necessite name)\n"
            . "- show : afficher les details d'un workflow (necessite name)\n"
            . "- enable : activer un workflow desactive (necessite name)\n"
            . "- disable : desactiver un workflow actif (necessite name)\n"
            . "- rename : renommer un workflow (necessite name + new_name dans reply)\n"
            . "- duplicate : dupliquer un workflow sous un nouveau nom (necessite name)\n"
            . "- stats : afficher les statistiques globales (pas de name requis)\n"
            . "- history : afficher l'historique d'executions (pas de name requis)\n"
            . "- dryrun : simuler un workflow sans l'executer (necessite name)\n"
            . "- run-all : lancer tous les workflows actifs, optionellement avec tag (name=tag ou vide)\n"
            . "- summary : generer un resume IA d'un workflow (necessite name)\n"
            . "- step-config : afficher ou modifier la config d'une etape (agent/condition/on_error) — necessite name dans le format 'workflow_name step_num param=valeur'\n"
            . "- suggest : suggerer un workflow IA base sur une description de besoin (name = description du besoin)\n"
            . "- health : verifier la sante de tous les workflows (workflows casses, inutilises, inactifs)\n"
            . "- quick : chercher et lancer immediatement un workflow par nom partiel (necessite name=terme de recherche)\n"
            . "- last : relancer le dernier workflow execute (pas de name requis)\n"
            . "- search : chercher un terme dans les etapes des workflows (necessite name=terme)\n"
            . "- export : exporter un workflow en texte copiable (necessite name)\n"
            . "- pin : epingler un workflow en tete de liste (necessite name)\n"
            . "- unpin : desepingler un workflow (necessite name)\n"
            . "- reset-stats : remettre les stats d'execution a zero (necessite name)\n"
            . "- notes : voir/modifier une note sur un workflow (necessite name)\n"
            . "- batch : lancer plusieurs workflows specifiques (name = 'nom1 nom2 nom3')\n"
            . "- tags : lister tous les tags utilises sur les workflows\n"
            . "- template : lister ou utiliser les templates pre-definis (name=template_name optionnel)\n"
            . "- diff : comparer deux workflows cote a cote (necessite name='nom1 nom2')\n"
            . "- favorites : afficher les workflows les plus utilises (top 5)\n"
            . "- schedule : planifier l'execution automatique d'un workflow (necessite name, reply contient la frequence ex: 'chaque jour a 8h', 'tous les lundis a 9h')\n"
            . "- merge : fusionner deux workflows en un (necessite name='nom1 nom2', optionnellement reply=nouveau_nom)\n"
            . "- optimize : analyser et suggerer des ameliorations IA pour un workflow (necessite name)\n"
            . "- swap : echanger la position de deux etapes (necessite name, reply='N1 N2' les numeros des etapes)\n"
            . "- undo : annuler la derniere modification des etapes d'un workflow (necessite name)\n"
            . "- dashboard : afficher un tableau de bord compact avec sante, favoris, recents (pas de name requis)\n"
            . "- retry : relancer un workflow (necessite name optionnel, sans name = relance le dernier execute)\n"
            . "- clean : nettoyer les workflows casses, inutilises ou obsoletes (pas de name requis, name='confirm' pour executer)\n"
            . "- status : apercu rapide de tous les workflows ou d'un workflow specifique (name optionnel)\n"
            . "- graph : afficher un graphe visuel du flux d'execution (necessite name)\n"
            . "- recent : afficher les workflows recemment executes (pas de name requis, name=nombre optionnel)\n"
            . "- test-step : executer une seule etape d'un workflow pour la tester (necessite name, reply=numero de l'etape)\n"
            . "- disable-step : desactiver temporairement une etape sans la supprimer (necessite name, reply=numero de l'etape)\n"
            . "- enable-step : reactiver une etape desactivee (necessite name, reply=numero de l'etape)\n"
            . "- help : si l'intention est ambigue ou non reconnue\n\n"
            . "Regles:\n"
            . "- Nom de workflow: kebab-case obligatoire (ex: morning-brief, daily-check, weekly-review)\n"
            . "- Chaque step.message doit etre une instruction complete et autonome pour un agent IA\n"
            . "- Valeurs par defaut: condition=\"always\", on_error=\"stop\"\n"
            . "- on_error=\"continue\" uniquement pour les etapes optionnelles\n"
            . "- condition=\"success\" pour executer une etape seulement si la precedente a reussi\n"
            . "- Si l'intention est ambigue, action=\"help\" avec reply explicatif\n"
            . "- Ne jamais inventer ou modifier des workflows non demandes explicitement\n\n"
            . "Exemples d'intentions et actions:\n"
            . "  \"montre-moi le workflow morning\" → {\"action\":\"show\",\"name\":\"morning\"}\n"
            . "  \"active le workflow daily-check\" → {\"action\":\"enable\",\"name\":\"daily-check\"}\n"
            . "  \"desactive mon workflow weekly\" → {\"action\":\"disable\",\"name\":\"weekly\"}\n"
            . "  \"lance morning-brief\" → {\"action\":\"trigger\",\"name\":\"morning-brief\"}\n"
            . "  \"cree un workflow qui check mes todos puis mes rappels\" → {\"action\":\"create\",\"name\":\"todo-rappels\",\"steps\":[...]}\n"
            . "  \"stats de mes workflows\" → {\"action\":\"stats\"}\n"
            . "  \"historique de mes workflows\" → {\"action\":\"history\"}\n"
            . "  \"simule le workflow morning\" → {\"action\":\"dryrun\",\"name\":\"morning\"}\n"
            . "  \"lance tous mes workflows\" → {\"action\":\"run-all\",\"name\":\"\"}\n"
            . "  \"lance tous les workflows du matin\" → {\"action\":\"run-all\",\"name\":\"matin\"}\n"
            . "  \"explique le workflow daily-check\" → {\"action\":\"summary\",\"name\":\"daily-check\"}\n"
            . "  \"verifie la sante de mes workflows\" → {\"action\":\"health\"}\n"
            . "  \"lance rapidement mon workflow morning\" → {\"action\":\"quick\",\"name\":\"morning\"}\n"
            . "  \"cherche todos dans mes workflows\" → {\"action\":\"search\",\"name\":\"todos\"}\n"
            . "  \"epingle le workflow daily-brief\" → {\"action\":\"pin\",\"name\":\"daily-brief\"}\n"
            . "  \"exporte le workflow morning-brief\" → {\"action\":\"export\",\"name\":\"morning-brief\"}\n"
            . "  \"compare morning-brief et evening-check\" → {\"action\":\"diff\",\"name\":\"morning-brief evening-check\"}\n"
            . "  \"mes workflows preferes\" → {\"action\":\"favorites\"}\n"
            . "  \"planifie morning-brief tous les jours a 8h\" → {\"action\":\"schedule\",\"name\":\"morning-brief\",\"reply\":\"chaque jour a 8h\"}\n"
            . "  \"fusionne morning-brief et evening-check\" → {\"action\":\"merge\",\"name\":\"morning-brief evening-check\",\"reply\":\"combined-routine\"}\n"
            . "  \"optimise le workflow morning-brief\" → {\"action\":\"optimize\",\"name\":\"morning-brief\"}\n"
            . "  \"echange les etapes 2 et 3 de daily-check\" → {\"action\":\"swap\",\"name\":\"daily-check\",\"reply\":\"2 3\"}\n"
            . "  \"annule la derniere modif de morning-brief\" → {\"action\":\"undo\",\"name\":\"morning-brief\"}\n"
            . "  \"tableau de bord de mes workflows\" → {\"action\":\"dashboard\"}\n"
            . "  \"apercu de mes workflows\" → {\"action\":\"dashboard\"}\n"
            . "  \"relance le workflow morning-brief\" → {\"action\":\"retry\",\"name\":\"morning-brief\"}\n"
            . "  \"reessaie le dernier workflow\" → {\"action\":\"retry\"}\n"
            . "  \"nettoie mes workflows\" → {\"action\":\"clean\"}\n"
            . "  \"fais le menage dans mes workflows\" → {\"action\":\"clean\"}\n"
            . "  \"status de mes workflows\" → {\"action\":\"status\"}\n"
            . "  \"statut du workflow morning\" → {\"action\":\"status\",\"name\":\"morning\"}\n"
            . "  \"lance morning-brief en mode finances\" → {\"action\":\"trigger\",\"name\":\"morning-brief\",\"input\":\"focus finances\"}\n"
            . "  \"trigger daily-check pour le projet alpha\" → {\"action\":\"trigger\",\"name\":\"daily-check\",\"input\":\"projet alpha\"}\n"
            . "  \"teste l'etape 2 de morning-brief\" → {\"action\":\"test-step\",\"name\":\"morning-brief\",\"reply\":\"2\"}\n"
            . "  \"desactive l'etape 3 de daily-check\" → {\"action\":\"disable-step\",\"name\":\"daily-check\",\"reply\":\"3\"}\n"
            . "  \"reactive l'etape 3 de daily-check\" → {\"action\":\"enable-step\",\"name\":\"daily-check\",\"reply\":\"3\"}\n"
            . "  \"montre le graphe de morning-brief\" → {\"action\":\"graph\",\"name\":\"morning-brief\"}\n"
            . "  \"visualise le flux de daily-check\" → {\"action\":\"graph\",\"name\":\"daily-check\"}\n"
            . "  \"mes workflows recents\" → {\"action\":\"recent\"}\n"
            . "  \"derniers workflows lances\" → {\"action\":\"recent\"}\n"
            . "  \"saute l'etape 1 du workflow morning\" → {\"action\":\"disable-step\",\"name\":\"morning\",\"reply\":\"1\"}"
        );

        $parsed = json_decode($response, true);
        if (!$parsed || !isset($parsed['action'])) {
            // Try to extract JSON from response if wrapped in markdown, backticks, or extra text
            $cleaned = $response ?? '';
            $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $cleaned);
            $cleaned = preg_replace('/\s*```\s*$/m', '', $cleaned);
            $parsed = json_decode(trim($cleaned), true);

            if (!$parsed || !isset($parsed['action'])) {
                // Last resort: find the outermost JSON object
                if (preg_match('/\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}/s', $cleaned, $matches)) {
                    $parsed = json_decode($matches[0], true);
                }
            }
        }
        if (!$parsed || !isset($parsed['action'])) {
            Log::warning("StreamlineAgent: NLU JSON parse failed", ['response_preview' => mb_substr($response ?? '', 0, 200)]);
            return AgentResult::reply(
                "Je n'ai pas compris ta demande de workflow.\n\n"
                . "Essaie:\n"
                . "  /workflow create [nom] [etape1] then [etape2]\n"
                . "  /workflow list\n"
                . "  /workflow trigger [nom]\n"
                . "  /workflow help"
            );
        }

        return match ($parsed['action']) {
            'create'      => $this->handleParsedCreate($context, $parsed),
            'list'        => $this->commandList($context, $parsed['name'] ?? ''),
            'trigger'     => $this->commandTrigger($context, $parsed['name'] ?? '', $parsed['input'] ?? ''),
            'delete'      => $this->commandDelete($context, $parsed['name'] ?? ''),
            'show'        => $this->commandShow($context, $parsed['name'] ?? ''),
            'enable'      => $this->commandToggle($context, $parsed['name'] ?? '', true),
            'disable'     => $this->commandToggle($context, $parsed['name'] ?? '', false),
            'rename'      => $this->commandRename($context, $parsed['name'] ?? '', $parsed['new_name'] ?? ''),
            'duplicate'   => $this->commandDuplicate($context, $parsed['name'] ?? '', ''),
            'stats'       => $this->commandStats($context),
            'history'     => $this->commandHistory($context, $parsed['name'] ?? ''),
            'dryrun'      => $this->commandDryrun($context, $parsed['name'] ?? ''),
            'run-all'     => $this->commandRunAll($context, $parsed['name'] ?? ''),
            'summary'     => $this->commandSummary($context, $parsed['name'] ?? ''),
            'step-config' => $this->commandStepConfig($context, $parsed['name'] ?? '', ''),
            'suggest'     => $this->commandSuggest($context, $parsed['name'] ?? ''),
            'health'      => $this->commandHealth($context),
            'quick'       => $this->commandQuick($context, $parsed['name'] ?? ''),
            'last'        => $this->commandLast($context),
            'search'      => $this->commandSearch($context, $parsed['name'] ?? ''),
            'export'      => $this->commandExport($context, $parsed['name'] ?? ''),
            'import'      => $this->commandImport($context, $parsed['name'] ?? ''),
            'template'    => $this->commandTemplate($context, $parsed['name'] ?? '', ''),
            'pin'         => $this->commandPin($context, $parsed['name'] ?? '', true),
            'unpin'       => $this->commandPin($context, $parsed['name'] ?? '', false),
            'reset-stats' => $this->commandResetStats($context, $parsed['name'] ?? ''),
            'notes'       => $this->commandNotes($context, $parsed['name'] ?? '', ''),
            'batch'       => $this->commandBatch($context, $parsed['name'] ?? ''),
            'tags'        => $this->commandTag($context, '', ''),
            'diff'        => $this->handleDiffFromNLU($context, $parsed['name'] ?? ''),
            'favorites'   => $this->commandFavorites($context),
            'schedule'    => $this->commandSchedule($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'merge'       => $this->handleMergeFromNLU($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'optimize'    => $this->commandOptimize($context, $parsed['name'] ?? ''),
            'swap'        => $this->commandSwapStep($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'undo'        => $this->commandUndo($context, $parsed['name'] ?? ''),
            'dashboard'   => $this->commandDashboard($context),
            'retry'       => $this->commandRetry($context, $parsed['name'] ?? ''),
            'clean'       => $this->commandClean($context, $parsed['name'] ?? ''),
            'status'       => $this->commandStatus($context, $parsed['name'] ?? ''),
            'graph'        => $this->commandGraph($context, $parsed['name'] ?? ''),
            'recent'       => $this->commandRecent($context, $parsed['name'] ?? ''),
            'test-step'    => $this->commandTestStep($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'disable-step' => $this->commandToggleStep($context, $parsed['name'] ?? '', $parsed['reply'] ?? '', true),
            'enable-step'  => $this->commandToggleStep($context, $parsed['name'] ?? '', $parsed['reply'] ?? '', false),
            default        => AgentResult::reply($parsed['reply'] ?? $this->getHelpText()),
        };
    }

    private function handleParsedCreate(AgentContext $context, array $parsed): AgentResult
    {
        $data = [
            'name'  => $parsed['name'] ?? 'workflow-' . now()->format('His'),
            'steps' => $parsed['steps'] ?? [],
        ];

        if (empty($data['steps'])) {
            return AgentResult::reply($parsed['reply'] ?? 'Aucune etape detectee pour le workflow.');
        }

        // Normalize step fields with defaults
        $data['steps'] = array_map(function (array $step) {
            return [
                'message'   => $step['message'] ?? '',
                'agent'     => $step['agent'] ?? null,
                'condition' => $step['condition'] ?? 'always',
                'on_error'  => $step['on_error'] ?? 'stop',
            ];
        }, $data['steps']);

        // Handle duplicate name
        $existing = Workflow::forUser($context->from)
            ->where('name', $data['name'])
            ->first();

        if ($existing) {
            $data['name'] = $data['name'] . '-' . now()->format('His');
        }

        $preview = $this->formatWorkflowPreview($data);
        $this->setPendingContext($context, 'confirm_workflow', $data, 3);

        return AgentResult::reply(
            (!empty($parsed['reply']) ? $parsed['reply'] . "\n\n" : '')
            . "Workflow a creer:\n{$preview}\n\nConfirmer? (oui/non)"
        );
    }

    /**
     * Edit a specific step of an existing workflow.
     * Usage: /workflow edit [nom] [step_number] [new_message]
     */
    private function commandEdit(AgentContext $context, string $name, string $stepArg): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow edit [nom] [numero_etape] [nouveau_message]\n\n"
                . "Exemple: /workflow edit morning-brief 2 liste mes rappels du jour"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $stepParts = preg_split('/\s+/', trim($stepArg), 2);
        $stepIndex = intval($stepParts[0] ?? 0) - 1; // convert to 0-based
        $newMessage = trim($stepParts[1] ?? '');

        $steps = $workflow->steps ?? [];

        if ($stepIndex < 0 || $stepIndex >= count($steps)) {
            $count = count($steps);
            return AgentResult::reply(
                "Numero d'etape invalide. Ce workflow a {$count} etape" . ($count > 1 ? 's' : '') . " (1 a {$count}).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes."
            );
        }

        if (empty($newMessage)) {
            return AgentResult::reply(
                "Precise le nouveau message pour l'etape " . ($stepIndex + 1) . ":\n"
                . "/workflow edit {$workflow->name} " . ($stepIndex + 1) . " [nouveau_message]"
            );
        }

        $oldMessage = $steps[$stepIndex]['message'] ?? '';
        $this->backupSteps($workflow);
        $steps[$stepIndex]['message'] = $newMessage;

        try {
            $workflow->update(['steps' => $steps]);
            $this->log($context, "Workflow step edited: {$workflow->name} step " . ($stepIndex + 1));

            return AgentResult::reply(
                "Etape " . ($stepIndex + 1) . " du workflow \"{$workflow->name}\" modifiee.\n\n"
                . "Avant: " . mb_substr($oldMessage, 0, 80) . "\n"
                . "Apres: " . mb_substr($newMessage, 0, 80) . "\n\n"
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to edit workflow step", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'step'     => $stepIndex + 1,
            ]);
            return AgentResult::reply('Erreur lors de la modification. Reessaie.');
        }
    }

    /**
     * Export a workflow as a copyable text definition.
     * Usage: /workflow export [nom]
     */
    private function commandExport(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply("Usage: /workflow export [nom]\n\nExporte un workflow sous forme de texte copiable.");
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $steps = $workflow->steps ?? [];
        if (empty($steps)) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape a exporter.");
        }

        // Build a recreatable command (simple messages only)
        $stepMessages = array_map(fn($s) => trim($s['message'] ?? ''), $steps);
        $stepsStr = implode(' then ', $stepMessages);

        $hasAdvanced = collect($steps)->some(fn($s) =>
            (!empty($s['agent'])) ||
            (!empty($s['condition']) && $s['condition'] !== 'always') ||
            (!empty($s['on_error']) && $s['on_error'] !== 'stop')
        );

        $stepCount = count($steps);
        $lines = [
            "Export: {$workflow->name}",
            "Statut : " . ($workflow->is_active ? 'actif' : 'inactif') . " | {$workflow->run_count} exec.",
        ];

        if (!empty($workflow->description)) {
            $lines[] = "Desc   : {$workflow->description}";
        }

        $lines[] = "";
        $lines[] = "Commande pour recreer:";
        $lines[] = "/workflow import {$workflow->name} {$stepsStr}";
        $lines[] = "";
        $lines[] = "Etapes ({$stepCount}):";

        foreach ($steps as $i => $step) {
            $msg       = $step['message'] ?? '';
            $agent     = !empty($step['agent']) ? "agent:{$step['agent']}" : '';
            $condition = (!empty($step['condition']) && $step['condition'] !== 'always') ? "si:{$step['condition']}" : '';
            $onError   = (!empty($step['on_error']) && $step['on_error'] !== 'stop') ? "on_error:{$step['on_error']}" : '';
            $meta      = implode(' ', array_filter([$agent, $condition, $onError]));
            $lines[]   = ($i + 1) . ". {$msg}" . ($meta ? " [{$meta}]" : '');
        }

        if ($hasAdvanced) {
            $lines[] = "";
            $lines[] = "Note: config avancee (agent/condition/on_error) a reproduire manuellement.";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Add a new step at the end of an existing workflow.
     * Usage: /workflow add [nom] [message]
     */
    private function commandAddStep(AgentContext $context, string $name, string $message): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow add [nom] [message_de_l_etape]\n\n"
                . "Exemple: /workflow add morning-brief check la meteo du jour"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        if (empty(trim($message))) {
            return AgentResult::reply(
                "Precise le message de la nouvelle etape:\n"
                . "/workflow add {$workflow->name} [message]"
            );
        }

        $steps = $workflow->steps ?? [];

        if (count($steps) >= 10) {
            return AgentResult::reply(
                "Ce workflow a deja 10 etapes (maximum). "
                . "Supprime une etape avec /workflow remove-step {$workflow->name} [N] ou cree un nouveau workflow."
            );
        }

        $newStep = [
            'message'   => trim($message),
            'agent'     => null,
            'condition' => 'always',
            'on_error'  => 'stop',
        ];

        $steps[] = $newStep;
        $newIndex = count($steps);

        try {
            $this->backupSteps($workflow);
            $workflow->update(['steps' => $steps]);
            $this->log($context, "Workflow step added: {$workflow->name} (now {$newIndex} steps)");

            return AgentResult::reply(
                "Etape {$newIndex} ajoutee au workflow \"{$workflow->name}\".\n"
                . "Message: " . mb_substr(trim($message), 0, 80) . "\n\n"
                . "Total: {$newIndex} etape" . ($newIndex > 1 ? 's' : '') . "\n"
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to add step to workflow", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply('Erreur lors de l\'ajout de l\'etape. Reessaie.');
        }
    }

    /**
     * Remove a specific step from a workflow.
     * Usage: /workflow remove-step [nom] [N]
     */
    private function commandRemoveStep(AgentContext $context, string $name, string $stepArg): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow remove-step [nom] [numero_etape]\n\n"
                . "Exemple: /workflow remove-step morning-brief 2"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $stepNumber = (int) trim(preg_split('/\s+/', $stepArg)[0] ?? '0');
        $stepIndex  = $stepNumber - 1;
        $steps      = $workflow->steps ?? [];

        if ($stepIndex < 0 || $stepIndex >= count($steps)) {
            $count = count($steps);
            return AgentResult::reply(
                "Numero d'etape invalide. Ce workflow a {$count} etape" . ($count > 1 ? 's' : '') . " (1 a {$count}).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes."
            );
        }

        if (count($steps) === 1) {
            return AgentResult::reply(
                "Impossible de supprimer la seule etape du workflow.\n"
                . "Supprime le workflow entier avec: /workflow delete {$workflow->name}"
            );
        }

        $removedMsg = mb_substr($steps[$stepIndex]['message'] ?? '', 0, 80);
        array_splice($steps, $stepIndex, 1);

        try {
            $this->backupSteps($workflow);
            $workflow->update(['steps' => $steps]);
            $remaining = count($steps);
            $this->log($context, "Workflow step removed: {$workflow->name} step {$stepNumber} (now {$remaining} steps)");

            return AgentResult::reply(
                "Etape {$stepNumber} supprimee du workflow \"{$workflow->name}\".\n"
                . "Etape supprimee: " . $removedMsg . "\n\n"
                . "Etapes restantes: {$remaining}\n"
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to remove step from workflow", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'step'     => $stepNumber,
            ]);
            return AgentResult::reply('Erreur lors de la suppression de l\'etape. Reessaie.');
        }
    }

    /**
     * Set or update the description of a workflow.
     * Usage: /workflow describe [nom] [description]
     */
    private function commandDescribe(AgentContext $context, string $name, string $description): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow describe [nom] [description]\n\n"
                . "Exemple: /workflow describe morning-brief Routine matinale: todos + rappels + meteo"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $description = trim($description);

        if (empty($description)) {
            // Show current description if no new one provided
            $current = $workflow->description ?? '(aucune description)';
            return AgentResult::reply(
                "Description actuelle de \"{$workflow->name}\":\n{$current}\n\n"
                . "Pour modifier: /workflow describe {$workflow->name} [nouvelle description]"
            );
        }

        if (mb_strlen($description) > 200) {
            return AgentResult::reply(
                "Description trop longue (" . mb_strlen($description) . " car.). Maximum 200 caracteres."
            );
        }

        try {
            $workflow->update(['description' => $description]);
            $this->log($context, "Workflow description updated: {$workflow->name}");

            return AgentResult::reply(
                "Description du workflow \"{$workflow->name}\" mise a jour.\n\n"
                . "Description: {$description}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to update workflow description", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply('Erreur lors de la mise a jour de la description. Reessaie.');
        }
    }

    /**
     * Move a step from one position to another within a workflow.
     * Usage: /workflow move-step [nom] [from] [to]
     */
    private function commandMoveStep(AgentContext $context, string $name, string $args): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow move-step [nom] [position_actuelle] [nouvelle_position]\n\n"
                . "Exemple: /workflow move-step morning-brief 3 1  (deplace l'etape 3 en premiere position)"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $parts = preg_split('/\s+/', trim($args), 2);
        $from  = (int) ($parts[0] ?? 0);
        $to    = (int) ($parts[1] ?? 0);

        $steps = $workflow->steps ?? [];
        $count = count($steps);

        if ($count < 2) {
            return AgentResult::reply("Ce workflow n'a qu'une seule etape. Impossible de reordonner.");
        }

        if ($from < 1 || $from > $count) {
            return AgentResult::reply(
                "Position source invalide: {$from}. Ce workflow a {$count} etapes (1 a {$count}).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes."
            );
        }

        if ($to < 1 || $to > $count) {
            return AgentResult::reply(
                "Position cible invalide: {$to}. Ce workflow a {$count} etapes (1 a {$count})."
            );
        }

        if ($from === $to) {
            return AgentResult::reply("L'etape {$from} est deja a la position {$to}.");
        }

        // Perform the move: remove from position, insert at target
        $fromIndex = $from - 1;
        $toIndex   = $to - 1;
        $moved     = array_splice($steps, $fromIndex, 1);
        array_splice($steps, $toIndex, 0, $moved);

        try {
            $this->backupSteps($workflow);
            $workflow->update(['steps' => $steps]);
            $this->log($context, "Workflow step moved: {$workflow->name} step {$from} -> {$to}");

            $movedMsg = mb_substr($moved[0]['message'] ?? '', 0, 60);
            return AgentResult::reply(
                "Etape {$from} deplacee en position {$to} dans \"{$workflow->name}\".\n"
                . "Etape: \"{$movedMsg}\"\n\n"
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to move step", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'from'     => $from,
                'to'       => $to,
            ]);
            return AgentResult::reply('Erreur lors du deplacement de l\'etape. Reessaie.');
        }
    }

    /**
     * Search for a term within workflow step messages.
     * Usage: /workflow search [terme]
     */
    private function commandSearch(AgentContext $context, string $term): AgentResult
    {
        if (empty(trim($term))) {
            return AgentResult::reply(
                "Usage: /workflow search [terme]\n\n"
                . "Recherche un mot ou une phrase dans les etapes de tes workflows.\n"
                . "Exemple: /workflow search todos"
            );
        }

        $term = trim($term);
        $workflows = Workflow::forUser($context->from)->orderBy('name')->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow cree.\n\n"
                . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        $matches = [];
        $lowerTerm = mb_strtolower($term);

        foreach ($workflows as $wf) {
            $matchingSteps = [];
            foreach (($wf->steps ?? []) as $i => $step) {
                $msg = $step['message'] ?? '';
                if (mb_strpos(mb_strtolower($msg), $lowerTerm) !== false) {
                    $matchingSteps[] = ($i + 1) . '. ' . mb_substr($msg, 0, 70);
                }
            }
            if (!empty($matchingSteps)) {
                $matches[] = ['workflow' => $wf, 'steps' => $matchingSteps];
            }
        }

        if (empty($matches)) {
            return AgentResult::reply(
                "Aucun resultat pour \"{$term}\" dans tes workflows.\n\n"
                . "Essaie un autre terme ou utilise /workflow list pour voir tous tes workflows."
            );
        }

        $total = count($matches);
        $lines = [
            "Recherche \"{$term}\" — {$total} workflow" . ($total > 1 ? 's' : '') . " trouve" . ($total > 1 ? 's' : ''),
            str_repeat('─', 28),
        ];

        foreach ($matches as $match) {
            $wf     = $match['workflow'];
            $status = $wf->is_active ? 'ON' : 'OFF';
            $lines[] = "[{$status}] {$wf->name}";
            foreach ($match['steps'] as $stepLine) {
                $lines[] = "  → {$stepLine}";
            }
        }

        $lines[] = str_repeat('─', 28);
        $lines[] = "/workflow trigger [nom]  — lancer";
        $lines[] = "/workflow show [nom]     — details";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Import (recreate) a workflow from a text definition.
     * Usage: /workflow import [nom] [etape1] then [etape2] then [etape3]
     */
    private function commandImport(AgentContext $context, string $arg): AgentResult
    {
        if (empty($arg)) {
            return AgentResult::reply(
                "Importe un workflow depuis une definition texte.\n\n"
                . "Usage: /workflow import [nom] [etape1] then [etape2]\n\n"
                . "Exemple:\n"
                . "  /workflow import morning-brief check todos then voir rappels then meteo\n\n"
                . "Tu peux copier la commande depuis /workflow export [nom]."
            );
        }

        $parsed = $this->parseWorkflowDefinition($arg);
        if (!$parsed || empty($parsed['steps'])) {
            return AgentResult::reply(
                "Definition invalide. Separe les etapes avec \"then\", \"puis\" ou \">>\".\n\n"
                . "Exemple: /workflow import daily-brief check todos then voir rappels"
            );
        }

        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $parsed['name'])) {
            return AgentResult::reply(
                "Nom invalide: \"{$parsed['name']}\". Utilise uniquement des lettres, chiffres, tirets et underscores."
            );
        }

        if (count($parsed['steps']) > 10) {
            return AgentResult::reply(
                "Maximum 10 etapes par workflow (tu en as " . count($parsed['steps']) . ").\n"
                . "Divise ton workflow en plusieurs workflows plus courts."
            );
        }

        // Validate each step
        foreach ($parsed['steps'] as $i => $step) {
            if (empty(trim($step['message'] ?? ''))) {
                return AgentResult::reply("L'etape " . ($i + 1) . " est vide. Chaque etape doit avoir une instruction.");
            }
        }

        // Check for duplicate name — offer to auto-suffix
        $existing = Workflow::forUser($context->from)->where('name', $parsed['name'])->first();
        if ($existing) {
            $parsed['name'] = $parsed['name'] . '-import-' . now()->format('His');
        }

        $preview = $this->formatWorkflowPreview($parsed);
        $this->setPendingContext($context, 'confirm_workflow', $parsed, 3);

        return AgentResult::reply(
            "Workflow a importer:\n{$preview}\n\nConfirmer? (oui/non)"
        );
    }

    /**
     * Show execution history sorted by last run date.
     * Usage: /workflow history          — tous les workflows exécutés
     *        /workflow history [nom]    — historique d'un workflow spécifique
     */
    private function commandHistory(AgentContext $context, string $name = ''): AgentResult
    {
        // Specific workflow history
        if (!empty(trim($name))) {
            $workflow = $this->findWorkflow($context->from, trim($name));
            if (!$workflow) {
                return AgentResult::reply(
                    "Workflow \"{$name}\" introuvable.\n"
                    . "Utilise /workflow list pour voir tes workflows."
                );
            }
            $status  = $workflow->is_active ? 'Actif' : 'Inactif';
            $lastRun = $workflow->last_run_at ? $workflow->last_run_at->format('d/m/Y H:i') : 'jamais';
            $ago     = $workflow->last_run_at ? $workflow->last_run_at->diffForHumans() : '';
            $steps   = count($workflow->steps ?? []);
            $created = $workflow->created_at->format('d/m/Y');

            $lines = [
                "Historique: {$workflow->name}",
                str_repeat('─', 26),
                "Statut   : {$status}",
                "Etapes   : {$steps}",
                "Cree le  : {$created}",
                "Executions: {$workflow->run_count}",
                "Dernier  : {$lastRun}" . ($ago ? " ({$ago})" : ''),
            ];

            if ($workflow->run_count === 0) {
                $lines[] = '';
                $lines[] = "Ce workflow n'a jamais ete execute.";
                $lines[] = "Lance-le: /workflow trigger {$workflow->name}";
            } else {
                $lines[] = '';
                $lines[] = "/workflow trigger {$workflow->name}  — relancer";
                $lines[] = "/workflow reset-stats {$workflow->name}  — remettre a zero";
            }

            return AgentResult::reply(implode("\n", $lines));
        }

        // Global history
        $workflows = Workflow::forUser($context->from)
            ->whereNotNull('last_run_at')
            ->orderByDesc('last_run_at')
            ->get();

        $neverRun = Workflow::forUser($context->from)
            ->whereNull('last_run_at')
            ->count();

        if ($workflows->isEmpty()) {
            $total = $neverRun;
            $msg = $total > 0
                ? "Aucun workflow n'a encore ete execute ({$total} workflow" . ($total > 1 ? 's' : '') . " cree" . ($total > 1 ? 's' : '') . ", jamais lances).\n\n"
                  . "Lance un workflow avec: /workflow trigger [nom]"
                : "Aucun workflow cree.\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]";
            return AgentResult::reply($msg);
        }

        $total = $workflows->count();
        $lines = [
            "Historique d'executions" . ($total > 10 ? " (10/{$total})" : ''),
            str_repeat('─', 26),
        ];

        foreach ($workflows->take(10) as $wf) {
            $status  = $wf->is_active ? 'ON' : 'OFF';
            $lastRun = $wf->last_run_at->format('d/m/Y H:i');
            $ago     = $wf->last_run_at->diffForHumans();
            $steps   = count($wf->steps ?? []);
            $lines[] = "{$wf->name} [{$status}]";
            $lines[] = "  {$wf->run_count}x · dernier: {$lastRun} ({$ago})";
            $lines[] = "  {$steps} etape" . ($steps > 1 ? 's' : '');
        }

        $lines[] = str_repeat('─', 26);

        if ($total > 10) {
            $lines[] = "+ " . ($total - 10) . " workflow" . ($total - 10 > 1 ? 's' : '') . " supplementaire" . ($total - 10 > 1 ? 's' : '') . ".";
        }
        if ($neverRun > 0) {
            $lines[] = "{$neverRun} workflow" . ($neverRun > 1 ? 's' : '') . " jamais lance" . ($neverRun > 1 ? 's' : '') . ".";
        }

        $lines[] = "/workflow history [nom]  — detail par workflow";
        $lines[] = "/workflow trigger [nom]  — lancer";
        $lines[] = "/workflow stats          — statistiques";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Execute a saved workflow, optionally with input context injected into each step.
     *
     * When $input is provided, each step's message is prefixed with the context so that
     * the receiving agent can use it. Example: trigger "morning-brief" with input "focus finance"
     * → step "check todos" becomes "[Contexte: focus finance] check todos".
     */
    private function triggerWorkflow(AgentContext $context, Workflow $workflow, ?string $input = null): AgentResult
    {
        $stepCount = count($workflow->steps ?? []);
        $inputHint = $input ? " (contexte: " . mb_substr($input, 0, 40) . ")" : '';
        $this->sendText($context->from, "Lancement du workflow \"{$workflow->name}\" ({$stepCount} etape" . ($stepCount > 1 ? 's' : '') . "){$inputHint}...");
        $this->log($context, "Workflow triggered: {$workflow->name}", ['id' => $workflow->id, 'input' => $input]);

        // If input is provided, create a temporary copy with context-injected steps
        $workflowToRun = $workflow;
        if ($input) {
            $contextPrefix = "[Contexte: {$input}] ";
            $injectedSteps = array_map(function (array $step) use ($contextPrefix) {
                $step['message'] = $contextPrefix . ($step['message'] ?? '');
                return $step;
            }, $workflow->steps ?? []);

            $workflowToRun = clone $workflow;
            $workflowToRun->steps = $injectedSteps;
        }

        try {
            $orchestrator    = new AgentOrchestrator();
            $executor        = new WorkflowExecutor($orchestrator);
            $executionResult = $executor->execute($workflowToRun, $context);

            return AgentResult::reply(
                WorkflowExecutor::formatResults($executionResult),
                ['workflow_execution' => $executionResult]
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: workflow execution failed", [
                'workflow' => $workflow->name,
                'error'    => $e->getMessage(),
            ]);
            return AgentResult::reply(
                "Erreur lors de l'execution du workflow \"{$workflow->name}\".\n"
                . "Verifie /workflow show {$workflow->name} et reessaie. Si le probleme persiste, contacte le support."
            );
        }
    }

    /**
     * Create and save a workflow from parsed data.
     */
    private function createWorkflowFromParsed(AgentContext $context, array $data): AgentResult
    {
        try {
            $workflow = Workflow::create([
                'user_phone'  => $context->from,
                'agent_id'    => $context->agent?->id,
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
                'steps'       => $data['steps'],
                'triggers'    => $data['triggers'] ?? null,
                'conditions'  => $data['conditions'] ?? null,
                'is_active'   => true,
            ]);

            $stepCount = count($data['steps']);
            $this->log($context, "Workflow cree: {$workflow->name}", ['id' => $workflow->id, 'steps' => $stepCount]);

            return AgentResult::reply(
                "Workflow \"{$workflow->name}\" cree avec {$stepCount} etape" . ($stepCount > 1 ? 's' : '') . ".\n"
                . "Lance-le avec: /workflow trigger {$workflow->name}\n"
                . "Details: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to create workflow", [
                'error' => $e->getMessage(),
                'name'  => $data['name'] ?? 'unknown',
            ]);
            return AgentResult::reply('Erreur lors de la creation du workflow. Reessaie.');
        }
    }

    /**
     * Parse a workflow definition string: "name step1 then step2 then step3"
     */
    private function parseWorkflowDefinition(string $input): ?array
    {
        $parts = preg_split('/\s+/', $input, 2);
        $name  = $parts[0] ?? 'workflow';
        $rest  = $parts[1] ?? '';

        if (empty($rest)) {
            return null;
        }

        $steps = $this->splitChainSteps($rest);

        if (empty($steps)) {
            return null;
        }

        return [
            'name'  => mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $name)),
            'steps' => array_map(fn($s) => [
                'message'   => trim($s),
                'agent'     => null,
                'condition' => 'always',
                'on_error'  => 'stop',
            ], $steps),
        ];
    }

    /**
     * Split text into steps using chain delimiters.
     */
    private function splitChainSteps(string $text): array
    {
        $steps = preg_split(
            '/\b(?:then|puis|et\s+puis|ensuite|et\s+ensuite|after\s+that|apr[eè]s\s+[cç]a|apr[eè]s\s+[cç]ela)\b|>>/iu',
            $text
        );

        return array_values(array_filter(array_map('trim', $steps), fn($s) => !empty($s)));
    }

    /**
     * Check if the message contains inline chain patterns.
     */
    private function isInlineChain(string $lower): bool
    {
        return (bool) preg_match('/\b(then|puis|et\s+puis|ensuite|et\s+ensuite|after\s+that|apr[eè]s\s+[cç]a|apr[eè]s\s+[cç]ela)\b|>>/iu', $lower);
    }

    /**
     * Find a workflow by exact name, then prefix match, then partial match.
     * Returns the most specific match to avoid wrong-workflow confusion.
     */
    private function findWorkflow(string $userPhone, string $name): ?Workflow
    {
        $lowerName = mb_strtolower(trim($name));

        // 1. Exact match (case-insensitive)
        $workflow = Workflow::forUser($userPhone)
            ->whereRaw('LOWER(name) = ?', [$lowerName])
            ->first();

        if ($workflow) {
            return $workflow;
        }

        // 2. Prefix match (starts with the search term)
        $prefixMatches = Workflow::forUser($userPhone)
            ->whereRaw('LOWER(name) LIKE ?', [$lowerName . '%'])
            ->get();

        if ($prefixMatches->count() === 1) {
            return $prefixMatches->first();
        }

        // 3. Partial match (contains the search term) — only if unique
        $partialMatches = Workflow::forUser($userPhone)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $lowerName . '%'])
            ->get();

        if ($partialMatches->count() === 1) {
            return $partialMatches->first();
        }

        // Multiple matches or no match at all: return null (caller should use findWorkflowOrAmbiguous)
        return null;
    }

    /**
     * Find a workflow and return an AgentResult with disambiguation if multiple matches exist.
     * Returns [?Workflow, ?AgentResult] — if AgentResult is set, return it immediately.
     */
    private function findWorkflowOrAmbiguous(string $userPhone, string $name): array
    {
        $lowerName = mb_strtolower(trim($name));

        // Exact match
        $exact = Workflow::forUser($userPhone)
            ->whereRaw('LOWER(name) = ?', [$lowerName])
            ->first();

        if ($exact) {
            return [$exact, null];
        }

        // Partial matches
        $matches = Workflow::forUser($userPhone)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $lowerName . '%'])
            ->orderBy('name')
            ->get();

        if ($matches->count() === 0) {
            return [null, AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            )];
        }

        if ($matches->count() === 1) {
            return [$matches->first(), null];
        }

        // Ambiguous: list matches
        $lines = ["Plusieurs workflows correspondent a \"{$name}\":"];
        foreach ($matches->values() as $i => $wf) {
            $lines[] = ($i + 1) . ". {$wf->name}";
        }
        $lines[] = "\nPrecise le nom exact ou utilise /workflow list.";

        return [null, AgentResult::reply(implode("\n", $lines))];
    }

    /**
     * Format a workflow preview for confirmation.
     */
    private function formatWorkflowPreview(array $data): string
    {
        $stepCount = count($data['steps'] ?? []);
        $lines = [
            "Nom: {$data['name']}",
            "Etapes: {$stepCount}",
            '',
        ];

        foreach ($data['steps'] as $i => $step) {
            $msg       = mb_substr($step['message'] ?? '', 0, 100);
            $agent     = $step['agent'] ?? 'auto';
            $condition = !empty($step['condition']) && $step['condition'] !== 'always'
                ? " [si: {$step['condition']}]"
                : '';
            $onError   = !empty($step['on_error']) && $step['on_error'] !== 'stop'
                ? " [on_error: {$step['on_error']}]"
                : '';
            $lines[] = "  " . ($i + 1) . ". [{$agent}] {$msg}{$condition}{$onError}";
        }

        return implode("\n", $lines);
    }

    /**
     * Dry-run a workflow: show what each step would do without executing anything.
     * Usage: /workflow dryrun [nom]
     */
    private function commandDryrun(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow dryrun [nom]\n\n"
                . "Affiche ce que ferait le workflow sans l'executer.\n"
                . "Exemple: /workflow dryrun morning-brief"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $steps = $workflow->steps ?? [];

        if (empty($steps)) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape a simuler.");
        }

        $status    = $workflow->is_active ? 'Actif' : 'Inactif';
        $stepCount = count($steps);

        $lines = [
            "[SIMULATION] Workflow: {$workflow->name}",
            "Statut: {$status} | {$stepCount} etape" . ($stepCount > 1 ? 's' : ''),
            str_repeat('─', 32),
            "Ordre d'execution prevu:",
            '',
        ];

        $agentMap = [
            'todo'               => 'TodoAgent',
            'reminder'           => 'ReminderAgent',
            'event_reminder'     => 'EventReminderAgent',
            'finance'            => 'FinanceAgent',
            'habit'              => 'HabitAgent',
            'pomodoro'           => 'PomodoroAgent',
            'chat'               => 'ChatAgent',
            'dev'                => 'DevAgent',
            'document'           => 'DocumentAgent',
            'analysis'           => 'AnalysisAgent',
            'web_search'         => 'WebSearchAgent',
            'content_summarizer' => 'ContentSummarizerAgent',
            'code_review'        => 'CodeReviewAgent',
            'music'              => 'MusicAgent',
            'streamline'         => 'StreamlineAgent',
        ];

        foreach ($steps as $i => $step) {
            $stepNum   = $i + 1;
            $agentKey  = $step['agent'] ?? null;
            $agentName = $agentKey ? ($agentMap[$agentKey] ?? $agentKey) : 'auto-detection';
            $msg       = mb_substr($step['message'] ?? '(vide)', 0, 100);
            $condition = !empty($step['condition']) && $step['condition'] !== 'always'
                ? " · condition: {$step['condition']}"
                : '';
            $onError   = !empty($step['on_error']) && $step['on_error'] !== 'stop'
                ? " · si erreur: continuer"
                : ' · si erreur: stop';

            $isSkipped = !empty($step['_skip']);
            $skipLabel = $isSkipped ? ' [SKIP]' : '';
            $lines[] = "Etape {$stepNum} → {$agentName}{$skipLabel}";
            $lines[] = "  Message : {$msg}";
            $lines[] = $isSkipped
                ? "  Config  : DESACTIVEE — sera ignoree"
                : "  Config  : toujours{$condition}{$onError}";

            if ($stepNum < $stepCount) {
                $lines[] = "  ↓";
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('─', 32);
        $lines[] = "Aucune action executee — simulation uniquement.";
        $lines[] = "Pour executer: /workflow trigger {$workflow->name}";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Reset run stats for a workflow (run_count + last_run_at).
     * Usage: /workflow reset-stats [nom]
     */
    private function commandResetStats(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow reset-stats [nom]\n\n"
                . "Remet a zero les statistiques d'execution d'un workflow.\n"
                . "Exemple: /workflow reset-stats morning-brief"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $previousCount = $workflow->run_count;
        $previousDate  = $workflow->last_run_at?->format('d/m/Y H:i') ?? 'jamais';

        try {
            $workflow->update([
                'run_count'   => 0,
                'last_run_at' => null,
            ]);
            $this->log($context, "Workflow stats reset: {$workflow->name} (was {$previousCount} runs)");

            return AgentResult::reply(
                "Stats du workflow \"{$workflow->name}\" remises a zero.\n\n"
                . "Avant: {$previousCount} exec. · dernier {$previousDate}\n"
                . "Apres: 0 exec. · jamais execute\n\n"
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to reset workflow stats", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply('Erreur lors de la remise a zero des stats. Reessaie.');
        }
    }

    /**
     * Create a workflow from a predefined template.
     * Usage: /workflow template              — list available templates
     *        /workflow template [name] [nom] — create workflow from template
     */
    private function commandTemplate(AgentContext $context, string $templateName, string $workflowName): AgentResult
    {
        $templates = $this->getPredefinedTemplates();

        // No template name: list available templates
        if (empty($templateName)) {
            $lines = [
                "Templates disponibles (" . count($templates) . "):",
                str_repeat('─', 28),
            ];
            foreach ($templates as $key => $tpl) {
                $stepCount = count($tpl['steps']);
                $lines[] = "  {$key}";
                $lines[] = "    " . $tpl['description'] . " ({$stepCount} etapes)";
            }
            $lines[] = '';
            $lines[] = "Usage: /workflow template [nom-template] [nom-workflow]";
            $lines[] = "Exemple: /workflow template morning-brief ma-routine";
            return AgentResult::reply(implode("\n", $lines));
        }

        if (!isset($templates[$templateName])) {
            $available = implode(', ', array_keys($templates));
            return AgentResult::reply(
                "Template \"{$templateName}\" introuvable.\n\n"
                . "Templates disponibles: {$available}\n\n"
                . "Utilise /workflow template pour voir tous les templates."
            );
        }

        $template = $templates[$templateName];

        // Auto-generate workflow name from template if not provided
        $finalName = !empty($workflowName)
            ? mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $workflowName))
            : $templateName;
        $finalName = trim($finalName, '-');

        if (empty($finalName) || !preg_match('/^[a-zA-Z0-9\-_]+$/', $finalName)) {
            return AgentResult::reply(
                "Nom de workflow invalide: \"{$workflowName}\".\n"
                . "Utilise uniquement des lettres, chiffres, tirets et underscores."
            );
        }

        // Handle duplicate name
        $existing = Workflow::forUser($context->from)->where('name', $finalName)->first();
        if ($existing) {
            $finalName = $finalName . '-' . now()->format('His');
        }

        $data = [
            'name'        => $finalName,
            'description' => $template['description'],
            'steps'       => $template['steps'],
        ];

        $preview = $this->formatWorkflowPreview($data);
        $this->setPendingContext($context, 'confirm_workflow', $data, 3);

        return AgentResult::reply(
            "Template \"{$templateName}\" → workflow \"{$finalName}\":\n\n"
            . "{$preview}\n\n"
            . "Confirmer? (oui/non)"
        );
    }

    /**
     * Returns the list of predefined workflow templates.
     */
    private function getPredefinedTemplates(): array
    {
        return [
            'morning-brief' => [
                'description' => 'Bilan matinal: todos + rappels + finances',
                'steps' => [
                    ['message' => 'liste mes taches non completees du jour', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'mes rappels pour aujourd\'hui et demain', 'agent' => 'reminder', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'resume mon solde et les dernieres transactions', 'agent' => 'finance', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'productivity' => [
                'description' => 'Session productive: focus + taches + habitudes',
                'steps' => [
                    ['message' => 'quelles taches prioritaires dois-je faire aujourd\'hui?', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'lance un pomodoro de 25 minutes pour travailler', 'agent' => 'pomodoro', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'verifie mes habitudes du jour et lesquelles sont en retard', 'agent' => 'habit', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'code-session' => [
                'description' => 'Session de code: revue + analyse + documentation',
                'steps' => [
                    ['message' => 'analyse les derniers fichiers modifies et identifie les problemes potentiels', 'agent' => 'code_review', 'condition' => 'always', 'on_error' => 'stop'],
                    ['message' => 'resume les points cles de l\'analyse precedente en 5 points actionables', 'agent' => 'chat', 'condition' => 'success', 'on_error' => 'stop'],
                    ['message' => 'cree une note de session avec les decisions techniques prises', 'agent' => 'document', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'weekly-review' => [
                'description' => 'Revue hebdomadaire: bilan + planification',
                'steps' => [
                    ['message' => 'liste toutes mes taches completees cette semaine', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'montre mes statistiques d\'habitudes de la semaine', 'agent' => 'habit', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'resume mes depenses et revenus de la semaine', 'agent' => 'finance', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'quels sont mes rappels et evenements de la semaine prochaine?', 'agent' => 'reminder', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'daily-check' => [
                'description' => 'Check quotidien leger: taches + rappels',
                'steps' => [
                    ['message' => 'mes taches ouvertes pour aujourd\'hui', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'rappels du jour et du lendemain', 'agent' => 'reminder', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'evening-check' => [
                'description' => 'Bilan du soir: taches restantes + planification demain',
                'steps' => [
                    ['message' => 'liste mes taches non completees d\'aujourd\'hui', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'quels sont mes evenements et rappels pour demain?', 'agent' => 'reminder', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'verifie mes habitudes du jour: lesquelles ai-je completees et lesquelles sont en retard?', 'agent' => 'habit', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'resume ma journee et propose 3 priorites pour demain', 'agent' => 'chat', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'study-session' => [
                'description' => 'Session d\'etude: focus + revision + notes',
                'steps' => [
                    ['message' => 'lance un pomodoro de 45 minutes pour une session d\'etude intensive', 'agent' => 'pomodoro', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'quelles taches d\'etude ou de formation sont ouvertes aujourd\'hui?', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'cree une note de session d\'etude avec la date, le sujet et les points cles appris', 'agent' => 'document', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'finance-check' => [
                'description' => 'Bilan financier complet: solde + depenses + budget',
                'steps' => [
                    ['message' => 'affiche mon solde actuel et les 10 dernieres transactions', 'agent' => 'finance', 'condition' => 'always', 'on_error' => 'stop'],
                    ['message' => 'resume mes depenses par categorie ce mois-ci et compare avec le mois dernier', 'agent' => 'finance', 'condition' => 'success', 'on_error' => 'continue'],
                    ['message' => 'y a-t-il des anomalies ou des depenses inhabituelles dans mes finances recentes?', 'agent' => 'analysis', 'condition' => 'success', 'on_error' => 'continue'],
                ],
            ],
            'health-check' => [
                'description' => 'Suivi bien-etre: habitudes + activite + pomodoro',
                'steps' => [
                    ['message' => 'montre mes habitudes du jour: lesquelles sont faites et lesquelles restent a faire', 'agent' => 'habit', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'quelles habitudes de sante ou sport ai-je validees cette semaine?', 'agent' => 'habit', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'lance un pomodoro de 20 minutes pour une pause active ou exercice', 'agent' => 'pomodoro', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'content-review' => [
                'description' => 'Veille et curation: recherche + resume + note',
                'steps' => [
                    ['message' => 'recherche les dernières actualites et tendances sur mon domaine principal', 'agent' => 'web_search', 'condition' => 'always', 'on_error' => 'stop'],
                    ['message' => 'resume les points cles et insights les plus importants trouves dans la recherche precedente', 'agent' => 'content_summarizer', 'condition' => 'success', 'on_error' => 'continue'],
                    ['message' => 'cree une note de veille avec la date et les insights cles du jour', 'agent' => 'document', 'condition' => 'success', 'on_error' => 'continue'],
                ],
            ],
        ];
    }

    /**
     * Insert a new step at a specific position within a workflow.
     * Usage: /workflow insert [nom] [position] [message]
     * Example: /workflow insert morning-brief 2 check la meteo du jour
     */
    private function commandInsertStep(AgentContext $context, string $name, string $args): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow insert [nom] [position] [message]\n\n"
                . "Insere une etape a une position precise (les etapes suivantes sont decalees).\n"
                . "Exemple: /workflow insert morning-brief 2 check la meteo du jour"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $parts      = preg_split('/\s+/', trim($args), 2);
        $position   = (int) ($parts[0] ?? 0);
        $message    = trim($parts[1] ?? '');
        $steps      = $workflow->steps ?? [];
        $stepCount  = count($steps);

        if ($position < 1 || $position > $stepCount + 1) {
            return AgentResult::reply(
                "Position invalide: {$position}. "
                . "Valeurs acceptees: 1 a " . ($stepCount + 1) . " (apres la derniere etape).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes existantes."
            );
        }

        if (empty($message)) {
            return AgentResult::reply(
                "Precise le message de la nouvelle etape:\n"
                . "/workflow insert {$workflow->name} {$position} [message]"
            );
        }

        if ($stepCount >= 10) {
            return AgentResult::reply(
                "Ce workflow a deja 10 etapes (maximum). "
                . "Supprime une etape avec /workflow remove-step {$workflow->name} [N] avant d'en ajouter."
            );
        }

        $newStep = [
            'message'   => $message,
            'agent'     => null,
            'condition' => 'always',
            'on_error'  => 'stop',
        ];

        array_splice($steps, $position - 1, 0, [$newStep]);

        try {
            $this->backupSteps($workflow);
            $workflow->update(['steps' => $steps]);
            $total = count($steps);
            $this->log($context, "Workflow step inserted: {$workflow->name} at position {$position} (now {$total} steps)");

            return AgentResult::reply(
                "Etape inseree en position {$position} dans \"{$workflow->name}\".\n"
                . "Message: " . mb_substr($message, 0, 80) . "\n\n"
                . "Total: {$total} etape" . ($total > 1 ? 's' : '') . "\n"
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to insert step into workflow", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'position' => $position,
            ]);
            return AgentResult::reply("Erreur lors de l'insertion de l'etape. Reessaie.");
        }
    }

    /**
     * Manage tags for a workflow (stored in conditions JSON).
     * Usage: /workflow tag [nom]           — afficher les tags
     *        /workflow tag [nom] [tags]    — definir les tags (comma-separated)
     *        /workflow tag [nom] clear     — supprimer tous les tags
     *        /workflow tags               — lister tous les tags utilises
     */
    private function commandTag(AgentContext $context, string $name, string $tagsArg): AgentResult
    {
        // /workflow tags (no name) — list all tags used across workflows
        if (empty($name) || $name === 'list') {
            $workflows = Workflow::forUser($context->from)->get();

            if ($workflows->isEmpty()) {
                return AgentResult::reply(
                    "Aucun workflow cree.\n\n"
                    . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
                );
            }

            $allTags = [];
            foreach ($workflows as $wf) {
                $tags = $wf->conditions['tags'] ?? [];
                foreach ($tags as $tag) {
                    $allTags[$tag][] = $wf->name;
                }
            }

            if (empty($allTags)) {
                return AgentResult::reply(
                    "Aucun tag defini sur tes workflows.\n\n"
                    . "Pour tagger un workflow:\n/workflow tag [nom] [tag1,tag2]"
                );
            }

            ksort($allTags);
            $lines = ["Tags utilises (" . count($allTags) . "):", str_repeat('─', 24)];
            foreach ($allTags as $tag => $wfNames) {
                $count = count($wfNames);
                $lines[] = "#{$tag} ({$count}): " . implode(', ', $wfNames);
            }
            $lines[] = '';
            $lines[] = "Filtrer par tag: /workflow list #{$tag}";

            return AgentResult::reply(implode("\n", $lines));
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        // No tags arg: show current tags
        if (empty(trim($tagsArg))) {
            $tags = $workflow->conditions['tags'] ?? [];
            if (empty($tags)) {
                return AgentResult::reply(
                    "Aucun tag sur \"{$workflow->name}\".\n\n"
                    . "Pour ajouter des tags:\n/workflow tag {$workflow->name} travail,urgent"
                );
            }
            $tagList = implode(', ', array_map(fn($t) => "#{$t}", $tags));
            return AgentResult::reply(
                "Tags de \"{$workflow->name}\": {$tagList}\n\n"
                . "Modifier: /workflow tag {$workflow->name} [nouveaux-tags]\n"
                . "Supprimer: /workflow tag {$workflow->name} clear"
            );
        }

        // "clear" — remove all tags
        if (mb_strtolower(trim($tagsArg)) === 'clear') {
            $conditions = $workflow->conditions ?? [];
            unset($conditions['tags']);
            $workflow->update(['conditions' => $conditions]);
            $this->log($context, "Workflow tags cleared: {$workflow->name}");
            return AgentResult::reply("Tags supprimes du workflow \"{$workflow->name}\".");
        }

        // Parse and validate tags
        $rawTags = preg_split('/[\s,;]+/', mb_strtolower(trim($tagsArg)));
        $tags = array_values(array_unique(array_filter(array_map(function (string $t) {
            $t = preg_replace('/[^a-z0-9\-_]/', '', ltrim($t, '#'));
            return strlen($t) >= 1 && strlen($t) <= 30 ? $t : null;
        }, $rawTags))));

        if (empty($tags)) {
            return AgentResult::reply(
                "Tags invalides. Utilise des lettres, chiffres, tirets et underscores.\n"
                . "Exemple: /workflow tag {$workflow->name} travail,urgent,quotidien"
            );
        }

        if (count($tags) > 10) {
            return AgentResult::reply("Maximum 10 tags par workflow (tu en as " . count($tags) . ").");
        }

        try {
            $conditions = $workflow->conditions ?? [];
            $conditions['tags'] = $tags;
            $workflow->update(['conditions' => $conditions]);
            $tagList = implode(', ', array_map(fn($t) => "#{$t}", $tags));
            $this->log($context, "Workflow tags updated: {$workflow->name} → [{$tagList}]");

            return AgentResult::reply(
                "Tags mis a jour pour \"{$workflow->name}\".\n"
                . "Tags: {$tagList}\n\n"
                . "Voir tous les tags: /workflow tags"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to update tags", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply("Erreur lors de la mise a jour des tags. Reessaie.");
        }
    }

    /**
     * Pin or unpin a workflow (stores pin status in conditions JSON).
     * Usage: /workflow pin [nom]   — epingler
     *        /workflow unpin [nom] — desepingler
     */
    private function commandPin(AgentContext $context, string $name, bool $pin): AgentResult
    {
        $action = $pin ? 'epingler' : 'desepingler';

        if (empty($name)) {
            return AgentResult::reply(
                "Precise le nom du workflow a {$action}.\n"
                . "Usage: /workflow " . ($pin ? 'pin' : 'unpin') . " [nom]"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $currentlyPinned = $this->isPinned($workflow);

        if ($pin && $currentlyPinned) {
            return AgentResult::reply(
                "Le workflow \"{$workflow->name}\" est deja epingle.\n"
                . "Pour le desepingler: /workflow unpin {$workflow->name}"
            );
        }

        if (!$pin && !$currentlyPinned) {
            return AgentResult::reply(
                "Le workflow \"{$workflow->name}\" n'est pas epingle."
            );
        }

        try {
            $conditions = $workflow->conditions ?? [];
            $conditions['pinned'] = $pin;
            $workflow->update(['conditions' => $conditions]);

            $verb = $pin ? 'epingle' : 'desepingle';
            $this->log($context, "Workflow {$verb}: {$workflow->name}");

            $msg = $pin
                ? "Workflow \"{$workflow->name}\" epingle — il apparait en tete de liste.\n"
                  . "Pour desepingler: /workflow unpin {$workflow->name}"
                : "Workflow \"{$workflow->name}\" desepingle.";

            return AgentResult::reply($msg);
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to pin/unpin workflow", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'pin'      => $pin,
            ]);
            return AgentResult::reply('Erreur lors de la modification du workflow. Reessaie.');
        }
    }

    /**
     * Run all active workflows, optionally filtered by tag.
     * Usage: /workflow run-all          — tous les workflows actifs
     *        /workflow run-all #tag     — workflows actifs avec ce tag
     */
    private function commandRunAll(AgentContext $context, string $arg): AgentResult
    {
        $tagFilter = ltrim(mb_strtolower(trim($arg)), '#');

        $workflows = Workflow::forUser($context->from)->active()->orderBy('name')->get();

        if (!empty($tagFilter)) {
            $workflows = $workflows->filter(function ($wf) use ($tagFilter) {
                return in_array($tagFilter, $wf->conditions['tags'] ?? [], true);
            });
        }

        if ($workflows->isEmpty()) {
            $msg = !empty($tagFilter)
                ? "Aucun workflow actif avec le tag #{$tagFilter}.\n\nVoir les tags: /workflow tags"
                : "Aucun workflow actif.\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]";
            return AgentResult::reply($msg);
        }

        $count = $workflows->count();

        if ($count > 5) {
            $tagMsg = !empty($tagFilter) ? " (tag: #{$tagFilter})" : '';
            $lines = [
                "{$count} workflows actifs{$tagMsg}.",
                "Maximum 5 workflows a la fois pour run-all.",
                '',
                "Filtre avec un tag:",
                "  /workflow run-all #[tag]",
                '',
                "Ou lance-les un par un:",
            ];
            foreach ($workflows->take(5) as $wf) {
                $lines[] = "  /workflow trigger {$wf->name}";
            }
            if ($count > 5) {
                $lines[] = "  ... et " . ($count - 5) . " autre(s)";
            }
            return AgentResult::reply(implode("\n", $lines));
        }

        $tagMsg = !empty($tagFilter) ? " avec #{$tagFilter}" : '';
        $this->sendText($context->from, "Lancement de {$count} workflow" . ($count > 1 ? 's' : '') . "{$tagMsg}...");

        try {
            $orchestrator = new AgentOrchestrator();
            $executor     = new WorkflowExecutor($orchestrator);

            $successCount = 0;
            $errorCount   = 0;
            $errorNames   = [];

            foreach ($workflows as $wf) {
                $stepCount = count($wf->steps ?? []);
                $this->sendText($context->from, "► {$wf->name} ({$stepCount} etape" . ($stepCount > 1 ? 's' : '') . ")...");
                try {
                    $executor->execute($wf, $context);
                    $successCount++;
                } catch (\Throwable $e) {
                    Log::error("StreamlineAgent: run-all failed for {$wf->name}", [
                        'error' => $e->getMessage(),
                    ]);
                    $errorCount++;
                    $errorNames[] = $wf->name;
                }
            }

            $lines = [
                "Run-all termine: {$successCount}/{$count} workflow" . ($count > 1 ? 's' : '') . " executes.",
            ];
            if ($errorCount > 0) {
                $lines[] = "{$errorCount} erreur" . ($errorCount > 1 ? 's' : '') . ": " . implode(', ', $errorNames);
                $lines[] = "Verifie /workflow show [nom] pour diagnostiquer.";
            }
            $this->log($context, "run-all: {$successCount}/{$count} OK", [
                'tag'    => $tagFilter ?: null,
                'errors' => $errorNames,
            ]);

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: run-all failed", ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de l'execution des workflows. Reessaie.");
        }
    }

    /**
     * Run a specific list of workflows sequentially by name.
     * Usage: /workflow batch [nom1] [nom2] [nom3...]
     */
    private function commandBatch(AgentContext $context, string $arg): AgentResult
    {
        if (empty(trim($arg))) {
            return AgentResult::reply(
                "Usage: /workflow batch [nom1] [nom2] [nom3...]\n\n"
                . "Lance plusieurs workflows specifiques dans l'ordre.\n"
                . "Exemple: /workflow batch morning-brief daily-check finance-check\n\n"
                . "Voir tes workflows: /workflow list"
            );
        }

        $names = preg_split('/[\s,;]+/', trim($arg));
        $names = array_values(array_unique(array_filter(array_map('trim', $names))));

        if (count($names) < 2) {
            return AgentResult::reply(
                "Batch requiert au moins 2 workflows.\n"
                . "Exemple: /workflow batch morning-brief daily-check\n\n"
                . "Pour lancer un seul workflow: /workflow trigger [nom]"
            );
        }

        if (count($names) > 5) {
            return AgentResult::reply(
                "Maximum 5 workflows par batch (tu en as " . count($names) . ").\n"
                . "Regroupe-les ou utilise /workflow run-all #tag."
            );
        }

        // Resolve workflows: skip not found and inactive with a warning
        $resolved  = [];
        $notFound  = [];
        $skipped   = [];

        foreach ($names as $name) {
            $wf = $this->findWorkflow($context->from, $name);
            if (!$wf) {
                $notFound[] = $name;
            } elseif (!$wf->is_active) {
                $skipped[] = $wf->name;
            } else {
                $resolved[] = $wf;
            }
        }

        if (!empty($notFound)) {
            $notFoundList = implode(', ', $notFound);
            return AgentResult::reply(
                "Workflow" . (count($notFound) > 1 ? 's' : '') . " introuvable" . (count($notFound) > 1 ? 's' : '') . ": {$notFoundList}\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        if (empty($resolved)) {
            $skippedList = implode(', ', $skipped);
            return AgentResult::reply(
                "Aucun workflow a executer — tous sont desactives: {$skippedList}\n"
                . "Active-les avec: /workflow enable [nom]"
            );
        }

        // Warn about skipped inactive workflows
        if (!empty($skipped)) {
            $skippedList = implode(', ', $skipped);
            $this->sendText($context->from, "Workflows desactives ignores: {$skippedList}");
        }

        $count = count($resolved);
        $this->sendText($context->from, "Batch: {$count} workflows en sequence...");

        try {
            $orchestrator = new AgentOrchestrator();
            $executor     = new WorkflowExecutor($orchestrator);

            $successCount = 0;
            $errorCount   = 0;
            $errorNames   = [];

            foreach ($resolved as $wf) {
                $stepCount = count($wf->steps ?? []);
                $this->sendText($context->from, "► {$wf->name} ({$stepCount} etape" . ($stepCount > 1 ? 's' : '') . ")...");
                try {
                    $executor->execute($wf, $context);
                    $successCount++;
                } catch (\Throwable $e) {
                    Log::error("StreamlineAgent: batch failed for {$wf->name}", [
                        'error' => $e->getMessage(),
                    ]);
                    $errorCount++;
                    $errorNames[] = $wf->name;
                }
            }

            $lines = [
                "Batch termine: {$successCount}/{$count} workflow" . ($count > 1 ? 's' : '') . " executes.",
            ];
            if ($errorCount > 0) {
                $lines[] = "{$errorCount} erreur" . ($errorCount > 1 ? 's' : '') . ": " . implode(', ', $errorNames);
                $lines[] = "Verifie /workflow show [nom] pour diagnostiquer.";
            }

            $this->log($context, "batch: {$successCount}/{$count} OK", ['names' => $names, 'errors' => $errorNames]);
            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: batch execution failed", ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de l'execution du batch. Reessaie.");
        }
    }

    /**
     * Add, view, or clear a personal note on a workflow (stored in conditions JSON).
     * Usage: /workflow notes [nom]           — voir la note
     *        /workflow notes [nom] [texte]   — ajouter/modifier la note
     *        /workflow notes [nom] clear     — supprimer la note
     */
    private function commandNotes(AgentContext $context, string $name, string $noteArg): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow notes [nom] [note]\n\n"
                . "Ajoute une note personnelle a un workflow.\n\n"
                . "Exemples:\n"
                . "  /workflow notes morning-brief A lancer chaque matin avant 9h\n"
                . "  /workflow notes morning-brief       — voir la note actuelle\n"
                . "  /workflow notes morning-brief clear — supprimer la note"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $noteArg = trim($noteArg);

        // Show current note
        if (empty($noteArg)) {
            $note = $workflow->conditions['note'] ?? null;
            if (empty($note)) {
                return AgentResult::reply(
                    "Aucune note sur \"{$workflow->name}\".\n\n"
                    . "Pour ajouter une note:\n"
                    . "/workflow notes {$workflow->name} [texte de ta note]"
                );
            }
            return AgentResult::reply(
                "Note de \"{$workflow->name}\":\n\n{$note}\n\n"
                . "Modifier: /workflow notes {$workflow->name} [nouveau texte]\n"
                . "Supprimer: /workflow notes {$workflow->name} clear"
            );
        }

        // Clear note
        if (mb_strtolower($noteArg) === 'clear') {
            $conditions = $workflow->conditions ?? [];
            unset($conditions['note']);
            try {
                $workflow->update(['conditions' => $conditions]);
                $this->log($context, "Workflow note cleared: {$workflow->name}");
                return AgentResult::reply("Note supprimee du workflow \"{$workflow->name}\".");
            } catch (\Throwable $e) {
                Log::error("StreamlineAgent: failed to clear note", ['error' => $e->getMessage(), 'workflow' => $workflow->name]);
                return AgentResult::reply("Erreur lors de la suppression de la note. Reessaie.");
            }
        }

        // Validate note length
        if (mb_strlen($noteArg) > 500) {
            return AgentResult::reply(
                "Note trop longue (" . mb_strlen($noteArg) . " car.). Maximum 500 caracteres."
            );
        }

        try {
            $conditions = $workflow->conditions ?? [];
            $conditions['note'] = $noteArg;
            $workflow->update(['conditions' => $conditions]);
            $this->log($context, "Workflow note updated: {$workflow->name}");

            return AgentResult::reply(
                "Note enregistree pour \"{$workflow->name}\".\n\n"
                . $noteArg . "\n\n"
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to update note", ['error' => $e->getMessage(), 'workflow' => $workflow->name]);
            return AgentResult::reply("Erreur lors de l'enregistrement de la note. Reessaie.");
        }
    }

    /**
     * Generate an AI-powered natural language summary of a workflow.
     * Usage: /workflow summary [nom]
     */
    private function commandSummary(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow summary [nom]\n\n"
                . "Genere une explication en langage naturel de ce que fait un workflow.\n"
                . "Exemple: /workflow summary morning-brief"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $steps = $workflow->steps ?? [];

        if (empty($steps)) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape a resumer.");
        }

        $stepsText = '';
        foreach ($steps as $i => $step) {
            $agent     = !empty($step['agent']) ? " [agent: {$step['agent']}]" : '';
            $condition = (!empty($step['condition']) && $step['condition'] !== 'always')
                ? " [condition: {$step['condition']}]" : '';
            $stepsText .= ($i + 1) . ". " . ($step['message'] ?? '') . $agent . $condition . "\n";
        }

        $model = $this->resolveModel($context);

        try {
            $summary = $this->claude->chat(
                "Workflow: \"{$workflow->name}\"\nNombre d'etapes: " . count($steps) . "\nEtapes:\n{$stepsText}",
                $model,
                "Tu es un assistant qui explique des workflows multi-agents en langage naturel, en francais.\n"
                . "Genere une description concise (3-5 phrases max) qui explique:\n"
                . "1. Le but general de ce workflow\n"
                . "2. Les principales etapes en termes simples (pas techniques)\n"
                . "3. Dans quel contexte ou moment l'utiliser\n\n"
                . "Sois clair et accessible. Reponds UNIQUEMENT avec le texte de la description,\n"
                . "sans titre, sans liste, sans markdown."
            );

            $stepCount = count($steps);
            $status    = $workflow->is_active ? 'Actif' : 'Inactif';
            $lastRun   = $workflow->last_run_at ? $workflow->last_run_at->format('d/m/Y H:i') : 'jamais';

            return AgentResult::reply(
                "Workflow: {$workflow->name}\n"
                . str_repeat('─', 26) . "\n\n"
                . trim($summary) . "\n\n"
                . str_repeat('─', 26) . "\n"
                . "{$stepCount} etape" . ($stepCount > 1 ? 's' : '') . " · {$status} · {$workflow->run_count} exec.\n"
                . "Dernier: {$lastRun}\n\n"
                . "/workflow trigger {$workflow->name}  — lancer\n"
                . "/workflow show {$workflow->name}     — details"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: summary generation failed", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply("Erreur lors de la generation du resume. Reessaie.");
        }
    }

    /**
     * Configure the agent, condition, or on_error of an existing step.
     * Usage: /workflow step-config [nom] [N] agent=[agent]
     *        /workflow step-config [nom] [N] condition=always|success|contains:mot
     *        /workflow step-config [nom] [N] on_error=stop|continue
     *        /workflow step-config [nom] [N]           — afficher la config actuelle
     */
    private function commandStepConfig(AgentContext $context, string $name, string $args): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow step-config [nom] [N] [param]=[valeur]\n\n"
                . "Modifie l'agent, la condition ou la gestion d'erreur d'une etape.\n\n"
                . "Exemples:\n"
                . "  /workflow step-config morning 2 agent=todo\n"
                . "  /workflow step-config morning 1 condition=success\n"
                . "  /workflow step-config morning 3 on_error=continue\n\n"
                . "Agents: chat, dev, todo, reminder, finance, habit, pomodoro,\n"
                . "        content_summarizer, code_review, web_search, document, analysis\n"
                . "Conditions: always, success, contains:mot, not_contains:mot\n"
                . "on_error: stop (defaut), continue"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);
        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $parts      = preg_split('/\s+/', trim($args), 2);
        $stepNumber = (int) ($parts[0] ?? 0);
        $configArg  = trim($parts[1] ?? '');
        $steps      = $workflow->steps ?? [];
        $stepCount  = count($steps);

        if ($stepNumber < 1 || $stepNumber > $stepCount) {
            return AgentResult::reply(
                "Numero d'etape invalide: {$stepNumber}. Ce workflow a {$stepCount} etape"
                . ($stepCount > 1 ? 's' : '') . " (1 a {$stepCount}).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes."
            );
        }

        $stepIndex = $stepNumber - 1;

        // No config arg → show current config
        if (empty($configArg) || !str_contains($configArg, '=')) {
            $step = $steps[$stepIndex];
            return AgentResult::reply(
                "Config etape {$stepNumber} — \"{$workflow->name}\":\n\n"
                . "  message   : " . mb_substr($step['message'] ?? '(vide)', 0, 80) . "\n"
                . "  agent     : " . ($step['agent'] ?? 'auto') . "\n"
                . "  condition : " . ($step['condition'] ?? 'always') . "\n"
                . "  on_error  : " . ($step['on_error'] ?? 'stop') . "\n\n"
                . "Pour modifier:\n"
                . "  /workflow step-config {$workflow->name} {$stepNumber} agent=[nom|auto]\n"
                . "  /workflow step-config {$workflow->name} {$stepNumber} condition=always|success|contains:mot\n"
                . "  /workflow step-config {$workflow->name} {$stepNumber} on_error=stop|continue"
            );
        }

        [$param, $value] = explode('=', $configArg, 2);
        $param = mb_strtolower(trim($param));
        $value = trim($value);

        $validAgents = [
            'chat', 'dev', 'todo', 'reminder', 'event_reminder', 'finance',
            'music', 'habit', 'pomodoro', 'content_summarizer', 'code_review',
            'web_search', 'document', 'analysis', 'streamline',
        ];

        switch ($param) {
            case 'agent':
                $agentValue = in_array($value, ['auto', 'null', ''], true) ? null : $value;
                if ($agentValue !== null && !in_array($value, $validAgents, true)) {
                    return AgentResult::reply(
                        "Agent invalide: \"{$value}\".\n"
                        . "Agents disponibles: " . implode(', ', $validAgents) . "\n"
                        . "Utilise 'auto' pour la detection automatique."
                    );
                }
                $oldDisplay = $steps[$stepIndex]['agent'] ?? 'auto';
                $steps[$stepIndex]['agent'] = $agentValue;
                $newDisplay = $agentValue ?? 'auto';
                break;

            case 'condition':
                $isValid = in_array($value, ['always', 'success'], true)
                    || str_starts_with($value, 'contains:')
                    || str_starts_with($value, 'not_contains:');
                if (!$isValid) {
                    return AgentResult::reply(
                        "Condition invalide: \"{$value}\".\n"
                        . "Valeurs: always, success, contains:mot, not_contains:mot"
                    );
                }
                $oldDisplay = $steps[$stepIndex]['condition'] ?? 'always';
                $steps[$stepIndex]['condition'] = $value;
                $newDisplay = $value;
                break;

            case 'on_error':
                if (!in_array($value, ['stop', 'continue'], true)) {
                    return AgentResult::reply(
                        "Valeur invalide: \"{$value}\".\n"
                        . "Valeurs acceptees: stop, continue"
                    );
                }
                $oldDisplay = $steps[$stepIndex]['on_error'] ?? 'stop';
                $steps[$stepIndex]['on_error'] = $value;
                $newDisplay = $value;
                break;

            default:
                return AgentResult::reply(
                    "Parametre inconnu: \"{$param}\".\n"
                    . "Parametres disponibles: agent, condition, on_error"
                );
        }

        if ($oldDisplay === $newDisplay) {
            return AgentResult::reply(
                "L'etape {$stepNumber} a deja {$param}={$oldDisplay}. Aucune modification."
            );
        }

        try {
            $this->backupSteps($workflow);
            $workflow->update(['steps' => $steps]);
            $this->log($context, "Workflow step-config: {$workflow->name} step {$stepNumber} {$param}={$newDisplay}");

            return AgentResult::reply(
                "Etape {$stepNumber} du workflow \"{$workflow->name}\" mise a jour.\n\n"
                . "{$param}: {$oldDisplay} → {$newDisplay}\n\n"
                . "Voir: /workflow show {$workflow->name}\n"
                . "Simuler: /workflow dryrun {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to update step config", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'step'     => $stepNumber,
                'param'    => $param,
            ]);
            return AgentResult::reply('Erreur lors de la modification. Reessaie.');
        }
    }

    /**
     * AI-powered workflow suggestion based on user's needs description.
     * Usage: /workflow suggest [description des besoins]
     */
    private function commandSuggest(AgentContext $context, string $description): AgentResult
    {
        if (empty(trim($description))) {
            return AgentResult::reply(
                "Decris ce que tu veux automatiser et je te suggere un workflow.\n\n"
                . "Usage: /workflow suggest [description]\n\n"
                . "Exemples:\n"
                . "  /workflow suggest bilan matinal avant de commencer ma journee\n"
                . "  /workflow suggest routine du soir pour tracker mes habitudes\n"
                . "  /workflow suggest revue de code et documentation automatique\n\n"
                . "Ou explore les templates pre-faits: /workflow template"
            );
        }

        $model             = $this->resolveModel($context);
        $existingWorkflows = Workflow::forUser($context->from)->pluck('name')->implode(', ');
        $existingContext   = $existingWorkflows
            ? "Workflows deja existants de l'utilisateur: {$existingWorkflows}"
            : "L'utilisateur n'a pas encore de workflow.";

        try {
            $response = $this->claude->chat(
                "Besoin: \"{$description}\"\n\n{$existingContext}",
                $model,
                "Tu es un expert en automatisation et productivite personnelle via WhatsApp.\n"
                . "L'utilisateur decrit un besoin. Suggere un workflow multi-agents optimal.\n\n"
                . "Reponds UNIQUEMENT en JSON valide, sans markdown, sans commentaire:\n"
                . "{\n"
                . "  \"name\": \"nom-kebab-case\",\n"
                . "  \"description\": \"description courte du workflow (max 80 car)\",\n"
                . "  \"steps\": [\n"
                . "    {\n"
                . "      \"message\": \"instruction claire, complete et directement actionnable par l'agent\",\n"
                . "      \"agent\": \"nom_agent ou null pour auto-detection\",\n"
                . "      \"condition\": \"always|success|contains:mot|not_contains:mot\",\n"
                . "      \"on_error\": \"stop|continue\",\n"
                . "      \"rationale\": \"pourquoi cette etape (max 1 phrase)\"\n"
                . "    }\n"
                . "  ],\n"
                . "  \"usage_tip\": \"conseil d'utilisation en 1 phrase\"\n"
                . "}\n\n"
                . "Agents disponibles: chat, dev, todo, reminder, event_reminder, finance, music, habit, pomodoro, content_summarizer, code_review, web_search, document, analysis, streamline\n\n"
                . "Regles:\n"
                . "- 2 a 5 etapes maximum\n"
                . "- Nom en kebab-case, court et descriptif\n"
                . "- Chaque message doit etre une instruction complete que l'agent peut executer seul\n"
                . "- Utilise condition=success pour les etapes qui dependent de la precedente\n"
                . "- Utilise on_error=continue pour les etapes optionnelles\n"
                . "- Evite les doublons avec les workflows existants\n"
                . "- Choisis l'agent le plus adapte (null si non certain)\n"
                . "- Valeurs par defaut: condition=always, on_error=stop"
            );

            $parsed = json_decode($response, true);
            if (!$parsed && preg_match('/\{.*\}/s', $response ?? '', $m)) {
                $parsed = json_decode($m[0], true);
            }

            if (!$parsed || empty($parsed['steps'])) {
                return AgentResult::reply(
                    "Je n'ai pas pu generer de suggestion pour ce besoin.\n\n"
                    . "Essaie une description plus precise, ou explore:\n"
                    . "/workflow template — 8 templates pre-faits\n"
                    . "/workflow create [nom] [etape1] then [etape2]"
                );
            }

            $suggestedName = $parsed['name'] ?? ('workflow-' . now()->format('His'));
            $suggestedDesc = $parsed['description'] ?? '';
            $usageTip      = $parsed['usage_tip'] ?? '';
            $steps         = $parsed['steps'];

            // Normalize steps
            $normalizedSteps = array_map(fn($s) => [
                'message'   => trim($s['message'] ?? ''),
                'agent'     => $s['agent'] ?? null,
                'condition' => $s['condition'] ?? 'always',
                'on_error'  => $s['on_error'] ?? 'stop',
            ], array_filter($steps, fn($s) => !empty(trim($s['message'] ?? ''))));

            if (empty($normalizedSteps)) {
                return AgentResult::reply(
                    "La suggestion generee est invalide (etapes vides).\n\n"
                    . "Utilise /workflow template pour voir les templates disponibles."
                );
            }

            $stepCount = count($normalizedSteps);
            $lines = [
                "Suggestion: {$suggestedName}",
                str_repeat('─', 32),
            ];

            if ($suggestedDesc) {
                $lines[] = $suggestedDesc;
                $lines[] = '';
            }

            $lines[] = "Etapes ({$stepCount}):";
            foreach ($normalizedSteps as $i => $step) {
                $agentLabel = !empty($step['agent']) ? " [{$step['agent']}]" : ' [auto]';
                $msg        = mb_substr($step['message'], 0, 80);
                $lines[]    = ($i + 1) . ".{$agentLabel} {$msg}";
                if (!empty($steps[$i]['rationale'])) {
                    $lines[] = "   → " . mb_substr($steps[$i]['rationale'], 0, 70);
                }
            }

            if ($usageTip) {
                $lines[] = '';
                $lines[] = "Conseil: {$usageTip}";
            }

            $lines[] = '';
            $lines[] = "Repondre 'oui' pour creer ce workflow, ou donne un nom personnalise.";

            $this->setPendingContext($context, 'confirm_workflow', [
                'name'        => $suggestedName,
                'description' => $suggestedDesc,
                'steps'       => $normalizedSteps,
            ], 3);

            $this->log($context, "Workflow suggestion generated: {$suggestedName} ({$stepCount} steps)");

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: suggest failed", ['error' => $e->getMessage()]);
            return AgentResult::reply(
                "Erreur lors de la generation de suggestion.\n\n"
                . "Tu peux utiliser /workflow template pour les templates pre-faits."
            );
        }
    }

    /**
     * Health check: show issues across all workflows (broken, unused, stale, disabled).
     * Usage: /workflow health
     */
    private function commandHealth(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->orderBy('name')->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow cree.\n\n"
                . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        $broken   = [];  // no steps
        $unused   = [];  // never run, created >7 days ago
        $stale    = [];  // active, last run >30 days ago
        $disabled = [];  // inactive
        $healthy  = [];  // everything OK

        $now = now();

        foreach ($workflows as $wf) {
            $stepCount = count($wf->steps ?? []);
            $ageInDays = $wf->created_at->diffInDays($now);

            if ($stepCount === 0) {
                $broken[] = $wf;
                continue;
            }

            if (!$wf->is_active) {
                $disabled[] = $wf;
                continue;
            }

            if ($wf->run_count === 0 && $ageInDays >= 7) {
                $unused[] = $wf;
                continue;
            }

            if ($wf->last_run_at && $wf->last_run_at->diffInDays($now) > 30) {
                $stale[] = $wf;
                continue;
            }

            $healthy[] = $wf;
        }

        $totalIssues = count($broken) + count($unused) + count($stale) + count($disabled);
        $total       = $workflows->count();
        $healthScore = $total > 0 ? (int) round(count($healthy) / $total * 100) : 100;

        $scoreEmoji = $healthScore >= 80 ? 'OK' : ($healthScore >= 50 ? '~' : '!');

        $lines = [
            "Sante des workflows — {$scoreEmoji} {$healthScore}%",
            str_repeat('─', 30),
            "{$total} workflows · " . count($healthy) . " sains · {$totalIssues} probleme" . ($totalIssues > 1 ? 's' : ''),
        ];

        if (!empty($broken)) {
            $lines[] = '';
            $lines[] = "[BROKEN] Sans etapes (" . count($broken) . "):";
            foreach ($broken as $wf) {
                $lines[] = "  · {$wf->name} — supprimable: /workflow delete {$wf->name}";
            }
        }

        if (!empty($unused)) {
            $lines[] = '';
            $lines[] = "[UNUSED] Jamais lances depuis +7j (" . count($unused) . "):";
            foreach ($unused as $wf) {
                $ageDays = $wf->created_at->diffInDays($now);
                $lines[] = "  · {$wf->name} (cree il y a {$ageDays}j) — /workflow trigger {$wf->name}";
            }
        }

        if (!empty($stale)) {
            $lines[] = '';
            $lines[] = "[STALE] Non executes depuis +30j (" . count($stale) . "):";
            foreach ($stale as $wf) {
                $staleDays = $wf->last_run_at->diffInDays($now);
                $lines[] = "  · {$wf->name} (dernier: il y a {$staleDays}j) — /workflow trigger {$wf->name}";
            }
        }

        if (!empty($disabled)) {
            $lines[] = '';
            $lines[] = "[OFF] Desactives (" . count($disabled) . "):";
            foreach ($disabled as $wf) {
                $lines[] = "  · {$wf->name} — /workflow enable {$wf->name}";
            }
        }

        if ($totalIssues === 0) {
            $lines[] = '';
            $lines[] = "Tous tes workflows sont actifs et en bonne sante.";
        }

        $lines[] = '';
        $lines[] = str_repeat('─', 30);
        $lines[] = "/workflow list      — voir tous";
        $lines[] = "/workflow stats     — statistiques";

        $this->log($context, "Workflow health check: score={$healthScore}%, issues={$totalIssues}/{$total}");
        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Quick find and trigger: search for a workflow by name fragment and run it immediately.
     * Usage: /workflow quick [terme]
     */
    private function commandQuick(AgentContext $context, string $term): AgentResult
    {
        if (empty(trim($term))) {
            return AgentResult::reply(
                "Cherche et lance immediatement un workflow.\n\n"
                . "Usage: /workflow quick [terme]\n\n"
                . "Exemples:\n"
                . "  /workflow quick morning  — lance le premier workflow contenant \"morning\"\n"
                . "  /workflow quick daily    — lance le workflow \"daily-check\"\n\n"
                . "Voir tous tes workflows: /workflow list"
            );
        }

        $term  = trim($term);
        $lower = mb_strtolower($term);

        // Search active workflows by name match (exact → prefix → partial)
        $exact   = Workflow::forUser($context->from)->active()->whereRaw('LOWER(name) = ?', [$lower])->first();
        if ($exact) {
            return $this->triggerWorkflow($context, $exact);
        }

        $prefix  = Workflow::forUser($context->from)->active()->whereRaw('LOWER(name) LIKE ?', [$lower . '%'])->get();
        if ($prefix->count() === 1) {
            return $this->triggerWorkflow($context, $prefix->first());
        }

        $partial = Workflow::forUser($context->from)->active()->whereRaw('LOWER(name) LIKE ?', ['%' . $lower . '%'])->orderBy('run_count', 'desc')->get();

        if ($partial->isEmpty()) {
            // Also check inactive ones to give a hint
            $anyMatch = Workflow::forUser($context->from)->whereRaw('LOWER(name) LIKE ?', ['%' . $lower . '%'])->get();
            if ($anyMatch->isNotEmpty()) {
                $names = $anyMatch->pluck('name')->implode(', ');
                return AgentResult::reply(
                    "Aucun workflow actif contenant \"{$term}\".\n\n"
                    . "Workflow(s) correspondant(s) mais desactive(s): {$names}\n"
                    . "Active-en un: /workflow enable [nom]"
                );
            }
            return AgentResult::reply(
                "Aucun workflow actif contenant \"{$term}\".\n\n"
                . "Voir tous tes workflows: /workflow list"
            );
        }

        if ($partial->count() === 1) {
            return $this->triggerWorkflow($context, $partial->first());
        }

        // Multiple matches — pick the most-run one, or let user choose
        $best = $partial->first(); // sorted by run_count desc
        $lines = [
            "Plusieurs workflows actifs correspondent a \"{$term}\":",
            str_repeat('─', 28),
        ];
        foreach ($partial->values() as $i => $wf) {
            $stepCount = count($wf->steps ?? []);
            $runInfo   = $wf->run_count > 0 ? "{$wf->run_count} exec." : 'jamais lance';
            $marker    = $i === 0 ? ' [recommande]' : '';
            $lines[]   = ($i + 1) . ". {$wf->name}{$marker}";
            $lines[]   = "   {$stepCount} etapes · {$runInfo}";
        }
        $lines[] = '';
        $lines[] = "Lance directement: /workflow trigger [nom]";
        $lines[] = "Ex: /workflow trigger {$best->name}";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Run the most recently executed workflow.
     * Usage: /workflow last
     */
    private function commandLast(AgentContext $context): AgentResult
    {
        $workflow = Workflow::forUser($context->from)
            ->whereNotNull('last_run_at')
            ->orderByDesc('last_run_at')
            ->first();

        if (!$workflow) {
            return AgentResult::reply(
                "Aucun workflow n'a encore ete execute.\n\n"
                . "Lance un workflow avec: /workflow trigger [nom]\n"
                . "Voir tes workflows: /workflow list"
            );
        }

        if (!$workflow->is_active) {
            $lastRun = $workflow->last_run_at->diffForHumans();
            return AgentResult::reply(
                "Le dernier workflow execute (\"{$workflow->name}\") est maintenant desactive.\n\n"
                . "Dernier: {$lastRun}\n\n"
                . "Active-le: /workflow enable {$workflow->name}\n"
                . "Ou lance un autre: /workflow trigger [nom]"
            );
        }

        $lastRun = $workflow->last_run_at->diffForHumans();
        $this->sendText($context->from, "Relancement de \"{$workflow->name}\" (dernier: {$lastRun})...");
        $this->log($context, "workflow last: relaunching {$workflow->name}");

        return $this->triggerWorkflow($context, $workflow);
    }

    /**
     * Copy a step from one workflow to another.
     * Usage: /workflow copy-step [source] [N] [destination]
     * Example: /workflow copy-step morning-brief 2 evening-check
     */
    private function commandCopyStep(AgentContext $context, string $source, string $args): AgentResult
    {
        if (empty($source)) {
            return AgentResult::reply(
                "Usage: /workflow copy-step [source] [N] [destination]\n\n"
                . "Copie l'etape N d'un workflow vers la fin d'un autre.\n\n"
                . "Exemple:\n"
                . "  /workflow copy-step morning-brief 2 evening-check\n"
                . "  /workflow copy-step daily 1 weekly\n\n"
                . "Voir les etapes: /workflow show [nom]"
            );
        }

        $sourceWf = $this->findWorkflow($context->from, $source);
        if (!$sourceWf) {
            return AgentResult::reply(
                "Workflow source \"{$source}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $parts      = preg_split('/\s+/', trim($args), 2);
        $stepNumber = (int) ($parts[0] ?? 0);
        $destName   = trim($parts[1] ?? '');

        $sourceSteps = $sourceWf->steps ?? [];
        $sourceCount = count($sourceSteps);

        if ($stepNumber < 1 || $stepNumber > $sourceCount) {
            return AgentResult::reply(
                "Numero d'etape invalide: {$stepNumber}. \"{$sourceWf->name}\" a {$sourceCount} etape"
                . ($sourceCount > 1 ? 's' : '') . " (1 a {$sourceCount}).\n"
                . "Utilise /workflow show {$sourceWf->name} pour voir les etapes."
            );
        }

        if (empty($destName)) {
            return AgentResult::reply(
                "Precise le workflow de destination:\n"
                . "/workflow copy-step {$sourceWf->name} {$stepNumber} [destination]"
            );
        }

        $destWf = $this->findWorkflow($context->from, $destName);
        if (!$destWf) {
            return AgentResult::reply(
                "Workflow destination \"{$destName}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        if ($destWf->id === $sourceWf->id) {
            return AgentResult::reply(
                "Source et destination identiques.\n"
                . "Pour copier l'etape au sein du meme workflow, utilise:\n"
                . "/workflow insert {$sourceWf->name} [nouvelle_position] [message]"
            );
        }

        $destSteps = $destWf->steps ?? [];

        if (count($destSteps) >= 10) {
            return AgentResult::reply(
                "Le workflow destination \"{$destWf->name}\" a deja 10 etapes (maximum).\n"
                . "Supprime une etape d'abord: /workflow remove-step {$destWf->name} [N]"
            );
        }

        $stepToCopy = $sourceSteps[$stepNumber - 1];
        $destSteps[] = $stepToCopy;
        $newIndex    = count($destSteps);

        try {
            $this->backupSteps($destWf);
            $destWf->update(['steps' => $destSteps]);
            $this->log($context, "Step copied: {$sourceWf->name}#{$stepNumber} → {$destWf->name} (pos {$newIndex})");

            $msg = mb_substr($stepToCopy['message'] ?? '', 0, 70);
            return AgentResult::reply(
                "Etape {$stepNumber} de \"{$sourceWf->name}\" copiee vers \"{$destWf->name}\" (position {$newIndex}).\n\n"
                . "Message: {$msg}\n\n"
                . "Voir: /workflow show {$destWf->name}\n"
                . "Simuler: /workflow dryrun {$destWf->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to copy step", [
                'error'  => $e->getMessage(),
                'source' => $sourceWf->name,
                'dest'   => $destWf->name,
                'step'   => $stepNumber,
            ]);
            return AgentResult::reply("Erreur lors de la copie de l'etape. Reessaie.");
        }
    }

    /**
     * Compare two workflows side by side.
     * Usage: /workflow diff [nom1] [nom2]
     */
    private function commandDiff(AgentContext $context, string $name1, string $name2): AgentResult
    {
        if (empty($name1) || empty($name2)) {
            return AgentResult::reply(
                "Usage: /workflow diff [nom1] [nom2]\n\n"
                . "Compare deux workflows cote a cote.\n\n"
                . "Exemple: /workflow diff morning-brief evening-check"
            );
        }

        $wf1 = $this->findWorkflow($context->from, $name1);
        if (!$wf1) {
            return AgentResult::reply(
                "Workflow \"{$name1}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $wf2 = $this->findWorkflow($context->from, $name2);
        if (!$wf2) {
            return AgentResult::reply(
                "Workflow \"{$name2}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        if ($wf1->id === $wf2->id) {
            return AgentResult::reply("Les deux noms designent le meme workflow (\"{$wf1->name}\").");
        }

        $steps1 = $wf1->steps ?? [];
        $steps2 = $wf2->steps ?? [];
        $maxSteps = max(count($steps1), count($steps2));

        $lines = [
            "Comparaison de workflows",
            str_repeat('─', 30),
            '',
            "A: {$wf1->name} ({$this->statusLabel($wf1)} · " . count($steps1) . " etapes · {$wf1->run_count} exec.)",
            "B: {$wf2->name} ({$this->statusLabel($wf2)} · " . count($steps2) . " etapes · {$wf2->run_count} exec.)",
            '',
        ];

        // Description diff
        $desc1 = $wf1->description ?? '(aucune)';
        $desc2 = $wf2->description ?? '(aucune)';
        if ($desc1 !== $desc2) {
            $lines[] = "Description:";
            $lines[] = "  A: {$desc1}";
            $lines[] = "  B: {$desc2}";
            $lines[] = '';
        }

        // Steps comparison
        $lines[] = "Etapes:";
        $identical = 0;
        $different = 0;

        for ($i = 0; $i < $maxSteps; $i++) {
            $s1 = $steps1[$i] ?? null;
            $s2 = $steps2[$i] ?? null;
            $num = $i + 1;

            if ($s1 && $s2) {
                $msg1 = mb_substr($s1['message'] ?? '', 0, 60);
                $msg2 = mb_substr($s2['message'] ?? '', 0, 60);
                $agent1 = $s1['agent'] ?? 'auto';
                $agent2 = $s2['agent'] ?? 'auto';

                if ($msg1 === $msg2 && $agent1 === $agent2) {
                    $lines[] = "  {$num}. = [{$agent1}] {$msg1}";
                    $identical++;
                } else {
                    $lines[] = "  {$num}. A [{$agent1}] {$msg1}";
                    $lines[] = "     B [{$agent2}] {$msg2}";
                    $different++;
                }
            } elseif ($s1) {
                $msg1 = mb_substr($s1['message'] ?? '', 0, 60);
                $agent1 = $s1['agent'] ?? 'auto';
                $lines[] = "  {$num}. A [{$agent1}] {$msg1}";
                $lines[] = "     B (absente)";
                $different++;
            } else {
                $msg2 = mb_substr($s2['message'] ?? '', 0, 60);
                $agent2 = $s2['agent'] ?? 'auto';
                $lines[] = "  {$num}. A (absente)";
                $lines[] = "     B [{$agent2}] {$msg2}";
                $different++;
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('─', 30);
        $lines[] = "Resume: {$identical} identique" . ($identical > 1 ? 's' : '') . ", {$different} difference" . ($different > 1 ? 's' : '');

        // Tags comparison
        $tags1 = $wf1->conditions['tags'] ?? [];
        $tags2 = $wf2->conditions['tags'] ?? [];
        if ($tags1 !== $tags2) {
            $t1 = !empty($tags1) ? implode(', ', array_map(fn($t) => "#{$t}", $tags1)) : '(aucun)';
            $t2 = !empty($tags2) ? implode(', ', array_map(fn($t) => "#{$t}", $tags2)) : '(aucun)';
            $lines[] = "Tags A: {$t1}";
            $lines[] = "Tags B: {$t2}";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Helper for NLU diff routing: split "name1 name2" from a single name field.
     */
    private function handleDiffFromNLU(AgentContext $context, string $names): AgentResult
    {
        $parts = preg_split('/[\s,]+/', trim($names), 2);
        $name1 = trim($parts[0] ?? '');
        $name2 = trim($parts[1] ?? '');
        return $this->commandDiff($context, $name1, $name2);
    }

    /**
     * Show the most-used workflows (top 5 by run_count).
     * Usage: /workflow favorites
     */
    private function commandFavorites(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)
            ->where('run_count', '>', 0)
            ->orderByDesc('run_count')
            ->take(5)
            ->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow n'a encore ete execute.\n\n"
                . "Lance un workflow avec: /workflow trigger [nom]\n"
                . "Ou explore les templates: /workflow template"
            );
        }

        $total = Workflow::forUser($context->from)->sum('run_count');
        $lines = [
            "Top workflows (par utilisation)",
            str_repeat('─', 30),
        ];

        foreach ($workflows->values() as $i => $wf) {
            $status    = $wf->is_active ? 'ON' : 'OFF';
            $stepCount = count($wf->steps ?? []);
            $pct       = $total > 0 ? round($wf->run_count / $total * 100) : 0;
            $lastRun   = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : 'jamais';
            $bar       = str_repeat('█', (int) round($pct / 10)) . str_repeat('░', 10 - (int) round($pct / 10));
            $pinBadge  = $this->isPinned($wf) ? ' [PIN]' : '';

            $lines[] = ($i + 1) . ". [{$status}] {$wf->name}{$pinBadge}";
            $lines[] = "   {$bar} {$wf->run_count} exec. ({$pct}%) · {$stepCount} etapes · {$lastRun}";
        }

        $lines[] = '';
        $lines[] = str_repeat('─', 30);
        $lines[] = "Total executions: {$total}";
        $lines[] = '';
        $lines[] = "/workflow trigger [nom]  — lancer";
        $lines[] = "/workflow stats          — toutes les stats";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Short status label for a workflow.
     */
    private function statusLabel(Workflow $wf): string
    {
        return $wf->is_active ? 'ON' : 'OFF';
    }

    /**
     * Check if a workflow is pinned.
     */
    private function isPinned(Workflow $workflow): bool
    {
        return (bool) ($workflow->conditions['pinned'] ?? false);
    }

    /**
     * Schedule a workflow to run automatically via a reminder.
     * Usage: /workflow schedule [nom] [frequence]
     * Example: /workflow schedule morning-brief chaque jour a 8h
     */
    private function commandSchedule(AgentContext $context, string $name, string $schedule): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Planifie l'execution automatique d'un workflow.\n\n"
                . "Usage: /workflow schedule [nom] [frequence]\n\n"
                . "Exemples:\n"
                . "  /workflow schedule morning-brief chaque jour a 8h\n"
                . "  /workflow schedule weekly-review tous les lundis a 9h\n"
                . "  /workflow schedule finance-check chaque vendredi a 18h\n\n"
                . "La planification cree un rappel recurrent qui lance le workflow automatiquement."
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        if (!$workflow->is_active) {
            return AgentResult::reply(
                "Le workflow \"{$workflow->name}\" est desactive.\n"
                . "Active-le d'abord: /workflow enable {$workflow->name}"
            );
        }

        if (empty($workflow->steps)) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape.");
        }

        // Check if user wants to cancel existing schedule
        $lowerSchedule = mb_strtolower(trim($schedule));
        if (in_array($lowerSchedule, ['off', 'stop', 'cancel', 'annuler', 'supprimer', 'desactiver'])) {
            $existingSchedule = $workflow->conditions['schedule'] ?? null;
            if (!$existingSchedule) {
                return AgentResult::reply(
                    "Le workflow \"{$workflow->name}\" n'a pas de planification active."
                );
            }
            $reminderId = $existingSchedule['reminder_id'] ?? null;
            if ($reminderId) {
                Reminder::where('id', $reminderId)->delete();
            }
            $conditions = $workflow->conditions ?? [];
            unset($conditions['schedule']);
            $workflow->update(['conditions' => $conditions]);
            $this->log($context, "Workflow schedule cancelled: {$workflow->name}");
            return AgentResult::reply(
                "Planification annulee pour \"{$workflow->name}\".\n"
                . "Le workflow ne sera plus lance automatiquement."
            );
        }

        // Show current schedule if exists and no new schedule provided
        $existingSchedule = $workflow->conditions['schedule'] ?? null;

        if (empty(trim($schedule))) {
            if ($existingSchedule) {
                $desc = $existingSchedule['description'] ?? '(inconnue)';
                return AgentResult::reply(
                    "Planification actuelle de \"{$workflow->name}\":\n"
                    . "Frequence: {$desc}\n\n"
                    . "Modifier: /workflow schedule {$workflow->name} [nouvelle frequence]\n"
                    . "Annuler: /workflow schedule {$workflow->name} off"
                );
            }
            return AgentResult::reply(
                "Precise la frequence de planification:\n"
                . "/workflow schedule {$workflow->name} [frequence]\n\n"
                . "Exemples de frequences:\n"
                . "  chaque jour a 8h\n"
                . "  tous les lundis a 9h\n"
                . "  chaque vendredi a 18h\n"
                . "  tous les jours a 7h30"
            );
        }

        // If already scheduled, cancel old reminder first
        if ($existingSchedule) {
            $oldReminderId = $existingSchedule['reminder_id'] ?? null;
            if ($oldReminderId) {
                Reminder::where('id', $oldReminderId)->delete();
            }
        }

        // Parse the schedule via LLM to get a cron-like time
        $model = $this->resolveModel($context);

        try {
            $parsed = $this->claude->chat(
                "Frequence demandee: \"{$schedule}\"\nDate/heure actuelle: " . now()->format('Y-m-d H:i (l)'),
                $model,
                "Tu convertis une frequence en langage naturel en parametres de planification.\n"
                . "Reponds UNIQUEMENT en JSON valide, sans markdown:\n"
                . "{\n"
                . "  \"recurrence_rule\": \"RRULE RFC 5545 (ex: FREQ=DAILY;BYHOUR=8;BYMINUTE=0)\",\n"
                . "  \"first_run\": \"YYYY-MM-DD HH:MM:SS (prochaine occurrence)\",\n"
                . "  \"description\": \"description courte en francais de la planification\"\n"
                . "}\n\n"
                . "Regles:\n"
                . "- FREQ: DAILY, WEEKLY, MONTHLY\n"
                . "- Pour WEEKLY: ajoute BYDAY=MO,TU,WE,TH,FR,SA,SU selon le jour demande\n"
                . "- first_run doit etre dans le futur (si l'heure est passee aujourd'hui, planifie pour demain ou la prochaine occurrence)\n"
                . "- Heure par defaut: 08:00 si non precise\n"
                . "- Fuseau: Europe/Paris"
            );

            $scheduleData = json_decode($parsed, true);
            if (!$scheduleData && preg_match('/\{(?:[^{}]|\{[^{}]*\})*\}/s', $parsed ?? '', $m)) {
                $scheduleData = json_decode($m[0], true);
            }

            if (!$scheduleData || empty($scheduleData['recurrence_rule']) || empty($scheduleData['first_run'])) {
                return AgentResult::reply(
                    "Je n'ai pas compris la frequence \"{$schedule}\".\n\n"
                    . "Exemples:\n"
                    . "  chaque jour a 8h\n"
                    . "  tous les lundis a 9h\n"
                    . "  chaque vendredi a 18h"
                );
            }

            $stepCount = count($workflow->steps ?? []);
            $desc = $scheduleData['description'] ?? $schedule;
            $firstRun = $scheduleData['first_run'];

            $this->setPendingContext($context, 'confirm_schedule', [
                'workflow_id'     => $workflow->id,
                'schedule'        => $schedule,
                'recurrence_rule' => $scheduleData['recurrence_rule'],
                'first_run'       => $firstRun,
                'description'     => $desc,
            ], 3);

            return AgentResult::reply(
                "Planification du workflow \"{$workflow->name}\" ({$stepCount} etapes):\n\n"
                . "Frequence : {$desc}\n"
                . "Premiere exec. : {$firstRun}\n"
                . "Recurrence : {$scheduleData['recurrence_rule']}\n\n"
                . "Un rappel recurrent sera cree pour lancer ce workflow automatiquement.\n\n"
                . "Confirmer? (oui/non)"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: schedule parsing failed", [
                'error'    => $e->getMessage(),
                'schedule' => $schedule,
            ]);
            return AgentResult::reply(
                "Erreur lors de l'analyse de la frequence.\n\n"
                . "Essaie un format plus simple:\n"
                . "  /workflow schedule {$workflow->name} chaque jour a 8h"
            );
        }
    }

    /**
     * Create a recurring reminder that triggers the workflow.
     */
    private function createScheduleReminder(AgentContext $context, Workflow $workflow, string $schedule): AgentResult
    {
        $pendingData = $context->session?->pending_context['data'] ?? [];
        $recurrenceRule = $pendingData['recurrence_rule'] ?? '';
        $firstRun       = $pendingData['first_run'] ?? now()->addDay()->format('Y-m-d 08:00:00');
        $desc           = $pendingData['description'] ?? $schedule;

        try {
            $reminder = Reminder::create([
                'agent_id'        => $context->agent?->id,
                'requester_phone' => $context->from,
                'requester_name'  => $context->senderName ?? 'Utilisateur',
                'message'         => "/workflow trigger {$workflow->name}",
                'channel'         => 'whatsapp',
                'scheduled_at'    => $firstRun,
                'recurrence_rule' => $recurrenceRule,
                'status'          => 'pending',
            ]);

            // Store schedule info in workflow conditions
            $conditions = $workflow->conditions ?? [];
            $conditions['schedule'] = [
                'reminder_id'     => $reminder->id,
                'recurrence_rule' => $recurrenceRule,
                'description'     => $desc,
                'created_at'      => now()->toISOString(),
            ];
            $workflow->update(['conditions' => $conditions]);

            $this->log($context, "Workflow scheduled: {$workflow->name} — {$desc}", [
                'reminder_id' => $reminder->id,
            ]);

            return AgentResult::reply(
                "Workflow \"{$workflow->name}\" planifie!\n\n"
                . "Frequence : {$desc}\n"
                . "Prochaine exec. : {$firstRun}\n\n"
                . "Le workflow sera lance automatiquement.\n"
                . "Pour annuler: /workflow schedule {$workflow->name} off"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to create schedule reminder", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply("Erreur lors de la planification. Reessaie.");
        }
    }

    /**
     * Merge two workflows into one, combining all steps sequentially.
     * Usage: /workflow merge [nom1] [nom2]
     * Optional 3rd arg or NLU reply: new name for the merged workflow.
     */
    private function commandMerge(AgentContext $context, string $name1, string $name2): AgentResult
    {
        if (empty($name1) || empty($name2)) {
            return AgentResult::reply(
                "Fusionne deux workflows en un seul.\n\n"
                . "Usage: /workflow merge [nom1] [nom2]\n\n"
                . "Les etapes du 2e workflow sont ajoutees a la suite du 1er.\n"
                . "Un nouveau workflow est cree (les originaux ne sont pas modifies).\n\n"
                . "Exemple: /workflow merge morning-brief evening-check"
            );
        }

        $wf1 = $this->findWorkflow($context->from, $name1);
        if (!$wf1) {
            return AgentResult::reply(
                "Workflow \"{$name1}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $wf2 = $this->findWorkflow($context->from, $name2);
        if (!$wf2) {
            return AgentResult::reply(
                "Workflow \"{$name2}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        if ($wf1->id === $wf2->id) {
            return AgentResult::reply("Les deux noms designent le meme workflow (\"{$wf1->name}\"). Impossible de fusionner.");
        }

        $steps1 = $wf1->steps ?? [];
        $steps2 = $wf2->steps ?? [];
        $totalSteps = count($steps1) + count($steps2);

        if ($totalSteps === 0) {
            return AgentResult::reply("Les deux workflows n'ont aucune etape. Rien a fusionner.");
        }

        if ($totalSteps > 10) {
            return AgentResult::reply(
                "La fusion donnerait {$totalSteps} etapes (maximum 10).\n\n"
                . "\"{$wf1->name}\": " . count($steps1) . " etapes\n"
                . "\"{$wf2->name}\": " . count($steps2) . " etapes\n\n"
                . "Supprime des etapes d'un des workflows d'abord."
            );
        }

        $newName = "{$wf1->name}-{$wf2->name}";
        // Truncate if too long
        if (mb_strlen($newName) > 50) {
            $newName = mb_substr($newName, 0, 50);
        }

        $mergedSteps = array_merge($steps1, $steps2);

        $lines = [
            "Fusion: {$wf1->name} + {$wf2->name}",
            str_repeat('─', 30),
            "Nouveau nom: {$newName}",
            "Etapes ({$totalSteps}):",
            '',
        ];

        foreach ($mergedSteps as $i => $step) {
            $source = $i < count($steps1) ? $wf1->name : $wf2->name;
            $agent  = $step['agent'] ?? 'auto';
            $msg    = mb_substr($step['message'] ?? '', 0, 70);
            $lines[] = "  " . ($i + 1) . ". [{$agent}] {$msg}  ← {$source}";
        }

        $lines[] = '';
        $lines[] = "Les workflows originaux ne seront pas modifies.";
        $lines[] = "Confirmer? (oui/non/un-nom-personnalise)";

        $this->setPendingContext($context, 'confirm_merge', [
            'wf1_id'   => $wf1->id,
            'wf2_id'   => $wf2->id,
            'new_name' => $newName,
            'steps'    => $mergedSteps,
        ], 3);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Execute the actual merge after confirmation.
     */
    private function executeMerge(AgentContext $context, array $data): AgentResult
    {
        $wf1 = Workflow::find($data['wf1_id'] ?? 0);
        $wf2 = Workflow::find($data['wf2_id'] ?? 0);

        if (!$wf1 || !$wf2) {
            return AgentResult::reply('Un des workflows source a ete supprime. Fusion annulee.');
        }

        $newName = $data['new_name'] ?? "{$wf1->name}-{$wf2->name}";

        // Ensure unique name
        $existing = Workflow::forUser($context->from)->where('name', $newName)->first();
        if ($existing) {
            $newName = $newName . '-' . now()->format('His');
        }

        try {
            $workflow = Workflow::create([
                'user_phone'  => $context->from,
                'agent_id'    => $context->agent?->id,
                'name'        => $newName,
                'description' => "Fusion de {$wf1->name} + {$wf2->name}",
                'steps'       => $data['steps'] ?? [],
                'triggers'    => null,
                'conditions'  => null,
                'is_active'   => true,
            ]);

            $stepCount = count($data['steps'] ?? []);
            $this->log($context, "Workflows merged: {$wf1->name} + {$wf2->name} → {$newName}", [
                'id' => $workflow->id,
                'steps' => $stepCount,
            ]);

            return AgentResult::reply(
                "Workflows fusionnes! Nouveau workflow: \"{$newName}\" ({$stepCount} etapes).\n\n"
                . "Les originaux ({$wf1->name}, {$wf2->name}) sont inchanges.\n\n"
                . "Lance-le: /workflow trigger {$newName}\n"
                . "Details: /workflow show {$newName}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: merge failed", [
                'error' => $e->getMessage(),
                'wf1'   => $wf1->name,
                'wf2'   => $wf2->name,
            ]);
            return AgentResult::reply('Erreur lors de la fusion. Reessaie.');
        }
    }

    /**
     * Helper for NLU merge routing: split "name1 name2" from a single name field.
     */
    private function handleMergeFromNLU(AgentContext $context, string $names, string $newName): AgentResult
    {
        $parts = preg_split('/[\s,]+/', trim($names), 2);
        $name1 = trim($parts[0] ?? '');
        $name2 = trim($parts[1] ?? '');
        return $this->commandMerge($context, $name1, $name2);
    }

    /**
     * AI-powered workflow optimization: analyze a workflow and suggest improvements.
     * Usage: /workflow optimize [nom]
     */
    private function commandOptimize(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Analyse un workflow et suggere des ameliorations.\n\n"
                . "Usage: /workflow optimize [nom]\n\n"
                . "Exemples:\n"
                . "  /workflow optimize morning-brief\n"
                . "  /workflow optimize daily-check\n\n"
                . "L'IA analysera l'ordre des etapes, les agents utilises,\n"
                . "les conditions et proposera des optimisations."
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $steps = $workflow->steps ?? [];

        if (empty($steps)) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape a optimiser.");
        }

        if (count($steps) < 2) {
            return AgentResult::reply(
                "Le workflow \"{$workflow->name}\" n'a qu'une seule etape.\n"
                . "L'optimisation est plus pertinente avec au moins 2 etapes."
            );
        }

        $stepsText = '';
        foreach ($steps as $i => $step) {
            $agent     = !empty($step['agent']) ? $step['agent'] : 'auto';
            $condition = $step['condition'] ?? 'always';
            $onError   = $step['on_error'] ?? 'stop';
            $stepsText .= ($i + 1) . ". [{$agent}] " . ($step['message'] ?? '') . " (condition={$condition}, on_error={$onError})\n";
        }

        $model = $this->resolveModel($context);

        try {
            $response = $this->claude->chat(
                "Workflow: \"{$workflow->name}\"\n"
                . "Description: " . ($workflow->description ?? '(aucune)') . "\n"
                . "Executions: {$workflow->run_count}\n"
                . "Etapes ({" . count($steps) . "}):\n{$stepsText}",
                $model,
                "Tu es un expert en automatisation et workflows multi-agents.\n"
                . "Analyse ce workflow et suggere des ameliorations concretes.\n\n"
                . "Reponds UNIQUEMENT en JSON valide, sans markdown:\n"
                . "{\n"
                . "  \"score\": 1-10,\n"
                . "  \"verdict\": \"bon|moyen|a_ameliorer\",\n"
                . "  \"suggestions\": [\n"
                . "    {\"type\": \"order|agent|condition|error_handling|missing_step|redundant_step|message_clarity\", \"step\": N_ou_null, \"description\": \"suggestion concise en francais\"}\n"
                . "  ],\n"
                . "  \"optimized_steps\": [\n"
                . "    {\"message\": \"...\", \"agent\": \"...\", \"condition\": \"...\", \"on_error\": \"...\"}\n"
                . "  ]\n"
                . "}\n\n"
                . "Agents disponibles: chat, dev, todo, reminder, event_reminder, finance, music, habit, pomodoro, content_summarizer, code_review, web_search, document, analysis, streamline\n\n"
                . "Criteres d'analyse:\n"
                . "- Ordre des etapes logique?\n"
                . "- Agent correct pour chaque etape?\n"
                . "- Conditions appropriees (success pour les etapes dependantes)?\n"
                . "- Gestion d'erreur (continue pour les optionnelles, stop pour les critiques)?\n"
                . "- Messages clairs et actionnables?\n"
                . "- Etapes manquantes ou redondantes?\n"
                . "- Peut-on fusionner ou simplifier?\n\n"
                . "Si le workflow est deja optimal, score=9-10, verdict=bon, suggestions vides."
            );

            $parsed = json_decode($response, true);
            if (!$parsed && preg_match('/\{.*\}/s', $response ?? '', $m)) {
                $parsed = json_decode($m[0], true);
            }

            if (!$parsed || !isset($parsed['score'])) {
                return AgentResult::reply(
                    "Erreur d'analyse. Reessaie ou consulte:\n"
                    . "/workflow show {$workflow->name}\n"
                    . "/workflow dryrun {$workflow->name}"
                );
            }

            $score      = (int) ($parsed['score'] ?? 5);
            $verdict    = $parsed['verdict'] ?? 'moyen';
            $suggestions = $parsed['suggestions'] ?? [];

            $verdictLabel = match ($verdict) {
                'bon'          => 'Bon',
                'a_ameliorer'  => 'A ameliorer',
                default        => 'Moyen',
            };

            $bar = str_repeat('█', $score) . str_repeat('░', 10 - $score);

            $lines = [
                "Analyse: {$workflow->name}",
                str_repeat('─', 30),
                "Score: {$bar} {$score}/10 — {$verdictLabel}",
                '',
            ];

            if (!empty($suggestions)) {
                $lines[] = "Suggestions (" . count($suggestions) . "):";
                foreach ($suggestions as $i => $s) {
                    $stepRef = isset($s['step']) && $s['step'] ? " (etape {$s['step']})" : '';
                    $lines[] = "  " . ($i + 1) . ". " . ($s['description'] ?? '') . $stepRef;
                }
            } else {
                $lines[] = "Aucune suggestion — ce workflow est deja bien optimise!";
            }

            // Offer optimized version if different
            $optimizedSteps = $parsed['optimized_steps'] ?? [];
            if (!empty($optimizedSteps) && !empty($suggestions)) {
                $normalizedOptimized = array_map(fn($s) => [
                    'message'   => trim($s['message'] ?? ''),
                    'agent'     => $s['agent'] ?? null,
                    'condition' => $s['condition'] ?? 'always',
                    'on_error'  => $s['on_error'] ?? 'stop',
                ], array_filter($optimizedSteps, fn($s) => !empty(trim($s['message'] ?? ''))));

                if (!empty($normalizedOptimized)) {
                    $lines[] = '';
                    $lines[] = "Version optimisee proposee:";
                    foreach ($normalizedOptimized as $i => $step) {
                        $agentLabel = !empty($step['agent']) ? "[{$step['agent']}]" : '[auto]';
                        $msg = mb_substr($step['message'], 0, 70);
                        $lines[] = "  " . ($i + 1) . ". {$agentLabel} {$msg}";
                    }

                    $this->setPendingContext($context, 'confirm_optimize', [
                        'workflow_id' => $workflow->id,
                        'steps'       => $normalizedOptimized,
                    ], 3);

                    $lines[] = '';
                    $lines[] = "Appliquer ces optimisations? (oui/non)";
                }
            }

            $lines[] = '';
            $lines[] = str_repeat('─', 30);
            $lines[] = "/workflow show {$workflow->name}     — details actuels";
            $lines[] = "/workflow dryrun {$workflow->name}   — simuler";

            $this->log($context, "Workflow optimized: {$workflow->name} score={$score}/10", [
                'suggestions' => count($suggestions),
            ]);

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: optimize failed", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply(
                "Erreur lors de l'analyse. Reessaie.\n\n"
                . "/workflow show {$workflow->name}   — voir les details"
            );
        }
    }

    /**
     * Swap two steps within a workflow.
     * Usage: /workflow swap [nom] [N1] [N2]
     * Example: /workflow swap morning-brief 2 3
     */
    private function commandSwapStep(AgentContext $context, string $name, string $args): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Echange la position de deux etapes dans un workflow.\n\n"
                . "Usage: /workflow swap [nom] [N1] [N2]\n\n"
                . "Exemple: /workflow swap morning-brief 2 3\n"
                . "(echange les etapes 2 et 3)\n\n"
                . "Voir les etapes: /workflow show [nom]"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $parts = preg_split('/\s+/', trim($args), 2);
        $pos1  = (int) ($parts[0] ?? 0);
        $pos2  = (int) ($parts[1] ?? 0);

        $steps = $workflow->steps ?? [];
        $count = count($steps);

        if ($count < 2) {
            return AgentResult::reply("Ce workflow n'a qu'une seule etape. Impossible d'echanger.");
        }

        if ($pos1 < 1 || $pos1 > $count) {
            return AgentResult::reply(
                "Position {$pos1} invalide. Ce workflow a {$count} etapes (1 a {$count}).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes."
            );
        }

        if ($pos2 < 1 || $pos2 > $count) {
            return AgentResult::reply(
                "Position {$pos2} invalide. Ce workflow a {$count} etapes (1 a {$count}).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes."
            );
        }

        if ($pos1 === $pos2) {
            return AgentResult::reply("Les deux positions sont identiques ({$pos1}). Rien a echanger.");
        }

        $idx1 = $pos1 - 1;
        $idx2 = $pos2 - 1;

        [$steps[$idx1], $steps[$idx2]] = [$steps[$idx2], $steps[$idx1]];

        try {
            $this->backupSteps($workflow);
            $workflow->update(['steps' => $steps]);

            $msg1 = mb_substr($steps[$idx1]['message'] ?? '', 0, 50);
            $msg2 = mb_substr($steps[$idx2]['message'] ?? '', 0, 50);
            $this->log($context, "Workflow steps swapped: {$workflow->name} {$pos1} <-> {$pos2}");

            return AgentResult::reply(
                "Etapes {$pos1} et {$pos2} echangees dans \"{$workflow->name}\".\n\n"
                . "Etape {$pos1}: {$msg1}\n"
                . "Etape {$pos2}: {$msg2}\n\n"
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to swap steps", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'pos1'     => $pos1,
                'pos2'     => $pos2,
            ]);
            return AgentResult::reply("Erreur lors de l'echange des etapes. Reessaie.");
        }
    }

    /**
     * Test a single step of a workflow by executing only that step.
     * Usage: /workflow test-step [nom] [N]
     */
    private function commandTestStep(AgentContext $context, string $name, string $stepArg): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Teste une seule etape d'un workflow sans lancer le workflow entier.\n\n"
                . "Usage: /workflow test-step [nom] [N]\n\n"
                . "Exemples:\n"
                . "  /workflow test-step morning-brief 2\n"
                . "  /workflow test daily-check 1\n\n"
                . "Utile pour verifier qu'une etape fonctionne avant de lancer tout le workflow."
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $stepNumber = (int) trim(preg_split('/\s+/', $stepArg)[0] ?? '0');
        $steps = $workflow->steps ?? [];
        $stepCount = count($steps);

        if ($stepNumber < 1 || $stepNumber > $stepCount) {
            if ($stepCount === 0) {
                return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape.");
            }
            return AgentResult::reply(
                "Numero d'etape invalide: {$stepNumber}. Ce workflow a {$stepCount} etape"
                . ($stepCount > 1 ? 's' : '') . " (1 a {$stepCount}).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes."
            );
        }

        $step = $steps[$stepNumber - 1];
        $msg = $step['message'] ?? '';

        if (empty(trim($msg))) {
            return AgentResult::reply("L'etape {$stepNumber} est vide. Rien a tester.");
        }

        if (!empty($step['_skip'])) {
            return AgentResult::reply(
                "L'etape {$stepNumber} est desactivee (skip).\n"
                . "Reactive-la: /workflow enable-step {$workflow->name} {$stepNumber}"
            );
        }

        $agent = $step['agent'] ?? 'auto';
        $this->sendText($context->from, "Test etape {$stepNumber}/{$stepCount} de \"{$workflow->name}\" [{$agent}]...");
        $this->log($context, "Test step: {$workflow->name} step {$stepNumber}");

        try {
            $orchestrator = new AgentOrchestrator();
            $executor = new WorkflowExecutor($orchestrator);

            $testWorkflow = new Workflow([
                'name' => 'test-step',
                'steps' => [$step],
            ]);
            $testWorkflow->run_count = 0;

            $executionResult = $executor->execute($testWorkflow, $context);

            return AgentResult::reply(
                "Test etape {$stepNumber} de \"{$workflow->name}\" termine.\n\n"
                . WorkflowExecutor::formatResults($executionResult) . "\n\n"
                . "Pour lancer le workflow complet: /workflow trigger {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: test-step failed", [
                'error' => $e->getMessage(),
                'workflow' => $workflow->name,
                'step' => $stepNumber,
            ]);
            return AgentResult::reply(
                "Erreur lors du test de l'etape {$stepNumber}.\n\n"
                . "Verifie le message: " . mb_substr($msg, 0, 80) . "\n\n"
                . "Modifier: /workflow edit {$workflow->name} {$stepNumber} [nouveau_message]"
            );
        }
    }

    /**
     * Temporarily disable (skip) or re-enable a specific step.
     * Stores a _skip flag in the step data — the WorkflowExecutor skips steps with _skip=true.
     * Usage: /workflow disable-step [nom] [N]
     *        /workflow enable-step [nom] [N]
     */
    private function commandToggleStep(AgentContext $context, string $name, string $stepArg, bool $disable): AgentResult
    {
        $verb = $disable ? 'desactiver' : 'reactiver';
        $cmd = $disable ? 'disable-step' : 'enable-step';

        if (empty($name)) {
            return AgentResult::reply(
                ucfirst($verb) . " temporairement une etape d'un workflow.\n\n"
                . "Usage: /workflow {$cmd} [nom] [N]\n\n"
                . "Exemples:\n"
                . "  /workflow {$cmd} morning-brief 2\n\n"
                . ($disable
                    ? "L'etape sera ignoree lors de l'execution sans etre supprimee.\n"
                      . "Pour la reactiver: /workflow enable-step [nom] [N]"
                    : "L'etape sera a nouveau executee lors de l'execution du workflow.")
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $stepNumber = (int) trim(preg_split('/\s+/', $stepArg)[0] ?? '0');
        $steps = $workflow->steps ?? [];
        $stepCount = count($steps);

        if ($stepNumber < 1 || $stepNumber > $stepCount) {
            if ($stepCount === 0) {
                return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape.");
            }
            return AgentResult::reply(
                "Numero d'etape invalide: {$stepNumber}. Ce workflow a {$stepCount} etape"
                . ($stepCount > 1 ? 's' : '') . " (1 a {$stepCount}).\n"
                . "Utilise /workflow show {$workflow->name} pour voir les etapes."
            );
        }

        $stepIndex = $stepNumber - 1;
        $currentlySkipped = !empty($steps[$stepIndex]['_skip']);

        if ($disable && $currentlySkipped) {
            return AgentResult::reply(
                "L'etape {$stepNumber} est deja desactivee.\n"
                . "Pour la reactiver: /workflow enable-step {$workflow->name} {$stepNumber}"
            );
        }

        if (!$disable && !$currentlySkipped) {
            return AgentResult::reply("L'etape {$stepNumber} est deja active.");
        }

        // Check: don't allow disabling all steps
        if ($disable) {
            $activeSteps = 0;
            foreach ($steps as $i => $s) {
                if (empty($s['_skip']) && $i !== $stepIndex) {
                    $activeSteps++;
                }
            }
            if ($activeSteps === 0) {
                return AgentResult::reply(
                    "Impossible de desactiver la derniere etape active.\n"
                    . "Supprime le workflow entier avec: /workflow delete {$workflow->name}"
                );
            }
        }

        try {
            $this->backupSteps($workflow);
            $steps[$stepIndex]['_skip'] = $disable;
            if (!$disable) {
                unset($steps[$stepIndex]['_skip']);
            }
            $workflow->update(['steps' => $steps]);

            $msg = mb_substr($steps[$stepIndex]['message'] ?? '', 0, 70);
            $action = $disable ? 'desactivee' : 'reactivee';
            $this->log($context, "Workflow step {$action}: {$workflow->name} step {$stepNumber}");

            $skippedCount = collect($steps)->filter(fn($s) => !empty($s['_skip']))->count();
            $skippedInfo = $skippedCount > 0
                ? "\n{$skippedCount} etape" . ($skippedCount > 1 ? 's' : '') . " desactivee" . ($skippedCount > 1 ? 's' : '') . " au total."
                : '';

            return AgentResult::reply(
                "Etape {$stepNumber} {$action} dans \"{$workflow->name}\".\n"
                . "Etape: {$msg}{$skippedInfo}\n\n"
                . ($disable
                    ? "Reactiver: /workflow enable-step {$workflow->name} {$stepNumber}\n"
                    : '')
                . "Voir: /workflow show {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: failed to toggle step", [
                'error' => $e->getMessage(),
                'workflow' => $workflow->name,
                'step' => $stepNumber,
                'disable' => $disable,
            ]);
            return AgentResult::reply("Erreur lors de la modification. Reessaie.");
        }
    }

    /**
     * Backup a workflow's current steps into conditions['_steps_backup'] before modification.
     * Allows undo of the last structural change.
     */
    private function backupSteps(Workflow $workflow): void
    {
        $conditions = $workflow->conditions ?? [];
        $conditions['_steps_backup'] = [
            'steps'      => $workflow->steps ?? [],
            'backed_at'  => now()->toISOString(),
        ];
        $workflow->updateQuietly(['conditions' => $conditions]);
    }

    /**
     * Undo the last step modification on a workflow (restore from backup).
     * Usage: /workflow undo [nom]
     */
    private function commandUndo(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Annule la derniere modification des etapes d'un workflow.\n\n"
                . "Usage: /workflow undo [nom]\n\n"
                . "Exemple: /workflow undo morning-brief\n\n"
                . "Fonctionne apres: edit, add, remove-step, insert, move-step, swap, optimize, copy-step."
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        $backup = $workflow->conditions['_steps_backup'] ?? null;

        if (!$backup || empty($backup['steps'])) {
            return AgentResult::reply(
                "Aucune sauvegarde disponible pour \"{$workflow->name}\".\n"
                . "L'undo est disponible apres une modification des etapes (edit, add, remove, insert, move, swap, optimize)."
            );
        }

        $backedAt = $backup['backed_at'] ?? null;
        $timeAgo  = $backedAt ? \Carbon\Carbon::parse($backedAt)->diffForHumans() : 'inconnu';

        $currentStepCount  = count($workflow->steps ?? []);
        $backupStepCount   = count($backup['steps']);

        try {
            // Save current steps as the new backup (allows re-undo / redo once)
            $conditions = $workflow->conditions ?? [];
            $conditions['_steps_backup'] = [
                'steps'      => $workflow->steps ?? [],
                'backed_at'  => now()->toISOString(),
            ];

            $workflow->update([
                'steps'      => $backup['steps'],
                'conditions' => $conditions,
            ]);

            $this->log($context, "Workflow undo: {$workflow->name} ({$currentStepCount} → {$backupStepCount} etapes)");

            return AgentResult::reply(
                "Modification annulee pour \"{$workflow->name}\".\n\n"
                . "Etapes restaurees: {$currentStepCount} → {$backupStepCount}\n"
                . "Sauvegarde datant de: {$timeAgo}\n\n"
                . "Voir: /workflow show {$workflow->name}\n"
                . "Re-annuler: /workflow undo {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: undo failed", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply("Erreur lors de l'annulation. Reessaie.");
        }
    }

    /**
     * Show a compact dashboard: health score, recent activity, favorites, and quick actions.
     * Usage: /workflow dashboard
     */
    private function commandDashboard(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->orderByDesc('updated_at')->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow cree.\n\n"
                . "Commence avec:\n"
                . "  /workflow template  — templates pre-faits\n"
                . "  /workflow suggest [description]  — suggestion IA\n"
                . "  /workflow create [nom] [etape1] then [etape2]"
            );
        }

        $total      = $workflows->count();
        $active     = $workflows->where('is_active', true)->count();
        $totalRuns  = $workflows->sum('run_count');
        $pinned     = $workflows->filter(fn($wf) => $this->isPinned($wf));

        // Health score
        $broken   = $workflows->filter(fn($wf) => empty($wf->steps));
        $stale    = $workflows->filter(fn($wf) =>
            $wf->is_active && $wf->last_run_at && $wf->last_run_at->diffInDays(now()) > 30
        );
        $healthy  = $total > 0 ? (int) round(($total - $broken->count() - $stale->count()) / $total * 100) : 100;
        $healthBar = str_repeat('█', (int) round($healthy / 10)) . str_repeat('░', 10 - (int) round($healthy / 10));

        $lines = [
            "Dashboard Workflows",
            str_repeat('═', 30),
            "",
            "Sante: {$healthBar} {$healthy}%",
            "Total: {$total} · Actifs: {$active} · Exec: {$totalRuns}",
        ];

        // Pinned workflows (quick access)
        if ($pinned->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "Epingles:";
            foreach ($pinned->take(3) as $wf) {
                $stepCount = count($wf->steps ?? []);
                $lastRun   = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : 'jamais';
                $lines[]   = "  ★ {$wf->name} ({$stepCount} et. · {$lastRun})";
            }
        }

        // Recent activity (last 3 executed)
        $recent = $workflows->whereNotNull('last_run_at')->sortByDesc('last_run_at')->take(3);
        if ($recent->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "Recents:";
            foreach ($recent as $wf) {
                $ago = $wf->last_run_at->diffForHumans();
                $lines[] = "  · {$wf->name} — {$ago} ({$wf->run_count}x)";
            }
        }

        // Top 3 most used
        $top = $workflows->where('run_count', '>', 0)->sortByDesc('run_count')->take(3);
        if ($top->isNotEmpty() && $top->first()->run_count > 1) {
            $lines[] = '';
            $lines[] = "Top:";
            foreach ($top->values() as $i => $wf) {
                $lines[] = "  " . ($i + 1) . ". {$wf->name} — {$wf->run_count} exec.";
            }
        }

        // Issues summary
        $issues = $broken->count() + $stale->count();
        if ($issues > 0) {
            $lines[] = '';
            $parts = [];
            if ($broken->isNotEmpty()) $parts[] = $broken->count() . " sans etapes";
            if ($stale->isNotEmpty()) $parts[] = $stale->count() . " inactifs >30j";
            $lines[] = "Problemes: " . implode(', ', $parts) . " → /workflow health";
        }

        // Scheduled workflows
        $scheduled = $workflows->filter(fn($wf) => !empty($wf->conditions['schedule'] ?? null));
        if ($scheduled->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "Planifies: " . $scheduled->count();
            foreach ($scheduled->take(2) as $wf) {
                $desc = $wf->conditions['schedule']['description'] ?? '?';
                $lines[] = "  ⏰ {$wf->name} — {$desc}";
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('═', 30);
        $lines[] = "/workflow last     — relancer dernier";
        $lines[] = "/workflow quick [x] — chercher & lancer";
        $lines[] = "/workflow stats    — stats detaillees";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Retry a workflow that previously failed or had errors.
     * Usage: /workflow retry [nom?]
     *   - No name: retry the last executed workflow
     *   - With name: retry a specific workflow
     */
    private function commandRetry(AgentContext $context, string $name = ''): AgentResult
    {
        if (empty(trim($name))) {
            // Retry the most recently run workflow
            $workflow = Workflow::forUser($context->from)
                ->whereNotNull('last_run_at')
                ->orderByDesc('last_run_at')
                ->first();

            if (!$workflow) {
                return AgentResult::reply(
                    "Aucun workflow n'a encore ete execute.\n\n"
                    . "Lance un workflow avec: /workflow trigger [nom]"
                );
            }

            $lastRun = $workflow->last_run_at->diffForHumans();
            $stepCount = count($workflow->steps ?? []);

            if (!$workflow->is_active) {
                return AgentResult::reply(
                    "Le dernier workflow execute (\"{$workflow->name}\") est desactive.\n\n"
                    . "Active-le: /workflow enable {$workflow->name}"
                );
            }

            $this->sendText($context->from, "Retry de \"{$workflow->name}\" ({$stepCount} etape" . ($stepCount > 1 ? 's' : '') . ", dernier: {$lastRun})...");
            $this->log($context, "Workflow retry: {$workflow->name}");

            return $this->triggerWorkflow($context, $workflow);
        }

        $workflow = $this->findWorkflow($context->from, trim($name));

        if (!$workflow) {
            return AgentResult::reply(
                "Workflow \"{$name}\" introuvable.\n"
                . "Utilise /workflow list pour voir tes workflows."
            );
        }

        if (!$workflow->is_active) {
            return AgentResult::reply(
                "Le workflow \"{$workflow->name}\" est desactive.\n"
                . "Active-le: /workflow enable {$workflow->name}"
            );
        }

        if (empty($workflow->steps)) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape.");
        }

        $stepCount = count($workflow->steps ?? []);
        $lastRun = $workflow->last_run_at ? $workflow->last_run_at->diffForHumans() : 'jamais execute';

        $this->sendText($context->from, "Retry de \"{$workflow->name}\" ({$stepCount} etape" . ($stepCount > 1 ? 's' : '') . ", dernier: {$lastRun})...");
        $this->log($context, "Workflow retry: {$workflow->name}");

        return $this->triggerWorkflow($context, $workflow);
    }

    /**
     * Auto-cleanup broken, stale, and unused workflows.
     * Usage: /workflow clean          — preview what would be cleaned
     *        /workflow clean confirm  — execute the cleanup
     */
    private function commandClean(AgentContext $context, string $arg = ''): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->orderBy('name')->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply("Aucun workflow a nettoyer.");
        }

        $now = now();

        $broken = $workflows->filter(fn($wf) => empty($wf->steps));
        $stale = $workflows->filter(fn($wf) =>
            $wf->is_active && $wf->last_run_at && $wf->last_run_at->diffInDays($now) > 60
        );
        $unused = $workflows->filter(fn($wf) =>
            $wf->run_count === 0 && $wf->created_at->diffInDays($now) >= 30
        );

        $totalIssues = $broken->count() + $stale->count() + $unused->count();

        if ($totalIssues === 0) {
            return AgentResult::reply(
                "Tous tes workflows sont en bon etat — rien a nettoyer.\n\n"
                . "Voir: /workflow health"
            );
        }

        $doConfirm = in_array(mb_strtolower(trim($arg)), ['confirm', 'oui', 'yes', 'ok', 'go'], true);

        if ($doConfirm) {
            $deletedCount = 0;
            $disabledCount = 0;
            $deletedNames = [];
            $disabledNames = [];

            // Delete broken workflows (no steps)
            foreach ($broken as $wf) {
                try {
                    $deletedNames[] = $wf->name;
                    $wf->delete();
                    $deletedCount++;
                } catch (\Throwable $e) {
                    Log::error("StreamlineAgent: clean failed to delete {$wf->name}", ['error' => $e->getMessage()]);
                }
            }

            // Disable stale workflows (>60 days without execution)
            foreach ($stale as $wf) {
                try {
                    $disabledNames[] = $wf->name;
                    $wf->update(['is_active' => false]);
                    $disabledCount++;
                } catch (\Throwable $e) {
                    Log::error("StreamlineAgent: clean failed to disable {$wf->name}", ['error' => $e->getMessage()]);
                }
            }

            // Delete unused workflows (never run, >30 days old)
            foreach ($unused as $wf) {
                if ($broken->contains('id', $wf->id)) continue; // already deleted
                try {
                    $deletedNames[] = $wf->name;
                    $wf->delete();
                    $deletedCount++;
                } catch (\Throwable $e) {
                    Log::error("StreamlineAgent: clean failed to delete {$wf->name}", ['error' => $e->getMessage()]);
                }
            }

            $this->log($context, "Workflow cleanup: deleted={$deletedCount}, disabled={$disabledCount}", [
                'deleted' => $deletedNames,
                'disabled' => $disabledNames,
            ]);

            $lines = [
                "Nettoyage termine:",
                str_repeat('─', 26),
            ];
            if ($deletedCount > 0) {
                $lines[] = "Supprimes ({$deletedCount}): " . implode(', ', $deletedNames);
            }
            if ($disabledCount > 0) {
                $lines[] = "Desactives ({$disabledCount}): " . implode(', ', $disabledNames);
            }
            $lines[] = '';
            $lines[] = "/workflow list  — voir les workflows restants";

            return AgentResult::reply(implode("\n", $lines));
        }

        // Preview mode
        $lines = [
            "Nettoyage propose:",
            str_repeat('─', 26),
        ];

        if ($broken->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "A SUPPRIMER — Sans etapes (" . $broken->count() . "):";
            foreach ($broken as $wf) {
                $lines[] = "  · {$wf->name} (cree " . $wf->created_at->diffForHumans() . ")";
            }
        }

        if ($unused->isNotEmpty()) {
            $unusedOnly = $unused->filter(fn($wf) => !$broken->contains('id', $wf->id));
            if ($unusedOnly->isNotEmpty()) {
                $lines[] = '';
                $lines[] = "A SUPPRIMER — Jamais utilises depuis +30j (" . $unusedOnly->count() . "):";
                foreach ($unusedOnly as $wf) {
                    $age = $wf->created_at->diffInDays($now);
                    $lines[] = "  · {$wf->name} (cree il y a {$age}j, jamais lance)";
                }
            }
        }

        if ($stale->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "A DESACTIVER — Inactifs depuis +60j (" . $stale->count() . "):";
            foreach ($stale as $wf) {
                $staleDays = $wf->last_run_at->diffInDays($now);
                $lines[] = "  · {$wf->name} (dernier: il y a {$staleDays}j)";
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('─', 26);
        $lines[] = "Pour executer: /workflow clean confirm";
        $lines[] = "Pour annuler: ne fais rien.";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Quick status overview of workflows (one line per workflow).
     * Usage: /workflow status          — all workflows
     *        /workflow status [nom]    — specific workflow one-liner
     */
    private function commandStatus(AgentContext $context, string $name = ''): AgentResult
    {
        // Specific workflow status
        if (!empty(trim($name))) {
            $workflow = $this->findWorkflow($context->from, trim($name));
            if (!$workflow) {
                return AgentResult::reply(
                    "Workflow \"{$name}\" introuvable.\n"
                    . "Utilise /workflow list pour voir tes workflows."
                );
            }

            $status    = $workflow->is_active ? 'ON' : 'OFF';
            $steps     = count($workflow->steps ?? []);
            $lastRun   = $workflow->last_run_at ? $workflow->last_run_at->diffForHumans() : 'jamais';
            $pinBadge  = $this->isPinned($workflow) ? ' [PIN]' : '';
            $tags      = $workflow->conditions['tags'] ?? [];
            $tagStr    = !empty($tags) ? ' ' . implode(' ', array_map(fn($t) => "#{$t}", $tags)) : '';
            $schedule  = $workflow->conditions['schedule']['description'] ?? null;
            $schedStr  = $schedule ? " | planifie: {$schedule}" : '';
            $note      = $workflow->conditions['note'] ?? null;

            $lines = [
                "[{$status}]{$pinBadge} {$workflow->name}",
                "{$steps} etapes | {$workflow->run_count} exec. | dernier: {$lastRun}{$schedStr}{$tagStr}",
            ];

            if ($note) {
                $lines[] = "Note: " . mb_substr($note, 0, 80);
            }

            if ($workflow->description) {
                $lines[] = mb_substr($workflow->description, 0, 100);
            }

            $lines[] = '';
            $lines[] = "/workflow trigger {$workflow->name}  — lancer";
            $lines[] = "/workflow show {$workflow->name}     — details complets";

            return AgentResult::reply(implode("\n", $lines));
        }

        // Global status: compact one-liner per workflow
        $workflows = Workflow::forUser($context->from)->orderByDesc('updated_at')->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow cree.\n\n"
                . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        // Sort: pinned first, then by last_run_at desc
        $workflows = $workflows->sortByDesc(fn($wf) => $this->isPinned($wf) ? '1' . $wf->name : '0' . ($wf->last_run_at ?? ''));

        $total  = $workflows->count();
        $active = $workflows->where('is_active', true)->count();
        $runs   = $workflows->sum('run_count');

        $lines = [
            "Status workflows ({$total} | {$active} actifs | {$runs} exec.)",
            str_repeat('─', 30),
        ];

        foreach ($workflows->values()->take(15) as $wf) {
            $status   = $wf->is_active ? 'ON' : 'OFF';
            $steps    = count($wf->steps ?? []);
            $lastRun  = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : '-';
            $pin      = $this->isPinned($wf) ? '★ ' : '  ';
            $sched    = !empty($wf->conditions['schedule'] ?? null) ? ' ⏰' : '';
            $lines[]  = "{$pin}[{$status}] {$wf->name} ({$steps}et./{$wf->run_count}x/{$lastRun}){$sched}";
        }

        if ($total > 15) {
            $lines[] = "... et " . ($total - 15) . " autre(s). /workflow list";
        }

        $lines[] = '';
        $lines[] = "/workflow status [nom]  — detail";
        $lines[] = "/workflow dashboard     — tableau de bord";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Display a visual ASCII graph of a workflow's execution flow.
     */
    private function commandGraph(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply("Usage: /workflow graph [nom]\nExemple: /workflow graph morning-brief");
        }

        try {
            $result = $this->findWorkflowOrAmbiguous($context, $name);
            if ($result instanceof AgentResult) {
                return $result;
            }
            $workflow = $result;

            $steps = $workflow->steps ?? [];
            if (empty($steps)) {
                return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape.");
            }

            $v = $this->version();
            $lines = [];
            $lines[] = "Streamline v{$v} — Graphe: {$workflow->name}";
            $lines[] = str_repeat('─', 30);
            $lines[] = '';
            $lines[] = '  ┌─ START ─┐';
            $lines[] = '  └────┬────┘';

            $total = count($steps);
            foreach ($steps as $i => $step) {
                $num = $i + 1;
                $agent = $step['agent'] ?? 'auto';
                $condition = $step['condition'] ?? 'always';
                $onError = $step['on_error'] ?? 'stop';
                $disabled = !empty($step['disabled']);
                $msg = mb_substr($step['message'] ?? '???', 0, 35);
                if (mb_strlen($step['message'] ?? '') > 35) {
                    $msg .= '...';
                }

                $lines[] = '       │';

                if ($condition !== 'always') {
                    $lines[] = "   ┌───┴───┐";
                    $lines[] = "   │ si: {$condition}";
                    $lines[] = "   └───┬───┘";
                }

                $statusIcon = $disabled ? '⏸' : '▶';
                $errorIcon = $onError === 'continue' ? '↪' : '✋';
                $lines[] = "  ┌────┴────────────────────┐";
                $lines[] = "  │ {$statusIcon} {$num}. [{$agent}]";
                $lines[] = "  │ {$msg}";
                $lines[] = "  │ erreur: {$errorIcon} {$onError}";
                $lines[] = "  └────┬────────────────────┘";

                if ($onError === 'continue' && $i < $total - 1) {
                    $lines[] = "       │ (continue meme si erreur)";
                }
            }

            $lines[] = '       │';
            $lines[] = '  ┌────┴────┐';
            $lines[] = '  │  END ✓  │';
            $lines[] = '  └─────────┘';
            $lines[] = '';

            $activeSteps = count(array_filter($steps, fn($s) => empty($s['disabled'])));
            $disabledSteps = $total - $activeSteps;
            $lines[] = "Etapes: {$total} ({$activeSteps} actives" . ($disabledSteps > 0 ? ", {$disabledSteps} desactivees" : '') . ')';
            $lines[] = "Executions: {$workflow->run_count}x";
            $lines[] = '';
            $lines[] = "/workflow show {$workflow->name}  — details";
            $lines[] = "/workflow dryrun {$workflow->name} — simuler";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: graph failed", [
                'error' => $e->getMessage(),
                'name' => $name,
            ]);
            return AgentResult::reply("Erreur lors de la generation du graphe. Verifie que le workflow \"{$name}\" existe.");
        }
    }

    /**
     * Display a summary of the most recently executed workflows with timing.
     */
    private function commandRecent(AgentContext $context, string $limit = ''): AgentResult
    {
        try {
            $max = min(max((int) ($limit ?: 5), 1), 10);
            $workflows = Workflow::forUser($context->from)
                ->whereNotNull('last_run_at')
                ->orderByDesc('last_run_at')
                ->limit($max)
                ->get();

            if ($workflows->isEmpty()) {
                return AgentResult::reply("Aucun workflow execute recemment.\nCree-en un avec: /workflow create [nom] [etape1] then [etape2]");
            }

            $v = $this->version();
            $lines = [];
            $lines[] = "Streamline v{$v} — Workflows recents";
            $lines[] = str_repeat('─', 30);
            $lines[] = '';

            foreach ($workflows as $i => $wf) {
                $num = $i + 1;
                $stepCount = count($wf->steps ?? []);
                $lastRun = $wf->last_run_at ? \Carbon\Carbon::parse($wf->last_run_at)->diffForHumans() : 'jamais';
                $status = $wf->is_active ? '✅' : '⏸';
                $pin = $this->isPinned($wf) ? '📌 ' : '';

                $lines[] = "{$num}. {$pin}{$status} *{$wf->name}*";
                $lines[] = "   {$stepCount} etape(s) · {$wf->run_count}x · dernier: {$lastRun}";
            }

            $lines[] = '';
            $lines[] = "/workflow trigger [nom]  — relancer";
            $lines[] = "/workflow last           — relancer le dernier";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: recent failed", ['error' => $e->getMessage()]);
            return AgentResult::reply("Erreur lors de la recuperation des workflows recents. Reessaie.");
        }
    }

    /**
     * Show help text, optionally filtered by category.
     */
    private function showHelp(AgentContext $context, string $category = ''): AgentResult
    {
        $category = mb_strtolower(trim($category));

        if (!empty($category)) {
            $text = $this->getHelpCategory($category);
            if ($text) {
                return AgentResult::reply($text);
            }
        }

        return AgentResult::reply($this->getHelpText());
    }

    /**
     * Return help text for a specific category.
     */
    private function getHelpCategory(string $category): ?string
    {
        $v = $this->version();
        $header = "Streamline v{$v} — Aide";

        return match ($category) {
            'create', 'creer', 'creation' => "{$header}: Creation & gestion\n"
                . str_repeat('─', 30) . "\n\n"
                . "/workflow create [nom] [etape1] then [etape2]\n"
                . "/workflow import [nom] [etape1] then [etape2]\n"
                . "/workflow template [tpl] [nom]  — depuis un modele\n"
                . "/workflow suggest [description] — suggestion IA\n"
                . "/workflow duplicate [nom] [nouveau]\n"
                . "/workflow rename [ancien] [nouveau]\n"
                . "/workflow describe [nom] [description]\n"
                . "/workflow delete [nom]\n\n"
                . "Exemples:\n"
                . "  /workflow create daily-brief check todos then rappels\n"
                . "  /workflow template morning-brief ma-routine\n"
                . "  /workflow suggest routine du soir\n\n"
                . "Aide complete: /workflow help",

            'execute', 'executer', 'run', 'lancer' => "{$header}: Execution\n"
                . str_repeat('─', 30) . "\n\n"
                . "/workflow trigger [nom]               — lancer\n"
                . "/workflow trigger [nom] [contexte]    — lancer avec contexte\n"
                . "/workflow batch [nom1] [nom2] [nom3]  — plusieurs en sequence\n"
                . "/workflow run-all [#tag?]              — tous les actifs (max 5)\n"
                . "/workflow quick [terme]               — chercher & lancer\n"
                . "/workflow last                        — relancer le dernier\n"
                . "/workflow retry [nom?]                — re-executer\n"
                . "/workflow schedule [nom] [frequence]  — planifier\n"
                . "/workflow dryrun [nom]                — simuler\n\n"
                . "Contexte parametrique:\n"
                . "  /workflow trigger morning-brief focus finances\n"
                . "  → Chaque etape recoit le contexte \"focus finances\"\n\n"
                . "Aide complete: /workflow help",

            'edit', 'modifier', 'etapes', 'steps' => "{$header}: Modifier les etapes\n"
                . str_repeat('─', 30) . "\n\n"
                . "/workflow edit [nom] [N] [nouveau_message]\n"
                . "/workflow add [nom] [message]\n"
                . "/workflow insert [nom] [position] [message]\n"
                . "/workflow remove-step [nom] [N]\n"
                . "/workflow move-step [nom] [from] [to]\n"
                . "/workflow swap [nom] [N1] [N2]\n"
                . "/workflow copy-step [src] [N] [dest]\n"
                . "/workflow step-config [nom] [N] agent=[a]\n"
                . "/workflow step-config [nom] [N] condition=always|success\n"
                . "/workflow step-config [nom] [N] on_error=stop|continue\n"
                . "/workflow disable-step [nom] [N]  — desactiver temporairement\n"
                . "/workflow enable-step [nom] [N]   — reactiver\n"
                . "/workflow test-step [nom] [N]     — tester une etape\n"
                . "/workflow undo [nom]  — annuler derniere modif\n\n"
                . "Aide complete: /workflow help",

            'organize', 'organiser', 'tags', 'tag' => "{$header}: Organisation\n"
                . str_repeat('─', 30) . "\n\n"
                . "/workflow tag [nom] [tag1,tag2]\n"
                . "/workflow tags  — tous les tags\n"
                . "/workflow list #tag  — filtrer par tag\n"
                . "/workflow pin [nom]  — epingler\n"
                . "/workflow unpin [nom]\n"
                . "/workflow notes [nom] [texte]\n"
                . "/workflow merge [nom1] [nom2]  — fusionner\n\n"
                . "Aide complete: /workflow help",

            'analyze', 'analyser', 'stats', 'analyse' => "{$header}: Analyse\n"
                . str_repeat('─', 30) . "\n\n"
                . "/workflow status [nom?]   — apercu rapide\n"
                . "/workflow stats           — statistiques\n"
                . "/workflow health          — audit sante\n"
                . "/workflow dashboard       — tableau de bord\n"
                . "/workflow favorites       — top 5\n"
                . "/workflow history [nom?]  — historique\n"
                . "/workflow summary [nom]   — resume IA\n"
                . "/workflow optimize [nom]  — suggestions IA\n"
                . "/workflow diff [nom1] [nom2]  — comparer\n"
                . "/workflow export [nom]    — exporter\n"
                . "/workflow clean           — nettoyer\n\n"
                . "Aide complete: /workflow help",

            default => null,
        };
    }

    private function getHelpText(): string
    {
        $v = $this->version();
        return "Streamline v{$v} — Workflows multi-agents\n"
            . str_repeat('─', 30) . "\n\n"
            . "Creer & gerer:\n"
            . "  /workflow create [nom] [etape1] then [etape2]\n"
            . "  /workflow import [nom] [etape1] then [etape2]\n"
            . "  /workflow list [filtre?] [#tag?]\n"
            . "  /workflow search [terme]\n"
            . "  /workflow show [nom]\n"
            . "  /workflow delete [nom]\n"
            . "  /workflow rename [ancien] [nouveau]\n"
            . "  /workflow duplicate [nom] [nouveau-nom]  (alias: clone)\n"
            . "  /workflow describe [nom] [description]\n\n"
            . "Modifier les etapes:\n"
            . "  /workflow edit [nom] [N] [nouveau_message]\n"
            . "  /workflow step-config [nom] [N] agent=[a]\n"
            . "  /workflow step-config [nom] [N] condition=[c]\n"
            . "  /workflow step-config [nom] [N] on_error=stop|continue\n"
            . "  /workflow add [nom] [message_etape]\n"
            . "  /workflow insert [nom] [position] [message]\n"
            . "  /workflow remove-step [nom] [N]\n"
            . "  /workflow move-step [nom] [from] [to]\n"
            . "  /workflow disable-step [nom] [N]          — desactiver temporairement\n"
            . "  /workflow enable-step [nom] [N]           — reactiver\n"
            . "  /workflow test-step [nom] [N]             — tester une seule etape\n\n"
            . "Executer:\n"
            . "  /workflow trigger [nom]               — lancer un workflow\n"
            . "  /workflow trigger [nom] [contexte]    — lancer avec parametres\n"
            . "  /workflow batch [nom1] [nom2] [nom3]  — lancer plusieurs specifiques\n"
            . "  /workflow run-all [#tag?]              — lancer tous (max 5)\n"
            . "  /workflow enable [nom]\n"
            . "  /workflow disable [nom]\n\n"
            . "Organisation:\n"
            . "  /workflow tag [nom] [tag1,tag2]  — etiqueter\n"
            . "  /workflow tags                   — tous les tags\n"
            . "  /workflow list #tag              — filtrer par tag\n"
            . "  /workflow pin [nom]              — epingler\n"
            . "  /workflow unpin [nom]            — desepingler\n"
            . "  /workflow notes [nom] [texte]    — ajouter une note\n\n"
            . "Templates & Suggestions IA:\n"
            . "  /workflow template               — 10 templates disponibles\n"
            . "  /workflow template [tpl] [nom]   — creer depuis un template\n"
            . "  /workflow suggest [description]  — suggestion IA sur mesure\n\n"
            . "Analyser & comprendre:\n"
            . "  /workflow status [nom?]      — apercu rapide\n"
            . "  /workflow graph [nom]        — graphe visuel du flux\n"
            . "  /workflow recent [N?]        — workflows recemment lances\n"
            . "  /workflow summary [nom]      — explication IA du workflow\n"
            . "  /workflow stats              — statistiques + tags\n"
            . "  /workflow health             — audit sante de tous les workflows\n"
            . "  /workflow history [nom?]\n"
            . "  /workflow export [nom]\n"
            . "  /workflow dryrun [nom]       — simuler sans executer\n"
            . "  /workflow reset-stats [nom]  — remettre les stats a zero\n\n"
            . "Automatisation & fusion:\n"
            . "  /workflow schedule [nom] [frequence]  — planifier l'execution automatique\n"
            . "  /workflow merge [nom1] [nom2]         — fusionner deux workflows en un\n"
            . "  /workflow optimize [nom]              — analyse IA et suggestions d'amelioration\n\n"
            . "Comparer & favoris:\n"
            . "  /workflow diff [nom1] [nom2]         — comparer deux workflows\n"
            . "  /workflow favorites                   — top 5 workflows les plus utilises\n"
            . "  /workflow dashboard                   — tableau de bord compact\n\n"
            . "Raccourcis:\n"
            . "  /workflow quick [terme]              — chercher et lancer immediatement\n"
            . "  /workflow last                        — relancer le dernier workflow execute\n"
            . "  /workflow copy-step [src] [N] [dest] — copier une etape vers un autre workflow\n"
            . "  /workflow swap [nom] [N1] [N2]       — echanger deux etapes de position\n"
            . "  /workflow undo [nom]                  — annuler la derniere modification\n"
            . "  /workflow retry [nom?]                — relancer un workflow (dernier si vide)\n"
            . "  /workflow clean                       — nettoyer workflows casses/obsoletes\n\n"
            . "Inline (execution immediate, max 8 etapes):\n"
            . "  \"resume mes todos puis check mes rappels\"\n"
            . "  \"analyse ce code >> cree un resume\"\n\n"
            . "Conditions par etape:\n"
            . "  always | success | contains:mot | not_contains:mot\n"
            . "Gestion erreurs: stop (defaut) | continue\n"
            . "Separateurs: then | puis | et puis | ensuite | after that | >>\n\n"
            . "Exemples:\n"
            . "  /workflow create daily-brief check todos then rappels then meteo\n"
            . "  /workflow template morning-brief ma-routine\n"
            . "  /workflow run-all #matin\n"
            . "  /workflow summary morning-brief\n\n"
            . "Aide par categorie:\n"
            . "  /workflow help create   — creation\n"
            . "  /workflow help execute  — execution\n"
            . "  /workflow help edit     — modifier etapes\n"
            . "  /workflow help organize — organisation\n"
            . "  /workflow help analyze  — analyse & stats";
    }
}
