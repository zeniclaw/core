<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('whitelist_enabled')->default(false)->after('status');
        });

        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->boolean('whitelisted')->default(false)->after('message_count');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('whitelist_enabled');
        });

        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropColumn('whitelisted');
        });
    }
};
