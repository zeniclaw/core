<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finances_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->decimal('amount', 10, 2);
            $table->string('category');
            $table->string('description')->nullable();
            $table->date('date');
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'category']);
            $table->index(['user_phone', 'date']);
        });

        Schema::create('finances_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->string('category');
            $table->decimal('monthly_limit', 10, 2);
            $table->timestamps();

            $table->unique(['user_phone', 'category'], 'budgets_user_category_unique');
        });

        Schema::create('finances_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->string('category');
            $table->unsignedTinyInteger('threshold_percentage')->default(80);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('user_phone');
            $table->unique(['user_phone', 'category'], 'alerts_user_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finances_alerts');
        Schema::dropIfExists('finances_budgets');
        Schema::dropIfExists('finances_expenses');
    }
};
