<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\AgentSession;
use App\Models\AppSetting;
use App\Models\EventReminder;
use App\Models\Flashcard;
use App\Models\FlashcardDeck;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\MoodLog;
use App\Models\PomodoroSession;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\SubAgent;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ManagerHeartbeat extends Command
{
    protected $signature = 'zeniclaw:heartbeat';
    protected $description = 'Send instance heartbeat to ZeniClaw Manager';

    private const DEFAULT_MANAGER_URL = 'https://zeniclaw-main-cf7m55.laravel.cloud';

    public function handle(): int
    {
        $managerUrl = AppSetting::get('manager_url') ?: self::DEFAULT_MANAGER_URL;

        $payload = $this->buildPayload();

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->post(rtrim($managerUrl, '/') . '/api/instances/heartbeat', $payload);

            if ($response->successful()) {
                $this->info('Heartbeat sent successfully.');
                Log::channel('single')->info('Manager heartbeat sent', ['status' => $response->status()]);
            } else {
                $this->error("Heartbeat failed: HTTP {$response->status()}");
                Log::channel('single')->warning('Manager heartbeat failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            $this->error("Heartbeat error: {$e->getMessage()}");
            Log::channel('single')->error('Manager heartbeat error', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }

    private function buildPayload(): array
    {
        return [
            'instance_id' => $this->getInstanceId(),
            'instance_name' => config('app.name', 'ZeniClaw'),
            'version' => $this->getVersion(),
            'timestamp' => now()->toIso8601String(),
            'system' => $this->getSystemInfo(),
            'health' => $this->getHealthInfo(),
            'counts' => $this->getCounts(),
            'features' => $this->getFeatureUsage(),
            'config' => $this->getConfigInfo(),
        ];
    }

    private function getInstanceId(): string
    {
        $id = AppSetting::get('instance_id');
        if (!$id) {
            $id = (string) Str::uuid();
            AppSetting::set('instance_id', $id);
        }
        return $id;
    }

    private function getVersion(): string
    {
        $path = storage_path('app/version.txt');
        return file_exists($path) ? trim(file_get_contents($path)) : 'unknown';
    }

    private function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'uptime_seconds' => (int) (microtime(true) - LARAVEL_START),
            'disk_free_mb' => (int) round(disk_free_space('/') / 1024 / 1024),
            'memory_usage_mb' => (int) round(memory_get_usage(true) / 1024 / 1024),
        ];
    }

    private function getHealthInfo(): array
    {
        $health = ['status' => 'ok'];

        // Database
        try {
            $t = microtime(true);
            DB::select('SELECT 1');
            $health['database'] = ['ok' => true, 'response_ms' => round((microtime(true) - $t) * 1000, 1)];
        } catch (\Exception $e) {
            $health['database'] = ['ok' => false, 'response_ms' => null];
            $health['status'] = 'degraded';
        }

        // Redis
        try {
            $t = microtime(true);
            Redis::ping();
            $health['redis'] = ['ok' => true, 'response_ms' => round((microtime(true) - $t) * 1000, 1)];
        } catch (\Exception $e) {
            $health['redis'] = ['ok' => false, 'response_ms' => null];
        }

        // WAHA
        try {
            $t = microtime(true);
            $resp = Http::timeout(5)->get('http://waha:3000/api/server/status');
            $health['waha'] = ['ok' => $resp->successful(), 'response_ms' => round((microtime(true) - $t) * 1000, 1)];
        } catch (\Exception $e) {
            $health['waha'] = ['ok' => false, 'response_ms' => null];
        }

        return $health;
    }

    private function getCounts(): array
    {
        $today = now()->startOfDay();

        return [
            'users' => User::count(),
            'agents' => Agent::count(),
            'agents_active' => Agent::where('status', 'active')->count(),
            'sessions_total' => AgentSession::count(),
            'sessions_today' => AgentSession::where('created_at', '>=', $today)->count(),
            'messages_total' => AgentLog::count(),
            'messages_today' => AgentLog::where('created_at', '>=', $today)->count(),
            'errors_today' => AgentLog::where('level', 'error')->where('created_at', '>=', $today)->count(),
            'subagents_running' => SubAgent::where('status', 'running')->count(),
        ];
    }

    private function getFeatureUsage(): array
    {
        $today = now()->startOfDay();

        return [
            'todos' => [
                'total' => Todo::count(),
                'done' => Todo::where('is_done', true)->count(),
            ],
            'habits' => [
                'total' => Habit::count(),
                'completed_today' => HabitLog::whereDate('completed_date', today())->count(),
            ],
            'reminders' => [
                'active' => Reminder::where('status', 'pending')->count(),
            ],
            'flashcards' => [
                'total' => Flashcard::count(),
                'decks' => FlashcardDeck::count(),
                'due' => Flashcard::where('next_review_at', '<=', now())->count(),
            ],
            'mood_logs' => [
                'total' => MoodLog::count(),
            ],
            'expenses' => [
                'total' => 0,
                'this_month' => 0,
            ],
            'pomodoro' => [
                'total' => PomodoroSession::count(),
                'today' => PomodoroSession::where('created_at', '>=', $today)->count(),
            ],
            'projects' => [
                'total' => Project::count(),
            ],
            'event_reminders' => [
                'active' => EventReminder::where('status', 'active')->count(),
            ],
        ];
    }

    private function getConfigInfo(): array
    {
        return [
            'has_anthropic_key' => AppSetting::has('anthropic_api_key'),
            'has_openai_key' => AppSetting::has('openai_api_key'),
            'has_gitlab_token' => AppSetting::has('gitlab_token'),
            'auto_update_enabled' => AppSetting::get('auto_update_enabled') !== 'false',
            'auto_suggest_enabled' => AppSetting::get('auto_suggest_enabled') === 'true',
        ];
    }
}
