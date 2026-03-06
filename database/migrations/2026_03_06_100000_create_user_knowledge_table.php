<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone', 50)->index();
            $table->string('topic_key', 100)->index();
            $table->string('label')->nullable();
            $table->json('data');
            $table->string('source', 50)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_phone', 'topic_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_knowledge');
    }
};
