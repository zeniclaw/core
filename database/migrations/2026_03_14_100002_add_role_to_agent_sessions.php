<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->string('role', 20)->default('user')->after('display_name');
            $table->integer('rate_limit_count')->default(0)->after('role');
            $table->timestamp('rate_limit_reset_at')->nullable()->after('rate_limit_count');
        });
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropColumn(['role', 'rate_limit_count', 'rate_limit_reset_at']);
        });
    }
};
