<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hangman_games', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->string('word');
            $table->json('guessed_letters')->default('[]');
            $table->unsignedTinyInteger('wrong_count')->default(0);
            $table->enum('status', ['playing', 'won', 'lost'])->default('playing');
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'status']);
            $table->foreign('agent_id')->references('id')->on('agents');
        });

        Schema::create('hangman_stats', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedInteger('games_played')->default(0);
            $table->unsignedInteger('games_won')->default(0);
            $table->unsignedInteger('best_streak')->default(0);
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('total_guesses')->default(0);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();

            $table->unique(['user_phone', 'agent_id']);
            $table->foreign('agent_id')->references('id')->on('agents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hangman_stats');
        Schema::dropIfExists('hangman_games');
    }
};
