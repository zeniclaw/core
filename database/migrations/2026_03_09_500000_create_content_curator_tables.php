<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_content_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->string('category');
            $table->json('keywords')->nullable();
            $table->json('sources')->nullable();
            $table->timestamps();

            $table->index('user_phone');
            $table->unique(['user_phone', 'category']);
        });

        Schema::create('saved_articles', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->string('url', 2048);
            $table->string('title')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'created_at']);
        });

        Schema::create('content_digest_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->json('categories')->nullable();
            $table->unsignedInteger('article_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_digest_logs');
        Schema::dropIfExists('saved_articles');
        Schema::dropIfExists('user_content_preferences');
    }
};
