<?php

use App\Jobs\ProcessEventRemindersJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('reminders:process')->everyMinute();
Schedule::command('zeniclaw:compact-logs')->daily();
Schedule::command('zeniclaw:auto-suggest')->everyFifteenMinutes();
Schedule::command('finance:check-alerts')->dailyAt('09:00');
Schedule::command('zeniclaw:watchdog')->everyMinute();
Schedule::job(new ProcessEventRemindersJob)->everyMinute();
Schedule::command('habits:remind')->dailyAt('08:00');
