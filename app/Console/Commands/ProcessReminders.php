<?php

namespace App\Console\Commands;

use App\Models\AgentLog;
use App\Models\Reminder;
use Illuminate\Console\Command;

class ProcessReminders extends Command
{
    protected $signature = 'reminders:process';
    protected $description = 'Process pending reminders and mark them as sent';

    public function handle(): void
    {
        $reminders = Reminder::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($reminders as $reminder) {
            $this->info("Processing reminder #{$reminder->id}: {$reminder->message}");

            // Log the reminder dispatch
            AgentLog::create([
                'agent_id' => $reminder->agent_id,
                'level' => 'info',
                'message' => "Reminder dispatched via {$reminder->channel}: {$reminder->message}",
                'context' => ['reminder_id' => $reminder->id, 'channel' => $reminder->channel],
            ]);

            $reminder->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        $this->info("Processed {$reminders->count()} reminders.");
    }
}
