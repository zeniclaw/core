<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mood_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedTinyInteger('mood_level')->comment('1-5 scale');
            $table->string('mood_label')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('recommendations_applied')->default(false);
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mood_logs');
    }
};
