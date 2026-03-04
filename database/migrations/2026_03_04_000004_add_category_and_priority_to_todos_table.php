<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->string('category')->nullable()->default(null)->after('title');
            $table->enum('priority', ['high', 'normal', 'low'])->default('normal')->after('category');
            $table->dateTime('due_at')->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn(['category', 'priority', 'due_at']);
        });
    }
};
