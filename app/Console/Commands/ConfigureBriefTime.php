<?php

namespace App\Console\Commands;

use App\Models\UserBriefPreference;
use Illuminate\Console\Command;

class ConfigureBriefTime extends Command
{
    protected $signature = 'brief:configure {phone} {time?} {--disable} {--enable} {--sections=}';
    protected $description = 'Configure daily brief preferences for a user';

    public function handle(): void
    {
        $phone = $this->argument('phone');

        if ($this->option('disable')) {
            UserBriefPreference::where('user_phone', $phone)->update(['enabled' => false]);
            $this->info("Daily brief disabled for {$phone}.");
            return;
        }

        if ($this->option('enable')) {
            $pref = UserBriefPreference::firstOrCreate(
                ['user_phone' => $phone],
                ['brief_time' => '07:00', 'enabled' => true, 'preferred_sections' => ['reminders', 'tasks', 'weather', 'news', 'quote']]
            );
            $pref->update(['enabled' => true]);
            $this->info("Daily brief enabled for {$phone} at {$pref->brief_time}.");
            return;
        }

        $time = $this->argument('time') ?? '07:00';

        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $this->error("Invalid time format. Use HH:MM (e.g., 07:30).");
            return;
        }

        $data = ['brief_time' => $time, 'enabled' => true];

        $sections = $this->option('sections');
        if ($sections) {
            $data['preferred_sections'] = array_map('trim', explode(',', $sections));
        }

        UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            $data
        );

        $this->info("Daily brief configured for {$phone} at {$time}.");

        if ($sections) {
            $this->info("Sections: {$sections}");
        }
    }
}
