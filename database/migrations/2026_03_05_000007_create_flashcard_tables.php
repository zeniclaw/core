<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flashcard_decks', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('language')->default('fr');
            $table->string('difficulty')->default('medium');
            $table->timestamps();

            $table->unique(['user_phone', 'agent_id', 'name']);
            $table->index('user_phone');
        });

        Schema::create('flashcards', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->string('deck_name');
            $table->text('question');
            $table->text('answer');
            $table->float('ease_factor')->default(2.5);
            $table->integer('interval')->default(0);
            $table->timestamp('next_review_at')->nullable();
            $table->integer('repetitions')->default(0);
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_phone', 'agent_id', 'deck_name']);
            $table->index(['user_phone', 'next_review_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flashcards');
        Schema::dropIfExists('flashcard_decks');
    }
};
