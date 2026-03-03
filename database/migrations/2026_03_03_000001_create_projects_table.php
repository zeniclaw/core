<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('gitlab_url');
            $table->text('request_description');
            $table->string('requester_phone');
            $table->string('requester_name');
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'in_progress', 'completed', 'failed'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
