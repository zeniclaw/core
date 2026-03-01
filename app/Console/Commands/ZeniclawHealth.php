<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class ZeniclawHealth extends Command
{
    protected $signature = 'zeniclaw:health';
    protected $description = 'Run ZeniClaw health checks';

    public function handle(): int
    {
        $this->info('🏥 ZeniClaw Health Check');
        $this->line('─────────────────────────');

        $allOk = true;

        // DB
        try {
            $t = microtime(true);
            DB::select('SELECT 1');
            $ms = round((microtime(true) - $t) * 1000, 1);
            $this->info("✅ Database: OK ({$ms}ms)");
        } catch (\Exception $e) {
            $this->error("❌ Database: FAIL — {$e->getMessage()}");
            $allOk = false;
        }

        // Redis
        try {
            $t = microtime(true);
            Redis::ping();
            $ms = round((microtime(true) - $t) * 1000, 1);
            $this->info("✅ Redis: OK ({$ms}ms)");
        } catch (\Exception $e) {
            $this->warn("⚠️  Redis: FAIL — {$e->getMessage()}");
        }

        // WAHA
        try {
            $t = microtime(true);
            $resp = Http::timeout(5)->get('http://waha:3000/api/server/status');
            $ms = round((microtime(true) - $t) * 1000, 1);
            if ($resp->successful()) {
                $this->info("✅ WAHA: OK ({$ms}ms)");
            } else {
                $this->warn("⚠️  WAHA: HTTP {$resp->status()}");
            }
        } catch (\Exception $e) {
            $this->warn("⚠️  WAHA: not reachable (expected in local dev)");
        }

        // Version
        $version = trim(file_get_contents(storage_path('app/version.txt')) ?: '1.0.0');
        $this->line("📦 Version: {$version}");

        $this->line('─────────────────────────');
        if ($allOk) {
            $this->info('✅ All critical checks passed.');
            return self::SUCCESS;
        } else {
            $this->error('❌ Some checks failed.');
            return self::FAILURE;
        }
    }
}
