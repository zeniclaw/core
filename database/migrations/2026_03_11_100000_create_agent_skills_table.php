<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('sub_agent', 50)->index();         // e.g. "reminder", "todo", "chat"
            $table->string('skill_key', 100);                  // unique per agent+sub_agent
            $table->string('title');                           // human-readable label
            $table->text('instructions');                      // the actual skill/instruction
            $table->json('examples')->nullable();              // optional usage examples
            $table->string('taught_by', 50)->nullable();       // phone of who taught it
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['agent_id', 'sub_agent', 'skill_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_skills');
    }
};
