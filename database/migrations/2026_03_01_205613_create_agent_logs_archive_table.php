<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_logs_archive', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id');
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['info', 'warn', 'error'])->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('archived_at')->useCurrent();
            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_logs_archive');
    }
};
