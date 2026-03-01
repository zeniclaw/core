<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('session_key')->unique(); // agent:{id}:{channel}:dm:{peerId}
            $table->string('channel')->default('whatsapp');
            $table->string('peer_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();
            $table->index(['agent_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sessions');
    }
};
