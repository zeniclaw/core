<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\SelfImprovement;
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

        $applied = 0;
        foreach ($improvements as $i => $improvement) {
            $num = $i + 1;
            $total = count($improvements);
            $this->info("[{$num}/{$total}] {$improvement['title']} ({$improvement['priority']})");

            // Create SelfImprovement record for tracking
            $record = SelfImprovement::create([
                'agent_id' => \App\Models\Agent::first()?->id ?? 1,
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
            ]);

            // Execute the improvement
            $result = $service->executeImprovement($improvement, $apiKey);

            if (!$result['success']) {
                $this->warn("  Failed: " . mb_substr($result['output_summary'] ?? 'unknown error', 0, 200));
                $record->update(['status' => 'failed']);
                Log::warning('[ContinuousImprove] Improvement failed', [
                    'title' => $improvement['title'],
                    'exit_code' => $result['exit_code'],
                ]);
                continue;
            }

            // Check for actual changes
            $statusResult = Process::path($this->workdir)->run('git status --porcelain');
            $changes = trim($statusResult->output());

            if (empty($changes)) {
                $this->info("  No code changes produced. Skipping commit.");
                $record->update(['status' => 'completed', 'admin_notes' => 'No changes needed']);
                continue;
            }

            // Commit with version bump
            $newVersion = $result['new_version'];
            $commitMsg = "fix(auto): {$improvement['title']} (v{$newVersion})";

            if ($improvement['category'] === 'new_feature') {
                $commitMsg = "feat(auto): {$improvement['title']} (v{$newVersion})";
            }

            Process::path($this->workdir)->run('git add -A');
            $commitResult = Process::path($this->workdir)->run(
                sprintf('git commit -m %s', escapeshellarg($commitMsg))
            );

            if (!$commitResult->successful()) {
                $this->warn("  Git commit failed: " . $commitResult->errorOutput());
                $record->update(['status' => 'failed']);
                continue;
            }

            // Tag
            Process::path($this->workdir)->run(
                sprintf('git tag %s', escapeshellarg("v{$newVersion}"))
            );

            // Push
            $pushResult = Process::path($this->workdir)->run('git push origin main --tags 2>&1');
            if (!$pushResult->successful()) {
                $this->warn("  Git push failed: " . $pushResult->errorOutput());
                // Commit succeeded locally, still mark as completed
            } else {
                $this->info("  Committed and pushed v{$newVersion}");
            }

            $record->update([
                'status' => 'completed',
                'admin_notes' => "Auto-applied v{$newVersion}. Changes: " . mb_substr($changes, 0, 500),
            ]);

            $applied++;
            Log::info("[ContinuousImprove] Applied improvement", [
                'title' => $improvement['title'],
                'version' => $newVersion,
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
            $gitlabUrl = AppSetting::get('gitlab_url') ?: 'https://gitlab.com/zenidev/zeniclaw.git';
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
}
