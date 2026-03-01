<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_memory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['daily', 'longterm'])->default('daily');
            $table->date('date')->nullable();
            $table->longText('content');
            $table->timestamps();
            $table->index(['agent_id', 'type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_memory');
    }
};
