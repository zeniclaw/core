<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_agent_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_agent_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);        // e.g. "api_token", "db_password"
            $table->text('value');              // AES-256 encrypted via Laravel Crypt
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['custom_agent_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_agent_credentials');
    }
};
