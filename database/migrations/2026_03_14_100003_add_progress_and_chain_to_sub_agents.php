<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sub_agents', function (Blueprint $table) {
            $table->integer('progress_percent')->default(0)->after('status');
            $table->string('progress_message', 500)->nullable()->after('progress_percent');
            $table->text('next_task_description')->nullable()->after('task_description');
            $table->string('priority', 20)->default('normal')->after('timeout_minutes');
            $table->string('cron_expression', 100)->nullable()->after('priority');
            $table->boolean('is_recurring')->default(false)->after('cron_expression');
        });
    }

    public function down(): void
    {
        Schema::table('sub_agents', function (Blueprint $table) {
            $table->dropColumn(['progress_percent', 'progress_message', 'next_task_description', 'priority', 'cron_expression', 'is_recurring']);
        });
    }
};
