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
    /** Maximum number of steps per workflow. */
    private const MAX_STEPS = 10;

    /** Maximum number of workflows per user. */
    private const MAX_WORKFLOWS = 50;

    /** Maximum input length for NLU processing (chars). */
    private const NLU_INPUT_MAX_LENGTH = 1000;

    /** Maximum workflows processed by summary-all. */
    private const SUMMARY_ALL_LIMIT = 20;

    /** Max tokens for NLU main call. */
    private const NLU_MAX_TOKENS = 800;

    /** Max tokens for self-heal JSON correction. */
    private const SELF_HEAL_MAX_TOKENS = 400;

    /** Max tokens for summary generation. */
    private const SUMMARY_MAX_TOKENS = 600;

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
            'workflow info', 'info workflow', 'fiche workflow', 'resume workflow',
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
            'workflow chain', 'enchainer workflows', 'chain workflow', 'chaine de workflows',
            'workflow analyze', 'analyser workflow', 'analyse workflow', 'rapport workflow',
            'workflow snapshot', 'snapshot workflow', 'sauvegarder etat workflow', 'capturer workflow',
            'workflow restore', 'restaurer snapshot', 'revenir snapshot', 'charger snapshot',
            'workflow compare-stats', 'comparer workflows', 'comparer stats',
            'workflow recap', 'recap workflows', 'bilan workflows', 'resume activite',
            'workflow profile', 'profil workflow', 'fiche workflow detaillee', 'score workflow',
            'workflow bulk', 'bulk workflow', 'action en masse', 'activer plusieurs workflows',
            'desactiver plusieurs workflows', 'supprimer plusieurs workflows',
            'workflow explain', 'expliquer workflow', 'que fait workflow', 'comment marche workflow',
            'workflow timeline', 'timeline workflow', 'chronologie workflow', 'historique visuel',
            'activite workflow', 'executions recentes workflow',
            'workflow estimate', 'estimation workflow', 'duree workflow', 'combien de temps workflow',
            'temps execution workflow', 'workflow watch', 'surveiller workflow', 'monitoring workflow',
            'workflow rename-step', 'renommer etape', 'modifier etape', 'changer message etape',
            'workflow pause', 'pause workflow', 'mettre en pause workflow', 'reprendre workflow',
            'workflow split', 'split workflow', 'decouper workflow', 'separer workflow', 'couper workflow',
            'workflow reorder', 'reorder workflow', 'reorganiser etapes', 'reordonner etapes', 'nouvel ordre etapes',
            'workflow archive', 'archiver workflow', 'desarchiver workflow', 'workflow unarchive',
            'workflows archives', 'mes archives',
            'workflow compact', 'vue compacte', 'compact workflows', 'apercu rapide workflows',
            'workflow go', 'lance vite', 'workflow rapide', 'go workflow',
            'workflow diagnose', 'diagnostique workflow', 'debug workflow', 'pourquoi workflow marche pas',
            'workflow streak', 'streak workflow', 'serie workflow', 'ma serie', 'jours consecutifs',
            'workflow focus', 'focus workflow', 'filtrer par tag', 'workflows par tag', 'voir tag',
            'workflow quick-create', 'creation rapide workflow', 'quick create', 'creer vite workflow',
            'workflow overview', 'vue d\'ensemble', 'overview workflows', 'apercu general',
            'workflow clone-steps', 'cloner etapes', 'copier etapes workflow', 'repliquer etapes',
            'workflow kpi', 'kpi workflow', 'indicateurs workflow', 'metriques workflow', 'performance globale workflows',
            'workflow help-search', 'cherche commande workflow', 'quelle commande workflow',
            'workflow summary-all', 'resume tous workflows', 'resume global workflows', 'sommaire workflows',
            'workflow benchmark', 'benchmark workflows', 'performance workflows', 'comparer performance',
            'classement workflows', 'ranking workflows', 'workflow perf',
            'workflow whatif', 'workflow what-if', 'impact etape', 'que se passe si', 'simuler retrait',
            'impact suppression etape', 'workflow impact',
        ];
    }

    public function version(): string
    {
        return '1.44.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return $context->routedAgent === 'streamline'
            || (bool) preg_match('/\b(workflow|chain|pipeline|enchainer|chainer|\/workflow|automatiser|automate|sequence|lancer workflow|mes workflows|creer workflow|gerer workflows|valider workflow|validate workflow|bulk.?tag|taguer tous|etapes automatiques|multi.?workflow)\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            return $this->handleInner($context);
        } catch (\Throwable $e) {
            Log::error('StreamlineAgent handle() exception', [
                'from'  => $context->from,
                'body'  => mb_substr($context->body ?? '', 0, 300),
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 1500),
            ]);
            $this->log($context, 'EXCEPTION: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'file'  => basename($e->getFile()) . ':' . $e->getLine(),
            ], 'error');

            $errMsg = $e->getMessage();
            $isDbError    = $e instanceof \Illuminate\Database\QueryException;
            $isRateLimit  = str_contains($errMsg, 'rate_limit') || str_contains($errMsg, '429');
            $isTimeout    = str_contains($errMsg, 'timed out') || str_contains($errMsg, 'timeout');
            $isMemory     = str_contains($errMsg, 'memory') || str_contains($errMsg, 'Allowed memory');
            $isConnection = str_contains($errMsg, 'Connection refused') || str_contains($errMsg, 'Could not resolve host');
            $isAuth       = str_contains($errMsg, '401') || str_contains($errMsg, 'Unauthorized') || str_contains($errMsg, 'authentication');
            $isJsonError  = $e instanceof \JsonException || str_contains($errMsg, 'json') || str_contains($errMsg, 'JSON');
            $isOverload   = str_contains($errMsg, 'overloaded') || str_contains($errMsg, '529') || str_contains($errMsg, '503');
            $reply = match (true) {
                $isDbError    => "⚠ Erreur temporaire de base de donnees. Reessaie dans quelques instants.",
                $isRateLimit  => "⚠ Trop de requetes en cours. Attends quelques secondes et reessaie.",
                $isTimeout    => "⚠ Le traitement a pris trop de temps. Essaie avec un workflow plus simple ou /workflow test-step pour tester etape par etape.",
                $isMemory     => "⚠ Workflow trop volumineux a traiter. Reduis le nombre d'etapes ou simplifie les instructions.",
                $isConnection => "⚠ Service externe indisponible. Verifie ta connexion et reessaie.",
                $isAuth       => "⚠ Erreur d'authentification avec le service IA. Contacte l'administrateur.",
                $isJsonError  => "⚠ Erreur de traitement des donnees du workflow. Reessaie ou utilise /workflow validate [nom] pour verifier.",
                $isOverload   => "⚠ Le service IA est temporairement surcharge. Reessaie dans quelques secondes.",
                default       => "⚠ Erreur interne de l'agent workflow. Reessaie ou tape /workflow help.",
            };
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['error' => $errMsg]);
        }
    }

    private function handleInner(AgentContext $context): AgentResult
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

        // Detect workflow-related keywords or routed agent
        if ($context->routedAgent === 'streamline'
            || preg_match('/\b(workflow|pipeline|automatiser|automate|sequence|enchainer|chainer)\b/iu', $lower)) {
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

        if ($type === 'confirm_bulk_delete') {
            $lower = mb_strtolower(trim($context->body ?? ''));
            $this->clearPendingContext($context);
            if (in_array($lower, ['oui', 'yes', 'ok', 'confirme', 'o'])) {
                $ids = $data['workflow_ids'] ?? [];
                $deleted = 0;
                foreach ($ids as $id) {
                    $wf = Workflow::find($id);
                    if ($wf && $wf->user_phone === $context->from) {
                        $wf->delete();
                        $deleted++;
                    }
                }
                $this->log($context, "Bulk delete: {$deleted} workflows supprime(s)");
                return AgentResult::reply("🗑 *{$deleted}* workflow(s) supprime(s).");
            }
            return AgentResult::reply('Suppression en masse annulee.');
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
            'info'                          => $this->commandInfo($context, $arg1),
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
            'chain'                         => $this->commandChain($context, implode(' ', array_slice($parts, 2))),
            'analyze', 'analyse'            => $this->commandAnalyze($context, $arg1),
            'snapshot'                      => $this->commandSnapshot($context, $arg1, $arg2),
            'restore'                       => $this->commandRestore($context, $arg1, $arg2),
            'validate', 'valider', 'check'  => $this->commandValidate($context, $arg1),
            'bulk-tag', 'bulktag'           => $this->commandBulkTag($context, $arg1, $arg2),
            'compare-stats', 'comparestats' => $this->commandCompareStats($context, $arg1, $arg2),
            'recap'                         => $this->commandRecap($context, $arg1),
            'profile', 'profil', 'fiche'    => $this->commandProfile($context, $arg1),
            'bulk'                          => $this->commandBulkAction($context, implode(' ', array_slice($parts, 2))),
            'dependencies', 'deps', 'agents'=> $this->commandDependencies($context),
            'export-all', 'exportall', 'backup' => $this->commandExportAll($context),
            'explain', 'expliquer'          => $this->commandExplain($context, $arg1),
            'timeline', 'chronologie'       => $this->commandTimeline($context, $arg1),
            'estimate', 'estimation'        => $this->commandEstimate($context, $arg1),
            'watch'                         => $this->commandWatch($context, $arg1),
            'rename-step', 'renamestep'     => $this->commandRenameStep($context, $arg1, $arg2),
            'pause', 'resume'               => $this->commandPause($context, $arg1),
            'split'                         => $this->commandSplit($context, $arg1, $arg2),
            'reorder'                       => $this->commandReorder($context, $arg1, $arg2),
            'archive'                       => $this->commandArchive($context, $arg1, true),
            'unarchive'                     => $this->commandArchive($context, $arg1, false),
            'compact'                       => $this->commandCompact($context),
            'go'                            => $this->commandGo($context, implode(' ', array_slice($parts, 2))),
            'diagnose', 'diag', 'debug'     => $this->commandDiagnose($context, $arg1),
            'preflight'                     => $this->commandPreflight($context, $arg1),
            'streak', 'serie'               => $this->commandStreak($context),
            'focus'                         => $this->commandFocus($context, $arg1),
            'quick-create', 'quickcreate'   => $this->commandQuickCreate($context, implode(' ', array_slice($parts, 2))),
            'overview'                      => $this->commandOverview($context),
            'clone-steps', 'clonesteps'     => $this->commandCloneSteps($context, $arg1, $arg2),
            'kpi', 'kpis'                   => $this->commandKpi($context, $arg1),
            'help-search', 'helpsearch'     => $this->commandHelpSearch($context, implode(' ', array_slice($parts, 2))),
            'summary-all', 'summaryall'     => $this->commandSummaryAll($context),
            'benchmark', 'bench', 'perf'    => $this->commandBenchmark($context, $arg1),
            'whatif', 'what-if', 'impact'   => $this->commandWhatIf($context, $arg1, $arg2),
            'help'                          => $this->showHelp($context, $arg1),
            default                         => $this->handleUnknownCommand($context, $action),
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

        // Validate name length
        if (mb_strlen($parsed['name']) > 50) {
            return AgentResult::reply(
                "Nom trop long (" . mb_strlen($parsed['name']) . " car.). Maximum 50 caracteres.\n"
                . "Choisis un nom plus court et descriptif."
            );
        }

        if (mb_strlen($parsed['name']) < 2) {
            return AgentResult::reply(
                "Nom trop court. Minimum 2 caracteres.\n"
                . "Exemples: morning-brief, daily-check, weekly-review"
            );
        }

        // Enforce workflow quota (max 50 per user)
        $workflowCount = Workflow::forUser($context->from)->count();
        if ($workflowCount >= 50) {
            return AgentResult::reply(
                "⚠ Limite atteinte: *50 workflows* maximum.\n\n"
                . "Libere de la place:\n"
                . "  /workflow clean — nettoyer les inutilises\n"
                . "  /workflow delete [nom] — supprimer un workflow\n"
                . "  /workflow merge [n1] [n2] — fusionner deux workflows"
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

        // Hide archived workflows by default
        $workflows = $workflows->filter(fn($wf) => empty($wf->conditions['archived']));

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
            ? "*Workflows #{$tagFilter}* ({$workflows->count()}):"
            : (!empty($search)
                ? "*Workflows* contenant \"{$search}\" ({$workflows->count()}):"
                : "*Tes workflows* ({$workflows->count()} · {$activeCount} actif" . ($activeCount > 1 ? 's' : '') . "):");

        // Sort: pinned first, then by updated_at desc
        $workflows = $workflows->sortByDesc(fn($wf) => $this->isPinned($wf) ? 1 : 0);

        $lines = [$header, str_repeat('─', 28)];
        foreach ($workflows->values() as $i => $wf) {
            $active    = $wf->is_active ? '✅' : '⏸';
            $pinBadge  = $this->isPinned($wf) ? '📌 ' : '';
            $stepCount = count($wf->steps ?? []);
            $lastRun   = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : 'jamais';
            $desc      = !empty($wf->description) ? "\n   _" . mb_substr($wf->description, 0, 60) . "_" : '';
            $lines[] = ($i + 1) . ". {$active} {$pinBadge}*{$wf->name}*{$desc}";
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
                "⏸ Le workflow *{$workflow->name}* est desactive.\n"
                . "Active-le avec: /workflow enable {$workflow->name}"
            );
        }

        $conditions = $workflow->conditions ?? [];
        if (!empty($conditions['paused'])) {
            return AgentResult::reply(
                "⏸ Le workflow *{$workflow->name}* est en pause.\n"
                . "Reprends-le avec: /workflow pause {$workflow->name}"
            );
        }

        if (empty($workflow->steps)) {
            return AgentResult::reply("Le workflow *{$workflow->name}* n'a aucune etape a executer.\nAjoute-en avec: /workflow add-step {$workflow->name} [instruction]");
        }

        $input = trim($input);

        // Support --preview flag: show steps before executing
        if (preg_match('/--preview\b/i', $input)) {
            $input = trim(preg_replace('/--preview\b/i', '', $input));
            return $this->commandPreview($context, $workflow, $input ?: null);
        }

        return $this->triggerWorkflow($context, $workflow, $input ?: null);
    }

    /**
     * Preview workflow steps before executing.
     */
    private function commandPreview(AgentContext $context, Workflow $workflow, ?string $input): AgentResult
    {
        $steps = $workflow->steps ?? [];
        $stepCount = count($steps);

        if ($stepCount === 0) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape.");
        }

        $lines = [
            "*Preview: {$workflow->name}*",
            str_repeat('─', 25),
        ];

        if ($input) {
            $lines[] = "Contexte: _{$input}_";
            $lines[] = '';
        }

        foreach ($steps as $i => $step) {
            $num = $i + 1;
            $agent = $step['agent'] ?? 'auto';
            $condition = $step['condition'] ?? 'always';
            $onError = $step['on_error'] ?? 'stop';
            $disabled = $step['disabled'] ?? false;
            $msg = mb_substr($step['message'] ?? '', 0, 80);

            $icon = $disabled ? '⏭' : '▶';
            $condStr = ($condition !== 'always') ? " [{$condition}]" : '';
            $errStr = ($onError === 'continue') ? ' (skip on error)' : '';

            $lines[] = "{$icon} *{$num}.* [{$agent}]{$condStr}{$errStr}";
            $lines[] = "   {$msg}" . (mb_strlen($step['message'] ?? '') > 80 ? '...' : '');
        }

        $lines[] = '';
        $lines[] = str_repeat('─', 25);
        $lines[] = "Lancer: /workflow trigger {$workflow->name}" . ($input ? " {$input}" : '');
        $lines[] = "Simuler: /workflow dryrun {$workflow->name}";

        $this->log($context, "Preview: {$workflow->name}", ['steps' => $stepCount]);

        return AgentResult::reply(implode("\n", $lines));
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

        $statusIcon = $workflow->is_active ? '✅' : '⏸';
        $pinIcon    = $this->isPinned($workflow) ? ' 📌' : '';

        $lines = [
            "*Workflow: {$workflow->name}*{$pinIcon}",
            str_repeat('─', 28),
        ];

        if (!empty($workflow->description)) {
            $lines[] = "_{$workflow->description}_";
            $lines[] = '';
        }

        $lines[] = "{$statusIcon} Statut  : *{$status}*";
        $lines[] = "📋 Etapes  : {$stepCount}";
        $lines[] = "▶ Exec.   : {$workflow->run_count}";
        $lines[] = "🕐 Dernier : {$lastRun}";
        $lines[] = "📅 Cree    : {$createdAt}";
        $lines[] = '';
        $lines[] = "*Etapes ({$stepCount}):*";

        foreach (($workflow->steps ?? []) as $i => $step) {
            $agent     = $step['agent'] ?? 'auto';
            $msg       = mb_substr($step['message'] ?? '', 0, 80);
            $condition = (!empty($step['condition']) && $step['condition'] !== 'always')
                ? " _[si:{$step['condition']}]_"
                : '';
            $onError   = (!empty($step['on_error']) && $step['on_error'] !== 'stop')
                ? " _[err:continuer]_"
                : '';
            $skipBadge = !empty($step['_skip']) ? " ⏭ SKIP" : '';
            $lines[] = "  " . ($i + 1) . ". *[{$agent}]* {$msg}{$condition}{$onError}{$skipBadge}";
        }

        // Show note if present
        $note = $workflow->conditions['note'] ?? null;
        if (!empty($note)) {
            $lines[] = '';
            $lines[] = "📝 Note: " . mb_substr($note, 0, 100) . (mb_strlen($note) > 100 ? '...' : '');
        }

        // Show tags if present
        $tags = $workflow->conditions['tags'] ?? [];
        if (!empty($tags)) {
            $lines[] = '🏷 Tags: ' . implode(' ', array_map(fn($t) => "#{$t}", $tags));
        }

        $lines[] = '';
        $lines[] = "/workflow trigger {$workflow->name}";
        $lines[] = "/workflow edit {$workflow->name} [N] [msg]";
        $lines[] = "/workflow remove-step {$workflow->name} [N]";
        $lines[] = "/workflow duplicate {$workflow->name} [nouveau]";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Compact info card for a workflow: quick overview with performance metrics.
     */
    private function commandInfo(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply('Precise le nom: /workflow info [nom]');
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) {
            return $errResult;
        }

        $stepCount  = count($workflow->steps ?? []);
        $statusIcon = $workflow->is_active ? '🟢' : '🔴';
        $pinIcon    = $this->isPinned($workflow) ? ' 📌' : '';
        $lastRun    = $workflow->last_run_at ? $workflow->last_run_at->diffForHumans() : 'jamais';

        $conditions = $workflow->conditions ?? [];
        $durations  = $conditions['durations'] ?? [];
        $avgTime    = !empty($durations) ? round(array_sum($durations) / count($durations), 1) . 's' : '—';
        $lastTime   = !empty($durations) ? end($durations) . 's' : '—';

        $agents = collect($workflow->steps ?? [])
            ->pluck('agent')
            ->filter()
            ->unique()
            ->implode(', ') ?: 'auto';

        $tags = !empty($conditions['tags']) ? ' #' . implode(' #', $conditions['tags']) : '';

        $successRate = $workflow->run_count > 0
            ? round((($workflow->run_count - ($conditions['fail_count'] ?? 0)) / $workflow->run_count) * 100) . '%'
            : '—';

        $lines = [
            "{$statusIcon} *{$workflow->name}*{$pinIcon}{$tags}",
            "📋 {$stepCount} etapes • ▶ {$workflow->run_count}x • ✅ {$successRate}",
            "⏱ Moy: {$avgTime} • Dernier: {$lastTime} • {$lastRun}",
            "🤖 Agents: {$agents}",
        ];

        if (!empty($workflow->description)) {
            $lines[] = "_{$workflow->description}_";
        }

        $lines[] = '';
        $lines[] = "/workflow show {$workflow->name} • /workflow trigger {$workflow->name}";

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

        $activeRatio = $total > 0 ? (int) round($active / $total * 100) : 0;
        $activeBar   = str_repeat('█', (int) round($activeRatio / 10)) . str_repeat('░', 10 - (int) round($activeRatio / 10));

        $lines = [
            "*📊 Statistiques workflows*",
            str_repeat('─', 28),
            "Actifs: {$activeBar} *{$activeRatio}%* ({$active}/{$total})",
            "",
            "📦 Total       : *{$total}* workflow" . ($total > 1 ? 's' : ''),
            "⏸ Inactifs    : " . ($total - $active),
            "🆕 Jamais uses : {$neverRun}",
            "📌 Epingles    : {$pinnedCount}",
            "🏷 Tagues      : {$taggedCount}",
            str_repeat('─', 20),
            "▶️ Exec. tot.  : *{$totalRuns}*",
            "🔗 Etapes tot. : {$totalSteps}",
            "📐 Moy. etapes : {$avgSteps}/workflow",
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
            Log::error("StreamlineAgent: inline chain execution failed", [
                'error' => $e->getMessage(),
                'steps' => count($steps),
                'file'  => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            $this->log($context, 'Inline chain failed', [
                'error' => mb_substr($e->getMessage(), 0, 200),
                'steps' => count($steps),
            ], 'error');
            return AgentResult::reply(
                "Erreur lors de l'execution de la chain ({$stepCount} etapes).\n"
                . "Essaie de simplifier ou cree un workflow permanent:\n"
                . "/workflow create ma-chain " . implode(' then ', array_slice($steps, 0, 3))
            );
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
        // Input length guard — truncate excessively long messages to avoid LLM token waste
        if (mb_strlen($body) > self::NLU_INPUT_MAX_LENGTH) {
            $body = mb_substr($body, 0, self::NLU_INPUT_MAX_LENGTH);
            Log::info("StreamlineAgent: NLU input truncated", ['from' => $context->from, 'original_len' => mb_strlen($context->body ?? '')]);
        }

        $model         = $this->resolveModel($context);
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);

        $userWorkflows   = Workflow::forUser($context->from)->pluck('name')->implode(', ');
        $workflowContext = $userWorkflows
            ? "Workflows existants de l'utilisateur: {$userWorkflows}"
            : "L'utilisateur n'a pas encore de workflow.";

        $workflowCount = Workflow::forUser($context->from)->count();

        try {
        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"\n\n{$workflowContext}\nNombre total de workflows: {$workflowCount}\n\n{$contextMemory}",
            $model,
            "Tu es un assistant specialise dans la creation et gestion de workflows WhatsApp.\n"
            . "Reponds UNIQUEMENT en JSON valide. Aucun markdown, aucun backtick, aucun commentaire.\n\n"
            . "REGLES CRITIQUES:\n"
            . "- N'invente JAMAIS de workflows, noms ou donnees qui n'existent pas\n"
            . "- Si l'intention est ambigue, utilise action=\"help\" plutot que de deviner\n"
            . "- Le champ \"name\" doit correspondre a un workflow existant ou a un nouveau nom explicitement demande\n"
            . "- Pour les actions sur un workflow existant (trigger/show/delete/etc.), le \"name\" DOIT etre un des workflows existants listés ci-dessus\n"
            . "- Maximum " . self::MAX_STEPS . " etapes par workflow, " . self::MAX_WORKFLOWS . " workflows par utilisateur\n"
            . "- Disambiguation: 'montre/affiche' → show, 'info/resume rapide' → info, 'explique/comment ca marche' → explain, 'analyse/rapport' → analyze, 'resume d'activite/bilan' → recap\n\n"
            . "Format de reponse:\n"
            . "{\n"
            . "  \"action\": \"create|list|trigger|delete|show|info|enable|disable|rename|duplicate|stats|history|dryrun|run-all|summary|step-config|suggest|health|quick|search|export|pin|unpin|reset-stats|notes|batch|tags|template|last|schedule|merge|optimize|swap|undo|dashboard|retry|clean|status|graph|recent|test-step|disable-step|enable-step|chain|analyze|snapshot|restore|validate|bulk-tag|compare-stats|recap|profile|bulk-action|dependencies|export-all|explain|timeline|estimate|watch|split|reorder|archive|unarchive|compact|go|diagnose|streak|focus|quick-create|overview|clone-steps|kpi|help-search|summary-all|benchmark|whatif|help\",\n"
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
            . "Agents disponibles (choisis le plus adapte pour chaque etape):\n"
            . "- chat : conversation generale, questions, reformulation\n"
            . "- dev : generation/revision de code, debugging, scripts\n"
            . "- todo : gestion de taches, listes, rappels de taches\n"
            . "- reminder : rappels temporels, alarmes, notifications planifiees\n"
            . "- event_reminder : evenements calendrier, reunions, rendez-vous\n"
            . "- finance : budgets, depenses, revenus, analyses financieres\n"
            . "- music : recommandations musicales, playlists, recherche artistes\n"
            . "- habit : suivi d'habitudes, streaks, objectifs quotidiens\n"
            . "- pomodoro : sessions de travail, timer, productivite\n"
            . "- content_summarizer : resume de texte, articles, documents\n"
            . "- code_review : revue de code, suggestions, bonnes pratiques\n"
            . "- web_search : recherche web, actualites, informations en ligne\n"
            . "- document : creation/edition de documents, notes structurees\n"
            . "- analysis : analyse de donnees, statistiques, insights\n"
            . "- streamline : sous-workflows, orchestration imbriquee\n"
            . "- interactive_quiz : quiz interactifs, tests de connaissances\n"
            . "- content_curator : curation de contenu, veille thematique, recommandations d'articles\n"
            . "- user_preferences : preferences utilisateur, parametres personnels, configuration du profil\n"
            . "- daily_brief : briefing quotidien, resume du jour, morning check, vue d'ensemble\n"
            . "- game_master : jeux interactifs, trivia, divertissement, quiz fun\n"
            . "Si l'agent n'est pas evident, laisse null (auto-detection).\n\n"
            . "REGLES DE DISAMBIGUATION AVANCEES:\n"
            . "- Si le message est vague (ex: 'workflow', 'aide'), utilise action=\"help\"\n"
            . "- Si le message mentionne un nom inexistant, cherche le workflow le plus similaire parmi les existants\n"
            . "- 'lance/run/go/execute/demarre' → trigger\n"
            . "- 'montre/affiche/voir/details' → show\n"
            . "- 'info/resume rapide/apercu/carte' → info\n"
            . "- 'explique/comment ca marche/c'est quoi' → explain\n"
            . "- 'analyse/rapport/performance' → analyze\n"
            . "- 'kpi/metriques/indicateurs/performance globale' → kpi\n"
            . "- 'clone etapes/copier toutes les etapes/repliquer etapes' → clone-steps\n"
            . "- 'resume d'activite/bilan/recap' → recap\n"
            . "- 'diagnostique/probleme/pourquoi ca marche pas/debug' → diagnose\n"
            . "- 'go/vite/rapide + nom' → go (trigger rapide du workflow le plus proche)\n"
            . "- 'benchmark/classement/ranking/performance globale' → benchmark\n"
            . "- 'que se passe si/impact/retrait/whatif + etape' → whatif\n\n"
            . "Actions supportees:\n"
            . "- create : creer un nouveau workflow (necessite name + steps)\n"
            . "- list : lister les workflows existants\n"
            . "- trigger : lancer/executer un workflow (necessite name)\n"
            . "- delete : supprimer un workflow (necessite name)\n"
            . "- show : afficher les details complets d'un workflow (necessite name)\n"
            . "- info : carte compacte avec metriques de performance (necessite name) — prefere info quand l'utilisateur dit 'info', 'resume rapide', 'apercu'\n"
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
            . "- chain : enchainer plusieurs workflows en sequence (name='nom1 nom2 nom3', max 5)\n"
            . "- analyze : analyse IA complete d'un workflow (performance, fiabilite, recommandations) — necessite name\n"
            . "- snapshot : sauvegarder un snapshot nomme de l'etat actuel du workflow (necessite name, reply=nom du snapshot optionnel)\n"
            . "- restore : restaurer un workflow depuis un snapshot (necessite name, reply=nom du snapshot ou 'list' pour voir les snapshots)\n"
            . "- validate : verifier l'integrite et la coherence d'un workflow (necessite name) — detecte etapes vides, agents invalides, problemes de conditions\n"
            . "- bulk-tag : appliquer un tag a plusieurs workflows (name='nom1 nom2 nom3', reply=tag a appliquer)\n"
            . "- compare-stats : comparer les statistiques de deux workflows cote a cote (name='nom1 nom2')\n"
            . "- recap : resume d'activite des workflows sur les N derniers jours (name=nombre de jours, defaut 7)\n"
            . "- profile : fiche detaillee d'un workflow avec score de performance, metriques, recommandations (necessite name)\n"
            . "- bulk-action : action en masse sur plusieurs workflows (name='action nom1 nom2...', actions: enable/disable/pin/unpin/delete)\n"
            . "- dependencies : carte des agents utilises par tous les workflows (pas de name requis)\n"
            . "- export-all : exporter tous les workflows en un seul texte pour backup (pas de name requis)\n"
            . "- explain : explication en langage simple de ce que fait un workflow (necessite name) — prefere quand l'utilisateur dit 'explique', 'comment ca marche', 'c'est quoi'\n"
            . "- timeline : chronologie visuelle des executions recentes (name optionnel pour filtrer par workflow)\n"
            . "- estimate : estimer la duree d'execution d'un workflow base sur l'historique (necessite name)\n"
            . "- watch : surveiller un workflow et recevoir un rapport apres chaque execution (necessite name, reply='on' ou 'off')\n"
            . "- rename-step : renommer le message d'une etape (necessite name, reply='numero nouveau_message')\n"
            . "- pause : pause/reprise temporaire d'un workflow — ignore par run-all et batch (necessite name, toggle)\n"
            . "- split : decouper un workflow en deux a partir d'une etape donnee (necessite name, reply=numero de l'etape ou couper)\n"
            . "- reorder : reorganiser les etapes d'un workflow (necessite name, reply=nouvel ordre ex: '3,1,2,4')\n"
            . "- archive : archiver un workflow sans le supprimer — masque de la liste (necessite name, ou name='list' pour voir les archives)\n"
            . "- unarchive : restaurer un workflow archive dans la liste active (necessite name)\n"
            . "- compact : vue compacte une-ligne-par-workflow de tous les workflows (pas de name requis)\n"
            . "- go : lancer immediatement le workflow epingle ou le plus utilise (name optionnel pour forcer un workflow specifique)\n"
            . "- diagnose : diagnostic complet d'un workflow problematique — analyse etapes, agents, erreurs, suggestions (necessite name)\n"
            . "- preflight : verification pre-lancement (statut, validation, derniere exec, duree estimee) — necessite name\n"
            . "- copy-step : copier une etape d'un workflow vers un autre (name='source num cible [position]')\n"
            . "- quick-create : creation rapide d'un workflow a partir d'un mot-cle de template (necessite name=morning|evening|weekly|review|standup|focus)\n"
            . "- overview : vue d'ensemble combinant serie, epingles, recents et actions rapides (pas de name requis)\n"
            . "- clone-steps : cloner toutes les etapes d'un workflow vers un autre (name='source cible', ecrase les etapes de la cible)\n"
            . "- kpi : tableau de bord KPI avec metriques cles de tous les workflows (name=nombre de jours optionnel, defaut 30)\n"
            . "- help-search : chercher une commande par mot-cle dans l'aide (necessite name=terme de recherche)\n"
            . "- summary-all : resume IA compact de tous les workflows (pas de name requis)\n"
            . "- benchmark : classement comparatif des workflows par performance (name=nombre optionnel, defaut 10)\n"
            . "- whatif : simuler l'impact du retrait d'une etape (necessite name, reply=numero de l'etape)\n"
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
            . "  \"info sur le workflow morning\" → {\"action\":\"info\",\"name\":\"morning\"}\n"
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
            . "  \"saute l'etape 1 du workflow morning\" → {\"action\":\"disable-step\",\"name\":\"morning\",\"reply\":\"1\"}\n"
            . "  \"enchaine morning-brief puis daily-check\" → {\"action\":\"chain\",\"name\":\"morning-brief daily-check\"}\n"
            . "  \"analyse le workflow morning-brief\" → {\"action\":\"analyze\",\"name\":\"morning-brief\"}\n"
            . "  \"rapport sur daily-check\" → {\"action\":\"analyze\",\"name\":\"daily-check\"}\n"
            . "  \"sauvegarde l'etat du workflow morning-brief\" → {\"action\":\"snapshot\",\"name\":\"morning-brief\"}\n"
            . "  \"snapshot morning-brief avant modifications\" → {\"action\":\"snapshot\",\"name\":\"morning-brief\",\"reply\":\"avant-modif\"}\n"
            . "  \"restaure le snapshot du workflow daily-check\" → {\"action\":\"restore\",\"name\":\"daily-check\",\"reply\":\"list\"}\n"
            . "  \"reviens au snapshot stable de morning-brief\" → {\"action\":\"restore\",\"name\":\"morning-brief\",\"reply\":\"stable\"}\n"
            . "  \"verifie le workflow morning-brief\" → {\"action\":\"validate\",\"name\":\"morning-brief\"}\n"
            . "  \"valide mon workflow daily-check\" → {\"action\":\"validate\",\"name\":\"daily-check\"}\n"
            . "  \"est-ce que mon workflow est correct\" → {\"action\":\"validate\",\"name\":\"...\"}\n"
            . "  \"taguer morning-brief et daily-check avec routine\" → {\"action\":\"bulk-tag\",\"name\":\"morning-brief daily-check\",\"reply\":\"routine\"}\n"
            . "  \"ajoute le tag urgent a tous mes workflows du matin\" → {\"action\":\"bulk-tag\",\"name\":\"morning-brief morning-check\",\"reply\":\"urgent\"}\n"
            . "  \"compare morning-brief et daily-check\" → {\"action\":\"compare-stats\",\"name\":\"morning-brief daily-check\"}\n"
            . "  \"lequel est le plus utilise entre morning et evening\" → {\"action\":\"compare-stats\",\"name\":\"morning evening\"}\n"
            . "  \"recap de mes workflows\" → {\"action\":\"recap\",\"name\":\"7\"}\n"
            . "  \"resume d'activite des 14 derniers jours\" → {\"action\":\"recap\",\"name\":\"14\"}\n"
            . "  \"bilan de mes workflows cette semaine\" → {\"action\":\"recap\",\"name\":\"7\"}\n"
            . "  \"profil du workflow morning-brief\" → {\"action\":\"profile\",\"name\":\"morning-brief\"}\n"
            . "  \"fiche detaillee de daily-check\" → {\"action\":\"profile\",\"name\":\"daily-check\"}\n"
            . "  \"score du workflow morning\" → {\"action\":\"profile\",\"name\":\"morning\"}\n"
            . "  \"active morning-brief et daily-check\" → {\"action\":\"bulk-action\",\"name\":\"enable morning-brief daily-check\"}\n"
            . "  \"desactive tous ces workflows: old-wf1 old-wf2\" → {\"action\":\"bulk-action\",\"name\":\"disable old-wf1 old-wf2\"}\n"
            . "  \"quels agents utilisent mes workflows\" → {\"action\":\"dependencies\"}\n"
            . "  \"carte des agents\" → {\"action\":\"dependencies\"}\n"
            . "  \"exporte tous mes workflows\" → {\"action\":\"export-all\"}\n"
            . "  \"backup de mes workflows\" → {\"action\":\"export-all\"}\n"
            . "  \"explique le workflow morning-brief\" → {\"action\":\"explain\",\"name\":\"morning-brief\"}\n"
            . "  \"comment marche le workflow daily-check\" → {\"action\":\"explain\",\"name\":\"daily-check\"}\n"
            . "  \"c'est quoi ce workflow morning\" → {\"action\":\"explain\",\"name\":\"morning\"}\n"
            . "  \"timeline de mes workflows\" → {\"action\":\"timeline\"}\n"
            . "  \"chronologie du workflow morning-brief\" → {\"action\":\"timeline\",\"name\":\"morning-brief\"}\n"
            . "  \"quand mes workflows ont ete lances\" → {\"action\":\"timeline\"}\n"
            . "  \"combien de temps prend le workflow morning-brief\" → {\"action\":\"estimate\",\"name\":\"morning-brief\"}\n"
            . "  \"estime la duree de daily-check\" → {\"action\":\"estimate\",\"name\":\"daily-check\"}\n"
            . "  \"surveille le workflow morning-brief\" → {\"action\":\"watch\",\"name\":\"morning-brief\",\"reply\":\"on\"}\n"
            . "  \"arrete de surveiller daily-check\" → {\"action\":\"watch\",\"name\":\"daily-check\",\"reply\":\"off\"}\n"
            . "  \"renomme l'etape 2 de morning-brief en Verifie les rappels urgents\" → {\"action\":\"rename-step\",\"name\":\"morning-brief\",\"reply\":\"2 Verifie les rappels urgents\"}\n"
            . "  \"change le message de l'etape 1 de daily-check\" → {\"action\":\"rename-step\",\"name\":\"daily-check\",\"reply\":\"1\"}\n"
            . "  \"met en pause le workflow morning-brief\" → {\"action\":\"pause\",\"name\":\"morning-brief\"}\n"
            . "  \"pause morning-brief\" → {\"action\":\"pause\",\"name\":\"morning-brief\"}\n"
            . "  \"reprends le workflow daily-check\" → {\"action\":\"pause\",\"name\":\"daily-check\"}\n"
            . "  \"decoupe morning-brief a l'etape 3\" → {\"action\":\"split\",\"name\":\"morning-brief\",\"reply\":\"3\"}\n"
            . "  \"separe le workflow daily-check apres l'etape 2\" → {\"action\":\"split\",\"name\":\"daily-check\",\"reply\":\"2\"}\n"
            . "  \"reorganise les etapes de morning-brief en 3,1,2\" → {\"action\":\"reorder\",\"name\":\"morning-brief\",\"reply\":\"3,1,2\"}\n"
            . "  \"change l'ordre des etapes de daily-check\" → {\"action\":\"reorder\",\"name\":\"daily-check\"}\n"
            . "  \"archive le workflow morning-brief\" → {\"action\":\"archive\",\"name\":\"morning-brief\"}\n"
            . "  \"mes workflows archives\" → {\"action\":\"archive\",\"name\":\"list\"}\n"
            . "  \"desarchive le workflow old-routine\" → {\"action\":\"unarchive\",\"name\":\"old-routine\"}\n"
            . "  \"vue compacte de mes workflows\" → {\"action\":\"compact\"}\n"
            . "  \"apercu rapide\" → {\"action\":\"compact\"}\n"
            . "  \"go\" → {\"action\":\"go\"}\n"
            . "  \"lance vite mon workflow\" → {\"action\":\"go\"}\n"
            . "  \"go morning-brief\" → {\"action\":\"go\",\"name\":\"morning-brief\"}\n"
            . "  \"diagnostique le workflow morning-brief\" → {\"action\":\"diagnose\",\"name\":\"morning-brief\"}\n"
            . "  \"pourquoi mon workflow marche pas\" → {\"action\":\"diagnose\",\"name\":\"...\"}\n"
            . "  \"debug daily-check\" → {\"action\":\"diagnose\",\"name\":\"daily-check\"}\n"
            . "  \"ma serie\" → {\"action\":\"streak\"}\n"
            . "  \"jours consecutifs\" → {\"action\":\"streak\"}\n"
            . "  \"streak workflows\" → {\"action\":\"streak\"}\n"
            . "  \"focus productivite\" → {\"action\":\"focus\",\"name\":\"productivite\"}\n"
            . "  \"workflows par tag finance\" → {\"action\":\"focus\",\"name\":\"finance\"}\n"
            . "  \"filtrer par tag\" → {\"action\":\"focus\"}\n"
            . "  \"cree-moi vite un workflow du matin\" → {\"action\":\"quick-create\",\"name\":\"morning\"}\n"
            . "  \"quick create evening\" → {\"action\":\"quick-create\",\"name\":\"evening\"}\n"
            . "  \"creation rapide standup\" → {\"action\":\"quick-create\",\"name\":\"standup\"}\n"
            . "  \"vue d'ensemble\" → {\"action\":\"overview\"}\n"
            . "  \"overview de mes workflows\" → {\"action\":\"overview\"}\n"
            . "  \"cherche la commande pour exporter\" → {\"action\":\"help-search\",\"name\":\"export\"}\n"
            . "  \"quelle commande pour les etapes\" → {\"action\":\"help-search\",\"name\":\"etape\"}\n"
            . "  \"resume de tous mes workflows\" → {\"action\":\"summary-all\"}\n"
            . "  \"resume global\" → {\"action\":\"summary-all\"}\n"
            . "  \"benchmark de mes workflows\" → {\"action\":\"benchmark\"}\n"
            . "  \"classement de mes workflows\" → {\"action\":\"benchmark\"}\n"
            . "  \"quel workflow est le plus performant\" → {\"action\":\"benchmark\"}\n"
            . "  \"que se passe-t-il si je retire l'etape 2 de morning-brief\" → {\"action\":\"whatif\",\"name\":\"morning-brief\",\"reply\":\"2\"}\n"
            . "  \"impact de supprimer l'etape 3 de daily-check\" → {\"action\":\"whatif\",\"name\":\"daily-check\",\"reply\":\"3\"}\n"
            . "  \"simule le retrait de l'etape 1\" → {\"action\":\"whatif\",\"name\":\"...\",\"reply\":\"1\"}",
            self::NLU_MAX_TOKENS
        );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: NLU LLM call failed", [
                'from'  => $context->from,
                'error' => $e->getMessage(),
                'body'  => mb_substr($body, 0, 100),
            ]);
            $response = null;
        }

        if ($response === null) {
            Log::warning("StreamlineAgent: Claude API returned null (service unavailable)", [
                'user'         => $context->from,
                'body_preview' => mb_substr($body, 0, 100),
            ]);
            return AgentResult::reply(
                "⚠ Le service IA est temporairement indisponible.\n\n"
                . "En attendant, utilise les commandes directes:\n"
                . "  /workflow list\n"
                . "  /workflow trigger [nom]\n"
                . "  /workflow help"
            );
        }

        $parsed = $this->parseJson($response);

        // Self-healing: if JSON parse fails, ask Claude to fix its own broken output
        if (!$parsed || !isset($parsed['action'])) {
            Log::warning("StreamlineAgent: NLU JSON parse failed, attempting self-heal", [
                'response_preview' => mb_substr($response ?? '', 0, 300),
                'user'             => $context->from,
            ]);

            try {
                $fixResponse = $this->claude->chat(
                    "Ta reponse precedente n'est PAS du JSON valide:\n\n" . mb_substr($response, 0, 500)
                    . "\n\nCorrige et renvoie UNIQUEMENT le JSON corrige, sans markdown ni backtick.",
                    $model,
                    "Tu corriges du JSON invalide. Renvoie UNIQUEMENT un objet JSON valide avec au minimum un champ \"action\".",
                    self::SELF_HEAL_MAX_TOKENS
                );
                $parsed = $this->parseJson($fixResponse);
                if ($parsed && isset($parsed['action'])) {
                    $this->log($context, 'NLU self-heal succeeded', ['action' => $parsed['action']]);
                }
            } catch (\Throwable $selfHealEx) {
                Log::debug("StreamlineAgent: self-heal also failed", ['error' => $selfHealEx->getMessage()]);
            }
        }

        if (!$parsed || !isset($parsed['action'])) {
            Log::warning("StreamlineAgent: NLU JSON parse failed after self-heal", [
                'user'         => $context->from,
                'body_preview' => mb_substr($body, 0, 100),
            ]);
            $workflowHint = $userWorkflows
                ? "\n\n*Tes workflows:* {$userWorkflows}"
                : '';
            return AgentResult::reply(
                "Je n'ai pas compris ta demande de workflow.\n\n"
                . "*Commandes courantes:*\n"
                . "  /workflow create [nom] [etape1] then [etape2]\n"
                . "  /workflow list\n"
                . "  /workflow trigger [nom]\n"
                . "  /workflow dashboard\n"
                . "  /workflow help\n\n"
                . "_Astuce: utilise /workflow help [categorie] pour plus de details._"
                . $workflowHint
            );
        }

        return match ($parsed['action']) {
            'create'      => $this->handleParsedCreate($context, $parsed),
            'list'        => $this->commandList($context, $parsed['name'] ?? ''),
            'trigger'     => $this->commandTrigger($context, $parsed['name'] ?? '', $parsed['input'] ?? ''),
            'delete'      => $this->commandDelete($context, $parsed['name'] ?? ''),
            'show'        => $this->commandShow($context, $parsed['name'] ?? ''),
            'info'        => $this->commandInfo($context, $parsed['name'] ?? ''),
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
            'chain'        => $this->commandChain($context, $parsed['name'] ?? ''),
            'analyze'      => $this->commandAnalyze($context, $parsed['name'] ?? ''),
            'snapshot'     => $this->commandSnapshot($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'restore'      => $this->commandRestore($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'validate'      => $this->commandValidate($context, $parsed['name'] ?? ''),
            'bulk-tag'      => $this->commandBulkTag($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'compare-stats' => $this->handleCompareStatsFromNLU($context, $parsed['name'] ?? ''),
            'recap'         => $this->commandRecap($context, $parsed['name'] ?? ''),
            'profile'       => $this->commandProfile($context, $parsed['name'] ?? ''),
            'bulk-action'   => $this->commandBulkAction($context, $parsed['name'] ?? ''),
            'explain'       => $this->commandExplain($context, $parsed['name'] ?? ''),
            'timeline'      => $this->commandTimeline($context, $parsed['name'] ?? ''),
            'dependencies'  => $this->commandDependencies($context),
            'export-all'    => $this->commandExportAll($context),
            'estimate'      => $this->commandEstimate($context, $parsed['name'] ?? ''),
            'watch'         => $this->commandWatch($context, $parsed['name'] ?? '', $parsed['reply'] ?? 'on'),
            'rename-step'   => $this->commandRenameStep($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'pause'         => $this->commandPause($context, $parsed['name'] ?? ''),
            'split'         => $this->commandSplit($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'reorder'       => $this->commandReorder($context, $parsed['name'] ?? '', $parsed['reply'] ?? ''),
            'archive'       => $this->commandArchive($context, $parsed['name'] ?? '', true),
            'unarchive'     => $this->commandArchive($context, $parsed['name'] ?? '', false),
            'compact'       => $this->commandCompact($context),
            'go'            => $this->commandGo($context, $parsed['name'] ?? ''),
            'diagnose'      => $this->commandDiagnose($context, $parsed['name'] ?? ''),
            'preflight'     => $this->commandPreflight($context, $parsed['name'] ?? ''),
            'streak'        => $this->commandStreak($context),
            'focus'         => $this->commandFocus($context, $parsed['name'] ?? ''),
            'quick-create'  => $this->commandQuickCreate($context, $parsed['name'] ?? ''),
            'overview'      => $this->commandOverview($context),
            'help-search'   => $this->commandHelpSearch($context, $parsed['name'] ?? ''),
            'summary-all'   => $this->commandSummaryAll($context),
            'copy-step'     => $this->commandCopyStep($context, $parsed['name'] ?? ''),
            'preview'       => $this->commandTrigger($context, $parsed['name'] ?? '', '--preview ' . ($parsed['input'] ?? '')),
            default         => AgentResult::reply($parsed['reply'] ?? $this->getHelpText()),
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

        if (count($data['steps']) > 10) {
            return AgentResult::reply("Un workflow peut contenir au maximum 10 etapes (recu: " . count($data['steps']) . ").\nDecoupe ton workflow en plusieurs plus petits.");
        }

        $validAgents = ['chat', 'dev', 'todo', 'reminder', 'event_reminder', 'finance', 'music', 'habit', 'pomodoro', 'content_summarizer', 'code_review', 'web_search', 'document', 'analysis', 'streamline', 'interactive_quiz', 'content_curator', 'user_preferences'];

        // Normalize step fields with defaults + validation
        $data['steps'] = array_map(function (array $step) use ($validAgents) {
            $agent = $step['agent'] ?? null;
            if ($agent && !in_array($agent, $validAgents, true)) {
                $agent = null; // Fallback to auto-detection for invalid agent
            }
            return [
                'message'   => trim($step['message'] ?? '') ?: 'Action a definir',
                'agent'     => $agent,
                'condition' => in_array($step['condition'] ?? '', ['always', 'success', 'contains', 'not_contains'], true)
                    || str_starts_with($step['condition'] ?? '', 'contains:')
                    || str_starts_with($step['condition'] ?? '', 'not_contains:')
                    ? ($step['condition'] ?? 'always')
                    : 'always',
                'on_error'  => in_array($step['on_error'] ?? '', ['stop', 'continue'], true) ? $step['on_error'] : 'stop',
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
        // Throttle: prevent re-triggering same workflow within 10 seconds
        if ($workflow->last_run_at && $workflow->last_run_at->diffInSeconds(now()) < 10) {
            $wait = 10 - $workflow->last_run_at->diffInSeconds(now());
            return AgentResult::reply(
                "⏳ Le workflow *{$workflow->name}* a ete lance il y a moins de 10s.\n"
                . "Attends ~{$wait}s ou utilise /workflow dryrun {$workflow->name} pour simuler."
            );
        }

        $stepCount = count($workflow->steps ?? []);
        $inputHint = $input ? " (contexte: _" . mb_substr($input, 0, 40) . "_)" : '';
        $this->sendText($context->from, "▶️ *Lancement:* \"{$workflow->name}\" ({$stepCount} etape" . ($stepCount > 1 ? 's' : '') . "){$inputHint}...");
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

        $startTime = microtime(true);

        try {
            $orchestrator    = new AgentOrchestrator();
            $executor        = new WorkflowExecutor($orchestrator);
            $executionResult = $executor->execute($workflowToRun, $context);

            $elapsed = round(microtime(true) - $startTime, 1);
            $this->log($context, "Workflow completed: {$workflow->name}", [
                'id'       => $workflow->id,
                'duration' => $elapsed,
                'steps'    => $stepCount,
            ]);

            // Track execution duration and history in conditions for analytics
            try {
                $conditions = $workflow->conditions ?? [];
                $conditions['last_duration'] = $elapsed;
                $durations = $conditions['durations'] ?? [];
                $durations[] = $elapsed;
                $conditions['durations'] = array_slice($durations, -10); // keep last 10

                // Track execution history for timeline
                $history = $conditions['history'] ?? [];
                $history[] = [
                    'date'   => now()->format('d/m H:i'),
                    'status' => 'success',
                    'input'  => $input ? mb_substr($input, 0, 50) : null,
                    'duration' => $elapsed,
                ];
                $conditions['history'] = array_slice($history, -20); // keep last 20

                $workflow->update(['conditions' => $conditions]);
            } catch (\Throwable) {
                // Non-critical, ignore
            }

            // Send watch report if watch mode is enabled
            if (!empty($workflow->conditions['watch'])) {
                $this->sendWatchReport($context, $workflow, $executionResult, $elapsed);
            }

            return AgentResult::reply(
                WorkflowExecutor::formatResults($executionResult),
                ['workflow_execution' => $executionResult, 'duration' => $elapsed]
            );
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $startTime, 1);
            $errorMsg = $e->getMessage();
            Log::error("StreamlineAgent: workflow execution failed", [
                'workflow' => $workflow->name,
                'error'    => $errorMsg,
                'duration' => $elapsed,
                'file'     => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            $this->log($context, "Workflow failed: {$workflow->name}", [
                'error'    => mb_substr($errorMsg, 0, 200),
                'duration' => $elapsed,
            ], 'error');

            // Track failure in execution history
            try {
                $conditions = $workflow->conditions ?? [];
                $history = $conditions['history'] ?? [];
                $history[] = [
                    'date'   => now()->format('d/m H:i'),
                    'status' => 'failed',
                    'input'  => $input ? mb_substr($input, 0, 50) : null,
                    'duration' => $elapsed,
                    'error'  => mb_substr($errorMsg, 0, 80),
                ];
                $conditions['history'] = array_slice($history, -20);
                $workflow->update(['conditions' => $conditions]);
            } catch (\Throwable) {
                // Non-critical
            }

            $isTimeout = stripos($errorMsg, 'timeout') !== false
                || stripos($errorMsg, 'timed out') !== false
                || $e instanceof \Illuminate\Http\Client\ConnectionException;
            $isDbError = $e instanceof \Illuminate\Database\QueryException;

            $wfName = $workflow->name;
            if ($isTimeout) {
                $reply = "⚠ *Timeout* — \"{$wfName}\" a expire apres {$elapsed}s.\n"
                    . str_repeat('─', 24) . "\n"
                    . "Certaines etapes sont peut-etre trop longues.\n\n"
                    . "*Actions suggerees:*\n"
                    . "  /workflow validate {$wfName}\n"
                    . "  /workflow test-step {$wfName} [N]\n"
                    . "  /workflow dryrun {$wfName}";
            } elseif ($isDbError) {
                $reply = "⚠ *Erreur BDD* — \"{$wfName}\" (apres {$elapsed}s)\n"
                    . str_repeat('─', 24) . "\n"
                    . "Erreur temporaire de base de donnees.\n\n"
                    . "Reessaie: /workflow retry {$wfName}";
            } else {
                $reply = "⚠ *Erreur* — \"{$wfName}\" (apres {$elapsed}s)\n"
                    . str_repeat('─', 24) . "\n"
                    . mb_substr($errorMsg, 0, 120) . "\n\n"
                    . "*Actions suggerees:*\n"
                    . "  /workflow show {$wfName}\n"
                    . "  /workflow validate {$wfName}\n"
                    . "  /workflow test-step {$wfName} [N]";
            }

            return AgentResult::reply($reply);
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
                "✅ Workflow *\"{$workflow->name}\"* cree avec *{$stepCount}* etape" . ($stepCount > 1 ? 's' : '') . ".\n\n"
                . "▶️ /workflow trigger {$workflow->name}\n"
                . "📋 /workflow show {$workflow->name}\n"
                . "🧪 /workflow dryrun {$workflow->name}"
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

        // 4. Levenshtein fuzzy match for typos (max distance 2, only if unique best match)
        if ($partialMatches->isEmpty()) {
            $allWorkflows = Workflow::forUser($userPhone)->pluck('name');
            $bestMatch    = null;
            $bestDist     = 3; // threshold

            foreach ($allWorkflows as $wfName) {
                $dist = levenshtein($lowerName, mb_strtolower($wfName));
                if ($dist < $bestDist) {
                    $bestDist  = $dist;
                    $bestMatch = $wfName;
                } elseif ($dist === $bestDist) {
                    $bestMatch = null; // ambiguous
                }
            }

            if ($bestMatch) {
                return Workflow::forUser($userPhone)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($bestMatch)])
                    ->first();
            }
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
            // Try Levenshtein suggestion
            $allNames  = Workflow::forUser($userPhone)->pluck('name');
            $suggest   = null;
            $bestDist  = 4;
            foreach ($allNames as $wfName) {
                $dist = levenshtein($lowerName, mb_strtolower($wfName));
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $suggest  = $wfName;
                }
            }
            $hint = $suggest ? "\nTu voulais peut-etre: *{$suggest}* ?" : '';
            return [null, AgentResult::reply(
                "Workflow \"{$name}\" introuvable.{$hint}\n"
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
            'interactive_quiz'   => 'InteractiveQuizAgent',
            'content_curator'    => 'ContentCuratorAgent',
            'user_preferences'   => 'UserPreferencesAgent',
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

            $isSkipped = !empty($step['_skip']) || !empty($step['disabled']);
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

        // Execution time estimate based on historical data
        $durations = $workflow->conditions['durations'] ?? [];
        if (!empty($durations)) {
            $avgDuration = round(array_sum($durations) / count($durations), 1);
            $lines[] = '';
            $lines[] = "⏱ Duree estimee: ~{$avgDuration}s (base sur " . count($durations) . " exec.)";
        } else {
            $activeSteps = count(array_filter($steps, fn($s) => empty($s['_skip']) && empty($s['disabled'])));
            $estimatedTime = $activeSteps * 5; // ~5s per step rough estimate
            $lines[] = '';
            $lines[] = "⏱ Duree estimee: ~{$estimatedTime}s ({$activeSteps} etape" . ($activeSteps > 1 ? 's' : '') . " active" . ($activeSteps > 1 ? 's' : '') . ")";
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

        // Skip paused workflows in run-all
        $paused = $workflows->filter(fn($wf) => !empty($wf->conditions['paused']));
        $workflows = $workflows->filter(fn($wf) => empty($wf->conditions['paused']));

        if ($workflows->isEmpty()) {
            $pauseHint = $paused->isNotEmpty()
                ? "\n\n_" . $paused->count() . " workflow(s) en pause ignore(s). /workflow pause [nom] pour reprendre._"
                : '';
            $msg = !empty($tagFilter)
                ? "Aucun workflow actif avec le tag #{$tagFilter}.{$pauseHint}\n\nVoir les tags: /workflow tags"
                : "Aucun workflow actif.{$pauseHint}\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]";
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

            $statusIcon = $workflow->is_active ? '✅' : '⏸';

            return AgentResult::reply(
                "*📖 Resume — {$workflow->name}*\n"
                . str_repeat('─', 26) . "\n\n"
                . trim($summary) . "\n\n"
                . str_repeat('─', 26) . "\n"
                . "{$statusIcon} {$stepCount} etape" . ($stepCount > 1 ? 's' : '') . " · {$workflow->run_count} exec. · Dernier: {$lastRun}\n\n"
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
                . "Agents disponibles: chat, dev, todo, reminder, event_reminder, finance, music, habit, pomodoro, content_summarizer, code_review, web_search, document, analysis, streamline, interactive_quiz, content_curator, user_preferences, daily_brief, game_master\n\n"
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

        $scoreEmoji = $healthScore >= 80 ? '🟢' : ($healthScore >= 50 ? '🟡' : '🔴');

        $lines = [
            "*🏥 Sante des workflows* — {$scoreEmoji} {$healthScore}%",
            str_repeat('─', 30),
            "*{$total}* workflows · " . count($healthy) . " sains · {$totalIssues} probleme" . ($totalIssues > 1 ? 's' : ''),
        ];

        if (!empty($broken)) {
            $lines[] = '';
            $lines[] = "🔴 *Sans etapes* (" . count($broken) . "):";
            foreach ($broken as $wf) {
                $lines[] = "  · {$wf->name} — /workflow delete {$wf->name}";
            }
        }

        if (!empty($unused)) {
            $lines[] = '';
            $lines[] = "⚠ *Jamais lances* depuis +7j (" . count($unused) . "):";
            foreach ($unused as $wf) {
                $ageDays = $wf->created_at->diffInDays($now);
                $lines[] = "  · *{$wf->name}* (cree il y a {$ageDays}j) — /workflow trigger {$wf->name}";
            }
        }

        if (!empty($stale)) {
            $lines[] = '';
            $lines[] = "🕰 *Dormants* +30j (" . count($stale) . "):";
            foreach ($stale as $wf) {
                $staleDays = $wf->last_run_at->diffInDays($now);
                $lines[] = "  · *{$wf->name}* (dernier: il y a {$staleDays}j) — /workflow trigger {$wf->name}";
            }
        }

        if (!empty($disabled)) {
            $lines[] = '';
            $lines[] = "⏸ *Desactives* (" . count($disabled) . "):";
            foreach ($disabled as $wf) {
                $lines[] = "  · *{$wf->name}* — /workflow enable {$wf->name}";
            }
        }

        if ($totalIssues === 0) {
            $lines[] = '';
            $lines[] = "✅ Tous tes workflows sont actifs et en bonne sante.";
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
                . "Agents disponibles: chat, dev, todo, reminder, event_reminder, finance, music, habit, pomodoro, content_summarizer, code_review, web_search, document, analysis, streamline, interactive_quiz, content_curator, user_preferences, daily_brief, game_master\n\n"
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

        // Average execution time from tracked durations
        $avgDuration = '';
        $allDurations = $workflows->flatMap(fn($wf) => $wf->conditions['durations'] ?? []);
        if ($allDurations->isNotEmpty()) {
            $avg = round($allDurations->avg(), 1);
            $avgDuration = " · Moy: {$avg}s";
        }

        $healthIcon = $healthy >= 80 ? '🟢' : ($healthy >= 50 ? '🟡' : '🔴');
        $lines = [
            "*📋 Dashboard Workflows*",
            str_repeat('═', 30),
            "",
            "{$healthIcon} Sante: {$healthBar} *{$healthy}%*",
            "📊 Total: *{$total}* · Actifs: *{$active}* · Exec: *{$totalRuns}*{$avgDuration}",
        ];

        // Pinned workflows (quick access)
        if ($pinned->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "*📍 Epingles:*";
            foreach ($pinned->take(3) as $wf) {
                $stepCount = count($wf->steps ?? []);
                $lastRun   = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : 'jamais';
                $lines[]   = "  ★ *{$wf->name}* ({$stepCount} et. · {$lastRun})";
            }
        }

        // Recent activity (last 3 executed)
        $recent = $workflows->whereNotNull('last_run_at')->sortByDesc('last_run_at')->take(3);
        if ($recent->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "*🕐 Recents:*";
            foreach ($recent as $wf) {
                $ago = $wf->last_run_at->diffForHumans();
                $lines[] = "  · *{$wf->name}* — {$ago} ({$wf->run_count}x)";
            }
        }

        // Top 3 most used
        $top = $workflows->where('run_count', '>', 0)->sortByDesc('run_count')->take(3);
        if ($top->isNotEmpty() && $top->first()->run_count > 1) {
            $lines[] = '';
            $lines[] = "*🏆 Top:*";
            foreach ($top->values() as $i => $wf) {
                $medal = match ($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉', default => '' };
                $lines[] = "  {$medal} *{$wf->name}* — {$wf->run_count} exec.";
            }
        }

        // Issues summary
        $issues = $broken->count() + $stale->count();
        if ($issues > 0) {
            $lines[] = '';
            $parts = [];
            if ($broken->isNotEmpty()) $parts[] = $broken->count() . " sans etapes";
            if ($stale->isNotEmpty()) $parts[] = $stale->count() . " inactifs >30j";
            $lines[] = "⚠️ *Problemes:* " . implode(', ', $parts) . " → /workflow health";
        }

        // Paused workflows
        $paused = $workflows->filter(fn($wf) => !empty($wf->conditions['paused'] ?? false));
        if ($paused->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "*⏸ En pause:* " . $paused->count();
            foreach ($paused->take(3) as $wf) {
                $lines[] = "  ⏸ *{$wf->name}* → /workflow pause {$wf->name} (reprendre)";
            }
            if ($paused->count() > 3) {
                $lines[] = "  + " . ($paused->count() - 3) . " autre(s)";
            }
        }

        // Scheduled workflows
        $scheduled = $workflows->filter(fn($wf) => !empty($wf->conditions['schedule'] ?? null));
        if ($scheduled->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "*⏰ Planifies:* " . $scheduled->count();
            foreach ($scheduled->take(2) as $wf) {
                $desc = $wf->conditions['schedule']['description'] ?? '?';
                $lines[] = "  ⏰ *{$wf->name}* — {$desc}";
            }
        }

        // Execution trend (last 7 days vs previous 7 days)
        $recentlyExecuted = $workflows->filter(fn($wf) =>
            $wf->last_run_at && $wf->last_run_at->gte(now()->subDays(7))
        )->count();
        $olderExecuted = $workflows->filter(fn($wf) =>
            $wf->last_run_at && $wf->last_run_at->gte(now()->subDays(14)) && $wf->last_run_at->lt(now()->subDays(7))
        )->count();
        if ($recentlyExecuted > 0 || $olderExecuted > 0) {
            $trend = $recentlyExecuted > $olderExecuted ? '📈' : ($recentlyExecuted < $olderExecuted ? '📉' : '➡️');
            $lines[] = '';
            $lines[] = "{$trend} *Tendance 7j:* {$recentlyExecuted} exec. (vs {$olderExecuted} sem. precedente)";
        }

        $lines[] = '';
        $lines[] = str_repeat('═', 30);
        $lines[] = "/workflow last      — relancer dernier";
        $lines[] = "/workflow quick [x]  — chercher & lancer";
        $lines[] = "/workflow profile [x] — fiche detaillee";
        $lines[] = "/workflow stats     — stats detaillees";

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
            [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
            if ($errResult) return $errResult;
            if (!$workflow) {
                return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
            }

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
     * Save a named snapshot of a workflow's current state.
     * Usage: /workflow snapshot [name] [snapshot-label?]
     */
    private function commandSnapshot(AgentContext $context, string $name, string $label = ''): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow snapshot [nom] [label?]\n\n"
                . "Exemples:\n"
                . "  /workflow snapshot morning-brief\n"
                . "  /workflow snapshot morning-brief avant-modif"
            );
        }

        [$workflow, $errorResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errorResult) return $errorResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nUtilise /workflow list pour voir tes workflows.");
        }

        $label = trim($label);
        if (empty($label)) {
            $label = 'snap-' . now()->format('Ymd-His');
        }

        $label = mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $label));
        $label = trim($label, '-');
        if (empty($label)) {
            $label = 'snap-' . now()->format('Ymd-His');
        }

        $conditions = $workflow->conditions ?? [];
        $snapshots = $conditions['snapshots'] ?? [];

        // Limit to 5 snapshots per workflow
        if (count($snapshots) >= 5) {
            array_shift($snapshots);
        }

        $snapshots[$label] = [
            'steps'      => $workflow->steps ?? [],
            'is_active'  => $workflow->is_active,
            'created_at' => now()->toIso8601String(),
        ];

        $conditions['snapshots'] = $snapshots;

        try {
            $workflow->update(['conditions' => $conditions]);
            $this->log($context, "Snapshot saved: {$workflow->name}/{$label}", [
                'workflow_id' => $workflow->id,
                'label'       => $label,
                'steps'       => count($workflow->steps ?? []),
            ]);

            $snapshotCount = count($snapshots);
            return AgentResult::reply(
                "Snapshot \"{$label}\" sauvegarde pour \"{$workflow->name}\".\n"
                . "({$snapshotCount}/5 snapshots)\n\n"
                . "Restaurer: /workflow restore {$workflow->name} {$label}\n"
                . "Voir tous: /workflow restore {$workflow->name} list"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: snapshot failed", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply("Erreur lors de la sauvegarde du snapshot. Reessaie.");
        }
    }

    /**
     * Restore a workflow from a named snapshot.
     * Usage: /workflow restore [name] [snapshot-label|list]
     */
    private function commandRestore(AgentContext $context, string $name, string $label = ''): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "Usage: /workflow restore [nom] [label|list]\n\n"
                . "Exemples:\n"
                . "  /workflow restore morning-brief list\n"
                . "  /workflow restore morning-brief avant-modif"
            );
        }

        [$workflow, $errorResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errorResult) return $errorResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nUtilise /workflow list pour voir tes workflows.");
        }

        $conditions = $workflow->conditions ?? [];
        $snapshots = $conditions['snapshots'] ?? [];

        if (empty($snapshots)) {
            return AgentResult::reply(
                "Aucun snapshot pour \"{$workflow->name}\".\n\n"
                . "Creer un snapshot: /workflow snapshot {$workflow->name} [label]"
            );
        }

        $label = mb_strtolower(trim($label));

        // List snapshots
        if (empty($label) || $label === 'list' || $label === 'liste') {
            $lines = [
                "*Snapshots — {$workflow->name}*",
                str_repeat('─', 25),
            ];
            foreach ($snapshots as $sLabel => $snap) {
                $stepCount = count($snap['steps'] ?? []);
                $date = isset($snap['created_at']) ? \Carbon\Carbon::parse($snap['created_at'])->diffForHumans() : '?';
                $lines[] = "  · *{$sLabel}* — {$stepCount} etapes · {$date}";
            }
            $lines[] = '';
            $lines[] = "/workflow restore {$workflow->name} [label]";
            return AgentResult::reply(implode("\n", $lines));
        }

        // Find snapshot by label (exact or partial match)
        $snapshot = $snapshots[$label] ?? null;
        if (!$snapshot) {
            foreach ($snapshots as $sLabel => $snap) {
                if (str_contains($sLabel, $label)) {
                    $snapshot = $snap;
                    $label = $sLabel;
                    break;
                }
            }
        }

        if (!$snapshot) {
            $available = implode(', ', array_keys($snapshots));
            return AgentResult::reply(
                "Snapshot \"{$label}\" introuvable pour \"{$workflow->name}\".\n"
                . "Disponibles: {$available}\n\n"
                . "Voir: /workflow restore {$workflow->name} list"
            );
        }

        try {
            $this->backupSteps($workflow);
            $oldStepCount = count($workflow->steps ?? []);
            $newSteps = $snapshot['steps'] ?? [];
            $workflow->update([
                'steps'     => $newSteps,
                'is_active' => $snapshot['is_active'] ?? $workflow->is_active,
            ]);

            $this->log($context, "Snapshot restored: {$workflow->name}/{$label}", [
                'workflow_id' => $workflow->id,
                'label'       => $label,
                'old_steps'   => $oldStepCount,
                'new_steps'   => count($newSteps),
            ]);

            return AgentResult::reply(
                "Snapshot \"{$label}\" restaure pour \"{$workflow->name}\".\n"
                . "Etapes: {$oldStepCount} → " . count($newSteps) . "\n\n"
                . "Voir: /workflow show {$workflow->name}\n"
                . "Annuler: /workflow undo {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: restore failed", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'label'    => $label,
            ]);
            return AgentResult::reply("Erreur lors de la restauration du snapshot. Reessaie.");
        }
    }

    /**
     * Validate a workflow's integrity: empty steps, invalid agents, condition issues.
     */
    private function commandValidate(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "*Validation de workflow*\n\n"
                . "Verifie les problemes courants: etapes vides, agents invalides, doublons, conditions, etc.\n\n"
                . "Utilisation: /workflow validate [nom]\n"
                . "Exemple: /workflow validate morning-brief"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $steps  = $workflow->steps ?? [];
        $issues = [];
        $warnings = [];

        if (empty($steps)) {
            $issues[] = "Le workflow n'a aucune etape";
        }

        $knownAgents = [
            'chat', 'dev', 'todo', 'reminder', 'event_reminder', 'finance', 'music',
            'habit', 'pomodoro', 'content_summarizer', 'code_review', 'web_search',
            'document', 'analysis', 'streamline', 'interactive_quiz', 'content_curator',
            'user_preferences', 'recipe', 'flashcard', 'budget_tracker', 'project',
            'daily_brief', 'smart_meeting', 'mood_check', 'game_master', 'hangman',
            'time_blocker', 'screenshot', 'voice_command', 'collaborative_task', 'assistant',
        ];

        $validConditions = ['always', 'success'];

        foreach ($steps as $i => $step) {
            $num = $i + 1;
            $msg = trim($step['message'] ?? '');

            if (empty($msg)) {
                $issues[] = "Etape {$num}: message vide";
            } elseif (mb_strlen($msg) < 5) {
                $warnings[] = "Etape {$num}: message tres court (\"{$msg}\")";
            }

            $agent = $step['agent'] ?? null;
            if ($agent !== null && !in_array($agent, $knownAgents, true)) {
                $issues[] = "Etape {$num}: agent \"{$agent}\" inconnu";
            }

            $condition = $step['condition'] ?? 'always';
            if (!in_array($condition, $validConditions, true) && !str_starts_with($condition, 'contains:') && !str_starts_with($condition, 'not_contains:')) {
                $issues[] = "Etape {$num}: condition \"{$condition}\" invalide";
            }

            if ($condition === 'success' && $i === 0) {
                $warnings[] = "Etape 1: condition \"success\" n'a pas de sens sur la premiere etape";
            }

            $onError = $step['on_error'] ?? 'stop';
            if (!in_array($onError, ['stop', 'continue'], true)) {
                $issues[] = "Etape {$num}: on_error \"{$onError}\" invalide (stop|continue)";
            }

            $disabled = $step['disabled'] ?? false;
            if ($disabled) {
                $warnings[] = "Etape {$num}: desactivee";
            }
        }

        if (count($steps) > 10) {
            $warnings[] = "Le workflow a " . count($steps) . " etapes (recommande: max 10)";
        }

        // Detect duplicate steps (same message content)
        $messages = [];
        foreach ($steps as $i => $step) {
            $msg = mb_strtolower(trim($step['message'] ?? ''));
            if (!empty($msg) && isset($messages[$msg])) {
                $warnings[] = "Etape " . ($i + 1) . ": doublon de l'etape " . ($messages[$msg] + 1) . " — \"" . mb_substr($step['message'], 0, 40) . "\"";
            } else {
                $messages[$msg] = $i;
            }
        }

        // Detect all steps with on_error=continue (risky)
        $continueCount = count(array_filter($steps, fn($s) => ($s['on_error'] ?? 'stop') === 'continue'));
        if ($continueCount === count($steps) && count($steps) > 1) {
            $warnings[] = "Toutes les etapes ont on_error=continue — les erreurs seront silencieuses";
        }

        if (empty($workflow->description)) {
            $warnings[] = "Pas de description — ajoute-en une avec: /workflow describe {$workflow->name} [texte]";
        }

        $this->log($context, "Workflow validated: {$workflow->name}", [
            'issues'   => count($issues),
            'warnings' => count($warnings),
        ]);

        $stepCount = count($steps);
        $lines = ["*Validation: {$workflow->name}* ({$stepCount} etape" . ($stepCount > 1 ? 's' : '') . ")\n"];

        if (empty($issues) && empty($warnings)) {
            $lines[] = "Aucun probleme detecte. Le workflow est valide.";
            $lines[] = "\nLancer: /workflow trigger {$workflow->name}";
            $lines[] = "Simuler: /workflow dryrun {$workflow->name}";
        } else {
            if (!empty($issues)) {
                $lines[] = "*Erreurs (" . count($issues) . "):*";
                foreach ($issues as $issue) {
                    $lines[] = "  [ERR] {$issue}";
                }
            }
            if (!empty($warnings)) {
                if (!empty($issues)) $lines[] = '';
                $lines[] = "*Avertissements (" . count($warnings) . "):*";
                foreach ($warnings as $warning) {
                    $lines[] = "  [!] {$warning}";
                }
            }
            $lines[] = "\nCorrections: /workflow edit, /workflow step-config, /workflow remove-step";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Apply a tag to multiple workflows at once.
     */
    private function commandBulkTag(AgentContext $context, string $names, string $tag = ''): AgentResult
    {
        $tag = trim($tag);
        if (empty($names) || empty($tag)) {
            return AgentResult::reply(
                "Usage: /workflow bulk-tag [nom1] [nom2] [nom3] [tag]\n\n"
                . "Exemple:\n"
                . "  /workflow bulk-tag morning-brief daily-check routine\n"
                . "  → Ajoute le tag \"routine\" a morning-brief et daily-check"
            );
        }

        $parts = preg_split('/[\s,]+/', trim($names));

        $tag = mb_strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '', $tag));
        if (empty($tag)) {
            return AgentResult::reply("Tag invalide. Utilise uniquement des lettres, chiffres, tirets.");
        }

        $tagged   = [];
        $notFound = [];
        $alreadyTagged = [];

        foreach ($parts as $wfName) {
            $wfName = trim($wfName);
            if (empty($wfName)) continue;

            $workflow = $this->findWorkflow($context->from, $wfName);
            if (!$workflow) {
                $notFound[] = $wfName;
                continue;
            }

            try {
                $conditions = $workflow->conditions ?? [];
                $tags = $conditions['tags'] ?? [];

                if (in_array($tag, $tags, true)) {
                    $alreadyTagged[] = $workflow->name;
                    continue;
                }

                $tags[] = $tag;
                $conditions['tags'] = array_values(array_unique($tags));
                $workflow->update(['conditions' => $conditions]);
                $tagged[] = $workflow->name;
            } catch (\Throwable $e) {
                Log::error("StreamlineAgent: bulk-tag failed for {$wfName}", [
                    'error' => $e->getMessage(),
                ]);
                $notFound[] = $wfName . ' (erreur)';
            }
        }

        $this->log($context, "Bulk tag applied: #{$tag}", [
            'tagged'  => count($tagged),
            'skipped' => count($alreadyTagged),
            'errors'  => count($notFound),
        ]);

        $lines = ["*Tag #{$tag}* applique:\n"];

        if (!empty($tagged)) {
            foreach ($tagged as $name) {
                $lines[] = "  [OK] {$name}";
            }
        }
        if (!empty($alreadyTagged)) {
            foreach ($alreadyTagged as $name) {
                $lines[] = "  [=] {$name} (deja tague)";
            }
        }
        if (!empty($notFound)) {
            $lines[] = '';
            foreach ($notFound as $name) {
                $lines[] = "  [?] {$name} — introuvable";
            }
        }

        if (empty($tagged) && empty($alreadyTagged)) {
            return AgentResult::reply("Aucun workflow trouve pour le tag #{$tag}.\nVerifie les noms avec /workflow list.");
        }

        $lines[] = "\nFiltrer: /workflow list #{$tag}";
        $lines[] = "Lancer tous: /workflow run-all #{$tag}";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Show help text, optionally filtered by category.
     */
    /**
     * Handle unknown /workflow subcommand with fuzzy suggestion.
     */
    private function handleUnknownCommand(AgentContext $context, string $action): AgentResult
    {
        $commands = [
            'create', 'list', 'trigger', 'run', 'delete', 'show', 'info',
            'enable', 'disable', 'rename', 'duplicate', 'stats', 'history',
            'edit', 'export', 'add-step', 'remove-step', 'move-step', 'describe',
            'dryrun', 'reset-stats', 'template', 'pin', 'unpin', 'insert',
            'tag', 'run-all', 'batch', 'summary', 'step-config', 'suggest',
            'notes', 'health', 'quick', 'last', 'copy-step', 'diff', 'compare',
            'favorites', 'schedule', 'merge', 'optimize', 'swap', 'undo',
            'dashboard', 'retry', 'clean', 'status', 'graph', 'recent',
            'test-step', 'disable-step', 'enable-step', 'chain', 'analyze',
            'snapshot', 'restore', 'validate', 'bulk-tag', 'compare-stats',
            'recap', 'profile', 'bulk', 'dependencies', 'export-all', 'explain',
            'timeline', 'estimate', 'watch', 'rename-step', 'pause', 'split',
            'reorder', 'archive', 'unarchive', 'compact', 'go', 'diagnose',
            'quick-create', 'overview', 'preflight', 'streak', 'focus',
            'clone-steps', 'kpi', 'help-search', 'summary-all', 'help',
        ];

        // Find closest match (Levenshtein distance <= 3)
        $bestMatch = null;
        $bestDist = PHP_INT_MAX;
        foreach ($commands as $cmd) {
            $dist = levenshtein($action, $cmd);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestMatch = $cmd;
            }
        }

        if ($bestMatch && $bestDist <= 3 && $bestDist > 0) {
            return AgentResult::reply(
                "Commande \"/workflow {$action}\" inconnue.\n\n"
                . "Tu voulais dire *{$bestMatch}* ?\n"
                . "  /workflow {$bestMatch}\n\n"
                . "_Tape /workflow help pour la liste complete._"
            );
        }

        return $this->showHelp($context);
    }

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
     * Chain multiple workflows: /workflow chain wf1 wf2 wf3
     * Executes workflows sequentially, passing context between them.
     */
    private function commandChain(AgentContext $context, string $arg): AgentResult
    {
        $names = preg_split('/[\s,]+/', trim($arg));
        $names = array_values(array_filter($names, fn($n) => !empty(trim($n))));

        if (count($names) < 2) {
            return AgentResult::reply(
                "*Chain de workflows*\n\n"
                . "Enchaine plusieurs workflows en sequence.\n"
                . "Le resultat de chaque workflow est passe au suivant.\n\n"
                . "Utilisation:\n"
                . "  /workflow chain [nom1] [nom2] [nom3]\n\n"
                . "Exemple:\n"
                . "  /workflow chain morning-brief daily-check"
            );
        }

        if (count($names) > 5) {
            return AgentResult::reply("Maximum 5 workflows dans une chain (tu en as " . count($names) . ").");
        }

        // Resolve all workflows first
        $workflows = [];
        foreach ($names as $name) {
            $wf = $this->findWorkflow($context->from, $name);
            if (!$wf) {
                return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
            }
            if (!$wf->is_active) {
                return AgentResult::reply("Le workflow \"{$wf->name}\" est desactive.\nActive-le avec: /workflow enable {$wf->name}");
            }
            $workflows[] = $wf;
        }

        $wfNames = array_map(fn($w) => $w->name, $workflows);
        $this->log($context, 'Chain started', ['workflows' => $wfNames]);
        $this->sendText($context->from, "Lancement de la chain: " . implode(' → ', $wfNames) . " (" . count($workflows) . " workflows)...");

        $results = [];
        $previousOutput = '';
        $allSuccess = true;

        foreach ($workflows as $i => $wf) {
            $stepNum = $i + 1;
            $input = $previousOutput ?: null;

            try {
                $orchestrator = new AgentOrchestrator();
                $executor = new WorkflowExecutor($orchestrator);

                $workflowToRun = $wf;
                if ($input) {
                    $contextPrefix = "[Contexte chain: " . mb_substr($input, 0, 200) . "] ";
                    $injectedSteps = array_map(function (array $step) use ($contextPrefix) {
                        $step['message'] = $contextPrefix . ($step['message'] ?? '');
                        return $step;
                    }, $wf->steps ?? []);
                    $workflowToRun = clone $wf;
                    $workflowToRun->steps = $injectedSteps;
                }

                $executionResult = $executor->execute($workflowToRun, $context);
                $formatted = WorkflowExecutor::formatResults($executionResult);

                // Extract last step output as context for next workflow
                $stepResults = $executionResult['steps'] ?? [];
                $lastStep = end($stepResults);
                $previousOutput = mb_substr($lastStep['reply'] ?? $formatted, 0, 500);

                $success = ($executionResult['status'] ?? '') !== 'failed';
                $results[] = [
                    'name'    => $wf->name,
                    'success' => $success,
                    'output'  => $formatted,
                ];

                if (!$success) {
                    $allSuccess = false;
                    $this->sendText($context->from, "Le workflow \"{$wf->name}\" a echoue. Chain interrompue.");
                    break;
                }
            } catch (\Throwable $e) {
                Log::error("StreamlineAgent: chain workflow failed", [
                    'workflow' => $wf->name,
                    'error'    => $e->getMessage(),
                ]);
                $results[] = [
                    'name'    => $wf->name,
                    'success' => false,
                    'output'  => 'Erreur: ' . $e->getMessage(),
                ];
                $allSuccess = false;
                break;
            }
        }

        // Format chain summary
        $lines = [];
        $lines[] = $allSuccess ? "*Chain terminee avec succes*" : "*Chain interrompue*";
        $lines[] = str_repeat('─', 25);

        foreach ($results as $i => $r) {
            $icon = $r['success'] ? '✅' : '❌';
            $lines[] = "{$icon} *{$r['name']}*";
        }

        // Show remaining unexecuted workflows
        $executed = count($results);
        if ($executed < count($workflows)) {
            for ($i = $executed; $i < count($workflows); $i++) {
                $lines[] = "⏭ *{$workflows[$i]->name}* (non execute)";
            }
        }

        $lines[] = '';
        $lines[] = "Workflows: " . count($results) . "/" . count($workflows) . " executes";

        $this->log($context, 'Chain completed', [
            'workflows' => $wfNames,
            'success'   => $allSuccess,
            'executed'  => $executed,
        ]);

        return AgentResult::reply(implode("\n", $lines), ['chain_results' => $results]);
    }

    /**
     * AI-powered analysis of a workflow's execution patterns and performance.
     */
    private function commandAnalyze(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "*Analyse IA de workflow*\n\n"
                . "Analyse les patterns d'execution, performance et fiabilite.\n\n"
                . "Utilisation: /workflow analyze [nom]\n"
                . "Exemple: /workflow analyze morning-brief"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $this->log($context, "Analyzing workflow: {$workflow->name}");

        // Gather execution data
        $steps = $workflow->steps ?? [];
        $stepCount = count($steps);
        $runCount = $workflow->run_count ?? 0;
        $lastRun = $workflow->last_run_at ? $workflow->last_run_at->diffForHumans() : 'jamais';
        $meta = $workflow->meta ?? [];
        $tags = $meta['tags'] ?? [];
        $notes = $meta['notes'] ?? '';
        $isPinned = $this->isPinned($workflow);
        $status = $this->statusLabel($workflow);
        $description = $workflow->description ?? 'Aucune description';

        // Build step details
        $stepDetails = '';
        foreach ($steps as $i => $step) {
            $agent = $step['agent'] ?? 'auto';
            $condition = $step['condition'] ?? 'always';
            $onError = $step['on_error'] ?? 'stop';
            $disabled = !empty($step['disabled']);
            $label = $disabled ? ' [DESACTIVEE]' : '';
            $stepDetails .= "  " . ($i + 1) . ". [{$agent}] {$step['message']}{$label} (condition: {$condition}, on_error: {$onError})\n";
        }

        // Get recent execution history
        $historyEntries = $this->memory->read($context->agent->id ?? 0, $context->from);
        $recentHistory = '';
        $entries = $historyEntries['entries'] ?? [];
        $wfEntries = array_filter($entries, fn($e) => str_contains($e['agent_reply'] ?? '', $workflow->name));
        $recentEntries = array_slice($wfEntries, -5);
        foreach ($recentEntries as $entry) {
            $recentHistory .= "  - " . mb_substr($entry['agent_reply'] ?? '', 0, 120) . "\n";
        }

        $model = $this->resolveModel($context);

        try {
            $response = $this->claude->chat(
                "Analyse le workflow suivant:\n\n"
                . "Nom: {$workflow->name}\n"
                . "Description: {$description}\n"
                . "Statut: {$status}\n"
                . "Epingle: " . ($isPinned ? 'oui' : 'non') . "\n"
                . "Executions: {$runCount}\n"
                . "Derniere execution: {$lastRun}\n"
                . "Tags: " . (empty($tags) ? 'aucun' : implode(', ', $tags)) . "\n"
                . "Notes: " . ($notes ?: 'aucune') . "\n\n"
                . "Etapes ({$stepCount}):\n{$stepDetails}\n"
                . ($recentHistory ? "Historique recent:\n{$recentHistory}\n" : ''),
                $model,
                "Tu es un expert en analyse de workflows et automatisation.\n"
                . "Analyse ce workflow WhatsApp et fournis un rapport structure.\n"
                . "Reponds directement en texte (pas de JSON), formate pour WhatsApp:\n"
                . "- Utilise *gras* pour les titres\n"
                . "- Utilise des bullets avec •\n"
                . "- Sois concis mais precis\n\n"
                . "Structure ton analyse:\n"
                . "1. *Resume* — Ce que fait ce workflow en 1-2 phrases\n"
                . "2. *Performance* — Score de fiabilite (base sur les executions), frequence d'utilisation\n"
                . "3. *Points forts* — Ce qui fonctionne bien\n"
                . "4. *Points d'amelioration* — Problemes potentiels ou optimisations\n"
                . "5. *Recommandations* — 2-3 actions concretes (ex: ajouter condition, changer agent, reordonner)\n\n"
                . "REGLES:\n"
                . "- N'invente JAMAIS de donnees ou statistiques non fournies\n"
                . "- Base-toi uniquement sur les informations donnees\n"
                . "- Si peu de donnees, dis-le clairement\n"
                . "- Maximum 15 lignes au total"
            );

            if (empty($response)) {
                return AgentResult::reply(
                    "⚠ L'analyse IA n'a pas pu etre generee.\n\n"
                    . "Alternatives:\n"
                    . "  /workflow profile {$workflow->name} — profil detaille\n"
                    . "  /workflow show {$workflow->name} — voir les etapes"
                );
            }

            $header = "*🔍 Analyse — {$workflow->name}*\n" . str_repeat('─', 25) . "\n\n";

            $this->log($context, "Workflow analyzed: {$workflow->name}", ['run_count' => $runCount]);

            return AgentResult::reply($header . trim($response));
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: analyze generation failed", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply(
                "⚠ Erreur lors de l'analyse IA de *{$workflow->name}*.\n\n"
                . "Alternatives:\n"
                . "  /workflow profile {$workflow->name} — profil detaille\n"
                . "  /workflow validate {$workflow->name} — verifier l'integrite"
            );
        }
    }


    /**
     * AI-powered plain language explanation of what a workflow does.
     * Usage: /workflow explain [name]
     */
    private function commandExplain(AgentContext $context, string $name): AgentResult
    {
        if (empty(trim($name))) {
            return AgentResult::reply(
                "*Explication IA d'un workflow*\n\n"
                . "Genere une explication claire et detaillee de ce que fait un workflow.\n\n"
                . "Utilisation: /workflow explain [nom]\n"
                . "Exemple: /workflow explain morning-brief"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $steps = $workflow->steps ?? [];
        if (empty($steps)) {
            return AgentResult::reply("Le workflow *{$workflow->name}* n'a aucune etape a expliquer.");
        }

        $stepCount = count($steps);
        $stepsDesc = [];
        foreach ($steps as $i => $step) {
            $num      = $i + 1;
            $msg      = $step['message'] ?? '(vide)';
            $agent    = $step['agent'] ?? 'auto';
            $cond     = $step['condition'] ?? 'always';
            $onErr    = $step['on_error'] ?? 'stop';
            $disabled = !empty($step['disabled']);
            $stepsDesc[] = "Etape {$num}: agent={$agent}, condition={$cond}, on_error={$onErr}"
                . ($disabled ? ', DESACTIVEE' : '')
                . " — \"{$msg}\"";
        }

        $model = $this->resolveModel($context);
        $desc  = $workflow->description ?: 'Aucune description';

        $response = $this->claude->chat(
            "Workflow: \"{$workflow->name}\"\nDescription: {$desc}\n\nEtapes:\n" . implode("\n", $stepsDesc),
            $model,
            "Tu es un assistant qui explique les workflows en langage simple et clair.\n"
            . "Genere une explication structuree du workflow en francais.\n\n"
            . "Format de reponse (texte pur, PAS de JSON):\n"
            . "1. Un resume en 1 phrase de l'objectif du workflow\n"
            . "2. Pour chaque etape: ce qu'elle fait concretement, quel agent la traite, et les conditions\n"
            . "3. Un conseil pratique d'utilisation\n\n"
            . "Regles:\n"
            . "- Sois concis et clair, comme si tu expliquais a quelqu'un qui ne connait pas les workflows\n"
            . "- Utilise des emojis WhatsApp pour structurer (▶, ✅, 💡, ⚙)\n"
            . "- Ne mentionne pas les termes techniques (JSON, API, etc.)\n"
            . "- Maximum 15 lignes au total\n"
            . "- Si une etape est desactivee, mentionne-le clairement",
            self::NLU_MAX_TOKENS
        );

        if (!$response) {
            $lines = ["*📖 Explication: {$workflow->name}*", str_repeat('─', 28), ""];
            $lines[] = "📝 {$desc}";
            $lines[] = "";
            foreach ($steps as $i => $step) {
                $num = $i + 1;
                $agent = $step['agent'] ?? 'auto';
                $disabled = !empty($step['disabled']) ? ' ⏭' : '';
                $lines[] = "▶ *Etape {$num}* ({$agent}){$disabled}: " . mb_substr($step['message'] ?? '', 0, 80);
            }
            $lines[] = "";
            $lines[] = "_Explication IA indisponible. Voici un resume basique._";
            $this->log($context, "Explain fallback: {$workflow->name}", ['reason' => 'llm_unavailable']);
            return AgentResult::reply(implode("\n", $lines));
        }

        $header = "*📖 Explication: {$workflow->name}*\n"
            . str_repeat('─', 28) . "\n\n";

        $footer = "\n\n" . str_repeat('─', 28)
            . "\n/workflow show {$workflow->name}     — voir etapes"
            . "\n/workflow trigger {$workflow->name}  — lancer";

        $this->log($context, "Explain: {$workflow->name}", ['steps' => $stepCount]);

        return AgentResult::reply($header . trim($response) . $footer);
    }

    /**
     * Visual timeline of workflow executions showing recent activity.
     * Usage: /workflow timeline [name?]
     */
    private function commandTimeline(AgentContext $context, string $name = ''): AgentResult
    {
        $name = trim($name);

        if (!empty($name)) {
            [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
            if ($errResult) return $errResult;
            if (!$workflow) {
                return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
            }

            $runs      = $workflow->run_count ?? 0;
            $lastRun   = $workflow->last_run_at;
            $durations = $workflow->conditions['durations'] ?? [];
            $history   = $workflow->conditions['history'] ?? [];

            $lines = [
                "*📅 Timeline: {$workflow->name}*",
                str_repeat('─', 28),
                "",
            ];

            if ($runs === 0) {
                $lines[] = "_Aucune execution enregistree._";
                $lines[] = "";
                $lines[] = "Lance-le: /workflow trigger {$workflow->name}";
                return AgentResult::reply(implode("\n", $lines));
            }

            $lines[] = "📊 *{$runs}* execution" . ($runs > 1 ? 's' : '') . " au total";
            if ($lastRun) {
                $lines[] = "🕐 Derniere: " . $lastRun->format('d/m/Y H:i') . ' (' . $lastRun->diffForHumans() . ')';
            }

            if (!empty($durations)) {
                $recent = array_slice($durations, -10);
                $avg    = round(array_sum($recent) / count($recent), 1);
                $trend  = '';
                if (count($recent) >= 3) {
                    $firstHalf  = array_slice($recent, 0, (int) floor(count($recent) / 2));
                    $secondHalf = array_slice($recent, (int) floor(count($recent) / 2));
                    $avgFirst   = array_sum($firstHalf) / count($firstHalf);
                    $avgSecond  = array_sum($secondHalf) / count($secondHalf);
                    if ($avgSecond < $avgFirst * 0.9) {
                        $trend = ' 📉 _en amelioration_';
                    } elseif ($avgSecond > $avgFirst * 1.1) {
                        $trend = ' 📈 _en hausse_';
                    } else {
                        $trend = ' ➡ _stable_';
                    }
                }
                $lines[] = "⏱ Duree moy: {$avg}s{$trend}";

                $lines[] = "";
                $lines[] = "*Dernieres executions:*";
                $maxDur = max($recent);
                foreach ($recent as $idx => $dur) {
                    $barLen = $maxDur > 0 ? (int) round(($dur / $maxDur) * 12) : 1;
                    $bar    = str_repeat('█', max(1, $barLen)) . str_repeat('░', 12 - max(1, $barLen));
                    $lines[] = "  {$bar} {$dur}s";
                }
            }

            if (!empty($history)) {
                $recentHistory = array_slice($history, -5);
                $lines[] = "";
                $lines[] = "*Historique recent:*";
                foreach (array_reverse($recentHistory) as $entry) {
                    $date   = $entry['date'] ?? '?';
                    $status = ($entry['status'] ?? '') === 'success' ? '✅' : '❌';
                    $input  = !empty($entry['input']) ? " _{$entry['input']}_" : '';
                    $lines[] = "  {$status} {$date}{$input}";
                }
            }

            $lines[] = "";
            $lines[] = str_repeat('─', 28);
            $lines[] = "/workflow profile {$workflow->name}  — profil complet";
            $lines[] = "/workflow trigger {$workflow->name}  — relancer";

            $this->log($context, "Timeline: {$workflow->name}", ['runs' => $runs]);

            return AgentResult::reply(implode("\n", $lines));
        }

        // Global timeline: recent activity across all workflows
        $workflows = Workflow::forUser($context->from)
            ->whereNotNull('last_run_at')
            ->orderByDesc('last_run_at')
            ->limit(10)
            ->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "*📅 Timeline globale*\n\n"
                . "_Aucun workflow n'a encore ete execute._\n\n"
                . "Cree un workflow: /workflow create [nom] [etapes]\n"
                . "Ou utilise un template: /workflow template"
            );
        }

        $lines = [
            "*📅 Timeline globale*",
            str_repeat('─', 28),
            "",
        ];

        $totalRuns = Workflow::forUser($context->from)->sum('run_count');
        $lines[] = "📊 *{$totalRuns}* execution" . ($totalRuns > 1 ? 's' : '') . " au total";
        $lines[] = "";

        $byDay = [];
        foreach ($workflows as $wf) {
            $day = $wf->last_run_at->format('d/m');
            $byDay[$day][] = $wf;
        }

        foreach ($byDay as $day => $wfs) {
            $lines[] = "*{$day}:*";
            foreach ($wfs as $wf) {
                $time    = $wf->last_run_at->format('H:i');
                $runs    = $wf->run_count ?? 0;
                $pinIcon = $this->isPinned($wf) ? ' 📌' : '';
                $lines[] = "  🕐 {$time} — *{$wf->name}* ({$runs}x){$pinIcon}";
            }
        }

        $lines[] = "";
        $lines[] = str_repeat('─', 28);
        $lines[] = "_/workflow timeline [nom] pour le detail d'un workflow_";
        $lines[] = "/workflow dashboard — tableau de bord";

        $this->log($context, 'Global timeline viewed', ['count' => $workflows->count()]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Estimate execution time for a workflow based on historical data.
     * Usage: /workflow estimate [name]
     */
    private function commandEstimate(AgentContext $context, string $name): AgentResult
    {
        if (empty(trim($name))) {
            return AgentResult::reply(
                "*Estimation de duree*\n\n"
                . "Estime le temps d'execution d'un workflow base sur l'historique.\n\n"
                . "Utilisation: /workflow estimate [nom]\n"
                . "Exemple: /workflow estimate morning-brief"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $steps = $workflow->steps ?? [];
        $stepCount = count($steps);
        $activeSteps = count(array_filter($steps, fn($s) => empty($s['_skip']) && empty($s['disabled'])));
        $durations = $workflow->conditions['durations'] ?? [];
        $history = $workflow->conditions['history'] ?? [];

        $lines = [
            "*⏱ Estimation: {$workflow->name}*",
            str_repeat('─', 28),
            "",
        ];

        if (!empty($durations)) {
            $avg = round(array_sum($durations) / count($durations), 1);
            $min = round(min($durations), 1);
            $max = round(max($durations), 1);
            $median = $this->calculateMedian($durations);

            $lines[] = "📊 Base sur *" . count($durations) . "* execution" . (count($durations) > 1 ? 's' : '') . ":";
            $lines[] = "";
            $lines[] = "  ⏱ Moyenne  : *{$avg}s*";
            $lines[] = "  ⏱ Mediane  : *{$median}s*";
            $lines[] = "  ⚡ Min      : {$min}s";
            $lines[] = "  🐢 Max      : {$max}s";

            // Trend analysis
            if (count($durations) >= 4) {
                $half = (int) floor(count($durations) / 2);
                $firstHalf = array_slice($durations, 0, $half);
                $secondHalf = array_slice($durations, $half);
                $avgFirst = array_sum($firstHalf) / count($firstHalf);
                $avgSecond = array_sum($secondHalf) / count($secondHalf);

                if ($avgSecond < $avgFirst * 0.85) {
                    $pct = (int) round((1 - $avgSecond / $avgFirst) * 100);
                    $lines[] = "";
                    $lines[] = "📉 Tendance: *-{$pct}%* — execution de plus en plus rapide";
                } elseif ($avgSecond > $avgFirst * 1.15) {
                    $pct = (int) round(($avgSecond / $avgFirst - 1) * 100);
                    $lines[] = "";
                    $lines[] = "📈 Tendance: *+{$pct}%* — execution de plus en plus lente";
                } else {
                    $lines[] = "";
                    $lines[] = "➡ Tendance: stable";
                }
            }

            // Reliability score
            $successCount = count(array_filter($history, fn($h) => ($h['status'] ?? '') === 'success'));
            $totalHistory = count($history);
            if ($totalHistory > 0) {
                $reliability = (int) round($successCount / $totalHistory * 100);
                $reliabilityBar = str_repeat('█', (int) round($reliability / 10)) . str_repeat('░', 10 - (int) round($reliability / 10));
                $lines[] = "";
                $lines[] = "✅ Fiabilite: {$reliabilityBar} *{$reliability}%* ({$successCount}/{$totalHistory})";
            }
        } else {
            $estimatedTime = $activeSteps * 5;
            $lines[] = "_Aucune donnee historique disponible._";
            $lines[] = "";
            $lines[] = "⏱ Estimation theorique: ~*{$estimatedTime}s*";
            $lines[] = "  ({$activeSteps} etape" . ($activeSteps > 1 ? 's' : '') . " × ~5s/etape)";
            $lines[] = "";
            $lines[] = "_Lance le workflow pour obtenir des donnees reelles._";
        }

        $lines[] = "";
        $lines[] = str_repeat('─', 28);
        $lines[] = "/workflow dryrun {$workflow->name}    — simulation";
        $lines[] = "/workflow trigger {$workflow->name}   — lancer";

        $this->log($context, "Estimate: {$workflow->name}", ['has_data' => !empty($durations)]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Calculate median of a numeric array.
     */
    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        if ($count === 0) return 0;
        $mid = (int) floor($count / 2);
        if ($count % 2 === 0) {
            return round(($values[$mid - 1] + $values[$mid]) / 2, 1);
        }
        return round($values[$mid], 1);
    }

    /**
     * Toggle watch mode on a workflow — sends a detailed report after each execution.
     * Usage: /workflow watch [name] [on|off]
     */
    private function commandWatch(AgentContext $context, string $name, string $mode = 'on'): AgentResult
    {
        if (empty(trim($name))) {
            return AgentResult::reply(
                "*Surveillance de workflow*\n\n"
                . "Active/desactive le mode surveillance sur un workflow.\n"
                . "En mode surveillance, un rapport detaille est envoye apres chaque execution.\n\n"
                . "Utilisation:\n"
                . "  /workflow watch [nom]      — activer\n"
                . "  /workflow watch [nom] off  — desactiver\n\n"
                . "Exemple: /workflow watch morning-brief"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $mode = mb_strtolower(trim($mode));
        $enable = !in_array($mode, ['off', 'non', 'desactiver', 'stop', '0', 'false']);

        try {
            $conditions = $workflow->conditions ?? [];
            $conditions['watch'] = $enable;
            $workflow->update(['conditions' => $conditions]);
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: watch toggle failed", [
                'workflow' => $workflow->name,
                'error'    => $e->getMessage(),
            ]);
            return AgentResult::reply("Erreur lors de la modification du mode surveillance.");
        }

        $this->log($context, "Watch " . ($enable ? 'enabled' : 'disabled') . ": {$workflow->name}");

        if ($enable) {
            return AgentResult::reply(
                "👁 Surveillance *activee* sur *{$workflow->name}*\n\n"
                . "Tu recevras un rapport detaille apres chaque execution:\n"
                . "  • Duree et statut de chaque etape\n"
                . "  • Comparaison avec les executions precedentes\n"
                . "  • Alertes en cas de degradation\n\n"
                . "Desactiver: /workflow watch {$workflow->name} off"
            );
        }

        return AgentResult::reply(
            "👁 Surveillance *desactivee* sur *{$workflow->name}*\n\n"
            . "Reactiver: /workflow watch {$workflow->name}"
        );
    }

    /**
     * Send a detailed watch report after workflow execution.
     */
    private function sendWatchReport(AgentContext $context, Workflow $workflow, array $executionResult, float $elapsed): void
    {
        try {
            $status = ($executionResult['status'] ?? '') === 'failed' ? '❌ Echoue' : '✅ Succes';
            $stepResults = $executionResult['steps'] ?? [];
            $durations = $workflow->conditions['durations'] ?? [];
            $avgDuration = !empty($durations) ? round(array_sum($durations) / count($durations), 1) : null;

            $lines = [
                "*👁 Rapport surveillance: {$workflow->name}*",
                str_repeat('─', 30),
                "",
                "Statut: {$status}",
                "Duree: *{$elapsed}s*" . ($avgDuration ? " (moy: {$avgDuration}s)" : ''),
            ];

            // Performance comparison
            if ($avgDuration && $elapsed > $avgDuration * 1.3) {
                $pct = (int) round(($elapsed / $avgDuration - 1) * 100);
                $lines[] = "⚠ *+{$pct}%* plus lent que la moyenne";
            } elseif ($avgDuration && $elapsed < $avgDuration * 0.7) {
                $pct = (int) round((1 - $elapsed / $avgDuration) * 100);
                $lines[] = "⚡ *-{$pct}%* plus rapide que la moyenne";
            }

            // Step-by-step results
            if (!empty($stepResults)) {
                $lines[] = "";
                $lines[] = "*Etapes:*";
                foreach ($stepResults as $i => $step) {
                    $icon = ($step['status'] ?? '') === 'success' ? '✅' : '❌';
                    $agent = $step['agent'] ?? 'auto';
                    $lines[] = "  {$icon} " . ($i + 1) . ". [{$agent}] " . mb_substr($step['message'] ?? '', 0, 50);
                }
            }

            $lines[] = "";
            $lines[] = str_repeat('─', 30);
            $lines[] = "/workflow estimate {$workflow->name} — stats detaillees";

            $this->sendText($context->from, implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::warning("StreamlineAgent: watch report failed", ['error' => $e->getMessage()]);
        }
    }

    // ── Missing utility methods ─────────────────────────────────────

    /**
     * Parse a JSON response from the LLM, with salvage for truncated output.
     */
    private function parseJson(?string $response): ?array
    {
        if (!$response) {
            return null;
        }

        // Strip BOM and invisible unicode characters
        $clean = preg_replace('/^\x{FEFF}/u', '', trim($response));

        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Handle array-wrapped responses: [{"action":...}] → {"action":...}
        $trimmed = ltrim($clean);
        if (str_starts_with($trimmed, '[')) {
            $arr = json_decode($trimmed, true);
            if (is_array($arr) && !empty($arr) && isset($arr[0]) && is_array($arr[0])) {
                return $arr[0];
            }
        }

        // Extract first JSON object if surrounded by text
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $result = json_decode($clean, true);
        if ($result !== null) {
            return $result;
        }

        // Fix trailing commas before } or ] (common LLM output artifact)
        $fixed = preg_replace('/,\s*([\}\]])/s', '$1', $clean);
        if ($fixed !== $clean) {
            $result = json_decode($fixed, true);
            if ($result !== null) {
                return $result;
            }
        }

        // Salvage truncated JSON (max_tokens hit)
        $salvage = $clean;
        // Close unclosed strings
        if (substr_count($salvage, '"') % 2 !== 0) {
            $salvage .= '"';
        }
        $opens = substr_count($salvage, '{') - substr_count($salvage, '}');
        $salvage .= str_repeat('}', max(0, $opens));
        $opens = substr_count($salvage, '[') - substr_count($salvage, ']');
        $salvage .= str_repeat(']', max(0, $opens));

        $result = json_decode($salvage, true);
        if ($result === null) {
            Log::debug("StreamlineAgent: parseJson salvage failed", [
                'preview' => mb_substr($response, 0, 200),
            ]);
        }

        return $result;
    }

    /**
     * Return the full help text for /workflow help.
     */
    private function getHelpText(): string
    {
        return "📋 *Guide des Workflows*\n\n"
            . "*Creer:*\n"
            . "  /workflow create [nom] [etape1] then [etape2]\n"
            . "  _Ou decris en langage naturel_\n\n"
            . "*Gerer:*\n"
            . "  /workflow list — lister tes workflows\n"
            . "  /workflow show [nom] — details complets\n"
            . "  /workflow info [nom] — carte compacte\n"
            . "  /workflow enable|disable [nom]\n"
            . "  /workflow rename [ancien] [nouveau]\n"
            . "  /workflow duplicate [nom] [copie]\n"
            . "  /workflow delete [nom]\n\n"
            . "*Executer:*\n"
            . "  /workflow trigger [nom] — lancer\n"
            . "  /workflow quick [terme] — recherche + lancer\n"
            . "  /workflow dryrun [nom] — simuler\n"
            . "  /workflow last — relancer le dernier\n"
            . "  /workflow run-all — lancer tous les actifs\n"
            . "  /workflow batch [nom1] [nom2] ...\n"
            . "  /workflow chain [nom1] [nom2] ...\n\n"
            . "*Etapes:*\n"
            . "  /workflow add-step [nom] [message]\n"
            . "  /workflow remove-step [nom] [numero]\n"
            . "  /workflow move-step [nom] [de] [vers]\n"
            . "  /workflow swap [nom] [n1] [n2]\n"
            . "  /workflow insert [nom] [pos] [message]\n"
            . "  /workflow test-step [nom] [numero]\n"
            . "  /workflow disable-step [nom] [numero]\n"
            . "  /workflow enable-step [nom] [numero]\n"
            . "  /workflow rename-step [nom] [numero] [nouveau message]\n"
            . "  /workflow step-config [nom] [num] [param=val]\n\n"
            . "*Analyse:*\n"
            . "  /workflow stats — statistiques globales\n"
            . "  /workflow health — sante des workflows\n"
            . "  /workflow dashboard — tableau de bord\n"
            . "  /workflow analyze [nom] — analyse IA\n"
            . "  /workflow profile [nom] — fiche detaillee\n"
            . "  /workflow explain [nom] — explication simple\n"
            . "  /workflow validate [nom] — verifier integrite\n"
            . "  /workflow estimate [nom] — duree estimee\n"
            . "  /workflow graph [nom] — graphe visuel\n\n"
            . "*Organisation:*\n"
            . "  /workflow tag [nom] [tag]\n"
            . "  /workflow pin|unpin [nom]\n"
            . "  /workflow notes [nom] [note]\n"
            . "  /workflow favorites — top 5\n"
            . "  /workflow search [terme]\n"
            . "  /workflow diff [nom1] [nom2]\n"
            . "  /workflow merge [nom1] [nom2]\n"
            . "  /workflow split [nom] [etape] — decouper en deux\n"
            . "  /workflow reorder [nom] [3,1,2] — reorganiser\n\n"
            . "*Historique:*\n"
            . "  /workflow history — executions\n"
            . "  /workflow recent — recemment lances\n"
            . "  /workflow timeline — chronologie\n"
            . "  /workflow recap [jours] — resume\n"
            . "  /workflow snapshot [nom] — sauvegarder\n"
            . "  /workflow restore [nom] — restaurer\n"
            . "  /workflow undo [nom] — annuler\n\n"
            . "*Avance:*\n"
            . "  /workflow optimize [nom] — ameliorations IA\n"
            . "  /workflow suggest [description] — suggestion IA\n"
            . "  /workflow template — modeles\n"
            . "  /workflow export|import [nom]\n"
            . "  /workflow export-all — backup complet\n"
            . "  /workflow schedule [nom] [frequence]\n"
            . "  /workflow watch [nom] — surveillance\n"
            . "  /workflow pause [nom] — pause temporaire\n"
            . "  /workflow dependencies — carte des agents\n"
            . "  /workflow clean — nettoyage\n"
            . "  /workflow archive [nom] — archiver (masquer)\n"
            . "  /workflow unarchive [nom] — desarchiver\n"
            . "  /workflow archive list — voir les archives\n"
            . "  /workflow compact — vue compacte\n"
            . "  /workflow go — lancer le workflow prioritaire\n"
            . "  /workflow diagnose [nom] — diagnostic complet\n"
            . "  /workflow streak — serie de jours consecutifs\n"
            . "  /workflow focus [tag] — filtrer par tag\n"
            . "  /workflow quick-create [type] — creation rapide\n"
            . "  /workflow overview — vue d'ensemble\n"
            . "  /workflow preflight [nom] — check pre-lancement\n"
            . "  /workflow help-search [terme] — chercher une commande\n"
            . "  /workflow summary-all — resume IA de tous les workflows\n"
            . "  /workflow benchmark — classement par performance\n"
            . "  /workflow whatif [nom] [etape] — simuler le retrait\n\n"
            . "_Aide par categorie: /workflow help [gestion|execution|etapes|analyse|avance]_";
    }

    /**
     * Return help text filtered by category.
     */
    private function getHelpCategory(string $category): ?string
    {
        $categories = [
            'creation' => "📋 *Aide: Creation de workflows*\n\n"
                . "/workflow create [nom] [etape1] then [etape2]\n"
                . "/workflow suggest [description] — suggestion IA\n"
                . "/workflow template — utiliser un modele\n"
                . "/workflow import [json] — importer\n"
                . "/workflow duplicate [nom] [copie]\n\n"
                . "_Exemples:_\n"
                . "  /workflow create morning-brief resume mes todos then check rappels\n"
                . "  /workflow suggest un workflow pour ma routine du matin\n"
                . "  /workflow template productivity",
            'gestion' => "📋 *Aide: Gestion des workflows*\n\n"
                . "/workflow list — lister tous\n"
                . "/workflow show [nom] — details complets\n"
                . "/workflow info [nom] — carte compacte\n"
                . "/workflow enable|disable [nom] — activer/desactiver\n"
                . "/workflow rename [ancien] [nouveau]\n"
                . "/workflow delete [nom]\n"
                . "/workflow tag [nom] [tag]\n"
                . "/workflow pin|unpin [nom]\n"
                . "/workflow notes [nom] [note]\n"
                . "/workflow archive [nom] — archiver (masquer)\n"
                . "/workflow unarchive [nom] — desarchiver\n"
                . "/workflow archive list — voir les archives\n"
                . "/workflow compact — vue compacte",
            'execution' => "📋 *Aide: Execution de workflows*\n\n"
                . "/workflow trigger [nom] — lancer\n"
                . "/workflow trigger [nom] [contexte] — lancer avec contexte\n"
                . "/workflow quick [terme] — recherche + lancer\n"
                . "/workflow dryrun [nom] — simuler\n"
                . "/workflow last — relancer le dernier\n"
                . "/workflow retry [nom] — reessayer\n"
                . "/workflow run-all — lancer tous les actifs\n"
                . "/workflow batch [nom1] [nom2] — lancer plusieurs\n"
                . "/workflow chain [nom1] [nom2] — enchainer\n"
                . "/workflow go — lancer le workflow prioritaire\n"
                . "/workflow schedule [nom] [frequence] — planifier\n"
                . "/workflow pause [nom] — pause temporaire",
            'etapes' => "📋 *Aide: Gestion des etapes*\n\n"
                . "/workflow add-step [nom] [message]\n"
                . "/workflow remove-step [nom] [numero]\n"
                . "/workflow move-step [nom] [de] [vers]\n"
                . "/workflow swap [nom] [n1] [n2]\n"
                . "/workflow insert [nom] [pos] [message]\n"
                . "/workflow rename-step [nom] [numero] [nouveau message]\n"
                . "/workflow test-step [nom] [numero]\n"
                . "/workflow disable-step [nom] [numero]\n"
                . "/workflow enable-step [nom] [numero]\n"
                . "/workflow step-config [nom] [num] [param=val]\n"
                . "/workflow copy-step [nom] [num] [cible]",
            'analyse' => "📋 *Aide: Analyse et statistiques*\n\n"
                . "/workflow stats — statistiques globales\n"
                . "/workflow health — sante des workflows\n"
                . "/workflow dashboard — tableau de bord\n"
                . "/workflow analyze [nom] — analyse IA complete\n"
                . "/workflow profile [nom] — fiche detaillee\n"
                . "/workflow explain [nom] — explication simple\n"
                . "/workflow validate [nom] — verifier integrite\n"
                . "/workflow estimate [nom] — duree estimee\n"
                . "/workflow graph [nom] — graphe visuel\n"
                . "/workflow compare-stats [nom1] [nom2]\n"
                . "/workflow diff [nom1] [nom2] — comparer\n"
                . "/workflow diagnose [nom] — diagnostic complet\n"
                . "/workflow favorites — top 5",
            'avance' => "📋 *Aide: Fonctionnalites avancees*\n\n"
                . "/workflow optimize [nom] — ameliorations IA\n"
                . "/workflow export [nom] — exporter\n"
                . "/workflow export-all — backup complet\n"
                . "/workflow import [json] — importer\n"
                . "/workflow snapshot [nom] — sauvegarder etat\n"
                . "/workflow restore [nom] — restaurer\n"
                . "/workflow undo [nom] — annuler derniere modif\n"
                . "/workflow merge [nom1] [nom2] — fusionner\n"
                . "/workflow watch [nom] — surveillance\n"
                . "/workflow pause [nom] — pause temporaire\n"
                . "/workflow dependencies — carte des agents\n"
                . "/workflow clean — nettoyage\n"
                . "/workflow bulk-tag [noms] [tag]\n"
                . "/workflow timeline — chronologie",
            'historique' => "📋 *Aide: Historique et suivi*\n\n"
                . "/workflow history — historique d'executions\n"
                . "/workflow recent — recemment lances\n"
                . "/workflow timeline — chronologie visuelle\n"
                . "/workflow recap [jours] — resume d'activite\n"
                . "/workflow snapshot [nom] — sauvegarder\n"
                . "/workflow restore [nom] — restaurer\n"
                . "/workflow undo [nom] — annuler",
        ];

        // Allow aliases
        $aliases = [
            'creer' => 'creation', 'create' => 'creation',
            'gerer' => 'gestion', 'manage' => 'gestion',
            'exec' => 'execution', 'run' => 'execution', 'lancer' => 'execution',
            'step' => 'etapes', 'steps' => 'etapes',
            'stats' => 'analyse', 'analysis' => 'analyse',
            'advanced' => 'avance',
            'history' => 'historique',
        ];

        $key = $aliases[$category] ?? $category;

        return $categories[$key] ?? null;
    }

    /**
     * Search help text by keyword: /workflow help-search [term]
     * Finds all commands matching a keyword in the help text.
     */
    private function commandHelpSearch(AgentContext $context, string $term): AgentResult
    {
        $term = trim($term);
        if (empty($term)) {
            return AgentResult::reply(
                "*🔎 Recherche d'aide*\n\n"
                . "Trouve les commandes liees a un mot-cle.\n\n"
                . "Utilisation: /workflow help-search [terme]\n"
                . "Exemple: /workflow help-search etape\n"
                . "Exemple: /workflow help-search statistique"
            );
        }

        $helpText = $this->getHelpText();
        $lines = explode("\n", $helpText);
        $matches = [];
        $termLower = mb_strtolower($term);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) continue;
            if (mb_strpos(mb_strtolower($trimmed), $termLower) !== false && str_contains($trimmed, '/workflow')) {
                $matches[] = $trimmed;
            }
        }

        // Also search in command descriptions from help categories
        $categories = ['creation', 'gestion', 'execution', 'etapes', 'analyse', 'avance', 'historique'];
        foreach ($categories as $cat) {
            $catText = $this->getHelpCategory($cat);
            if (!$catText) continue;
            foreach (explode("\n", $catText) as $line) {
                $trimmed = trim($line);
                if (empty($trimmed)) continue;
                if (mb_strpos(mb_strtolower($trimmed), $termLower) !== false && str_contains($trimmed, '/workflow')) {
                    if (!in_array($trimmed, $matches)) {
                        $matches[] = $trimmed;
                    }
                }
            }
        }

        if (empty($matches)) {
            return AgentResult::reply(
                "🔎 Aucune commande trouvee pour *\"{$term}\"*.\n\n"
                . "Essaie un autre mot-cle ou tape /workflow help pour la liste complete."
            );
        }

        $result = "🔎 *Resultats pour \"{$term}\"* — " . count($matches) . " commande" . (count($matches) > 1 ? 's' : '') . "\n"
            . str_repeat('━', 28) . "\n\n";
        foreach (array_slice($matches, 0, 15) as $match) {
            $result .= "  {$match}\n";
        }
        if (count($matches) > 15) {
            $result .= "\n  _...et " . (count($matches) - 15) . " de plus_";
        }
        $result .= "\n\n_/workflow help [categorie] pour plus de details._";

        $this->log($context, 'help-search', ['term' => $term, 'results' => count($matches)]);
        return AgentResult::reply($result);
    }

    /**
     * Rename a step's message: /workflow rename-step [name] [step_num] [new message]
     */
    private function commandRenameStep(AgentContext $context, string $name, string $arg): AgentResult
    {
        if (empty(trim($name)) || empty(trim($arg))) {
            return AgentResult::reply(
                "*Renommer une etape*\n\n"
                . "Modifie le message d'une etape existante.\n\n"
                . "Utilisation: /workflow rename-step [nom] [numero] [nouveau message]\n"
                . "Exemple: /workflow rename-step morning-brief 2 Verifie mes rappels urgents"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $steps = $workflow->steps ?? [];
        $argParts = preg_split('/\s+/', trim($arg), 2);
        $stepNum = (int) ($argParts[0] ?? 0);
        $newMessage = trim($argParts[1] ?? '');

        if ($stepNum < 1 || $stepNum > count($steps)) {
            return AgentResult::reply(
                "Numero d'etape invalide: {$stepNum}\n"
                . "Le workflow *{$workflow->name}* a " . count($steps) . " etape(s)."
            );
        }

        if (empty($newMessage)) {
            return AgentResult::reply(
                "Nouveau message requis.\n"
                . "Utilisation: /workflow rename-step {$workflow->name} {$stepNum} [nouveau message]"
            );
        }

        $this->backupSteps($workflow);

        $oldMessage = $steps[$stepNum - 1]['message'] ?? '(vide)';
        $steps[$stepNum - 1]['message'] = $newMessage;
        $workflow->steps = $steps;
        $workflow->save();

        $this->log($context, "Step {$stepNum} renamed in workflow {$workflow->name}", [
            'workflow' => $workflow->name,
            'step' => $stepNum,
            'old_message' => mb_substr($oldMessage, 0, 100),
            'new_message' => mb_substr($newMessage, 0, 100),
        ]);

        return AgentResult::reply(
            "✅ Etape {$stepNum} de *{$workflow->name}* renommee.\n\n"
            . "*Avant:* {$oldMessage}\n"
            . "*Apres:* {$newMessage}\n\n"
            . "_/workflow show {$workflow->name} pour voir le workflow_\n"
            . "/workflow undo {$workflow->name} — annuler"
        );
    }

    /**
     * Pause/resume a workflow temporarily: /workflow pause [name]
     * Paused workflows are skipped by run-all and batch but can still be triggered manually.
     */
    private function commandPause(AgentContext $context, string $name): AgentResult
    {
        if (empty(trim($name))) {
            return AgentResult::reply(
                "*Pause temporaire*\n\n"
                . "Met un workflow en pause — il sera ignore par run-all et batch,\n"
                . "mais pourra toujours etre lance manuellement.\n\n"
                . "Utilisation: /workflow pause [nom]\n"
                . "Exemple: /workflow pause morning-brief\n\n"
                . "_Relancer: /workflow pause [nom] (toggle)_"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $conditions = $workflow->conditions ?? [];
        $isPaused = !empty($conditions['paused']);

        $conditions['paused'] = !$isPaused;
        $workflow->conditions = $conditions;
        $workflow->save();

        $newState = !$isPaused;

        $this->log($context, "Workflow {$workflow->name} " . ($newState ? 'paused' : 'resumed'), [
            'workflow' => $workflow->name,
            'paused' => $newState,
        ]);

        if ($newState) {
            return AgentResult::reply(
                "⏸ Workflow *{$workflow->name}* mis en pause.\n\n"
                . "Il sera ignore par /workflow run-all et batch.\n"
                . "Tu peux toujours le lancer avec /workflow trigger {$workflow->name}\n\n"
                . "_/workflow pause {$workflow->name} pour reprendre_"
            );
        }

        return AgentResult::reply(
            "▶ Workflow *{$workflow->name}* repris.\n\n"
            . "Il sera de nouveau inclus dans run-all et batch.\n\n"
            . "_/workflow trigger {$workflow->name} pour le lancer maintenant_"
        );
    }

    /**
     * Compare execution stats between two workflows side by side.
     */
    private function commandCompareStats(AgentContext $context, string $name1, string $name2): AgentResult
    {
        if (empty($name1) || empty($name2)) {
            return AgentResult::reply(
                "*Comparer les stats de deux workflows*\n\n"
                . "Utilisation: /workflow compare-stats [nom1] [nom2]\n"
                . "Exemple: /workflow compare-stats morning-brief daily-check"
            );
        }

        $wf1 = $this->findWorkflow($context->from, $name1);
        $wf2 = $this->findWorkflow($context->from, $name2);

        if (!$wf1 && !$wf2) {
            return AgentResult::reply("Workflows \"{$name1}\" et \"{$name2}\" introuvables.\nVerifie avec /workflow list");
        }
        if (!$wf1) {
            return AgentResult::reply("Workflow \"{$name1}\" introuvable.\nVerifie avec /workflow list");
        }
        if (!$wf2) {
            return AgentResult::reply("Workflow \"{$name2}\" introuvable.\nVerifie avec /workflow list");
        }

        $this->log($context, "Compare stats: {$wf1->name} vs {$wf2->name}");

        $steps1 = count($wf1->steps ?? []);
        $steps2 = count($wf2->steps ?? []);
        $runs1 = $wf1->run_count ?? 0;
        $runs2 = $wf2->run_count ?? 0;
        $lastRun1 = $wf1->last_run_at ? $wf1->last_run_at->diffForHumans() : 'jamais';
        $lastRun2 = $wf2->last_run_at ? $wf2->last_run_at->diffForHumans() : 'jamais';
        $status1 = $wf1->is_active ? '✅ Actif' : '⏸ Inactif';
        $status2 = $wf2->is_active ? '✅ Actif' : '⏸ Inactif';

        $dur1 = $wf1->conditions['durations'] ?? [];
        $dur2 = $wf2->conditions['durations'] ?? [];
        $avgDur1 = !empty($dur1) ? round(array_sum($dur1) / count($dur1), 1) . 's' : 'N/A';
        $avgDur2 = !empty($dur2) ? round(array_sum($dur2) / count($dur2), 1) . 's' : 'N/A';

        $tags1 = implode(' ', array_map(fn($t) => "#{$t}", $wf1->conditions['tags'] ?? [])) ?: '—';
        $tags2 = implode(' ', array_map(fn($t) => "#{$t}", $wf2->conditions['tags'] ?? [])) ?: '—';

        // Reliability comparison using execution history
        $hist1 = $wf1->conditions['history'] ?? [];
        $hist2 = $wf2->conditions['history'] ?? [];
        $rel1 = !empty($hist1) ? (int) round(count(array_filter($hist1, fn($h) => ($h['status'] ?? '') === 'success')) / count($hist1) * 100) . '%' : 'N/A';
        $rel2 = !empty($hist2) ? (int) round(count(array_filter($hist2, fn($h) => ($h['status'] ?? '') === 'success')) / count($hist2) * 100) . '%' : 'N/A';

        $lines = [
            "*📊 Comparaison*",
            str_repeat('─', 28),
            "",
            "                *{$wf1->name}*  vs  *{$wf2->name}*",
            str_repeat('─', 28),
            "Statut     : {$status1}  |  {$status2}",
            "Etapes     : {$steps1}  |  {$steps2}",
            "Executions : {$runs1}  |  {$runs2}",
            "Duree moy. : {$avgDur1}  |  {$avgDur2}",
            "Fiabilite  : {$rel1}  |  {$rel2}",
            "Dernier    : {$lastRun1}  |  {$lastRun2}",
            "Tags       : {$tags1}  |  {$tags2}",
        ];

        // Winner summary
        if ($runs1 > 0 && $runs2 > 0) {
            $lines[] = '';
            $moreUsed = $runs1 > $runs2 ? $wf1->name : ($runs2 > $runs1 ? $wf2->name : 'egalite');
            if ($moreUsed !== 'egalite') {
                $lines[] = "🏆 Plus utilise: *{$moreUsed}*";
            }
        }

        $lines[] = '';
        $lines[] = "/workflow diff {$wf1->name} {$wf2->name} — comparer les etapes";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * NLU helper for compare-stats (splits "name1 name2" from single name field).
     */
    private function handleCompareStatsFromNLU(AgentContext $context, string $names): AgentResult
    {
        $parts = preg_split('/\s+/', trim($names), 2);
        return $this->commandCompareStats($context, $parts[0] ?? '', $parts[1] ?? '');
    }

    /**
     * Activity recap: summary of recent workflow executions over the last N days.
     */
    private function commandRecap(AgentContext $context, string $arg): AgentResult
    {
        $days = max(1, min(30, (int) ($arg ?: 7)));

        $workflows = Workflow::forUser($context->from)->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow cree.\n\n"
                . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        $since = now()->subDays($days);

        // Workflows executed in the period
        $executedRecently = $workflows->filter(fn($wf) => $wf->last_run_at && $wf->last_run_at->gte($since));
        $neverRun = $workflows->where('run_count', 0);
        $totalRuns = $workflows->sum('run_count');
        $activeCount = $workflows->where('is_active', true)->count();

        $lines = [
            "*📋 Recap des {$days} derniers jours*",
            str_repeat('─', 28),
            "",
            "Workflows total : *{$workflows->count()}* ({$activeCount} actifs)",
            "Executions tot. : {$totalRuns}",
            "Lances recemment: {$executedRecently->count()}/{$workflows->count()}",
        ];

        if ($executedRecently->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "*Derniers lances:*";
            foreach ($executedRecently->sortByDesc('last_run_at')->take(8)->values() as $wf) {
                $ago = $wf->last_run_at->diffForHumans();
                $stepCount = count($wf->steps ?? []);
                $lines[] = "  · *{$wf->name}* — {$wf->run_count} exec. · {$stepCount} etapes · {$ago}";
            }
        }

        if ($neverRun->isNotEmpty()) {
            $lines[] = '';
            $neverCount = $neverRun->count();
            $lines[] = "⚠ *{$neverCount} workflow" . ($neverCount > 1 ? 's' : '') . " jamais lance" . ($neverCount > 1 ? 's' : '') . ":*";
            foreach ($neverRun->take(5)->values() as $wf) {
                $lines[] = "  · {$wf->name}";
            }
            if ($neverCount > 5) {
                $lines[] = "  ... et " . ($neverCount - 5) . " autre(s)";
            }
        }

        // Inactive but recently created
        $dormant = $workflows->filter(fn($wf) =>
            !$wf->is_active && $wf->created_at->gte($since)
        );
        if ($dormant->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "⏸ {$dormant->count()} workflow(s) cree(s) mais desactive(s)";
        }

        $lines[] = '';
        $lines[] = str_repeat('─', 24);
        $lines[] = "/workflow stats — statistiques detaillees";
        $lines[] = "/workflow health — audit de sante";

        $this->log($context, "Recap generated: {$days} days, {$executedRecently->count()} active");

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Detailed workflow profile card with performance scoring, reliability, and recommendations.
     * Usage: /workflow profile [name]
     */
    private function commandProfile(AgentContext $context, string $name): AgentResult
    {
        if (empty(trim($name))) {
            return AgentResult::reply(
                "*Profil detaille d'un workflow*\n\n"
                . "Utilisation: /workflow profile [nom]\n"
                . "Exemple: /workflow profile morning-brief"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $steps     = $workflow->steps ?? [];
        $stepCount = count($steps);
        $runs      = $workflow->run_count ?? 0;
        $status    = $workflow->is_active ? '✅ Actif' : '⏸ Inactif';
        $pinned    = $this->isPinned($workflow) ? '📌 Epingle' : '';
        $tags      = $workflow->conditions['tags'] ?? [];
        $tagStr    = !empty($tags) ? implode(' ', array_map(fn($t) => "#{$t}", $tags)) : '—';
        $created   = $workflow->created_at->format('d/m/Y');
        $lastRun   = $workflow->last_run_at ? $workflow->last_run_at->format('d/m/Y H:i') : 'jamais';
        $ago       = $workflow->last_run_at ? ' (' . $workflow->last_run_at->diffForHumans() . ')' : '';
        $desc      = $workflow->description ?: '_Aucune description_';

        // Performance scoring (0-100)
        $perfScore = 50;
        if ($runs > 0) $perfScore += 10;
        if ($runs > 5) $perfScore += 10;
        if ($runs > 20) $perfScore += 10;
        if ($workflow->is_active) $perfScore += 5;
        if (!empty($workflow->description)) $perfScore += 5;
        if (!empty($tags)) $perfScore += 5;
        if ($this->isPinned($workflow)) $perfScore += 5;
        if ($workflow->last_run_at && $workflow->last_run_at->diffInDays(now()) > 30) $perfScore -= 15;
        if ($workflow->last_run_at && $workflow->last_run_at->diffInDays(now()) > 60) $perfScore -= 10;
        $emptySteps = collect($steps)->filter(fn($s) => empty(trim($s['message'] ?? '')))->count();
        if ($emptySteps > 0) $perfScore -= ($emptySteps * 10);
        $perfScore = max(0, min(100, $perfScore));
        $perfBar   = str_repeat('█', (int) round($perfScore / 10)) . str_repeat('░', 10 - (int) round($perfScore / 10));
        $perfIcon  = $perfScore >= 80 ? '🟢' : ($perfScore >= 50 ? '🟡' : '🔴');

        // Duration stats
        $durations = $workflow->conditions['durations'] ?? [];
        $durationInfo = 'N/A';
        if (!empty($durations)) {
            $avg = round(array_sum($durations) / count($durations), 1);
            $min = round(min($durations), 1);
            $max = round(max($durations), 1);
            $durationInfo = "moy: {$avg}s · min: {$min}s · max: {$max}s";
        }

        // Reliability from execution history
        $history = $workflow->conditions['history'] ?? [];
        $reliabilityInfo = 'N/A';
        if (!empty($history)) {
            $successCount = count(array_filter($history, fn($h) => ($h['status'] ?? '') === 'success'));
            $reliability = (int) round($successCount / count($history) * 100);
            $reliabilityInfo = "{$reliability}% ({$successCount}/" . count($history) . ")";
        }

        // Agent distribution
        $agentCounts = [];
        foreach ($steps as $step) {
            $agent = $step['agent'] ?? 'auto';
            $agentCounts[$agent] = ($agentCounts[$agent] ?? 0) + 1;
        }
        arsort($agentCounts);
        $agentStr = implode(', ', array_map(fn($a, $c) => "{$a}({$c})", array_keys($agentCounts), $agentCounts));

        // Conditions analysis
        $hasConditions = collect($steps)->contains(fn($s) => ($s['condition'] ?? 'always') !== 'always');
        $hasErrorHandling = collect($steps)->contains(fn($s) => ($s['on_error'] ?? 'stop') === 'continue');
        $disabledSteps = collect($steps)->filter(fn($s) => !empty($s['disabled']))->count();
        $watchEnabled = !empty($workflow->conditions['watch']);

        $lines = [
            "*🪪 Profil: {$workflow->name}*",
            str_repeat('═', 30),
            "",
            "{$perfIcon} Score: {$perfBar} *{$perfScore}/100*",
            "",
            "📝 {$desc}",
            "",
            "📊 *Metriques:*",
            "  Statut    : {$status} {$pinned}",
            "  Etapes    : {$stepCount}" . ($disabledSteps > 0 ? " ({$disabledSteps} desactivee" . ($disabledSteps > 1 ? 's' : '') . ")" : ''),
            "  Executions: {$runs}",
            "  Duree     : {$durationInfo}",
            "  Fiabilite : {$reliabilityInfo}",
            "  Cree le   : {$created}",
            "  Dernier   : {$lastRun}{$ago}",
            "  Tags      : {$tagStr}",
            "  Surveillance: " . ($watchEnabled ? '👁 active' : 'desactivee'),
            "",
            "🔧 *Configuration:*",
            "  Agents   : {$agentStr}",
            "  Conditions: " . ($hasConditions ? '✅ oui' : '❌ aucune'),
            "  Tolerance : " . ($hasErrorHandling ? '✅ on_error=continue' : '⛔ on_error=stop'),
        ];

        // Recommendations
        $recs = [];
        if (empty($workflow->description)) $recs[] = "Ajoute une description: /workflow describe {$workflow->name} [desc]";
        if (empty($tags)) $recs[] = "Ajoute des tags: /workflow tag {$workflow->name} [tag1,tag2]";
        if ($runs === 0) $recs[] = "Lance-le: /workflow trigger {$workflow->name}";
        if ($workflow->last_run_at && $workflow->last_run_at->diffInDays(now()) > 30) {
            $recs[] = "Inactif >30j. Planifie: /workflow schedule {$workflow->name} [freq]";
        }
        if ($emptySteps > 0) $recs[] = "Corrige {$emptySteps} etape(s) vide(s): /workflow show {$workflow->name}";
        if (!$hasConditions && $stepCount > 2) $recs[] = "Ajoute des conditions: /workflow step-config {$workflow->name} [N] condition=success";
        if (!$watchEnabled && $runs > 3) $recs[] = "Active la surveillance: /workflow watch {$workflow->name}";

        if (!empty($recs)) {
            $lines[] = '';
            $lines[] = "*💡 Recommandations:*";
            foreach (array_slice($recs, 0, 4) as $rec) {
                $lines[] = "  · {$rec}";
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('═', 30);
        $lines[] = "/workflow show {$workflow->name}     — voir etapes";
        $lines[] = "/workflow analyze {$workflow->name}  — analyse IA";
        $lines[] = "/workflow estimate {$workflow->name} — temps d'exec.";

        $this->log($context, "Profile viewed: {$workflow->name}", ['score' => $perfScore]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Bulk action: enable/disable/pin/unpin/delete multiple workflows at once.
     * Usage: /workflow bulk [action] [name1] [name2] [name3]
     */
    private function commandBulkAction(AgentContext $context, string $arg): AgentResult
    {
        $parts  = preg_split('/\s+/', trim($arg));
        $action = mb_strtolower($parts[0] ?? '');
        $names  = array_slice($parts, 1);

        $validActions = ['enable', 'disable', 'pin', 'unpin', 'delete'];

        if (empty($action) || !in_array($action, $validActions, true) || empty($names)) {
            return AgentResult::reply(
                "*Actions en masse*\n\n"
                . "Utilisation: /workflow bulk [action] [nom1] [nom2] ...\n\n"
                . "Actions: enable, disable, pin, unpin, delete\n\n"
                . "Exemples:\n"
                . "  /workflow bulk enable morning-brief daily-check\n"
                . "  /workflow bulk disable old-wf1 old-wf2\n"
                . "  /workflow bulk pin morning-brief evening-check\n"
                . "  /workflow bulk delete obsolete-1 obsolete-2"
            );
        }

        if (count($names) > 10) {
            return AgentResult::reply("Maximum 10 workflows par action en masse (recu: " . count($names) . ").");
        }

        // Delete needs confirmation
        if ($action === 'delete') {
            $resolved = [];
            $notFound = [];
            foreach ($names as $n) {
                $wf = $this->findWorkflow($context->from, $n);
                if ($wf) {
                    $resolved[] = $wf;
                } else {
                    $notFound[] = $n;
                }
            }
            if (empty($resolved)) {
                return AgentResult::reply("Aucun des workflows specifies n'a ete trouve.\nVerifie avec /workflow list");
            }
            $nameList = implode(', ', array_map(fn($wf) => "*{$wf->name}*", $resolved));
            $this->setPendingContext($context, 'confirm_bulk_delete', [
                'workflow_ids' => array_map(fn($wf) => $wf->id, $resolved),
            ], 3);
            $warn = !empty($notFound) ? "\n⚠ Introuvable(s): " . implode(', ', $notFound) : '';
            return AgentResult::reply(
                "🗑 Supprimer *" . count($resolved) . "* workflow(s)?\n"
                . $nameList . $warn
                . "\n\nReponds *oui* pour confirmer ou *non* pour annuler."
            );
        }

        // Apply action
        $success = [];
        $failed  = [];
        foreach ($names as $n) {
            $wf = $this->findWorkflow($context->from, $n);
            if (!$wf) {
                $failed[] = $n;
                continue;
            }
            switch ($action) {
                case 'enable':
                    $wf->update(['is_active' => true]);
                    $success[] = $wf->name;
                    break;
                case 'disable':
                    $wf->update(['is_active' => false]);
                    $success[] = $wf->name;
                    break;
                case 'pin':
                    $conditions = $wf->conditions ?? [];
                    $conditions['pinned'] = true;
                    $wf->update(['conditions' => $conditions]);
                    $success[] = $wf->name;
                    break;
                case 'unpin':
                    $conditions = $wf->conditions ?? [];
                    unset($conditions['pinned']);
                    $wf->update(['conditions' => $conditions]);
                    $success[] = $wf->name;
                    break;
            }
        }

        $actionLabel = match ($action) {
            'enable'  => 'active(s)',
            'disable' => 'desactive(s)',
            'pin'     => 'epingle(s)',
            'unpin'   => 'desepingle(s)',
            default   => $action,
        };
        $emoji = match ($action) {
            'enable'  => '✅',
            'disable' => '⏸',
            'pin'     => '📌',
            'unpin'   => '📌',
            default   => '✅',
        };

        $lines = ["{$emoji} *" . count($success) . "* workflow(s) {$actionLabel}:"];
        foreach ($success as $n) {
            $lines[] = "  · {$n}";
        }
        if (!empty($failed)) {
            $lines[] = '';
            $lines[] = "⚠ Introuvable(s): " . implode(', ', $failed);
        }

        $this->log($context, "Bulk {$action}: " . implode(', ', $success), ['failed' => $failed]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Show agent usage distribution across all workflows.
     */
    private function commandDependencies(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow cree.\n\n"
                . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        $agentMap = [];
        $totalSteps = 0;

        foreach ($workflows as $wf) {
            foreach ($wf->steps ?? [] as $step) {
                $agent = $step['agent'] ?? 'auto';
                $agentMap[$agent] ??= [];
                if (!in_array($wf->name, $agentMap[$agent], true)) {
                    $agentMap[$agent][] = $wf->name;
                }
                $totalSteps++;
            }
        }

        uasort($agentMap, fn($a, $b) => count($b) <=> count($a));

        $lines = [
            "*🔗 Carte des dependances*",
            str_repeat('─', 28),
            "",
            "*{$workflows->count()}* workflows · *{$totalSteps}* etapes · *" . count($agentMap) . "* agents",
            "",
        ];

        foreach ($agentMap as $agent => $wfNames) {
            $pct = $totalSteps > 0 ? (int) round(collect($workflows)->sum(fn($wf) => collect($wf->steps ?? [])->where('agent', $agent)->count()) / $totalSteps * 100) : 0;
            $bar = str_repeat('█', max(1, (int) round($pct / 10))) . str_repeat('░', max(0, 10 - (int) round($pct / 10)));
            $lines[] = "*{$agent}* {$bar} {$pct}%";
            $lines[] = "  " . implode(', ', array_slice($wfNames, 0, 5)) . (count($wfNames) > 5 ? " +..." : '');
        }

        $shared = array_filter($agentMap, fn($wfs) => count($wfs) >= 3);
        if (!empty($shared)) {
            $lines[] = '';
            $lines[] = "*💡 Agents partages (3+ workflows):*";
            foreach ($shared as $agent => $wfs) {
                $lines[] = "  · *{$agent}* → " . count($wfs) . " workflows";
            }
        }

        $lines[] = '';
        $lines[] = "/workflow stats     — statistiques globales";
        $lines[] = "/workflow dashboard — tableau de bord";

        $this->log($context, "Dependencies viewed", ['agents' => count($agentMap), 'workflows' => $workflows->count()]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Export all workflows as a single text block for backup.
     */
    private function commandExportAll(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->orderBy('name')->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow a exporter.\n\n"
                . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        $lines = [
            "*📦 Export complet* — {$workflows->count()} workflow(s)",
            str_repeat('═', 30),
            "_Genere le " . now()->format('d/m/Y H:i') . "_",
            "",
        ];

        foreach ($workflows as $wf) {
            $status = $wf->is_active ? '✅' : '⏸';
            $tags = $wf->conditions['tags'] ?? [];
            $tagStr = !empty($tags) ? ' #' . implode(' #', $tags) : '';

            $lines[] = str_repeat('─', 28);
            $lines[] = "{$status} *{$wf->name}*{$tagStr}";
            if (!empty($wf->description)) {
                $lines[] = "_" . mb_substr($wf->description, 0, 80) . "_";
            }
            $lines[] = "{$wf->run_count} exec. · " . count($wf->steps ?? []) . " etapes";

            foreach ($wf->steps ?? [] as $i => $step) {
                $agent = !empty($step['agent']) ? " [{$step['agent']}]" : '';
                $condition = (!empty($step['condition']) && $step['condition'] !== 'always')
                    ? " si:{$step['condition']}" : '';
                $disabled = !empty($step['disabled']) ? ' ⏭' : '';
                $lines[] = "  " . ($i + 1) . ". " . mb_substr($step['message'] ?? '', 0, 100) . $agent . $condition . $disabled;
            }
            $lines[] = "";
        }

        $lines[] = str_repeat('═', 30);
        $lines[] = "Pour reimporter: /workflow import [nom] [etape1] then [etape2]";

        $this->log($context, "Export all: {$workflows->count()} workflows");

        $text = implode("\n", $lines);
        // WhatsApp message limit safety
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3950) . "\n\n_... tronque (trop de workflows)_";
        }

        return AgentResult::reply($text);
    }

    /**
     * Split a workflow into two at a given step number.
     * Steps 1..N go to the original, steps N+1..end become a new workflow.
     * Usage: /workflow split [name] [step_number]
     */
    private function commandSplit(AgentContext $context, string $name, string $stepArg): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "*Decouper un workflow*\n\n"
                . "Separe un workflow en deux a partir d'une etape.\n"
                . "Les etapes 1 a N restent, les suivantes deviennent un nouveau workflow.\n\n"
                . "Usage: /workflow split [nom] [numero_etape]\n"
                . "Exemple: /workflow split morning-brief 3\n\n"
                . "Voir les etapes: /workflow show [nom]"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $steps = $workflow->steps ?? [];
        $total = count($steps);

        if ($total < 2) {
            return AgentResult::reply(
                "Le workflow \"{$workflow->name}\" n'a que {$total} etape — impossible de decouper.\n"
                . "Il faut au moins 2 etapes."
            );
        }

        $splitAt = (int) trim($stepArg);
        if ($splitAt < 1 || $splitAt >= $total) {
            return AgentResult::reply(
                "Numero d'etape invalide: {$splitAt}.\n"
                . "Choisis un numero entre 1 et " . ($total - 1) . " pour decouper \"{$workflow->name}\" ({$total} etapes).\n\n"
                . "Exemple: /workflow split {$workflow->name} " . intdiv($total, 2)
            );
        }

        // Check workflow count limit
        $workflowCount = Workflow::forUser($context->from)->count();
        if ($workflowCount >= 50) {
            return AgentResult::reply(
                "Tu as atteint la limite de 50 workflows.\n"
                . "Supprime un workflow avant de decouper: /workflow delete [nom]"
            );
        }

        $keepSteps = array_slice($steps, 0, $splitAt);
        $newSteps  = array_values(array_slice($steps, $splitAt));

        $newName = $workflow->name . '-part2';
        $suffix  = 2;
        while (Workflow::forUser($context->from)->where('name', $newName)->exists()) {
            $suffix++;
            $newName = $workflow->name . '-part' . $suffix;
        }

        try {
            $this->backupSteps($workflow);
            $workflow->update(['steps' => $keepSteps]);

            Workflow::create([
                'user_id'     => $workflow->user_id,
                'name'        => $newName,
                'steps'       => $newSteps,
                'is_active'   => $workflow->is_active,
                'description' => $workflow->description ? $workflow->description . ' (partie 2)' : null,
                'conditions'  => $workflow->conditions,
            ]);

            $this->log($context, "Workflow split: {$workflow->name} at step {$splitAt} → {$newName}", [
                'kept'  => count($keepSteps),
                'moved' => count($newSteps),
            ]);

            return AgentResult::reply(
                "✂ Workflow decoupe avec succes!\n\n"
                . "*{$workflow->name}* — etapes 1 a {$splitAt} (" . count($keepSteps) . " etapes)\n"
                . "*{$newName}* — etapes " . ($splitAt + 1) . " a {$total} (" . count($newSteps) . " etapes)\n\n"
                . "Voir: /workflow show {$workflow->name}\n"
                . "Voir: /workflow show {$newName}\n"
                . "Annuler: /workflow undo {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: split failed", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
                'split_at' => $splitAt,
            ]);
            return AgentResult::reply("Erreur lors du decoupage du workflow. Reessaie.");
        }
    }

    /**
     * Reorder steps within a workflow using a comma-separated position spec.
     * Usage: /workflow reorder [name] [3,1,2,4]
     */
    private function commandReorder(AgentContext $context, string $name, string $orderArg): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "*Reorganiser les etapes*\n\n"
                . "Change l'ordre des etapes d'un workflow.\n\n"
                . "Usage: /workflow reorder [nom] [nouvel_ordre]\n"
                . "Exemple: /workflow reorder morning-brief 3,1,2,4\n\n"
                . "Le nouvel ordre utilise les numeros actuels des etapes.\n"
                . "Voir les etapes: /workflow show [nom]"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $steps = $workflow->steps ?? [];
        $total = count($steps);

        if ($total < 2) {
            return AgentResult::reply(
                "Le workflow \"{$workflow->name}\" n'a que {$total} etape — rien a reorganiser."
            );
        }

        $orderArg = trim($orderArg);
        if (empty($orderArg)) {
            $stepList = [];
            foreach ($steps as $i => $step) {
                $msg = mb_substr(trim($step['message'] ?? ''), 0, 50);
                $stepList[] = "  " . ($i + 1) . ". {$msg}";
            }
            return AgentResult::reply(
                "*Etapes de {$workflow->name}:*\n"
                . implode("\n", $stepList) . "\n\n"
                . "Precise le nouvel ordre (numeros separes par des virgules):\n"
                . "/workflow reorder {$workflow->name} [ordre]\n"
                . "Exemple: /workflow reorder {$workflow->name} " . implode(',', range($total, 1, -1))
            );
        }

        // Parse order spec
        $positions = array_map('intval', preg_split('/[\s,]+/', $orderArg));

        // Validate: must contain each position exactly once
        $sorted = $positions;
        sort($sorted);
        $expected = range(1, $total);

        if ($sorted !== $expected) {
            $missing = array_diff($expected, $positions);
            $extra   = array_diff($positions, $expected);
            $hints   = [];
            if (!empty($missing)) {
                $hints[] = "Manquantes: " . implode(', ', $missing);
            }
            if (!empty($extra)) {
                $hints[] = "Invalides: " . implode(', ', $extra);
            }
            return AgentResult::reply(
                "Ordre invalide. Tu dois lister chaque etape exactement une fois (1 a {$total}).\n"
                . (!empty($hints) ? implode("\n", $hints) . "\n" : '')
                . "\nExemple: /workflow reorder {$workflow->name} " . implode(',', range($total, 1, -1))
            );
        }

        // Check if order actually changes anything
        if ($positions === $expected) {
            return AgentResult::reply("L'ordre est deja identique — rien a changer.");
        }

        $newSteps = [];
        foreach ($positions as $pos) {
            $newSteps[] = $steps[$pos - 1];
        }

        try {
            $this->backupSteps($workflow);
            $workflow->update(['steps' => $newSteps]);

            $this->log($context, "Workflow reordered: {$workflow->name}", [
                'order' => implode(',', $positions),
            ]);

            $preview = [];
            foreach ($newSteps as $i => $step) {
                $msg = mb_substr(trim($step['message'] ?? ''), 0, 50);
                $agent = !empty($step['agent']) ? " [{$step['agent']}]" : '';
                $preview[] = "  " . ($i + 1) . ". {$msg}{$agent}";
            }

            return AgentResult::reply(
                "🔀 Etapes de *{$workflow->name}* reorganisees!\n\n"
                . "*Nouvel ordre:*\n"
                . implode("\n", $preview) . "\n\n"
                . "Simuler: /workflow dryrun {$workflow->name}\n"
                . "Annuler: /workflow undo {$workflow->name}"
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: reorder failed", [
                'error'    => $e->getMessage(),
                'workflow' => $workflow->name,
            ]);
            return AgentResult::reply("Erreur lors de la reorganisation. Reessaie.");
        }
    }

    /**
     * Archive or unarchive a workflow, or list archived workflows.
     */
    private function commandArchive(AgentContext $context, string $name, bool $archive = true): AgentResult
    {
        // List archived workflows
        if ($name === 'list' || $name === 'ls') {
            $workflows = Workflow::forUser($context->from)->get()->filter(function ($wf) {
                return !empty($wf->conditions['archived']);
            });

            if ($workflows->isEmpty()) {
                return AgentResult::reply(
                    "Aucun workflow archive.\n\n"
                    . "Pour archiver: /workflow archive [nom]\n"
                    . "Les workflows archives sont masques de la liste par defaut."
                );
            }

            $lines = ["*📦 Workflows archives* ({$workflows->count()})", str_repeat('─', 28)];
            foreach ($workflows->values() as $i => $wf) {
                $stepCount = count($wf->steps ?? []);
                $lastRun = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : 'jamais';
                $lines[] = ($i + 1) . ". 📦 *{$wf->name}* — {$stepCount} etapes · {$wf->run_count} exec. · {$lastRun}";
            }
            $lines[] = str_repeat('─', 24);
            $lines[] = "/workflow unarchive [nom] — restaurer";
            $lines[] = "/workflow delete [nom]    — supprimer";

            return AgentResult::reply(implode("\n", $lines));
        }

        if (empty($name)) {
            return AgentResult::reply(
                $archive
                    ? "Usage: /workflow archive [nom]\n\nArchive un workflow sans le supprimer. Il sera masque de /workflow list.\n\nVoir les archives: /workflow archive list"
                    : "Usage: /workflow unarchive [nom]\n\nRestaure un workflow archive dans la liste active."
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $conditions = $workflow->conditions ?? [];
        $isArchived = !empty($conditions['archived']);

        if ($archive && $isArchived) {
            return AgentResult::reply("Le workflow *{$workflow->name}* est deja archive.");
        }
        if (!$archive && !$isArchived) {
            return AgentResult::reply("Le workflow *{$workflow->name}* n'est pas archive.");
        }

        $conditions['archived'] = $archive;
        if (!$archive) {
            unset($conditions['archived']);
        }
        $workflow->conditions = $conditions;
        $workflow->save();

        $action = $archive ? 'archive' : 'desarchive';
        $emoji = $archive ? '📦' : '📂';
        $hint = $archive
            ? "\n\n_Masque de /workflow list. Voir: /workflow archive list_"
            : "\n\n_Visible a nouveau dans /workflow list._";

        $this->log($context, "Workflow {$action}: {$workflow->name}", ['archived' => $archive]);

        return AgentResult::reply("{$emoji} Workflow *{$workflow->name}* {$action} avec succes.{$hint}");
    }

    /**
     * Quick-trigger: /workflow go [name?]
     * Triggers the pinned workflow, most-used workflow, or a specific one by partial name.
     */
    private function commandGo(AgentContext $context, string $arg = ''): AgentResult
    {
        $arg = trim($arg);

        // If a name is given, find and trigger it directly
        if (!empty($arg)) {
            $workflow = $this->findWorkflow($context->from, $arg);
            if (!$workflow) {
                return AgentResult::reply(
                    "Aucun workflow correspondant a \"{$arg}\".\n"
                    . "Utilise /workflow list pour voir tes workflows."
                );
            }
            if (!$workflow->is_active) {
                return AgentResult::reply(
                    "Le workflow *{$workflow->name}* est desactive.\n"
                    . "Active-le avec: /workflow enable {$workflow->name}"
                );
            }
            if (empty($workflow->steps)) {
                return AgentResult::reply("Le workflow *{$workflow->name}* n'a aucune etape.");
            }
            return $this->triggerWorkflow($context, $workflow);
        }

        // No name given — find the best candidate
        $workflows = Workflow::forUser($context->from)->active()->get()
            ->filter(fn($wf) => !empty($wf->steps) && empty($wf->conditions['archived']));

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow actif a lancer.\n\n"
                . "Cree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        // Priority: pinned > paused=false + most-used > most recently run
        $pinned = $workflows->filter(fn($wf) => $this->isPinned($wf))->first();
        if ($pinned) {
            $this->log($context, "Go: triggered pinned workflow {$pinned->name}");
            return $this->triggerWorkflow($context, $pinned);
        }

        $topUsed = $workflows->sortByDesc('run_count')->first();
        if ($topUsed && $topUsed->run_count > 0) {
            $this->log($context, "Go: triggered most-used workflow {$topUsed->name}");
            return $this->triggerWorkflow($context, $topUsed);
        }

        $mostRecent = $workflows->whereNotNull('last_run_at')->sortByDesc('last_run_at')->first();
        if ($mostRecent) {
            $this->log($context, "Go: triggered most-recent workflow {$mostRecent->name}");
            return $this->triggerWorkflow($context, $mostRecent);
        }

        // Fallback: first workflow
        $first = $workflows->first();
        $this->log($context, "Go: triggered first workflow {$first->name}");
        return $this->triggerWorkflow($context, $first);
    }

    /**
     * Diagnose a workflow: /workflow diagnose [name]
     * Deep diagnostic: checks each step, agents, conditions, execution history, and suggests fixes.
     */
    private function commandDiagnose(AgentContext $context, string $name): AgentResult
    {
        if (empty(trim($name))) {
            return AgentResult::reply(
                "*🔍 Diagnostic de workflow*\n\n"
                . "Analyse en profondeur un workflow pour identifier les problemes.\n\n"
                . "Utilisation:\n"
                . "  /workflow diagnose [nom]\n\n"
                . "Exemple:\n"
                . "  /workflow diagnose morning-brief"
            );
        }

        $workflow = $this->findWorkflow($context->from, $name);
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nUtilise /workflow list pour voir tes workflows.");
        }

        $steps = $workflow->steps ?? [];
        $conditions = $workflow->conditions ?? [];
        $issues = [];
        $warnings = [];
        $suggestions = [];

        // Check: no steps
        if (empty($steps)) {
            $issues[] = "❌ Aucune etape definie";
            $suggestions[] = "Ajoute des etapes: /workflow add-step {$workflow->name} [instruction]";
        }

        // Check: workflow disabled
        if (!$workflow->is_active) {
            $warnings[] = "⏸ Workflow desactive";
            $suggestions[] = "Active-le: /workflow enable {$workflow->name}";
        }

        // Check: workflow paused
        if (!empty($conditions['paused'])) {
            $warnings[] = "⏸ Workflow en pause (ignore par run-all et batch)";
            $suggestions[] = "Reprends: /workflow pause {$workflow->name}";
        }

        // Check: workflow archived
        if (!empty($conditions['archived'])) {
            $warnings[] = "📦 Workflow archive (masque de la liste)";
            $suggestions[] = "Desarchive: /workflow unarchive {$workflow->name}";
        }

        // Check each step
        $knownAgents = [
            'chat', 'dev', 'todo', 'reminder', 'event_reminder', 'finance',
            'music', 'habit', 'pomodoro', 'content_summarizer', 'code_review',
            'web_search', 'document', 'analysis', 'streamline', 'interactive_quiz',
            'content_curator', 'user_preferences', 'daily_brief', 'game_master',
        ];

        foreach ($steps as $i => $step) {
            $num = $i + 1;
            $msg = $step['message'] ?? '';
            $agent = $step['agent'] ?? null;
            $condition = $step['condition'] ?? 'always';
            $onError = $step['on_error'] ?? 'stop';
            $disabled = !empty($step['disabled']);

            if ($disabled) {
                $warnings[] = "⏭ Etape {$num} desactivee";
            }

            if (empty(trim($msg))) {
                $issues[] = "❌ Etape {$num}: message vide";
                $suggestions[] = "Corrige: /workflow rename-step {$workflow->name} {$num} [nouveau message]";
            } elseif (mb_strlen($msg) < 5) {
                $warnings[] = "⚠ Etape {$num}: message tres court (\"{$msg}\") — risque de resultat imprecis";
            } elseif (mb_strlen($msg) > 500) {
                $warnings[] = "⚠ Etape {$num}: message tres long (" . mb_strlen($msg) . " car.) — peut ralentir le traitement";
            }

            if ($agent !== null && !in_array($agent, $knownAgents, true)) {
                $issues[] = "❌ Etape {$num}: agent \"{$agent}\" inconnu";
                $suggestions[] = "Corrige: /workflow step-config {$workflow->name} {$num} agent=chat";
            }

            if ($condition === 'success' && $i === 0) {
                $warnings[] = "⚠ Etape 1 a condition=\"success\" mais il n'y a pas d'etape precedente";
                $suggestions[] = "Change en: /workflow step-config {$workflow->name} 1 condition=always";
            }

            if (str_starts_with($condition, 'contains:') || str_starts_with($condition, 'not_contains:')) {
                $keyword = explode(':', $condition, 2)[1] ?? '';
                if (empty(trim($keyword))) {
                    $issues[] = "❌ Etape {$num}: condition \"{$condition}\" sans mot-cle";
                }
            }
        }

        // Check: never run
        if ($workflow->run_count === 0) {
            $warnings[] = "🆕 Jamais execute — teste-le avec /workflow dryrun {$workflow->name}";
        }

        // Check: stale (not run in 30+ days)
        if ($workflow->last_run_at && $workflow->last_run_at->diffInDays(now()) > 30) {
            $warnings[] = "💤 Pas execute depuis " . $workflow->last_run_at->diffInDays(now()) . " jours";
        }

        // Check: high failure indicators (all steps on_error=stop with many steps)
        $stopCount = collect($steps)->filter(fn($s) => ($s['on_error'] ?? 'stop') === 'stop')->count();
        if (count($steps) >= 4 && $stopCount === count($steps)) {
            $suggestions[] = "💡 Toutes les etapes ont on_error=\"stop\". Pour les etapes optionnelles, utilise on_error=\"continue\"";
        }

        // Check: duplicate consecutive agents
        $prevAgent = null;
        foreach ($steps as $i => $step) {
            $curAgent = $step['agent'] ?? null;
            if ($curAgent && $curAgent === $prevAgent && $curAgent !== 'chat') {
                $suggestions[] = "💡 Etapes " . $i . " et " . ($i + 1) . " utilisent le meme agent ({$curAgent}) — envisage de les fusionner";
                break;
            }
            $prevAgent = $curAgent;
        }

        // Build report
        $lines = [
            "*🔍 Diagnostic: {$workflow->name}*",
            str_repeat('─', 30),
            "",
            "📊 *Etat general*",
            "  Statut: " . ($workflow->is_active ? '✅ Actif' : '⏸ Inactif'),
            "  Etapes: " . count($steps),
            "  Executions: {$workflow->run_count}",
        ];

        if ($workflow->last_run_at) {
            $lines[] = "  Derniere exec: " . $workflow->last_run_at->diffForHumans();
        }

        $score = 100;
        $score -= count($issues) * 25;
        $score -= count($warnings) * 10;
        $score = max(0, min(100, $score));

        $scoreEmoji = match (true) {
            $score >= 80 => '🟢',
            $score >= 50 => '🟡',
            default      => '🔴',
        };

        $lines[] = "";
        $lines[] = "{$scoreEmoji} *Score de sante: {$score}/100*";

        if (!empty($issues)) {
            $lines[] = "";
            $lines[] = "*Problemes:*";
            foreach ($issues as $issue) {
                $lines[] = "  {$issue}";
            }
        }

        if (!empty($warnings)) {
            $lines[] = "";
            $lines[] = "*Avertissements:*";
            foreach ($warnings as $warning) {
                $lines[] = "  {$warning}";
            }
        }

        if (!empty($suggestions)) {
            $lines[] = "";
            $lines[] = "*Suggestions:*";
            foreach ($suggestions as $suggestion) {
                $lines[] = "  {$suggestion}";
            }
        }

        if (empty($issues) && empty($warnings)) {
            $lines[] = "";
            $lines[] = "✅ Aucun probleme detecte. Le workflow semble sain.";
        }

        $lines[] = "";
        $lines[] = "_Commandes utiles:_";
        $lines[] = "  /workflow validate {$workflow->name}";
        $lines[] = "  /workflow dryrun {$workflow->name}";
        $lines[] = "  /workflow optimize {$workflow->name}";

        $this->log($context, "Diagnose: {$workflow->name}", [
            'score' => $score,
            'issues' => count($issues),
            'warnings' => count($warnings),
        ]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Compact one-line-per-workflow overview.
     */
    private function commandCompact(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn($wf) => empty($wf->conditions['archived']));

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow actif.\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        // Sort: pinned first
        $workflows = $workflows->sortByDesc(fn($wf) => $this->isPinned($wf) ? 1 : 0);

        $active = $workflows->where('is_active', true)->count();
        $paused = $workflows->where('is_active', false)->count();
        $totalRuns = $workflows->sum('run_count');

        $lines = [
            "*⚡ Vue compacte* — {$workflows->count()} workflows · {$active} actifs · {$totalRuns} exec. totales",
            str_repeat('─', 32),
        ];

        foreach ($workflows->values() as $wf) {
            $icon = $wf->is_active ? '✅' : '⏸';
            $pin = $this->isPinned($wf) ? '📌' : '';
            $steps = count($wf->steps ?? []);
            $runs = $wf->run_count;
            $tags = $wf->conditions['tags'] ?? [];
            $tagStr = !empty($tags) ? ' #' . implode(' #', $tags) : '';

            $lines[] = "{$icon}{$pin} *{$wf->name}* · {$steps}st · {$runs}x{$tagStr}";
        }

        $lines[] = str_repeat('─', 24);
        $lines[] = "_" . ($paused > 0 ? "{$paused} en pause · " : '') . "/workflow list pour details_";

        $this->log($context, "Compact view: {$workflows->count()} workflows");

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Pre-flight check before running a workflow: validates, shows status, estimates time, last run result.
     * Usage: /workflow preflight [name]
     */
    private function commandPreflight(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply(
                "*Pre-flight Check*\n\n"
                . "Verifie qu'un workflow est pret a etre lance:\n"
                . "  - Validation des etapes\n"
                . "  - Statut (actif/pause/desactive)\n"
                . "  - Derniere execution\n"
                . "  - Duree estimee\n\n"
                . "Utilisation: /workflow preflight [nom]\n"
                . "Exemple: /workflow preflight morning-brief"
            );
        }

        [$workflow, $errResult] = $this->findWorkflowOrAmbiguous($context->from, $name);
        if ($errResult) return $errResult;
        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.\nVerifie avec /workflow list");
        }

        $steps = $workflow->steps ?? [];
        $conditions = $workflow->conditions ?? [];
        $lines = [];

        // Header
        $lines[] = "*🔍 Pre-flight: {$workflow->name}*";
        $lines[] = str_repeat('━', 28);

        // Status check
        $statusOk = true;
        if (!$workflow->is_active) {
            $lines[] = "❌ *Statut:* Desactive";
            $lines[] = "   → /workflow enable {$workflow->name}";
            $statusOk = false;
        } elseif (!empty($conditions['paused'])) {
            $lines[] = "⏸ *Statut:* En pause";
            $lines[] = "   → /workflow pause {$workflow->name} (pour reprendre)";
            $statusOk = false;
        } else {
            $lines[] = "✅ *Statut:* Actif";
        }

        // Steps check
        $issues = 0;
        $warnings = 0;
        if (empty($steps)) {
            $lines[] = "❌ *Etapes:* Aucune etape definie";
            $issues++;
        } else {
            $disabledCount = count(array_filter($steps, fn($s) => !empty($s['disabled'])));
            $emptyCount = count(array_filter($steps, fn($s) => empty(trim($s['message'] ?? ''))));

            if ($emptyCount > 0) {
                $lines[] = "⚠ *Etapes:* {$emptyCount} etape(s) vide(s) sur " . count($steps);
                $issues += $emptyCount;
            } else {
                $activeSteps = count($steps) - $disabledCount;
                $disabledNote = $disabledCount > 0 ? " ({$disabledCount} desactivee" . ($disabledCount > 1 ? 's' : '') . ")" : '';
                $lines[] = "✅ *Etapes:* {$activeSteps} active" . ($activeSteps > 1 ? 's' : '') . $disabledNote;
            }
        }

        // Agent validation
        $knownAgents = [
            'chat', 'dev', 'todo', 'reminder', 'event_reminder', 'finance', 'music',
            'habit', 'pomodoro', 'content_summarizer', 'code_review', 'web_search',
            'document', 'analysis', 'streamline', 'interactive_quiz', 'content_curator',
            'user_preferences', 'daily_brief', 'game_master',
        ];
        $unknownAgents = [];
        foreach ($steps as $step) {
            $agent = $step['agent'] ?? null;
            if ($agent !== null && !in_array($agent, $knownAgents, true)) {
                $unknownAgents[] = $agent;
            }
        }
        if (!empty($unknownAgents)) {
            $lines[] = "⚠ *Agents inconnus:* " . implode(', ', array_unique($unknownAgents));
            $warnings++;
        }

        // Last run info
        if ($workflow->last_run_at) {
            $ago = $workflow->last_run_at->diffForHumans();
            $lastResult = $conditions['last_result'] ?? null;
            $resultIcon = match ($lastResult) {
                'success' => '✅',
                'failed'  => '❌',
                'partial' => '⚠',
                default   => '▶️',
            };
            $lines[] = "{$resultIcon} *Derniere exec:* {$ago} ({$workflow->run_count} au total)";
        } else {
            $lines[] = "🆕 *Jamais lance*";
        }

        // Estimated duration
        $durations = $conditions['durations'] ?? [];
        if (!empty($durations)) {
            $avg = round(array_sum($durations) / count($durations), 1);
            $lines[] = "⏱ *Duree estimee:* ~{$avg}s";
        } else {
            $stepEstimate = count($steps) * 3;
            $lines[] = "⏱ *Duree estimee:* ~{$stepEstimate}s (estimation)";
        }

        // Verdict
        $lines[] = '';
        if ($issues === 0 && $statusOk) {
            $lines[] = "✅ *Pret a lancer!*";
            $lines[] = "→ /workflow trigger {$workflow->name}";
        } else {
            $lines[] = "⚠ *{$issues} probleme(s) detecte(s)*";
            $lines[] = "Corrige-les avant de lancer.";
            if (!$statusOk) {
                $lines[] = "→ /workflow validate {$workflow->name} (details)";
            }
        }

        $this->log($context, "Preflight check: {$workflow->name}", [
            'issues' => $issues,
            'warnings' => $warnings,
            'status_ok' => $statusOk,
        ]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Show execution streak: consecutive days where the user triggered at least one workflow.
     * Gamification feature to encourage regular workflow usage.
     */
    private function commandStreak(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow cree.\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        // Collect all execution dates from history across all workflows
        $allDates = [];
        foreach ($workflows as $wf) {
            $history = $wf->conditions['history'] ?? [];
            foreach ($history as $entry) {
                $dateStr = $entry['date'] ?? null;
                if ($dateStr) {
                    // Format is "d/m H:i" — extract just the day part
                    $dayPart = explode(' ', $dateStr)[0] ?? '';
                    if (!empty($dayPart)) {
                        $allDates[$dayPart] = true;
                    }
                }
            }
            // Also check last_run_at
            if ($wf->last_run_at) {
                $allDates[$wf->last_run_at->format('d/m')] = true;
            }
        }

        if (empty($allDates)) {
            return AgentResult::reply(
                "📊 *Serie d'utilisation*\n\n"
                . "Aucune execution enregistree.\n"
                . "Lance un workflow pour commencer ta serie!\n\n"
                . "→ /workflow go"
            );
        }

        // Calculate current streak (consecutive days ending today or yesterday)
        $today = now();
        $currentStreak = 0;
        $checkDate = $today->copy();

        // Check if today has activity, if not start from yesterday
        $todayKey = $checkDate->format('d/m');
        if (!isset($allDates[$todayKey])) {
            $checkDate->subDay();
            $yesterdayKey = $checkDate->format('d/m');
            if (!isset($allDates[$yesterdayKey])) {
                $currentStreak = 0;
            } else {
                $currentStreak = 1;
                $checkDate->subDay();
                while (isset($allDates[$checkDate->format('d/m')])) {
                    $currentStreak++;
                    $checkDate->subDay();
                }
            }
        } else {
            $currentStreak = 1;
            $checkDate->subDay();
            while (isset($allDates[$checkDate->format('d/m')])) {
                $currentStreak++;
                $checkDate->subDay();
            }
        }

        // Best streak (longest consecutive sequence)
        $sortedDates = array_keys($allDates);
        $bestStreak = max(1, $currentStreak);

        // Total active days
        $totalDays = count($allDates);

        // Total executions
        $totalRuns = $workflows->sum('run_count');

        // Streak emoji
        $streakEmoji = match (true) {
            $currentStreak >= 30 => '🏆',
            $currentStreak >= 14 => '🔥',
            $currentStreak >= 7  => '⚡',
            $currentStreak >= 3  => '✨',
            $currentStreak >= 1  => '🌱',
            default              => '💤',
        };

        $lines = [
            "*{$streakEmoji} Serie d'utilisation*",
            str_repeat('─', 28),
            "",
        ];

        if ($currentStreak > 0) {
            $bar = str_repeat('🟩', min($currentStreak, 14)) . ($currentStreak > 14 ? ' +' . ($currentStreak - 14) : '');
            $lines[] = "*Serie actuelle:* {$currentStreak} jour" . ($currentStreak > 1 ? 's' : '') . " {$streakEmoji}";
            $lines[] = $bar;
        } else {
            $lines[] = "*Serie actuelle:* 0 jour";
            $lines[] = "_Lance un workflow aujourd'hui pour demarrer!_";
        }

        $lines[] = "";
        $lines[] = "📊 *Statistiques:*";
        $lines[] = "  Jours actifs: *{$totalDays}*";
        $lines[] = "  Executions totales: *{$totalRuns}*";

        if ($totalDays > 0) {
            $avgPerDay = round($totalRuns / $totalDays, 1);
            $lines[] = "  Moyenne: *{$avgPerDay}* exec./jour actif";
        }

        // Motivational message
        $lines[] = "";
        if ($currentStreak === 0) {
            $lines[] = "💡 _Lance /workflow go pour reprendre ta serie!_";
        } elseif ($currentStreak < 7) {
            $remaining = 7 - $currentStreak;
            $lines[] = "💡 _Encore {$remaining} jour(s) pour atteindre 1 semaine!_";
        } elseif ($currentStreak < 30) {
            $remaining = 30 - $currentStreak;
            $lines[] = "💡 _Encore {$remaining} jour(s) pour atteindre 1 mois!_";
        } else {
            $lines[] = "🏆 _Impressionnant! Tu es un pro des workflows!_";
        }

        $this->log($context, "Streak check", [
            'current_streak' => $currentStreak,
            'total_days' => $totalDays,
            'total_runs' => $totalRuns,
        ]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Focus mode: filter and display workflows by tag, with quick-trigger options.
     * Usage: /workflow focus [tag?]
     */
    private function commandFocus(AgentContext $context, string $tag = ''): AgentResult
    {
        $tag = trim(mb_strtolower($tag));
        $workflows = Workflow::forUser($context->from)->get()
            ->filter(fn($wf) => empty($wf->conditions['archived']));

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow.\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        // If no tag specified, show all available tags
        if (empty($tag)) {
            $allTags = [];
            foreach ($workflows as $wf) {
                $tags = $wf->conditions['tags'] ?? [];
                foreach ($tags as $t) {
                    $t = mb_strtolower($t);
                    $allTags[$t] = ($allTags[$t] ?? 0) + 1;
                }
            }

            if (empty($allTags)) {
                return AgentResult::reply(
                    "*🎯 Mode Focus*\n\n"
                    . "Aucun tag defini sur tes workflows.\n\n"
                    . "Ajoute des tags avec:\n"
                    . "  /workflow tag [nom] [tag]\n\n"
                    . "Exemples:\n"
                    . "  /workflow tag morning-brief productivite\n"
                    . "  /workflow tag budget-check finance"
                );
            }

            arsort($allTags);
            $lines = [
                "*🎯 Mode Focus — Tags disponibles*",
                str_repeat('─', 28),
                "",
            ];

            foreach ($allTags as $tagName => $count) {
                $lines[] = "  #{$tagName} — {$count} workflow" . ($count > 1 ? 's' : '');
            }

            $lines[] = "";
            $lines[] = "_Utilise /workflow focus [tag] pour filtrer._";
            $lines[] = "_Exemple: /workflow focus productivite_";

            return AgentResult::reply(implode("\n", $lines));
        }

        // Filter workflows by tag
        $filtered = $workflows->filter(function ($wf) use ($tag) {
            $tags = array_map('mb_strtolower', $wf->conditions['tags'] ?? []);
            return in_array($tag, $tags, true);
        });

        if ($filtered->isEmpty()) {
            // Suggest closest tags
            $allTags = [];
            foreach ($workflows as $wf) {
                foreach ($wf->conditions['tags'] ?? [] as $t) {
                    $allTags[mb_strtolower($t)] = true;
                }
            }
            $available = !empty($allTags) ? "\n\nTags disponibles: " . implode(', ', array_map(fn($t) => "#{$t}", array_keys($allTags))) : '';
            return AgentResult::reply(
                "Aucun workflow avec le tag *#{$tag}*.{$available}\n\n"
                . "Ajoute un tag: /workflow tag [nom] {$tag}"
            );
        }

        $active = $filtered->where('is_active', true);
        $totalRuns = $filtered->sum('run_count');

        $lines = [
            "*🎯 Focus: #{$tag}* — {$filtered->count()} workflow" . ($filtered->count() > 1 ? 's' : '') . " · {$totalRuns} exec.",
            str_repeat('─', 30),
            "",
        ];

        foreach ($filtered->sortByDesc('run_count') as $wf) {
            $icon = $wf->is_active ? '✅' : '⏸';
            $pin = $this->isPinned($wf) ? '📌' : '';
            $steps = count($wf->steps ?? []);
            $runs = $wf->run_count;
            $lastRun = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : 'jamais';

            $lines[] = "{$icon}{$pin} *{$wf->name}* · {$steps} et. · {$runs}x · {$lastRun}";
        }

        $lines[] = "";
        $lines[] = str_repeat('─', 24);

        // Quick actions
        if ($active->count() > 1) {
            $names = $active->pluck('name')->implode(' ');
            $lines[] = "▶️ Lancer tous: /workflow batch {$names}";
        } elseif ($active->count() === 1) {
            $lines[] = "▶️ Lancer: /workflow trigger {$active->first()->name}";
        }

        $lines[] = "📊 Stats: /workflow stats";
        $lines[] = "🏷 Voir tous les tags: /workflow focus";

        $this->log($context, "Focus view: #{$tag}", [
            'tag' => $tag,
            'count' => $filtered->count(),
        ]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Quick-create a workflow from common template keywords.
     * Usage: /workflow quick-create [keyword]
     */
    private function commandQuickCreate(AgentContext $context, string $keyword): AgentResult
    {
        $keyword = mb_strtolower(trim($keyword));

        if (empty($keyword)) {
            return AgentResult::reply(
                "*⚡ Creation rapide*\n\n"
                . "Cree un workflow en un mot a partir de modeles courants.\n\n"
                . "Utilisation: /workflow quick-create [mot-cle]\n\n"
                . "*Mots-cles disponibles:*\n"
                . "  *morning* — routine du matin (todos + rappels + briefing)\n"
                . "  *evening* — routine du soir (bilan + habitudes + lendemain)\n"
                . "  *weekly* — revue hebdomadaire (stats + nettoyage + planif)\n"
                . "  *review* — revue de code (analyse + suggestions)\n"
                . "  *standup* — standup meeting (taches + blocages + objectifs)\n"
                . "  *focus* — session focus (pomodoro + taches prioritaires)\n\n"
                . "Exemple: /workflow quick-create morning"
            );
        }

        $templates = [
            'morning' => [
                'name' => 'morning-routine',
                'steps' => [
                    ['message' => 'Montre mes taches prioritaires du jour', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Quels rappels ai-je pour aujourd\'hui?', 'agent' => 'reminder', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Donne-moi un briefing rapide de ma journee', 'agent' => 'daily_brief', 'condition' => 'always', 'on_error' => 'stop'],
                ],
            ],
            'evening' => [
                'name' => 'evening-routine',
                'steps' => [
                    ['message' => 'Bilan de mes taches completees aujourd\'hui', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Verifie mes habitudes du jour', 'agent' => 'habit', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Quels rappels ai-je pour demain?', 'agent' => 'reminder', 'condition' => 'always', 'on_error' => 'stop'],
                ],
            ],
            'weekly' => [
                'name' => 'weekly-review',
                'steps' => [
                    ['message' => 'Resume de mes taches de la semaine', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Statistiques de mes habitudes cette semaine', 'agent' => 'habit', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Resume de mes depenses de la semaine', 'agent' => 'finance', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
            'review' => [
                'name' => 'code-review',
                'steps' => [
                    ['message' => 'Analyse mon dernier code pour les bugs potentiels', 'agent' => 'code_review', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Suggere des ameliorations de performance', 'agent' => 'dev', 'condition' => 'success', 'on_error' => 'stop'],
                ],
            ],
            'standup' => [
                'name' => 'daily-standup',
                'steps' => [
                    ['message' => 'Quelles taches ai-je completees hier?', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Quelles sont mes taches prioritaires aujourd\'hui?', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                    ['message' => 'Y a-t-il des rappels ou evenements bloquants?', 'agent' => 'reminder', 'condition' => 'always', 'on_error' => 'stop'],
                ],
            ],
            'focus' => [
                'name' => 'focus-session',
                'steps' => [
                    ['message' => 'Demarre une session pomodoro de 25 minutes', 'agent' => 'pomodoro', 'condition' => 'always', 'on_error' => 'stop'],
                    ['message' => 'Montre ma tache la plus prioritaire', 'agent' => 'todo', 'condition' => 'always', 'on_error' => 'continue'],
                ],
            ],
        ];

        // Fuzzy match keyword
        $matched = $templates[$keyword] ?? null;
        if (!$matched) {
            $bestMatch = null;
            $bestDist = PHP_INT_MAX;
            foreach (array_keys($templates) as $key) {
                $dist = levenshtein($keyword, $key);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestMatch = $key;
                }
            }
            if ($bestMatch && $bestDist <= 3) {
                $matched = $templates[$bestMatch];
                $keyword = $bestMatch;
            }
        }

        if (!$matched) {
            $available = implode(', ', array_keys($templates));
            return AgentResult::reply(
                "Mot-cle \"{$keyword}\" non reconnu.\n\n"
                . "Mots-cles disponibles: *{$available}*\n\n"
                . "Exemple: /workflow quick-create morning"
            );
        }

        // Check for name conflict
        $name = $matched['name'];
        $existing = Workflow::forUser($context->from)->where('name', $name)->first();
        if ($existing) {
            $name = $name . '-' . now()->format('His');
        }
        $matched['name'] = $name;

        // Check workflow count limit
        $count = Workflow::forUser($context->from)->count();
        if ($count >= 50) {
            return AgentResult::reply(
                "Tu as atteint la limite de 50 workflows.\n"
                . "Supprime ou archive des workflows pour en creer de nouveaux.\n\n"
                . "→ /workflow clean\n"
                . "→ /workflow archive [nom]"
            );
        }

        $preview = $this->formatWorkflowPreview($matched);
        $this->setPendingContext($context, 'confirm_workflow', $matched, 3);

        $this->log($context, "Quick-create: {$keyword}", ['template' => $keyword, 'name' => $name]);

        return AgentResult::reply(
            "⚡ *Creation rapide: {$keyword}*\n\n"
            . "Workflow a creer:\n{$preview}\n\n"
            . "Confirmer? (oui/non/ou tape un nom personnalise)"
        );
    }

    /**
     * Overview: bird's-eye view combining streak, pinned workflows, recent activity, and quick actions.
     * Usage: /workflow overview
     */
    private function commandOverview(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->get()
            ->filter(fn($wf) => empty($wf->conditions['archived']));

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "*📊 Vue d'ensemble*\n\n"
                . "Aucun workflow actif.\n\n"
                . "Commence par creer ton premier workflow:\n"
                . "  /workflow create [nom] [etape1] then [etape2]\n"
                . "  /workflow quick-create morning\n"
                . "  /workflow template"
            );
        }

        $active = $workflows->where('is_active', true);
        $paused = $workflows->filter(fn($wf) => !empty($wf->conditions['paused']));
        $pinned = $workflows->filter(fn($wf) => $this->isPinned($wf));
        $totalRuns = $workflows->sum('run_count');

        $lines = [
            "*📊 Vue d'ensemble*",
            str_repeat('━', 28),
            "",
        ];

        // Stats summary
        $lines[] = "📈 *{$workflows->count()}* workflows · *{$active->count()}* actifs · *{$totalRuns}* executions";

        if ($paused->isNotEmpty()) {
            $lines[] = "⏸ {$paused->count()} en pause";
        }
        $lines[] = "";

        // Streak mini
        $allDates = [];
        foreach ($workflows as $wf) {
            if ($wf->last_run_at) {
                $allDates[$wf->last_run_at->format('d/m')] = true;
            }
            foreach (($wf->conditions['history'] ?? []) as $entry) {
                $dateStr = $entry['date'] ?? null;
                if ($dateStr) {
                    $dayPart = explode(' ', $dateStr)[0] ?? '';
                    if (!empty($dayPart)) {
                        $allDates[$dayPart] = true;
                    }
                }
            }
        }

        $currentStreak = 0;
        $checkDate = now()->copy();
        $todayKey = $checkDate->format('d/m');
        if (isset($allDates[$todayKey])) {
            $currentStreak = 1;
            $checkDate->subDay();
            while (isset($allDates[$checkDate->format('d/m')])) {
                $currentStreak++;
                $checkDate->subDay();
            }
        } else {
            $checkDate->subDay();
            if (isset($allDates[$checkDate->format('d/m')])) {
                $currentStreak = 1;
                $checkDate->subDay();
                while (isset($allDates[$checkDate->format('d/m')])) {
                    $currentStreak++;
                    $checkDate->subDay();
                }
            }
        }

        $streakEmoji = match (true) {
            $currentStreak >= 30 => '🏆',
            $currentStreak >= 14 => '🔥',
            $currentStreak >= 7  => '⚡',
            $currentStreak >= 3  => '✨',
            $currentStreak >= 1  => '🌱',
            default              => '💤',
        };
        $lines[] = "{$streakEmoji} *Serie:* {$currentStreak} jour" . ($currentStreak !== 1 ? 's' : '') . " consecutif" . ($currentStreak !== 1 ? 's' : '');
        $lines[] = "";

        // Pinned workflows
        if ($pinned->isNotEmpty()) {
            $lines[] = "📌 *Epingles:*";
            foreach ($pinned->take(5) as $wf) {
                $icon = $wf->is_active ? '✅' : '⏸';
                $runs = $wf->run_count;
                $lines[] = "  {$icon} {$wf->name} ({$runs}x)";
            }
            $lines[] = "";
        }

        // Recently executed (top 3)
        $recent = $workflows->filter(fn($wf) => $wf->last_run_at !== null)
            ->sortByDesc('last_run_at')
            ->take(3);

        if ($recent->isNotEmpty()) {
            $lines[] = "🕐 *Recemment lances:*";
            foreach ($recent as $wf) {
                $ago = $wf->last_run_at->diffForHumans();
                $result = $wf->conditions['last_result'] ?? '';
                $icon = match ($result) {
                    'success' => '✅',
                    'failed'  => '❌',
                    'partial' => '⚠',
                    default   => '▶️',
                };
                $lines[] = "  {$icon} {$wf->name} — {$ago}";
            }
            $lines[] = "";
        }

        // Top tags
        $allTags = [];
        foreach ($workflows as $wf) {
            foreach ($wf->conditions['tags'] ?? [] as $t) {
                $t = mb_strtolower($t);
                $allTags[$t] = ($allTags[$t] ?? 0) + 1;
            }
        }
        if (!empty($allTags)) {
            arsort($allTags);
            $topTags = array_slice($allTags, 0, 5, true);
            $tagStr = implode('  ', array_map(fn($t, $c) => "#{$t}({$c})", array_keys($topTags), array_values($topTags)));
            $lines[] = "🏷 *Tags:* {$tagStr}";
            $lines[] = "";
        }

        // Quick actions
        $lines[] = str_repeat('─', 24);
        $lines[] = "▶️ /workflow go — lancer le prioritaire";
        $lines[] = "📋 /workflow dashboard — tableau complet";
        $lines[] = "🔥 /workflow streak — details serie";
        $lines[] = "🎯 /workflow focus [tag] — filtrer";
        $lines[] = "⚡ /workflow quick-create [type] — creer vite";

        $this->log($context, 'Overview displayed', [
            'workflows' => $workflows->count(),
            'streak' => $currentStreak,
        ]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Clone all steps from one workflow to another, replacing the target's steps.
     * Usage: /workflow clone-steps [source] [target]
     */
    private function commandCloneSteps(AgentContext $context, string $sourceName, string $targetName): AgentResult
    {
        $sourceName = trim(mb_strtolower($sourceName));
        $targetName = trim(mb_strtolower($targetName));

        if (empty($sourceName) || empty($targetName)) {
            return AgentResult::reply(
                "*🔄 Cloner les etapes*\n\n"
                . "Copie toutes les etapes d'un workflow vers un autre.\n\n"
                . "Usage: /workflow clone-steps [source] [cible]\n\n"
                . "Exemples:\n"
                . "  /workflow clone-steps morning-brief evening-routine\n"
                . "  /workflow clone-steps daily-check weekly-review\n\n"
                . "⚠ Les etapes existantes de la cible seront *remplacees*.\n"
                . "💡 Utilise /workflow snapshot [cible] avant pour sauvegarder."
            );
        }

        if ($sourceName === $targetName) {
            return AgentResult::reply("La source et la cible doivent etre differentes.");
        }

        $source = $this->findWorkflow($context, $sourceName);
        if (!$source) {
            return AgentResult::reply("Workflow source *{$sourceName}* introuvable.\nVerifie avec /workflow list.");
        }

        $target = $this->findWorkflow($context, $targetName);
        if (!$target) {
            return AgentResult::reply("Workflow cible *{$targetName}* introuvable.\nVerifie avec /workflow list.");
        }

        $sourceSteps = $source->steps ?? [];
        if (empty($sourceSteps)) {
            return AgentResult::reply("Le workflow source *{$source->name}* n'a aucune etape a cloner.");
        }

        try {
            // Backup target steps before overwriting
            $this->backupSteps($target);

            $target->update(['steps' => $sourceSteps]);

            $stepCount = count($sourceSteps);

            $this->log($context, "Clone steps: {$source->name} → {$target->name}", [
                'source' => $source->name,
                'target' => $target->name,
                'steps'  => $stepCount,
            ]);

            $lines = [
                "*🔄 Etapes clonees*",
                str_repeat('─', 24),
                "",
                "📤 Source: *{$source->name}*",
                "📥 Cible: *{$target->name}*",
                "📋 *{$stepCount}* etape" . ($stepCount > 1 ? 's' : '') . " copiee" . ($stepCount > 1 ? 's' : ''),
                "",
            ];

            foreach ($sourceSteps as $i => $step) {
                $agent = $step['agent'] ?? 'auto';
                $msg = mb_substr($step['message'] ?? '', 0, 50);
                $lines[] = "  " . ($i + 1) . ". [{$agent}] {$msg}";
            }

            $lines[] = "";
            $lines[] = str_repeat('─', 24);
            $lines[] = "Voir: /workflow show {$target->name}";
            $lines[] = "Annuler: /workflow undo {$target->name}";
            $lines[] = "Lancer: /workflow trigger {$target->name}";

            return AgentResult::reply(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: clone-steps failed", [
                'source' => $sourceName,
                'target' => $targetName,
                'error'  => $e->getMessage(),
            ]);
            return AgentResult::reply("Erreur lors du clonage des etapes. Reessaie.");
        }
    }

    /**
     * KPI dashboard: key performance indicators across all workflows.
     * Usage: /workflow kpi [days?]
     */
    private function commandKpi(AgentContext $context, string $daysArg = ''): AgentResult
    {
        $days = max(1, min(365, (int) ($daysArg ?: 30)));
        $cutoff = now()->subDays($days);

        $workflows = Workflow::forUser($context->from)->get()
            ->filter(fn($wf) => empty($wf->conditions['archived']));

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow.\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        $active = $workflows->where('is_active', true);
        $totalRuns = $workflows->sum('run_count');
        $totalSteps = $workflows->sum(fn($wf) => count($wf->steps ?? []));

        // Execution stats from history
        $runsInPeriod = 0;
        $successInPeriod = 0;
        $failedInPeriod = 0;
        $totalDuration = 0;
        $durationCount = 0;
        $workflowsUsedInPeriod = [];
        $agentUsage = [];

        foreach ($workflows as $wf) {
            $history = $wf->conditions['history'] ?? [];
            foreach ($history as $entry) {
                $runsInPeriod++;
                $workflowsUsedInPeriod[$wf->name] = true;
                if (($entry['status'] ?? '') === 'success') {
                    $successInPeriod++;
                } else {
                    $failedInPeriod++;
                }
                if (!empty($entry['duration'])) {
                    $totalDuration += (float) $entry['duration'];
                    $durationCount++;
                }
            }

            // Track agent usage
            foreach ($wf->steps ?? [] as $step) {
                $agent = $step['agent'] ?? 'auto';
                $agentUsage[$agent] = ($agentUsage[$agent] ?? 0) + 1;
            }
        }

        $successRate = $runsInPeriod > 0 ? round(($successInPeriod / $runsInPeriod) * 100) : 0;
        $avgDuration = $durationCount > 0 ? round($totalDuration / $durationCount, 1) : 0;
        $neverRun = $workflows->where('run_count', 0)->count();

        // Reliability score (0-100)
        $reliabilityScore = 0;
        if ($workflows->count() > 0) {
            $activeRatio = $active->count() / $workflows->count();
            $usageRatio = $workflows->count() > 0 ? min(1, count($workflowsUsedInPeriod) / $workflows->count()) : 0;
            $reliabilityScore = round(($successRate * 0.5 + $activeRatio * 100 * 0.25 + $usageRatio * 100 * 0.25));
        }

        $scoreEmoji = match (true) {
            $reliabilityScore >= 90 => '🏆',
            $reliabilityScore >= 70 => '✅',
            $reliabilityScore >= 50 => '⚡',
            $reliabilityScore >= 30 => '⚠',
            default                 => '🔴',
        };

        $lines = [
            "*📊 KPI Dashboard* — {$days} derniers jours",
            str_repeat('━', 30),
            "",
            "{$scoreEmoji} *Score global: {$reliabilityScore}/100*",
            "",
            "*📈 Vue d'ensemble:*",
            "  Workflows: *{$workflows->count()}* ({$active->count()} actifs)",
            "  Etapes totales: *{$totalSteps}*",
            "  Executions totales: *{$totalRuns}*",
            "",
            "*🎯 Periode ({$days}j):*",
            "  Executions: *{$runsInPeriod}*",
            "  Taux de succes: *{$successRate}%* ({$successInPeriod}✅ / {$failedInPeriod}❌)",
        ];

        if ($avgDuration > 0) {
            $lines[] = "  Duree moyenne: *{$avgDuration}s*";
        }

        $lines[] = "  Workflows utilises: *" . count($workflowsUsedInPeriod) . "/{$workflows->count()}*";

        if ($neverRun > 0) {
            $lines[] = "  Jamais lances: *{$neverRun}* ⚠";
        }

        // Top agents
        if (!empty($agentUsage)) {
            arsort($agentUsage);
            $topAgents = array_slice($agentUsage, 0, 5, true);
            $lines[] = "";
            $lines[] = "*🤖 Top agents:*";
            foreach ($topAgents as $agent => $count) {
                $bar = str_repeat('█', min(10, (int) ceil($count / max(1, max($agentUsage)) * 10)));
                $lines[] = "  {$agent}: {$bar} ({$count})";
            }
        }

        // Most active workflows
        $topWorkflows = $workflows->sortByDesc('run_count')->take(5)->filter(fn($wf) => $wf->run_count > 0);
        if ($topWorkflows->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "*🏅 Top workflows:*";
            $rank = 1;
            foreach ($topWorkflows as $wf) {
                $medal = match ($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => '  ' };
                $lines[] = "  {$medal} {$wf->name} — {$wf->run_count}x";
                $rank++;
            }
        }

        // Recommendations
        $tips = [];
        if ($neverRun > 2) {
            $tips[] = "💡 {$neverRun} workflows jamais lances — /workflow clean pour nettoyer";
        }
        if ($successRate < 70 && $runsInPeriod > 0) {
            $tips[] = "💡 Taux de succes bas — /workflow diagnose [nom] pour identifier les problemes";
        }
        if ($active->count() < $workflows->count() * 0.5) {
            $tips[] = "💡 Moins de 50% actifs — reactive ou archive les inutilises";
        }

        if (!empty($tips)) {
            $lines[] = "";
            $lines[] = "*💡 Recommandations:*";
            foreach ($tips as $tip) {
                $lines[] = "  {$tip}";
            }
        }

        $lines[] = "";
        $lines[] = str_repeat('─', 24);
        $lines[] = "📋 /workflow dashboard — vue detaillee";
        $lines[] = "🔥 /workflow streak — serie de jours";
        $lines[] = "🔍 /workflow health — audit complet";

        $this->log($context, 'KPI dashboard', [
            'days'   => $days,
            'score'  => $reliabilityScore,
            'runs'   => $runsInPeriod,
            'rate'   => $successRate,
        ]);

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Batch summary of all workflows: /workflow summary-all
     * Generates a compact AI-powered overview of every workflow.
     */
    private function commandSummaryAll(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->get()
            ->filter(fn($wf) => empty($wf->conditions['archived']));

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow a resumer.\n\nCree-en un avec:\n/workflow create [nom] [etape1] then [etape2]"
            );
        }

        if ($workflows->count() > self::SUMMARY_ALL_LIMIT) {
            $workflows = $workflows->take(self::SUMMARY_ALL_LIMIT);
        }

        // Build a compact description of all workflows for the LLM
        $descriptions = [];
        foreach ($workflows as $wf) {
            $steps = collect($wf->steps ?? [])->map(fn($s, $i) => ($i + 1) . '. ' . ($s['message'] ?? '?'))->implode('; ');
            $status = $wf->is_active ? 'actif' : 'inactif';
            $runs = $wf->run_count ?? 0;
            $descriptions[] = "- {$wf->name} ({$status}, {$runs}x): {$steps}";
        }

        $model = $this->resolveModel($context);

        try {
            $response = $this->claude->chat(
                "Voici les workflows de l'utilisateur:\n\n" . implode("\n", $descriptions),
                $model,
                "Tu es un assistant qui resume des workflows d'automatisation.\n"
                . "Pour chaque workflow, ecris UNE phrase de resume concise (max 15 mots) qui explique son objectif.\n"
                . "Reponds UNIQUEMENT en JSON valide: {\"summaries\": {\"nom-workflow\": \"resume\", ...}}\n"
                . "Aucun markdown, aucun backtick.",
                self::SUMMARY_MAX_TOKENS
            );
        } catch (\Throwable $e) {
            Log::error("StreamlineAgent: summary-all LLM failed", ['error' => $e->getMessage()]);
            // Fallback: generate summaries without LLM
            $lines = ["📝 *Resume de tous les workflows*", str_repeat('━', 30), ""];
            foreach ($workflows as $wf) {
                $stepCount = count($wf->steps ?? []);
                $status = $wf->is_active ? '✅' : '⏸';
                $lines[] = "{$status} *{$wf->name}* — {$stepCount} etape" . ($stepCount > 1 ? 's' : '') . ", {$wf->run_count}x lance";
            }
            return AgentResult::reply(implode("\n", $lines));
        }

        $parsed = $this->parseJson($response);
        $summaries = $parsed['summaries'] ?? [];

        $lines = ["📝 *Resume de tous les workflows*", str_repeat('━', 30), ""];

        foreach ($workflows as $wf) {
            $status = $wf->is_active ? '✅' : '⏸';
            $summary = $summaries[$wf->name] ?? null;
            $stepCount = count($wf->steps ?? []);
            $runsLabel = $wf->run_count > 0 ? " ({$wf->run_count}x)" : '';

            if ($summary) {
                $lines[] = "{$status} *{$wf->name}*{$runsLabel}";
                $lines[] = "   _{$summary}_";
            } else {
                $lines[] = "{$status} *{$wf->name}* — {$stepCount} etape" . ($stepCount > 1 ? 's' : '') . $runsLabel;
            }
            $lines[] = "";
        }

        $lines[] = str_repeat('─', 24);
        $lines[] = "📋 /workflow show [nom] — details complets";
        $lines[] = "🚀 /workflow trigger [nom] — lancer";

        $this->log($context, 'summary-all', ['count' => $workflows->count()]);
        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Compare performance metrics across the user's top workflows.
     * Shows success rate, avg execution time, frequency — sorted by a composite score.
     */
    private function commandBenchmark(AgentContext $context, string $arg): AgentResult
    {
        $workflows = Workflow::forUser($context->from)
            ->where('run_count', '>', 0)
            ->get()
            ->filter(fn($wf) => empty($wf->conditions['archived']));

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow execute pour comparer.\n\n"
                . "Lance tes workflows d'abord avec /workflow trigger [nom]"
            );
        }

        $limit = is_numeric($arg) && (int) $arg > 0 ? min((int) $arg, 15) : 10;
        $scored = [];

        foreach ($workflows as $wf) {
            $runs     = $wf->run_count ?? 0;
            $success  = $wf->conditions['success_count'] ?? $runs;
            $failures = $wf->conditions['failure_count'] ?? 0;
            $total    = $success + $failures;
            $rate     = $total > 0 ? round(($success / $total) * 100) : 100;

            $history     = $wf->conditions['history'] ?? [];
            $durations   = [];
            foreach ($history as $entry) {
                if (isset($entry['duration_ms']) && $entry['duration_ms'] > 0) {
                    $durations[] = $entry['duration_ms'];
                }
            }
            $avgMs   = !empty($durations) ? (int) (array_sum($durations) / count($durations)) : null;
            $avgLabel = $avgMs !== null ? round($avgMs / 1000, 1) . 's' : '?';

            // Composite score: success rate (60%) + frequency (40%)
            $freqScore = min($runs / 10, 1.0); // normalize to 0-1 (10+ runs = max)
            $score     = ($rate / 100) * 0.6 + $freqScore * 0.4;

            $scored[] = [
                'wf'       => $wf,
                'runs'     => $runs,
                'rate'     => $rate,
                'avgLabel' => $avgLabel,
                'score'    => $score,
            ];
        }

        // Sort by composite score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, $limit);

        $lines = ["📊 *Benchmark des workflows*", str_repeat('━', 30), ""];

        foreach ($scored as $i => $item) {
            $rank    = $i + 1;
            $medal   = match ($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "#{$rank}" };
            $wf      = $item['wf'];
            $bar     = $this->progressBar($item['rate']);
            $status  = $wf->is_active ? '' : ' ⏸';

            $lines[] = "{$medal} *{$wf->name}*{$status}";
            $lines[] = "   {$bar} {$item['rate']}% succes · {$item['runs']}x · ~{$item['avgLabel']}";
            $lines[] = "";
        }

        $totalRuns = $workflows->sum('run_count');
        $activeCount = $workflows->where('is_active', true)->count();
        $lines[] = str_repeat('─', 24);
        $lines[] = "Total: {$totalRuns} executions sur {$activeCount} workflow(s) actif(s)";
        $lines[] = "→ /workflow optimize [nom] — ameliorer un workflow";

        $this->log($context, 'benchmark', ['count' => count($scored)]);
        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Simulate "what if" a step is removed/disabled: show impact on workflow.
     */
    private function commandWhatIf(AgentContext $context, string $workflowName, string $arg): AgentResult
    {
        if (empty($workflowName)) {
            return AgentResult::reply("Usage: /workflow whatif [nom] [numero_etape]\nEx: /workflow whatif morning-brief 2");
        }

        $result = $this->findWorkflowOrAmbiguous($context, $workflowName);
        if ($result instanceof AgentResult) {
            return $result;
        }
        $workflow = $result;

        $steps = $workflow->steps ?? [];
        $stepCount = count($steps);

        if ($stepCount === 0) {
            return AgentResult::reply("Le workflow \"{$workflow->name}\" n'a aucune etape.");
        }

        $stepNum = (int) trim($arg);
        if ($stepNum < 1 || $stepNum > $stepCount) {
            return AgentResult::reply(
                "Numero d'etape invalide. Le workflow \"{$workflow->name}\" a {$stepCount} etape"
                . ($stepCount > 1 ? 's' : '') . " (1-{$stepCount})."
            );
        }

        $targetStep = $steps[$stepNum - 1];
        $targetMsg  = $targetStep['message'] ?? '(vide)';
        $targetAgent = $targetStep['agent'] ?? 'auto';
        $isDisabled = !empty($targetStep['disabled']);

        $lines = ["🔮 *What-if: retrait de l'etape {$stepNum}*", str_repeat('━', 30), ""];
        $lines[] = "*Workflow:* {$workflow->name}";
        $lines[] = "*Etape ciblee:* #{$stepNum} — _{$targetMsg}_";
        $lines[] = "*Agent:* {$targetAgent}";
        $lines[] = "";

        if ($isDisabled) {
            $lines[] = "ℹ️ Cette etape est deja desactivee — son retrait n'aurait aucun impact.";
            $lines[] = "";
            $lines[] = str_repeat('─', 24);
            $lines[] = "→ /workflow remove-step {$workflow->name} {$stepNum} — supprimer definitivement";
            $this->log($context, 'whatif: step already disabled', ['workflow' => $workflow->name, 'step' => $stepNum]);
            return AgentResult::reply(implode("\n", $lines));
        }

        // Analyze impact
        $impacts = [];

        // 1. Check if subsequent steps depend on this one via condition="success"
        $dependentSteps = [];
        for ($i = $stepNum; $i < $stepCount; $i++) {
            $cond = $steps[$i]['condition'] ?? 'always';
            if ($cond === 'success') {
                $dependentSteps[] = $i + 1;
            } elseif ($cond !== 'always') {
                break; // stop at first non-dependent
            }
        }
        if (!empty($dependentSteps)) {
            $depList = implode(', ', $dependentSteps);
            $impacts[] = "⚠️ Les etape(s) #{$depList} dependent du succes de l'etape {$stepNum} (condition=success). Elles pourraient ne plus se declencher correctement.";
        }

        // 2. Step count impact
        $newCount = $stepCount - 1;
        if ($newCount === 0) {
            $impacts[] = "🚨 Le workflow deviendrait *vide* (0 etape). Il ne ferait plus rien.";
        } else {
            $impacts[] = "📉 Le workflow passerait de {$stepCount} a {$newCount} etape" . ($newCount > 1 ? 's' : '') . ".";
        }

        // 3. Agent coverage impact
        $agents = collect($steps)->pluck('agent')->filter()->unique()->values();
        $remainingAgents = collect($steps)->forget($stepNum - 1)->pluck('agent')->filter()->unique()->values();
        $lostAgents = $agents->diff($remainingAgents);
        if ($lostAgents->isNotEmpty()) {
            $impacts[] = "🔌 Agent(s) perdu(s): *" . $lostAgents->implode(', ') . "* — plus aucune etape ne l'utilise.";
        }

        // 4. On-error behavior
        $onError = $targetStep['on_error'] ?? 'stop';
        if ($onError === 'stop') {
            $impacts[] = "🛑 Cette etape avait on_error=stop: en cas d'erreur, elle stoppait la chain. Sans elle, les etapes suivantes s'executeront toujours.";
        }

        // 5. Execution time estimate
        $history = $workflow->conditions['history'] ?? [];
        if (!empty($history)) {
            $avgDuration = collect($history)
                ->pluck('duration_ms')
                ->filter()
                ->avg();
            if ($avgDuration && $stepCount > 0) {
                $perStep = $avgDuration / $stepCount;
                $saved = round($perStep / 1000, 1);
                if ($saved > 0) {
                    $impacts[] = "⏱ Gain estime: ~{$saved}s par execution.";
                }
            }
        }

        foreach ($impacts as $impact) {
            $lines[] = $impact;
        }

        $lines[] = "";
        $lines[] = "*Apercu du workflow sans l'etape {$stepNum}:*";
        $remaining = 1;
        foreach ($steps as $i => $step) {
            if ($i === $stepNum - 1) continue;
            $msg = mb_substr($step['message'] ?? '?', 0, 50);
            $disabled = !empty($step['disabled']) ? ' 🚫' : '';
            $lines[] = "  {$remaining}. {$msg}{$disabled}";
            $remaining++;
        }

        $lines[] = "";
        $lines[] = str_repeat('─', 24);
        $lines[] = "→ /workflow disable-step {$workflow->name} {$stepNum} — desactiver sans supprimer";
        $lines[] = "→ /workflow remove-step {$workflow->name} {$stepNum} — supprimer definitivement";
        $lines[] = "→ /workflow dryrun {$workflow->name} — simuler en l'etat";

        $this->log($context, 'whatif', ['workflow' => $workflow->name, 'step' => $stepNum, 'impacts' => count($impacts)]);
        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Build a simple text progress bar for WhatsApp.
     */
    private function progressBar(int $percent, int $width = 10): string
    {
        $filled = (int) round($percent / (100 / $width));
        $empty  = $width - $filled;
        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }
}
