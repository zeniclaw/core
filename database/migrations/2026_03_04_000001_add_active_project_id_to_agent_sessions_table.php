<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->foreignId('active_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('pending_switch_project_id')->nullable(); // temp state for confirmation flow
        });
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_project_id');
            $table->dropColumn('pending_switch_project_id');
        });
    }
};
