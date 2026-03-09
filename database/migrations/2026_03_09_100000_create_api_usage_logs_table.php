<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('requester_phone')->nullable();
            $table->string('caller_agent')->index();        // which sub-agent made the call
            $table->string('api_name');                      // e.g. 'brave_search', 'openweather'
            $table->string('endpoint');                      // API endpoint called
            $table->string('method', 10)->default('GET');
            $table->json('request_params')->nullable();
            $table->integer('response_status')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->integer('result_count')->default(0);     // number of results returned
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['api_name', 'created_at']);
            $table->index(['caller_agent', 'created_at']);
            $table->index('requester_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_logs');
    }
};
