<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('group_name');
            $table->string('status')->default('active'); // active, completed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('messages_captured')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_sessions');
    }
};
