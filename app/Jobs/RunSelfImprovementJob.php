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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RunSelfImprovementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    private string $workdir = '/opt/zeniclaw-repo';

    public function __construct(
        public SelfImprovement $improvement,
        public SubAgent $subAgent,
    ) {
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
            $this->subAgent->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
            $this->improvement->update(['status' => 'in_progress']);

            $this->subAgent->appendLog("[START] Auto-amelioration #{$this->improvement->id}: {$this->improvement->improvement_title}");

            // Build the prompt
            $plan = $this->improvement->admin_notes
                ? $this->improvement->admin_notes . "\n\n---\nPlan original:\n" . $this->improvement->development_plan
                : $this->improvement->development_plan;

            $prompt = "Tu travailles sur le projet ZeniClaw (Laravel). "
                . "Applique l'amelioration suivante directement sur les fichiers du projet.\n\n"
                . "Titre: {$this->improvement->improvement_title}\n\n"
                . "Analyse du probleme:\n" . ($this->improvement->analysis['analysis'] ?? '') . "\n\n"
                . "Plan de developpement:\n{$plan}\n\n"
                . "Message declencheur: {$this->improvement->trigger_message}\n"
                . "Reponse de l'agent: " . mb_substr($this->improvement->agent_response, 0, 500) . "\n\n"
                . "INSTRUCTIONS:\n"
                . "- Applique les modifications necessaires directement sur les fichiers\n"
                . "- Ne cree PAS de tests sauf si le plan le demande explicitement\n"
                . "- Sois concis et efficace\n"
                . "- Ne touche pas aux fichiers de migration existants";

            // Run Claude Code with streaming
            $this->subAgent->appendLog("[ZENICLAW AI] Lancement sur /home/ubuntu/zeniclaw/...");
            $apiCalls = 0;
            $success = $this->executeClaudeCode($prompt, $apiKey, $apiCalls);
            $this->subAgent->update(['api_calls_count' => $apiCalls]);

            if (!$success) {
                $this->subAgent->appendLog("[ERROR] Claude Code a echoue");
                $this->markFailed('Claude Code a echoue');
                return;
            }

            // Check for changes
            $statusResult = Process::path($this->workdir)->run('git status --porcelain');
            $changes = trim($statusResult->output());

            if (empty($changes)) {
                $this->subAgent->appendLog("[DONE] Aucune modification necessaire");
                $this->markCompleted();
                return;
            }

            // Commit and push
            $this->subAgent->appendLog("[GIT] Commit des modifications...");
            $commitMsg = "feat(auto-improve): {$this->improvement->improvement_title}";

            Process::path($this->workdir)->run('git add -A');
            $commitResult = Process::path($this->workdir)->run(
                sprintf('git commit -m %s', escapeshellarg($commitMsg))
            );

            if (!$commitResult->successful()) {
                $this->subAgent->appendLog("[ERROR] Git commit failed: " . $commitResult->errorOutput());
                $this->markFailed('Git commit failed: ' . $commitResult->errorOutput());
                return;
            }

            $this->subAgent->appendLog("[GIT] Push...");
            $pushResult = Process::path($this->workdir)->run('git push');

            if (!$pushResult->successful()) {
                $this->subAgent->appendLog("[WARN] Git push failed: " . $pushResult->errorOutput());
            } else {
                $this->subAgent->appendLog("[GIT] Push OK");
            }

            $this->markCompleted();

        } catch (\Exception $e) {
            Log::error("[SelfImprovement #{$this->improvement->id}] Error: " . $e->getMessage());
            $this->markFailed($e->getMessage());
        }
    }

    private function executeClaudeCode(string $prompt, string $apiKey, int &$apiCalls): bool
    {
        $claudeTimeout = max(($this->subAgent->timeout_minutes ?: 10) * 60 - 60, 120);

        $cmd = sprintf(
            'claude -p %s --model sonnet --dangerously-skip-permissions --verbose --output-format stream-json 2>&1',
            escapeshellarg($prompt)
        );

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

            $this->consumeOutput($process, $apiCalls);
            usleep(500_000);
        }

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

    private function consumeOutput($process, int &$apiCalls): void
    {
        $newOutput = $process->latestOutput();
        if ($newOutput) {
            foreach (explode("\n", trim($newOutput)) as $line) {
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

        $newError = $process->latestErrorOutput();
        if ($newError) {
            foreach (explode("\n", trim($newError)) as $errLine) {
                if (trim($errLine)) {
                    $this->subAgent->appendLog("[STDERR] " . trim($errLine));
                }
            }
        }
    }

    private function processStreamEvent(array $event, int &$apiCalls): void
    {
        $type = $event['type'] ?? '';

        switch ($type) {
            case 'system':
                if (($event['subtype'] ?? '') === 'init') {
                    $this->subAgent->appendLog("[ZENICLAW AI] Modele: " . ($event['model'] ?? 'unknown'));
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
                break;
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
        $this->subAgent->appendLog("[DONE] Auto-amelioration terminee");
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
