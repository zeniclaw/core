<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 100)->index();
            $table->string('agent_name', 50)->index();
            $table->string('action', 100)->index();
            $table->string('tool_name', 100)->nullable()->index();
            $table->json('input_summary')->nullable();
            $table->string('result_status', 20)->default('success');
            $table->integer('duration_ms')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
