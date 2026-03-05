<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('user_phone');
            $table->string('requester_name')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->enum('frequency', ['daily', 'weekly'])->default('daily');
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_phone');
            $table->index(['user_phone', 'frequency']);
            $table->foreign('agent_id')->references('id')->on('agents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habits');
    }
};
