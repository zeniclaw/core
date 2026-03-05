<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('user_phone');
            $table->string('event_name');
            $table->date('event_date');
            $table->time('event_time')->nullable();
            $table->string('location')->nullable();
            $table->json('participants')->nullable();
            $table->text('description')->nullable();
            $table->json('reminder_times')->nullable(); // e.g. [30, 60, 1440] minutes before
            $table->boolean('notification_escalation')->default(false);
            $table->string('status')->default('active'); // active, completed, cancelled
            $table->timestamps();

            $table->index('user_phone');
            $table->index(['user_phone', 'event_date']);
            $table->index(['status', 'event_date', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminders');
    }
};
