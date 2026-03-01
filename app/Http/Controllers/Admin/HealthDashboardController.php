<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class HealthDashboardController extends Controller
{
    public function index()
    {
        $checks = [];

        // DB
        try {
            $t = microtime(true);
            DB::select('SELECT 1');
            $ms = round((microtime(true) - $t) * 1000, 1);
            $checks['database'] = ['status' => 'ok', 'ms' => $ms, 'label' => 'Database (PostgreSQL)'];
        } catch (\Exception $e) {
            $checks['database'] = ['status' => 'fail', 'ms' => null, 'label' => 'Database (PostgreSQL)', 'error' => $e->getMessage()];
        }

        // Redis
        try {
            $t = microtime(true);
            Redis::ping();
            $ms = round((microtime(true) - $t) * 1000, 1);
            $checks['redis'] = ['status' => 'ok', 'ms' => $ms, 'label' => 'Redis'];
        } catch (\Exception $e) {
            $checks['redis'] = ['status' => 'warn', 'ms' => null, 'label' => 'Redis', 'error' => $e->getMessage()];
        }

        // WAHA
        try {
            $t = microtime(true);
            $resp = Http::timeout(5)->get('http://waha:3000/api/server/status');
            $ms = round((microtime(true) - $t) * 1000, 1);
            $checks['waha'] = ['status' => $resp->successful() ? 'ok' : 'warn', 'ms' => $ms, 'label' => 'WAHA (WhatsApp)'];
        } catch (\Exception $e) {
            $checks['waha'] = ['status' => 'warn', 'ms' => null, 'label' => 'WAHA (WhatsApp)', 'error' => 'Not reachable'];
        }

        // Scheduler (check if last job ran recently via cache key or just show status)
        $checks['scheduler'] = ['status' => 'ok', 'ms' => null, 'label' => 'Task Scheduler', 'error' => 'Manual verification needed'];

        // Recent errors
        $recentErrors = AgentLog::where('level', 'error')
            ->with('agent')
            ->latest('created_at')
            ->take(10)
            ->get();

        $version = trim(file_get_contents(storage_path('app/version.txt')) ?: '1.0.0');

        return view('admin.health', compact('checks', 'recentErrors', 'version'));
    }
}
