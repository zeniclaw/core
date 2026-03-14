<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Models\AppSetting;
use App\Models\SelfImprovement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContinuousImprovementService
{
    /**
     * Analyze recent agent logs and identify improvement opportunities.
     * Returns an array of improvement suggestions with context.
     */
    public function analyzeLogsForImprovements(int $hoursBack = 24, int $maxSuggestions = 5): array
    {
        $since = now()->subHours($hoursBack);

        // Gather error/warning logs
        $errorLogs = AgentLog::where('created_at', '>=', $since)
            ->whereIn('level', ['error', 'warning'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // Gather all logs for pattern analysis
        $allLogs = AgentLog::where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        if ($allLogs->isEmpty()) {
            return [];
        }

        // Build analysis context
        $errorSummary = $this->summarizeErrors($errorLogs);
        $patternSummary = $this->detectPatterns($allLogs);
        $agentPerformance = $this->getAgentPerformance($since);

        // Use Claude to analyze and suggest improvements
        $client = new AnthropicClient();
        $prompt = $this->buildAnalysisPrompt($errorSummary, $patternSummary, $agentPerformance);

        $response = $client->chat(
            $prompt,
            ModelResolver::balanced(),
            $this->getSystemPrompt(),
            8192
        );

        if (!$response) {
            Log::warning('[ContinuousImprovement] Claude analysis returned null');
            return [];
        }

        return $this->parseImprovements($response, $maxSuggestions);
    }

    /**
     * Execute a single improvement: generate code, commit, bump version.
     */
    public function executeImprovement(array $improvement, string $apiKey): array
    {
        $workdir = '/opt/zeniclaw-repo';

        // Read current version
        $currentVersion = $this->getCurrentVersion($workdir);
        $newVersion = $this->incrementPatchVersion($currentVersion);

        $prompt = $this->buildExecutionPrompt($improvement, $currentVersion, $newVersion);

        // Run Claude Code
        $result = $this->runClaudeCode($prompt, $apiKey, $workdir, $newVersion);

        return array_merge($result, [
            'previous_version' => $currentVersion,
            'new_version' => $newVersion,
            'improvement' => $improvement,
        ]);
    }

    private function summarizeErrors(\Illuminate\Support\Collection $errors): string
    {
        if ($errors->isEmpty()) {
            return "Aucune erreur detectee dans les dernieres 24h.";
        }

        $grouped = $errors->groupBy(function ($log) {
            // Group by agent name (extract from message)
            preg_match('/^\[([^\]]+)\]/', $log->message, $m);
            return $m[1] ?? 'unknown';
        });

        $lines = ["## Erreurs/Warnings par agent ({$errors->count()} total):"];
        foreach ($grouped as $agent => $logs) {
            $lines[] = "\n### {$agent} ({$logs->count()} erreurs)";
            foreach ($logs->take(5) as $log) {
                $msg = mb_substr($log->message, 0, 200);
                $ctx = $log->context ? json_encode($log->context, JSON_UNESCAPED_UNICODE) : '';
                $ctx = $ctx ? ' | ctx: ' . mb_substr($ctx, 0, 150) : '';
                $lines[] = "- [{$log->level}] {$msg}{$ctx}";
            }
            if ($logs->count() > 5) {
                $lines[] = "  ... et " . ($logs->count() - 5) . " autres";
            }
        }

        return implode("\n", $lines);
    }

    private function detectPatterns(\Illuminate\Support\Collection $logs): string
    {
        $lines = ["## Patterns detectes:"];

        // Most active agents
        $agentActivity = $logs->groupBy(function ($log) {
            preg_match('/^\[([^\]]+)\]/', $log->message, $m);
            return $m[1] ?? 'unknown';
        })->map->count()->sortDesc()->take(10);

        $lines[] = "\n### Agents les plus actifs:";
        foreach ($agentActivity as $agent => $count) {
            $lines[] = "- {$agent}: {$count} logs";
        }

        // Repeated error patterns
        $errorPatterns = $logs->where('level', 'error')
            ->groupBy(function ($log) {
                // Normalize message for grouping
                $msg = preg_replace('/[0-9]+/', 'N', $log->message);
                $msg = preg_replace('/\b[a-f0-9]{8,}\b/', 'HASH', $msg);
                return mb_substr($msg, 0, 100);
            })
            ->filter(fn ($group) => $group->count() >= 2)
            ->sortByDesc(fn ($group) => $group->count())
            ->take(5);

        if ($errorPatterns->isNotEmpty()) {
            $lines[] = "\n### Erreurs recurrentes:";
            foreach ($errorPatterns as $pattern => $group) {
                $lines[] = "- ({$group->count()}x) {$pattern}";
            }
        }

        // Tool usage patterns (from context)
        $toolUsage = $logs->filter(fn ($l) => isset($l->context['tool']))
            ->groupBy(fn ($l) => $l->context['tool'])
            ->map->count()
            ->sortDesc()
            ->take(10);

        if ($toolUsage->isNotEmpty()) {
            $lines[] = "\n### Outils les plus utilises:";
            foreach ($toolUsage as $tool => $count) {
                $lines[] = "- {$tool}: {$count} utilisations";
            }
        }

        return implode("\n", $lines);
    }

    private function getAgentPerformance(\Carbon\Carbon $since): string
    {
        $lines = ["## Performance des agents:"];

        // Average response time from SubAgent records
        $subAgentStats = DB::table('sub_agents')
            ->where('created_at', '>=', $since)
            ->whereNotNull('completed_at')
            ->selectRaw("
                spawning_agent,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(EXTRACT(EPOCH FROM (completed_at - started_at))) as avg_duration_sec
            ")
            ->groupBy('spawning_agent')
            ->get();

        foreach ($subAgentStats as $stat) {
            $agent = $stat->spawning_agent ?: 'direct';
            $rate = $stat->total > 0 ? round(($stat->success / $stat->total) * 100) : 0;
            $avgDur = $stat->avg_duration_sec ? round($stat->avg_duration_sec, 1) : 'n/a';
            $lines[] = "- {$agent}: {$stat->total} taches, {$rate}% succes, ~{$avgDur}s moy.";
        }

        // Memory usage stats
        $memoryCount = DB::table('conversation_memories')
            ->where('created_at', '>=', $since)
            ->count();
        $lines[] = "\n### Memoire: {$memoryCount} faits sauvegardes en 24h";

        return implode("\n", $lines);
    }

    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en amelioration continue pour ZeniClaw, un assistant IA multi-agents (Laravel 12 + PHP 8.4).

Tu analyses les logs d'utilisation des agents et identifies des ameliorations concretes et implementables.

Pour chaque amelioration, tu fournis:
1. Un titre court et clair
2. Le fichier principal a modifier (chemin relatif)
3. Une description detaillee du probleme identifie
4. Un plan d'implementation precis (quoi modifier, ou, comment)
5. La priorite (high, medium, low)
6. La categorie (bug_fix, performance, ux, new_feature, reliability, security)

REGLES:
- Ne propose que des ameliorations CONCRETES et IMPLEMENTABLES en un seul commit
- Chaque amelioration doit etre independante et atomique
- Privilege les corrections de bugs et les ameliorations UX
- Ne propose PAS de changements de migration DB (risque de perte de donnees)
- Ne propose PAS de restructurations majeures (un seul commit = un petit changement)
- Formatte ta reponse en JSON valide

Reponds UNIQUEMENT avec un JSON array:
[
  {
    "title": "...",
    "file": "app/Services/Agents/ChatAgent.php",
    "problem": "...",
    "plan": "...",
    "priority": "high|medium|low",
    "category": "bug_fix|performance|ux|new_feature|reliability|security"
  }
]
PROMPT;
    }

    private function buildAnalysisPrompt(string $errors, string $patterns, string $performance): string
    {
        return <<<PROMPT
Analyse ces logs ZeniClaw des dernieres 24h et propose des ameliorations concretes.

{$errors}

{$patterns}

{$performance}

Propose jusqu'a 5 ameliorations, classees par priorite. Chaque amelioration doit etre un petit changement implementable en un seul commit.
PROMPT;
    }

    private function parseImprovements(string $response, int $max): array
    {
        // Extract JSON from response (may be wrapped in markdown code block)
        $json = $response;
        if (preg_match('/```(?:json)?\s*(\[[\s\S]*?\])\s*```/', $response, $m)) {
            $json = $m[1];
        } elseif (preg_match('/(\[[\s\S]*\])/', $response, $m)) {
            $json = $m[1];
        }

        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            Log::warning('[ContinuousImprovement] Failed to parse Claude response as JSON');
            return [];
        }

        // Validate and limit
        $improvements = [];
        foreach (array_slice($parsed, 0, $max) as $item) {
            if (empty($item['title']) || empty($item['plan'])) continue;
            $improvements[] = [
                'title' => $item['title'],
                'file' => $item['file'] ?? 'unknown',
                'problem' => $item['problem'] ?? '',
                'plan' => $item['plan'],
                'priority' => $item['priority'] ?? 'medium',
                'category' => $item['category'] ?? 'ux',
            ];
        }

        return $improvements;
    }

    private function buildExecutionPrompt(array $improvement, string $currentVersion, string $newVersion): string
    {
        $title = $improvement['title'];
        $file = $improvement['file'];
        $problem = $improvement['problem'];
        $plan = $improvement['plan'];
        $category = $improvement['category'];

        return <<<PROMPT
Tu es un developpeur expert travaillant sur ZeniClaw (Laravel 12 + PHP 8.4).

## Amelioration a implementer
- **Titre**: {$title}
- **Fichier principal**: {$file}
- **Categorie**: {$category}
- **Probleme**: {$problem}
- **Plan**: {$plan}

## Instructions
1. Lis le fichier concerne et comprends le code existant
2. Implemente l'amelioration de maniere minimale et propre
3. Verifie la syntaxe PHP avec `php -l` sur chaque fichier modifie
4. Met a jour la version dans Dockerfile: remplace "{$currentVersion}" par "{$newVersion}" dans la ligne `echo "..." > storage/app/version.txt`
5. NE FAIS PAS de git commit/push — je le ferai moi-meme

REGLES:
- Change le minimum necessaire
- Garde la compatibilite avec le code existant
- Pas de nouvelles migrations
- Pas de modification des routes existantes (sauf ajout)
- Pas de suppression de fonctionnalites existantes
- Syntaxe PHP 8.4 valide
PROMPT;
    }

    public function getCurrentVersion(string $workdir): string
    {
        $dockerfile = $workdir . '/Dockerfile';
        if (!file_exists($dockerfile)) {
            return '2.36.0';
        }
        $content = file_get_contents($dockerfile);
        if (preg_match('/echo\s+"([^"]+)"\s+>\s+storage\/app\/version\.txt/', $content, $m)) {
            return $m[1];
        }
        return '2.36.0';
    }

    public function incrementPatchVersion(string $version): string
    {
        $parts = explode('.', $version);
        if (count($parts) !== 3) {
            return $version . '.1';
        }
        $parts[2] = (int) $parts[2] + 1;
        return implode('.', $parts);
    }

    private function runClaudeCode(string $prompt, string $apiKey, string $workdir, string $newVersion): array
    {
        $envKey = str_starts_with($apiKey, 'sk-ant-oat')
            ? 'CLAUDE_CODE_OAUTH_TOKEN'
            : 'ANTHROPIC_API_KEY';

        $cmd = sprintf(
            'claude -p %s --model sonnet --dangerously-skip-permissions --verbose --output-format stream-json 2>&1',
            escapeshellarg($prompt)
        );

        $process = \Illuminate\Support\Facades\Process::timeout(300)
            ->path($workdir)
            ->env([
                $envKey => $apiKey,
                'HOME' => '/tmp',
            ])
            ->run($cmd);

        $output = $process->output();
        $success = $process->successful();

        // Parse stream events for summary
        $summary = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (!$line) continue;
            $event = json_decode($line, true);
            if ($event && ($event['type'] ?? '') === 'result') {
                $summary[] = $event['result'] ?? '';
            }
        }

        return [
            'success' => $success,
            'output_summary' => implode("\n", $summary) ?: mb_substr($output, -500),
            'exit_code' => $process->exitCode(),
        ];
    }

    /**
     * Get the current mode: 'off', 'once', 'continuous'
     */
    public static function getMode(): string
    {
        return AppSetting::get('continuous_improve_mode') ?: 'off';
    }

    /**
     * Set the mode: 'off', 'once', 'continuous'
     */
    public static function setMode(string $mode): void
    {
        AppSetting::set('continuous_improve_mode', $mode);
    }

    /**
     * Check if service should run.
     */
    public static function shouldRun(): bool
    {
        $mode = self::getMode();
        if ($mode === 'off') return false;
        if ($mode === 'once') {
            // Check if already ran this cycle
            $lastRun = AppSetting::get('continuous_improve_last_run');
            if ($lastRun && now()->diffInMinutes(\Carbon\Carbon::parse($lastRun)) < 30) {
                return false;
            }
        }
        return true;
    }

    /**
     * Record that a run completed (for one-shot mode).
     */
    public static function recordRun(): void
    {
        AppSetting::set('continuous_improve_last_run', now()->toIso8601String());
        if (self::getMode() === 'once') {
            self::setMode('off');
        }
    }
}
