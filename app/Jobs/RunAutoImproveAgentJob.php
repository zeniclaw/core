<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\SelfImprovement;
use App\Models\SubAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RunAutoImproveAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;          // Must be < worker --timeout (660s)
    public int $tries = 0;
    public int $maxExceptions = 2;
    public int $backoff = 10;

    private const MAX_TURNS_PER_RUN = 40;
    private const MAX_RUNS = 3;             // Max resume cycles
    private const CLAUDE_MODEL = 'opus';

    private string $workdir = '/opt/zeniclaw-repo';

    public int $runNumber = 1;
    public ?string $sessionId = null;

    public function __construct(
        public SelfImprovement $improvement,
        public SubAgent $subAgent,
        public string $agentSlug,
        public string $agentFile,
        int $runNumber = 1,
        ?string $sessionId = null,
    ) {
        $this->runNumber = $runNumber;
        $this->sessionId = $sessionId;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $apiKey = AppSetting::get('anthropic_api_key');
        if (!$apiKey) {
            $this->markFailed('Anthropic API key not configured');
            return;
        }

        try {
            // Cleanup before starting
            $this->cleanupOrphanedProcesses();

            $isResume = $this->runNumber > 1 && $this->sessionId;

            $this->subAgent->update([
                'status' => 'running',
                'started_at' => $this->subAgent->started_at ?? now(),
            ]);
            $this->improvement->update(['status' => 'in_progress']);

            if ($isResume) {
                $this->subAgent->appendLog("[RESUME] Run #{$this->runNumber} — reprise session {$this->sessionId}");
            } else {
                $this->subAgent->appendLog("[START] Auto-improve agent: {$this->agentSlug} (run #{$this->runNumber})");
                $this->prepareWorkdir();
            }

            // Build command
            $cmd = $this->buildClaudeCommand($isResume);

            $this->subAgent->appendLog("[CLAUDE CODE] Lancement " . self::CLAUDE_MODEL . " (max " . self::MAX_TURNS_PER_RUN . " turns)...");
            $apiCalls = $this->subAgent->api_calls_count ?? 0;
            $capturedSessionId = $this->sessionId;
            $hitMaxTurns = false;

            $success = $this->executeClaudeCode($cmd, $apiKey, $apiCalls, $capturedSessionId, $hitMaxTurns);
            $this->subAgent->update(['api_calls_count' => $apiCalls]);

            // If max turns reached and we haven't exceeded max runs, resume
            if ($hitMaxTurns && $this->runNumber < self::MAX_RUNS && $capturedSessionId) {
                $this->subAgent->appendLog("[PAUSE] Max turns atteint, re-dispatch pour continuer (run #{$this->runNumber} -> #{$nextRun})...");
                $nextRun = $this->runNumber + 1;

                // Dispatch continuation
                self::dispatch(
                    $this->improvement,
                    $this->subAgent,
                    $this->agentSlug,
                    $this->agentFile,
                    $nextRun,
                    $capturedSessionId,
                )->delay(now()->addSeconds(5));

                return;
            }

            if (!$success && !$hitMaxTurns) {
                $this->subAgent->appendLog("[ERROR] Claude Code a echoue");
                $this->markFailed('Claude Code a echoue');
                return;
            }

            // Git commit & push
            $this->commitAndPush();
            $this->markCompleted();

        } catch (\Exception $e) {
            Log::error("[AutoImproveAgent {$this->agentSlug}] Error: " . $e->getMessage());
            $this->markFailed($e->getMessage());
        }
    }

    private function prepareWorkdir(): void
    {
        $this->subAgent->appendLog("[GIT] Preparing workdir...");
        $env = ['HOME' => '/tmp'];
        Process::env($env)->run('git config --global --add safe.directory ' . $this->workdir);
        Process::env($env)->path($this->workdir)->run('git fetch origin 2>&1');
        Process::env($env)->path($this->workdir)->run('git reset --hard origin/main 2>&1');
        Process::env($env)->path($this->workdir)->run('git clean -fd 2>&1');
        $this->subAgent->appendLog("[GIT] Synced to origin/main");
    }

    private function buildClaudeCommand(bool $isResume): string
    {
        if ($isResume) {
            // Resume previous session
            return sprintf(
                'claude --resume %s --model %s --max-turns %d --dangerously-skip-permissions --verbose --output-format stream-json 2>&1',
                escapeshellarg($this->sessionId),
                self::CLAUDE_MODEL,
                self::MAX_TURNS_PER_RUN,
            );
        }

        // New session with prompt
        $prompt = $this->buildPrompt();
        return sprintf(
            'claude -p %s --model %s --max-turns %d --dangerously-skip-permissions --verbose --output-format stream-json 2>&1',
            escapeshellarg($prompt),
            self::CLAUDE_MODEL,
            self::MAX_TURNS_PER_RUN,
        );
    }

    private function buildPrompt(): string
    {
        $date = now()->format('Y-m-d');

        return <<<PROMPT
Tu es un expert en amelioration d'agents IA pour le projet ZeniClaw (Laravel 12 + PHP 8.4).

Mission: analyser et ameliorer le sub-agent "{$this->agentSlug}" dans {$this->agentFile}.

STRATEGIE DE TRAVAIL:
- Utilise l'outil Agent pour paralleliser les taches (analyse, recherche de patterns, etc.)
- Pour les gros fichiers (>500 lignes), utilise Grep pour trouver les sections pertinentes AVANT de lire
- Ne relis JAMAIS un fichier deja lu. Utilise offset+limit si necessaire.
- Fais des Edit cibles, jamais de Write pour reecrire un fichier entier.

ETAPES:

1. ANALYSE — Utilise Grep pour identifier la structure (methodes, version, keywords). Lis uniquement les sections cles.

2. AMELIORATIONS — Ameliore gestion d'erreurs, prompts LLM, formatage WhatsApp, cas limites. Ajoute 1-2 nouvelles fonctionnalites coherentes.

3. TESTS — Lance `php artisan test` UNE SEULE FOIS. Les echecs pre-existants sont normaux, ignore-les. Verifie syntaxe avec `php -l`.

4. RAPPORT — Cree `app/Services/Agents/test_{$this->agentSlug}_v<version>_{$date}.md` avec: resume, liste capacites avec exemples WhatsApp, resultats tests.

5. VERSION — Incremente version mineure dans la methode version().

REGLES:
- Ne modifie PAS les migrations, RouterAgent, AgentOrchestrator
- Garde compatibilite BaseAgent/AgentInterface
- Patterns existants: AnthropicClient, sendText, etc.
- Sois efficace, pas de boucles inutiles
PROMPT;
    }

    private function executeClaudeCode(string $cmd, string $apiKey, int &$apiCalls, ?string &$sessionId, bool &$hitMaxTurns): bool
    {
        $claudeTimeout = max(min(($this->subAgent->timeout_minutes ?: 10) * 60 - 60, 500), 120);

        $envKey = str_starts_with($apiKey, 'sk-ant-oat')
            ? 'CLAUDE_CODE_OAUTH_TOKEN'
            : 'ANTHROPIC_API_KEY';

        $process = Process::timeout($claudeTimeout)
            ->path($this->workdir)
            ->env([
                $envKey => $apiKey,
                'HOME' => '/tmp',
            ])
            ->start($cmd);

        $pid = $process->id();
        if ($pid) {
            $this->subAgent->update(['pid' => $pid]);
        }

        while ($process->running()) {
            $this->subAgent->refresh();
            if ($this->subAgent->status === 'killed') {
                $this->subAgent->appendLog("[KILL] Arret demande par l'admin");
                if ($pid) {
                    Process::run("kill -TERM -{$pid} 2>/dev/null; kill -TERM {$pid} 2>/dev/null");
                }
                throw new \RuntimeException('SubAgent arrete par l\'admin');
            }

            $this->consumeOutput($process, $apiCalls, $sessionId, $hitMaxTurns);
            usleep(500_000);
        }

        // Consume remaining output
        $result = $process->wait();
        $remaining = $result->output();
        if ($remaining) {
            foreach (explode("\n", trim($remaining)) as $line) {
                $line = trim($line);
                if (!$line) continue;
                $event = json_decode($line, true);
                if ($event) {
                    $this->processStreamEvent($event, $apiCalls, $sessionId, $hitMaxTurns);
                } else {
                    $this->subAgent->appendLog($line);
                }
            }
        }

        return $result->successful();
    }

    private function consumeOutput($process, int &$apiCalls, ?string &$sessionId, bool &$hitMaxTurns): void
    {
        $newOutput = $process->latestOutput();
        if ($newOutput) {
            foreach (explode("\n", trim($newOutput)) as $line) {
                $line = trim($line);
                if (!$line) continue;
                $event = json_decode($line, true);
                if ($event) {
                    $this->processStreamEvent($event, $apiCalls, $sessionId, $hitMaxTurns);
                } else {
                    $this->subAgent->appendLog($line);
                }
            }
        }

        $newError = $process->latestErrorOutput();
        if ($newError) {
            foreach (explode("\n", trim($newError)) as $errLine) {
                if (trim($errLine)) {
                    $this->subAgent->appendLog("[STDERR] " . trim($errLine));
                }
            }
        }
    }

    private function processStreamEvent(array $event, int &$apiCalls, ?string &$sessionId, bool &$hitMaxTurns): void
    {
        $type = $event['type'] ?? '';

        switch ($type) {
            case 'system':
                $subtype = $event['subtype'] ?? '';
                if ($subtype === 'init') {
                    $this->subAgent->appendLog("[CLAUDE CODE] Modele: " . ($event['model'] ?? 'unknown'));
                    // Capture session ID for resume
                    if (!empty($event['session_id'])) {
                        $sessionId = $event['session_id'];
                        $this->subAgent->appendLog("[SESSION] ID: {$sessionId}");
                    }
                } elseif ($subtype === 'max_turns_reached') {
                    $hitMaxTurns = true;
                    $this->subAgent->appendLog("[MAX TURNS] Limite atteinte, pause...");
                }
                break;

            case 'assistant':
                $apiCalls++;
                foreach ($event['message']['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                        $text = mb_substr($block['text'], 0, 300);
                        if (strlen($block['text']) > 300) $text .= '...';
                        $this->subAgent->appendLog("[CLAUDE] " . $text);
                    } elseif (($block['type'] ?? '') === 'tool_use') {
                        $tool = $block['name'] ?? '?';
                        $input = $block['input'] ?? [];
                        $desc = match ($tool) {
                            'Read' => "Lecture: " . ($input['file_path'] ?? '?'),
                            'Edit' => "Edition: " . ($input['file_path'] ?? '?'),
                            'Write' => "Ecriture: " . ($input['file_path'] ?? '?'),
                            'Bash' => "Commande: " . mb_substr($input['command'] ?? '?', 0, 100),
                            'Glob' => "Recherche fichiers: " . ($input['pattern'] ?? '?'),
                            'Grep' => "Recherche code: " . ($input['pattern'] ?? '?'),
                            'Agent' => "Sub-agent: " . mb_substr($input['prompt'] ?? $input['description'] ?? '?', 0, 100),
                            default => "Outil: {$tool}",
                        };
                        $this->subAgent->appendLog("[TOOL] {$desc}");
                    }
                }
                break;

            case 'result':
                $resultText = $event['result'] ?? '';
                if ($resultText) {
                    $this->subAgent->appendLog("[RESULT] " . mb_substr($resultText, 0, 500));
                }
                // Check for max_turns in result
                if (str_contains($resultText, 'max_turns') || str_contains($resultText, 'Hit max turns')) {
                    $hitMaxTurns = true;
                }
                break;
        }
    }

    private function commitAndPush(): void
    {
        $statusResult = Process::path($this->workdir)->run('git status --porcelain');
        $changes = trim($statusResult->output());

        if (empty($changes)) {
            $this->subAgent->appendLog("[GIT] Aucune modification");
            return;
        }

        $this->subAgent->appendLog("[GIT] Commit des modifications...");
        $commitMsg = "feat(auto-improve): upgrade {$this->agentSlug} agent";
        $env = ['HOME' => '/tmp'];

        Process::env($env)->path($this->workdir)->run('git add -A');
        $commitResult = Process::env($env)->path($this->workdir)->run(
            sprintf('git commit -m %s', escapeshellarg($commitMsg))
        );

        if (!$commitResult->successful()) {
            $this->subAgent->appendLog("[ERROR] Git commit failed: " . $commitResult->errorOutput());
            return;
        }

        // Pull rebase to integrate any remote changes before push
        $this->subAgent->appendLog("[GIT] Pull rebase...");
        $pullResult = Process::env($env)->path($this->workdir)->run('git pull --rebase origin main 2>&1');
        if (!$pullResult->successful()) {
            $this->subAgent->appendLog("[WARN] Git pull rebase failed: " . $pullResult->output());
            // Abort rebase if stuck
            Process::env($env)->path($this->workdir)->run('git rebase --abort 2>/dev/null');
        }

        $this->subAgent->appendLog("[GIT] Push...");
        $pushResult = Process::env($env)->path($this->workdir)->run('git push origin main 2>&1');

        if (!$pushResult->successful()) {
            $this->subAgent->appendLog("[WARN] Git push failed: " . $pushResult->errorOutput());
        } else {
            $this->subAgent->appendLog("[GIT] Push OK");
        }
    }

    private function cleanupOrphanedProcesses(): void
    {
        // Kill any running Claude processes in the workdir
        $result = Process::run("pgrep -f 'claude.*zeniclaw-repo' 2>/dev/null");
        $pids = array_filter(explode("\n", trim($result->output())));
        if (!empty($pids)) {
            $this->subAgent->appendLog("[CLEANUP] Killing " . count($pids) . " orphaned Claude process(es)");
            foreach ($pids as $pid) {
                Process::run("kill -TERM {$pid} 2>/dev/null");
            }
            sleep(2);
            foreach ($pids as $pid) {
                Process::run("kill -9 {$pid} 2>/dev/null");
            }
        }

        // Mark stuck "running" subagents with dead PIDs as failed
        $stuck = SubAgent::where('status', 'running')
            ->where('id', '!=', $this->subAgent->id)
            ->whereNotNull('pid')
            ->get();

        foreach ($stuck as $sa) {
            $checkPid = Process::run("ps -p {$sa->pid} -o pid= 2>/dev/null");
            if (empty(trim($checkPid->output()))) {
                $sa->update([
                    'status' => 'failed',
                    'error_message' => 'Process orphelin nettoyé',
                    'completed_at' => now(),
                    'pid' => null,
                ]);
                $this->subAgent->appendLog("[CLEANUP] SubAgent {$sa->id} marqué failed (process mort)");
            }
        }
    }

    private function markCompleted(): void
    {
        $this->subAgent->update([
            'status' => 'completed',
            'completed_at' => now(),
            'pid' => null,
        ]);
        $this->improvement->update(['status' => 'completed']);
        $this->subAgent->appendLog("[DONE] Auto-improve agent {$this->agentSlug} termine (run #{$this->runNumber})");

        $this->dispatchNext();
    }

    private function dispatchNext(): void
    {
        if (AppSetting::get('auto_improve_agents_enabled') !== 'true') {
            Log::info('AutoImproveAgent: auto-improve disabled, not dispatching next');
            return;
        }

        Log::info('AutoImproveAgent: dispatching next agent improvement');
        Artisan::call('zeniclaw:auto-improve-agents');
    }

    private function markFailed(string $reason): void
    {
        $this->subAgent->update([
            'status' => 'failed',
            'error_message' => $reason,
            'completed_at' => now(),
            'pid' => null,
        ]);
        $this->improvement->update(['status' => 'failed']);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->improvement->update(['status' => 'failed']);
        $this->subAgent->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage() ?? 'Unknown error',
            'completed_at' => now(),
            'pid' => null,
        ]);
    }
}
