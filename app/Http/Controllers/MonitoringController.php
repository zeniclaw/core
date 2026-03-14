<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use App\Models\AuditLog;
use App\Models\SubAgent;
use App\Services\AnthropicClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring dashboard API (D15.2, D15.3).
 * Provides metrics, health checks, and alerting data.
 */
class MonitoringController extends Controller
{
    /**
     * Provider health check (D14.3).
     */
    public function healthCheck(): JsonResponse
    {
        $health = AnthropicClient::healthCheck();

        // Add Redis check
        try {
            \Illuminate\Support\Facades\Redis::ping();
            $health['redis'] = true;
        } catch (\Throwable $e) {
            $health['redis'] = false;
        }

        // Add DB check
        try {
            DB::connection()->getPdo();
            $health['database'] = true;
        } catch (\Throwable $e) {
            $health['database'] = false;
        }

        // Add queue check
        $health['queue'] = Cache::get('queue_health', false);

        $allHealthy = !in_array(false, $health, true);

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'services' => $health,
            'timestamp' => now()->toIso8601String(),
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Monitoring dashboard data (D15.2).
     */
    public function dashboard(): JsonResponse
    {
        // Token usage today
        $tokenUsage = AnthropicClient::getTokenUsage();

        // Agent stats (last 24h)
        $agentStats = AgentLog::where('created_at', '>=', now()->subHours(24))
            ->selectRaw("JSON_EXTRACT(context, '$.from') as user_from, count(*) as count")
            ->groupByRaw("JSON_EXTRACT(context, '$.from')")
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Tool usage (last 24h) from audit logs
        $toolStats = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
            $toolStats = AuditLog::where('created_at', '>=', now()->subHours(24))
                ->where('action', 'tool_call')
                ->selectRaw('tool_name, count(*) as count, avg(duration_ms) as avg_duration_ms')
                ->groupBy('tool_name')
                ->orderByDesc('count')
                ->limit(20)
                ->get();
        }

        // Active tasks
        $activeTasks = SubAgent::whereIn('status', ['queued', 'running'])->count();
        $completedToday = SubAgent::where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfDay())
            ->count();
        $failedToday = SubAgent::where('status', 'failed')
            ->where('completed_at', '>=', now()->startOfDay())
            ->count();

        // Error rate (last hour)
        $totalLogs = AgentLog::where('created_at', '>=', now()->subHour())->count();
        $errorLogs = AgentLog::where('created_at', '>=', now()->subHour())
            ->where('level', 'error')
            ->count();
        $errorRate = $totalLogs > 0 ? round(($errorLogs / $totalLogs) * 100, 1) : 0;

        return response()->json([
            'token_usage' => $tokenUsage,
            'active_tasks' => $activeTasks,
            'completed_today' => $completedToday,
            'failed_today' => $failedToday,
            'error_rate_percent' => $errorRate,
            'tool_stats' => $toolStats,
            'top_users' => $agentStats,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Alerting data (D15.3) — check if error thresholds are exceeded.
     */
    public function alerts(): JsonResponse
    {
        $alerts = [];

        // High error rate alert
        $errorCount = AgentLog::where('created_at', '>=', now()->subMinutes(10))
            ->where('level', 'error')
            ->count();
        if ($errorCount > 10) {
            $alerts[] = [
                'severity' => 'high',
                'message' => "High error rate: {$errorCount} errors in last 10 minutes",
                'type' => 'error_rate',
            ];
        }

        // Provider health
        $health = AnthropicClient::healthCheck();
        if (!$health['anthropic']) {
            $alerts[] = [
                'severity' => 'critical',
                'message' => 'Anthropic API is down',
                'type' => 'provider_down',
            ];
        }

        // Queue backlog
        $queuedTasks = SubAgent::where('status', 'queued')
            ->where('created_at', '<', now()->subMinutes(5))
            ->count();
        if ($queuedTasks > 3) {
            $alerts[] = [
                'severity' => 'medium',
                'message' => "{$queuedTasks} tasks queued for >5 minutes — queue may be stuck",
                'type' => 'queue_backlog',
            ];
        }

        return response()->json([
            'alerts' => $alerts,
            'count' => count($alerts),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
