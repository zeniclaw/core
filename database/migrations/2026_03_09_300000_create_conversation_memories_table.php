<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_memories', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->enum('fact_type', ['project', 'preference', 'decision', 'skill', 'constraint'])->default('preference');
            $table->text('content');
            $table->json('tags')->nullable();
            $table->string('status')->default('active'); // active, archived
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->index(['user_id', 'fact_type']);
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_memories');
    }
};
