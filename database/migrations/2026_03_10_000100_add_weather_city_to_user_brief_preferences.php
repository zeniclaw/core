<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_brief_preferences', function (Blueprint $table) {
            $table->string('weather_city')->nullable()->after('preferred_sections');
        });
    }

    public function down(): void
    {
        Schema::table('user_brief_preferences', function (Blueprint $table) {
            $table->dropColumn('weather_city');
        });
    }
};
