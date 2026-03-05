<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_wishlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('user_phone');
            $table->string('song_name');
            $table->string('artist');
            $table->string('album')->nullable();
            $table->string('spotify_url')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('spotify_id')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'user_phone']);
        });

        Schema::create('music_listen_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('user_phone');
            $table->string('song_name');
            $table->string('artist');
            $table->string('action'); // search, recommend, playlist, top
            $table->string('spotify_url')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'user_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_listen_history');
        Schema::dropIfExists('music_wishlist');
    }
};
