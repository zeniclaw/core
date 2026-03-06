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

        // Check for smart commands before treating as dev request
        $command = $this->detectSmartCommand($context->body);
        if ($command) {
            return $this->handleSmartCommand($command, $context);
        }

        // Process new dev request
        return $this->handleDevRequest($context);
    }

    // ── Smart Commands Detection ────────────────────────────────────

    private function detectSmartCommand(string $body): ?array
    {
        $lower = mb_strtolower(trim($body));

        // List GitLab projects
        if (preg_match('/^(list|liste|ls|voir|show|affiche)\s*(mes\s*)?(projets?|repos?|repositories|gitlab)/iu', $lower)) {
            return ['command' => 'list_gitlab_projects', 'args' => []];
        }

        // Project status / info
        if (preg_match('/^(status|statut|info|infos|details?)\s+(du\s+)?(projet|repo)\s+(.+)/iu', $lower, $m)) {
            return ['command' => 'project_info', 'args' => ['name' => trim($m[4])]];
        }

        // List branches
        if (preg_match('/^(branches?|list\s*branches?|les\s*branches?)\s*(de|du|of|pour)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'list_branches', 'args' => ['name' => trim($m[3] ?? '')]];
        }

        // List MRs
        if (preg_match('/^(mrs?|merge\s*requests?|list\s*mrs?)\s*(de|du|of|pour|ouvertes?)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'list_mrs', 'args' => ['name' => trim($m[3] ?? '')]];
        }

        // Pipeline status
        if (preg_match('/^(pipeline[s]?|ci|ci\/cd)\s*(de|du|of|pour|status)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'pipeline_status', 'args' => ['name' => trim($m[3] ?? '')]];
        }

        // Recent commits
        if (preg_match('/^(commits?|derniers?\s*commits?|log|historique)\s*(de|du|of|pour)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'recent_commits', 'args' => ['name' => trim($m[3] ?? '')]];
        }

        // Issues
        if (preg_match('/^(issues?|tickets?|bugs?)\s*(de|du|of|pour|ouvertes?)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'list_issues', 'args' => ['name' => trim($m[3] ?? '')]];
        }

        // Create issue
        if (preg_match('/^(creer?|create|nouvelle?|new|ajoute[r]?)\s+(issue|ticket|bug)\s+(.+)/iu', $lower, $m)) {
            return ['command' => 'create_issue', 'args' => ['title' => trim($m[3])]];
        }

        // Search code
        if (preg_match('/^(cherche|search|find|trouve)\s+(dans\s+le\s+code\s+)?(.+)/iu', $lower, $m)) {
            return ['command' => 'search_code', 'args' => ['query' => trim($m[3])]];
        }

        // File tree
        if (preg_match('/^(arborescence|tree|fichiers?|structure|ls\s+repo)\s*(de|du|of|pour)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'file_tree', 'args' => ['name' => trim($m[3] ?? '')]];
        }

        // Read file
        if (preg_match('/^(lis|read|cat|montre|affiche)\s+(le\s+)?(fichier|file)\s+(.+)/iu', $lower, $m)) {
            return ['command' => 'read_file', 'args' => ['path' => trim($m[4])]];
        }

        // Compare branches
        if (preg_match('/^(compare|diff|comparer?)\s+(.+)\s+(et|vs|with|contre)\s+(.+)/iu', $lower, $m)) {
            return ['command' => 'compare_branches', 'args' => ['from' => trim($m[2]), 'to' => trim($m[4])]];
        }

        // Project health
        if (preg_match('/^(health|sante|bilan|diagnostic)\s*(du\s*)?(projet|repo)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'project_health', 'args' => ['name' => trim($m[4] ?? '')]];
        }

        // Task history
        if (preg_match('/^(historique|history|taches?|tasks?)\s*(du\s*)?(projet|repo)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'task_history', 'args' => ['name' => trim($m[4] ?? '')]];
        }

        // Rollback
        if (preg_match('/^(rollback|revert|annuler?)\s+(la\s+)?(derniere\s+)?(tache|task|modif)/iu', $lower)) {
            return ['command' => 'rollback', 'args' => []];
        }

        // Deploy status
        if (preg_match('/^(deploy|deploiement|deployment)\s*(status|statut)?\s*(de|du|of|pour)?\s*(.+)?/iu', $lower, $m)) {
            return ['command' => 'deploy_status', 'args' => ['name' => trim($m[4] ?? '')]];
        }

        return null;
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

        $lines = ["*Tes projets GitLab :*\n"];
        foreach (array_slice($projects, 0, 20) as $i => $p) {
            $name = $p['path_with_namespace'] ?? $p['name'];
            $updated = isset($p['last_activity_at']) ? substr($p['last_activity_at'], 0, 10) : '?';
            $lines[] = ($i + 1) . ". *{$name}* (modifie: {$updated})";
        }

        $lines[] = "\nPour travailler sur un projet, dis-moi son nom !";
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

            // Multiple results → ask user to clarify
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
