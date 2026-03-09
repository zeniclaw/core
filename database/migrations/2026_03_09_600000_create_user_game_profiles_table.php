<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_game_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->string('current_game')->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('total_games')->default(0);
            $table->json('achievements')->nullable();
            $table->unsignedInteger('weekly_challenges_completed')->default(0);
            $table->unsignedInteger('streak')->default(0);
            $table->unsignedInteger('best_streak')->default(0);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();

            $table->unique(['user_phone', 'agent_id']);
            $table->index('user_phone');
            $table->foreign('agent_id')->references('id')->on('agents');
        });

        Schema::create('game_achievements', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->string('achievement_key');
            $table->string('game_type');
            $table->timestamp('unlocked_at');
            $table->timestamps();

            $table->index(['user_phone', 'agent_id']);
            $table->index(['user_phone', 'game_type']);
            $table->foreign('agent_id')->references('id')->on('agents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_achievements');
        Schema::dropIfExists('user_game_profiles');
    }
};
