<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('reminders:process')->everyMinute();
Schedule::command('zeniclaw:compact-logs')->daily();
