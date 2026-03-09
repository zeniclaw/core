<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_agent_analytics', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('agent_used')->index();
            $table->string('interaction_type')->default('command'); // question, command, file_upload
            $table->unsignedInteger('duration')->nullable(); // milliseconds
            $table->boolean('success')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'agent_used']);
            $table->index(['user_id', 'created_at']);
            $table->index(['agent_used', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_agent_analytics');
    }
};
