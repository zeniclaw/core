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
            ['key' => 'api_query', 'description' => 'Interroger/consulter l\'API live du projet (lister donnees, stats, entites). PAS modifier le code.', 'examples' => ['liste les campagnes', 'combien de users', 'donne moi les apis du projet']],
            ['key' => 'api_credentials', 'description' => 'L\'utilisateur donne/partage des credentials: cle API, token, endpoint, URL d\'API, secret. Il veut les stocker, pas coder.', 'examples' => ['voici la cle pk_xxx et le endpoint https://...', 'utilise cette API key: xxx', 'le token c\'est abc123']],
            // Dev tasks (code changes)
            ['key' => 'dev_task', 'description' => 'Tache de developpement: modifier code, fix bug, ajouter feature, refactoring. Necessite modification de code.', 'examples' => ['fix le bug du login', 'ajoute un bouton', 'refactore le controller']],
        ];
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Check pending context first (list selection, ambiguous project, etc.)
        $pendingCtx = $context->session->pending_agent_context;
        if ($pendingCtx && ($pendingCtx['agent'] ?? '') === 'dev') {
            $result = $this->handlePendingContext($context, $pendingCtx);
            if ($result) return $result;
        }

        // Handle task awaiting validation
        $awaitingProject = Project::where('status', 'awaiting_validation')
            ->where('requester_phone', $context->from)
            ->orderByDesc('created_at')
            ->first();

        if ($awaitingProject) {
            return $this->handleTaskValidation($awaitingProject, $context);
        }

        // Intent classification — replaces detectSmartCommand + handleDevRequest cascade
        $activeProjectHint = '';
        if ($context->session->active_project_id) {
            $ap = Project::find($context->session->active_project_id);
            if ($ap) {
                $activeProjectHint = "PROJET ACTIF: {$ap->name}\n";
            }
        }

        $classified = $this->classifyIntent($context, $activeProjectHint);

        $this->log($context, 'Intent classified', [
            'intent' => $classified['intent'],
            'confidence' => $classified['confidence'],
            'args' => $classified['args'],
        ]);

        // Try intent dispatch
        $result = $this->dispatchIntent($classified, $context);
        if ($result) return $result;

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
                    $collectedText .= "--- Appel " . ($j + 1) . ": {$cd['method']} {$cd['url']} ---\n";
                    $collectedText .= mb_substr($cd['response'], 0, 2000) . "\n\n";
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

