<?php

namespace App\Console\Commands;

use App\Jobs\RunAutoImproveAgentJob;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SelfImprovement;
use App\Models\SubAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class AutoImproveAgentCommand extends Command
{
    protected $signature = 'zeniclaw:auto-improve-agents';
    protected $description = 'Pick the sub-agent with the oldest version, analyze and improve it, then move to the next';

    public function handle(): int
    {
        $running = SubAgent::where('status', 'running')
            ->where('task_description', 'like', '[Auto-Improve]%')
            ->exists();

        if ($running) {
            $this->info('An auto-improve job is already running. Skipping.');
            return self::SUCCESS;
        }

        $agents = $this->scanAgents();

        if (empty($agents)) {
            $this->error('No agents found to improve.');
            return self::FAILURE;
        }

        usort($agents, fn($a, $b) => version_compare($a['version'], $b['version']));

        $lowestVersion = $agents[0]['version'];
        $candidates = array_values(array_filter($agents, fn($a) => $a['version'] === $lowestVersion));
        $chosen = $candidates[array_rand($candidates)];

        $this->info("Selected agent: {$chosen['slug']} (v{$chosen['version']})");

        $agent = Agent::first();
        if (!$agent) {
            $this->error('No agent found in database.');
            return self::FAILURE;
        }

        $project = $this->getOrCreateZeniclawProject($agent);

        $defaultTimeout = (int) (AppSetting::get('subagent_default_timeout') ?: 15);
        $subAgent = SubAgent::create([
            'project_id' => $project->id,
            'status' => 'queued',
            'task_description' => "[Auto-Improve] Upgrade {$chosen['slug']} agent (v{$chosen['version']})",
            'timeout_minutes' => $defaultTimeout,
        ]);

        $improvement = SelfImprovement::create([
            'agent_id' => $agent->id,
            'trigger_message' => "[Auto-Improve] Analyse et amelioration de {$chosen['slug']}",
            'agent_response' => "Agent selectionne automatiquement (version la plus ancienne: v{$chosen['version']})",
            'routed_agent' => 'auto-improve',
            'analysis' => [
                'improve' => true,
                'title' => "Upgrade {$chosen['slug']} agent",
                'analysis' => "Version actuelle: v{$chosen['version']}.",
                'plan' => "Analyse, amelioration, test, rapport, bump version, push.",
            ],
            'improvement_title' => "Auto-improve: {$chosen['slug']} v{$chosen['version']}",
            'development_plan' => "Analyse et amelioration automatique de l'agent {$chosen['slug']}",
            'status' => 'in_progress',
            'sub_agent_id' => $subAgent->id,
        ]);

        RunAutoImproveAgentJob::dispatch($improvement, $subAgent, $chosen['slug'], $chosen['file']);

        $this->info("Job dispatched: SubAgent #{$subAgent->id}, Improvement #{$improvement->id}");
        Log::info("AutoImproveAgent: dispatched for {$chosen['slug']} v{$chosen['version']}");

        return self::SUCCESS;
    }

    private function scanAgents(): array
    {
        $agentsDir = app_path('Services/Agents');
        $agents = [];
        $skip = ['BaseAgent.php', 'AgentInterface.php', 'AgentResult.php', 'RouterAgent.php'];

        foreach (File::files($agentsDir) as $file) {
            if (in_array($file->getFilename(), $skip) || $file->getExtension() !== 'php') continue;

            $content = $file->getContents();
            if (!preg_match("/function\s+name\(\).*?return\s+['\"]([^'\"]+)['\"]/s", $content, $nameMatch)) continue;

            $version = '1.0.0';
            if (preg_match("/function\s+version\(\).*?return\s+['\"]([^'\"]+)['\"]/s", $content, $versionMatch)) {
                $version = $versionMatch[1];
            }

            $agents[] = [
                'slug' => $nameMatch[1],
                'version' => $version,
                'file' => 'app/Services/Agents/' . $file->getFilename(),
            ];
        }

        return $agents;
    }

    private function getOrCreateZeniclawProject(Agent $agent): Project
    {
        $projectId = AppSetting::get('zeniclaw_project_id');
        if ($projectId && ($project = Project::find($projectId))) return $project;

        $project = Project::where('name', 'ZeniClaw (Auto-Improve)')->first();
        if ($project) return $project;

        $project = Project::create([
            'name' => 'ZeniClaw (Auto-Improve)',
            'gitlab_url' => 'https://gitlab.com/zenidev/zeniclaw.git',
            'request_description' => 'Projet auto-genere pour les auto-ameliorations de ZeniClaw.',
            'requester_phone' => 'system',
            'requester_name' => 'ZeniClaw Auto-Improve',
            'agent_id' => $agent->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        AppSetting::set('zeniclaw_project_id', (string) $project->id);
        return $project;
    }
}
