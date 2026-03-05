<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\SelfImprovement;
use App\Models\SubAgent;
use App\Services\AnthropicClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RunSubAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // default, overridden in handle()
    public int $tries = 2; // allow 1 retry after container restart

    /**
     * No queue middleware — sequential execution is handled by tries=1 + single worker.
     */
    public function middleware(): array
    {
        return [];
    }

    /**
     * Handle job failure (container killed, timeout, etc.)
     */
    public function failed(?\Throwable $exception): void
    {
        $this->subAgent->refresh();
        if (in_array($this->subAgent->status, ['running', 'queued'])) {
            $this->subAgent->update([
                'status' => 'failed',
                'error_message' => 'Job interrompu: ' . ($exception?->getMessage() ?? 'container redemarré'),
                'completed_at' => now(),
                'pid' => null,
            ]);
            $this->subAgent->project->update(['status' => 'failed']);
            $this->subAgent->appendLog('[ERROR] Job interrompu (container redemarré ou timeout)');
        }
    }

    private string $workspace;
    private string $lastToolUsed = '';
    private float $lastProgressNotification = 0;

    public function __construct(
        public SubAgent $subAgent
    ) {
        $minutes = $this->subAgent->timeout_minutes ?: 10;
        $this->timeout = $minutes * 60;
    }

    public function handle(): void
    {
        $this->workspace = storage_path("app/subagent-workspaces/{$this->subAgent->id}");

        try {
            $this->subAgent->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
            $this->subAgent->project->update(['status' => 'in_progress']);

            $this->subAgent->appendLog("[START] SubAgent #{$this->subAgent->id} demarré");

            // Step 1: Clone the repo
            $this->notifyRequester("[1/8] Recuperation du repo...");
            $this->cloneRepo();

            // Step 2: Reuse existing branch or create new one
            $branchName = $this->resolveOrCreateBranch();
            $this->subAgent->update(['branch_name' => $branchName]);

            // Step 3: Run ZeniClaw AI with context from previous tasks
            $isReadonly = (bool) $this->subAgent->is_readonly;

            if ($isReadonly) {
                $this->notifyRequester("Investigation en cours...");
            } else {
                $this->notifyRequester("[2/8] ZeniClaw AI analyse et applique les modifications...");
            }
            $this->runClaudeCode();

            if ($isReadonly) {
                // Readonly task: report findings, skip commit/push/MR/deploy
                $this->subAgent->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                $this->subAgent->project->update(['status' => 'completed']);
                $this->syncImprovementStatus('completed');
                $this->subAgent->appendLog("[DONE] Investigation terminee (readonly)");

                // Extract Claude's findings from the log to send to user
                $findings = $this->extractReadonlyFindings();
                $this->notifyRequester($findings ?: "Investigation terminee. Consulte les logs pour les details.");
            } else {
                // Step 3.5: Verify the work and retry if needed
                $this->notifyRequester("[3/8] Verification du code...");
                $this->verifyAndRetryIfNeeded();

                // Step 4: Commit and push
                $this->notifyRequester("[4/8] Push du code...");
                $commitHash = $this->commitAndPush($branchName);
                $this->subAgent->update(['commit_hash' => $commitHash]);

                // Step 4.5: Send diff preview to user
                $this->sendDiffPreview();

                // Step 4.6: Security analysis
                $this->notifyRequester("[5/8] Analyse de securite...");
                $this->runSecurityAnalysis();

                // Step 5: Create Merge Request (only if none exists for this branch)
                $this->notifyRequester("[6/8] Creation et merge de la MR...");
                $mrUrl = $this->findOrCreateMergeRequest($branchName);

                // Step 7: Verify deployment on Laravel Cloud
                $this->notifyRequester("[7/8] Verification du deploiement...");
                $deployStatus = $this->checkLaravelCloudDeployment();

                $this->subAgent->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                $this->subAgent->project->update(['status' => 'completed']);
                $this->syncImprovementStatus('completed');
                $this->subAgent->appendLog("[DONE] Termine. Branche: {$branchName}, Commit: {$commitHash}");

                $message = "C'est fait !\nCommit: {$commitHash}";
                if ($mrUrl) {
                    $message .= "\nMerge Request: {$mrUrl}";
                }
                if ($deployStatus) {
                    $message .= "\nDeploy: {$deployStatus}";
                }
                $this->notifyRequester($message);
            }

        } catch (\Throwable $e) {
            Log::error("SubAgent #{$this->subAgent->id} failed: " . $e->getMessage());
            $this->subAgent->appendLog("[ERROR] " . $e->getMessage());

            // Don't overwrite 'killed' status
            $this->subAgent->refresh();
            if ($this->subAgent->status !== 'killed') {
                $this->subAgent->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
                $this->subAgent->project->update(['status' => 'failed']);
                $this->syncImprovementStatus('failed');
                $this->notifyRequester(
                    "Oups, j'ai pas reussi cette fois.\nErreur: " . substr($e->getMessage(), 0, 200)
                );
            } else {
                $this->subAgent->update(['completed_at' => now(), 'pid' => null]);
            }
        } finally {
            // Clear PID
            $this->subAgent->update(['pid' => null]);

            // Cleanup workspace
            if (is_dir($this->workspace)) {
                Process::run("rm -rf " . escapeshellarg($this->workspace));
                $this->subAgent->appendLog("[CLEANUP] Workspace supprime");
            }
        }
    }

    /**
     * Verify the work done by Claude Code and retry once if issues are found.
     * Uses Claude Haiku to review the git diff + check for syntax errors.
     */
    private function verifyAndRetryIfNeeded(): void
    {
        $this->subAgent->appendLog("[VERIFY] Verification des modifications...");

        // Get the diff of what was changed
        $diffResult = Process::path($this->workspace)->run('git diff HEAD');
        $diff = $diffResult->output();

        // Also get list of new/modified files
        $statusResult = Process::path($this->workspace)->run('git status --porcelain');
        $status = $statusResult->output();

        if (empty(trim($status))) {
            $this->subAgent->appendLog("[VERIFY] Aucune modification detectee - rien a verifier");
            return;
        }

        // Run PHP syntax check on modified/added PHP files
        $syntaxErrors = [];
        foreach (explode("\n", trim($status)) as $line) {
            $file = trim(substr($line, 3));
            if (!str_ends_with($file, '.php') || !file_exists("{$this->workspace}/{$file}")) {
                continue;
            }
            $check = Process::path($this->workspace)->run("php -l " . escapeshellarg($file));
            if (!$check->successful()) {
                $syntaxErrors[] = "{$file}: " . trim($check->errorOutput());
            }
        }

        // Build verification prompt
        $task = $this->subAgent->task_description;
        $verifyPrompt = "Tache demandee:\n{$task}\n\n"
            . "Fichiers modifies:\n{$status}\n\n"
            . "Diff des modifications:\n" . substr($diff, 0, 15000) . "\n\n";

        if ($syntaxErrors) {
            $verifyPrompt .= "ERREURS DE SYNTAXE PHP DETECTEES:\n" . implode("\n", $syntaxErrors) . "\n\n";
        }

        $verifyPrompt .= "Analyse ces modifications et reponds UNIQUEMENT par un JSON:\n"
            . "{\"ok\": true/false, \"issues\": [\"probleme 1\", \"probleme 2\"]}\n"
            . "- ok=true si les modifications realisent correctement la tache demandee\n"
            . "- ok=false si il y a des problemes: fichiers manquants, routes non ajoutees, erreurs de syntaxe, "
            . "imports manquants, controller/vue incomplets, etc.\n"
            . "- Sois strict: verifie que TOUT ce qui est necessaire est present (routes, controller, vue, model, migration si besoin)\n"
            . "Reponds UNIQUEMENT avec le JSON.";

        $claude = new AnthropicClient();
        $response = $claude->chat($verifyPrompt, 'claude-haiku-4-5-20251001');

        // Parse response
        $clean = trim($response ?? '');
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $result = json_decode($clean, true);

        if (!$result) {
            $this->subAgent->appendLog("[VERIFY] Impossible de parser la reponse de verification - on continue");
            return;
        }

        // Add syntax errors as issues even if Haiku says ok
        if ($syntaxErrors && ($result['ok'] ?? false)) {
            $result['ok'] = false;
            $result['issues'] = array_merge($result['issues'] ?? [], $syntaxErrors);
        }

        if ($result['ok'] ?? false) {
            $this->subAgent->appendLog("[VERIFY] Verification OK !");
            return;
        }

        // Issues found — retry with Claude Code
        $issues = $result['issues'] ?? ['Problemes non specifies'];
        $issueList = implode("\n- ", $issues);
        $this->subAgent->appendLog("[VERIFY] Problemes detectes:\n- {$issueList}");
        $this->subAgent->appendLog("[VERIFY] Relance de Claude Code pour corriger...");
        $this->notifyRequester("Verification en cours... des corrections sont necessaires, je relance.");

        // Build fix prompt and re-run Claude Code
        $fixPrompt = "Tu travailles dans un repository git. Tu as deja fait des modifications mais la verification a detecte des problemes.\n\n"
            . "Tache originale:\n{$task}\n\n"
            . "Problemes detectes:\n- {$issueList}\n\n"
            . "Corrige ces problemes. Verifie que tout est complet: routes, controllers, vues, models, migrations, imports.";

        $apiKey = AppSetting::get('anthropic_api_key');
        $apiCalls = 0;
        $this->executeClaudeCode($fixPrompt, 'opus', $apiKey, $apiCalls);
        $currentCalls = $this->subAgent->api_calls_count ?? 0;
        $this->subAgent->update(['api_calls_count' => $currentCalls + $apiCalls]);
        $this->subAgent->appendLog("[VERIFY] Corrections appliquees (retry termine)");
    }

    /**
     * Check Laravel Cloud deployment status after merge, then check app logs for errors.
     */
    private function checkLaravelCloudDeployment(): ?string
    {
        $cloudToken = AppSetting::get('laravel_cloud_token');
        if (!$cloudToken) {
            $this->subAgent->appendLog("[DEPLOY] Pas de token Laravel Cloud configure, skip");
            return null;
        }

        try {
            $envId = $this->findCloudEnvironment($cloudToken);
            if (!$envId) {
                return null;
            }

            // Step 1: Wait for deployment to finish
            $deployResult = $this->waitForDeployment($cloudToken, $envId);
            if (!$deployResult['success']) {
                return $deployResult['message'];
            }

            // Step 2: After deploy succeeds, wait a bit then check app logs for errors
            $this->subAgent->appendLog("[DEPLOY] Deploiement reussi ! Verification des logs applicatifs...");
            $this->notifyRequester("[8/8] Deploy OK. Verification des logs pour erreurs...");
            sleep(20); // Wait for app to start serving requests

            $errors = $this->checkCloudAppLogs($cloudToken, $envId);
            if ($errors) {
                $this->subAgent->appendLog("[DEPLOY] Erreurs detectees dans les logs apres deploy:\n{$errors}");
                $this->notifyRequester("Deploy OK mais des erreurs ont ete detectees dans les logs:\n{$errors}");
                return "Deploy OK mais erreurs detectees ({$deployResult['appName']})";
            }

            $this->subAgent->appendLog("[DEPLOY] Aucune erreur dans les logs. Tout est bon !");
            return "OK (deploye sur {$deployResult['appName']})";

        } catch (\Throwable $e) {
            $this->subAgent->appendLog("[DEPLOY] Erreur: " . $e->getMessage());
            return null;
        }
    }

    private function findCloudEnvironment(string $cloudToken): ?string
    {
        $gitlabUrl = $this->subAgent->project->gitlab_url;
        $repoPath = trim(parse_url($gitlabUrl, PHP_URL_PATH), '/');
        $repoPath = rtrim($repoPath, '.git');

        $this->subAgent->appendLog("[DEPLOY] Recherche de l'app Laravel Cloud pour {$repoPath}...");

        $appsResponse = Http::timeout(15)
            ->withHeaders([
                'Authorization' => "Bearer {$cloudToken}",
                'Accept' => 'application/json',
            ])
            ->get('https://cloud.laravel.com/api/applications', [
                'include' => 'environments',
            ]);

        if (!$appsResponse->successful()) {
            $this->subAgent->appendLog("[DEPLOY] Erreur API Laravel Cloud: " . $appsResponse->status());
            return null;
        }

        foreach ($appsResponse->json('data') as $app) {
            $appRepo = $app['attributes']['repository']['full_name'] ?? '';
            if (strcasecmp($appRepo, $repoPath) === 0) {
                $this->cloudAppName = $app['attributes']['name'];
                $envs = $app['relationships']['environments']['data'] ?? [];
                if (!empty($envs)) {
                    $envId = $envs[0]['id'];
                    $this->subAgent->appendLog("[DEPLOY] App trouvee: {$this->cloudAppName} (env: {$envId})");
                    return $envId;
                }
            }
        }

        $this->subAgent->appendLog("[DEPLOY] App non trouvee sur Laravel Cloud pour {$repoPath}");
        return null;
    }

    private string $cloudAppName = '';
    private string $cloudEnvId = '';

    private function waitForDeployment(string $cloudToken, string $envId): array
    {
        $this->cloudEnvId = $envId;

        for ($i = 0; $i < 12; $i++) {
            sleep(15);

            $deplResponse = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => "Bearer {$cloudToken}",
                    'Accept' => 'application/json',
                ])
                ->get("https://cloud.laravel.com/api/environments/{$envId}/deployments");

            if (!$deplResponse->successful()) {
                continue;
            }

            $deployments = $deplResponse->json('data');
            if (empty($deployments)) {
                continue;
            }

            $latest = $deployments[0];
            $status = $latest['attributes']['status'] ?? 'unknown';
            $deplCommit = substr($latest['attributes']['commit_hash'] ?? '', 0, 8);

            $this->subAgent->appendLog("[DEPLOY] Status: {$status} (commit: {$deplCommit}) - " . ($i + 1) . "/12");

            if ($status === 'deployment.succeeded') {
                return ['success' => true, 'appName' => $this->cloudAppName];
            }

            if ($status === 'deployment.failed') {
                $logResponse = Http::timeout(15)
                    ->withHeaders([
                        'Authorization' => "Bearer {$cloudToken}",
                        'Accept' => 'application/json',
                    ])
                    ->get("https://cloud.laravel.com/api/deployments/{$latest['id']}/logs");

                $logs = $logResponse->successful() ? substr(json_encode($logResponse->json()), 0, 500) : '';

                $this->subAgent->appendLog("[DEPLOY] ECHEC du deploiement !\n{$logs}");
                $this->notifyRequester("Le deploiement sur {$this->cloudAppName} a echoue !");
                return ['success' => false, 'message' => "ECHEC (deploy echoue sur {$this->cloudAppName})"];
            }
        }

        $this->subAgent->appendLog("[DEPLOY] Timeout - deploiement toujours en cours apres 3 min");
        return ['success' => false, 'message' => "Timeout (deploy en cours sur {$this->cloudAppName})"];
    }

    /**
     * Check app logs on Laravel Cloud for errors after deployment.
     */
    private function checkCloudAppLogs(string $cloudToken, string $envId): ?string
    {
        $from = now()->subSeconds(30)->toIso8601String();
        $to = now()->toIso8601String();

        $logResponse = Http::timeout(15)
            ->withHeaders([
                'Authorization' => "Bearer {$cloudToken}",
                'Accept' => 'application/json',
            ])
            ->get("https://cloud.laravel.com/api/environments/{$envId}/logs", [
                'from' => $from,
                'to' => $to,
            ]);

        if (!$logResponse->successful()) {
            $this->subAgent->appendLog("[DEPLOY] Impossible de recuperer les logs (HTTP " . $logResponse->status() . ")");
            return null;
        }

        $entries = $logResponse->json('data') ?? [];
        $errors = [];

        foreach ($entries as $entry) {
            $level = $entry['level'] ?? '';
            $message = $entry['message'] ?? '';
            if (in_array($level, ['error', 'critical', 'emergency'])) {
                $errors[] = "[{$level}] {$message}";
            }
        }

        if (empty($errors)) {
            $this->subAgent->appendLog("[DEPLOY] " . count($entries) . " log entries, aucune erreur");
            return null;
        }

        return implode("\n", array_slice($errors, 0, 5)); // Max 5 errors
    }

    private function cloneRepo(): void
    {
        // Clean up any leftover workspace from a previous run
        if (is_dir($this->workspace)) {
            Process::run("rm -rf " . escapeshellarg($this->workspace));
            $this->subAgent->appendLog("[GIT] Ancien workspace nettoye");
        }

        $gitlabUrl = $this->subAgent->project->gitlab_url;
        $token = AppSetting::get('gitlab_access_token');

        if (!$token) {
            throw new \RuntimeException('GitLab access token not configured');
        }

        // Insert token into URL for auth: https://oauth2:TOKEN@gitlab.com/...
        $authedUrl = preg_replace(
            '#^(https?://)#',
            '$1oauth2:' . $token . '@',
            $gitlabUrl
        );

        // Ensure .git suffix
        if (!str_ends_with($authedUrl, '.git')) {
            $authedUrl .= '.git';
        }

        $this->subAgent->appendLog("[GIT] Clonage de {$gitlabUrl}...");

        $result = Process::timeout(120)->run(
            "git clone --depth 50 " . escapeshellarg($authedUrl) . " " . escapeshellarg($this->workspace)
        );

        if (!$result->successful()) {
            throw new \RuntimeException("Git clone failed: " . $result->errorOutput());
        }

        // Set local git config in workspace
        Process::path($this->workspace)->run('git config user.email "zeniclaw@bot.local"');
        Process::path($this->workspace)->run('git config user.name "ZeniClaw SubAgent"');

        $this->subAgent->appendLog("[GIT] Clone reussi");
    }

    /**
     * Reuse the branch from a previous SubAgent on the same project,
     * or create a fresh one. This keeps context between consecutive requests.
     */
    private function resolveOrCreateBranch(): string
    {
        $projectId = $this->subAgent->project_id;

        // Find the most recent completed SubAgent on the same project with a branch
        $previous = SubAgent::where('project_id', $projectId)
            ->where('id', '!=', $this->subAgent->id)
            ->whereNotNull('branch_name')
            ->whereIn('status', ['completed', 'running'])
            ->orderByDesc('id')
            ->first();

        if ($previous) {
            $existingBranch = $previous->branch_name;

            // Try to checkout the existing remote branch
            $fetch = Process::path($this->workspace)->run(
                "git fetch origin " . escapeshellarg($existingBranch) . " 2>&1"
            );

            if ($fetch->successful()) {
                $checkout = Process::path($this->workspace)->run(
                    "git checkout -b " . escapeshellarg($existingBranch) . " origin/" . escapeshellarg($existingBranch) . " 2>&1"
                );

                if ($checkout->successful()) {
                    $this->subAgent->appendLog("[GIT] Branche existante recuperee: {$existingBranch} (continuite des modifs precedentes)");
                    return $existingBranch;
                }
            }

            $this->subAgent->appendLog("[GIT] Branche {$existingBranch} introuvable sur le remote, creation d'une nouvelle");
        }

        // No previous branch or couldn't fetch it → create new
        $branchName = "zeniclaw/subagent-{$this->subAgent->id}";
        $result = Process::path($this->workspace)->run(
            "git checkout -b " . escapeshellarg($branchName)
        );

        if (!$result->successful()) {
            throw new \RuntimeException("Branch creation failed: " . $result->errorOutput());
        }

        $this->subAgent->appendLog("[GIT] Nouvelle branche creee: {$branchName}");
        return $branchName;
    }

    /**
     * Run ZeniClaw AI CLI to apply modifications.
     * Tries Opus first, falls back to Sonnet if overloaded.
     */
    private function runClaudeCode(): void
    {
        $apiKey = AppSetting::get('anthropic_api_key');
        if (!$apiKey) {
            throw new \RuntimeException('Anthropic API key not configured');
        }

        $task = $this->subAgent->task_description;

        // Build context from previous tasks on the same project
        $previousTasks = SubAgent::where('project_id', $this->subAgent->project_id)
            ->where('id', '!=', $this->subAgent->id)
            ->where('status', 'completed')
            ->orderBy('id')
            ->pluck('task_description')
            ->toArray();

        $isReadonly = (bool) $this->subAgent->is_readonly;

        $prompt = "Tu travailles dans un repository git.";
        if ($previousTasks) {
            $prompt .= "\n\nModifications deja effectuees precedemment sur ce projet:\n";
            foreach ($previousTasks as $i => $prevTask) {
                $prompt .= ($i + 1) . ". {$prevTask}\n";
            }
            $prompt .= "\nCes modifs sont deja dans le code. Ne les refais pas.";
        }

        if ($isReadonly) {
            $prompt .= "\n\nIMPORTANT: C'est une tache de diagnostic/lecture UNIQUEMENT. Tu ne dois modifier AUCUN fichier. Investigate, analyse, et rapporte tes trouvailles de maniere claire et concise.";
            $prompt .= "\n\nTache a investiguer:\n{$task}";
        } else {
            $prompt .= "\n\nNouvelle tache a realiser maintenant:\n{$task}\n\nApplique les modifications necessaires directement sur les fichiers du projet.";
        }

        $models = ['opus', 'sonnet'];

        foreach ($models as $i => $model) {
            $this->subAgent->appendLog("[ZENICLAW AI] Lancement avec modele {$model}...");
            if ($i === 0) {
                $this->subAgent->appendLog("[ZENICLAW AI] Tache: {$task}");
            }

            $apiCalls = 0;
            $success = $this->executeClaudeCode($prompt, $model, $apiKey, $apiCalls);
            $this->subAgent->update(['api_calls_count' => $apiCalls]);

            if ($success) {
                $this->subAgent->appendLog("[ZENICLAW AI] Termine avec {$model}");
                return;
            }

            // Check if files were modified despite the error
            $hasChanges = Process::path($this->workspace)->run('git status --porcelain');
            if (!empty(trim($hasChanges->output()))) {
                $this->subAgent->appendLog("[WARN] ZeniClaw AI ({$model}) a echoue mais des fichiers ont ete modifies. On continue.");
                return;
            }

            // If not last model, try next one
            if ($i < count($models) - 1) {
                $this->subAgent->appendLog("[WARN] {$model} echoue (API surchargee?). Fallback vers {$models[$i + 1]}...");
                // Reset git state for clean retry
                Process::path($this->workspace)->run('git checkout -- .');
                Process::path($this->workspace)->run('git clean -fd');
            }
        }

        throw new \RuntimeException("ZeniClaw AI a echoue avec tous les modeles (opus, sonnet)");
    }

    /**
     * Execute ZeniClaw AI with a specific model. Returns true on success.
     */
    private function executeClaudeCode(string $prompt, string $model, string $apiKey, int &$apiCalls): bool
    {
        $claudeTimeout = max(($this->subAgent->timeout_minutes ?: 10) * 60 - 120, 120);

        $cmd = sprintf(
            'claude -p %s --model %s --dangerously-skip-permissions --verbose --output-format stream-json 2>&1',
            escapeshellarg($prompt),
            escapeshellarg($model)
        );

        // OAuth tokens (sk-ant-oat*) from Max/Pro plans use CLAUDE_CODE_OAUTH_TOKEN,
        // standard API keys (sk-ant-api*) use ANTHROPIC_API_KEY
        $envKey = str_starts_with($apiKey, 'sk-ant-oat')
            ? 'CLAUDE_CODE_OAUTH_TOKEN'
            : 'ANTHROPIC_API_KEY';

        $process = Process::timeout($claudeTimeout)
            ->path($this->workspace)
            ->env([
                $envKey => $apiKey,
                'HOME' => '/tmp',
            ])
            ->start($cmd);

        // Store PID for kill functionality
        $pid = $process->id();
        if ($pid) {
            $this->subAgent->update(['pid' => $pid]);
        }

        // Poll output in real-time, check for kill requests
        while ($process->running()) {
            // Check if killed
            $this->subAgent->refresh();
            if ($this->subAgent->status === 'killed') {
                $this->subAgent->appendLog("[KILL] Arret demande par l'admin");
                $this->killProcessTree($pid);
                throw new \RuntimeException('SubAgent arrete par l\'admin');
            }

            $this->consumeProcessOutput($process, $apiCalls);
            $this->sendProgressNotificationIfNeeded();
            usleep(500_000);
        }

        // Capture remaining output
        $result = $process->wait();
        $remaining = $result->output();
        if ($remaining) {
            foreach (explode("\n", trim($remaining)) as $line) {
                $line = trim($line);
                if (!$line) continue;
                $event = json_decode($line, true);
                if ($event) {
                    $this->processStreamEvent($event, $apiCalls);
                } else {
                    $this->subAgent->appendLog($line);
                }
            }
        }

        return $result->successful();
    }

    /**
     * Read and log streaming output from a running ZeniClaw AI process.
     */
    private function consumeProcessOutput($process, int &$apiCalls): void
    {
        $newOutput = $process->latestOutput();
        $newError = $process->latestErrorOutput();

        if ($newOutput) {
            foreach (explode("\n", trim($newOutput)) as $line) {
                $line = trim($line);
                if (!$line) continue;

                $event = json_decode($line, true);
                if (!$event) {
                    $this->subAgent->appendLog($line);
                    continue;
                }

                $this->processStreamEvent($event, $apiCalls);
            }
        }

        if ($newError) {
            foreach (explode("\n", trim($newError)) as $errLine) {
                if (trim($errLine)) {
                    $this->subAgent->appendLog("[STDERR] " . trim($errLine));
                }
            }
        }
    }

    /**
     * Parse a stream-json event and log readable output.
     */
    private function processStreamEvent(array $event, int &$apiCalls): void
    {
        $type = $event['type'] ?? '';

        switch ($type) {
            case 'system':
                $subtype = $event['subtype'] ?? '';
                if ($subtype === 'init') {
                    $model = $event['model'] ?? 'unknown';
                    $this->subAgent->appendLog("[ZENICLAW AI] Modele: {$model}");
                }
                break;

            case 'assistant':
                $apiCalls++;
                $content = $event['message']['content'] ?? [];
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                        // Truncate long text blocks
                        $text = $block['text'];
                        if (strlen($text) > 300) {
                            $text = substr($text, 0, 300) . '...';
                        }
                        $this->subAgent->appendLog("[CLAUDE] " . $text);
                    } elseif (($block['type'] ?? '') === 'tool_use') {
                        $tool = $block['name'] ?? '?';
                        $input = $block['input'] ?? [];
                        $desc = match ($tool) {
                            'Read' => "Lecture: " . ($input['file_path'] ?? '?'),
                            'Edit' => "Edition: " . ($input['file_path'] ?? '?'),
                            'Write' => "Ecriture: " . ($input['file_path'] ?? '?'),
                            'Bash' => "Commande: " . substr($input['command'] ?? '?', 0, 100),
                            'Glob' => "Recherche fichiers: " . ($input['pattern'] ?? '?'),
                            'Grep' => "Recherche code: " . ($input['pattern'] ?? '?'),
                            default => "Outil: {$tool}",
                        };
                        $this->lastToolUsed = $desc;
                        $this->subAgent->appendLog("[TOOL] {$desc}");
                    }
                }
                break;

            case 'result':
                $resultText = $event['result'] ?? '';
                if ($resultText) {
                    if (strlen($resultText) > 500) {
                        $resultText = substr($resultText, 0, 500) . '...';
                    }
                    $this->subAgent->appendLog("[RESULT] " . $resultText);
                }
                break;
        }
    }

    private function extractReadonlyFindings(): string
    {
        $log = $this->subAgent->output_log ?? '';
        $findings = [];

        // Extract [RESULT] lines (final Claude output)
        foreach (explode("\n", $log) as $line) {
            if (str_starts_with($line, '[RESULT] ')) {
                $findings[] = substr($line, 9);
            }
        }

        if ($findings) {
            return implode("\n", $findings);
        }

        // Fallback: extract [CLAUDE] text blocks
        foreach (explode("\n", $log) as $line) {
            if (str_starts_with($line, '[CLAUDE] ')) {
                $findings[] = substr($line, 9);
            }
        }

        return $findings ? implode("\n", $findings) : '';
    }

    private function commitAndPush(string $branchName): string
    {
        $this->subAgent->appendLog("[GIT] Staging des modifications...");

        $result = Process::path($this->workspace)->run('git add -A');
        if (!$result->successful()) {
            throw new \RuntimeException("Git add failed: " . $result->errorOutput());
        }

        // Check if there are changes to commit
        $status = Process::path($this->workspace)->run('git status --porcelain');
        if (empty(trim($status->output()))) {
            $this->subAgent->appendLog("[GIT] Aucune modification a committer");
            return 'no-changes';
        }

        // Log what changed
        $this->subAgent->appendLog("[GIT] Fichiers modifies:\n" . trim($status->output()));

        $commitMessage = "feat: " . substr($this->subAgent->task_description, 0, 72) . "\n\nAutomated by ZeniClaw SubAgent #{$this->subAgent->id}";

        $result = Process::path($this->workspace)->run(
            'git commit -m ' . escapeshellarg($commitMessage)
        );
        if (!$result->successful()) {
            throw new \RuntimeException("Git commit failed: " . $result->errorOutput());
        }

        $this->subAgent->appendLog("[GIT] Commit effectue");

        // Push
        $this->subAgent->appendLog("[GIT] Push vers origin/{$branchName}...");
        $result = Process::timeout(60)->path($this->workspace)->run(
            "git push origin " . escapeshellarg($branchName)
        );
        if (!$result->successful()) {
            throw new \RuntimeException("Git push failed: " . $result->errorOutput());
        }

        // Get commit hash
        $hashResult = Process::path($this->workspace)->run('git rev-parse --short HEAD');
        $commitHash = trim($hashResult->output());

        $this->subAgent->appendLog("[GIT] Push reussi. Commit: {$commitHash}");

        return $commitHash;
    }

    /**
     * Find existing MR for this branch, or create a new one.
     * Then auto-merge it into the target branch.
     * Returns the MR web URL or null on failure.
     */
    private function findOrCreateMergeRequest(string $branchName): ?string
    {
        try {
            $token = AppSetting::get('gitlab_access_token');
            $gitlabUrl = $this->subAgent->project->gitlab_url;

            if (!$token || !$gitlabUrl) return null;

            $parsed = parse_url($gitlabUrl);
            $host = $parsed['host'] ?? 'gitlab.com';
            $projectPath = trim($parsed['path'] ?? '', '/');
            $projectPath = str_replace('.git', '', $projectPath);
            $encodedPath = urlencode($projectPath);

            $apiBase = "https://{$host}/api/v4";
            $mrIid = null;
            $mrUrl = null;

            // Check if MR already exists for this branch
            $existing = Http::timeout(10)
                ->withHeaders(['PRIVATE-TOKEN' => $token])
                ->get("{$apiBase}/projects/{$encodedPath}/merge_requests", [
                    'source_branch' => $branchName,
                    'state' => 'opened',
                ]);

            if ($existing->successful()) {
                $mrs = $existing->json();
                if (!empty($mrs) && isset($mrs[0]['web_url'])) {
                    $mrUrl = $mrs[0]['web_url'];
                    $mrIid = $mrs[0]['iid'];
                    $this->subAgent->appendLog("[MR] Merge Request existante, nouveau commit ajoute: {$mrUrl}");
                }
            }

            // No existing MR → create one
            if (!$mrIid) {
                $title = substr($this->subAgent->task_description, 0, 200);
                $this->subAgent->appendLog("[MR] Creation de la Merge Request...");

                foreach (['main', 'master'] as $targetBranch) {
                    $response = Http::timeout(15)
                        ->withHeaders(['PRIVATE-TOKEN' => $token])
                        ->post("{$apiBase}/projects/{$encodedPath}/merge_requests", [
                            'source_branch' => $branchName,
                            'target_branch' => $targetBranch,
                            'title' => $title,
                            'description' => "Modification automatique par ZeniClaw AI (SubAgent #{$this->subAgent->id})",
                            'remove_source_branch' => true,
                        ]);

                    if ($response->successful()) {
                        $mrUrl = $response->json('web_url');
                        $mrIid = $response->json('iid');
                        $this->subAgent->appendLog("[MR] Merge Request creee: {$mrUrl}");
                        break;
                    }
                }

                if (!$mrIid) {
                    $this->subAgent->appendLog("[MR] Echec creation MR: " . substr(($response ?? null)?->body() ?? '', 0, 200));
                    return null;
                }
            }

            // Auto-merge the MR
            $this->acceptMergeRequest($apiBase, $encodedPath, $token, $mrIid);

            return $mrUrl;

        } catch (\Throwable $e) {
            $this->subAgent->appendLog("[MR] Erreur MR: " . $e->getMessage());
            Log::warning("Failed to create/merge MR: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Accept (merge) a Merge Request via GitLab API.
     * Waits for GitLab to confirm mergeability, then merges.
     */
    private function acceptMergeRequest(string $apiBase, string $encodedPath, string $token, int $mrIid): void
    {
        $this->subAgent->appendLog("[MR] Validation (merge) de la MR #{$mrIid}...");

        $mrEndpoint = "{$apiBase}/projects/{$encodedPath}/merge_requests/{$mrIid}";

        // Step 1: Wait for GitLab to finish checking mergeability
        for ($wait = 1; $wait <= 20; $wait++) {
            $mrInfo = Http::timeout(10)
                ->withHeaders(['PRIVATE-TOKEN' => $token])
                ->get($mrEndpoint);

            if (!$mrInfo->successful()) {
                $this->subAgent->appendLog("[MR] Impossible de lire la MR (HTTP {$mrInfo->status()})");
                return;
            }

            $data = $mrInfo->json();
            $mergeStatus = $data['detailed_merge_status'] ?? $data['merge_status'] ?? 'unknown';

            if (in_array($mergeStatus, ['mergeable', 'can_be_merged'])) {
                $this->subAgent->appendLog("[MR] MR prete a merger (status: {$mergeStatus})");
                break;
            }

            if (in_array($mergeStatus, ['broken_status', 'cannot_be_merged', 'conflict'])) {
                $this->subAgent->appendLog("[MR] MR non mergeable: {$mergeStatus}. Abandon.");
                return;
            }

            // checking, unchecked, ci_must_pass, etc. → wait
            $this->subAgent->appendLog("[MR] MR en cours de verification ({$mergeStatus})... ({$wait}/20)");
            sleep(5);
        }

        // Step 2: Attempt merge
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $mergeResponse = Http::timeout(15)
                ->withHeaders(['PRIVATE-TOKEN' => $token])
                ->put("{$mrEndpoint}/merge", [
                    'should_remove_source_branch' => true,
                ]);

            if ($mergeResponse->successful()) {
                $this->subAgent->appendLog("[MR] Merge Request validee et fusionnee !");
                return;
            }

            $status = $mergeResponse->status();
            $body = substr($mergeResponse->body(), 0, 200);

            // 406 = can't merge (conflicts) → give up
            if ($status === 406) {
                $this->subAgent->appendLog("[MR] Conflit detecte, merge impossible: {$body}");
                return;
            }

            // 405 = not yet mergeable → retry with pipeline option
            if ($status === 405 && $attempt < 3) {
                $this->subAgent->appendLog("[MR] Pas encore prete, tentative avec merge_when_pipeline_succeeds... ({$attempt}/3)");
                $pipelineMerge = Http::timeout(15)
                    ->withHeaders(['PRIVATE-TOKEN' => $token])
                    ->put("{$mrEndpoint}/merge", [
                        'should_remove_source_branch' => true,
                        'merge_when_pipeline_succeeds' => true,
                    ]);

                if ($pipelineMerge->successful()) {
                    $this->subAgent->appendLog("[MR] Merge programme apres le pipeline CI !");
                    return;
                }

                sleep(10);
                continue;
            }

            $this->subAgent->appendLog("[MR] Tentative merge {$attempt}/3 echouee (HTTP {$status}): {$body}");
            if ($attempt < 3) sleep(5);
        }

        $this->subAgent->appendLog("[MR] Impossible de merger automatiquement. La MR reste ouverte.");
    }

    /**
     * Kill a process and all its children.
     */
    private function killProcessTree(?int $pid): void
    {
        if (!$pid) return;
        try {
            // Kill entire process group
            Process::run("kill -TERM -{$pid} 2>/dev/null || kill -TERM {$pid} 2>/dev/null");
            usleep(500_000);
            Process::run("kill -KILL -{$pid} 2>/dev/null || kill -KILL {$pid} 2>/dev/null");
        } catch (\Throwable $e) {
            // Process may already be dead
        }
    }

    /**
     * Send a progress notification every 60 seconds during Claude Code execution.
     * Shows the last tool being used.
     */
    private function sendProgressNotificationIfNeeded(): void
    {
        $now = microtime(true);
        if ($this->lastProgressNotification === 0.0) {
            $this->lastProgressNotification = $now;
            return;
        }

        if (($now - $this->lastProgressNotification) >= 60 && $this->lastToolUsed) {
            $friendly = $this->humanizeProgress($this->lastToolUsed);
            $this->notifyRequester("⏳ {$friendly}");
            $this->lastProgressNotification = $now;
        }
    }

    /**
     * Convert technical tool description into a friendly, casual progress message.
     */
    private function humanizeProgress(string $technicalDesc): string
    {
        try {
            $claude = new AnthropicClient();
            $result = $claude->chat(
                "Description technique: {$technicalDesc}",
                'claude-haiku-4-5-20251001',
                "Transforme cette description technique en un message de progression court et cool pour l'utilisateur. "
                . "Maximum 10 mots. Pas de details techniques (pas de chemins de fichiers, pas de commandes). "
                . "Sois naturel et decontracte. Exemples:\n"
                . "- 'Lecture: /var/www/html/routes/web.php' → 'Je regarde le code des routes...'\n"
                . "- 'Edition: /var/www/html/app/Http/Controllers/TodoController.php' → 'Je modifie le contrôleur des todos...'\n"
                . "- 'Commande: grep -n project-todos routes/web.php' → 'Je cherche les routes du projet...'\n"
                . "- 'Recherche fichiers: **/*.blade.php' → 'Je cherche les fichiers de vue...'\n"
                . "- 'Outil: TodoWrite' → 'Je note les prochaines étapes...'\n"
                . "Reponds UNIQUEMENT avec le message, rien d'autre."
            );
            return $result ?: 'Je bosse dessus...';
        } catch (\Throwable $e) {
            return 'Je bosse dessus...';
        }
    }

    /**
     * Send a diff preview summary to the user after commit.
     * Uses Haiku to summarize the diff in max 5 lines.
     */
    private function sendDiffPreview(): void
    {
        try {
            $diffResult = Process::path($this->workspace)->run('git diff HEAD~1 HEAD');
            $diff = $diffResult->output();

            if (empty(trim($diff))) {
                return;
            }

            // Get list of changed files
            $filesResult = Process::path($this->workspace)->run('git diff --name-only HEAD~1 HEAD');
            $files = trim($filesResult->output());

            $claude = new AnthropicClient();
            $summary = $claude->chat(
                "Voici le diff d'un commit:\n\nFichiers modifies:\n{$files}\n\nDiff:\n" . substr($diff, 0, 15000),
                'claude-haiku-4-5-20251001',
                "Tu resumes un diff git en 5 lignes maximum.\n"
                . "Format STRICT a respecter:\n"
                . "Modifications :\n"
                . "- fichier1 : description courte du changement\n"
                . "- fichier2 : description courte du changement\n"
                . "Regroupe les fichiers lies. Sois concis (1 ligne par fichier ou groupe). Max 5 lignes."
            );

            if ($summary) {
                $this->notifyRequester($summary);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send diff preview: " . $e->getMessage());
            $this->subAgent->appendLog("[PREVIEW] Erreur lors du resume du diff: " . $e->getMessage());
        }
    }

    /**
     * Run a security analysis on the committed diff using Haiku.
     * Scans for: hardcoded secrets, SQL/XSS injections, OWASP vulnerabilities.
     * Logs warnings and notifies user if critical issues found, but does not block.
     */
    private function runSecurityAnalysis(): void
    {
        try {
            $diffResult = Process::path($this->workspace)->run('git diff HEAD~1 HEAD');
            $diff = $diffResult->output();

            if (empty(trim($diff))) {
                $this->subAgent->appendLog("[SECURITY] Aucune modification a analyser");
                return;
            }

            $claude = new AnthropicClient();
            $response = $claude->chat(
                "Analyse ce diff git pour des problemes de securite:\n\n" . substr($diff, 0, 15000),
                'claude-haiku-4-5-20251001',
                "Tu es un expert en securite applicative. Analyse ce diff pour detecter:\n"
                . "1. Secrets/credentials/API keys hardcodes (tokens, mots de passe, cles privees)\n"
                . "2. Injections SQL (requetes non parametrees, concatenation de variables dans du SQL)\n"
                . "3. Failles XSS (output non echappe, {!! !!} avec input utilisateur)\n"
                . "4. Autres failles OWASP (CSRF manquant, IDOR, path traversal, deserialisation non securisee)\n\n"
                . "Reponds UNIQUEMENT par un JSON:\n"
                . "{\"safe\": true/false, \"issues\": [{\"severity\": \"critical|warning\", \"file\": \"fichier\", \"description\": \"description\"}]}\n"
                . "- safe=true si aucun probleme detecte\n"
                . "- safe=false si au moins un probleme\n"
                . "- Ne signale PAS les faux positifs evidents (ex: cles dans .env.example, mots de passe de test)\n"
                . "Reponds UNIQUEMENT avec le JSON."
            );

            $clean = trim($response ?? '');
            if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
                $clean = $m[1];
            }
            if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
                $clean = $m[1];
            }

            $result = json_decode($clean, true);

            if (!$result) {
                $this->subAgent->appendLog("[SECURITY] Impossible de parser la reponse de l'analyse de securite");
                return;
            }

            if ($result['safe'] ?? true) {
                $this->subAgent->appendLog("[SECURITY] Aucun probleme de securite detecte");
                return;
            }

            $issues = $result['issues'] ?? [];
            $criticalIssues = [];
            $allIssueLines = [];

            foreach ($issues as $issue) {
                $severity = $issue['severity'] ?? 'warning';
                $file = $issue['file'] ?? '?';
                $desc = $issue['description'] ?? '?';
                $line = "[{$severity}] {$file}: {$desc}";
                $allIssueLines[] = $line;

                if ($severity === 'critical') {
                    $criticalIssues[] = "- {$file}: {$desc}";
                }
            }

            $logMessage = "[SECURITY] Problemes detectes:\n" . implode("\n", $allIssueLines);
            $this->subAgent->appendLog($logMessage);
            Log::warning("SubAgent #{$this->subAgent->id} security issues", ['issues' => $issues]);

            if (!empty($criticalIssues)) {
                $notification = "⚠️ Alerte securite :\n" . implode("\n", array_slice($criticalIssues, 0, 5))
                    . "\n\nLe code a ete pousse malgre tout. Verifie ces points.";
                $this->notifyRequester($notification);
            }
        } catch (\Throwable $e) {
            Log::warning("Security analysis failed: " . $e->getMessage());
            $this->subAgent->appendLog("[SECURITY] Erreur lors de l'analyse: " . $e->getMessage());
        }
    }

    private function notifyRequester(string $message): void
    {
        try {
            $phone = $this->subAgent->project->requester_phone;
            if (!$phone) return;

            $projectName = $this->subAgent->project->name;
            $prefixedMessage = "[{$projectName}] {$message}";

            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $phone,
                    'text' => $prefixedMessage,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to notify requester: " . $e->getMessage());
        }
    }

    /**
     * Sync any SelfImprovement linked to this SubAgent.
     */
    private function syncImprovementStatus(string $status): void
    {
        try {
            $improvement = SelfImprovement::where('sub_agent_id', $this->subAgent->id)->first();
            if ($improvement && $improvement->status !== $status) {
                $improvement->update(['status' => $status]);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to sync improvement status: " . $e->getMessage());
        }
    }
}
