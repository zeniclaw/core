<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_agents', function (Blueprint $table) {
            $table->string('type', 20)->default('code')->after('project_id'); // code, research
            $table->string('requester_phone', 50)->nullable()->after('type');
            $table->text('result')->nullable()->after('output_log'); // final result for research tasks
        });
    }

    public function down(): void
    {
        Schema::table('sub_agents', function (Blueprint $table) {
            $table->dropColumn(['type', 'requester_phone', 'result']);
        });
    }
};
