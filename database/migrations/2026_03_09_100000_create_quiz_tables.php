<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quizzes')) {
            return;
        }

        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->string('category')->default('general');
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->json('questions');
            $table->unsignedTinyInteger('current_question_index')->default(0);
            $table->unsignedTinyInteger('correct_answers')->default(0);
            $table->enum('status', ['playing', 'completed', 'abandoned'])->default('playing');
            $table->string('challenger_phone')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'status']);
            $table->foreign('agent_id')->references('id')->on('agents');
        });

        Schema::create('quiz_scores', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('quiz_id');
            $table->string('category')->default('general');
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedTinyInteger('total_questions')->default(0);
            $table->unsignedInteger('time_taken')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_phone', 'created_at']);
            $table->index(['user_phone', 'agent_id']);
            $table->foreign('agent_id')->references('id')->on('agents');
            $table->foreign('quiz_id')->references('id')->on('quizzes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_scores');
        Schema::dropIfExists('quizzes');
    }
};
