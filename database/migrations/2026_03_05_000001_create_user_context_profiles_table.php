<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_context_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone')->unique();
            $table->json('facts')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->index('user_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_context_profiles');
    }
};
