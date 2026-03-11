<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE agent_logs DROP CONSTRAINT IF EXISTS agent_logs_level_check");
        DB::statement("ALTER TABLE agent_logs ADD CONSTRAINT agent_logs_level_check CHECK (level IN ('info', 'warn', 'error', 'debug'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE agent_logs DROP CONSTRAINT IF EXISTS agent_logs_level_check");
        DB::statement("ALTER TABLE agent_logs ADD CONSTRAINT agent_logs_level_check CHECK (level IN ('info', 'warn', 'error'))");
    }
};
