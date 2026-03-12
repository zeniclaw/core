<?php

namespace App\Services\Agents;

use App\Jobs\RunSubAgentJob;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SubAgent;
use App\Models\UserKnowledge;
use App\Services\AgentContext;
use App\Services\GitLabService;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Log;

class DevAgent extends BaseAgent
{
    private ?GitLabService $gitlab = null;

    public function name(): string
    {
        return 'dev';
    }

    public function description(): string
    {
        return 'Agent developpeur avec commandes GitLab intelligentes. Gere les taches de developpement, code review, deploiement, et permet d\'interagir avec les projets GitLab : lister les projets, branches, MRs, pipelines, commits, issues, arborescence, rollback, deploiement, diagnostic de sante du projet.';
    }

    public function keywords(): array
    {
        return [
            // Smart commands - Projects
            'liste projets', 'mes projets', 'mes repos', 'projets dev', 'projets gitlab',
            'my projects', 'list projects', 'show projects',
            // Branches
            'branches', 'liste branches', 'mes branches', 'show branches', 'list branches',
            'branche', 'branch',
            // Merge Requests
            'MRs', 'merge requests', 'merge request', 'pull requests', 'PR', 'PRs',
            'mes MRs', 'mes merge requests', 'liste MRs', 'show MRs', 'list MRs',
            'merge', 'fusionner',
            // Pipeline / CI
            'pipeline', 'pipelines', 'CI', 'CI/CD', 'build', 'builds',
            'status pipeline', 'etat pipeline', 'dernier build',
            'continuous integration', 'integration continue',
            // Commits
            'commits', 'commit', 'derniers commits', 'historique commits',
            'log git', 'git log', 'show commits', 'list commits',
            // Issues / Tickets
            'issues', 'issue', 'tickets', 'ticket', 'bug', 'bugs',
            'mes issues', 'mes tickets', 'liste issues', 'list issues',
            'ouvrir issue', 'creer issue', 'create issue', 'open issue',
            // Files / Tree
            'arborescence', 'fichiers', 'tree', 'file tree', 'structure',
            'voir fichiers', 'show files', 'list files', 'ls', 'dossiers',
            'contenu fichier', 'lire fichier', 'read file', 'cat',
            // Rollback / Deploy
            'rollback', 'revert', 'annuler deploy', 'undo deploy',
            'deploy', 'deployer', 'deploiement', 'deployment', 'mise en prod',
            'mettre en prod', 'push en prod', 'release',
            // Health / Diagnostic
            'health projet', 'diagnostic', 'sante projet', 'project health',
            'etat du projet', 'status projet', 'project status',
            // Dev general
            'dev', 'developpement', 'development', 'coder', 'code',
            'programmer', 'programming', 'developper', 'develop',
            'modifier le code', 'changer le code', 'edit code',
            'corriger', 'fix', 'fixer', 'bugfix', 'hotfix',
            'refactorer', 'refactoring', 'refacto',
            'implementer', 'implement', 'ajouter feature', 'add feature',
            'gitlab', 'git', 'repo', 'repository', 'depot',
            'tache dev', 'task dev', 'dev task',
            'tag', 'tags', 'version', 'versions',
            'diff', 'compare', 'comparer branches',
            'cherry-pick', 'cherry pick', 'rebase',
            'stash', 'checkout',
            // API interaction
            'api', 'endpoint', 'route', 'appel api', 'requete api',
            'lister les donnees', 'voir les donnees', 'combien de',
            'campagnes', 'users', 'clients', 'commandes', 'produits',
            'statistiques', 'stats', 'dashboard data',
        ];
    }

    public function version(): string
    {
        return '2.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'dev';
    }

