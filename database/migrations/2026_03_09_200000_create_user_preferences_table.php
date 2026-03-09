<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('language', 10)->default('fr');
            $table->string('timezone', 50)->default('Europe/Paris');
            $table->string('date_format', 20)->default('d/m/Y');
            $table->string('unit_system', 10)->default('metric');
            $table->string('communication_style', 30)->default('friendly');
            $table->boolean('notification_enabled')->default(true);
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
