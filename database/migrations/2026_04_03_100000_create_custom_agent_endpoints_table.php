<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_agent_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_agent_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('method', 10)->default('GET'); // GET, POST, PUT, PATCH, DELETE
            $table->string('url', 1000);
            $table->string('auth_type', 30)->default('bearer'); // bearer, header, query, none
            $table->string('auth_credential_key', 150)->nullable(); // FK name in credentials
            $table->json('trigger_phrases')->nullable(); // ["mes factures", "list invoices"]
            $table->json('parameters')->nullable(); // [{name, type, enum?, mapping, required}]
            $table->string('response_path', 300)->nullable(); // JSON path: "data.invoices"
            $table->json('headers')->nullable(); // extra headers
            $table->json('request_body_template')->nullable(); // for POST/PUT/PATCH
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_share_id')->nullable()->constrained('custom_agent_shares')->nullOnDelete();
            $table->timestamps();

            $table->index(['custom_agent_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_agent_endpoints');
    }
};
