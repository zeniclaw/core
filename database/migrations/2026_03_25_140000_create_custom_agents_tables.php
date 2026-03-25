<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom agents — user-created AI assistants
        Schema::create('custom_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->onDelete('cascade'); // parent ZeniClaw agent
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('system_prompt')->nullable();
            $table->string('model')->default('default'); // LLM model override
            $table->string('avatar')->default('🤖');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // temperature, max_tokens, etc.
            $table->timestamps();

            $table->index(['agent_id', 'is_active']);
        });

        // Documents uploaded to train custom agents
        Schema::create('custom_agent_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_agent_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->string('type'); // pdf, text, url, file
            $table->string('source')->nullable(); // original filename or URL
            $table->longText('raw_content')->nullable(); // extracted text
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('status')->default('pending'); // pending, processing, ready, failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['custom_agent_id', 'status']);
        });

        // Embedding chunks for RAG search
        Schema::create('custom_agent_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('custom_agent_documents')->onDelete('cascade');
            $table->foreignId('custom_agent_id')->constrained()->onDelete('cascade');
            $table->text('content'); // chunk text
            $table->binary('embedding')->nullable(); // 384-dim vector stored as binary
            $table->unsignedInteger('chunk_index')->default(0);
            $table->json('metadata')->nullable(); // page number, section, etc.
            $table->timestamps();

            $table->index('custom_agent_id');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_agent_chunks');
        Schema::dropIfExists('custom_agent_documents');
        Schema::dropIfExists('custom_agents');
    }
};