REGLES CRITIQUES:
1. EXTRAIRE ET STOCKER IMMEDIATEMENT: Si le message contient URL, cle API, token, endpoint, identifiants → stocke-les dans "store" ET confirme avec "reply". Exemple: si l'utilisateur dit "voici la cle abc123 et le endpoint https://api.example.com", tu reponds:
{"action": "reply", "store": {"api_key": "abc123", "api_endpoint": "https://api.example.com"}, "message": "[projet] Configuration enregistree ! Cle API et endpoint sauvegardes. Je peux maintenant interroger l'API."}
NE PROPOSE PAS de code, d'integration, ou de plan technique quand l'utilisateur donne juste des credentials. Stocke et confirme.
2. AGIR: Ne pose JAMAIS de question si tu peux deduire du code source ou des settings.
3. UTILISER LES CREDENTIALS: Si des cles/endpoints sont dans la CONFIGURATION STOCKEE, utilise-les directement pour faire des appels API. Ne propose pas de les integrer dans du code.
4. ENCHAINER: Si tu as besoin de plusieurs endpoints (ex: d'abord les projets, puis les factures par projet), fais-les un par un. Tu reverras les resultats a chaque iteration.
5. ANALYSER: Quand tu as assez de donnees, fais une VRAIE analyse (tendances, totaux, alertes, recommandations). Ne te contente pas de lister.
6. AUTH: Deduis le mecanisme du code (Bearer, API key header, query param...). Utilise les settings stockes.
7. REPONSE FINALE: Formate pour WhatsApp (*gras*, listes). Commence par [{$project->name}].
8. SAUVEGARDER: Dans "knowledge", inclus les donnees importantes a retenir (listes de clients, factures, totaux) pour ne pas refaire les memes appels.
9. VERIFIER: Regarde "DONNEES DEJA CONNUES" ci-dessus AVANT de faire un appel API. Ne refais pas un appel si tu as deja l'info.
10. PAGINER: Si l'API retourne des resultats pagines, fais TOUS les appels necessaires pour avoir TOUTES les pages.

JSON UNIQUEMENT.
PROMPT;

            $response = $this->claude->chat($query, ModelResolver::powerful(), $systemPrompt);
            $parsed = $this->parseJson($response);

            if (!$parsed || empty($parsed['action'])) {
                Log::warning('DevAgent parse failed', [
                    'from' => $context->from ?? null,
                    'project' => $project->name,
                    'raw_response' => mb_substr($response ?? '', 0, 2000),
                    'parsed' => $parsed,
                    'query' => mb_substr($query, 0, 500),
                ]);
                return "[{$project->name}] Erreur d'analyse. Reformule ta demande.";
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
                // Auto-store knowledge from the reply
                $knowledge = $parsed['knowledge'] ?? [];
                if (!empty($knowledge) && is_array($knowledge)) {
                    foreach ($knowledge as $item) {
                        if (!empty($item['key']) && !empty($item['data'])) {
                            UserKnowledge::store(
                                $context->from,
                                $item['key'],
                                $item['data'],
                                $item['label'] ?? null,
                                $project->name,
                                $item['ttl_minutes'] ?? null
                            );
                        }
                    }
                }
                return ($parsed['message'] ?? '');
            }

            if ($parsed['action'] === 'ask') {
                return $this->handleApiAsk($project, $parsed, $query, $conversation, $context);
            }

            if ($parsed['action'] === 'call') {
                $callResult = $this->executeRawApiCall($parsed);
                $collectedData[] = [
                    'method' => $parsed['method'] ?? 'GET',
                    'url' => $parsed['url'] ?? '',
                    'status' => $callResult['status'],
                    'response' => $callResult['body'],
                ];

                // Auto-store base_url on first successful call
                if ($callResult['status'] >= 200 && $callResult['status'] < 300) {
                    $parsedUrl = parse_url($parsed['url'] ?? '');
                    $baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
                    if ($baseUrl && !$project->getSetting('base_url')) {
                        $project->setSetting('base_url', $baseUrl);
                    }
                }

                // If auth error, let Claude handle it in next iteration
                // (it will see the 401 in collected data and decide to ask or fix)
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

    private function finalizeApiResponse(Project $project, string $query, array $collectedData): string
    {
        $dataText = '';
        foreach ($collectedData as $j => $cd) {
            $dataText .= "Appel " . ($j + 1) . ": {$cd['method']} {$cd['url']} (HTTP {$cd['status']})\n";
            $dataText .= mb_substr($cd['response'], 0, 3000) . "\n\n";
        }

        $response = $this->claude->chat(
            "Donnees collectees:\n{$dataText}",
            ModelResolver::balanced(),
            "L'utilisateur a demande: \"{$query}\" pour le projet {$project->name}.\n"
            . "Voici toutes les donnees collectees via API. Fais une analyse complete:\n"
            . "- Resume, totaux, tendances\n- Alertes ou anomalies\n- Recommandations\n"
            . "Formate pour WhatsApp (*gras*, listes). Commence par [{$project->name}]."
        );

        return $response ?: "[{$project->name}] Donnees collectees mais analyse impossible.";
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
        return json_decode($clean, true);
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

    private function handleIntentListProjects(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdListGitlabProjects($context), $context);
    }

    private function handleIntentProjectInfo(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdProjectInfo($args['name'] ?? '', $context), $context);
    }

    private function handleIntentListBranches(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdListBranches($args['name'] ?? '', $context), $context);
    }

    private function handleIntentListMrs(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdListMRs($args['name'] ?? '', $context), $context);
    }

    private function handleIntentPipelineStatus(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdPipelineStatus($args['name'] ?? '', $context), $context);
    }

    private function handleIntentRecentCommits(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdRecentCommits($args['name'] ?? '', $context), $context);
    }

    private function handleIntentListIssues(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdListIssues($args['name'] ?? '', $context), $context);
    }

    private function handleIntentCreateIssue(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdCreateIssue($args['title'] ?? '', $context), $context);
    }

    private function handleIntentSearchCode(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdSearchCode($args['query'] ?? '', $context), $context);
    }

    private function handleIntentFileTree(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdFileTree($args['name'] ?? '', $context), $context);
    }

    private function handleIntentReadFile(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdReadFile($args['path'] ?? '', $context), $context);
    }

    private function handleIntentCompareBranches(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdCompareBranches($args['from'] ?? '', $args['to'] ?? '', $context), $context);
    }

    private function handleIntentProjectHealth(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdProjectHealth($args['name'] ?? '', $context), $context);
    }

    private function handleIntentTaskHistory(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdTaskHistory($args['name'] ?? '', $context), $context);
    }

    private function handleIntentRollback(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdRollback($context), $context);
    }

    private function handleIntentDeployStatus(array $args, AgentContext $context): AgentResult
    {
        if ($err = $this->requireGitlab($context)) return $err;
        return $this->gitlabIntent($this->cmdDeployStatus($args['name'] ?? '', $context), $context);
    }

    private function handleIntentApiQuery(array $args, AgentContext $context): AgentResult
    {
        $project = $this->findProjectForUser($context);
        if (!$project) {
            $reply = "Aucun projet actif. Selectionne d'abord un projet.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
        $reply = $this->runApiAgent($project, $context->body, [], $context);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'api_query']);
    }

    private function handleIntentApiCredentials(array $args, AgentContext $context): AgentResult
    {
        $project = $this->findProjectForUser($context);
        if (!$project) {
            $reply = "Aucun projet actif. Selectionne d'abord un projet.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
        $reply = $this->runApiAgent($project, $context->body, [], $context);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'api_credentials']);
    }

    private function handleIntentDevTask(array $args, AgentContext $context): AgentResult
    {
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

        // Highest priority: explicit "projet X" / "le projet X" / "ds le projet X" mentions
        // Catches corrections like "c'est ds le projet prospections" or "sur le projet X"
        if (preg_match('/(?:le\s+projet|ds\s+le\s+projet|dans\s+le\s+projet|sur\s+le\s+projet|bosser\s+sur|passe\s+sur|projet\s+)(["\']?)(\S+)\1/iu', $body, $m)) {
            $explicit = rtrim($m[2], '.,;:!?)');
            foreach ($projects as $project) {
                if (mb_stripos($project->name, $explicit) !== false || mb_stripos($explicit, $project->name) !== false) {
                    return $project;
                }
                if (strlen($explicit) >= 4 && levenshtein(mb_strtolower($explicit), mb_strtolower($project->name)) <= 2) {
                    return $project;
                }
            }
        }

        // Exact name match
        foreach ($projects as $project) {
            if (mb_stripos($body, $project->name) !== false) {
                return $project;
            }
        }

        // Slug match
        foreach ($projects as $project) {
            $slug = basename(parse_url($project->gitlab_url, PHP_URL_PATH) ?? '');
            $slug = str_replace('.git', '', $slug);
            if ($slug && mb_stripos($body, $slug) !== false) {
                return $project;
            }
        }

        // Fuzzy match (Levenshtein) — only for long enough words/names to avoid false positives
        $words = preg_split('/\s+/', mb_strtolower($body));
        foreach ($projects as $project) {
            $projectNameLower = mb_strtolower($project->name);
            if (strlen($projectNameLower) < 5) continue; // skip short project names
            foreach ($words as $word) {
                if (strlen($word) >= 5 && levenshtein($word, $projectNameLower) <= 2) {
                    return $project;
                }
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
