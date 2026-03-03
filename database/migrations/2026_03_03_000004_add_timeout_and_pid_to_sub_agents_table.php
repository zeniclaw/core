<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_agents', function (Blueprint $table) {
            $table->unsignedInteger('timeout_minutes')->default(10)->after('api_calls_count');
            $table->unsignedInteger('pid')->nullable()->after('timeout_minutes');
        });

        // Add 'killed' to the status enum
        DB::statement("ALTER TABLE sub_agents DROP CONSTRAINT IF EXISTS sub_agents_status_check");
        DB::statement("ALTER TABLE sub_agents ADD CONSTRAINT sub_agents_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed', 'killed'))");
    }

    public function down(): void
    {
        Schema::table('sub_agents', function (Blueprint $table) {
            $table->dropColumn(['timeout_minutes', 'pid']);
        });

        DB::statement("ALTER TABLE sub_agents DROP CONSTRAINT IF EXISTS sub_agents_status_check");
        DB::statement("ALTER TABLE sub_agents ADD CONSTRAINT sub_agents_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed'))");
    }
};