    public function intents(): array
    {
        return [
            // GitLab consultation commands
            ['key' => 'list_projects', 'description' => 'Lister les projets GitLab', 'examples' => ['mes projets', 'liste les repos', 'quels projets tu geres']],
            ['key' => 'project_info', 'description' => 'Voir info/selectionner/switcher de projet', 'examples' => ['status du projet zeniclaw', 'passe sur invoices', 'bosser sur padel']],
            ['key' => 'list_branches', 'description' => 'Voir les branches', 'examples' => ['les branches de mon-app', 'branches']],
            ['key' => 'list_mrs', 'description' => 'Voir les merge requests', 'examples' => ['MRs ouvertes', 'merge requests']],
            ['key' => 'pipeline_status', 'description' => 'Status CI/CD', 'examples' => ['la CI passe ?', 'status pipeline']],
            ['key' => 'recent_commits', 'description' => 'Derniers commits', 'examples' => ['derniers commits', 'git log']],
            ['key' => 'list_issues', 'description' => 'Voir les issues/tickets', 'examples' => ['les issues', 'bugs ouverts']],
            ['key' => 'create_issue', 'description' => 'Creer un ticket/issue (args: title)', 'examples' => ['creer issue: fix login', 'ouvrir un ticket']],
            ['key' => 'search_code', 'description' => 'Chercher dans le code (args: query)', 'examples' => ['cherche "sendEmail" dans le code']],
            ['key' => 'file_tree', 'description' => 'Arborescence/fichiers du projet', 'examples' => ['structure du projet', 'arborescence']],
            ['key' => 'read_file', 'description' => 'Lire un fichier (args: path)', 'examples' => ['montre moi routes/api.php', 'lire le fichier .env.example']],
            ['key' => 'compare_branches', 'description' => 'Comparer/diff entre branches (args: from, to)', 'examples' => ['diff main vs develop']],
            ['key' => 'project_health', 'description' => 'Bilan de sante/diagnostic', 'examples' => ['diagnostic projet', 'health check']],
            ['key' => 'task_history', 'description' => 'Historique des taches', 'examples' => ['historique des taches']],
            ['key' => 'rollback', 'description' => 'Annuler la derniere modif', 'examples' => ['rollback', 'annule le dernier deploy']],
            ['key' => 'deploy_status', 'description' => 'Status deploiement', 'examples' => ['status deploy', 'mise en prod']],
            // API interaction
            ['key' => 'api_query', 'description' => 'Utiliser l\'API live du projet: lister, creer, modifier, supprimer des entites (projets, campagnes, prospects, etc.) via les endpoints API. PAS modifier le code source.', 'examples' => ['liste les campagnes', 'combien de users', 'donne moi les apis du projet', 'cree un projet', 'cree une campagne marketing', 'ajoute des prospects']],
            ['key' => 'api_credentials', 'description' => 'L\'utilisateur donne/partage des credentials: cle API, token, endpoint, URL d\'API, secret. Il veut les stocker, pas coder.', 'examples' => ['voici la cle pk_xxx et le endpoint https://...', 'utilise cette API key: xxx', 'le token c\'est abc123']],
            // Dev tasks (code changes)
            ['key' => 'dev_task', 'description' => 'Tache de developpement: modifier code, fix bug, ajouter feature, refactoring. Necessite modification de code.', 'examples' => ['fix le bug du login', 'ajoute un bouton', 'refactore le controller']],
        ];
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            return $this->handleInner($context);
        } catch (\Throwable $e) {
            Log::error('DevAgent handle() exception', [
                'from' => $context->from,
                'body' => mb_substr($context->body ?? '', 0, 300),
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 1500),
            ]);
            $this->log($context, 'EXCEPTION: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ], 'error');

            $reply = "Erreur interne de l'agent dev. Verifie les logs debug.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['error' => $e->getMessage()]);
        }
    }

    private function handleInner(AgentContext $context): AgentResult
    {
        // Check pending context first (list selection, ambiguous project, etc.)
        $pendingCtx = $context->session->pending_agent_context;
        if ($pendingCtx && ($pendingCtx['agent'] ?? '') === 'dev') {
            $this->log($context, 'Pending context found', ['type' => $pendingCtx['type'] ?? '']);
            $result = $this->handlePendingContext($context, $pendingCtx);
            if ($result) return $result;
        }

        // Handle task awaiting validation
        $awaitingProject = Project::where('status', 'awaiting_validation')
            ->where('requester_phone', $context->from)
            ->orderByDesc('created_at')
            ->first();

        if ($awaitingProject) {
            $this->log($context, 'Awaiting validation', ['project' => $awaitingProject->name]);
            return $this->handleTaskValidation($awaitingProject, $context);
        }

        // Intent classification — enrich with project context so classifier knows about API credentials
        $activeProjectHint = '';
        $activeProject = null;
        if ($context->session->active_project_id) {
            $activeProject = Project::find($context->session->active_project_id);
            if ($activeProject) {
                $activeProjectHint = "PROJET ACTIF: {$activeProject->name}\n";
                $settings = $activeProject->settings ?? [];
                $hasApiCreds = !empty($settings['api_key']) || !empty($settings['base_url']) || !empty($settings['api_endpoint']);
                if ($hasApiCreds) {
                    $credKeys = array_keys(array_filter($settings, fn($v) => !empty($v)));
                    $activeProjectHint .= "CREDENTIALS API CONFIGUREES: " . implode(', ', $credKeys) . "\n";
                    $activeProjectHint .= "IMPORTANT: Ce projet a des credentials API. Si l'utilisateur demande de creer/lister/modifier des entites (projets, campagnes, prospects, etc.), utilise 'api_query' PAS 'dev_task'. 'dev_task' = modifier le CODE SOURCE. 'api_query' = utiliser l'API live.\n";
                }
            }
        }

        $this->log($context, 'Classifying intent...');
        $classified = $this->classifyIntent($context, $activeProjectHint);

        $this->log($context, 'Intent classified', [
            'intent' => $classified['intent'],
            'confidence' => $classified['confidence'],
            'args' => $classified['args'],
        ]);

        // Try intent dispatch
        $result = $this->dispatchIntent($classified, $context);
        if ($result) return $result;

        $this->log($context, 'No handler for intent, fallback to dev_task');
        // Fallback: treat as dev task
        return $this->handleIntentDevTask($classified['args'], $context);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';
        $data = $pendingContext['data'] ?? [];

        return match ($type) {
            'list_selection' => $this->handleListSelection($context, $data),
            'ambiguous_project' => $this->handleAmbiguousProjectSelection($context, $data),
            'api_followup' => $this->handleApiFollowup($context, $data),
            default => null,
        };
    }

    private function handleListSelection(AgentContext $context, array $data): ?AgentResult
    {
        $body = trim($context->body);
        $items = $data['items'] ?? [];
        $action = $data['action'] ?? 'project_info';

        if (empty($items)) {
            $this->clearPendingContext($context);
            return null;
        }

        // Try to match by number
        if (is_numeric($body)) {
            $index = (int) $body - 1;
            if ($index >= 0 && $index < count($items)) {
                $this->clearPendingContext($context);
                $selected = $items[$index];
                return $this->executeListAction($context, $selected, $action);
            }
            $reply = "Numero invalide. Choisis entre 1 et " . count($items) . ".";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Try to match by name (fuzzy)
        $bodyLower = mb_strtolower($body);
        foreach ($items as $item) {
            $nameLower = mb_strtolower($item['name'] ?? '');
            if ($nameLower && (str_contains($nameLower, $bodyLower) || str_contains($bodyLower, $nameLower))) {
                $this->clearPendingContext($context);
                return $this->executeListAction($context, $item, $action);
            }
        }

        // Not understood — clear and fall through
        $this->clearPendingContext($context);
        return null;
    }

    private function handleAmbiguousProjectSelection(AgentContext $context, array $data): ?AgentResult
    {
        $body = trim($context->body);
        $candidates = $data['candidates'] ?? [];
        $originalCommand = $data['original_command'] ?? null;

        if (empty($candidates)) {
            $this->clearPendingContext($context);
            return null;
        }

        $selected = null;
        if (is_numeric($body)) {
            $index = (int) $body - 1;
            if ($index >= 0 && $index < count($candidates)) {
                $selected = $candidates[$index];
            }
        } else {
            $bodyLower = mb_strtolower($body);
            foreach ($candidates as $c) {
                $nameLower = mb_strtolower($c['path_with_namespace'] ?? $c['name'] ?? '');
                if (str_contains($nameLower, $bodyLower) || str_contains($bodyLower, $nameLower)) {
                    $selected = $c;
                    break;
                }
            }
        }

        if (!$selected) {
            $reply = "Je n'ai pas compris. Choisis un numero entre 1 et " . count($candidates) . ".";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->clearPendingContext($context);
        $project = $this->createProjectFromGitlab($selected, $context);

        // Set as active project on session
        $context->session->update(['active_project_id' => $project->id]);

        // If there was an original command, re-execute it with the resolved project
        if ($originalCommand) {
            $fakeCommand = ['command' => $originalCommand['command'], 'args' => array_merge($originalCommand['args'] ?? [], ['name' => $project->name])];
            return $this->handleSmartCommand($fakeCommand, $context);
        }

        $reply = "Projet *{$project->name}* selectionne ! Que veux-tu faire dessus ?";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    private function executeListAction(AgentContext $context, array $item, string $action): AgentResult
    {
        // Auto-import the project from GitLab if needed
        $project = $this->createProjectFromGitlab($item, $context);

        // Set as active project on session
        $context->session->update(['active_project_id' => $project->id]);

        // Execute the requested action
        $reply = match ($action) {
            'project_info' => $this->cmdProjectInfo($project->name, $context),
            'list_branches' => $this->cmdListBranches($project->name, $context),
            'list_mrs' => $this->cmdListMRs($project->name, $context),
            'pipeline_status' => $this->cmdPipelineStatus($project->name, $context),
            'recent_commits' => $this->cmdRecentCommits($project->name, $context),
            'list_issues' => $this->cmdListIssues($project->name, $context),
            'project_health' => $this->cmdProjectHealth($project->name, $context),
            default => "Projet *{$project->name}* selectionne ! Que veux-tu faire dessus ?",
        };

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    // ── API Query (100% Claude-driven Project API Interaction) ────────

    private function handleApiFollowup(AgentContext $context, array $data): ?AgentResult
    {
        $body = trim($context->body);
        $projectId = $data['project_id'] ?? null;
        $conversation = $data['conversation'] ?? [];
        $originalQuery = $data['original_query'] ?? '';
        $saveAs = $data['save_as'] ?? null;

        $project = $projectId ? Project::find($projectId) : null;
        if (!$project) {
            $this->clearPendingContext($context);
            return null;
        }

        // Store the user's response if we know what setting it corresponds to
        if ($saveAs) {
            $project->setSetting($saveAs, $body);
        }

        $this->clearPendingContext($context);

        // Add user response to conversation
        $conversation[] = ['role' => 'user', 'content' => $body];

        // Let Claude decide what to do next with updated settings
        $reply = $this->runApiAgent($project, $originalQuery, $conversation, $context);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    private function cmdApiQuery(string $query, AgentContext $context): string
    {
        $project = $this->findProjectForUser($context);
        if (!$project) {
            return "Aucun projet actif. Selectionne d'abord un projet.";
        }

        // Pass the full original message so Claude can extract all info (API keys, URLs, etc.)
        $fullMessage = $context->body ?? $query;
        return $this->runApiAgent($project, $fullMessage, [], $context);
    }

    /**
     * Agentic API loop — Claude makes multiple API calls, accumulates data,
     * and decides when it has enough to answer. Max 10 iterations.
     */
    private function runApiAgent(Project $project, string $query, array $conversation, AgentContext $context): string
    {
        $projectCode = $this->discoverProjectApi($project);
        $collectedData = [];
        $maxIterations = 10;
        $parseFailures = 0;

        $this->log($context, 'runApiAgent starting', [
            'project' => $project->name,
            'project_id' => $project->id,
            'settings_keys' => array_keys($project->settings ?? []),
            'query' => mb_substr($query, 0, 200),
        ]);

        for ($i = 0; $i < $maxIterations; $i++) {
            // Refresh settings each iteration (may have been updated by store)
            $project->refresh();
            $storedSettings = $project->settings ?? [];
            $settingsJson = !empty($storedSettings) ? json_encode($storedSettings, JSON_UNESCAPED_UNICODE) : '(aucun)';

            $conversationText = '';
            foreach ($conversation as $msg) {
                $role = $msg['role'] === 'user' ? 'UTILISATEUR' : 'ASSISTANT';
                $conversationText .= "{$role}: {$msg['content']}\n";
            }

            $collectedText = '';
            if (!empty($collectedData)) {
                $collectedText = "\n\nDONNEES DEJA COLLECTEES (appels precedents):\n";
                foreach ($collectedData as $j => $cd) {
                    $action = $cd['action'] ?? 'call';
                    $method = $cd['method'] ?? $action;
                    $url = $cd['url'] ?? '';
                    $body = $cd['response'] ?? $cd['content'] ?? '';
                    $collectedText .= "--- Appel " . ($j + 1) . ": {$method} {$url} ---\n";
                    $collectedText .= mb_substr($body, 0, 2000) . "\n\n";
                }
            }

            // Inject stored user knowledge relevant to this project
            $userKnowledge = UserKnowledge::search($context->from, $project->name);
            $knowledgeText = '';
            if ($userKnowledge->isNotEmpty()) {
                $knowledgeText = "\n\nDONNEES DEJA CONNUES POUR CET UTILISATEUR (ne pas redemander!):\n";
                foreach ($userKnowledge as $k) {
                    $knowledgeText .= "--- [{$k->topic_key}] {$k->label} ---\n";
                    $knowledgeText .= mb_substr(json_encode($k->data, JSON_UNESCAPED_UNICODE), 0, 2000) . "\n\n";
                }
            }

            $systemPrompt = <<<PROMPT
Tu es un agent API autonome. Tu fais PLUSIEURS appels si necessaire pour repondre completement.

PROJET: {$project->name}
CONFIGURATION STOCKEE: {$settingsJson}
CODE SOURCE (routes): {$projectCode}
CONVERSATION: {$conversationText}
DEMANDE: {$query}
{$collectedText}{$knowledgeText}
ITERATION: {$i} sur {$maxIterations}

ACTIONS (reponds en JSON):

A) APPEL API — Fais un appel pour collecter des donnees:
{"action": "call", "store": {}, "method": "GET", "url": "https://...", "headers": {}, "params": {}, "explanation": "pourquoi cet appel"}

B) REPONSE FINALE — Tu as ASSEZ de donnees pour repondre completement:
{"action": "reply", "store": {}, "knowledge": [{"key": "...", "label": "...", "data": {...}}], "message": "ta reponse complete et formatee"}

C) QUESTION — DERNIER RECOURS, info impossible a deduire:
{"action": "ask", "store": {}, "message": "question", "save_as": "setting_name"}

D) FETCH PAGE — Recuperer et analyser une page web (doc API, swagger, etc.):
{"action": "web_fetch", "url": "https://...", "explanation": "pourquoi cette page"}

REGLES CRITIQUES:
1. EXTRAIRE ET STOCKER IMMEDIATEMENT: Si le message contient URL, cle API, token, endpoint, identifiants → stocke-les dans "store" ET confirme avec "reply". Exemple: si l'utilisateur dit "voici la cle abc123 et le endpoint https://api.example.com", tu reponds:
{"action": "reply", "store": {"api_key": "abc123", "api_endpoint": "https://api.example.com"}, "message": "[projet] Configuration enregistree ! Cle API et endpoint sauvegardes. Je peux maintenant interroger l'API."}
NE PROPOSE PAS de code, d'integration, ou de plan technique quand l'utilisateur donne juste des credentials. Stocke et confirme.
2. AGIR: Ne pose JAMAIS de question si tu peux deduire du code source ou des settings. NE REDEMANDE JAMAIS une cle API, un token ou un endpoint si il est DEJA dans la CONFIGURATION STOCKEE ci-dessus.
3. UTILISER LES CREDENTIALS: Si des cles/endpoints sont dans la CONFIGURATION STOCKEE, utilise-les IMMEDIATEMENT pour faire des appels API. Ne propose pas de les integrer dans du code. Ne demande pas confirmation.
4. ENCHAINER: Si tu as besoin de plusieurs endpoints (ex: d'abord les projets, puis les factures par projet), fais-les un par un. Tu reverras les resultats a chaque iteration.
5. ANALYSER: Quand tu as assez de donnees, fais une VRAIE analyse (tendances, totaux, alertes, recommandations). Ne te contente pas de lister.
6. AUTH: Deduis le mecanisme du code (Bearer, API key header, query param...). Utilise les settings stockes.
7. REPONSE FINALE: Formate pour WhatsApp (*gras*, listes). Commence par [{$project->name}].
8. SAUVEGARDER: Dans "knowledge", inclus les donnees importantes avec ttl_minutes=60 pour les donnees qui changent (clients, factures, totaux).
9. DONNEES FRAICHES: TOUJOURS refaire l'appel API quand l'utilisateur demande explicitement une liste (clients, factures, etc.) — les donnees en cache peuvent etre obsoletes. N'utilise "DONNEES DEJA CONNUES" que pour les infos de configuration (endpoints, tokens).
10. ANTI-HALLUCINATION: N'invente JAMAIS de donnees (noms, adresses, TVA, emails, montants). Si tu n'as pas fait d'appel API et que tu n'as pas de donnees fraiches, fais l'appel. Ne restitue JAMAIS des donnees du knowledge sans les verifier via un appel API.
11. PAGINER: Si l'API retourne des resultats pagines, fais TOUS les appels necessaires pour avoir TOUTES les pages.

JSON UNIQUEMENT.
PROMPT;

            $response = $this->claude->chat($query, ModelResolver::powerful(), $systemPrompt, 8192);
            $parsed = $this->parseJson($response);

            // Self-healing: if JSON parse fails, send the raw response back to Claude to fix it
            if (!$parsed || empty($parsed['action'])) {
                $parseFailures++;
                $this->log($context, "runApiAgent parse failed at iteration {$i} (attempt {$parseFailures})", [
                    'raw_response' => mb_substr($response ?? '', 0, 500),
                ], 'warn');

                if ($parseFailures >= 3) {
                    return "[{$project->name}] Erreur d'analyse apres plusieurs tentatives. Reformule ta demande.";
                }

                // Ask Claude to fix its own broken JSON
                $fixPrompt = "Ta reponse precedente n'est PAS du JSON valide. Voici ce que tu as repondu:\n\n"
                    . mb_substr($response ?? '', 0, 3000)
                    . "\n\nCorrige et renvoie UNIQUEMENT le JSON valide avec le champ 'action' (call/reply/ask/web_fetch). JSON UNIQUEMENT, rien d'autre.";
                $fixResponse = $this->claude->chat($fixPrompt, ModelResolver::fast(), "Tu corriges du JSON invalide. Reponds UNIQUEMENT avec le JSON corrige.");
                $parsed = $this->parseJson($fixResponse);

                if (!$parsed || empty($parsed['action'])) {
                    $this->log($context, "Self-heal also failed, continuing to next iteration", [], 'warn');
                    continue;
                }

                $this->log($context, "Self-heal succeeded, recovered valid JSON", ['action' => $parsed['action']]);
            }

            // Store extracted info
            $toStore = $parsed['store'] ?? [];
            if (!empty($toStore) && is_array($toStore)) {
                foreach ($toStore as $key => $value) {
                    if ($key && $value) $project->setSetting($key, $value);
                }
            }

            // Handle action
            if ($parsed['action'] === 'reply') {
                // If we collected real API data, ALWAYS use finalizeApiResponse
                // to format it — never trust the LLM's own formatted message
                // (it hallucinates/replaces real data with invented names)
                if (!empty($collectedData)) {
                    return $this->finalizeApiResponse($project, $query, $collectedData);
                }

                // No API data collected — this is a config/info reply, trust it
                return ($parsed['message'] ?? '');
            }

            if ($parsed['action'] === 'ask') {
                return $this->handleApiAsk($project, $parsed, $query, $conversation, $context);
            }

            if ($parsed['action'] === 'call') {
                $callResult = $this->executeRawApiCall($parsed);
                $responseBody = $callResult['body'];

                // Smart response validation: detect non-JSON responses (HTML login pages, redirects)
                $trimmed = trim($responseBody);
                $isJson = str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
                $isHtml = str_starts_with($trimmed, '<!') || str_starts_with($trimmed, '<html');
                $isAuthFail = $callResult['status'] === 401 || $callResult['status'] === 403
                    || ($callResult['status'] === 200 && $isHtml && !$isJson);

                if ($isAuthFail) {
                    // Token doesn't work — inject clear error so LLM knows
                    $responseBody = "[ERREUR AUTH] L'appel a retourne du HTML (page de login) au lieu de JSON. "
                        . "Le token d'authentification est invalide ou expire. "
                        . "HTTP {$callResult['status']}. Demande a l'utilisateur un nouveau token.";
                }

                $collectedData[] = [
                    'method' => $parsed['method'] ?? 'GET',
                    'url' => $parsed['url'] ?? '',
                    'status' => $callResult['status'],
                    'response' => $responseBody,
                ];

                // Auto-store base_url on first successful JSON call
                if ($callResult['status'] >= 200 && $callResult['status'] < 300 && $isJson) {
                    $parsedUrl = parse_url($parsed['url'] ?? '');
                    $baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
                    if ($baseUrl && !$project->getSetting('base_url')) {
                        $project->setSetting('base_url', $baseUrl);
                    }
                }

                // Smart pagination: if JSON response has pagination metadata, auto-fetch all pages
                if ($isJson && $callResult['status'] >= 200 && $callResult['status'] < 300) {
                    $json = json_decode($callResult['body'], true);
                    if ($json) {
                        $collectedData = $this->autoPaginate($parsed, $json, $callResult, $collectedData);
                    }
                }
            }

            if ($parsed['action'] === 'web_fetch') {
                $fetchResult = $this->fetchWebPage($parsed['url'] ?? '');
                $collectedData[] = [
                    'action' => 'web_fetch',
                    'url' => $parsed['url'] ?? '',
                    'content' => $fetchResult,
                ];
            }
        }

        // Max iterations reached — ask Claude to summarize what it has
        return $this->finalizeApiResponse($project, $query, $collectedData);
    }

    private function executeRawApiCall(array $parsed): array
    {
        $method = strtoupper($parsed['method'] ?? 'GET');
        $url = $parsed['url'] ?? '';
        $headers = $parsed['headers'] ?? [];
        $params = $parsed['params'] ?? [];

        if (!$url) return ['status' => 0, 'body' => 'No URL'];

        try {
            $http = \Illuminate\Support\Facades\Http::timeout(15)->withHeaders($headers);
            $httpResponse = match ($method) {
                'POST' => $http->post($url, $params),
                'PUT' => $http->put($url, $params),
                'DELETE' => $http->delete($url, $params),
                default => empty($params) ? $http->get($url) : $http->get($url, $params),
            };

            return [
                'status' => $httpResponse->status(),
                'body' => mb_substr($httpResponse->body(), 0, 5000),
            ];
        } catch (\Exception $e) {
            return ['status' => 0, 'body' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Auto-paginate: detect pagination metadata and fetch remaining pages.
     * Supports Laravel-style (next_page_url), offset-based (page param), and link headers.
     */
    private function autoPaginate(array $parsedCall, array $firstPageJson, array $firstResult, array $collectedData): array
    {
        // Detect pagination patterns
        $nextUrl = null;
        $totalPages = 1;
        $currentPage = 1;

        // Laravel/standard: { "next_page_url": "...", "last_page": N, "current_page": 1 }
        if (!empty($firstPageJson['next_page_url'])) {
            $nextUrl = $firstPageJson['next_page_url'];
            $totalPages = $firstPageJson['last_page'] ?? 10;
            $currentPage = $firstPageJson['current_page'] ?? 1;
        }
        // Alternative: { "meta": { "current_page": 1, "last_page": N }, "links": { "next": "..." } }
        elseif (!empty($firstPageJson['links']['next'])) {
            $nextUrl = $firstPageJson['links']['next'];
            $totalPages = $firstPageJson['meta']['last_page'] ?? $firstPageJson['last_page'] ?? 10;
            $currentPage = $firstPageJson['meta']['current_page'] ?? 1;
        }
        // Another: { "pagination": { "next": "...", "pages": N } }
        elseif (!empty($firstPageJson['pagination']['next'])) {
            $nextUrl = $firstPageJson['pagination']['next'];
            $totalPages = $firstPageJson['pagination']['pages'] ?? 10;
        }

        if (!$nextUrl || $totalPages <= 1) return $collectedData;

        // Fetch remaining pages (max 20 to avoid runaway loops)
        $maxPages = min($totalPages, 20);
        for ($page = $currentPage + 1; $page <= $maxPages; $page++) {
            $pageCall = $parsedCall;
            $pageCall['url'] = $nextUrl;

            $pageResult = $this->executeRawApiCall($pageCall);
            $pageJson = json_decode($pageResult['body'], true);

            if (!$pageJson || $pageResult['status'] < 200 || $pageResult['status'] >= 300) break;

            $collectedData[] = [
                'method' => $parsedCall['method'] ?? 'GET',
                'url' => $nextUrl,
                'status' => $pageResult['status'],
                'response' => $pageResult['body'],
            ];

            // Get next page URL
            $nextUrl = $pageJson['next_page_url']
                ?? $pageJson['links']['next']
                ?? $pageJson['pagination']['next']
                ?? null;

            if (!$nextUrl) break;
        }

        return $collectedData;
    }

    private function fetchWebPage(string $url): string
    {
        if (!$url) return 'No URL provided';

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['User-Agent' => 'ZeniClaw/1.0'])
                ->get($url);

            if (!$response->successful()) {
                return "HTTP {$response->status()} error fetching {$url}";
            }

            $body = $response->body();

            // Strip HTML tags, keep text content
            $text = strip_tags($body);
            // Collapse whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            // Limit size
            return mb_substr(trim($text), 0, 8000);
        } catch (\Exception $e) {
            return "Fetch error: " . $e->getMessage();
        }
    }

    /**
     * Smart API response formatter.
     *
     * Strategy: extract data programmatically from JSON, then let
     * the LLM only decide layout/grouping — never touch the actual values.
     * If JSON parsing fails, fall back to LLM formatting with strict rules.
     */
    private function finalizeApiResponse(Project $project, string $query, array $collectedData): string
    {
        // Step 1: Try to extract structured records from all API responses
        $allRecords = [];
        $rawTexts = [];
        $hasStructuredData = false;

        foreach ($collectedData as $cd) {
            $rawBody = $cd['response'] ?? $cd['body'] ?? '';
            $rawTexts[] = $rawBody;

            $json = json_decode($rawBody, true);
            if (!$json) continue;

            // Handle common API response shapes:
            // { "data": [...] }, { "items": [...] }, { "results": [...] }, or just [...]
            $records = null;
            if (isset($json['data']) && is_array($json['data'])) {
                $records = $json['data'];
            } elseif (isset($json['items']) && is_array($json['items'])) {
                $records = $json['items'];
            } elseif (isset($json['results']) && is_array($json['results'])) {
                $records = $json['results'];
            } elseif (is_array($json) && isset($json[0]) && is_array($json[0])) {
                $records = $json;
            }

            if ($records) {
                $allRecords = array_merge($allRecords, $records);
                $hasStructuredData = true;
            }
        }

        // Step 2: If we got structured records, format them programmatically
        // then only ask LLM for a short intro/summary line — not the data itself
        if ($hasStructuredData && !empty($allRecords)) {
            $formatted = $this->formatRecordsProgrammatically($allRecords);
            $count = count($allRecords);

            // Ask LLM only for a 1-line intro based on the query
            $intro = $this->claude->chat(
                "Requete: \"{$query}\"\nNombre de resultats: {$count}",
                ModelResolver::fast(),
                "Genere UNE SEULE ligne d'introduction pour une liste de {$count} resultats. "
                . "Commence par [{$project->name}]. Format WhatsApp. Pas de donnees, juste l'intro. "
                . "Exemple: '[MonProjet] 📋 Voici vos 13 clients :'",
                128
            );

            $intro = $intro ?: "[{$project->name}] {$count} resultats :";
            return $intro . "\n\n" . $formatted;
        }

        // Step 3: Fallback — non-structured data (HTML, text, single object)
        // Let LLM format but with raw data embedded as immutable reference
        $dataText = '';
        foreach ($collectedData as $j => $cd) {
            $dataText .= "=== APPEL " . ($j + 1) . ": {$cd['method']} {$cd['url']} (HTTP {$cd['status']}) ===\n";
            $dataText .= mb_substr($cd['response'] ?? $cd['body'] ?? '', 0, 5000) . "\n\n";
        }

        $response = $this->claude->chat(
            "DONNEES BRUTES:\n{$dataText}",
            ModelResolver::balanced(),
            "Tu es un formateur. Demande: \"{$query}\" pour [{$project->name}].\n"
            . "REGLE: Restitue EXACTEMENT les donnees du JSON — aucune modification de noms, emails, montants, TVA. "
            . "Si un champ est null/absent: 'Non renseigne'. N'invente rien. Format WhatsApp.",
            4096
        );

        return $response ?: "[{$project->name}] Donnees collectees mais analyse impossible.";
    }

    /**
     * Format API records into WhatsApp-friendly text without any LLM involvement.
     * Handles any shape of records by auto-detecting fields.
     */
    private function formatRecordsProgrammatically(array $records): string
    {
        if (empty($records)) return '(aucun resultat)';

        // Auto-detect which fields are present across all records
        $fieldFrequency = [];
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            foreach (array_keys($record) as $key) {
                $fieldFrequency[$key] = ($fieldFrequency[$key] ?? 0) + 1;
            }
        }

        // Prioritize human-readable fields for display
        $displayPriority = [
            'name', 'company_name', 'nom', 'label', 'title', 'titre',
            'reference', 'ref', 'number', 'numero', 'code', 'id',
            'email', 'mail', 'phone', 'telephone', 'tel',
            'vat_number', 'tva', 'vat', 'tax_number',
            'address', 'adresse', 'city', 'ville', 'country', 'pays',
            'amount', 'total', 'montant', 'price', 'prix',
            'status', 'statut', 'state', 'etat',
            'date', 'created_at', 'due_date', 'date_echeance',
            'description', 'notes', 'comment',
        ];

        // Select fields to show: prioritized fields that exist, then others
        $fieldsToShow = [];
        foreach ($displayPriority as $pf) {
            foreach ($fieldFrequency as $key => $count) {
                if (mb_strtolower($key) === $pf || str_contains(mb_strtolower($key), $pf)) {
                    $fieldsToShow[$key] = true;
                }
            }
        }
        // Add remaining non-nested fields (skip large objects/arrays)
        foreach ($fieldFrequency as $key => $count) {
            if (isset($fieldsToShow[$key])) continue;
            // Only include scalar fields
            $sample = $records[0][$key] ?? null;
            if (!is_array($sample) && !is_object($sample)) {
                $fieldsToShow[$key] = true;
            }
        }
        $fields = array_keys($fieldsToShow);

        // Find the "name" field (first field that looks like a name/title)
        $nameField = null;
        foreach (['name', 'company_name', 'nom', 'label', 'title', 'titre', 'reference'] as $candidate) {
            foreach ($fields as $f) {
                if (mb_strtolower($f) === $candidate || str_contains(mb_strtolower($f), $candidate)) {
                    $nameField = $f;
                    break 2;
                }
            }
        }

        // Format each record
        $lines = [];
        foreach ($records as $i => $record) {
            if (!is_array($record)) continue;

            $num = $i + 1;
            $header = $nameField && !empty($record[$nameField])
                ? "*{$num}. {$record[$nameField]}*"
                : "*{$num}.*";

            $details = [];
            foreach ($fields as $field) {
                if ($field === $nameField) continue; // already in header
                $value = $record[$field] ?? null;
                if ($value === null || $value === '') {
                    continue; // skip empty — cleaner output
                }
                if (is_array($value) || is_object($value)) continue;

                $label = $this->humanizeFieldName($field);
                $details[] = "  • {$label}: {$value}";
            }

            $lines[] = $header . ($details ? "\n" . implode("\n", $details) : '');
        }

        return implode("\n\n", $lines);
    }

    /**
     * Convert field_name to readable label: "vat_number" → "N° TVA"
     */
    private function humanizeFieldName(string $field): string
    {
        static $labels = [
            'id' => 'ID', 'name' => 'Nom', 'company_name' => 'Entreprise',
            'email' => 'Email', 'phone' => 'Tel', 'telephone' => 'Tel',
            'vat_number' => 'N° TVA', 'tva' => 'TVA', 'tax_number' => 'N° fiscal',
            'address' => 'Adresse', 'city' => 'Ville', 'country' => 'Pays',
            'zip' => 'Code postal', 'postal_code' => 'Code postal',
            'amount' => 'Montant', 'total' => 'Total', 'price' => 'Prix',
            'status' => 'Statut', 'state' => 'Etat',
            'reference' => 'Ref', 'number' => 'N°', 'code' => 'Code',
            'date' => 'Date', 'created_at' => 'Cree le', 'updated_at' => 'Modifie le',
            'due_date' => 'Echeance', 'description' => 'Description',
            'notes' => 'Notes', 'label' => 'Label', 'title' => 'Titre',
            'customer_number' => 'N° client', 'invoice_number' => 'N° facture',
        ];

        $lower = mb_strtolower($field);
        if (isset($labels[$lower])) return $labels[$lower];

        // "some_field_name" → "Some field name"
        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }

    private function handleApiAsk(Project $project, array $parsed, string $query, array $conversation, AgentContext $context): string
    {
        $saveAs = $parsed['save_as'] ?? 'unknown';
        $message = $parsed['message'] ?? 'J\'ai besoin d\'une info supplementaire.';

        // Store pending context so the response comes back here
        $conversation[] = ['role' => 'assistant', 'content' => $message];
        $this->setPendingContext($context, 'api_followup', [
            'project_id' => $project->id,
            'original_query' => $query,
            'conversation' => $conversation,
            'save_as' => $saveAs,
        ], 10, true);

        return "[{$project->name}] {$message}";
    }

    private function discoverProjectApi(Project $project): string
    {
        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $gitlab = $this->getGitlab();

        // Try to read common API route/config files
        $routeFiles = [
            'routes/api.php', 'routes/web.php',
            'src/routes.js', 'src/routes.ts', 'src/router/index.js', 'src/router/index.ts',
            'app/urls.py', 'config/routes.rb',
            '.env.example', 'docker-compose.yml',
        ];
        $apiInfo = [];

        foreach ($routeFiles as $file) {
            $content = $gitlab->readFile($projectId, $file);
            if ($content) {
                $decoded = base64_decode($content['content'] ?? '');
                $apiInfo[] = "=== {$file} ===\n" . mb_substr($decoded, 0, 3000);
            }
        }

        if (empty($apiInfo)) {
            $tree = $gitlab->listTree($projectId);
            if ($tree) {
                $names = array_map(fn($t) => $t['name'], array_slice($tree, 0, 30));
                return "Structure racine: " . implode(', ', $names);
            }
            return "(Aucune info trouvee)";
        }

        return implode("\n\n", $apiInfo);
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

        $result = json_decode($clean, true);
        if ($result !== null) {
            return $result;
        }

        // JSON may be truncated (max_tokens hit with long HTML content)
        // Try to salvage by closing open strings/objects
        $salvage = $clean;
        // Count unmatched quotes — if odd, close the string
        if (substr_count($salvage, '"') % 2 !== 0) {
            $salvage .= '"';
        }
        // Close open braces/brackets
        $opens = substr_count($salvage, '{') - substr_count($salvage, '}');
        $salvage .= str_repeat('}', max(0, $opens));
        $opens = substr_count($salvage, '[') - substr_count($salvage, ']');
        $salvage .= str_repeat(']', max(0, $opens));

        return json_decode($salvage, true);
    }

    // ── Intent Handlers ──────────────────────────────────────────────

    private function requireGitlab(AgentContext $context): ?AgentResult
    {
        if (!$this->getGitlab()->isConfigured()) {
            $reply = "Le token GitLab n'est pas configure. Ajoute-le dans les settings.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
        return null;
    }

    private function gitlabIntent(string $reply, AgentContext $context): AgentResult
    {
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    protected function handleIntentListProjects(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdListGitlabProjects($context), $context);
    }

    protected function handleIntentProjectInfo(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdProjectInfo($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentListBranches(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdListBranches($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentListMrs(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdListMRs($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentPipelineStatus(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdPipelineStatus($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentRecentCommits(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdRecentCommits($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentListIssues(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdListIssues($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentCreateIssue(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdCreateIssue($args['title'] ?? '', $context), $context);
    }

    protected function handleIntentSearchCode(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdSearchCode($args['query'] ?? '', $context), $context);
    }

    protected function handleIntentFileTree(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdFileTree($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentReadFile(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdReadFile($args['path'] ?? '', $context), $context);
    }

    protected function handleIntentCompareBranches(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdCompareBranches($args['from'] ?? '', $args['to'] ?? '', $context), $context);
    }

    protected function handleIntentProjectHealth(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdProjectHealth($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentTaskHistory(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdTaskHistory($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentRollback(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdRollback($context), $context);
    }

    protected function handleIntentDeployStatus(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdDeployStatus($args['name'] ?? '', $context), $context);
    }

    protected function handleIntentApiQuery(array $args, AgentContext $context): AgentResult
    {
        $project = $this->findProjectForUser($context);
        if (!$project) {
            $reply = "Aucun projet actif. Selectionne d'abord un projet.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
        $conversation = $this->getRecentConversation($context);
        $reply = $this->runApiAgent($project, $context->body, $conversation, $context);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'api_query']);
    }

    protected function handleIntentApiCredentials(array $args, AgentContext $context): AgentResult
    {
        $project = $this->findProjectForUser($context);
        if (!$project) {
            $reply = "Aucun projet actif. Selectionne d'abord un projet.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
        $conversation = $this->getRecentConversation($context);
        $reply = $this->runApiAgent($project, $context->body, $conversation, $context);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'api_credentials']);
    }

    protected function handleIntentDevTask(array $args, AgentContext $context): AgentResult
    {
        // Guard: if project has API credentials and message mentions API entities,
        // redirect to api_query instead of creating a code-change sub-agent
        $project = $this->findProjectForUser($context);
        if ($project) {
            $settings = $project->settings ?? [];
            $hasApiCreds = !empty($settings['api_key']) || !empty($settings['base_url']) || !empty($settings['api_endpoint']);
            if ($hasApiCreds) {
                $apiEntities = '/\b(campagne|campaign|prospect|client|projet|project|facture|invoice|commande|order|produit|product|booking|email|template|contact|utilisateur|user)\b/iu';
                if (preg_match($apiEntities, $context->body ?? '')) {
                    $this->log($context, 'dev_task redirected to api_query: project has API creds + message mentions API entities');
                    return $this->handleIntentApiQuery($args, $context);
                }
            }
        }

        return $this->handleDevRequest($context);
    }

    // ── Smart Command Implementations ───────────────────────────────

    private function cmdListGitlabProjects(AgentContext $context): string
    {
        $projects = $this->getGitlab()->listProjects();
        if (!$projects || empty($projects)) {
            return "Aucun projet trouve sur GitLab.";
        }

        $displayed = array_slice($projects, 0, 20);
        $lines = ["*Tes projets GitLab :*\n"];
        foreach ($displayed as $i => $p) {
            $name = $p['path_with_namespace'] ?? $p['name'];
            $updated = isset($p['last_activity_at']) ? substr($p['last_activity_at'], 0, 10) : '?';
            $lines[] = ($i + 1) . ". *{$name}* (modifie: {$updated})";
        }

        $lines[] = "\nEnvoie le *numero* ou le *nom* du projet pour y acceder !";

        // Store list as pending context for follow-up selection
        $items = array_map(fn($p) => [
            'name' => $p['name'] ?? '',
            'path_with_namespace' => $p['path_with_namespace'] ?? '',
            'web_url' => $p['web_url'] ?? '',
            'http_url_to_repo' => $p['http_url_to_repo'] ?? '',
        ], $displayed);

        $this->setPendingContext($context, 'list_selection', [
            'items' => $items,
            'action' => 'project_info',
        ]);

        return implode("\n", $lines);
    }

    private function cmdProjectInfo(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project; // error message

        // Set as active project on session
        $context->session->update(['active_project_id' => $project->id]);

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $gitlab = $this->getGitlab();

        $info = $gitlab->getProject($projectId);
        if (!$info) return "Impossible de recuperer les infos du projet.";

        $lines = ["*{$info['name_with_namespace']}*\n"];
        $lines[] = "URL: {$info['web_url']}";
        $lines[] = "Branche par defaut: {$info['default_branch']}";
        $lines[] = "Visibilite: {$info['visibility']}";
        $lines[] = "Stars: {$info['star_count']} | Forks: {$info['forks_count']}";
        if (!empty($info['description'])) $lines[] = "Description: {$info['description']}";
        $lines[] = "Derniere activite: " . substr($info['last_activity_at'] ?? '', 0, 16);

        $openMrs = $gitlab->listMergeRequests($projectId, 'opened', 1);
        $openIssues = $gitlab->listIssues($projectId, 'opened', 1);
        $lines[] = "\nMRs ouvertes: " . (is_array($openMrs) ? count($openMrs) : '?');
        $lines[] = "Issues ouvertes: " . (is_array($openIssues) ? count($openIssues) : '?');

        // SubAgent stats
        $subAgents = $project->subAgents;
        if ($subAgents->count() > 0) {
            $completed = $subAgents->where('status', 'completed')->count();
            $failed = $subAgents->where('status', 'failed')->count();
            $lines[] = "\nTaches ZeniClaw: {$completed} terminees, {$failed} echouees sur {$subAgents->count()} total";
        }

        return implode("\n", $lines);
    }

    private function cmdListBranches(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $branches = $this->getGitlab()->listBranches($projectId);

        if (!$branches || empty($branches)) {
            return "[{$project->name}] Aucune branche trouvee.";
        }

        $lines = ["*[{$project->name}] Branches :*\n"];
        foreach (array_slice($branches, 0, 15) as $b) {
            $default = ($b['default'] ?? false) ? ' (default)' : '';
            $protected = ($b['protected'] ?? false) ? ' (protected)' : '';
            $lines[] = "- {$b['name']}{$default}{$protected}";
        }

        return implode("\n", $lines);
    }

    private function cmdListMRs(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $mrs = $this->getGitlab()->listMergeRequests($projectId);

        if (!$mrs || empty($mrs)) {
            return "[{$project->name}] Aucune MR ouverte.";
        }

        $lines = ["*[{$project->name}] Merge Requests ouvertes :*\n"];
        foreach ($mrs as $mr) {
            $lines[] = "- !{$mr['iid']} *{$mr['title']}*";
            $lines[] = "  par {$mr['author']['name']} | {$mr['source_branch']} -> {$mr['target_branch']}";
        }

        return implode("\n", $lines);
    }

    private function cmdPipelineStatus(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $pipelines = $this->getGitlab()->listPipelines($projectId);

        if (!$pipelines || empty($pipelines)) {
            return "[{$project->name}] Aucun pipeline trouve.";
        }

        $statusEmoji = [
            'success' => 'ok', 'failed' => 'FAIL', 'running' => 'en cours',
            'pending' => 'en attente', 'canceled' => 'annule', 'skipped' => 'skip',
        ];

        $lines = ["*[{$project->name}] Derniers pipelines :*\n"];
        foreach ($pipelines as $p) {
            $status = $statusEmoji[$p['status']] ?? $p['status'];
            $lines[] = "- #{$p['id']} [{$status}] {$p['ref']} (" . substr($p['updated_at'] ?? '', 0, 16) . ")";
        }

        return implode("\n", $lines);
    }

    private function cmdRecentCommits(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $commits = $this->getGitlab()->listCommits($projectId);

        if (!$commits || empty($commits)) {
            return "[{$project->name}] Aucun commit trouve.";
        }

        $lines = ["*[{$project->name}] Derniers commits :*\n"];
        foreach (array_slice($commits, 0, 10) as $c) {
            $sha = substr($c['id'], 0, 8);
            $date = substr($c['committed_date'] ?? '', 0, 10);
            $title = mb_substr($c['title'], 0, 60);
            $lines[] = "- `{$sha}` {$title} ({$date})";
        }

        return implode("\n", $lines);
    }

    private function cmdListIssues(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $issues = $this->getGitlab()->listIssues($projectId);

        if (!$issues || empty($issues)) {
            return "[{$project->name}] Aucune issue ouverte.";
        }

        $lines = ["*[{$project->name}] Issues ouvertes :*\n"];
        foreach ($issues as $issue) {
            $labels = !empty($issue['labels']) ? ' [' . implode(', ', $issue['labels']) . ']' : '';
            $lines[] = "- #{$issue['iid']} *{$issue['title']}*{$labels}";
        }

        return implode("\n", $lines);
    }

    private function cmdCreateIssue(string $title, AgentContext $context): string
    {
        $project = $this->resolveProject('', $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $issue = $this->getGitlab()->createIssue($projectId, $title);

        if (!$issue) {
            return "[{$project->name}] Impossible de creer l'issue.";
        }

        return "[{$project->name}] Issue #{$issue['iid']} creee : *{$issue['title']}*\n{$issue['web_url']}";
    }

    private function cmdSearchCode(string $query, AgentContext $context): string
    {
        $project = $this->resolveProject('', $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $results = $this->getGitlab()->searchCode($projectId, $query);

        if (!$results || empty($results)) {
            return "[{$project->name}] Aucun resultat pour \"{$query}\".";
        }

        $lines = ["*[{$project->name}] Resultats pour \"{$query}\" :*\n"];
        foreach (array_slice($results, 0, 8) as $r) {
            $file = $r['filename'] ?? $r['path'] ?? '?';
            $lines[] = "- {$file}";
            if (!empty($r['data'])) {
                $snippet = mb_substr(trim($r['data']), 0, 100);
                $lines[] = "  `{$snippet}`";
            }
        }

        return implode("\n", $lines);
    }

    private function cmdFileTree(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $tree = $this->getGitlab()->listTree($projectId);

        if (!$tree || empty($tree)) {
            return "[{$project->name}] Arborescence vide.";
        }

        $lines = ["*[{$project->name}] Arborescence :*\n"];
        foreach ($tree as $item) {
            $icon = $item['type'] === 'tree' ? '📁' : '📄';
            $lines[] = "{$icon} {$item['name']}";
        }

        return implode("\n", $lines);
    }

    private function cmdReadFile(string $path, AgentContext $context): string
    {
        $project = $this->resolveProject('', $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $file = $this->getGitlab()->readFile($projectId, $path);

        if (!$file) {
            return "[{$project->name}] Fichier \"{$path}\" introuvable.";
        }

        $content = base64_decode($file['content'] ?? '');
        if (strlen($content) > 3000) {
            $content = mb_substr($content, 0, 3000) . "\n\n... (tronque)";
        }

        return "[{$project->name}] *{$path}* :\n\n```\n{$content}\n```";
    }

    private function cmdCompareBranches(string $from, string $to, AgentContext $context): string
    {
        $project = $this->resolveProject('', $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $diff = $this->getGitlab()->compareBranches($projectId, $from, $to);

        if (!$diff) {
            return "[{$project->name}] Impossible de comparer {$from} et {$to}.";
        }

        $commits = $diff['commits'] ?? [];
        $diffs = $diff['diffs'] ?? [];

        $lines = ["*[{$project->name}] {$from} -> {$to} :*\n"];
        $lines[] = count($commits) . " commits, " . count($diffs) . " fichiers modifies\n";

        foreach (array_slice($commits, 0, 5) as $c) {
            $lines[] = "- `" . substr($c['id'], 0, 8) . "` {$c['title']}";
        }

        if (count($diffs) > 0) {
            $lines[] = "\nFichiers :";
            foreach (array_slice($diffs, 0, 10) as $d) {
                $lines[] = "- {$d['new_path']}";
            }
        }

        return implode("\n", $lines);
    }

    private function cmdProjectHealth(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $gitlab = $this->getGitlab();

        $pipelines = $gitlab->listPipelines($projectId, 5) ?? [];
        $mrs = $gitlab->listMergeRequests($projectId, 'opened') ?? [];
        $issues = $gitlab->listIssues($projectId, 'opened') ?? [];

        $score = 100;
        $notes = [];

        // Pipeline health
        $failedPipelines = collect($pipelines)->where('status', 'failed')->count();
        if ($failedPipelines > 0) {
            $score -= $failedPipelines * 15;
            $notes[] = "{$failedPipelines} pipeline(s) en echec";
        }
        if (empty($pipelines)) {
            $notes[] = "Aucun pipeline (pas de CI?)";
        } elseif (($pipelines[0]['status'] ?? '') === 'success') {
            $notes[] = "Dernier pipeline OK";
        }

        // MR health
        $staleMrs = collect($mrs)->filter(function ($mr) {
            $updated = strtotime($mr['updated_at'] ?? '');
            return $updated && $updated < strtotime('-7 days');
        })->count();
        if ($staleMrs > 0) {
            $score -= $staleMrs * 5;
            $notes[] = "{$staleMrs} MR(s) stagnante(s) (>7j)";
        }
        if (count($mrs) > 5) {
            $score -= 10;
            $notes[] = count($mrs) . " MRs ouvertes (beaucoup!)";
        }

        // Issue health
        if (count($issues) > 20) {
            $score -= 10;
            $notes[] = count($issues) . " issues ouvertes";
        }

        // SubAgent stats
        $recentFailed = $project->subAgents()->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))->count();
        if ($recentFailed > 0) {
            $score -= $recentFailed * 5;
            $notes[] = "{$recentFailed} tache(s) ZeniClaw echouee(s) cette semaine";
        }

        $score = max(0, min(100, $score));
        $emoji = $score >= 80 ? '🟢' : ($score >= 50 ? '🟡' : '🔴');

        $lines = ["*[{$project->name}] Bilan de sante :* {$emoji} {$score}/100\n"];
        foreach ($notes as $note) {
            $lines[] = "- {$note}";
        }

        return implode("\n", $lines);
    }

    private function cmdTaskHistory(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $subAgents = $project->subAgents()->orderByDesc('created_at')->take(10)->get();

        if ($subAgents->isEmpty()) {
            return "[{$project->name}] Aucune tache precedente.";
        }

        $lines = ["*[{$project->name}] Historique des taches :*\n"];
        foreach ($subAgents as $sa) {
            $status = match ($sa->status) {
                'completed' => 'OK', 'failed' => 'FAIL', 'running' => 'en cours',
                'queued' => 'en file', 'killed' => 'stoppe', default => $sa->status,
            };
            $desc = mb_substr($sa->task_description, 0, 50);
            $date = $sa->created_at->format('d/m H:i');
            $lines[] = "- [{$status}] {$desc} ({$date})";
        }

        return implode("\n", $lines);
    }

    private function cmdRollback(AgentContext $context): string
    {
        $project = $this->findProjectForUser($context);
        if (!$project) return "Aucun projet actif. Dis-moi sur quel projet tu veux rollback.";

        $lastCompleted = $project->subAgents()
            ->where('status', 'completed')
            ->whereNotNull('branch_name')
            ->orderByDesc('completed_at')
            ->first();

        if (!$lastCompleted) {
            return "[{$project->name}] Aucune tache completee a rollback.";
        }

        $desc = mb_substr($lastCompleted->task_description, 0, 80);
        $reply = "[{$project->name}] Derniere tache completee :\n"
            . "- {$desc}\n"
            . "- Branche: {$lastCompleted->branch_name}\n"
            . "- Commit: " . substr($lastCompleted->commit_hash ?? '?', 0, 8) . "\n\n"
            . "Pour rollback, envoie-moi : \"revert le commit " . substr($lastCompleted->commit_hash ?? '', 0, 8) . " sur {$project->name}\"";

        return $reply;
    }

    private function cmdDeployStatus(string $name, AgentContext $context): string
    {
        $project = $this->resolveProject($name, $context);
        if (is_string($project)) return $project;

        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $envs = $this->getGitlab()->listEnvironments($projectId);

        if (!$envs || empty($envs)) {
            return "[{$project->name}] Aucun environnement de deploiement configure.";
        }

        $lines = ["*[{$project->name}] Environnements :*\n"];
        foreach ($envs as $env) {
            $state = $env['state'] ?? '?';
            $url = $env['external_url'] ?? 'pas d\'URL';
            $lines[] = "- *{$env['name']}* [{$state}] {$url}";
        }

        return implode("\n", $lines);
    }

    // ── Smart Project Resolution (Fuzzy Matching) ───────────────────

    /**
     * Resolve a project by name, with fuzzy matching and GitLab lookup.
     * Returns a Project model or an error string.
     */
    private function resolveProject(string $name, AgentContext $context): Project|string
    {
        // No name specified → use active/last project
        if (empty(trim($name))) {
            $project = $this->findProjectForUser($context);
            if ($project) return $project;
            return "Aucun projet actif. Precise le nom du projet.";
        }

        $name = trim($name);

        // Helper: persist active project on session so next request defaults to it
        $persistActive = function (Project $p) use ($context): Project {
            $context->session->update(['active_project_id' => $p->id]);
            return $p;
        };

        // 1. Exact match in local DB
        $exact = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->where('name', 'ilike', $name)
            ->first();
        if ($exact) return $persistActive($exact);

        // 2. Partial match in local DB
        $partial = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->where('name', 'ilike', "%{$name}%")
            ->first();
        if ($partial) return $persistActive($partial);

        // 3. Fuzzy match in local DB (Levenshtein)
        $allProjects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])->get();
        $bestMatch = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($allProjects as $p) {
            $distance = levenshtein(mb_strtolower($name), mb_strtolower($p->name));
            if ($distance < $bestDistance && $distance <= 3) {
                $bestDistance = $distance;
                $bestMatch = $p;
            }
        }

        if ($bestMatch) return $persistActive($bestMatch);

        // 4. Search in GitLab API
        $gitlab = $this->getGitlab();
        $gitlabResults = $gitlab->listProjects($name, 5);

        if ($gitlabResults && !empty($gitlabResults)) {
            $gitlabNames = array_map(fn($p) => $p['path_with_namespace'], array_slice($gitlabResults, 0, 5));

            // Exact match in GitLab results → auto-create local project
            foreach ($gitlabResults as $gp) {
                if (mb_strtolower($gp['name']) === mb_strtolower($name) ||
                    mb_strtolower($gp['path']) === mb_strtolower($name)) {
                    return $persistActive($this->createProjectFromGitlab($gp, $context));
                }
            }

            // Single result → use it
            if (count($gitlabResults) === 1) {
                return $persistActive($this->createProjectFromGitlab($gitlabResults[0], $context));
            }

            // Multiple results → ask user to clarify (context stored by caller if needed)
            $lines = "J'ai trouve plusieurs projets GitLab pour \"{$name}\" :\n";
            foreach (array_slice($gitlabResults, 0, 5) as $i => $gp) {
                $lines .= ($i + 1) . ". {$gp['path_with_namespace']}\n";
            }
            $lines .= "\nLequel tu veux ? Envoie le numero ou le nom exact.";
            return $lines;
        }

        // Nothing found
        $suggestions = $allProjects->map(fn($p) => $p->name)->implode(', ');
        return "Projet \"{$name}\" introuvable.\n"
            . ($suggestions ? "Projets disponibles : {$suggestions}" : "Aucun projet configure.");
    }

    private function createProjectFromGitlab(array $gitlabProject, AgentContext $context): Project
    {
        $gitlabUrl = $gitlabProject['web_url'] ?? $gitlabProject['http_url_to_repo'] ?? '';

        // Check if already exists
        $existing = Project::where('gitlab_url', $gitlabUrl)
            ->whereIn('status', ['approved', 'in_progress', 'completed'])
            ->first();
        if ($existing) return $existing;

        return Project::create([
            'name' => $gitlabProject['name'],
            'gitlab_url' => $gitlabUrl,
            'request_description' => 'Projet importe depuis GitLab',
            'requester_phone' => $context->from,
            'requester_name' => $context->senderName,
            'agent_id' => $context->agent->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    // ── Original DevAgent Logic ─────────────────────────────────────

    private function handleTaskValidation(Project $project, AgentContext $context): AgentResult
    {
        $classification = $this->claude->chat(
            "Message de l'utilisateur: \"{$context->body}\"",
            ModelResolver::fast(),
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
            // Dev request without GitLab URL — smart resolve
            $resolved = $this->smartResolveProject($context);

            if ($resolved instanceof Project) {
                $gitlabUrl = $resolved->gitlab_url;
                $repoName = $resolved->name;
                $isNewRepo = !in_array($resolved->status, ['approved', 'in_progress', 'completed']);
            } elseif (is_string($resolved)) {
                // It's an ambiguity message — send and wait
                $this->sendText($context->from, $resolved);
                return AgentResult::reply($resolved, ['action' => 'asked_for_clarification']);
            } else {
                $reply = "On dirait une demande de modif !\n"
                    . "Envoie-moi l'URL GitLab du repo ou dis \"liste projets\" pour voir tes repos.";
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

    /**
     * Smart project resolution: tries local DB first, then fuzzy, then GitLab API.
     * Returns Project, error string, or null.
     */
    private function smartResolveProject(AgentContext $context): Project|string|null
    {
        $body = $context->body;
        $from = $context->from;

        // 1. Try existing local matching (exact name, active, allowed)
        $localMatch = $this->findProjectForUser($context);
        if ($localMatch) return $localMatch;

        // 2. Extract potential project name from message using Claude
        $extractedName = $this->extractProjectNameFromMessage($body);
        if (!$extractedName) return null;

        // 3. Fuzzy match in local DB
        $allProjects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])->get();

        foreach ($allProjects as $p) {
            $distance = levenshtein(mb_strtolower($extractedName), mb_strtolower($p->name));
            if ($distance <= 3) {
                return $p;
            }
        }

        // 4. Search GitLab
        $gitlab = $this->getGitlab();
        if (!$gitlab->isConfigured()) return null;

        $gitlabResults = $gitlab->listProjects($extractedName, 5);
        if (!$gitlabResults || empty($gitlabResults)) return null;

        // Single result → auto-use
        if (count($gitlabResults) === 1) {
            return $this->createProjectFromGitlab($gitlabResults[0], $context);
        }

        // Multiple → ask user
        $lines = "J'ai trouve plusieurs projets pour \"{$extractedName}\" :\n";
        foreach (array_slice($gitlabResults, 0, 5) as $i => $gp) {
            $lines .= ($i + 1) . ". *{$gp['path_with_namespace']}*\n";
        }
        $lines .= "\nLequel tu veux ?";
        return $lines;
    }

    private function extractProjectNameFromMessage(string $body): ?string
    {
        $response = $this->claude->chat(
            "Message: \"{$body}\"",
            ModelResolver::fast(),
            "Extrait le nom du projet/repo mentionne dans ce message.\n"
            . "Reponds UNIQUEMENT avec le nom du projet, rien d'autre.\n"
            . "Si aucun projet n'est mentionne, reponds NULL."
        );

        $name = trim($response ?? '');
        if (empty($name) || mb_strtoupper($name) === 'NULL') return null;
        return $name;
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

        return AgentResult::dispatched(['project_id' => $project->id, 'sub_agent_id' => $subAgent->id], $reply);
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

        return AgentResult::dispatched(['project_id' => $project->id, 'sub_agent_id' => $subAgent->id], $reply);
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

    private function getRecentConversation(AgentContext $context, int $maxEntries = 8): array
    {
        $history = $this->memory->read($context->agent->id, $context->from);
        $entries = $history['entries'] ?? [];

        if (empty($entries)) {
            return [];
        }

        $recent = array_slice($entries, -$maxEntries);
        $conversation = [];
        foreach ($recent as $entry) {
            $conversation[] = [
                'role' => 'user',
                'content' => $entry['sender_message'] ?? '',
            ];
            $conversation[] = [
                'role' => 'assistant',
                'content' => $entry['agent_reply'] ?? '',
            ];
        }

        return $conversation;
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

    /**
     * Among multiple matching projects, pick the best one.
     * Smart scoring: validates API config actually works (quick probe).
     */
    private function pickBestProject(\Illuminate\Support\Collection $candidates): ?Project
    {
        if ($candidates->isEmpty()) return null;
        if ($candidates->count() === 1) return $candidates->first();

        // Score each candidate
        $scored = $candidates->map(function ($p) {
            $s = $p->settings ?? [];
            $score = 0;

            $hasBaseUrl = !empty($s['base_url']);
            $hasToken = (bool) collect($s)->keys()->first(fn($k) => str_contains($k, 'token') || str_contains($k, 'api_key'));

            // Has API config = high value
            if ($hasBaseUrl && $hasToken) {
                $score += 10;

                // Quick probe: does the base_url return JSON? (300ms max)
                try {
                    $tokenKey = collect($s)->keys()->first(fn($k) => str_contains($k, 'token') || str_contains($k, 'api_key'));
                    $token = $s[$tokenKey] ?? '';
                    $testUrl = rtrim($s['base_url'], '/');

                    $response = \Illuminate\Support\Facades\Http::timeout(2)
                        ->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                        ->get($testUrl);

                    $body = trim($response->body());
                    $isJson = str_starts_with($body, '{') || str_starts_with($body, '[');

                    if ($response->successful() && $isJson) {
                        $score += 20; // Token works, returns JSON = best candidate
                    } elseif ($response->successful() && !$isJson) {
                        $score -= 5; // Returns HTML = token probably dead
                    }
                } catch (\Exception $e) {
                    // Probe failed — don't penalize, maybe network issue
                }
            } elseif ($hasBaseUrl || $hasToken) {
                $score += 3; // Partial config
            } elseif (!empty($s)) {
                $score += 1; // Has some settings
            }

            return ['project' => $p, 'score' => $score];
        });

        return $scored->sortByDesc('score')->first()['project'];
    }

    private function findProjectByNameInMessage(string $body, string $phone): ?Project
    {
        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        if ($projects->isEmpty()) return null;

        // Highest priority: explicit "projet X" / "le projet X" / "ds le projet X" mentions
        if (preg_match('/(?:le\s+projet|ds\s+le\s+projet|dans\s+le\s+projet|sur\s+le\s+projet|bosser\s+sur|passe\s+sur|projet\s+)(["\']?)(\S+)\1/iu', $body, $m)) {
            $explicit = rtrim($m[2], '.,;:!?)');
            $matches = $projects->filter(fn($p) =>
                mb_stripos($p->name, $explicit) !== false
                || mb_stripos($explicit, $p->name) !== false
                || (strlen($explicit) >= 4 && levenshtein(mb_strtolower($explicit), mb_strtolower($p->name)) <= 2)
            );
            $best = $this->pickBestProject($matches);
            if ($best) return $best;
        }

        // Exact name match — collect all matches, pick best
        $nameMatches = $projects->filter(fn($p) => mb_stripos($body, $p->name) !== false);
        $best = $this->pickBestProject($nameMatches);
        if ($best) return $best;

        // Slug match
        $slugMatches = $projects->filter(function ($p) use ($body) {
            $slug = basename(parse_url($p->gitlab_url, PHP_URL_PATH) ?? '');
            $slug = str_replace('.git', '', $slug);
            return $slug && mb_stripos($body, $slug) !== false;
        });
        $best = $this->pickBestProject($slugMatches);
        if ($best) return $best;

        // Fuzzy match (Levenshtein)
        $words = preg_split('/\s+/', mb_strtolower($body));
        $fuzzyMatches = $projects->filter(function ($p) use ($words) {
            $projectNameLower = mb_strtolower($p->name);
            if (strlen($projectNameLower) < 5) return false;
            foreach ($words as $word) {
                if (strlen($word) >= 5 && levenshtein($word, $projectNameLower) <= 2) {
                    return true;
                }
            }
            return false;
        });
        $best = $this->pickBestProject($fuzzyMatches);
        if ($best) return $best;

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
            ModelResolver::fast(),
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
            Log::warning("Failed to notify admin: " . $e->getMessage());
        }
    }

    private function getGitlab(): GitLabService
    {
        if (!$this->gitlab) {
            $this->gitlab = new GitLabService();
        }
        return $this->gitlab;
    }
}
