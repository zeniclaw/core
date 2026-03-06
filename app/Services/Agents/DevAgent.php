<?php

namespace App\Services\Agents;

use App\Jobs\RunSubAgentJob;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SubAgent;
use App\Services\AgentContext;
use App\Services\GitLabService;
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

        // Check for smart commands before treating as dev request
        $command = $this->detectSmartCommand($context->body);
        if ($command) {
            return $this->handleSmartCommand($command, $context);
        }

        // Process new dev request
        return $this->handleDevRequest($context);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';
        $data = $pendingContext['data'] ?? [];

        return match ($type) {
            'list_selection' => $this->handleListSelection($context, $data),
            'ambiguous_project' => $this->handleAmbiguousProjectSelection($context, $data),
            'api_credentials' => $this->handleApiCredentials($context, $data),
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

    // ── API Query (Generic Project API Interaction) ───────────────────

    private function handleApiCredentials(AgentContext $context, array $data): ?AgentResult
    {
        $body = trim($context->body);
        $projectId = $data['project_id'] ?? null;
        $missing = $data['missing'] ?? '';
        $originalQuery = $data['original_query'] ?? '';

        $project = $projectId ? Project::find($projectId) : null;
        if (!$project) {
            $this->clearPendingContext($context);
            return null;
        }

        // Store the provided credential
        $project->setSetting($missing, $body);
        $this->clearPendingContext($context);

        $reply = "Merci ! {$missing} enregistre pour *{$project->name}*.";
        $this->sendText($context->from, $reply);

        // Retry the original query now that we have credentials
        if ($originalQuery) {
            $retryReply = $this->executeApiQuery($project, $originalQuery, $context);
            $this->sendText($context->from, $retryReply);
            return AgentResult::reply($reply . "\n\n" . $retryReply);
        }

        return AgentResult::reply($reply);
    }

    private function cmdApiQuery(string $query, AgentContext $context): string
    {
        $project = $this->findProjectForUser($context);
        if (!$project) {
            return "Aucun projet actif. Selectionne d'abord un projet.";
        }

        $baseUrl = $project->getSetting('base_url');
        $apiKey = $project->getSetting('api_key');

        // If missing base_url, ask for it
        if (!$baseUrl) {
            $this->setPendingContext($context, 'api_credentials', [
                'project_id' => $project->id,
                'missing' => 'base_url',
                'original_query' => $query,
            ], 10);
            return "[{$project->name}] J'ai besoin de l'URL de base de l'API pour ce projet.\n"
                . "Exemple: https://mon-app.com ou https://api.mon-app.com\n\n"
                . "Envoie-moi l'URL :";
        }

        return $this->executeApiQuery($project, $query, $context);
    }

    private function executeApiQuery(Project $project, string $query, AgentContext $context): string
    {
        $baseUrl = rtrim($project->getSetting('base_url'), '/');
        $apiKey = $project->getSetting('api_key');

        // Step 1: Read project routes/API structure from GitLab to understand available endpoints
        $apiStructure = $this->discoverProjectApi($project);

        // Step 2: Ask Claude to determine the right API call
        $settingsInfo = "Base URL: {$baseUrl}";
        if ($apiKey) {
            $settingsInfo .= "\nAPI Key: disponible (sera envoyee en header Authorization: Bearer)";
        }

        $prompt = "Tu es un assistant qui aide a interroger l'API d'un projet.\n\n"
            . "PROJET: {$project->name}\n"
            . "CONFIG:\n{$settingsInfo}\n\n"
            . "STRUCTURE API DU PROJET (extraite du code source):\n{$apiStructure}\n\n"
            . "DEMANDE DE L'UTILISATEUR: {$query}\n\n"
            . "Determine l'appel API a faire. Reponds en JSON:\n"
            . '{"method": "GET|POST|...", "endpoint": "/api/...", "params": {}, "needs_auth": true|false, "explanation": "..."}' . "\n\n"
            . "Si tu ne trouves pas l'endpoint adapte, reponds:\n"
            . '{"error": "explication du probleme"}' . "\n\n"
            . "Si l'API necessite une cle/token et qu'on n'en a pas, reponds:\n"
            . '{"needs_credential": "api_key", "message": "explication"}' . "\n\n"
            . "Reponds UNIQUEMENT en JSON.";

        $response = $this->claude->chat($query, 'claude-sonnet-4-20250514', $prompt);
        $parsed = $this->parseJson($response);

        if (!$parsed) {
            return "[{$project->name}] Je n'ai pas pu determiner l'appel API. Reformule ta demande.";
        }

        // Handle missing credential
        if (!empty($parsed['needs_credential'])) {
            $credName = $parsed['needs_credential'];
            $this->setPendingContext($context, 'api_credentials', [
                'project_id' => $project->id,
                'missing' => $credName,
                'original_query' => $query,
            ], 10);
            return "[{$project->name}] " . ($parsed['message'] ?? "J'ai besoin de: {$credName}") . "\n\nEnvoie-moi la valeur :";
        }

        // Handle error
        if (!empty($parsed['error'])) {
            return "[{$project->name}] {$parsed['error']}";
        }

        // Step 3: Execute the API call
        $method = strtoupper($parsed['method'] ?? 'GET');
        $endpoint = $parsed['endpoint'] ?? '/';
        $params = $parsed['params'] ?? [];
        $needsAuth = $parsed['needs_auth'] ?? false;
        $url = $baseUrl . $endpoint;

        try {
            $http = \Illuminate\Support\Facades\Http::timeout(15);

            if ($needsAuth && $apiKey) {
                $http = $http->withHeaders(['Authorization' => "Bearer {$apiKey}"]);
            }

            $httpResponse = match ($method) {
                'POST' => $http->post($url, $params),
                'PUT' => $http->put($url, $params),
                'DELETE' => $http->delete($url, $params),
                default => $http->get($url, $params),
            };

            if (!$httpResponse->successful()) {
                // If 401/403 and no API key, ask for one
                if (in_array($httpResponse->status(), [401, 403]) && !$apiKey) {
                    $this->setPendingContext($context, 'api_credentials', [
                        'project_id' => $project->id,
                        'missing' => 'api_key',
                        'original_query' => $query,
                    ], 10);
                    return "[{$project->name}] L'API repond {$httpResponse->status()} (non autorise).\n"
                        . "Envoie-moi la cle API / token d'acces :";
                }

                return "[{$project->name}] Erreur API: HTTP {$httpResponse->status()}\n"
                    . mb_substr($httpResponse->body(), 0, 300);
            }

            $data = $httpResponse->json();

            // Step 4: Ask Claude to format the response nicely
            $formatPrompt = "Tu es un assistant. L'utilisateur a demande: \"{$query}\"\n"
                . "Voici la reponse de l'API (JSON):\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Formate cette reponse de maniere claire et lisible pour WhatsApp (pas de markdown complexe, utilise des listes numerotees et *gras* pour les titres).\n"
                . "Si la reponse est longue, resume les elements principaux (max 20 items).\n"
                . "Commence par le nom du projet entre crochets.";

            $formatted = $this->claude->chat(
                json_encode($data, JSON_UNESCAPED_UNICODE),
                'claude-haiku-4-5-20251001',
                $formatPrompt
            );

            return $formatted ?: "[{$project->name}] Reponse:\n" . mb_substr(json_encode($data, JSON_PRETTY_PRINT), 0, 2000);

        } catch (\Exception $e) {
            Log::warning("API query failed for {$project->name}: " . $e->getMessage());
            return "[{$project->name}] Erreur lors de l'appel API: " . $e->getMessage();
        }
    }

    private function discoverProjectApi(Project $project): string
    {
        $projectId = GitLabService::encodeProjectPath($project->gitlab_url);
        $gitlab = $this->getGitlab();

        // Try to read common API route files
        $routeFiles = ['routes/api.php', 'routes/web.php', 'src/routes.js', 'src/router/index.js', 'app/urls.py'];
        $apiInfo = [];

        foreach ($routeFiles as $file) {
            $content = $gitlab->readFile($projectId, $file);
            if ($content) {
                $decoded = base64_decode($content['content'] ?? '');
                $apiInfo[] = "=== {$file} ===\n" . mb_substr($decoded, 0, 3000);
            }
        }

        if (empty($apiInfo)) {
            // Fallback: check root tree for clues
            $tree = $gitlab->listTree($projectId);
            if ($tree) {
                $names = array_map(fn($t) => $t['name'], array_slice($tree, 0, 30));
                return "Structure du projet (racine): " . implode(', ', $names) . "\n(Pas de fichier de routes standard trouve)";
            }
            return "(Structure API non determinee)";
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

    // ── Smart Commands Detection (LLM-based) ─────────────────────────

    private function detectSmartCommand(string $body): ?array
    {
        $response = $this->claude->chat(
            "Message: \"{$body}\"",
            'claude-haiku-4-5-20251001',
            <<<'PROMPT'
Tu analyses un message envoye a un agent de developpement. Determine si c'est une SMART COMMAND (consultation/info GitLab) ou une DEV TASK (tache de code a executer).

SMART COMMANDS DISPONIBLES:
- list_gitlab_projects = lister les projets, repos, "mes projets", "quels projets tu as"
- project_info = info/status/details d'un projet specifique
- list_branches = voir les branches d'un projet
- list_mrs = voir les merge requests ouvertes
- pipeline_status = status CI/CD, pipelines
- recent_commits = derniers commits, historique, log
- list_issues = issues/tickets/bugs ouverts
- create_issue = creer un ticket/issue/bug (args: title)
- search_code = chercher dans le code (args: query)
- file_tree = arborescence, structure, fichiers d'un projet
- read_file = lire/afficher un fichier du repo (args: path)
- compare_branches = comparer/diff entre branches (args: from, to)
- project_health = bilan de sante, diagnostic projet
- task_history = historique des taches executees
- rollback = annuler/revert la derniere modification
- deploy_status = status de deploiement
- api_query = l'utilisateur veut interroger/interagir avec l'API live du projet (lister des donnees, voir des entites, stats, etc.) — PAS modifier le code, mais CONSULTER les donnees de l'app en production (args: query = ce qu'il veut savoir)

Si c'est une smart command, reponds en JSON:
{"command": "nom_commande", "args": {"name": "nom_projet_si_mentionne", ...}}

Si c'est une tache de dev (modifier code, fix bug, ajouter feature) ou si tu n'es pas sur:
{"command": null}

EXEMPLES:
- "liste moi les projets dev que tu as" → {"command": "list_gitlab_projects", "args": {}}
- "mes repos" → {"command": "list_gitlab_projects", "args": {}}
- "quels projets tu geres" → {"command": "list_gitlab_projects", "args": {}}
- "status du projet zeniclaw" → {"command": "project_info", "args": {"name": "zeniclaw"}}
- "les MRs ouvertes sur mon-app" → {"command": "list_mrs", "args": {"name": "mon-app"}}
- "la CI passe ?" → {"command": "pipeline_status", "args": {}}
- "liste les campagnes" → {"command": "api_query", "args": {"query": "lister les campagnes"}}
- "combien de users" → {"command": "api_query", "args": {"query": "compter les utilisateurs"}}
- "montre moi les commandes recentes" → {"command": "api_query", "args": {"query": "lister les commandes recentes"}}
- "fix le bug du login" → {"command": null}
- "ajoute un bouton" → {"command": null}

Reponds UNIQUEMENT en JSON valide.
PROMPT
        );

        if (!$response) return null;

        $clean = trim($response);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $parsed = json_decode($clean, true);
        if (!$parsed || empty($parsed['command'])) return null;

        return [
            'command' => $parsed['command'],
            'args' => $parsed['args'] ?? [],
        ];
    }

    private function handleSmartCommand(array $command, AgentContext $context): AgentResult
    {
        $gitlab = $this->getGitlab();

        if (!$gitlab->isConfigured()) {
            $reply = "Le token GitLab n'est pas configure. Ajoute-le dans les settings.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = match ($command['command']) {
            'list_gitlab_projects' => $this->cmdListGitlabProjects($context),
            'project_info' => $this->cmdProjectInfo($command['args']['name'], $context),
            'list_branches' => $this->cmdListBranches($command['args']['name'], $context),
            'list_mrs' => $this->cmdListMRs($command['args']['name'], $context),
            'pipeline_status' => $this->cmdPipelineStatus($command['args']['name'], $context),
            'recent_commits' => $this->cmdRecentCommits($command['args']['name'], $context),
            'list_issues' => $this->cmdListIssues($command['args']['name'], $context),
            'create_issue' => $this->cmdCreateIssue($command['args']['title'], $context),
            'search_code' => $this->cmdSearchCode($command['args']['query'], $context),
            'file_tree' => $this->cmdFileTree($command['args']['name'], $context),
            'read_file' => $this->cmdReadFile($command['args']['path'], $context),
            'compare_branches' => $this->cmdCompareBranches($command['args']['from'], $command['args']['to'], $context),
            'project_health' => $this->cmdProjectHealth($command['args']['name'], $context),
            'task_history' => $this->cmdTaskHistory($command['args']['name'], $context),
            'rollback' => $this->cmdRollback($context),
            'deploy_status' => $this->cmdDeployStatus($command['args']['name'], $context),
            'api_query' => $this->cmdApiQuery($command['args']['query'] ?? $context->body, $context),
            default => "Commande non reconnue.",
        };

        $this->sendText($context->from, $reply);
        $this->log($context, "Smart command: {$command['command']}", $command['args']);
        return AgentResult::reply($reply);
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

        // 1. Exact match in local DB
        $exact = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->where('name', 'ilike', $name)
            ->first();
        if ($exact) return $exact;

        // 2. Partial match in local DB
        $partial = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->where('name', 'ilike', "%{$name}%")
            ->first();
        if ($partial) return $partial;

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

        if ($bestMatch) return $bestMatch;

        // 4. Search in GitLab API
        $gitlab = $this->getGitlab();
        $gitlabResults = $gitlab->listProjects($name, 5);

        if ($gitlabResults && !empty($gitlabResults)) {
            $gitlabNames = array_map(fn($p) => $p['path_with_namespace'], array_slice($gitlabResults, 0, 5));

            // Exact match in GitLab results → auto-create local project
            foreach ($gitlabResults as $gp) {
                if (mb_strtolower($gp['name']) === mb_strtolower($name) ||
                    mb_strtolower($gp['path']) === mb_strtolower($name)) {
                    return $this->createProjectFromGitlab($gp, $context);
                }
            }

            // Single result → use it
            if (count($gitlabResults) === 1) {
                return $this->createProjectFromGitlab($gitlabResults[0], $context);
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
            'claude-haiku-4-5-20251001',
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

        // Fuzzy match (Levenshtein) on words in the message
        $words = preg_split('/\s+/', mb_strtolower($body));
        foreach ($projects as $project) {
            $projectNameLower = mb_strtolower($project->name);
            foreach ($words as $word) {
                if (strlen($word) >= 3 && levenshtein($word, $projectNameLower) <= 2) {
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
