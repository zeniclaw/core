<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_music_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('phone');
            $table->json('favorite_genres')->nullable();
            $table->json('favorite_artists')->nullable();
            $table->string('preferred_mood')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_music_preferences');
    }
};
