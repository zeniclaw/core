<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaborative_votes', function (Blueprint $table) {
            $table->id();
            $table->string('message_group_id')->index();
            $table->text('task_description');
            $table->unsignedTinyInteger('vote_quorum')->default(60);
            $table->string('created_by');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->json('votes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['message_group_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborative_votes');
    }
};
