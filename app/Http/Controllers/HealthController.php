<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $version = trim(file_get_contents(storage_path('app/version.txt')) ?: '1.0.0');

        // DB
        $dbOk = false;
        $dbMs = null;
        try {
            $t = microtime(true);
            DB::select('SELECT 1');
            $dbMs = round((microtime(true) - $t) * 1000, 1);
            $dbOk = true;
        } catch (\Exception $e) {}

        // Redis
        $redisOk = false;
        $redisMs = null;
        try {
            $t = microtime(true);
            Redis::ping();
            $redisMs = round((microtime(true) - $t) * 1000, 1);
            $redisOk = true;
        } catch (\Exception $e) {}

        $status = $dbOk ? 'ok' : 'degraded';

        return response()->json([
            'status'    => $status,
            'version'   => $version,
            'db'        => ['ok' => $dbOk, 'ms' => $dbMs],
            'redis'     => ['ok' => $redisOk, 'ms' => $redisMs],
            'timestamp' => now()->toIso8601String(),
        ], $dbOk ? 200 : 503);
    }
}
