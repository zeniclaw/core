<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('key_name');
            $table->text('encrypted_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_secrets');
    }
};
