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

    public int $timeout = 900;
    public int $tries = 1;

    private string $workdir = '/opt/zeniclaw-repo';

    public function __construct(
        public SelfImprovement $improvement,
        public SubAgent $subAgent,
        public string $agentSlug,
        public string $agentFile,
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

            $this->subAgent->appendLog("[START] Auto-improve agent: {$this->agentSlug}");

            // Ensure workdir is clean and up to date
            $this->subAgent->appendLog("[GIT] Preparing workdir...");
            $env = ['HOME' => '/tmp'];
            Process::env($env)->run('git config --global --add safe.directory ' . $this->workdir);
            Process::env($env)->path($this->workdir)->run('git fetch origin 2>&1');
            Process::env($env)->path($this->workdir)->run('git reset --hard origin/main 2>&1');
            Process::env($env)->path($this->workdir)->run('git clean -fd 2>&1');
            $this->subAgent->appendLog("[GIT] Synced to origin/main");

            $prompt = $this->buildPrompt();

            $this->subAgent->appendLog("[ZENICLAW AI] Lancement analyse + amelioration de {$this->agentSlug}...");
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
            $commitMsg = "feat(auto-improve): upgrade {$this->agentSlug} agent";

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
            Log::error("[AutoImproveAgent {$this->agentSlug}] Error: " . $e->getMessage());
            $this->markFailed($e->getMessage());
        }
    }

    private function buildPrompt(): string
    {
        $date = now()->format('Y-m-d');

        return <<<PROMPT
Tu es un expert en amelioration d'agents IA pour le projet ZeniClaw (Laravel 12 + PHP 8.4).

Ta mission: analyser et ameliorer le sub-agent "{$this->agentSlug}" situe dans {$this->agentFile}.

ETAPES A SUIVRE DANS L'ORDRE:

## 1. ANALYSE
- Lis le fichier source complet de l'agent
- Identifie toutes ses capacites actuelles (methodes, fonctionnalites, commandes supportees)
- Note les faiblesses, cas non geres, ameliorations possibles

## 2. AMELIORATION DES CAPACITES EXISTANTES
Pour chaque capacite identifiee:
- Ameliore la gestion d'erreurs
- Ameliore les prompts LLM (plus precis, meilleurs exemples)
- Ameliore le formatage des reponses (plus clair, plus lisible pour WhatsApp)
- Ajoute des cas limites non geres
- Optimise les requetes DB si applicable

## 3. CREATION DE NOUVELLES CAPACITES
- Ajoute au minimum 1-2 nouvelles fonctionnalites pertinentes pour cet agent
- Les nouvelles capacites doivent etre coherentes avec le role de l'agent
- Mets a jour le system prompt et les keywords si necessaire

## 4. TESTS
- Lance `php artisan test` et corrige toute erreur
- Si des tests existent pour cet agent, verifie qu'ils passent
- Lance `php artisan route:list` pour verifier que les routes sont OK
- Corrige tout probleme jusqu'a ce que tout soit stable

## 5. RAPPORT DE TEST
- Cree un fichier `test_{$this->agentSlug}_v<nouvelle_version>_{$date}.md` dans le meme dossier que l'agent (app/Services/Agents/)
- Le rapport doit contenir:
  - Resume des ameliorations apportees
  - Liste COMPLETE de toutes les capacites (existantes + nouvelles), chacune avec:
    - Description courte de la fonctionnalite
    - 1-2 exemples de messages WhatsApp que l'utilisateur peut envoyer pour declencher cette fonctionnalite
    - Exemple: "**Ajouter une tache** — `ajoute acheter du pain`, `nouvelle tache: finir le rapport`"
  - Resultats des tests (pass/fail)
  - Version precedente -> nouvelle version

## 6. BUMP DE VERSION
- Dans le fichier de l'agent, incremente la version mineure: si 1.X.0, passe a 1.(X+1).0
- La methode version() doit retourner la nouvelle version

REGLES IMPORTANTES:
- Ne modifie PAS les fichiers de migration existants
- Ne touche PAS au RouterAgent ni a l'AgentOrchestrator (sauf si absolument necessaire pour les keywords)
- Garde la compatibilite avec l'interface AgentInterface et BaseAgent
- Sois concis et efficace dans tes modifications
- Assure-toi que le code est propre et sans erreur de syntaxe
- Utilise les memes patterns que le code existant (AnthropicClient, sendText, etc.)
PROMPT;
    }

    private function executeClaudeCode(string $prompt, string $apiKey, int &$apiCalls): bool
    {
        $claudeTimeout = max(($this->subAgent->timeout_minutes ?: 15) * 60 - 60, 120);

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
        $this->subAgent->appendLog("[DONE] Auto-improve agent {$this->agentSlug} termine");

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
