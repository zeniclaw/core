<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('requester_phone')->nullable()->after('user_id');
            $table->string('requester_name')->nullable()->after('requester_phone');
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn(['requester_phone', 'requester_name']);
        });
    }
};
