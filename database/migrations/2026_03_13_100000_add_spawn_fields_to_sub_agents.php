<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_agents', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('id');
            $table->string('spawning_agent', 50)->nullable()->after('requester_phone');
            $table->unsignedTinyInteger('depth')->default(0)->after('spawning_agent');

            $table->foreign('parent_id')->references('id')->on('sub_agents')->nullOnDelete();
            $table->index(['requester_phone', 'status']); // for activeForUser queries
        });
    }

    public function down(): void
    {
        Schema::table('sub_agents', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['requester_phone', 'status']);
            $table->dropColumn(['parent_id', 'spawning_agent', 'depth']);
        });
    }
};
