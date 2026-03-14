<?php

use App\Jobs\ProcessEventRemindersJob;
use App\Jobs\PurgeStaleContext;
use App\Jobs\SendDailyBriefJob;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Schedule;

Schedule::command('reminders:process')->everyMinute();
Schedule::command('zeniclaw:compact-logs')->daily();
Schedule::command('zeniclaw:auto-suggest')->everyFifteenMinutes()->when(function () {
    return AppSetting::get('auto_suggest_enabled') === 'true';
});
Schedule::command('finance:check-alerts')->dailyAt('09:00');
Schedule::command('zeniclaw:watchdog')->everyMinute();
Schedule::job(new ProcessEventRemindersJob)->everyMinute();
Schedule::command('habits:remind')->dailyAt('08:00');
Schedule::command('zeniclaw:update')->dailyAt('03:00')->when(function () {
    return AppSetting::get('auto_update_enabled') !== 'false';
});
Schedule::command('zeniclaw:heartbeat')->everyFifteenMinutes();
Schedule::command('zeniclaw:auto-improve-agents')->everyThirtyMinutes()->when(function () {
    return AppSetting::get('auto_improve_agents_enabled') === 'true';
});
Schedule::command('memories:cleanup')->dailyAt('02:00');
Schedule::command('content:daily-digest')->dailyAt('07:30');
Schedule::job(new PurgeStaleContext)->dailyAt('03:00');
// Runs every minute; the job itself filters users whose brief_time matches the current HH:MM
Schedule::job(new SendDailyBriefJob)->everyMinute()->between('5:00', '23:00');
Schedule::command('assistant:send-tips')->weeklyOn(1, '10:00'); // Monday 10:00 AM
Schedule::command('tasks:process-recurring')->everyMinute(); // D8.3: recurring tasks
