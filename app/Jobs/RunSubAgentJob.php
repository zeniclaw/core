<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\SubAgent;
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
    public int $tries = 1;

    /**
     * Run SubAgent jobs one at a time (never in parallel).
     * If a job is already running, the next one waits up to 30 min.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('subagent-global'))
                ->releaseAfter(1800)
                ->expireAfter($this->timeout + 120),
        ];
    }

    /**
     * Dynamic timeout based on subAgent's timeout_minutes setting.
     */
    public function retryUntil(): \DateTime
    {
        $minutes = $this->subAgent->timeout_minutes ?: 10;
        return now()->addMinutes($minutes + 2); // +2 min grace for git ops
    }

    private string $workspace;

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
            $this->cloneRepo();
            $this->notifyRequester("Repo recupere. ZeniClaw AI analyse le code et applique les modifications...");

            // Step 2: Reuse existing branch or create new one
            $branchName = $this->resolveOrCreateBranch();
            $this->subAgent->update(['branch_name' => $branchName]);

            // Step 3: Run ZeniClaw AI with context from previous tasks
            $this->runClaudeCode();

            // Step 4: Commit and push
            $this->notifyRequester("Modifications terminees ! Je pousse le code...");
            $commitHash = $this->commitAndPush($branchName);
            $this->subAgent->update(['commit_hash' => $commitHash]);

            // Step 5: Create Merge Request (only if none exists for this branch)
            $mrUrl = $this->findOrCreateMergeRequest($branchName);

            $this->subAgent->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            $this->subAgent->project->update(['status' => 'completed']);
            $this->subAgent->appendLog("[DONE] Termine. Branche: {$branchName}, Commit: {$commitHash}");

            $message = "C'est fait ! Les modifications ont ete mergees.\n"
                . "Commit: {$commitHash}";
            if ($mrUrl) {
                $message .= "\nMerge Request: {$mrUrl}";
            }
            $this->notifyRequester($message);

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

    private function cloneRepo(): void
    {
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

        $prompt = "Tu travailles dans un repository git.";
        if ($previousTasks) {
            $prompt .= "\n\nModifications deja effectuees precedemment sur ce projet:\n";
            foreach ($previousTasks as $i => $prevTask) {
                $prompt .= ($i + 1) . ". {$prevTask}\n";
            }
            $prompt .= "\nCes modifs sont deja dans le code. Ne les refais pas.";
        }
        $prompt .= "\n\nNouvelle tache a realiser maintenant:\n{$task}\n\nApplique les modifications necessaires directement sur les fichiers du projet.";

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

        $process = Process::timeout($claudeTimeout)
            ->path($this->workspace)
            ->env([
                'ANTHROPIC_API_KEY' => $apiKey,
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
}
