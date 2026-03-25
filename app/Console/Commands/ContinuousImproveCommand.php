<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SelfImprovement;
use App\Models\SubAgent;
use App\Services\ContinuousImprovementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ContinuousImproveCommand extends Command
{
    protected $signature = 'zeniclaw:continuous-improve {--once : Run once regardless of mode}';
    protected $description = 'Analyze user logs and auto-improve ZeniClaw with version-bumped commits';

    private string $workdir = '/opt/zeniclaw-repo';

    public function handle(): int
    {
        $service = new ContinuousImprovementService();
        $mode = ContinuousImprovementService::getMode();

        if (!$this->option('once') && !ContinuousImprovementService::shouldRun()) {
            $this->info("Continuous improvement is {$mode}. Skipping.");
            return 0;
        }

        $apiKey = AppSetting::get('anthropic_api_key');
        if (!$apiKey) {
            $this->error('No Anthropic API key configured.');
            return 1;
        }

        $this->info('Analyzing logs for improvements...');
        Log::info('[ContinuousImprove] Starting log analysis');

        $improvements = $service->analyzeLogsForImprovements(hoursBack: 24, maxSuggestions: 3);

        if (empty($improvements)) {
            $this->info('No improvements identified. System looks good!');
            ContinuousImprovementService::recordRun();
            return 0;
        }

        $this->info(count($improvements) . ' improvement(s) identified.');

        // Ensure workdir is ready
        if (!$this->prepareWorkdir()) {
            $this->error('Failed to prepare workdir.');
            return 1;
        }

        $project = $this->getOrCreateProject();
        $defaultTimeout = (int) (AppSetting::get('subagent_default_timeout') ?: 10);

        $applied = 0;
        foreach ($improvements as $i => $improvement) {
            $num = $i + 1;
            $total = count($improvements);
            $this->info("[{$num}/{$total}] {$improvement['title']} ({$improvement['priority']})");

            // Create SubAgent for visibility on /subagents page
            $subAgent = SubAgent::create([
                'project_id' => $project->id,
                'type' => 'continuous_improve',
                'requester_phone' => 'system',
                'spawning_agent' => 'continuous_improve',
                'status' => 'running',
                'task_description' => "[Auto] {$improvement['title']}\n\n{$improvement['plan']}",
                'timeout_minutes' => $defaultTimeout,
                'started_at' => now(),
            ]);

            // Create SelfImprovement record linked to SubAgent
            $record = SelfImprovement::create([
                'agent_id' => Agent::first()?->id ?? 1,
                'trigger_message' => 'Continuous improvement — log analysis',
                'agent_response' => $improvement['problem'],
                'routed_agent' => 'continuous_improve',
                'analysis' => [
                    'improve' => true,
                    'new_capability' => $improvement['category'] === 'new_feature',
                    'title' => $improvement['title'],
                    'analysis' => $improvement['problem'],
                    'plan' => $improvement['plan'],
                ],
                'improvement_title' => $improvement['title'],
                'development_plan' => $improvement['plan'],
                'status' => 'in_progress',
                'sub_agent_id' => $subAgent->id,
            ]);

            // Execute the improvement with SubAgent logging
            $result = $service->executeImprovement($improvement, $apiKey, $subAgent);

            if (!$result['success']) {
                $this->warn("  Failed: " . mb_substr($result['output_summary'] ?? 'unknown error', 0, 200));
                $subAgent->update(['status' => 'failed', 'error_message' => $result['output_summary'], 'completed_at' => now(), 'pid' => null]);
                $record->update(['status' => 'failed']);
                Log::warning('[ContinuousImprove] Improvement failed', [
                    'title' => $improvement['title'],
                    'subagent_id' => $subAgent->id,
                ]);
                continue;
            }

            // Check for actual changes
            $statusResult = Process::path($this->workdir)->run('git status --porcelain');
            $changes = trim($statusResult->output());

            if (empty($changes)) {
                $this->info("  No code changes produced. Skipping commit.");
                $subAgent->appendLog("[DONE] Aucune modification necessaire");
                $subAgent->update(['status' => 'completed', 'completed_at' => now(), 'pid' => null]);
                $record->update(['status' => 'completed', 'admin_notes' => 'No changes needed']);
                continue;
            }

            // Commit with version bump
            $newVersion = $result['new_version'];
            $commitMsg = "fix(auto): {$improvement['title']} (v{$newVersion})";

            if ($improvement['category'] === 'new_feature') {
                $commitMsg = "feat(auto): {$improvement['title']} (v{$newVersion})";
            }

            $subAgent->appendLog("[GIT] Commit: {$commitMsg}");
            Process::path($this->workdir)->run('git add -A');
            $commitResult = Process::path($this->workdir)->run(
                sprintf('git commit -m %s', escapeshellarg($commitMsg))
            );

            if (!$commitResult->successful()) {
                $errMsg = $commitResult->errorOutput();
                $this->warn("  Git commit failed: " . $errMsg);
                $subAgent->appendLog("[ERROR] Git commit failed: " . $errMsg);
                $subAgent->update(['status' => 'failed', 'error_message' => 'Git commit failed', 'completed_at' => now(), 'pid' => null]);
                $record->update(['status' => 'failed']);
                continue;
            }

            // Tag
            Process::path($this->workdir)->run(
                sprintf('git tag %s', escapeshellarg("v{$newVersion}"))
            );

            // Push
            $subAgent->appendLog("[GIT] Push v{$newVersion}...");
            $pushResult = Process::path($this->workdir)->run('git push origin main --tags 2>&1');
            if (!$pushResult->successful()) {
                $subAgent->appendLog("[WARN] Push failed: " . $pushResult->errorOutput());
                $this->warn("  Git push failed: " . $pushResult->errorOutput());
            } else {
                $subAgent->appendLog("[GIT] Push OK — v{$newVersion}");
                $this->info("  Committed and pushed v{$newVersion}");
            }

            $subAgent->update([
                'status' => 'completed',
                'completed_at' => now(),
                'commit_hash' => trim(Process::path($this->workdir)->run('git rev-parse HEAD')->output()),
                'result' => "v{$newVersion}: {$improvement['title']}",
                'pid' => null,
            ]);
            $subAgent->updateProgress(100, "v{$newVersion} deploye");

            $record->update([
                'status' => 'completed',
                'admin_notes' => "Auto-applied v{$newVersion}. Changes: " . mb_substr($changes, 0, 500),
            ]);

            $applied++;
            Log::info("[ContinuousImprove] Applied improvement", [
                'title' => $improvement['title'],
                'version' => $newVersion,
                'subagent_id' => $subAgent->id,
            ]);
        }

        ContinuousImprovementService::recordRun();
        $this->info("Done. {$applied}/" . count($improvements) . " improvements applied.");

        return 0;
    }

    private function prepareWorkdir(): bool
    {
        $env = ['HOME' => '/tmp'];

        if (!is_dir($this->workdir)) {
            $this->info("Cloning repo to {$this->workdir}...");
            $gitlabUrl = AppSetting::get('gitlab_url') ?: 'https://github.com/zeniclaw/core.git';
            $result = Process::env($env)->timeout(120)->run(
                sprintf('git clone %s %s 2>&1', escapeshellarg($gitlabUrl), escapeshellarg($this->workdir))
            );
            if (!$result->successful()) {
                Log::error('[ContinuousImprove] Git clone failed: ' . $result->errorOutput());
                return false;
            }
        }

        Process::env($env)->run('git config --global --add safe.directory ' . $this->workdir);
        Process::env($env)->path($this->workdir)->run('git fetch origin 2>&1');
        Process::env($env)->path($this->workdir)->run('git reset --hard origin/main 2>&1');
        Process::env($env)->path($this->workdir)->run('git clean -fd 2>&1');

        return true;
    }

    private function getOrCreateProject(): Project
    {
        $projectId = AppSetting::get('zeniclaw_project_id');
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) return $project;
        }

        $project = Project::where('name', 'ZeniClaw (Auto-Improve)')->first();
        if ($project) return $project;

        $project = Project::create([
            'name' => 'ZeniClaw (Auto-Improve)',
            'gitlab_url' => 'https://github.com/zeniclaw/core.git',
            'request_description' => 'Projet auto-genere pour les ameliorations continues.',
            'requester_phone' => 'system',
            'requester_name' => 'Continuous Improve',
            'agent_id' => Agent::first()?->id ?? 1,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        AppSetting::set('zeniclaw_project_id', (string) $project->id);

        return $project;
    }
}
