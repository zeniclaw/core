<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_improvements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->text('trigger_message');
            $table->text('agent_response');
            $table->string('routed_agent');
            $table->json('analysis')->nullable();
            $table->string('improvement_title');
            $table->longText('development_plan');
            $table->enum('status', ['pending', 'approved', 'rejected', 'in_progress', 'completed', 'failed'])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->foreignId('sub_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_improvements');
    }
};
