<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('habit_id');
            $table->date('completed_date');
            $table->unsignedInteger('streak_count')->default(0);
            $table->unsignedInteger('best_streak')->default(0);
            $table->timestamps();

            $table->index(['habit_id', 'completed_date']);
            $table->unique(['habit_id', 'completed_date']);
            $table->foreign('habit_id')->references('id')->on('habits')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_logs');
    }
};
