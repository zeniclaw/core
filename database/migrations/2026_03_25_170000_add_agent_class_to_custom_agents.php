<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_agents', function (Blueprint $table) {
            $table->string('agent_class', 200)->nullable()->after('model')
                  ->comment('FQCN of coded agent class — when set, uses this instead of CustomAgentRunner');
        });
    }

    public function down(): void
    {
        Schema::table('custom_agents', function (Blueprint $table) {
            $table->dropColumn('agent_class');
        });
    }
};
