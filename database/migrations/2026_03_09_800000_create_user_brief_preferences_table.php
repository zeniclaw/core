<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_brief_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone')->unique();
            $table->string('brief_time')->default('07:00');
            $table->boolean('enabled')->default(true);
            $table->json('preferred_sections')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_brief_preferences');
    }
};
