<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pomodoro_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('user_phone');
            $table->integer('duration')->default(25);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedTinyInteger('focus_quality')->nullable();
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'is_completed']);
            $table->foreign('agent_id')->references('id')->on('agents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pomodoro_sessions');
    }
};
