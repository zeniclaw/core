<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_agents', function (Blueprint $table) {
            $table->json('allowed_peers')->nullable()->after('enabled_tools');
        });
    }

    public function down(): void
    {
        Schema::table('custom_agents', function (Blueprint $table) {
            $table->dropColumn('allowed_peers');
        });
    }
};
