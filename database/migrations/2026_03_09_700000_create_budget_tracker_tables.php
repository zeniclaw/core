<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_categories', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->string('name');
            $table->decimal('monthly_limit', 10, 2)->default(0);
            $table->decimal('spent_this_month', 10, 2)->default(0);
            $table->string('month_key')->comment('Format: YYYY-MM');
            $table->timestamps();

            $table->unique(['user_phone', 'agent_id', 'name', 'month_key']);
            $table->index('user_phone');
            $table->foreign('agent_id')->references('id')->on('agents');
        });

        Schema::create('budget_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->unsignedBigInteger('agent_id');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('EUR');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->date('expense_date');
            $table->timestamps();

            $table->index(['user_phone', 'agent_id']);
            $table->index(['user_phone', 'expense_date']);
            $table->index(['user_phone', 'category']);
            $table->foreign('agent_id')->references('id')->on('agents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_expenses');
        Schema::dropIfExists('budget_categories');
    }
};
