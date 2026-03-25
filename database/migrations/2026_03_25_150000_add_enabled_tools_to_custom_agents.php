<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_agents', function (Blueprint $table) {
            $table->json('enabled_tools')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('custom_agents', function (Blueprint $table) {
            $table->dropColumn('enabled_tools');
        });
    }
};
