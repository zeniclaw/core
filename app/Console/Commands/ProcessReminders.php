<?php

namespace App\Console\Commands;

use App\Models\AgentLog;
use App\Services\AgentManager;
use App\Models\AppSetting;
use App\Models\Reminder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessReminders extends Command
{
    protected $signature = 'reminders:process';
    protected $description = 'Process pending reminders and send notifications';

    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';

    public function handle(): void
    {
        $reminders = Reminder::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($reminders as $reminder) {
            $this->info("Processing reminder #{$reminder->id}: {$reminder->message}");

            // Send WhatsApp message if we have a phone number
            if ($reminder->requester_phone) {
                $this->sendWhatsApp($reminder);
            }

            // Log the reminder dispatch
            AgentManager::log($reminder->agent_id, 'reminder', "Reminder sent via {$reminder->channel}: {$reminder->message}", [
                'reminder_id' => $reminder->id,
                'channel'     => $reminder->channel,
            ]);

            if ($reminder->recurrence_rule) {
                $nextAt = $this->getNextOccurrence($reminder->recurrence_rule, now(AppSetting::timezone()));
                if ($nextAt) {
                    $reminder->update([
                        'scheduled_at' => $nextAt->utc(),
                        'sent_at' => now(),
                    ]);
                    $this->info("  -> Recurring — next occurrence: {$nextAt}");
                } else {
                    $reminder->update(['status' => 'sent', 'sent_at' => now()]);
                }
            } else {
                $reminder->update(['status' => 'sent', 'sent_at' => now()]);
            }
        }

        $this->info("Processed {$reminders->count()} reminders.");
    }

    private function getNextOccurrence(string $rule, Carbon $from): ?Carbon
    {
        $parts = explode(':', $rule);
        $type = $parts[0] ?? '';

        return match ($type) {
            'daily' => $this->nextDaily($parts, $from),
            'weekdays' => $this->nextWeekdays($parts, $from),
            'weekly' => $this->nextWeekly($parts, $from),
            'monthly' => $this->nextMonthly($parts, $from),
            default => null,
        };
    }

    private function nextDaily(array $parts, Carbon $from): Carbon
    {
        $time = $parts[1] ?? '08:00';
        [$h, $m] = explode(':', $time) + [0 => 8, 1 => 0];

        return $from->copy()->addDay()->setTime((int) $h, (int) $m, 0);
    }

    /**
     * Weekdays (Monday-Friday) recurrence
     * Format: weekdays:HH:MM
     */
    private function nextWeekdays(array $parts, Carbon $from): Carbon
    {
        $time = $parts[1] ?? '08:00';
        [$h, $m] = explode(':', $time) + [0 => 8, 1 => 0];

        $next = $from->copy()->addDay()->setTime((int) $h, (int) $m, 0);

        // Skip Saturday (6) and Sunday (7)
        while ($next->isWeekend()) {
            $next->addDay();
        }

        return $next;
    }

    private function nextWeekly(array $parts, Carbon $from): Carbon
    {
        $day = strtolower($parts[1] ?? 'monday');
        $time = $parts[2] ?? '09:00';
        [$h, $m] = explode(':', $time) + [0 => 9, 1 => 0];

        return $from->copy()->next($day)->setTime((int) $h, (int) $m, 0);
    }

    private function nextMonthly(array $parts, Carbon $from): Carbon
    {
        $dayOfMonth = (int) ($parts[1] ?? 1);
        $time = $parts[2] ?? '09:00';
        [$h, $m] = explode(':', $time) + [0 => 9, 1 => 0];

        $next = $from->copy()->day($dayOfMonth)->setTime((int) $h, (int) $m, 0);
        if ($next->lte($from)) {
            $next->addMonth();
        }

        return $next;
    }

    private function sendWhatsApp(Reminder $reminder): void
    {
        // Build the message text
        $text = "Rappel : {$reminder->message}";

        // Add snooze hint for non-recurring reminders
        if (!$reminder->recurrence_rule) {
            $text .= "\n\n_Reponds 'fait' pour confirmer ou '5min'/'1h'/'demain' pour reporter._";
        }

        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => $this->wahaApiKey])
                ->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $reminder->requester_phone,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to send reminder #{$reminder->id} via WhatsApp: " . $e->getMessage());
        }
    }
}
