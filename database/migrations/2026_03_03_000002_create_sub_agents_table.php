<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');
            $table->text('task_description');
            $table->string('branch_name')->nullable();
            $table->string('commit_hash')->nullable();
            $table->longText('output_log')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('api_calls_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_agents');
    }
};
