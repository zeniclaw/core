<?php

use App\Jobs\ProcessEventRemindersJob;
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
