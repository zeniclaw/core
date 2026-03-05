<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\SelfImprovement;
use App\Models\SubAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class DebugController extends Controller
{
    public function index()
    {
        $autoSuggestEnabled = AppSetting::get('auto_suggest_enabled') === 'true';

        // System info
        $system = $this->gatherSystemInfo();

        // Scheduled jobs status
        $jobs = $this->gatherJobsInfo();

        // Recent improvements
        $recentImprovements = SelfImprovement::orderByDesc('created_at')->limit(10)->get();

        return view('admin.debug', compact('autoSuggestEnabled', 'system', 'jobs', 'recentImprovements'));
    }

    public function toggleAutoSuggest(Request $request): JsonResponse
    {
        $current = AppSetting::get('auto_suggest_enabled') === 'true';
        $newValue = !$current;
        AppSetting::set('auto_suggest_enabled', $newValue ? 'true' : 'false');

        return response()->json([
            'enabled' => $newValue,
            'message' => $newValue ? 'Auto-suggest enabled' : 'Auto-suggest disabled',
        ]);
    }

    public function systemInfo(): JsonResponse
    {
        return response()->json($this->gatherSystemInfo());
    }

    private function gatherSystemInfo(): array
    {
        $info = [];

        // OS & Kernel
        $info['hostname'] = gethostname();
        $info['os'] = php_uname('s') . ' ' . php_uname('r');
        $info['arch'] = php_uname('m');
        $info['php_version'] = PHP_VERSION;
        $info['laravel_version'] = app()->version();

        // App version
        $versionFile = storage_path('app/version.txt');
        $info['app_version'] = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : 'unknown';

        // CPU
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $m);
            $info['cpu_model'] = trim($m[1] ?? 'unknown');
            $info['cpu_cores'] = substr_count($cpuinfo, 'processor');
        }

        // Load average
        $load = sys_getloadavg();
        $info['load_avg'] = $load ? implode(' / ', array_map(fn($v) => round($v, 2), $load)) : 'n/a';

        // Memory
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $avail);
            $totalMb = round(($total[1] ?? 0) / 1024);
            $availMb = round(($avail[1] ?? 0) / 1024);
            $usedMb = $totalMb - $availMb;
            $info['memory_total'] = $totalMb . ' MB';
            $info['memory_used'] = $usedMb . ' MB';
            $info['memory_available'] = $availMb . ' MB';
            $info['memory_percent'] = $totalMb > 0 ? round(($usedMb / $totalMb) * 100) : 0;
        }

        // Disk
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        if ($total && $free) {
            $info['disk_total'] = round($total / (1024 * 1024 * 1024), 1) . ' GB';
            $info['disk_used'] = round(($total - $free) / (1024 * 1024 * 1024), 1) . ' GB';
            $info['disk_free'] = round($free / (1024 * 1024 * 1024), 1) . ' GB';
            $info['disk_percent'] = round((($total - $free) / $total) * 100);
        }

        // Uptime
        if (is_readable('/proc/uptime')) {
            $uptime = (float) explode(' ', file_get_contents('/proc/uptime'))[0];
            $days = floor($uptime / 86400);
            $hours = floor(($uptime % 86400) / 3600);
            $mins = floor(($uptime % 3600) / 60);
            $info['uptime'] = "{$days}d {$hours}h {$mins}m";
        }

        // Database
        try {
            $dbSize = DB::selectOne("SELECT pg_size_pretty(pg_database_size(current_database())) as size");
            $info['db_size'] = $dbSize->size ?? 'unknown';
            $info['db_connection'] = 'OK';
        } catch (\Exception $e) {
            $info['db_connection'] = 'ERROR: ' . $e->getMessage();
        }

        // Redis
        try {
            $redisInfo = Redis::info();
            $info['redis_version'] = $redisInfo['redis_version'] ?? 'unknown';
            $info['redis_memory'] = $redisInfo['used_memory_human'] ?? 'unknown';
            $info['redis_keys'] = $redisInfo['db0'] ?? 'empty';
            $info['redis_connection'] = 'OK';
        } catch (\Exception $e) {
            $info['redis_connection'] = 'ERROR: ' . $e->getMessage();
        }

        // Docker
        $info['in_docker'] = file_exists('/.dockerenv') || file_exists('/run/.containerenv');

        // Queue
        try {
            $queueSize = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();
            $info['queue_pending'] = $queueSize;
            $info['queue_failed'] = $failedJobs;
        } catch (\Exception $e) {
            $info['queue_pending'] = 'n/a';
            $info['queue_failed'] = 'n/a';
        }

        return $info;
    }

    private function gatherJobsInfo(): array
    {
        $autoSuggestEnabled = AppSetting::get('auto_suggest_enabled') === 'true';

        return [
            ['name' => 'reminders:process', 'schedule' => 'Every minute', 'enabled' => true],
            ['name' => 'zeniclaw:watchdog', 'schedule' => 'Every minute', 'enabled' => true],
            ['name' => 'zeniclaw:auto-suggest', 'schedule' => 'Every 15 minutes', 'enabled' => $autoSuggestEnabled],
            ['name' => 'zeniclaw:compact-logs', 'schedule' => 'Daily', 'enabled' => true],
            ['name' => 'finance:check-alerts', 'schedule' => 'Daily at 09:00', 'enabled' => true],
            ['name' => 'habits:remind', 'schedule' => 'Daily at 08:00', 'enabled' => true],
            ['name' => 'ProcessEventRemindersJob', 'schedule' => 'Every minute', 'enabled' => true],
        ];
    }
}
