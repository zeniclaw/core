<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_agent_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_agent_id')->constrained()->cascadeOnDelete();
            $table->string('category', 50)->default('general'); // general, fact, instruction, preference
            $table->text('content');
            $table->string('source', 100)->nullable(); // who taught this: "partner", "chat", "admin"
            $table->timestamps();

            $table->index(['custom_agent_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_agent_memories');
    }
};
