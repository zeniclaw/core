<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_agent_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_agent_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('partner_name')->nullable();
            $table->json('permissions')->nullable(); // ['documents','chat','skills','scripts']
            $table->timestamp('expires_at')->nullable(); // null = never
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->boolean('is_revoked')->default(false);
            $table->timestamps();

            $table->index('token');
        });

        Schema::create('custom_agent_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_agent_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('trigger_phrase')->nullable();
            $table->json('routine'); // [{type:'prompt',content:'...'}, ...]
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_share_id')->nullable()->constrained('custom_agent_shares')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('custom_agent_scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_agent_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('language', 20); // python, php, bash
            $table->text('code');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_share_id')->nullable()->constrained('custom_agent_shares')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_agent_scripts');
        Schema::dropIfExists('custom_agent_skills');
        Schema::dropIfExists('custom_agent_shares');
    }
};
